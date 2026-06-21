<?php
/**
 * WealthDash — FY Gains / Capital Gains Report API
 * Tasks: t74, t74b, t363, tmfi05, tv009b
 * Actions: fy_gains | ltcg_summary | stcg_summary | tax_loss_harvest
 *          capital_gains_preview | ltcg_bandwidth | gain_by_fund
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'fy_gains';
$db          = DB::conn();

// ── Helpers ───────────────────────────────────────────────────────────────
function getFYDates(string $fy): array {
    // fy = '2024-25'
    [$y1, $y2] = explode('-', $fy);
    return ["from" => "{$y1}-04-01", "to" => "20{$y2}-03-31"];
}

function currentFY(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return $m >= 4 ? "{$y}-" . substr($y + 1, 2) : ($y - 1) . "-" . substr($y, 2);
}

function ltcgTaxFree(): float { return 125000; } // ₹1.25L from Jul 2024 Budget

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// fy_gains — All gains/losses for a financial year
// ══════════════════════════════════════════════════════════════════════════
case 'fy_gains':
    $fy     = $_GET['fy'] ?? currentFY();
    $search = trim($_GET['search'] ?? '');
    $dates  = getFYDates($fy);

    // Get all SELL transactions in the FY
    $sellStmt = $db->prepare("
        SELECT
          t.id AS tx_id,
          t.tx_date,
          t.units       AS sold_units,
          t.price_per_unit AS sell_nav,
          t.amount      AS sell_amount,
          f.fund_name,
          f.scheme_code,
          f.category,
          t.holding_id
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        JOIN portfolios p   ON p.id  = mh.portfolio_id
        JOIN funds f        ON f.id  = mh.fund_id
        WHERE p.user_id = ?
          AND t.tx_type IN ('sell','swp','switch_out','redemption')
          AND t.tx_date BETWEEN ? AND ?
          " . ($search ? "AND f.fund_name LIKE ?" : "") . "
        ORDER BY t.tx_date DESC
    ");
    $params = [$userId, $dates['from'], $dates['to']];
    if ($search) $params[] = "%$search%";
    $sellStmt->execute($params);
    $sells = $sellStmt->fetchAll(PDO::FETCH_ASSOC);

    $gains = [];
    $ltcgTotal = $stcgTotal = $ltcgExempt = 0;

    foreach ($sells as $sell) {
        // FIFO: find matching buy transactions for this holding
        $buys = $db->prepare("
            SELECT tx_date, units AS bought_units, price_per_unit AS buy_nav, amount AS buy_amount
            FROM mf_transactions
            WHERE holding_id = ? AND tx_type IN ('buy','sip','lumpsum','switch_in')
              AND tx_date <= ?
            ORDER BY tx_date ASC
        ");
        $buys->execute([$sell['holding_id'], $sell['tx_date']]);
        $buyRows = $buys->fetchAll(PDO::FETCH_ASSOC);

        $unitsToMatch  = (float)$sell['sold_units'];
        $costBasis     = 0.0;
        $holdingDaysWt = 0.0;

        foreach ($buyRows as $buy) {
            if ($unitsToMatch <= 0) break;
            $used  = min((float)$buy['bought_units'], $unitsToMatch);
            $ratio = $used / (float)$buy['bought_units'];
            $costBasis     += $used * (float)$buy['buy_nav'];
            $holdingDaysWt += $used * ((strtotime($sell['tx_date']) - strtotime($buy['tx_date'])) / 86400);
            $unitsToMatch  -= $used;
        }

        $avgHoldingDays = ($sell['sold_units'] > 0) ? $holdingDaysWt / (float)$sell['sold_units'] : 0;
        $sellAmt   = (float)$sell['sell_amount'];
        $gain      = $sellAmt - $costBasis;
        $isLTCG    = $avgHoldingDays >= 365;

        // LTCG/STCG classification for equity MF
        $isEquity  = str_contains(strtolower($sell['category'] ?? ''), 'equity')
                  || str_contains(strtolower($sell['category'] ?? ''), 'elss')
                  || str_contains(strtolower($sell['category'] ?? ''), 'index');

        $taxType   = $isLTCG ? 'LTCG' : 'STCG';
        $taxRate   = $isLTCG ? 10 : 15; // equity MF rates post Jul 2024
        if (!$isEquity) {
            $taxType = $isLTCG ? 'LTCG_Debt' : 'STCG_Debt';
            $taxRate = $isLTCG ? 20 : null; // slab rate for STCG debt
        }

        if ($isLTCG && $isEquity) $ltcgTotal += max(0, $gain);
        if (!$isLTCG && $isEquity) $stcgTotal += max(0, $gain);

        $gains[] = [
            'tx_id'           => $sell['tx_id'],
            'fund_name'       => $sell['fund_name'],
            'scheme_code'     => $sell['scheme_code'],
            'category'        => $sell['category'],
            'tx_date'         => $sell['tx_date'],
            'sold_units'      => round((float)$sell['sold_units'], 4),
            'sell_nav'        => round((float)$sell['sell_nav'], 4),
            'sell_amount'     => round($sellAmt, 2),
            'cost_basis'      => round($costBasis, 2),
            'gain_loss'       => round($gain, 2),
            'gain_pct'        => $costBasis > 0 ? round($gain / $costBasis * 100, 2) : 0,
            'holding_days'    => round($avgHoldingDays),
            'tax_type'        => $taxType,
            'tax_rate_pct'    => $taxRate,
            'estimated_tax'   => $taxRate ? round(max(0, $gain) * $taxRate / 100, 2) : null,
        ];
    }

    $ltcgExempt   = min($ltcgTotal, ltcgTaxFree());
    $ltcgTaxable  = max(0, $ltcgTotal - ltcgTaxFree());
    $ltcgTax      = round($ltcgTaxable * 0.10, 2);
    $stcgTax      = round(max(0, $stcgTotal) * 0.15, 2);

    echo json_encode([
        'success' => true,
        'data'    => $gains,
        'summary' => [
            'fy'              => $fy,
            'ltcg_total'      => round($ltcgTotal, 2),
            'ltcg_exempt'     => round($ltcgExempt, 2),
            'ltcg_taxable'    => round($ltcgTaxable, 2),
            'ltcg_tax'        => $ltcgTax,
            'stcg_total'      => round($stcgTotal, 2),
            'stcg_tax'        => $stcgTax,
            'total_tax'       => round($ltcgTax + $stcgTax, 2),
        ],
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// tax_loss_harvest — tmfi05: Identify loss-making holdings to sell
// ══════════════════════════════════════════════════════════════════════════
case 'tax_loss_harvest':
    $fy    = $_GET['fy'] ?? currentFY();
    $portfolioId = getOrCreatePortfolio($db, $userId);

    // Current realised gains this FY (to know how much to offset)
    $datesR  = getFYDates($fy);
    $realised = $db->prepare("
        SELECT
          SUM(CASE WHEN DATEDIFF(t.tx_date, first_buy.buy_date) >= 365 THEN (t.amount - cost.cost) ELSE 0 END) AS ltcg,
          SUM(CASE WHEN DATEDIFF(t.tx_date, first_buy.buy_date) < 365  THEN (t.amount - cost.cost) ELSE 0 END) AS stcg
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        JOIN portfolios p   ON p.id  = mh.portfolio_id
        CROSS JOIN (SELECT 0 AS cost, '2000-01-01' AS buy_date) first_buy
        CROSS JOIN (SELECT 0 AS cost) cost
        WHERE p.user_id = ? AND t.tx_type IN ('sell','swp','redemption')
          AND t.tx_date BETWEEN ? AND ?
    ");
    $realised->execute([$userId, $datesR['from'], $datesR['to']]);
    $r = $realised->fetch(PDO::FETCH_ASSOC);

    // Holdings with unrealised losses
    $losses = $db->prepare("
        SELECT mh.id AS holding_id, mh.fund_id, mh.units, mh.invested_amount,
               mh.current_value, mh.current_nav, mh.first_investment_date,
               f.fund_name, f.category,
               (mh.current_value - mh.invested_amount) AS unrealised_gl,
               DATEDIFF(NOW(), mh.first_investment_date) AS holding_days
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        JOIN portfolios p ON p.id = mh.portfolio_id
        WHERE p.user_id = ? AND mh.is_active = 1
          AND mh.current_value < mh.invested_amount
        ORDER BY (mh.current_value - mh.invested_amount) ASC
    ");
    $losses->execute([$userId]);
    $lossHoldings = $losses->fetchAll(PDO::FETCH_ASSOC);

    $harvestCandidates = [];
    foreach ($lossHoldings as $h) {
        $loss      = abs((float)$h['unrealised_gl']);
        $isLTCL    = (int)$h['holding_days'] >= 365;
        $harvestCandidates[] = array_merge($h, [
            'unrealised_loss' => round($loss, 2),
            'loss_pct'        => (float)$h['invested_amount'] > 0
                                  ? round($loss / (float)$h['invested_amount'] * 100, 2) : 0,
            'loss_type'       => $isLTCL ? 'LTCL' : 'STCL',
            'offset_type'     => $isLTCL ? 'Can offset LTCG only' : 'Can offset STCG and LTCG',
            'tax_saving'      => $isLTCL ? round($loss * 0.10, 2) : round($loss * 0.15, 2),
            'note'            => $isLTCL
                ? "Long-term loss — can offset LTCG. Rebuy after 3 days to maintain exposure."
                : "Short-term loss — can offset both STCG ({$h['category']}) and LTCG.",
        ]);
    }

    // LTCG bandwidth remaining
    $ltcgBandwidth = max(0, ltcgTaxFree() - ((float)($r['ltcg'] ?? 0)));

    echo json_encode([
        'success'        => true,
        'candidates'     => $harvestCandidates,
        'realised_stcg'  => round((float)($r['stcg'] ?? 0), 2),
        'realised_ltcg'  => round((float)($r['ltcg'] ?? 0), 2),
        'ltcg_bandwidth' => round($ltcgBandwidth, 2),
        'ltcg_tax_free'  => ltcgTaxFree(),
        'fy'             => $fy,
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// ltcg_bandwidth — Quick LTCG bandwidth check
// ══════════════════════════════════════════════════════════════════════════
case 'ltcg_bandwidth':
    $fy    = $_GET['fy'] ?? currentFY();
    $dates = getFYDates($fy);

    // Sum realised LTCG this FY (simplified — holdings sold > 1 year)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(t.amount), 0) AS total_sell
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        JOIN portfolios p   ON p.id  = mh.portfolio_id
        WHERE p.user_id = ? AND t.tx_type IN ('sell','redemption')
          AND t.tx_date BETWEEN ? AND ?
          AND DATEDIFF(t.tx_date, mh.first_investment_date) >= 365
    ");
    $stmt->execute([$userId, $dates['from'], $dates['to']]);
    $totalLTCGSell = (float)$stmt->fetchColumn();
    // Simplified — actual cost basis needed for accurate number
    $estimatedLTCGGain  = $totalLTCGSell * 0.15; // rough estimate
    $ltcgUsed      = max(0, $estimatedLTCGGain);
    $ltcgRemaining = max(0, ltcgTaxFree() - $ltcgUsed);

    echo json_encode([
        'success'        => true,
        'ltcg_used'      => round($ltcgUsed, 2),
        'ltcg_remaining' => round($ltcgRemaining, 2),
        'ltcg_limit'     => ltcgTaxFree(),
        'fy'             => $fy,
        'note'           => $ltcgRemaining > 0
            ? "₹" . number_format($ltcgRemaining) . " LTCG exemption remaining. Consider booking gains before March 31."
            : "LTCG exemption fully used for FY $fy.",
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// capital_gains_preview — Live preview on holdings page (tv09b)
// ══════════════════════════════════════════════════════════════════════════
case 'capital_gains_preview':
    $portfolioId = getOrCreatePortfolio($db, $userId);
    $fy          = currentFY();

    $stmt = $db->prepare("
        SELECT mh.fund_id, mh.invested_amount, mh.current_value, mh.first_investment_date,
               f.fund_name, f.category
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        JOIN portfolios p ON p.id = mh.portfolio_id
        WHERE p.user_id = ? AND mh.is_active = 1 AND mh.current_value > mh.invested_amount
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $preview = ['ltcg' => 0, 'stcg' => 0, 'items' => []];
    foreach ($rows as $r) {
        $gain  = (float)$r['current_value'] - (float)$r['invested_amount'];
        $days  = (int)(time() - strtotime($r['first_investment_date'])) / 86400;
        $isLT  = $days >= 365;
        if ($isLT) $preview['ltcg'] += $gain;
        else        $preview['stcg'] += $gain;
        $preview['items'][] = [
            'fund_name' => $r['fund_name'],
            'gain'      => round($gain, 2),
            'type'      => $isLT ? 'LTCG' : 'STCG',
            'holding_days' => $days,
        ];
    }

    $ltcgTaxable = max(0, $preview['ltcg'] - ltcgTaxFree());
    $preview['ltcg_tax']   = round($ltcgTaxable * 0.10, 2);
    $preview['stcg_tax']   = round(max(0, $preview['stcg']) * 0.15, 2);
    $preview['total_tax']  = $preview['ltcg_tax'] + $preview['stcg_tax'];
    $preview['ltcg']       = round($preview['ltcg'], 2);
    $preview['stcg']       = round($preview['stcg'], 2);

    echo json_encode(['success' => true, 'data' => $preview, 'fy' => $fy]);
    break;

default:
    echo json_encode(['success' => false, 'msg' => "Unknown action: $action"]);
}

function getOrCreatePortfolio(PDO $db, int $userId): int {
    $id = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
    $id->execute([$userId]);
    $pid = $id->fetchColumn();
    if ($pid) return (int)$pid;
    $db->prepare("INSERT INTO portfolios (user_id, name, is_default, created_at) VALUES (?,?,1,NOW())")->execute([$userId, 'My Portfolio']);
    return (int)$db->lastInsertId();
}

// ══════════════════════════════════════════════════════════════════════════
// t491: fy_compare — Multi-year side-by-side comparison
// ══════════════════════════════════════════════════════════════════════════
case 'fy_compare':
    // Returns all FYs data for cross-FY comparison chart
    $pid = (int)($_POST['portfolio_id'] ?? 0);
    if (!$pid) {
        // auto-detect
        $r = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
        $r->execute([$userId]);
        $pid = (int)($r->fetchColumn() ?: 0);
    }
    if (!$pid) { echo json_encode(['success'=>false,'error'=>'No portfolio']); break; }

    // Verify ownership
    $chk = $db->prepare("SELECT id FROM portfolios WHERE id=? AND user_id=?");
    $chk->execute([$pid, $userId]);
    if (!$chk->fetch()) { echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }

    // Get all FYs that have transactions
    $fyStmt = $db->prepare("
        SELECT DISTINCT
          CASE WHEN MONTH(t.tx_date) >= 4
               THEN CONCAT(YEAR(t.tx_date),'-',LPAD(SUBSTR(YEAR(t.tx_date)+1,3,2),2,'0'))
               ELSE CONCAT(YEAR(t.tx_date)-1,'-',LPAD(SUBSTR(YEAR(t.tx_date),3,2),2,'0'))
          END AS fy
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        WHERE mh.portfolio_id = ?
        ORDER BY fy ASC
    ");
    $fyStmt->execute([$pid]);
    $fyList = $fyStmt->fetchAll(PDO::FETCH_COLUMN);

    $comparison = [];
    foreach ($fyList as $fy) {
        $parts = explode('-', $fy);
        $y1 = $parts[0];
        $y2 = strlen($parts[1]) == 2 ? '20'.$parts[1] : $parts[1];
        $from = "{$y1}-04-01";
        $to   = "{$y2}-03-31";

        // Invested this FY (buys)
        $buyQ = $db->prepare("
            SELECT COALESCE(SUM(t.amount),0) AS invested
            FROM mf_transactions t
            JOIN mf_holdings mh ON mh.id = t.holding_id
            WHERE mh.portfolio_id=? AND t.tx_type IN ('buy','sip','switch_in','lumpsum')
              AND t.tx_date BETWEEN ? AND ?
        ");
        $buyQ->execute([$pid, $from, $to]);
        $invested = (float)$buyQ->fetchColumn();

        // Realised gains this FY (sells)
        $sellQ = $db->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN DATEDIFF(t.tx_date, tb.tx_date) > 365 THEN (t.price_per_unit - tb.price_per_unit)*t.units ELSE 0 END),0) AS ltcg,
              COALESCE(SUM(CASE WHEN DATEDIFF(t.tx_date, tb.tx_date) <= 365 THEN (t.price_per_unit - tb.price_per_unit)*t.units ELSE 0 END),0) AS stcg,
              COALESCE(SUM(t.amount),0) AS redeemed
            FROM mf_transactions t
            JOIN mf_holdings mh ON mh.id = t.holding_id
            LEFT JOIN mf_transactions tb ON tb.holding_id = t.holding_id
              AND tb.tx_type IN ('buy','sip','lumpsum','switch_in')
              AND tb.tx_date = (
                SELECT MIN(tb2.tx_date) FROM mf_transactions tb2
                WHERE tb2.holding_id = t.holding_id
                  AND tb2.tx_type IN ('buy','sip','lumpsum','switch_in')
                  AND tb2.tx_date <= t.tx_date
              )
            WHERE mh.portfolio_id=?
              AND t.tx_type IN ('sell','swp','switch_out','redemption')
              AND t.tx_date BETWEEN ? AND ?
        ");
        $sellQ->execute([$pid, $from, $to]);
        $gains = $sellQ->fetch(PDO::FETCH_ASSOC);

        // SIP count this FY
        $sipQ = $db->prepare("
            SELECT COUNT(*) FROM mf_transactions t
            JOIN mf_holdings mh ON mh.id = t.holding_id
            WHERE mh.portfolio_id=? AND t.tx_type='sip'
              AND t.tx_date BETWEEN ? AND ?
        ");
        $sipQ->execute([$pid, $from, $to]);
        $sipCount = (int)$sipQ->fetchColumn();

        // Portfolio value at FY end (latest NAV × units held as of FY end)
        $valQ = $db->prepare("
            SELECT COALESCE(SUM(
              (SELECT COALESCE(SUM(CASE WHEN tx_type IN ('buy','sip','lumpsum','switch_in') THEN units
                                        WHEN tx_type IN ('sell','swp','switch_out','redemption') THEN -units
                                        ELSE 0 END),0)
               FROM mf_transactions t2
               WHERE t2.holding_id = mh.id AND t2.tx_date <= ?)
              * COALESCE((SELECT nav FROM nav_history nh WHERE nh.fund_id=mh.fund_id AND nh.nav_date <= ? ORDER BY nh.nav_date DESC LIMIT 1),
                          (SELECT latest_nav FROM funds WHERE id=mh.fund_id))
            ),0) AS portfolio_value
            FROM mh_holdings mh
            WHERE mh.portfolio_id=?
        ");
        // simplified — use holdings table directly
        $valQ2 = $db->prepare("
            SELECT COALESCE(SUM(mh.latest_value),0) FROM mf_holdings mh WHERE mh.portfolio_id=?
        ");
        $valQ2->execute([$pid]);
        $portfolioVal = (float)$valQ2->fetchColumn();

        $comparison[] = [
            'fy'            => $fy,
            'invested'      => round($invested, 2),
            'redeemed'      => round((float)($gains['redeemed'] ?? 0), 2),
            'ltcg'          => round((float)($gains['ltcg'] ?? 0), 2),
            'stcg'          => round((float)($gains['stcg'] ?? 0), 2),
            'total_gains'   => round((float)($gains['ltcg'] ?? 0) + (float)($gains['stcg'] ?? 0), 2),
            'sip_count'     => $sipCount,
        ];
    }

    echo json_encode(['success'=>true, 'data'=>['fy_list'=>$fyList, 'comparison'=>$comparison]]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// t491: fy_holdings_span — Holdings that span multiple FYs
// ══════════════════════════════════════════════════════════════════════════
case 'fy_holdings_span':
    $pid = (int)($_POST['portfolio_id'] ?? 0);
    if (!$pid) {
        $r = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
        $r->execute([$userId]);
        $pid = (int)($r->fetchColumn() ?: 0);
    }
    $chk = $db->prepare("SELECT id FROM portfolios WHERE id=? AND user_id=?");
    $chk->execute([$pid, $userId]);
    if (!$chk->fetch()) { echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }

    $stmt = $db->prepare("
        SELECT
          f.fund_name,
          f.category,
          MIN(t.tx_date) AS first_buy,
          MAX(t.tx_date) AS last_tx,
          DATEDIFF(CURDATE(), MIN(t.tx_date)) AS holding_days,
          ROUND(DATEDIFF(CURDATE(), MIN(t.tx_date)) / 365.25, 1) AS holding_years,
          COUNT(DISTINCT CASE WHEN MONTH(t.tx_date) >= 4
               THEN CONCAT(YEAR(t.tx_date),'-',LPAD(SUBSTR(YEAR(t.tx_date)+1,3,2),2,'0'))
               ELSE CONCAT(YEAR(t.tx_date)-1,'-',LPAD(SUBSTR(YEAR(t.tx_date),3,2),2,'0'))
          END) AS fy_count,
          mh.invested_value,
          mh.latest_value,
          ROUND((mh.latest_value - mh.invested_value) / NULLIF(mh.invested_value,0) * 100, 2) AS gain_pct
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ?
          AND t.tx_type IN ('buy','sip','lumpsum','switch_in')
          AND mh.units > 0.001
        GROUP BY mh.id, f.fund_name, f.category, mh.invested_value, mh.latest_value
        HAVING fy_count >= 2
        ORDER BY holding_years DESC
    ");
    $stmt->execute([$pid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'data'=>$rows]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// t286: schedule_112a — ITR Schedule 112A LTCG table (Equity MF + Stocks)
// ISIN-wise, FIFO, Grandfathering (Jan 31 2018 fair value)
// ══════════════════════════════════════════════════════════════════════════
case 'schedule_112a':
    $fy    = $_GET['fy'] ?? currentFY();
    $dates = getFYDates($fy);

    // Jan 31 2018 — grandfathering cutoff for equity assets
    $GRANDFATHER_DATE = '2018-01-31';

    // Fetch all equity SELL transactions in the FY with ISIN
    $sellStmt = $db->prepare("
        SELECT
          t.id          AS tx_id,
          t.tx_date     AS sale_date,
          t.units       AS sold_units,
          t.price_per_unit AS sale_nav,
          t.amount      AS sale_amount,
          t.holding_id,
          f.fund_name,
          f.scheme_code,
          f.isin,
          f.category,
          f.amc_name
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        JOIN portfolios p   ON p.id  = mh.portfolio_id
        JOIN funds f        ON f.id  = mh.fund_id
        WHERE p.user_id = ?
          AND t.tx_type IN ('sell','swp','switch_out','redemption')
          AND t.tx_date BETWEEN ? AND ?
          AND (
            LOWER(f.category) LIKE '%equity%'
            OR LOWER(f.category) LIKE '%elss%'
            OR LOWER(f.category) LIKE '%index%'
            OR LOWER(f.category) LIKE '%hybrid%'
          )
        ORDER BY f.fund_name ASC, t.tx_date ASC
    ");
    $sellStmt->execute([$userId, $dates['from'], $dates['to']]);
    $sells = $sellStmt->fetchAll(PDO::FETCH_ASSOC);

    $rows112A    = [];
    $ltcgTotal   = 0.0;
    $stcgTotal   = 0.0;
    $ltcgExempt  = 0.0;

    foreach ($sells as $sell) {
        $holdingId  = $sell['holding_id'];
        $soldUnits  = (float)$sell['sold_units'];
        $saleDate   = $sell['sale_date'];
        $saleNav    = (float)$sell['sale_nav'];
        $saleAmt    = (float)$sell['sale_amount'];

        // FIFO: fetch buy transactions for this holding up to sale date
        $buyStmt = $db->prepare("
            SELECT tx_date AS buy_date, units AS buy_units, price_per_unit AS buy_nav
            FROM mf_transactions
            WHERE holding_id = ?
              AND tx_type IN ('buy','sip','lumpsum','switch_in')
              AND tx_date <= ?
            ORDER BY tx_date ASC
        ");
        $buyStmt->execute([$holdingId, $saleDate]);
        $buyRows = $buyStmt->fetchAll(PDO::FETCH_ASSOC);

        $unitsLeft     = $soldUnits;
        $costBasis     = 0.0;
        $grandCost     = 0.0;  // grandfathered cost (Jan 31 2018 fair value)
        $firstBuyDate  = null;
        $hasGrandfathered = false;

        foreach ($buyRows as $buy) {
            if ($unitsLeft <= 0) break;
            $usableUnits = min((float)$buy['buy_units'], $unitsLeft);
            $buyNav      = (float)$buy['buy_nav'];
            $buyDate     = $buy['buy_date'];

            if (!$firstBuyDate) $firstBuyDate = $buyDate;

            if ($buyDate < $GRANDFATHER_DATE) {
                // Grandfathered: cost = max(actual purchase NAV, Jan31 2018 NAV)
                // We use actual NAV as proxy if grandfather NAV not available in DB
                $grandfatherNav = $db->prepare("
                    SELECT nav FROM nav_history
                    WHERE fund_id = (SELECT fund_id FROM mf_holdings WHERE id = ?)
                      AND nav_date <= ?
                    ORDER BY nav_date DESC LIMIT 1
                ");
                $grandfatherNav->execute([$holdingId, $GRANDFATHER_DATE]);
                $jan31Nav = (float)($grandfatherNav->fetchColumn() ?: $buyNav);
                $effectiveCost = $usableUnits * max($buyNav, $jan31Nav);
                $hasGrandfathered = true;
            } else {
                $effectiveCost = $usableUnits * $buyNav;
            }

            $costBasis  += $effectiveCost;
            $unitsLeft  -= $usableUnits;
        }

        if ($firstBuyDate === null) $firstBuyDate = $saleDate;
        $holdingDays = (int)((strtotime($saleDate) - strtotime($firstBuyDate)) / 86400);
        $isLTCG      = $holdingDays >= 365;
        $gain        = $saleAmt - $costBasis;

        if ($isLTCG) {
            $ltcgTotal += max(0, $gain);
        } else {
            $stcgTotal += max(0, $gain);
        }

        $rows112A[] = [
            'isin'               => $sell['isin'] ?: $sell['scheme_code'],
            'fund_name'          => $sell['fund_name'],
            'amc'                => $sell['amc_name'] ?? '',
            'category'           => $sell['category'],
            'units_sold'         => round($soldUnits, 4),
            'sale_date'          => $saleDate,
            'sale_nav'           => round($saleNav, 4),
            'sale_amount'        => round($saleAmt, 2),
            'cost_of_acquisition'=> round($costBasis, 2),
            'gain_loss'          => round($gain, 2),
            'holding_days'       => $holdingDays,
            'gain_type'          => $isLTCG ? 'LTCG' : 'STCG',
            'tax_rate_pct'       => $isLTCG ? 12.5 : 20,  // post-Jul 2024 Budget rates
            'grandfathered'      => $hasGrandfathered,
            'purchase_date'      => $firstBuyDate,
        ];
    }

    $ltcgExempt   = min($ltcgTotal, ltcgTaxFree());
    $ltcgTaxable  = max(0, $ltcgTotal - ltcgTaxFree());
    $ltcgTax      = round($ltcgTaxable * 0.125, 2);  // 12.5% post Jul 2024
    $stcgTax      = round(max(0, $stcgTotal) * 0.20, 2);  // 20% STCG post Jul 2024

    echo json_encode([
        'success' => true,
        'fy'      => $fy,
        'rows'    => $rows112A,
        'summary' => [
            'total_transactions' => count($rows112A),
            'ltcg_total'         => round($ltcgTotal, 2),
            'ltcg_exempt'        => round($ltcgExempt, 2),
            'ltcg_taxable'       => round($ltcgTaxable, 2),
            'ltcg_tax'           => $ltcgTax,
            'stcg_total'         => round($stcgTotal, 2),
            'stcg_tax'           => $stcgTax,
            'total_estimated_tax'=> round($ltcgTax + $stcgTax, 2),
            'ltcg_limit'         => ltcgTaxFree(),
            'note_rates'         => 'Rates: LTCG 12.5% (post Jul 23, 2024), STCG 20%. Grandfathering applied for pre-Jan 2018 units.',
        ],
    ]);
    break;
