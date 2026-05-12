<?php
/**
 * WealthDash — t317: Crypto Exchange P&L Import
 * Parse & import CSV from Binance / WazirX / CoinDCX
 *
 * Actions (router.php must route these):
 *   POST crypto_import_preview  — parse CSV, return preview rows (no DB write)
 *   POST crypto_import_confirm  — persist previewed rows to DB
 *   GET  crypto_import_log      — list past import batches for this user
 *
 * Router cases (for Master merge):
 *   case 'crypto_import_preview':
 *   case 'crypto_import_confirm':
 *   case 'crypto_import_log':
 *       require APP_ROOT . '/api/crypto/crypto_import.php'; exit;
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Resolve a trading symbol/pair → CoinGecko-style coin_id + symbol.
 * e.g. "BTCUSDT" → ['coin_id'=>'bitcoin','coin_symbol'=>'BTC']
 */
function t317_resolve_coin(string $raw): array {
    static $map = [
        'BTC'  => ['bitcoin',         'BTC'],
        'ETH'  => ['ethereum',         'ETH'],
        'BNB'  => ['binancecoin',      'BNB'],
        'SOL'  => ['solana',           'SOL'],
        'ADA'  => ['cardano',          'ADA'],
        'XRP'  => ['ripple',           'XRP'],
        'DOT'  => ['polkadot',         'DOT'],
        'DOGE' => ['dogecoin',         'DOGE'],
        'MATIC'=> ['matic-network',    'MATIC'],
        'POL'  => ['matic-network',    'POL'],
        'SHIB' => ['shiba-inu',        'SHIB'],
        'LTC'  => ['litecoin',         'LTC'],
        'AVAX' => ['avalanche-2',      'AVAX'],
        'LINK' => ['chainlink',        'LINK'],
        'UNI'  => ['uniswap',          'UNI'],
        'ATOM' => ['cosmos',           'ATOM'],
        'NEAR' => ['near',             'NEAR'],
        'APT'  => ['aptos',            'APT'],
        'ARB'  => ['arbitrum',         'ARB'],
        'OP'   => ['optimism',         'OP'],
        'TRX'  => ['tron',             'TRX'],
        'XLM'  => ['stellar',          'XLM'],
        'VET'  => ['vechain',          'VET'],
        'FIL'  => ['filecoin',         'FIL'],
        'SAND' => ['the-sandbox',      'SAND'],
        'MANA' => ['decentraland',     'MANA'],
        'AXS'  => ['axie-infinity',    'AXS'],
        'AAVE' => ['aave',             'AAVE'],
        'MKR'  => ['maker',            'MKR'],
        'COMP' => ['compound-governance-token', 'COMP'],
        'CRV'  => ['curve-dao-token',  'CRV'],
        'SUSHI'=> ['sushi',            'SUSHI'],
        'INJ'  => ['injective-protocol','INJ'],
        'FTM'  => ['fantom',           'FTM'],
        'ALGO' => ['algorand',         'ALGO'],
        'HBAR' => ['hedera-hashgraph', 'HBAR'],
        'ICP'  => ['internet-computer','ICP'],
        'WRX'  => ['wazirx',           'WRX'],
        'USDT' => ['tether',           'USDT'],
        'USDC' => ['usd-coin',         'USDC'],
        'BUSD' => ['binance-usd',      'BUSD'],
    ];

    // strip common quote currencies to isolate base
    $sym = strtoupper(preg_replace('/\s+/', '', $raw));
    $sym = preg_replace('/(USDT|USDC|BUSD|BTC|ETH|BNB|INR|USD)$/', '', $sym);
    $sym = preg_replace('/[^A-Z0-9]/', '', $sym);

    if (isset($map[$sym])) {
        return ['coin_id' => $map[$sym][0], 'coin_symbol' => $map[$sym][1], 'coin_name' => $map[$sym][1]];
    }
    // fallback: use raw symbol lowercased as coin_id
    return ['coin_id' => strtolower($sym), 'coin_symbol' => $sym, 'coin_name' => $sym];
}

/**
 * Parse a Binance trade history CSV.
 *
 * Binance columns (UTC export):
 *   Date(UTC), Pair, Side, Price, Executed, Amount, Fee
 *
 * Returns array of normalised rows.
 */
