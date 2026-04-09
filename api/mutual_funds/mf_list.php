<?php
/**
 * WealthDash — MF Holdings List
 * GET /api/mutual_funds/mf_list.php?portfolio_id=&view=holdings|transactions|folio
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json');
$currentUser = require_auth();

$portfolio_id = (int)($_GET['portfolio_id'] ?? 0);
$view         = $_GET['view'] ?? 'holdings';  // holdings | transactions | folio
$fund_id      = (int)($_GET['fund_id'] ?? 0);
$page         = max(1, (int)($_GET['page'] ?? 1));
$per_page     = min((int)($_GET['per_page'] ?? 50), 200);
$offset       = ($page - 1) * $per_page;

try {
    $db = DB::conn();

    // Build portfolio filter
    $portfolioWhere = '';
    $portfolioParams = [];
    if ($portfolio_id > 0) {
        // verify access
        $pStmt = $db->prepare("SELECT id, user_id FROM portfolios WHERE id = ?");
        $pStmt->execute([$portfolio_id]);
        $portfolio = $pStmt->fetch();
        if (!$portfolio) {
            echo json_encode(['success' => false, 'message' => 'Portfolio not found']);
            exit;
        }
        if ($portfolio['user_id'] != $currentUser['id'] && $currentUser['role'] !== 'admin') {
            // access denied — not owner
            {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
        }
        $portfolioWhere = ' AND h.portfolio_id = ? ';
        $portfolioParams[] = $portfolio_id;
    } else {
        // All portfolios owned by user
        $portfolioWhere = ' AND p.user_id = ? ';
        $portfolioParams[] = $currentUser['id'];
    }

    if ($view === 'transactions') {
        // ── TRANSACTIONS LIST ──────────────────────────────────
        $fundFilter = $fund_id > 0 ? ' AND t.fund_id = ? ' : '';
        $fundParams = $fund_id > 0 ? [$fund_id] : [];

        $whereBase = $portfolio_id > 0
            ? ' t.portfolio_id = ? '
            : ' p.user_id = ? ';

        // ── Extra filters from JS (txnFilterType, date range, search) ──
        $txnType  = $_GET['txn_type'] ?? '';
        $fromDate = $_GET['from']     ?? '';
        $toDate   = $_GET['to']       ?? '';
        $searchQ  = trim($_GET['q']   ?? '');
        $fyFilter = trim($_GET['fy']  ?? '');

        $extraWhere  = '';
        $extraParams = [];
        if ($txnType)  { $extraWhere .= ' AND t.transaction_type = ? '; $extraParams[] = $txnType; }
        if ($fromDate) { $extraWhere .= ' AND t.txn_date >= ? ';        $extraParams[] = $fromDate; }
        if ($toDate)   { $extraWhere .= ' AND t.txn_date <= ? ';        $extraParams[] = $toDate; }
        if ($searchQ)  { $extraWhere .= ' AND f.scheme_name LIKE ? ';   $extraParams[] = "%$searchQ%"; }
        if ($fyFilter) { $extraWhere .= ' AND t.investment_fy = ? ';    $extraParams[] = $fyFilter; }

        $countStmt = $db->prepare("
            SELECT COUNT(*) FROM mf_transactions t
            JOIN funds f ON f.id = t.fund_id
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE $whereBase $fundFilter $extraWhere
        ");
        $countStmt->execute(array_merge($portfolioParams, $fundParams, $extraParams));
        $total = (int)$countStmt->fetchColumn();

        // t31: Dynamic sort — whitelist allowed columns
        $sortColMap = [
            'txn_date'         => 't.txn_date',
            'scheme_name'      => 'f.scheme_name',
            'transaction_type' => 't.transaction_type',
            'units'            => 't.units',
            'nav'              => 't.nav',
            'value_at_cost'    => 't.value_at_cost',
        ];
        $rawSortCol  = $_GET['sort_col'] ?? 'txn_date';
        $rawSortDir  = strtoupper($_GET['sort_dir'] ?? 'DESC');
        $orderCol    = $sortColMap[$rawSortCol] ?? 't.txn_date';
        $orderDir    = ($rawSortDir === 'ASC') ? 'ASC' : 'DESC';
        // Secondary sort: always t.id DESC for stable pagination
        $orderClause = "$orderCol $orderDir, t.id DESC";

        $stmt = $db->prepare("
            SELECT t.id, t.portfolio_id, t.fund_id, t.folio_number,
                   t.transaction_type, t.platform, t.txn_date,
                   t.units, t.nav, t.value_at_cost, t.investment_fy,
                   t.stamp_duty, t.notes, t.created_at,
                   f.scheme_name, f.scheme_code, f.category,
                   fh.short_name AS fund_house,
                   port.name AS portfolio_name
            FROM mf_transactions t
            JOIN funds f ON f.id = t.fund_id
            JOIN fund_houses fh ON fh.id = f.fund_house_id
            JOIN portfolios port ON port.id = t.portfolio_id
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE $whereBase $fundFilter $extraWhere
            ORDER BY $orderClause
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($portfolioParams, $fundParams, $extraParams, [$per_page, $offset]));
        $rows = $stmt->fetchAll();

        $data = array_map(function($r) {
            return [
                'id'               => (int)$r['id'],
                'portfolio_id'     => (int)$r['portfolio_id'],
                'portfolio_name'   => $r['portfolio_name'],
                'fund_id'          => (int)$r['fund_id'],
                'scheme_code'      => $r['scheme_code'],
                'scheme_name'      => $r['scheme_name'],
                'fund_house'       => $r['fund_house'],
                'category'         => $r['category'],
                'folio_number'     => $r['folio_number'],
                'transaction_type' => $r['transaction_type'],
                'platform'         => $r['platform'],
                'txn_date'         => $r['txn_date'],
                'txn_date_fmt'     => format_date_display($r['txn_date']),
                'units'            => (float)$r['units'],
                'nav'              => (float)$r['nav'],
                'value_at_cost'    => (float)$r['value_at_cost'],
                'investment_fy'    => $r['investment_fy'],
                'stamp_duty'       => (float)$r['stamp_duty'],
                'notes'            => $r['notes'],
                'created_at'       => $r['created_at'],
            ];
        }, $rows);

        // ── Summary stats (always full-filter, ignore pagination) ────────
        $summaryStmt = $db->prepare("
            SELECT
                COUNT(*)                                                    AS total_txns,
                SUM(CASE WHEN t.transaction_type IN ('BUY','SWITCH_IN','DIV_REINVEST') THEN t.value_at_cost ELSE 0 END) AS total_buy,
                SUM(CASE WHEN t.transaction_type IN ('SELL','SWITCH_OUT')              THEN t.value_at_cost ELSE 0 END) AS total_sell,
                SUM(t.units * CASE WHEN t.transaction_type IN ('BUY','SWITCH_IN','DIV_REINVEST') THEN 1 ELSE -1 END)   AS net_units,
                MIN(t.txn_date)                                            AS first_txn,
                MAX(t.txn_date)                                            AS last_txn,
                COUNT(DISTINCT t.fund_id)                                  AS unique_funds,
                COUNT(DISTINCT t.investment_fy)                            AS unique_fys
            FROM mf_transactions t
            JOIN funds f ON f.id = t.fund_id
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE $whereBase $fundFilter $extraWhere
        ");
        $summaryStmt->execute(array_merge($portfolioParams, $fundParams, $extraParams));
        $sumRow = $summaryStmt->fetch();
        $summary = [
            'total_txns'   => (int)($sumRow['total_txns'] ?? 0),
            'total_buy'    => round((float)($sumRow['total_buy']  ?? 0), 2),
            'total_sell'   => round((float)($sumRow['total_sell'] ?? 0), 2),
            'net_invested' => round((float)($sumRow['total_buy'] ?? 0) - (float)($sumRow['total_sell'] ?? 0), 2),
            'net_units'    => round((float)($sumRow['net_units']  ?? 0), 4),
            'first_txn'    => $sumRow['first_txn'] ?? null,
            'last_txn'     => $sumRow['last_txn']  ?? null,
            'unique_funds' => (int)($sumRow['unique_funds'] ?? 0),
            'unique_fys'   => (int)($sumRow['unique_fys']   ?? 0),
        ];

        // ── Available FY list for filter dropdown ─────────────────────────
        $fyStmt = $db->prepare("
            SELECT DISTINCT t.investment_fy
            FROM mf_transactions t
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE $whereBase AND t.investment_fy IS NOT NULL AND t.investment_fy != ''
            ORDER BY t.investment_fy DESC
        ");
        $fyStmt->execute($portfolioParams);
        $fyList = array_column($fyStmt->fetchAll(), 'investment_fy');

        echo json_encode([
            'success'    => true,
            'view'       => 'transactions',
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'pages'      => ceil($total / $per_page),
            'data'       => $data,
            'summary'    => $summary,
            'fy_list'    => $fyList,
        ]);

    } elseif ($view === 'folio') {
        // ── FOLIO VIEW: per-folio holdings ────────────────────
        $whereBase = $portfolio_id > 0 ? ' h.portfolio_id = ? ' : ' p.user_id = ? ';

        $stmt = $db->prepare("
            SELECT h.id, h.portfolio_id, h.fund_id, h.folio_number,
                   h.total_units, h.avg_cost_nav, h.total_invested,
                   h.value_now, h.gain_loss, h.gain_pct, h.cagr,
                   h.first_purchase_date, h.ltcg_date, h.lock_in_date,
                   h.investment_fy, h.withdrawable_fy, h.gain_type,
                   h.highest_nav, h.highest_nav_date, h.is_active,
                   f.scheme_name, f.scheme_code, f.category, f.sub_category,
                   f.fund_type, f.option_type, f.latest_nav, f.latest_nav_date,
                   f.min_ltcg_days, f.lock_in_days,
                   f.exit_load_pct, f.exit_load_days, f.exit_load_text,
                   f.expense_ratio,
                   fh.name AS fund_house_name, fh.short_name AS fund_house_short,
                   port.name AS portfolio_name,
                   (SELECT COUNT(*) FROM sip_schedules s
                    WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                      AND s.is_active = 1 AND s.asset_type = 'mf'
                      AND s.schedule_type = 'SIP') AS active_sip_count,
                   (SELECT COUNT(*) FROM sip_schedules s
                    WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                      AND s.is_active = 1 AND s.asset_type = 'mf'
                      AND s.schedule_type = 'SWP') AS active_swp_count,
                   (SELECT s.sip_amount FROM sip_schedules s
                    WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                      AND s.is_active = 1 AND s.asset_type = 'mf'
                      AND s.schedule_type = 'SIP'
                    ORDER BY s.created_at DESC LIMIT 1) AS active_sip_amount,
                   (SELECT s.frequency FROM sip_schedules s
                    WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                      AND s.is_active = 1 AND s.asset_type = 'mf'
                      AND s.schedule_type = 'SIP'
                    ORDER BY s.created_at DESC LIMIT 1) AS active_sip_frequency,
                   (SELECT s.sip_amount FROM sip_schedules s
                    WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                      AND s.is_active = 1 AND s.asset_type = 'mf'
                      AND s.schedule_type = 'SWP'
                    ORDER BY s.created_at DESC LIMIT 1) AS active_swp_amount,
                   (SELECT s.id FROM sip_schedules s
                    WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                      AND s.is_active = 1 AND s.asset_type = 'mf'
                      AND s.schedule_type = 'SIP'
                    ORDER BY s.created_at DESC LIMIT 1) AS active_sip_id,
                   (SELECT s.id FROM sip_schedules s
                    WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                      AND s.is_active = 1 AND s.asset_type = 'mf'
                      AND s.schedule_type = 'SWP'
                    ORDER BY s.created_at DESC LIMIT 1) AS active_swp_id
            FROM mf_holdings h
            JOIN funds f ON f.id = h.fund_id
            JOIN fund_houses fh ON fh.id = f.fund_house_id
            JOIN portfolios port ON port.id = h.portfolio_id
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE $whereBase AND h.is_active = 1
            ORDER BY h.total_invested DESC
        ");
        $stmt->execute($portfolioParams);
        $rows = $stmt->fetchAll();

        $data = array_map(function($r) use ($db, $portfolio_id, $portfolioParams) {
            $invested = (float)$r['total_invested'];
            // Always compute live from latest_nav — never trust stale mf_holdings.value_now
            $latestNavLive = $r['latest_nav'] ? (float)$r['latest_nav'] : 0;
            $totalUnits    = (float)$r['total_units'];
            $valueNow = $latestNavLive > 0 ? round($totalUnits * $latestNavLive, 2) : (float)$r['value_now'];
            $gainLoss = round($valueNow - $invested, 2);
            $gainPct  = $invested > 0 ? round(($gainLoss / $invested) * 100, 2) : 0;

            // XIRR for this specific folio
            $wherePort = $portfolio_id > 0 ? 't.portfolio_id = ?' : 'p.user_id = ?';
            $txnStmt = $db->prepare("
                SELECT t.transaction_type, t.txn_date, t.value_at_cost, t.units
                FROM mf_transactions t
                JOIN portfolios p ON p.id = t.portfolio_id
                WHERE $wherePort AND t.fund_id = ? AND t.folio_number = ?
                ORDER BY t.txn_date ASC
            ");
            $txnStmt->execute(array_merge($portfolioParams, [(int)$r['fund_id'], $r['folio_number']]));
            $txns = $txnStmt->fetchAll();

            $xirr = null;
            if (!empty($txns) && $valueNow > 0) {
                $xirr = xirr_from_txns($txns, $valueNow, date('Y-m-d'));
            }
            if ($xirr === null && $r['first_purchase_date'] && $invested > 0 && $valueNow > 0) {
                $days = (int)((strtotime('today') - strtotime($r['first_purchase_date'])) / 86400);
                if ($days > 30) {
                    $xirr = round((pow($valueNow / $invested, 1 / ($days / 365)) - 1) * 100, 2);
                }
            }

            // Recalculate LTCG date fresh
            $minLtcgDays = (int)($r['min_ltcg_days'] ?? 365);
            $ltcgDate    = null;
            if ($r['first_purchase_date']) {
                $ltcgDate = date('Y-m-d', strtotime($r['first_purchase_date']) + ($minLtcgDays * 86400));
            }

            return [
                'id'               => (int)$r['id'],
                'portfolio_id'     => (int)$r['portfolio_id'],
                'portfolio_name'   => $r['portfolio_name'],
                'fund_id'          => (int)$r['fund_id'],
                'scheme_code'      => $r['scheme_code'],
                'scheme_name'      => $r['scheme_name'],
                'fund_house'       => $r['fund_house_name'],
                'fund_house_short' => $r['fund_house_short'],
                'category'         => $r['category'],
                'sub_category'     => $r['sub_category'],
                'fund_type'        => $r['fund_type'],
                'option_type'      => $r['option_type'],
                'folio_number'     => $r['folio_number'],
                'total_units'      => round((float)$r['total_units'], 4),
                'avg_cost_nav'     => round((float)$r['avg_cost_nav'], 4),
                'total_invested'   => round($invested, 2),
                'value_now'        => round($valueNow, 2),
                'gain_loss'        => round($gainLoss, 2),
                'gain_pct'         => $gainPct,
                'cagr'             => $xirr,
                'gain_type'        => $r['gain_type'],
                'latest_nav'       => $r['latest_nav'] ? (float)$r['latest_nav'] : null,
                'latest_nav_date'  => $r['latest_nav_date'],
                'first_purchase_date' => $r['first_purchase_date'],
                'ltcg_date'        => $ltcgDate,
                'lock_in_date'     => $r['lock_in_date'],
                'investment_fy'    => $r['investment_fy'],
                'withdrawable_fy'  => $r['withdrawable_fy'],
                'highest_nav'      => $r['highest_nav'] ? (float)$r['highest_nav'] : null,
                'highest_nav_date' => $r['highest_nav_date'],
                'min_ltcg_days'    => $minLtcgDays,
                'lock_in_days'     => (int)$r['lock_in_days'],
                'days_held'        => $r['first_purchase_date'] ? days_diff($r['first_purchase_date']) : 0,
                'invested_fmt'     => format_inr($invested),
                'value_fmt'        => format_inr($valueNow),
                'gain_fmt'         => format_inr($gainLoss),
                'active_sip_count'     => (int)($r['active_sip_count'] ?? 0),
                'active_swp_count'     => (int)($r['active_swp_count'] ?? 0),
                'active_sip_amount'    => $r['active_sip_amount'] !== null ? (float)$r['active_sip_amount'] : null,
                'active_sip_frequency' => $r['active_sip_frequency'] ?? null,
                'active_swp_amount'    => $r['active_swp_amount'] !== null ? (float)$r['active_swp_amount'] : null,
                'active_sip_id'        => isset($r['active_sip_id']) && $r['active_sip_id'] !== null ? (int)$r['active_sip_id'] : null,
                'active_swp_id'        => isset($r['active_swp_id']) && $r['active_swp_id'] !== null ? (int)$r['active_swp_id'] : null,
                // t367: Exit Load Calculator
                'exit_load_pct'        => $r['exit_load_pct'] !== null ? (float)$r['exit_load_pct'] : null,
                'exit_load_days'       => $r['exit_load_days'] !== null ? (int)$r['exit_load_days'] : null,
                'exit_load_text'       => $r['exit_load_text'] ?? null,
                // t269: Direct vs Regular
                'expense_ratio'        => $r['expense_ratio'] !== null ? (float)$r['expense_ratio'] : null,
            ];
        }, $rows);

        echo json_encode([
            'success' => true,
            'view'    => 'folio',
            'total'   => count($data),
            'data'    => $data
        ]);

    } else {
        // ── COMBINED HOLDINGS VIEW (group by fund, sum across folios) ──
        $whereBase = $portfolio_id > 0 ? ' h.portfolio_id = ? ' : ' p.user_id = ? ';

        $stmt = $db->prepare("
            SELECT
                h.fund_id,
                f.scheme_name, f.scheme_code, f.category, f.sub_category,
                f.fund_type, f.option_type, f.latest_nav, f.latest_nav_date,
                f.highest_nav, f.highest_nav_date,
                f.min_ltcg_days, f.lock_in_days,
                f.exit_load_pct, f.exit_load_days, f.exit_load_text,
                f.expense_ratio,
                fh.name AS fund_house_name, fh.short_name AS fund_house_short,
                SUM(h.total_units) AS total_units,
                SUM(h.total_invested) AS total_invested,
                SUM(h.value_now) AS value_now,
                SUM(h.gain_loss) AS gain_loss,
                MIN(h.first_purchase_date) AS first_purchase_date,
                MIN(h.ltcg_date) AS ltcg_date,
                GROUP_CONCAT(DISTINCT h.folio_number ORDER BY h.folio_number SEPARATOR ', ') AS folios,
                COUNT(DISTINCT h.folio_number) AS folio_count,
                (SELECT COUNT(*) FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.asset_type = 'mf'
                   AND s.schedule_type = 'SIP') AS active_sip_count,
                (SELECT COUNT(*) FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.asset_type = 'mf'
                   AND s.schedule_type = 'SWP') AS active_swp_count,
                (SELECT s.sip_amount FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.schedule_type = 'SIP'
                 ORDER BY s.created_at DESC LIMIT 1) AS active_sip_amount,
                (SELECT s.frequency FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.schedule_type = 'SIP'
                 ORDER BY s.created_at DESC LIMIT 1) AS active_sip_frequency,
                (SELECT s.sip_amount FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.schedule_type = 'SWP'
                 ORDER BY s.created_at DESC LIMIT 1) AS active_swp_amount,
                (SELECT s.id FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.asset_type = 'mf'
                   AND s.schedule_type = 'SIP'
                 ORDER BY s.created_at DESC LIMIT 1) AS active_sip_id,
                (SELECT s.id FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.asset_type = 'mf'
                   AND s.schedule_type = 'SWP'
                 ORDER BY s.created_at DESC LIMIT 1) AS active_swp_id
            FROM mf_holdings h
            JOIN funds f ON f.id = h.fund_id
            JOIN fund_houses fh ON fh.id = f.fund_house_id
            JOIN portfolios port ON port.id = h.portfolio_id
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE $whereBase AND h.is_active = 1
            GROUP BY h.fund_id
            ORDER BY SUM(h.total_invested) DESC
        ");
        $stmt->execute($portfolioParams);
        $rows = $stmt->fetchAll();

        $data = array_map(function($r) use ($db, $portfolio_id, $portfolioParams) {
            $totalUnits    = (float)$r['total_units'];
            $totalInvested = (float)$r['total_invested'];
            // Always compute live from latest_nav — never trust stale mf_holdings.value_now
            $latestNavLive = $r['latest_nav'] ? (float)$r['latest_nav'] : 0;
            $valueNow      = $latestNavLive > 0 ? round($totalUnits * $latestNavLive, 2) : (float)$r['value_now'];
            $gainLoss      = round($valueNow - $totalInvested, 2);
            $gainPct       = $totalInvested > 0 ? round(($gainLoss / $totalInvested) * 100, 2) : 0;

            // --- XIRR: fetch all transactions for this fund ---
            $wherePort = $portfolio_id > 0 ? 't.portfolio_id = ?' : 'p.user_id = ?';
            $txnStmt = $db->prepare("
                SELECT t.transaction_type, t.txn_date, t.value_at_cost, t.units
                FROM mf_transactions t
                JOIN portfolios p ON p.id = t.portfolio_id
                WHERE $wherePort AND t.fund_id = ?
                ORDER BY t.txn_date ASC
            ");
            $txnStmt->execute(array_merge($portfolioParams, [(int)$r['fund_id']]));
            $txns = $txnStmt->fetchAll();

            $xirr = null;
            if (!empty($txns) && $valueNow > 0) {
                $xirr = xirr_from_txns($txns, $valueNow, date('Y-m-d'));
            }
            // Fallback to simple CAGR if XIRR didn't converge
            if ($xirr === null && $r['first_purchase_date'] && $totalInvested > 0 && $valueNow > 0) {
                $days = (int)((strtotime('today') - strtotime($r['first_purchase_date'])) / 86400);
                if ($days > 30) {
                    $years = $days / 365;
                    $xirr  = round((pow($valueNow / $totalInvested, 1 / $years) - 1) * 100, 2);
                }
            }

            // --- LTCG / STCG units via FIFO ---
            $minLtcgDays = (int)($r['min_ltcg_days'] ?? 365);
            [$ltcgUnits, $stcgUnits] = calc_ltcg_stcg_units($txns, $minLtcgDays);

            // --- LTCG date: calculate fresh from first_purchase_date ---
            $ltcgDate    = null;
            if ($r['first_purchase_date']) {
                $ltcgDate = date('Y-m-d', strtotime($r['first_purchase_date']) + ($minLtcgDays * 86400));
            }

            return [
                'fund_id'          => (int)$r['fund_id'],
                'scheme_code'      => $r['scheme_code'],
                'scheme_name'      => $r['scheme_name'],
                'fund_house'       => $r['fund_house_name'],
                'fund_house_short' => $r['fund_house_short'],
                'category'         => $r['category'],
                'sub_category'     => $r['sub_category'],
                'fund_type'        => $r['fund_type'],
                'option_type'      => $r['option_type'],
                'total_units'      => round($totalUnits, 4),
                'total_invested'   => round($totalInvested, 2),
                'value_now'        => round($valueNow, 2),
                'gain_loss'        => round($gainLoss, 2),
                'gain_pct'         => $gainPct,
                'cagr'             => $xirr,
                'latest_nav'       => $r['latest_nav'] ? (float)$r['latest_nav'] : null,
                'latest_nav_date'  => $r['latest_nav_date'],
                'first_purchase_date' => $r['first_purchase_date'],
                'ltcg_date'        => $ltcgDate,
                'min_ltcg_days'    => $minLtcgDays,
                'folios'           => $r['folios'],
                'folio_count'      => (int)$r['folio_count'],
                'gain_type'        => $r['first_purchase_date'] ? get_gain_type($r['first_purchase_date'], $minLtcgDays) : 'STCG',
                'days_held'        => $r['first_purchase_date'] ? days_diff($r['first_purchase_date']) : 0,
                'ltcg_units'       => round($ltcgUnits, 4),
                'stcg_units'       => round($stcgUnits, 4),
                'invested_fmt'     => format_inr($totalInvested),
                'value_fmt'        => format_inr($valueNow),
                'gain_fmt'         => format_inr($gainLoss),
                'highest_nav'      => $r['highest_nav'] ? (float)$r['highest_nav'] : null,
                'highest_nav_date' => $r['highest_nav_date'],
                'drawdown_pct'     => ($r['highest_nav'] && (float)$r['highest_nav'] > 0 && $r['latest_nav'])
                                        ? round(((float)$r['highest_nav'] - (float)$r['latest_nav']) / (float)$r['highest_nav'] * 100, 2)
                                        : null,
                'active_sip_count'     => (int)($r['active_sip_count'] ?? 0),
                'active_swp_count'     => (int)($r['active_swp_count'] ?? 0),
                'active_sip_amount'    => isset($r['active_sip_amount']) && $r['active_sip_amount'] !== null ? (float)$r['active_sip_amount'] : null,
                'active_sip_frequency' => $r['active_sip_frequency'] ?? null,
                'active_swp_amount'    => isset($r['active_swp_amount']) && $r['active_swp_amount'] !== null ? (float)$r['active_swp_amount'] : null,
                'active_sip_id'        => isset($r['active_sip_id']) && $r['active_sip_id'] !== null ? (int)$r['active_sip_id'] : null,
                'active_swp_id'        => isset($r['active_swp_id']) && $r['active_swp_id'] !== null ? (int)$r['active_swp_id'] : null,
                // t367: Exit Load Calculator
                'exit_load_pct'        => $r['exit_load_pct'] !== null ? (float)$r['exit_load_pct'] : null,
                'exit_load_days'       => $r['exit_load_days'] !== null ? (int)$r['exit_load_days'] : null,
                'exit_load_text'       => $r['exit_load_text'] ?? null,
                // t269: Direct vs Regular cost
                'expense_ratio'        => $r['expense_ratio'] !== null ? (float)$r['expense_ratio'] : null,
                'lock_in_days'         => (int)($r['lock_in_days'] ?? 0),
            ];
        }, $rows);

        // Summary totals
        $summary = [
            'total_invested' => array_sum(array_column($data, 'total_invested')),
            'value_now'      => array_sum(array_column($data, 'value_now')),
            'gain_loss'      => array_sum(array_column($data, 'gain_loss')),
            'fund_count'     => count($data),
        ];
        $summary['gain_pct'] = $summary['total_invested'] > 0
            ? round(($summary['gain_loss'] / $summary['total_invested']) * 100, 2)
            : 0;

        echo json_encode([
            'success' => true,
            'view'    => 'holdings',
            'total'   => count($data),
            'summary' => $summary,
            'data'    => $data
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

// ── Helper: format raw holding row ──────────────────────────
function format_holding_row(array $r): array {
    $invested  = (float)$r['total_invested'];
    $valueNow  = (float)$r['value_now'];
    $gainLoss  = (float)$r['gain_loss'];
    $gainPct   = $invested > 0 ? round(($gainLoss / $invested) * 100, 2) : 0;
    return [
        'id'               => (int)$r['id'],
        'portfolio_id'     => (int)$r['portfolio_id'],
        'portfolio_name'   => $r['portfolio_name'],
        'fund_id'          => (int)$r['fund_id'],
        'scheme_code'      => $r['scheme_code'],
        'scheme_name'      => $r['scheme_name'],
        'fund_house'       => $r['fund_house_name'],
        'fund_house_short' => $r['fund_house_short'],
        'category'         => $r['category'],
        'sub_category'     => $r['sub_category'],
        'fund_type'        => $r['fund_type'],
        'option_type'      => $r['option_type'],
        'folio_number'     => $r['folio_number'],
        'total_units'      => round((float)$r['total_units'], 4),
        'avg_cost_nav'     => round((float)$r['avg_cost_nav'], 4),
        'total_invested'   => round($invested, 2),
        'value_now'        => round($valueNow, 2),
        'gain_loss'        => round($gainLoss, 2),
        'gain_pct'         => $gainPct,
        'cagr'             => (float)($r['cagr'] ?? 0),
        'gain_type'        => $r['gain_type'],
        'latest_nav'       => $r['latest_nav'] ? (float)$r['latest_nav'] : null,
        'latest_nav_date'  => $r['latest_nav_date'],
        'first_purchase_date' => $r['first_purchase_date'],
        'ltcg_date'        => $r['ltcg_date'],
        'lock_in_date'     => $r['lock_in_date'],
        'investment_fy'    => $r['investment_fy'],
        'withdrawable_fy'  => $r['withdrawable_fy'],
        'highest_nav'      => $r['highest_nav'] ? (float)$r['highest_nav'] : null,
        'highest_nav_date' => $r['highest_nav_date'],
        'min_ltcg_days'    => (int)$r['min_ltcg_days'],
        'lock_in_days'     => (int)$r['lock_in_days'],
        'days_held'        => $r['first_purchase_date'] ? days_diff($r['first_purchase_date']) : 0,
        'invested_fmt'     => format_inr($invested),
        'value_fmt'        => format_inr($valueNow),
        'gain_fmt'         => format_inr($gainLoss),
    ];
}

/**
 * FIFO-based LTCG/STCG units calculation.
 *
 * Logic:
 *  1. Sort all BUY txns oldest→newest (queue).
 *  2. Deduct SELL units from the oldest BUYs first (FIFO).
 *  3. Remaining BUY lots: if held >= min_ltcg_days → LTCG, else STCG.
 *
 * @param  array $txns       Rows: [transaction_type, txn_date, units]
 * @param  int   $ltcgDays   Min holding days for LTCG (365 equity / 1095 debt)
 * @return array             [ltcg_units, stcg_units]
 */
