<?php
/**
 * WealthDash — tc006: Cold Wallet Tracker — Hardware Wallet
 *
 * Track hardware wallet addresses (Ledger, Trezor, Coldcard, etc.)
 * with manual balance updates, chain tracking, and portfolio sync.
 *
 * Actions handled (routed from router.php):
 *   GET  cold_wallet_list    — list all cold wallets for user
 *   POST cold_wallet_add     — add new wallet address
 *   POST cold_wallet_edit    — edit label / notes / alert threshold
 *   POST cold_wallet_delete  — delete wallet record
 *   POST cold_wallet_refresh — manually update balance for a wallet
 *   GET  cold_wallet_summary — total cold storage value + breakdown
 *
 * Router note (add to router.php after crypto block ~line 832):
 *   case 'cold_wallet_list':
 *   case 'cold_wallet_add':
 *   case 'cold_wallet_edit':
 *   case 'cold_wallet_delete':
 *   case 'cold_wallet_refresh':
 *   case 'cold_wallet_summary':
 *       require APP_ROOT . '/api/crypto/crypto_cold_wallet.php'; exit;
 *
 * DB deps: crypto_cold_wallets (tc006_migration.sql)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$isAdmin     = (bool)($currentUser['is_admin'] ?? false);
$db          = DB::pdo();
$action      = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Constants ──────────────────────────────────────────────────────────────────
const TC006_DEVICE_TYPES = [
    'LEDGER'     => 'Ledger',
    'TREZOR'     => 'Trezor',
    'COLDCARD'   => 'Coldcard',
    'BITBOX'     => 'BitBox',
    'KEYSTONE'   => 'Keystone',
    'NGRAVE'     => 'NGRAVE',
    'FOUNDATION' => 'Foundation Passport',
    'PAPER'      => 'Paper Wallet',
    'SEED'       => 'Seed Phrase Only',
    'OTHER'      => 'Other',
];

const TC006_CHAINS = [
    'bitcoin'   => ['name' => 'Bitcoin',    'symbol' => 'BTC', 'explorer' => 'https://blockstream.info/address/'],
    'ethereum'  => ['name' => 'Ethereum',   'symbol' => 'ETH', 'explorer' => 'https://etherscan.io/address/'],
    'solana'    => ['name' => 'Solana',     'symbol' => 'SOL', 'explorer' => 'https://solscan.io/account/'],
    'polygon'   => ['name' => 'Polygon',    'symbol' => 'MATIC','explorer' => 'https://polygonscan.com/address/'],
    'bnb'       => ['name' => 'BNB Chain',  'symbol' => 'BNB', 'explorer' => 'https://bscscan.com/address/'],
    'avalanche' => ['name' => 'Avalanche',  'symbol' => 'AVAX','explorer' => 'https://snowtrace.io/address/'],
    'cardano'   => ['name' => 'Cardano',    'symbol' => 'ADA', 'explorer' => 'https://cardanoscan.io/address/'],
    'tron'      => ['name' => 'Tron',       'symbol' => 'TRX', 'explorer' => 'https://tronscan.org/#/address/'],
    'other'     => ['name' => 'Other',      'symbol' => '',    'explorer' => ''],
];

// ── Helper: verify wallet ownership ───────────────────────────────────────────
function tc006_verify(int $id, int $userId, bool $isAdmin): void
{
    $row = DB::fetchOne(
        "SELECT user_id FROM crypto_cold_wallets WHERE id = ? LIMIT 1", [$id]
    );
    if (!$row || ((int)$row['user_id'] !== $userId && !$isAdmin)) {
        json_response(false, 'Wallet not found or access denied.');
    }
}

// ── Helper: validate address format ───────────────────────────────────────────
function tc006_validate_address(string $chain, string $addr): bool
{
    $addr = trim($addr);
    if (strlen($addr) < 10 || strlen($addr) > 200) return false;

    return match ($chain) {
        'bitcoin'  => (bool)preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}$/', $addr),
        'ethereum',
        'polygon',
        'bnb',
        'avalanche' => (bool)preg_match('/^0x[a-fA-F0-9]{40}$/', $addr),
        'solana'   => (bool)preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $addr),
        'tron'     => (bool)preg_match('/^T[a-zA-Z0-9]{33}$/', $addr),
        default    => true,  // Other chains — accept any non-empty
    };
}

// ════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ════════════════════════════════════════════════════════════════════════════
switch ($action) {

    // ── LIST WALLETS ──────────────────────────────────────────────────────
    case 'cold_wallet_list': {
        $rows = DB::fetchAll(
            "SELECT cw.*,
                    pc.price_inr AS coin_price_inr,
                    pc.change_24h
             FROM crypto_cold_wallets cw
             LEFT JOIN crypto_price_cache pc ON pc.coin_id = cw.chain
             WHERE cw.user_id = ?
             ORDER BY cw.total_value_inr DESC, cw.created_at DESC",
            [$userId]
        );

        $totalValue = 0.0;
        foreach ($rows as &$r) {
            // Live value = quantity × current price (if price available)
            $qty   = (float)$r['quantity'];
            $price = (float)($r['coin_price_inr'] ?? 0);
            if ($price > 0 && $qty > 0) {
                $r['live_value_inr'] = round($qty * $price, 2);
            } else {
                $r['live_value_inr'] = (float)$r['total_value_inr'];
            }

            // Gain/Loss from cost basis
            $costBasis = (float)$r['cost_basis_inr'];
            $liveVal   = (float)$r['live_value_inr'];
            $r['unrealised_pnl'] = $costBasis > 0 ? round($liveVal - $costBasis, 2) : null;
            $r['unrealised_pnl_pct'] = $costBasis > 0
                ? round(($liveVal - $costBasis) / $costBasis * 100, 2) : null;

            // Chain meta
            $chain = strtolower($r['chain']);
            $r['chain_meta'] = TC006_CHAINS[$chain] ?? TC006_CHAINS['other'];
            $r['explorer_url'] = ($r['chain_meta']['explorer'] ?? '') . rawurlencode($r['address']);
            $r['device_label']  = TC006_DEVICE_TYPES[$r['device_type'] ?? 'OTHER'] ?? 'Other';

            // Address masked for display (first 6 + last 4)
            $addr = $r['address'];
            $r['address_masked'] = strlen($addr) > 12
                ? substr($addr, 0, 6) . '…' . substr($addr, -4)
                : $addr;

            $totalValue += (float)$r['live_value_inr'];
        }
        unset($r);

        // Group by chain for summary
        $byChain = [];
        foreach ($rows as $r) {
            $c = $r['chain'];
            if (!isset($byChain[$c])) $byChain[$c] = ['chain' => $c, 'count' => 0, 'value' => 0];
            $byChain[$c]['count']++;
            $byChain[$c]['value'] += (float)$r['live_value_inr'];
        }

        json_response(true, '', [
            'wallets'     => $rows,
            'total_value' => round($totalValue, 2),
            'count'       => count($rows),
            'by_chain'    => array_values($byChain),
            'chains_meta' => TC006_CHAINS,
            'device_types'=> TC006_DEVICE_TYPES,
        ]);
        break;
    }

    // ── ADD WALLET ────────────────────────────────────────────────────────
    case 'cold_wallet_add': {
        $label       = clean($_POST['label']       ?? '');
        $address     = trim(clean($_POST['address']?? ''));
        $chain       = strtolower(clean($_POST['chain'] ?? 'bitcoin'));
        $deviceType  = strtoupper(clean($_POST['device_type'] ?? 'OTHER'));
        $qty         = (float)($_POST['quantity']        ?? 0);
        $costBasis   = (float)($_POST['cost_basis_inr']  ?? 0);
        $purchaseDate= clean($_POST['purchase_date']     ?? date('Y-m-d'));
        $notes       = clean($_POST['notes']             ?? '');
        $alertThresh = (float)($_POST['alert_threshold_pct'] ?? 0);

        if (!$address) json_response(false, 'Wallet address required.');
        if (!$label)   $label = (TC006_CHAINS[$chain]['symbol'] ?? 'Crypto') . ' Cold Wallet';

        // Validate address format
        if (!tc006_validate_address($chain, $address)) {
            json_response(false, "Invalid address format for chain: {$chain}. Please verify.");
        }

        // Duplicate check (same address for this user)
        $exists = DB::fetchOne(
            "SELECT id FROM crypto_cold_wallets WHERE user_id = ? AND address = ? LIMIT 1",
            [$userId, $address]
        );
        if ($exists) json_response(false, 'This address is already tracked.');

        // Get current price from cache for initial value estimate
        $priceRow    = DB::fetchOne(
            "SELECT price_inr FROM crypto_price_cache WHERE coin_id = ? LIMIT 1",
            [$chain]
        );
        $priceInr    = $priceRow ? (float)$priceRow['price_inr'] : 0;
        $totalValue  = $qty > 0 && $priceInr > 0 ? round($qty * $priceInr, 2) : $costBasis;

        $id = DB::insert(
            "INSERT INTO crypto_cold_wallets
             (user_id, label, address, chain, device_type,
              quantity, cost_basis_inr, total_value_inr,
              purchase_date, alert_threshold_pct, notes, last_synced_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL)",
            [$userId, $label, $address, $chain, $deviceType,
             $qty, $costBasis, $totalValue,
             $purchaseDate ?: null,
             $alertThresh ?: null,
             $notes ?: null]
        );

        audit_log('cold_wallet_add', 'crypto_cold_wallets', (int)$id);
        json_response(true, "Cold wallet added: {$label}", [
            'id'           => $id,
            'label'        => $label,
            'address'      => $address,
            'chain'        => $chain,
            'total_value'  => $totalValue,
        ]);
        break;
    }

    // ── EDIT WALLET ───────────────────────────────────────────────────────
    case 'cold_wallet_edit': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'id required.');
        tc006_verify($id, $userId, $isAdmin);

        $sets   = ['updated_at = NOW()'];
        $params = [];

        $fields = [
            'label'               => 'label',
            'notes'               => 'notes',
            'alert_threshold_pct' => 'alert_threshold_pct',
            'cost_basis_inr'      => 'cost_basis_inr',
            'quantity'            => 'quantity',
            'purchase_date'       => 'purchase_date',
        ];

        foreach ($fields as $postKey => $dbCol) {
            if (isset($_POST[$postKey]) && $_POST[$postKey] !== '') {
                $sets[]   = "{$dbCol} = ?";
                $params[] = in_array($dbCol, ['alert_threshold_pct','cost_basis_inr','quantity'])
                    ? (float)$_POST[$postKey]
                    : clean($_POST[$postKey]);
            }
        }

        if (count($sets) === 1) json_response(false, 'Nothing to update.');

        $params[] = $id;
        DB::execute("UPDATE crypto_cold_wallets SET " . implode(', ', $sets) . " WHERE id = ?", $params);
        audit_log('cold_wallet_edit', 'crypto_cold_wallets', $id);
        json_response(true, 'Wallet updated.');
        break;
    }

    // ── DELETE WALLET ─────────────────────────────────────────────────────
    case 'cold_wallet_delete': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'id required.');
        tc006_verify($id, $userId, $isAdmin);

        DB::execute("DELETE FROM crypto_cold_wallets WHERE id = ?", [$id]);
        audit_log('cold_wallet_delete', 'crypto_cold_wallets', $id);
        json_response(true, 'Wallet removed.');
        break;
    }

    // ── MANUAL BALANCE REFRESH ────────────────────────────────────────────
    case 'cold_wallet_refresh': {
        $id          = (int)($_POST['id']               ?? 0);
        $newQty      = isset($_POST['quantity'])    ? (float)$_POST['quantity']     : null;
        $newValueInr = isset($_POST['value_inr'])   ? (float)$_POST['value_inr']   : null;
        $newCostBasis= isset($_POST['cost_basis_inr'])?(float)$_POST['cost_basis_inr']:null;

        if (!$id) json_response(false, 'id required.');
        tc006_verify($id, $userId, $isAdmin);

        $wallet = DB::fetchOne("SELECT * FROM crypto_cold_wallets WHERE id = ?", [$id]);
        if (!$wallet) json_response(false, 'Wallet not found.');

        $sets   = ['last_synced_at = NOW()', 'updated_at = NOW()'];
        $params = [];

        // Update quantity if provided
        if ($newQty !== null) {
            $sets[]   = 'quantity = ?';
            $params[] = $newQty;
        }

        // Compute value: either manual entry or qty × price
        if ($newValueInr !== null) {
            $sets[]   = 'total_value_inr = ?';
            $params[] = $newValueInr;
        } elseif ($newQty !== null) {
            // Try live price from cache
            $priceRow = DB::fetchOne(
                "SELECT price_inr FROM crypto_price_cache WHERE coin_id = ? LIMIT 1",
                [$wallet['chain']]
            );
            if ($priceRow && (float)$priceRow['price_inr'] > 0) {
                $computedVal = round($newQty * (float)$priceRow['price_inr'], 2);
                $sets[]   = 'total_value_inr = ?';
                $params[] = $computedVal;
            }
        }

        if ($newCostBasis !== null) {
            $sets[]   = 'cost_basis_inr = ?';
            $params[] = $newCostBasis;
        }

        $params[] = $id;
        DB::execute("UPDATE crypto_cold_wallets SET " . implode(', ', $sets) . " WHERE id = ?", $params);

        // Fetch updated row
        $updated = DB::fetchOne("SELECT * FROM crypto_cold_wallets WHERE id = ?", [$id]);

        json_response(true, 'Balance updated.', [
            'id'             => $id,
            'quantity'       => (float)$updated['quantity'],
            'total_value_inr'=> (float)$updated['total_value_inr'],
            'last_synced_at' => $updated['last_synced_at'],
        ]);
        break;
    }

    // ── SUMMARY ───────────────────────────────────────────────────────────
    case 'cold_wallet_summary': {
        $agg = DB::fetchOne(
            "SELECT
                COUNT(*)                  AS wallet_count,
                SUM(total_value_inr)      AS total_cold_value,
                SUM(cost_basis_inr)       AS total_cost_basis,
                COUNT(DISTINCT chain)     AS chain_count,
                COUNT(DISTINCT device_type) AS device_count
             FROM crypto_cold_wallets WHERE user_id = ?",
            [$userId]
        );

        $byChain = DB::fetchAll(
            "SELECT chain, device_type,
                    COUNT(*)            AS wallets,
                    SUM(quantity)       AS total_qty,
                    SUM(total_value_inr)AS total_value,
                    SUM(cost_basis_inr) AS cost_basis
             FROM crypto_cold_wallets WHERE user_id = ?
             GROUP BY chain, device_type
             ORDER BY total_value DESC",
            [$userId]
        );

        $byDevice = DB::fetchAll(
            "SELECT device_type, COUNT(*) AS wallets, SUM(total_value_inr) AS total_value
             FROM crypto_cold_wallets WHERE user_id = ?
             GROUP BY device_type ORDER BY total_value DESC",
            [$userId]
        );

        $totalVal  = (float)($agg['total_cold_value'] ?? 0);
        $totalCost = (float)($agg['total_cost_basis'] ?? 0);
        $unrealPnl = $totalCost > 0 ? round($totalVal - $totalCost, 2) : null;

        json_response(true, '', [
            'summary' => [
                'wallet_count'       => (int)($agg['wallet_count']  ?? 0),
                'chain_count'        => (int)($agg['chain_count']   ?? 0),
                'device_count'       => (int)($agg['device_count']  ?? 0),
                'total_cold_value'   => round($totalVal,  2),
                'total_cost_basis'   => round($totalCost, 2),
                'unrealised_pnl'     => $unrealPnl,
                'unrealised_pnl_pct' => $totalCost > 0 ? round($unrealPnl / $totalCost * 100, 2) : null,
            ],
            'by_chain'   => $byChain,
            'by_device'  => $byDevice,
        ]);
        break;
    }

    default:
        json_response(false, "Unknown cold wallet action: {$action}");
}