function t317_parse_binance(array $rows): array {
    $out = [];
    foreach ($rows as $i => $r) {
        if ($i === 0) continue; // header
        $r = array_map('trim', $r);
        if (count($r) < 7) continue;

        [$dateUtc, $pair, $side, $price, $executed, $amount, $fee] = $r;

        $side = strtoupper($side);
        if (!in_array($side, ['BUY', 'SELL'], true)) continue;

        $coin     = t317_resolve_coin($pair);
        $qty      = (float)preg_replace('/[^0-9.]/', '', $executed);
        $priceVal = (float)preg_replace('/[^0-9.]/', '', $price);
        $amtUsd   = (float)preg_replace('/[^0-9.]/', '', $amount);
        $feeAmt   = (float)preg_replace('/[^0-9.]/', '', $fee);
        $feeCur   = preg_replace('/[^A-Z]/', '', $fee);

        // Binance prices are in USDT; convert to INR estimate (will use cached rate)
        // We store price_inr = 0 for preview; confirm step fetches live rate
        $txnDate = date('Y-m-d H:i:s', strtotime($dateUtc) ?: time());

        // External ID: date+pair+side+qty (no order ID in CSV)
        $extId = md5($dateUtc . $pair . $side . $executed);

        $out[] = [
            'exchange'      => 'BINANCE',
            'txn_type'      => $side,
            'coin_id'       => $coin['coin_id'],
            'coin_symbol'   => $coin['coin_symbol'],
            'coin_name'     => $coin['coin_name'],
            'quantity'      => $qty,
            'price_usd'     => $priceVal,
            'price_inr'     => 0,        // resolved at confirm time
            'amount_inr'    => 0,
            'fee_amount'    => $feeAmt,
            'fee_currency'  => $feeCur ?: null,
            'trade_pair'    => $pair,
            'tds_deducted'  => 0,
            'txn_date'      => $txnDate,
            'external_id'   => $extId,
            'notes'         => "Binance import: {$pair} {$side}",
        ];
    }
    return $out;
}

/**
 * Parse a WazirX transaction history CSV.
 *
 * WazirX columns:
 *   Date, Transaction Type, Currency, Volume, Price (INR), Amount (INR), Fee, TDS (INR)
 */
function t317_parse_wazirx(array $rows): array {
    $out = [];
    foreach ($rows as $i => $r) {
        if ($i === 0) continue;
        $r = array_map('trim', $r);
        if (count($r) < 6) continue;

        // Flexible column detection
        $date    = $r[0]  ?? '';
        $txnType = strtoupper($r[1] ?? '');
        $currency= strtoupper($r[2] ?? '');
        $volume  = (float)preg_replace('/[^0-9.]/', '', $r[3] ?? '0');
        $priceInr= (float)preg_replace('/[^0-9.]/', '', $r[4] ?? '0');
        $amtInr  = (float)preg_replace('/[^0-9.]/', '', $r[5] ?? '0');
        $feeRaw  = $r[6] ?? '0';
        $tds     = (float)preg_replace('/[^0-9.]/', '', $r[7] ?? '0');

        // Map WazirX type → WealthDash txn_type
        $typeMap = [
            'BUY'      => 'BUY',
            'SELL'     => 'SELL',
            'DEPOSIT'  => 'TRANSFER_IN',
            'WITHDRAW' => 'TRANSFER_OUT',
        ];
        $mappedType = $typeMap[$txnType] ?? null;
        if (!$mappedType) continue;

        $coin   = t317_resolve_coin($currency);
        $txnDate= date('Y-m-d H:i:s', strtotime($date) ?: time());
        $extId  = md5($date . $txnType . $currency . $volume);

        $out[] = [
            'exchange'      => 'WAZIRX',
            'txn_type'      => $mappedType,
            'coin_id'       => $coin['coin_id'],
            'coin_symbol'   => $coin['coin_symbol'],
            'coin_name'     => $coin['coin_name'],
            'quantity'      => $volume,
            'price_usd'     => 0,
            'price_inr'     => $priceInr,
            'amount_inr'    => $amtInr,
            'fee_amount'    => (float)preg_replace('/[^0-9.]/', '', $feeRaw),
            'fee_currency'  => 'INR',
            'trade_pair'    => $currency . '/INR',
            'tds_deducted'  => $tds,
            'txn_date'      => $txnDate,
            'external_id'   => $extId,
            'notes'         => "WazirX import: {$currency} {$txnType}",
        ];
    }
    return $out;
}

/**
 * Parse CoinDCX trade history CSV.
 *
 * CoinDCX columns:
 *   UTC Date, Pair, Trade Type, Buy/Sell Price, Quantity, Total (in INR), Fee Amount, Fee Currency
 */