function calc_ltcg_stcg_units(array $txns, int $ltcgDays = 365): array
{
    $buyTypes  = ['BUY', 'SWITCH_IN', 'DIV_REINVEST'];
    $sellTypes = ['SELL', 'SWITCH_OUT'];

    // Build BUY queue: [date => timestamp, units => float]
    $buyQueue = [];
    foreach ($txns as $t) {
        if (in_array($t['transaction_type'], $buyTypes)) {
            $buyQueue[] = ['ts' => strtotime($t['txn_date']), 'units' => (float)$t['units']];
        }
    }
    // Sort oldest first for FIFO
    usort($buyQueue, fn($a, $b) => $a['ts'] <=> $b['ts']);

    // Total SELL units
    $totalSold = 0.0;
    foreach ($txns as $t) {
        if (in_array($t['transaction_type'], $sellTypes)) {
            $totalSold += (float)$t['units'];
        }
    }

    // Deduct sells from oldest BUYs (FIFO)
    $remaining = $totalSold;
    foreach ($buyQueue as &$lot) {
        if ($remaining <= 0) break;
        $deduct      = min($lot['units'], $remaining);
        $lot['units'] -= $deduct;
        $remaining   -= $deduct;
    }
    unset($lot);

    // Classify remaining lots
    $cutoff    = strtotime("today") - ($ltcgDays * 86400);
    $ltcgUnits = 0.0;
    $stcgUnits = 0.0;
    foreach ($buyQueue as $lot) {
        if ($lot['units'] <= 0) continue;
        if ($lot['ts'] <= $cutoff) {
            $ltcgUnits += $lot['units'];
        } else {
            $stcgUnits += $lot['units'];
        }
    }

    return [round($ltcgUnits, 4), round($stcgUnits, 4)];
}