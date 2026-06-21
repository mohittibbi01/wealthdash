<?php
/**
 * WealthDash — tc003: DeFi & Staking Income Tracker
 *
 * Track DeFi protocol positions (LP, Lending, Staking, Yield Farming)
 * and on-chain staking rewards across protocols and chains.
 *
 * Actions handled (routed from router.php):
 *   GET  crypto_defi_list    — list all DeFi positions (with live INR value)
 *   POST crypto_defi_add     — add new position
 *   POST crypto_defi_edit    — edit APY / current value / rewards
 *   POST crypto_defi_close   — close position (record exit value + P&L)
 *   POST crypto_defi_delete  — delete position record
 *   GET  crypto_defi_summary — summary stats (TVL, total income, chain/protocol breakdown)
 *
 * Router note (add to router.php after crypto block ~line 832):
 *   case 'crypto_defi_list':
 *   case 'crypto_defi_add':
 *   case 'crypto_defi_edit':
 *   case 'crypto_defi_close':
 *   case 'crypto_defi_delete':
 *   case 'crypto_defi_summary':
 *       require APP_ROOT . '/api/crypto/crypto_defi.php'; exit;
 *
 * DB deps: crypto_defi_positions (tc003_migration.sql)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$isAdmin     = (bool)($currentUser['is_admin'] ?? false);
$db          = DB::pdo();
$action      = $_GET['action'] ?? $_POST['action'] ?? '';
$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);

// Build portfolio WHERE clause
$pWhere = $portfolioId
    ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}"
    : "AND p.user_id = {$userId}";

// ── Constants ──────────────────────────────────────────────────────────────────
const TC003_PROTOCOLS = [
    'Uniswap','Aave','Compound','Curve','SushiSwap','PancakeSwap',
    'Yearn','Balancer','Synthetix','MakerDAO','Lido','Rocket Pool',
    'Convex','Frax','GMX','dYdX','Blur','Blur Blend','WazirX',
    'Other'
];
const TC003_CHAINS = [
    'Ethereum','Binance Smart Chain','Polygon','Solana','Avalanche',
    'Arbitrum','Optimism','Base','Fantom','Tron','Near','Other'
];
const TC003_TYPES = [
    'STAKING'         => 'Staking',
    'LIQUID_STAKING'  => 'Liquid Staking',
    'LP'              => 'Liquidity Pool',
    'LENDING'         => 'Lending',
    'YIELD_FARMING'   => 'Yield Farming',
    'VAULT'           => 'Vault / Auto-compound',
    'OTHER'           => 'Other',
];

// ── Helpers ────────────────────────────────────────────────────────────────────
function tc003_verify_ownership(int $id, int $userId, bool $isAdmin): array
{
    $row = DB::fetchOne(
        "SELECT dp.id, p.user_id FROM crypto_defi_positions dp
         JOIN portfolios p ON p.id = dp.portfolio_id WHERE dp.id = ? LIMIT 1",
        [$id]
    );
    if (!$row || ((int)$row['user_id'] !== $userId && !$isAdmin)) {
        json_response(false, 'Position not found or access denied.');
    }
    return $row;
}

function tc003_days_active(string $entryDate, ?string $exitDate = null): int
{
    try {
        $start = new DateTime($entryDate);
        $end   = $exitDate ? new DateTime($exitDate) : new DateTime();
        return max(0, (int)$start->diff($end)->days);
    } catch (\Exception $e) {
        return 0;
    }
}

// ════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ════════════════════════════════════════════════════════════════════════════
switch ($action) {

    // ── LIST POSITIONS ────────────────────────────────────────────────────
    case 'crypto_defi_list': {
        $status  = clean($_GET['status'] ?? 'ACTIVE');
        $chain   = clean($_GET['chain']  ?? '');
        $proto   = clean($_GET['protocol'] ?? '');

        $where  = "WHERE dp.status = ? {$pWhere}";
        $params = [$status];

        if ($chain) { $where  .= ' AND dp.chain = ?';    $params[] = $chain; }
        if ($proto) { $where  .= ' AND dp.protocol = ?'; $params[] = $proto; }

        $rows = DB::fetchAll(
            "SELECT dp.*,
                    p.name AS portfolio_name
             FROM crypto_defi_positions dp
             JOIN portfolios p ON p.id = dp.portfolio_id
             {$where}
             ORDER BY dp.status ASC, dp.entry_date DESC",
            $params
        );

        // Enrich: P&L, days active, annualised income estimate
        $totalTvl     = 0.0;
        $totalIncome  = 0.0;
        $totalPnl     = 0.0;

        foreach ($rows as &$r) {
            $principal  = (float)$r['principal_inr'];
            $curValue   = (float)$r['current_value_inr'];
            $rewards    = (float)$r['rewards_value_inr'];
            $apy        = (float)$r['apy_pct'];
            $days       = tc003_days_active($r['entry_date'], $r['exit_date'] ?: null);

            $unrealised_pnl = $curValue - $principal;
            $total_return   = $unrealised_pnl + $rewards;
            $return_pct     = $principal > 0 ? round($total_return / $principal * 100, 2) : 0;

            // Annualised income estimate from APY
            $estimated_annual_income = $principal * ($apy / 100);
            $daily_income_est        = $days > 0 ? round($estimated_annual_income / 365, 2) : 0;

            $r['days_active']          = $days;
            $r['unrealised_pnl']       = round($unrealised_pnl, 2);
            $r['total_return_inr']     = round($total_return, 2);
            $r['return_pct']           = $return_pct;
            $r['est_annual_income_inr']= round($estimated_annual_income, 2);
            $r['daily_income_est']     = $daily_income_est;
            $r['position_type_label']  = TC003_TYPES[$r['position_type']] ?? $r['position_type'];

            if ($r['status'] === 'ACTIVE') {
                $totalTvl    += $curValue;
                $totalIncome += $rewards;
                $totalPnl    += $unrealised_pnl;
            }
        }
        unset($r);

        // Protocol / chain breakdown for ACTIVE only
        $byProtocol = []; $byChain = [];
        foreach ($rows as $r) {
            if ($r['status'] !== 'ACTIVE') continue;
            $proto = $r['protocol'];
            $chain = $r['chain'];
            if (!isset($byProtocol[$proto])) $byProtocol[$proto] = ['protocol' => $proto, 'tvl' => 0, 'count' => 0];
            $byProtocol[$proto]['tvl']   += (float)$r['current_value_inr'];
            $byProtocol[$proto]['count'] ++;
            if (!isset($byChain[$chain])) $byChain[$chain] = ['chain' => $chain, 'tvl' => 0, 'count' => 0];
            $byChain[$chain]['tvl']   += (float)$r['current_value_inr'];
            $byChain[$chain]['count'] ++;
        }

        json_response(true, '', [
            'positions'    => $rows,
            'summary' => [
                'total_tvl'           => round($totalTvl, 2),
                'total_rewards_earned'=> round($totalIncome, 2),
                'total_unrealised_pnl'=> round($totalPnl, 2),
                'active_count'        => count(array_filter($rows, fn($r) => $r['status'] === 'ACTIVE')),
            ],
            'by_protocol' => array_values($byProtocol),
            'by_chain'    => array_values($byChain),
        ]);
        break;
    }

    // ── ADD POSITION ──────────────────────────────────────────────────────
    case 'crypto_defi_add': {
        $pid          = (int)($_POST['portfolio_id'] ?? 0) ?: get_user_portfolio_id($userId);
        $protocol     = clean($_POST['protocol']      ?? '');
        $chain        = clean($_POST['chain']         ?? 'Ethereum');
        $posType      = strtoupper(clean($_POST['position_type'] ?? 'STAKING'));
        $coinId       = clean($_POST['coin_id']       ?? '');
        $coinSymbol   = strtoupper(clean($_POST['coin_symbol'] ?? ''));
        $coinName     = clean($_POST['coin_name']     ?? $coinSymbol);
        $pairSymbol   = clean($_POST['pair_symbol']   ?? '');
        $walletAddr   = clean($_POST['wallet_address'] ?? '');
        $principalInr = (float)($_POST['principal_inr'] ?? 0);
        $entryDate    = clean($_POST['entry_date']    ?? date('Y-m-d'));
        $apyPct       = (float)($_POST['apy_pct']     ?? 0);
        $rewardsCoinId  = clean($_POST['rewards_coin_id']     ?? $coinId);
        $rewardsCoinSym = strtoupper(clean($_POST['rewards_coin_symbol'] ?? $coinSymbol));
        $notes        = clean($_POST['notes']         ?? '');

        if (!$pid)        json_response(false, 'Portfolio required.');
        if (!$protocol)   json_response(false, 'Protocol required (e.g. Aave, Uniswap).');
        if (!$coinSymbol) json_response(false, 'Coin symbol required.');
        if (!in_array($posType, array_keys(TC003_TYPES))) json_response(false, 'Invalid position type.');
        if (!can_access_portfolio($pid, $userId, $isAdmin)) json_response(false, 'Access denied.');

        $curValue = $principalInr; // Initial current value = principal

        $id = DB::insert(
            "INSERT INTO crypto_defi_positions
             (portfolio_id, protocol, chain, position_type,
              coin_id, coin_symbol, coin_name, pair_symbol,
              wallet_address, principal_inr, current_value_inr, entry_date,
              apy_pct, rewards_coin_id, rewards_coin_symbol,
              rewards_quantity, rewards_value_inr, status, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,'ACTIVE',?)",
            [$pid, $protocol, $chain, $posType,
             $coinId ?: null, $coinSymbol, $coinName, $pairSymbol ?: null,
             $walletAddr ?: null, $principalInr, $curValue, $entryDate,
             $apyPct, $rewardsCoinId ?: null, $rewardsCoinSym ?: null,
             $notes ?: null]
        );

        audit_log('crypto_defi_add', 'crypto_defi_positions', (int)$id);
        json_response(true, "DeFi position added: {$protocol} ({$posType}).", [
            'id'           => $id,
            'protocol'     => $protocol,
            'position_type'=> $posType,
        ]);
        break;
    }

    // ── EDIT POSITION ─────────────────────────────────────────────────────
    case 'crypto_defi_edit': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'ID required.');
        tc003_verify_ownership($id, $userId, $isAdmin);

        $sets   = ['updated_at = NOW()'];
        $params = [];

        $fields = [
            'protocol'          => 'protocol',
            'chain'             => 'chain',
            'apy_pct'           => 'apy_pct',
            'current_value_inr' => 'current_value_inr',
            'rewards_quantity'  => 'rewards_quantity',
            'rewards_value_inr' => 'rewards_value_inr',
            'notes'             => 'notes',
            'wallet_address'    => 'wallet_address',
            'pair_symbol'       => 'pair_symbol',
        ];

        foreach ($fields as $postKey => $dbCol) {
            if (isset($_POST[$postKey]) && $_POST[$postKey] !== '') {
                $sets[]   = "{$dbCol} = ?";
                $params[] = in_array($dbCol, ['apy_pct','current_value_inr','rewards_quantity','rewards_value_inr'])
                    ? (float)$_POST[$postKey]
                    : clean($_POST[$postKey]);
            }
        }

        if (count($sets) === 1) json_response(false, 'Nothing to update.');

        $params[] = $id;
        DB::execute("UPDATE crypto_defi_positions SET " . implode(', ', $sets) . " WHERE id = ?", $params);
        audit_log('crypto_defi_edit', 'crypto_defi_positions', $id);
        json_response(true, 'Position updated.');
        break;
    }

    // ── CLOSE POSITION ─────────────────────────────────────────────────────
    case 'crypto_defi_close': {
        $id            = (int)($_POST['id'] ?? 0);
        $exitValueInr  = (float)($_POST['exit_value_inr'] ?? 0);
        $exitDate      = clean($_POST['exit_date'] ?? date('Y-m-d'));
        $finalRewards  = (float)($_POST['final_rewards_value_inr'] ?? 0);
        $notes         = clean($_POST['notes'] ?? '');

        if (!$id) json_response(false, 'ID required.');
        tc003_verify_ownership($id, $userId, $isAdmin);

        $pos = DB::fetchOne("SELECT * FROM crypto_defi_positions WHERE id = ?", [$id]);
        if (!$pos) json_response(false, 'Position not found.');

        $pnl = $exitValueInr - (float)$pos['principal_inr'];

        DB::execute(
            "UPDATE crypto_defi_positions SET
             status = 'CLOSED', exit_date = ?, exit_value_inr = ?,
             realised_pnl_inr = ?,
             rewards_value_inr = IF(? > 0, ?, rewards_value_inr),
             notes = IF(? <> '', CONCAT(COALESCE(notes,''), '\n[Closed] ', ?), notes),
             updated_at = NOW()
             WHERE id = ?",
            [$exitDate, $exitValueInr, round($pnl, 2),
             $finalRewards, $finalRewards,
             $notes, $notes,
             $id]
        );

        audit_log('crypto_defi_close', 'crypto_defi_positions', $id);
        json_response(true, 'Position closed. P&L: ₹' . number_format($pnl, 2), [
            'id'            => $id,
            'exit_value_inr'=> $exitValueInr,
            'realised_pnl'  => round($pnl, 2),
        ]);
        break;
    }

    // ── DELETE POSITION ───────────────────────────────────────────────────
    case 'crypto_defi_delete': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'ID required.');
        tc003_verify_ownership($id, $userId, $isAdmin);

        DB::execute("DELETE FROM crypto_defi_positions WHERE id = ?", [$id]);
        audit_log('crypto_defi_delete', 'crypto_defi_positions', $id);
        json_response(true, 'Position deleted.');
        break;
    }

    // ── SUMMARY STATS ─────────────────────────────────────────────────────
    case 'crypto_defi_summary': {
        // Aggregate stats
        $agg = DB::fetchOne(
            "SELECT
                COUNT(CASE WHEN dp.status='ACTIVE' THEN 1 END)    AS active_count,
                COUNT(CASE WHEN dp.status='CLOSED' THEN 1 END)    AS closed_count,
                SUM(CASE WHEN dp.status='ACTIVE' THEN dp.current_value_inr  ELSE 0 END) AS total_tvl,
                SUM(CASE WHEN dp.status='ACTIVE' THEN dp.principal_inr      ELSE 0 END) AS total_principal,
                SUM(dp.rewards_value_inr)                          AS total_rewards_earned,
                SUM(CASE WHEN dp.status='CLOSED' THEN dp.realised_pnl_inr ELSE 0 END) AS total_realised_pnl,
                AVG(CASE WHEN dp.status='ACTIVE' THEN dp.apy_pct END)      AS avg_apy
             FROM crypto_defi_positions dp
             JOIN portfolios p ON p.id = dp.portfolio_id
             WHERE 1=1 {$pWhere}"
        );

        $tvl       = (float)($agg['total_tvl']       ?? 0);
        $principal = (float)($agg['total_principal'] ?? 0);
        $unrealPnl = $tvl - $principal;

        // Protocol breakdown
        $byProtocol = DB::fetchAll(
            "SELECT dp.protocol,
                    COUNT(*)                   AS position_count,
                    SUM(dp.current_value_inr)  AS tvl,
                    SUM(dp.rewards_value_inr)  AS rewards,
                    AVG(dp.apy_pct)            AS avg_apy
             FROM crypto_defi_positions dp
             JOIN portfolios p ON p.id = dp.portfolio_id
             WHERE dp.status = 'ACTIVE' {$pWhere}
             GROUP BY dp.protocol
             ORDER BY tvl DESC"
        );

        // Chain breakdown
        $byChain = DB::fetchAll(
            "SELECT dp.chain,
                    COUNT(*)                  AS position_count,
                    SUM(dp.current_value_inr) AS tvl
             FROM crypto_defi_positions dp
             JOIN portfolios p ON p.id = dp.portfolio_id
             WHERE dp.status = 'ACTIVE' {$pWhere}
             GROUP BY dp.chain
             ORDER BY tvl DESC"
        );

        // Position type breakdown
        $byType = DB::fetchAll(
            "SELECT dp.position_type,
                    COUNT(*)                  AS count,
                    SUM(dp.current_value_inr) AS tvl,
                    SUM(dp.rewards_value_inr) AS rewards
             FROM crypto_defi_positions dp
             JOIN portfolios p ON p.id = dp.portfolio_id
             WHERE dp.status = 'ACTIVE' {$pWhere}
             GROUP BY dp.position_type"
        );

        // Best performing position (by total return %)
        $bestPos = DB::fetchOne(
            "SELECT dp.protocol, dp.coin_symbol, dp.position_type,
                    dp.principal_inr, dp.current_value_inr, dp.rewards_value_inr,
                    ROUND(((dp.current_value_inr + dp.rewards_value_inr - dp.principal_inr)
                           / NULLIF(dp.principal_inr, 0) * 100), 2) AS return_pct
             FROM crypto_defi_positions dp
             JOIN portfolios p ON p.id = dp.portfolio_id
             WHERE dp.status = 'ACTIVE' AND dp.principal_inr > 0 {$pWhere}
             ORDER BY return_pct DESC LIMIT 1"
        );

        json_response(true, '', [
            'summary' => [
                'active_positions'   => (int)($agg['active_count']        ?? 0),
                'closed_positions'   => (int)($agg['closed_count']        ?? 0),
                'total_tvl'          => round($tvl, 2),
                'total_principal'    => round($principal, 2),
                'unrealised_pnl'     => round($unrealPnl, 2),
                'unrealised_pnl_pct' => $principal > 0 ? round($unrealPnl / $principal * 100, 2) : 0,
                'total_rewards_earned'=> round((float)($agg['total_rewards_earned'] ?? 0), 2),
                'total_realised_pnl' => round((float)($agg['total_realised_pnl']   ?? 0), 2),
                'avg_apy'            => round((float)($agg['avg_apy']              ?? 0), 2),
            ],
            'by_protocol'  => $byProtocol,
            'by_chain'     => $byChain,
            'by_type'      => $byType,
            'best_position'=> $bestPos,
        ]);
        break;
    }

    default:
        json_response(false, "Unknown DeFi action: {$action}");
}