function t317_parse_coindcx(array $rows): array {
    $out = [];
    foreach ($rows as $i => $r) {
        if ($i === 0) continue;
        $r = array_map('trim', $r);
        if (count($r) < 6) continue;

        $date     = $r[0] ?? '';
        $pair     = $r[1] ?? '';
        $tradeType= strtoupper($r[2] ?? '');
        $price    = (float)preg_replace('/[^0-9.]/', '', $r[3] ?? '0');
        $qty      = (float)preg_replace('/[^0-9.]/', '', $r[4] ?? '0');
        $totalInr = (float)preg_replace('/[^0-9.]/', '', $r[5] ?? '0');
        $feeAmt   = (float)preg_replace('/[^0-9.]/', '', $r[6] ?? '0');
        $feeCur   = strtoupper(trim($r[7] ?? 'INR'));

        $mappedType = in_array($tradeType, ['BUY','SELL'], true) ? $tradeType : null;
        if (!$mappedType) continue;

        $coin    = t317_resolve_coin($pair);
        $txnDate = date('Y-m-d H:i:s', strtotime($date) ?: time());
        $extId   = md5($date . $pair . $tradeType . $qty);

        $out[] = [
            'exchange'      => 'COINDCX',
            'txn_type'      => $mappedType,
            'coin_id'       => $coin['coin_id'],
            'coin_symbol'   => $coin['coin_symbol'],
            'coin_name'     => $coin['coin_name'],
            'quantity'      => $qty,
            'price_usd'     => 0,
            'price_inr'     => $price,
            'amount_inr'    => $totalInr,
            'fee_amount'    => $feeAmt,
            'fee_currency'  => $feeCur,
            'trade_pair'    => $pair,
            'tds_deducted'  => 0,
            'txn_date'      => $txnDate,
            'external_id'   => $extId,
            'notes'         => "CoinDCX import: {$pair} {$tradeType}",
        ];
    }
    return $out;
}

/**
 * Auto-detect exchange from CSV header row.
 */
function t317_detect_exchange(array $header): string {
    $h = implode(',', array_map('strtolower', $header));
    if (str_contains($h, 'date(utc)') || str_contains($h, 'executed'))  return 'BINANCE';
    if (str_contains($h, 'tds') || str_contains($h, 'wazirx'))          return 'WAZIRX';
    if (str_contains($h, 'coindcx') || str_contains($h, 'trade type'))  return 'COINDCX';
    return 'UNKNOWN';
}

/**
 * Fetch USD→INR rate (cached in DB or live from CoinGecko).
 */
function t317_usd_to_inr(mysqli $db): float {
    $row = $db->query(
        "SELECT price_inr FROM crypto_price_cache WHERE coin_id='tether' AND fetched_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
    );
    if ($row && $r = $row->fetch_assoc()) return (float)$r['price_inr'];

    // Fetch live
    $url = 'https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=inr';
    $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
    $data = $raw ? json_decode($raw, true) : null;
    return (float)($data['tether']['inr'] ?? 85.0);
}

// ── Persist confirmed rows ─────────────────────────────────────────────────────

/**
 * Write parsed rows into crypto_transactions + upsert crypto_holdings.
 * Returns ['inserted'=>N, 'skipped'=>N].
 */
