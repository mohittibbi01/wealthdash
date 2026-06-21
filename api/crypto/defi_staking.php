<?php
/**
 * WealthDash — tc003: DeFi & Staking Income Tracker
 * Actions: defi_list, defi_add, defi_update, defi_delete,
 *          defi_income_add, defi_income_list, defi_summary,
 *          defi_platforms, defi_tax_report
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = clean($_GET['action'] ?? $_POST['action'] ?? '');

function defi_portfolio(int $userId): ?int
{
    $pid = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
    if ($pid && can_access_portfolio($pid, $userId, is_admin())) return $pid;
    return get_user_portfolio_id($userId) ?: null;
}

function defi_position_access(int $id, int $userId): ?array
{
    return DB::fetchOne(
        "SELECT * FROM defi_positions WHERE id=? AND user_id=? AND is_active=1",
        [$id, $userId]
    ) ?: null;
}

switch ($action) {

    // ─── LIST POSITIONS ───────────────────────────────────────────────────────
    case 'defi_list':
        $rows = DB::fetchAll("
            SELECT p.*,
                COALESCE(SUM(il.amount_inr), 0) AS total_income_inr,
                COUNT(il.id)                     AS income_events
            FROM defi_positions p
            LEFT JOIN defi_income_log il ON il.position_id = p.id
            WHERE p.user_id = ? AND p.is_active = 1
            GROUP BY p.id
            ORDER BY p.staked_value_inr DESC
        ", [$userId]);
        json_response(true, '', ['data' => $rows]);


    // ─── SUMMARY ─────────────────────────────────────────────────────────────
    case 'defi_summary':
        $sum = DB::fetchOne("
            SELECT
                COUNT(DISTINCT p.id)                  AS total_positions,
                COALESCE(SUM(p.staked_value_inr), 0)  AS total_staked,
                COALESCE(SUM(il.amount_inr), 0)        AS total_income_inr
            FROM defi_positions p
            LEFT JOIN defi_income_log il ON il.position_id = p.id
            WHERE p.user_id = ? AND p.is_active = 1
        ", [$userId]);

        // By type
        $byType = DB::fetchAll("
            SELECT p.position_type,
                   COUNT(*) AS count,
                   SUM(p.staked_value_inr) AS staked
            FROM defi_positions p
            WHERE p.user_id=? AND p.is_active=1
            GROUP BY p.position_type
        ", [$userId]);

        // Recent income (last 30 days)
        $recentIncome = DB::fetchOne("
            SELECT COALESCE(SUM(il.amount_inr),0) AS amount
            FROM defi_income_log il
            JOIN defi_positions p ON p.id = il.position_id
            WHERE p.user_id=? AND il.income_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ", [$userId]);

        json_response(true, '', [
            'summary'       => $sum,
            'by_type'       => $byType,
            'income_30d'    => $recentIncome['amount'] ?? 0,
        ]);


    // ─── ADD POSITION ─────────────────────────────────────────────────────────
    case 'defi_add':
        $platform    = clean($_POST['platform']       ?? '');
        $posType     = in_array($_POST['position_type'] ?? '', ['staking','lending','liquidity','yield_farming','vault','other'])
                       ? $_POST['position_type'] : 'staking';
        $asset       = clean($_POST['asset_symbol']   ?? '');
        $assetName   = clean($_POST['asset_name']     ?? $asset);
        $amount      = (float)($_POST['staked_amount']?? 0);
        $valueInr    = (float)($_POST['staked_value_inr'] ?? 0);
        $apy         = (float)($_POST['apy_pct']      ?? 0);
        $startDate   = clean($_POST['start_date']     ?? date('Y-m-d'));
        $lockupDays  = (int) ($_POST['lockup_days']   ?? 0);
        $rewardSymbol= clean($_POST['reward_symbol']  ?? $asset);
        $chainName   = clean($_POST['chain_name']     ?? '');
        $contractAddr= clean($_POST['contract_address']?? '');
        $notes       = clean($_POST['notes']          ?? '');

        if (!$platform || !$asset || $amount <= 0) {
            json_response(false, 'Platform, asset, and staked amount are required.');
        }

        $unlockDate = null;
        if ($lockupDays > 0) {
            $unlockDate = date('Y-m-d', strtotime($startDate . " +{$lockupDays} days"));
        }

        DB::run("
            INSERT INTO defi_positions
            (user_id, platform, position_type, asset_symbol, asset_name,
             staked_amount, staked_value_inr, apy_pct, start_date, lockup_days,
             unlock_date, reward_symbol, chain_name, contract_address, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ", [$userId, $platform, $posType, strtoupper($asset), $assetName,
            $amount, $valueInr, $apy, $startDate, $lockupDays,
            $unlockDate, strtoupper($rewardSymbol) ?: null,
            $chainName ?: null, $contractAddr ?: null, $notes ?: null]);

        json_response(true, 'DeFi position added.', ['id' => (int)$db->lastInsertId()]);


    // ─── UPDATE POSITION ──────────────────────────────────────────────────────
    case 'defi_update':
        $id = (int)($_POST['id'] ?? 0);
        if (!defi_position_access($id, $userId)) { json_response(false, 'Position not found.'); }

        $allowed = ['platform','position_type','staked_amount','staked_value_inr',
                    'apy_pct','lockup_days','unlock_date','reward_symbol','chain_name','notes'];
        $sets = $params = [];
        foreach ($allowed as $f) {
            if (isset($_POST[$f])) { $sets[] = "`{$f}`=?"; $params[] = clean($_POST[$f]); }
        }
        if (!$sets) { json_response(false, 'Nothing to update.'); }
        $params[] = $id;
        DB::run("UPDATE defi_positions SET " . implode(',', $sets) . ", updated_at=NOW() WHERE id=?", $params);
        json_response(true, 'Updated.');


    // ─── DELETE POSITION ──────────────────────────────────────────────────────
    case 'defi_delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!defi_position_access($id, $userId)) { json_response(false, 'Position not found.'); }
        DB::run("UPDATE defi_positions SET is_active=0 WHERE id=?", [$id]);
        json_response(true, 'Position removed.');


    // ─── LOG INCOME ───────────────────────────────────────────────────────────
    case 'defi_income_add':
        $positionId  = (int)($_POST['position_id'] ?? 0);
        $incomeDate  = clean($_POST['income_date'] ?? date('Y-m-d'));
        $amountToken = (float)($_POST['amount_token'] ?? 0);
        $priceInr    = (float)($_POST['price_inr']    ?? 0);
        $incomeType  = in_array($_POST['income_type'] ?? '', ['staking','interest','lp_fee','airdrop','bonus','other'])
                       ? $_POST['income_type'] : 'staking';
        $rewardSymbol= clean($_POST['reward_symbol']  ?? '');
        $notes       = clean($_POST['notes']          ?? '');

        if (!$positionId || $amountToken <= 0) {
            json_response(false, 'position_id and amount_token required.');
        }
        if (!defi_position_access($positionId, $userId)) { json_response(false, 'Position not found.'); }

        $amountInr = round($amountToken * $priceInr, 4);

        DB::run("
            INSERT INTO defi_income_log
            (position_id, income_date, income_type, reward_symbol,
             amount_token, price_inr, amount_inr, notes)
            VALUES (?,?,?,?,?,?,?,?)
        ", [$positionId, $incomeDate, $incomeType,
            strtoupper($rewardSymbol) ?: null,
            $amountToken, $priceInr, $amountInr, $notes ?: null]);

        // Update last income date on position
        DB::run("UPDATE defi_positions SET last_income_date=?, updated_at=NOW() WHERE id=?",
            [$incomeDate, $positionId]);

        json_response(true, 'Income logged.', [
            'amount_inr' => $amountInr,
            'id'         => (int)$db->lastInsertId(),
        ]);


    // ─── INCOME LIST ──────────────────────────────────────────────────────────
    case 'defi_income_list':
        $positionId = (int)($_GET['position_id'] ?? 0);
        $limit      = min((int)($_GET['limit'] ?? 100), 500);

        if ($positionId) {
            if (!defi_position_access($positionId, $userId)) { json_response(false, 'Position not found.'); }
            $rows = DB::fetchAll("
                SELECT il.*, p.platform, p.asset_symbol
                FROM defi_income_log il
                JOIN defi_positions p ON p.id = il.position_id
                WHERE il.position_id=?
                ORDER BY il.income_date DESC LIMIT ?
            ", [$positionId, $limit]);
        } else {
            $rows = DB::fetchAll("
                SELECT il.*, p.platform, p.asset_symbol
                FROM defi_income_log il
                JOIN defi_positions p ON p.id = il.position_id
                WHERE p.user_id=?
                ORDER BY il.income_date DESC LIMIT ?
            ", [$userId, $limit]);
        }
        json_response(true, '', ['data' => $rows]);


    // ─── PLATFORMS ────────────────────────────────────────────────────────────
    case 'defi_platforms':
        // Known DeFi platforms list + user's own
        $known = [
            ['name'=>'Lido Finance',    'type'=>'staking',        'chain'=>'Ethereum'],
            ['name'=>'Rocket Pool',     'type'=>'staking',        'chain'=>'Ethereum'],
            ['name'=>'Aave',            'type'=>'lending',        'chain'=>'Ethereum'],
            ['name'=>'Compound',        'type'=>'lending',        'chain'=>'Ethereum'],
            ['name'=>'Uniswap V3',      'type'=>'liquidity',      'chain'=>'Ethereum'],
            ['name'=>'Curve Finance',   'type'=>'liquidity',      'chain'=>'Ethereum'],
            ['name'=>'Yearn Finance',   'type'=>'vault',          'chain'=>'Ethereum'],
            ['name'=>'Convex Finance',  'type'=>'yield_farming',  'chain'=>'Ethereum'],
            ['name'=>'Polygon MATIC',   'type'=>'staking',        'chain'=>'Polygon'],
            ['name'=>'QuickSwap',       'type'=>'liquidity',      'chain'=>'Polygon'],
            ['name'=>'PancakeSwap',     'type'=>'liquidity',      'chain'=>'BSC'],
            ['name'=>'Venus Protocol',  'type'=>'lending',        'chain'=>'BSC'],
            ['name'=>'WazirX Earn',     'type'=>'staking',        'chain'=>'Centralized'],
            ['name'=>'CoinDCX Earn',    'type'=>'staking',        'chain'=>'Centralized'],
            ['name'=>'Mudrex',          'type'=>'yield_farming',  'chain'=>'Centralized'],
        ];
        // User-added platforms
        $userPlatforms = DB::fetchAll("
            SELECT DISTINCT platform FROM defi_positions WHERE user_id=? ORDER BY platform ASC
        ", [$userId]);
        json_response(true, '', ['known' => $known, 'user_platforms' => $userPlatforms]);


    // ─── TAX REPORT ───────────────────────────────────────────────────────────
    case 'defi_tax_report':
        $year = (int)($_GET['year'] ?? date('Y'));

        $income = DB::fetchAll("
            SELECT il.*, p.platform, p.asset_symbol, p.position_type
            FROM defi_income_log il
            JOIN defi_positions p ON p.id = il.position_id
            WHERE p.user_id=? AND YEAR(il.income_date)=?
            ORDER BY il.income_date ASC
        ", [$userId, $year]);

        $totals = DB::fetchOne("
            SELECT
                COALESCE(SUM(il.amount_inr), 0) AS total_inr,
                COUNT(*)                         AS events
            FROM defi_income_log il
            JOIN defi_positions p ON p.id = il.position_id
            WHERE p.user_id=? AND YEAR(il.income_date)=?
        ", [$userId, $year]);

        json_response(true, '', [
            'year'       => $year,
            'income'     => $income,
            'total_inr'  => $totals['total_inr'] ?? 0,
            'events'     => $totals['events']    ?? 0,
            'tax_note'   => 'DeFi staking/lending income is taxable as "Income from Other Sources" under Indian IT Act. VDA rewards taxed at 30% u/s 115BBH.',
        ]);


    default:
        json_response(false, "Unknown DeFi action: {$action}", [], 400);
}
