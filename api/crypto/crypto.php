<?php
/**
 * WealthDash — t24/t40/t41/t315: Crypto Holdings + Live Prices + P&L
 * t42/t316: VDA Tax Calculator — 30% flat + 1% TDS
 * t317: Crypto Exchange P&L Import (Binance/WazirX CSV)
 * Actions: crypto_list | crypto_add | crypto_update_prices | crypto_pl | crypto_vda_tax | crypto_import_csv
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'crypto_list';

// Ensure tables
try {
    DB::conn()->exec("
        CREATE TABLE IF NOT EXISTS crypto_holdings (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id         INT UNSIGNED NOT NULL,
            coin_id         VARCHAR(60)  NOT NULL,
            coin_symbol     VARCHAR(20)  NOT NULL,
            coin_name       VARCHAR(80)  NOT NULL,
            quantity        DECIMAL(24,8) NOT NULL,
            avg_buy_price   DECIMAL(20,2) NOT NULL,
            current_price   DECIMAL(20,2) DEFAULT 0,
            current_value_inr DECIMAL(15,2) DEFAULT 0,
            invested_inr    DECIMAL(15,2) DEFAULT 0,
            exchange        VARCHAR(60)  DEFAULT NULL,
            is_active       TINYINT(1)   NOT NULL DEFAULT 1,
            price_updated_at DATETIME DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_coin (user_id, coin_id, exchange),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS crypto_transactions (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id         INT UNSIGNED NOT NULL,
            holding_id      INT UNSIGNED NOT NULL,
            tx_type         ENUM('buy','sell','transfer_in','transfer_out','reward') NOT NULL,
            quantity        DECIMAL(24,8) NOT NULL,
            price_inr       DECIMAL(20,2) NOT NULL,
            fee_inr         DECIMAL(10,2) DEFAULT 0,
            total_inr       DECIMAL(15,2) NOT NULL,
            tds_deducted    DECIMAL(10,2) DEFAULT 0,
            exchange        VARCHAR(60)  DEFAULT NULL,
            tx_date         DATE NOT NULL,
            notes           VARCHAR(200) DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_holding (holding_id),
            INDEX idx_date (tx_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {}

// ── CoinGecko price fetch ──────────────────────────────────────
function fetchCoinGeckoPrices(array $coinIds): array {
    if (empty($coinIds)) return [];
    $ids = implode(',', $coinIds);
    $url = "https://api.coingecko.com/api/v3/simple/price?ids={$ids}&vs_currencies=inr&include_24hr_change=true";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) return [];
    return json_decode($resp, true) ?? [];
}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── LIST HOLDINGS ──────────────────────────────────────────
    case 'crypto_list':
        $holdings = DB::fetchAll(
            "SELECT * FROM crypto_holdings WHERE user_id = ? AND is_active = 1 ORDER BY current_value_inr DESC",
            [$userId]
        );

        $totalInvested = array_sum(array_column($holdings, 'invested_inr'));
        $totalCurrent  = array_sum(array_column($holdings, 'current_value_inr'));
        $totalPL       = $totalCurrent - $totalInvested;

        foreach ($holdings as &$h) {
            $h['pl_inr']   = round((float)$h['current_value_inr'] - (float)$h['invested_inr'], 2);
            $h['pl_pct']   = $h['invested_inr'] > 0
                ? round($h['pl_inr'] / $h['invested_inr'] * 100, 2) : 0;
        }
        unset($h);

        json_response(true, '', [
            'holdings'        => $holdings,
            'total_invested'  => round($totalInvested, 2),
            'total_current'   => round($totalCurrent, 2),
            'total_pl'        => round($totalPL, 2),
            'total_pl_pct'    => $totalInvested > 0 ? round($totalPL / $totalInvested * 100, 2) : 0,
        ]);

    // ── ADD HOLDING ────────────────────────────────────────────
    case 'crypto_add':
        csrf_verify();
        $coinId    = clean(strtolower($_POST['coin_id']    ?? ''));
        $symbol    = clean(strtoupper($_POST['coin_symbol']?? ''));
        $name      = clean($_POST['coin_name']             ?? $symbol);
        $qty       = (float)($_POST['quantity']            ?? 0);
        $avgPrice  = (float)($_POST['avg_buy_price']       ?? 0);
        $exchange  = clean($_POST['exchange']              ?? '');

        if (!$coinId || !$symbol || $qty <= 0 || $avgPrice <= 0) {
            json_response(false, 'Coin ID, symbol, quantity aur avg price required hai.');
        }

        $invested = round($qty * $avgPrice, 2);

        // Fetch current price from CoinGecko
        $prices = fetchCoinGeckoPrices([$coinId]);
        $currentPrice = (float)($prices[$coinId]['inr'] ?? $avgPrice);
        $currentValue = round($qty * $currentPrice, 2);

        // Upsert (add to existing if same coin+exchange)
        $existing = DB::fetchRow(
            "SELECT id, quantity, invested_inr FROM crypto_holdings WHERE user_id=? AND coin_id=? AND exchange=? AND is_active=1",
            [$userId, $coinId, $exchange]
        );

        if ($existing) {
            $newQty      = (float)$existing['quantity'] + $qty;
            $newInvested = (float)$existing['invested_inr'] + $invested;
            $newAvg      = $newInvested / $newQty;
            DB::run(
                "UPDATE crypto_holdings SET quantity=?, avg_buy_price=?, invested_inr=?, current_price=?, current_value_inr=?, price_updated_at=NOW()
                 WHERE id=?",
                [$newQty, round($newAvg,2), $newInvested, $currentPrice, round($newQty*$currentPrice,2), $existing['id']]
            );
            $holdingId = $existing['id'];
        } else {
            DB::run(
                "INSERT INTO crypto_holdings (user_id, coin_id, coin_symbol, coin_name, quantity, avg_buy_price, current_price, current_value_inr, invested_inr, exchange, price_updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
                [$userId, $coinId, $symbol, $name, $qty, $avgPrice, $currentPrice, $currentValue, $invested, $exchange]
            );
            $holdingId = DB::lastInsertId();
        }

        // Log transaction
        DB::run(
            "INSERT INTO crypto_transactions (user_id, holding_id, tx_type, quantity, price_inr, total_inr, exchange, tx_date)
             VALUES (?, ?, 'buy', ?, ?, ?, ?, CURDATE())",
            [$userId, $holdingId, $qty, $avgPrice, $invested, $exchange]
        );

        json_response(true, "{$name} holding add ho gayi!", ['holding_id' => $holdingId]);

    // ── UPDATE PRICES ──────────────────────────────────────────
    case 'crypto_update_prices':
        $holdings = DB::fetchAll(
            "SELECT id, coin_id, quantity FROM crypto_holdings WHERE user_id=? AND is_active=1",
            [$userId]
        );
        if (empty($holdings)) json_response(true, 'No holdings.', ['updated' => 0]);

        $coinIds = array_unique(array_column($holdings, 'coin_id'));
        $prices  = fetchCoinGeckoPrices($coinIds);
        $updated = 0;

        foreach ($holdings as $h) {
            $coinId = $h['coin_id'];
            if (!isset($prices[$coinId]['inr'])) continue;
            $price = (float)$prices[$coinId]['inr'];
            $value = round((float)$h['quantity'] * $price, 2);
            DB::run(
                "UPDATE crypto_holdings SET current_price=?, current_value_inr=?, price_updated_at=NOW() WHERE id=?",
                [$price, $value, $h['id']]
            );
            $updated++;
        }

        json_response(true, "{$updated} coins ki prices update ho gayi.", [
            'updated' => $updated,
            'prices'  => $prices,
        ]);

    // ── P&L REPORT ─────────────────────────────────────────────
    case 'crypto_pl':
        $fy = $_GET['fy'] ?? null;
        $yr = $fy ? (int)substr($fy,0,4) : ((int)date('n')>=4 ? (int)date('Y') : (int)date('Y')-1);
        $fyStart = $yr . '-04-01';
        $fyEnd   = ($yr+1) . '-03-31';

        // Realised gains this FY (sell transactions)
        $sells = DB::fetchAll(
            "SELECT ct.tx_date, ch.coin_name, ch.coin_symbol,
                    ct.quantity, ct.price_inr AS sell_price,
                    ch.avg_buy_price AS buy_price,
                    ct.fee_inr, ct.tds_deducted,
                    ROUND((ct.price_inr - ch.avg_buy_price) * ct.quantity, 2) AS gain_loss,
                    ct.total_inr AS sale_value
             FROM crypto_transactions ct
             JOIN crypto_holdings ch ON ch.id = ct.holding_id
             WHERE ct.user_id=? AND ct.tx_type='sell'
               AND ct.tx_date BETWEEN ? AND ?
             ORDER BY ct.tx_date",
            [$userId, $fyStart, $fyEnd]
        );

        $totalGain   = array_sum(array_column($sells, 'gain_loss'));
        $totalTds    = array_sum(array_column($sells, 'tds_deducted'));
        $totalFees   = array_sum(array_column($sells, 'fee_inr'));
        // VDA tax: 30% flat, no deduction except cost
        $taxPayable  = max(0, $totalGain * 0.30);
        $tdsCredit   = $totalTds;
        $netTax      = max(0, $taxPayable - $tdsCredit);

        json_response(true, '', [
            'fy'              => $yr . '-' . ($yr+1),
            'transactions'    => $sells,
            'total_gain_loss' => round($totalGain, 2),
            'total_fees'      => round($totalFees, 2),
            'tax_calculation' => [
                'gross_gain'     => round($totalGain, 2),
                'tax_rate'       => '30% flat (Section 115BBH)',
                'tax_payable'    => round($taxPayable, 2),
                'tds_credit'     => round($tdsCredit, 2),
                'net_tax'        => round($netTax, 2),
                'note'           => 'No deduction allowed except cost of acquisition. Loss cannot be set off against other income.',
            ],
        ]);

    // ── VDA TAX CALCULATOR ─────────────────────────────────────
    case 'crypto_vda_tax':
        $buyPrice  = (float)($_POST['buy_price']  ?? 0);
        $sellPrice = (float)($_POST['sell_price'] ?? 0);
        $quantity  = (float)($_POST['quantity']   ?? 0);
        $tdsAlready = (float)($_POST['tds_paid']  ?? 0);

        if (!$buyPrice || !$sellPrice || !$quantity) {
            json_response(false, 'Buy price, sell price aur quantity required hai.');
        }

        $gain       = ($sellPrice - $buyPrice) * $quantity;
        $saleValue  = $sellPrice * $quantity;
        $tds        = $saleValue * 0.01; // 1% TDS on sale value
        $taxable    = max(0, $gain);     // Loss not allowed for set-off
        $tax30      = $taxable * 0.30;
        $cess4      = $tax30 * 0.04;
        $totalTax   = $tax30 + $cess4;
        $netTax     = max(0, $totalTax - $tdsAlready);

        json_response(true, '', [
            'sale_value'     => round($saleValue, 2),
            'cost'           => round($buyPrice * $quantity, 2),
            'gain_loss'      => round($gain, 2),
            'is_profit'      => $gain > 0,
            'tax_breakdown' => [
                'vda_tax_30pct' => round($tax30, 2),
                'cess_4pct'     => round($cess4, 2),
                'total_tax'     => round($totalTax, 2),
                'tds_1pct'      => round($tds, 2),
                'tds_already_paid' => round($tdsAlready, 2),
                'net_tax_payable'  => round($netTax, 2),
            ],
            'rules' => [
                'Tax rate'    => '30% flat (Section 115BBH, from FY 2022-23)',
                'TDS'         => '1% on sale value (Section 194S)',
                'Set-off'     => 'Loss from VDA cannot be set off against any other income',
                'Deduction'   => 'Only cost of acquisition allowed, no other expense',
                'ITR form'    => 'Report in Schedule VDA of ITR-2/ITR-3',
            ],
            'disclaimer' => 'Ye calculator indicative hai. CA se confirm karo before filing.',
        ]);

    // ── IMPORT CSV ─────────────────────────────────────────────
    case 'crypto_import_csv':
        csrf_verify();
        if (empty($_FILES['csv_file'])) json_response(false, 'CSV file required hai.');

        $file     = $_FILES['csv_file']['tmp_name'];
        $platform = clean($_POST['platform'] ?? 'generic');
        $rows     = array_map('str_getcsv', file($file));
        $header   = array_shift($rows);
        $imported = 0; $errors = [];

        // Platform-specific column mapping
        $colMap = match($platform) {
            'wazirx'  => ['date'=>0,'coin'=>1,'type'=>2,'qty'=>3,'price'=>4,'fee'=>5,'total'=>6],
            'binance' => ['date'=>0,'pair'=>2,'type'=>2,'qty'=>3,'price'=>5,'fee'=>7,'total'=>6],
            default   => ['date'=>0,'coin'=>1,'type'=>2,'qty'=>3,'price'=>4,'fee'=>5,'total'=>6],
        };

        foreach ($rows as $i => $row) {
            if (count($row) < 4 || empty($row[0])) continue;
            try {
                $txDate  = date('Y-m-d', strtotime($row[$colMap['date']]));
                $coinRaw = strtoupper(trim($row[$colMap['coin']]));
                $coinSym = preg_replace('/[^A-Z0-9].*$/', '', $coinRaw);
                $txType  = strtolower(trim($row[$colMap['type']] ?? ''));
                $qty     = (float)($row[$colMap['qty']] ?? 0);
                $price   = (float)($row[$colMap['price']] ?? 0);
                $fee     = (float)($row[$colMap['fee']] ?? 0);
                $total   = (float)($row[$colMap['total']] ?? $qty * $price);

                if ($qty <= 0 || $price <= 0) continue;

                $txTypeMapped = str_contains($txType,'buy') ? 'buy' : (str_contains($txType,'sell') ? 'sell' : 'buy');

                // Get or create holding
                $coinId = strtolower($coinSym);
                $h = DB::fetchRow(
                    "SELECT id FROM crypto_holdings WHERE user_id=? AND coin_id=? AND exchange=?",
                    [$userId, $coinId, $platform]
                );
                if (!$h) {
                    DB::run(
                        "INSERT INTO crypto_holdings (user_id, coin_id, coin_symbol, coin_name, quantity, avg_buy_price, invested_inr, exchange)
                         VALUES (?,?,?,?,0,0,0,?)",
                        [$userId, $coinId, $coinSym, $coinSym, $platform]
                    );
                    $holdingId = DB::lastInsertId();
                } else {
                    $holdingId = $h['id'];
                }

                DB::run(
                    "INSERT IGNORE INTO crypto_transactions (user_id, holding_id, tx_type, quantity, price_inr, fee_inr, total_inr, exchange, tx_date)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$userId, $holdingId, $txTypeMapped, $qty, $price, $fee, $total, $platform, $txDate]
                );
                $imported++;
            } catch (Exception $e) {
                $errors[] = "Row " . ($i+2) . ": " . $e->getMessage();
            }
        }

        // Recalculate avg cost for all holdings
        $hIds = DB::fetchAll("SELECT DISTINCT holding_id FROM crypto_transactions WHERE user_id=?", [$userId]);
        foreach ($hIds as $hRow) {
            $buys = DB::fetchRow(
                "SELECT SUM(quantity) AS tot_qty, SUM(total_inr) AS tot_invested
                 FROM crypto_transactions WHERE holding_id=? AND tx_type='buy'",
                [$hRow['holding_id']]
            );
            if ($buys && $buys['tot_qty'] > 0) {
                DB::run(
                    "UPDATE crypto_holdings SET quantity=?, avg_buy_price=?, invested_inr=? WHERE id=?",
                    [$buys['tot_qty'], round($buys['tot_invested']/$buys['tot_qty'],2), $buys['tot_invested'], $hRow['holding_id']]
                );
            }
        }

        json_response(true, "{$imported} transactions import ho gayi.", [
            'imported' => $imported,
            'errors'   => array_slice($errors, 0, 10),
        ]);

    default:
        json_response(false, 'Unknown crypto action.', [], 400);
}