function t317_persist(mysqli $db, array $parsedRows, int $portfolioId, int $userId, string $batchId, float $usdToInr): array {
    $inserted = 0;
    $skipped  = 0;

    foreach ($parsedRows as $r) {
        // Resolve INR price for Binance rows
        $priceInr = $r['price_inr'];
        $amtInr   = $r['amount_inr'];
        if ($priceInr == 0 && $r['price_usd'] > 0) {
            $priceInr = round($r['price_usd'] * $usdToInr, 4);
            $amtInr   = round($r['quantity'] * $priceInr, 2);
        }

        // Skip if already imported (unique key on import_source+external_id+portfolio_id)
        $chk = $db->prepare(
            "SELECT id FROM crypto_transactions WHERE import_source=? AND external_id=? LIMIT 1"
        );
        $chk->bind_param('ss', $r['exchange'], $r['external_id']);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) { $skipped++; continue; }
        $chk->close();

        // Insert transaction
        $stmt = $db->prepare(
            "INSERT INTO crypto_transactions
             (portfolio_id, coin_id, coin_symbol, txn_type, quantity, price_inr, amount_inr,
              tds_deducted, txn_date, exchange, notes, import_source, import_batch,
              trade_pair, fee_amount, fee_currency, external_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param(
            'isssddddssssssdss',
            $portfolioId,
            $r['coin_id'], $r['coin_symbol'], $r['txn_type'],
            $r['quantity'], $priceInr, $amtInr,
            $r['tds_deducted'], $r['txn_date'],
            $r['exchange'], $r['notes'],
            $r['exchange'], $batchId,
            $r['trade_pair'], $r['fee_amount'], $r['fee_currency'],
            $r['external_id']
        );
        if (!$stmt->execute()) { $skipped++; $stmt->close(); continue; }
        $stmt->close();
        $inserted++;

        // Upsert crypto_holdings (only for BUY/TRANSFER_IN)
        if (in_array($r['txn_type'], ['BUY', 'TRANSFER_IN'], true)) {
            $ex = $db->prepare(
                "SELECT id, quantity, avg_buy_price, total_invested FROM crypto_holdings
                 WHERE portfolio_id=? AND coin_id=? LIMIT 1"
            );
            $ex->bind_param('is', $portfolioId, $r['coin_id']);
            $ex->execute();
            $exists = $ex->get_result()->fetch_assoc();
            $ex->close();

            if ($exists) {
                $newQty  = (float)$exists['quantity'] + $r['quantity'];
                $newInvt = (float)$exists['total_invested'] + $amtInr;
                $newAvg  = $newQty > 0 ? round($newInvt / $newQty, 4) : 0;
                $upd = $db->prepare(
                    "UPDATE crypto_holdings SET quantity=?, avg_buy_price=?, total_invested=?, updated_at=NOW() WHERE id=?"
                );
                $upd->bind_param('dddi', $newQty, $newAvg, $newInvt, $exists['id']);
                $upd->execute(); $upd->close();
            } else {
                $ins = $db->prepare(
                    "INSERT INTO crypto_holdings
                     (portfolio_id, coin_id, coin_symbol, coin_name, quantity, avg_buy_price, total_invested, exchange)
                     VALUES (?,?,?,?,?,?,?,?)"
                );
                $ins->bind_param('isssddds',
                    $portfolioId, $r['coin_id'], $r['coin_symbol'], $r['coin_name'],
                    $r['quantity'], $priceInr, $amtInr, $r['exchange']
                );
                $ins->execute(); $ins->close();
            }
        } elseif ($r['txn_type'] === 'SELL') {
            // Reduce holding quantity
            $upd = $db->prepare(
                "UPDATE crypto_holdings SET quantity = GREATEST(0, quantity - ?), updated_at=NOW()
                 WHERE portfolio_id=? AND coin_id=?"
            );
            $upd->bind_param('dis', $r['quantity'], $portfolioId, $r['coin_id']);
            $upd->execute(); $upd->close();
        }
    }
    return ['inserted' => $inserted, 'skipped' => $skipped];
}

// ── Action routing ─────────────────────────────────────────────────────────────

switch ($action) {

    // ── PREVIEW: parse CSV, return rows without writing ────────────────────────
    case 'crypto_import_preview': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(false, 'POST required');

        $exchange = strtoupper(clean($_POST['exchange'] ?? 'AUTO'));
        $csvRaw   = $_POST['csv_data'] ?? '';
        if (empty($csvRaw)) json_response(false, 'No CSV data provided');

        // Parse CSV string into rows
        $lines = array_filter(array_map('trim', explode("\n", $csvRaw)));
        $rows  = [];
        foreach ($lines as $line) {
            // Use str_getcsv for proper quoted-field handling
            $rows[] = str_getcsv($line);
        }
        if (empty($rows)) json_response(false, 'Empty CSV');

        // Auto-detect exchange if not specified
        if ($exchange === 'AUTO') {
            $exchange = t317_detect_exchange($rows[0]);
        }

        // Parse per exchange
        switch ($exchange) {
            case 'BINANCE':  $parsed = t317_parse_binance($rows);  break;
            case 'WAZIRX':   $parsed = t317_parse_wazirx($rows);   break;
            case 'COINDCX':  $parsed = t317_parse_coindcx($rows);  break;
            default:
                // Try Binance, then WazirX
                $parsed = t317_parse_binance($rows);
                if (empty($parsed)) { $parsed = t317_parse_wazirx($rows); $exchange = 'WAZIRX'; }
        }

        if (empty($parsed)) json_response(false, 'No valid rows parsed. Check exchange format.');

        // Get USD→INR rate for Binance rows
        $usdToInr = t317_usd_to_inr($db);

        // Enrich preview: add estimated INR values
        foreach ($parsed as &$row) {
            if ($row['price_inr'] == 0 && $row['price_usd'] > 0) {
                $row['price_inr']  = round($row['price_usd'] * $usdToInr, 2);
                $row['amount_inr'] = round($row['quantity'] * $row['price_inr'], 2);
            }
        }
        unset($row);

        // Check for already-imported rows (mark as duplicate)
        $portfolio = (int)($_POST['portfolio_id'] ?? 0);
        foreach ($parsed as &$p) {
            $chk = $db->prepare(
                "SELECT id FROM crypto_transactions WHERE import_source=? AND external_id=? LIMIT 1"
            );
            $chk->bind_param('ss', $p['exchange'], $p['external_id']);
            $chk->execute();
            $p['is_duplicate'] = ($chk->get_result()->num_rows > 0);
            $chk->close();
        }
        unset($p);

        json_response(true, 'Preview ready', [
            'exchange'    => $exchange,
            'rows'        => $parsed,
            'total'       => count($parsed),
            'duplicates'  => count(array_filter($parsed, fn($p) => $p['is_duplicate'])),
            'usd_to_inr'  => $usdToInr,
        ]);
    }

    // ── CONFIRM: write rows to DB ──────────────────────────────────────────────
    case 'crypto_import_confirm': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(false, 'POST required');

        $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
        $exchange    = strtoupper(clean($_POST['exchange'] ?? ''));
        $csvRaw      = $_POST['csv_data'] ?? '';
        $filename    = clean($_POST['filename'] ?? 'import.csv');

        if (!$portfolioId) json_response(false, 'portfolio_id required');
        if (empty($csvRaw))json_response(false, 'csv_data required');

        // Verify portfolio belongs to user
        $pChk = $db->prepare("SELECT id FROM portfolios WHERE id=? AND user_id=? LIMIT 1");
        $pChk->bind_param('ii', $portfolioId, $userId);
        $pChk->execute();
        if (!$pChk->get_result()->fetch_assoc()) json_response(false, 'Portfolio not found');
        $pChk->close();

        // Parse
        $lines = array_filter(array_map('trim', explode("\n", $csvRaw)));
        $rows  = [];
        foreach ($lines as $line) { $rows[] = str_getcsv($line); }

        switch ($exchange) {
            case 'BINANCE':  $parsed = t317_parse_binance($rows);  break;
            case 'WAZIRX':   $parsed = t317_parse_wazirx($rows);   break;
            case 'COINDCX':  $parsed = t317_parse_coindcx($rows);  break;
            default:         json_response(false, 'Unknown exchange: ' . $exchange);
        }

        if (empty($parsed)) json_response(false, 'No valid rows found');

        $batchId   = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        $usdToInr  = t317_usd_to_inr($db);

        $db->begin_transaction();
        try {
            $result = t317_persist($db, $parsed, $portfolioId, $userId, $batchId, $usdToInr);

            // Log the batch
            $log = $db->prepare(
                "INSERT INTO crypto_import_log (batch_id, portfolio_id, user_id, exchange, filename, rows_parsed, rows_inserted, rows_skipped)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $log->bind_param('siissiiii',  // corrected types
                $batchId, $portfolioId, $userId, $exchange, $filename,
                count($parsed), $result['inserted'], $result['skipped']
            );
            $log->execute();
            $log->close();

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            json_response(false, 'Import failed: ' . $e->getMessage());
        }

        json_response(true, "Import complete: {$result['inserted']} rows added, {$result['skipped']} skipped.", [
            'batch_id'  => $batchId,
            'inserted'  => $result['inserted'],
            'skipped'   => $result['skipped'],
            'exchange'  => $exchange,
        ]);
    }

    // ── IMPORT LOG: list past imports ─────────────────────────────────────────
    case 'crypto_import_log': {
        $logs = $db->query(
            "SELECT batch_id, exchange, filename, rows_parsed, rows_inserted, rows_skipped, imported_at
             FROM crypto_import_log
             WHERE user_id = {$userId}
             ORDER BY imported_at DESC
             LIMIT 50"
        );
        $rows = [];
        while ($r = $logs->fetch_assoc()) $rows[] = $r;
        json_response(true, '', ['logs' => $rows]);
    }

    default:
        json_response(false, 'Unknown action: ' . $action);
}
