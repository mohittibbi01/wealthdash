<?php
/**
 * WealthDash — Crypto VDA Tax Tracker
 * Task    : tc002 — Section 115BBH flat 30% + 1% TDS (Section 194S)
 * Actions : vda_tax_list | vda_tax_add | vda_tax_edit | vda_tax_delete
 *           vda_tax_summary | vda_tds_summary | vda_tds_save
 *           vda_tax_itr_export | vda_gain_loss_calc
 *
 * Law:
 *   Sec 115BBH: Transfer of VDA taxed @ flat 30% (+ 4% cess = 31.2%)
 *   Only allowable deduction: Cost of Acquisition
 *   Losses: Cannot set off / carry forward
 *   Sec 194S: 1% TDS by buyer/exchange on sale > ₹10,000/year (per person)
 *   Gifting VDA: Taxable in hands of recipient (if > ₹50,000)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$portfolioId = get_user_portfolio_id($userId);
$action      = $_POST['action'] ?? $_GET['action'] ?? '';

// Tax constants (115BBH)
const VDA_TAX_RATE  = 0.30;    // 30%
const VDA_CESS_RATE = 0.04;    // 4% cess on tax
const VDA_TDS_RATE  = 0.01;    // 1% TDS Section 194S
const VDA_TDS_THRESHOLD = 10000; // ₹10,000/year threshold for TDS (194S)

// ────────────────────────────────────────────────────────────────────────────
switch ($action) {

    // ═══════════════════════════════════════════════════════════════════════
    // LIST — all VDA transactions with optional FY filter
    // ═══════════════════════════════════════════════════════════════════════
    case 'vda_tax_list':
        $fy     = sanitize($_GET['fy'] ?? '');
        $type   = sanitize($_GET['txn_type'] ?? '');
        $coin   = sanitize($_GET['coin'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(100, max(10, (int)($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $where  = ['vda.portfolio_id = ?'];
        $params = [$portfolioId];

        if ($fy) {
            $where[]  = 'vda.fy = ?';
            $params[] = $fy;
        }
        if ($type) {
            $where[]  = 'vda.txn_type = ?';
            $params[] = strtoupper($type);
        }
        if ($coin) {
            $where[]  = 'vda.coin_symbol LIKE ?';
            $params[] = '%' . $coin . '%';
        }

        $whereClause = implode(' AND ', $where);
        $total = (int) DB::fetchVal("SELECT COUNT(*) FROM vda_transactions vda WHERE {$whereClause}", $params);

        $pParams   = $params;
        $pParams[] = $limit;
        $pParams[] = $offset;

        $rows = DB::fetchAll(
            "SELECT * FROM vda_transactions vda
             WHERE {$whereClause}
             ORDER BY vda.sell_date DESC, vda.buy_date DESC
             LIMIT ? OFFSET ?",
            $pParams
        );

        // Enrich each row with effective tax
        foreach ($rows as &$r) {
            $r = _enrich_vda_row($r);
        }
        unset($r);

        json_response(true, '', [
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'pages'      => (int)ceil($total / $limit),
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // ADD — new VDA transaction
    // ═══════════════════════════════════════════════════════════════════════
    case 'vda_tax_add':
        $data = _parse_vda_input();
        _validate_vda_input($data);
        _compute_vda_tax($data);

        DB::run(
            "INSERT INTO vda_transactions
             (portfolio_id, coin_symbol, coin_name, coingecko_id, txn_type,
              buy_date, buy_quantity, buy_price_inr, buy_cost_inr, buy_fees_inr, buy_exchange,
              sell_date, sell_quantity, sell_price_inr, sell_proceeds_inr, sell_fees_inr, sell_exchange,
              cost_of_acquisition, gross_proceeds, gain_loss_inr, is_gain,
              tax_30pct, cess_4pct, total_tax_payable,
              tds_deducted, tds_date, tds_certificate_no,
              fy, fy_quarter, txn_hash, notes)
             VALUES (?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?, ?,?,?,?)",
            [
                $portfolioId,
                $data['coin_symbol'],    $data['coin_name'],   $data['coingecko_id'], $data['txn_type'],
                $data['buy_date'],       $data['buy_quantity'],  $data['buy_price_inr'],
                $data['buy_cost_inr'],   $data['buy_fees_inr'],  $data['buy_exchange'],
                $data['sell_date'],      $data['sell_quantity'], $data['sell_price_inr'],
                $data['sell_proceeds'],  $data['sell_fees_inr'], $data['sell_exchange'],
                $data['cost_of_acquisition'], $data['gross_proceeds'],
                $data['gain_loss'],      $data['is_gain'],
                $data['tax_30pct'],      $data['cess_4pct'],    $data['total_tax'],
                $data['tds_deducted'],   $data['tds_date'],     $data['tds_cert'],
                $data['fy'],             $data['fy_quarter'],   $data['txn_hash'], $data['notes'],
            ]
        );

        $newId = DB::lastInsertId();
        json_response(true, 'VDA transaction added ✅', ['id' => $newId, 'tax' => $data['total_tax']]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // EDIT — update VDA transaction
    // ═══════════════════════════════════════════════════════════════════════
    case 'vda_tax_edit':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'id required');

        _verify_vda_ownership($id, $portfolioId);

        $data = _parse_vda_input();
        _validate_vda_input($data);
        _compute_vda_tax($data);

        DB::run(
            "UPDATE vda_transactions SET
             coin_symbol=?, coin_name=?, coingecko_id=?, txn_type=?,
             buy_date=?, buy_quantity=?, buy_price_inr=?, buy_cost_inr=?, buy_fees_inr=?, buy_exchange=?,
             sell_date=?, sell_quantity=?, sell_price_inr=?, sell_proceeds_inr=?, sell_fees_inr=?, sell_exchange=?,
             cost_of_acquisition=?, gross_proceeds=?, gain_loss_inr=?, is_gain=?,
             tax_30pct=?, cess_4pct=?, total_tax_payable=?,
             tds_deducted=?, tds_date=?, tds_certificate_no=?,
             fy=?, fy_quarter=?, txn_hash=?, notes=?, updated_at=NOW()
             WHERE id=?",
            [
                $data['coin_symbol'],    $data['coin_name'],   $data['coingecko_id'], $data['txn_type'],
                $data['buy_date'],       $data['buy_quantity'], $data['buy_price_inr'],
                $data['buy_cost_inr'],   $data['buy_fees_inr'], $data['buy_exchange'],
                $data['sell_date'],      $data['sell_quantity'],$data['sell_price_inr'],
                $data['sell_proceeds'],  $data['sell_fees_inr'],$data['sell_exchange'],
                $data['cost_of_acquisition'], $data['gross_proceeds'],
                $data['gain_loss'],      $data['is_gain'],
                $data['tax_30pct'],      $data['cess_4pct'],   $data['total_tax'],
                $data['tds_deducted'],   $data['tds_date'],    $data['tds_cert'],
                $data['fy'],             $data['fy_quarter'],  $data['txn_hash'], $data['notes'],
                $id,
            ]
        );

        json_response(true, 'VDA transaction updated ✅', ['id' => $id]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // DELETE
    // ═══════════════════════════════════════════════════════════════════════
    case 'vda_tax_delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'id required');
        _verify_vda_ownership($id, $portfolioId);
        DB::run('DELETE FROM vda_transactions WHERE id = ?', [$id]);
        json_response(true, 'VDA transaction deleted');
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // FY SUMMARY — total gain, tax, TDS credit, net payable
    // ═══════════════════════════════════════════════════════════════════════
    case 'vda_tax_summary':
        $fy = sanitize($_GET['fy'] ?? '');
        [$fyStart, $fyEnd] = _vda_fy_dates($fy);
        $fyLabel = $fy ?: _vda_current_fy();

        $agg = DB::fetchOne(
            "SELECT
                COUNT(*)                                      AS total_txns,
                SUM(CASE WHEN txn_type='SELL' THEN 1 ELSE 0 END) AS sell_txns,
                SUM(CASE WHEN txn_type='BUY'  THEN 1 ELSE 0 END) AS buy_txns,
                SUM(gross_proceeds)                           AS total_sale_value,
                SUM(cost_of_acquisition)                      AS total_cost,
                SUM(CASE WHEN is_gain=1 THEN gain_loss_inr ELSE 0 END) AS total_gains,
                SUM(CASE WHEN is_gain=0 THEN ABS(gain_loss_inr) ELSE 0 END) AS total_losses,
                SUM(tax_30pct)                                AS total_tax_30pct,
                SUM(cess_4pct)                                AS total_cess,
                SUM(total_tax_payable)                        AS total_tax_payable,
                SUM(tds_deducted)                             AS total_tds_deducted
             FROM vda_transactions
             WHERE portfolio_id = ? AND fy = ?",
            [$portfolioId, $fyLabel]
        );

        $totalGains      = (float)($agg['total_gains']       ?? 0);
        $totalLosses     = (float)($agg['total_losses']      ?? 0);
        $totalTax        = (float)($agg['total_tax_payable'] ?? 0);
        $totalTds        = (float)($agg['total_tds_deducted']?? 0);
        $netTaxPayable   = max(0, $totalTax - $totalTds);  // TDS is advance tax credit

        // Coin-wise breakdown
        $coinBreakdown = DB::fetchAll(
            "SELECT
                coin_symbol, coin_name,
                COUNT(*)                AS txn_count,
                SUM(CASE WHEN is_gain=1 THEN gain_loss_inr ELSE 0 END) AS gains,
                SUM(CASE WHEN is_gain=0 THEN ABS(gain_loss_inr) ELSE 0 END) AS losses,
                SUM(total_tax_payable)  AS tax,
                SUM(tds_deducted)       AS tds
             FROM vda_transactions
             WHERE portfolio_id = ? AND fy = ?
               AND txn_type = 'SELL'
             GROUP BY coin_symbol, coin_name
             ORDER BY gains DESC",
            [$portfolioId, $fyLabel]
        );

        // Gifting summary (taxable in recipient's hands)
        $giftSummary = DB::fetchAll(
            "SELECT coin_symbol, SUM(gross_proceeds) AS gift_value,
                    COUNT(*) AS gift_count
             FROM vda_transactions
             WHERE portfolio_id = ? AND fy = ? AND txn_type IN ('GIFT_GIVEN','GIFT_RECEIVED')
             GROUP BY coin_symbol",
            [$portfolioId, $fyLabel]
        );

        json_response(true, '', [
            'fy'              => $fyLabel,
            'aggregates' => [
                'total_txns'        => (int)($agg['total_txns']   ?? 0),
                'sell_txns'         => (int)($agg['sell_txns']    ?? 0),
                'total_sale_value'  => round((float)($agg['total_sale_value'] ?? 0), 2),
                'total_cost'        => round((float)($agg['total_cost'] ?? 0), 2),
                'total_gains'       => round($totalGains, 2),
                'total_losses'      => round($totalLosses, 2),
                'net_taxable_gains' => round($totalGains, 2), // No loss set-off allowed
                'tax_30pct'         => round((float)($agg['total_tax_30pct'] ?? 0), 2),
                'cess_4pct'         => round((float)($agg['total_cess'] ?? 0), 2),
                'total_tax_payable' => round($totalTax, 2),
                'tds_deducted'      => round($totalTds, 2),
                'net_tax_after_tds' => round($netTaxPayable, 2),
            ],
            'coin_breakdown'   => $coinBreakdown,
            'gift_summary'     => $giftSummary,
            'law_notes' => [
                'rate'         => 'Flat 30% + 4% cess = effective 31.2% (Sec 115BBH)',
                'deductions'   => 'Sirf cost of acquisition — koi aur deduction nahi (brokerage/fees bhi nahi)',
                'loss_rule'    => 'VDA loss kisi bhi income se set-off nahi ho sakta. Carry forward bhi nahi.',
                'tds'          => '1% TDS exchange deduct karta hai (Sec 194S). Ye advance tax credit mil jaata hai.',
                'gift'         => 'VDA gift karna = transfer. Recipient mein ₹50,000+ gift taxable hoga.',
                'itr_schedule' => 'ITR-2 / ITR-3 mein Schedule VDA fill karo.',
            ],
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // TDS SUMMARY — per-exchange TDS tracking (194S)
    // ═══════════════════════════════════════════════════════════════════════
    case 'vda_tds_summary':
        $fy = sanitize($_GET['fy'] ?? _vda_current_fy());

        // Auto-compute TDS from transaction data
        $exchangeTds = DB::fetchAll(
            "SELECT
                COALESCE(sell_exchange,'Unknown') AS exchange,
                SUM(gross_proceeds)   AS total_sale_value,
                SUM(tds_deducted)     AS tds_deducted_txns,
                COUNT(*)              AS sell_count
             FROM vda_transactions
             WHERE portfolio_id = ? AND fy = ? AND txn_type = 'SELL'
             GROUP BY sell_exchange
             ORDER BY total_sale_value DESC",
            [$portfolioId, $fy]
        );

        // Merge with manually saved TDS log
        $savedTds = DB::fetchAll(
            "SELECT * FROM vda_tds_log WHERE portfolio_id = ? AND fy = ?",
            [$portfolioId, $fy]
        );
        $savedMap = array_column($savedTds, null, 'exchange');

        foreach ($exchangeTds as &$e) {
            $saved = $savedMap[$e['exchange']] ?? null;
            $e['tds_1pct_computed']  = round((float)$e['total_sale_value'] * VDA_TDS_RATE, 2);
            $e['tds_paid']           = $saved ? (float)$saved['tds_paid'] : (float)$e['tds_deducted_txns'];
            $e['tds_balance']        = round($e['tds_1pct_computed'] - $e['tds_paid'], 2);
            $e['form_26as_verified'] = $saved ? (bool)$saved['form_26as_verified'] : false;
            $e['threshold_met']      = (float)$e['total_sale_value'] >= VDA_TDS_THRESHOLD;
        }
        unset($e);

        json_response(true, '', [
            'fy'           => $fy,
            'tds_rate'     => '1% (Section 194S)',
            'threshold'    => VDA_TDS_THRESHOLD,
            'breakdown'    => $exchangeTds,
            'total_tds'    => round(array_sum(array_column($exchangeTds, 'tds_1pct_computed')), 2),
            'note'         => 'TDS exchange deduct karta hai. Form 26AS mein verify karo. Net TDS income tax mein credit milta hai.',
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // SAVE TDS LOG — manual TDS entry/verification
    // ═══════════════════════════════════════════════════════════════════════
    case 'vda_tds_save':
        $exchange  = sanitize($_POST['exchange']            ?? '');
        $fy        = sanitize($_POST['fy']                  ?? _vda_current_fy());
        $saleValue = (float)($_POST['total_sale_value']     ?? 0);
        $tdsPaid   = (float)($_POST['tds_paid']             ?? 0);
        $verified  = (int)  ($_POST['form_26as_verified']   ?? 0);
        $notes     = sanitize($_POST['notes']               ?? '');

        if (!$exchange) json_response(false, 'exchange required');

        $tds1pct = round($saleValue * VDA_TDS_RATE, 2);
        $balance = round($tds1pct - $tdsPaid, 2);

        DB::run(
            "INSERT INTO vda_tds_log
             (portfolio_id, exchange, fy, total_sale_value, tds_1pct, tds_paid, tds_balance, form_26as_verified, notes)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               total_sale_value=VALUES(total_sale_value),
               tds_1pct=VALUES(tds_1pct),
               tds_paid=VALUES(tds_paid),
               tds_balance=VALUES(tds_balance),
               form_26as_verified=VALUES(form_26as_verified),
               notes=VALUES(notes)",
            [$portfolioId, $exchange, $fy, $saleValue, $tds1pct, $tdsPaid, $balance, $verified, $notes]
        );

        json_response(true, 'TDS log saved ✅', [
            'exchange'    => $exchange,
            'fy'          => $fy,
            'tds_1pct'    => $tds1pct,
            'tds_paid'    => $tdsPaid,
            'tds_balance' => $balance,
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // GAIN/LOSS CALC — quick calculator (no DB save)
    // ═══════════════════════════════════════════════════════════════════════
    case 'vda_gain_loss_calc':
        $buyPrice  = (float)($_GET['buy_price']  ?? 0);
        $sellPrice = (float)($_GET['sell_price'] ?? 0);
        $qty       = (float)($_GET['qty']        ?? 1);
        $buyFees   = (float)($_GET['buy_fees']   ?? 0);
        // Note: sell fees NOT deductible under 115BBH

        if ($buyPrice <= 0 || $sellPrice <= 0 || $qty <= 0) {
            json_response(false, 'buy_price, sell_price, qty required and > 0');
        }

        $coa       = round($buyPrice * $qty + $buyFees, 2); // Only buy cost + fees
        $proceeds  = round($sellPrice * $qty, 2);           // Sell fees NOT deductible
        $gain      = round($proceeds - $coa, 2);
        $isGain    = $gain > 0;
        $tax30     = $isGain ? round($gain * VDA_TAX_RATE, 2) : 0;
        $cess      = round($tax30 * VDA_CESS_RATE, 2);
        $totalTax  = round($tax30 + $cess, 2);
        $tds1pct   = round($proceeds * VDA_TDS_RATE, 2);
        $netAfterTds = max(0, round($totalTax - $tds1pct, 2));

        json_response(true, '', [
            'buy_price'              => $buyPrice,
            'sell_price'             => $sellPrice,
            'quantity'               => $qty,
            'cost_of_acquisition'    => $coa,
            'gross_proceeds'         => $proceeds,
            'gain_loss'              => $gain,
            'is_gain'                => $isGain,
            'tax_30pct'              => $tax30,
            'cess_4pct'              => $cess,
            'total_tax'              => $totalTax,
            'tds_1pct'               => $tds1pct,
            'net_tax_after_tds'      => $netAfterTds,
            'effective_rate'         => '31.2% (30% tax + 4% cess)',
            'note'                   => $isGain
                ? "Gain: ₹{$gain} → Tax: ₹{$totalTax} (TDS ₹{$tds1pct} credit milega)"
                : "Loss: ₹" . abs($gain) . " — Koi tax nahi. Loss set-off bhi nahi hoga.",
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // ITR EXPORT — schedule-ready data
    // ═══════════════════════════════════════════════════════════════════════
    case 'vda_tax_itr_export':
        $fy = sanitize($_GET['fy'] ?? _vda_current_fy());

        $txns = DB::fetchAll(
            "SELECT id, coin_symbol, coin_name, txn_type,
                    buy_date, sell_date, buy_quantity, sell_quantity,
                    cost_of_acquisition, gross_proceeds, gain_loss_inr, is_gain,
                    tax_30pct, cess_4pct, total_tax_payable, tds_deducted,
                    sell_exchange, notes
             FROM vda_transactions
             WHERE portfolio_id = ? AND fy = ?
               AND txn_type IN ('SELL','GIFT_GIVEN')
             ORDER BY sell_date ASC",
            [$portfolioId, $fy]
        );

        // Format for ITR-3 Schedule VDA
        $itrRows = array_map(function ($t) {
            return [
                'sl_no'                   => null, // fill in UI
                'name_of_vda'             => $t['coin_name'] ?? $t['coin_symbol'],
                'date_of_acquisition'     => $t['buy_date'],
                'date_of_transfer'        => $t['sell_date'],
                'cost_of_acquisition'     => (float)$t['cost_of_acquisition'],
                'sale_consideration'      => (float)$t['gross_proceeds'],
                'income_from_vda'         => (float)$t['gain_loss_inr'],
                'is_gain'                 => (bool)$t['is_gain'],
                'applicable_tax_rate'     => '30%',
                'tax_payable'             => (float)$t['total_tax_payable'],
                'tds_deducted_194s'       => (float)$t['tds_deducted'],
            ];
        }, $txns);

        $totalIncome = array_sum(array_column(
            array_filter($itrRows, fn($r) => $r['is_gain']),
            'income_from_vda'
        ));

        json_response(true, '', [
            'fy'            => $fy,
            'itr_schedule'  => 'Schedule VDA (ITR-2 / ITR-3)',
            'section'       => '115BBH',
            'rows'          => $itrRows,
            'total_count'   => count($itrRows),
            'total_income'  => round($totalIncome, 2),
            'total_tax'     => round(array_sum(array_column($itrRows, 'tax_payable')), 2),
            'filing_note'   => "Ye data ITR mein Schedule VDA mein fill karo. Loss wali rows include mat karo (wo set-off nahi hoti).",
        ]);
        break;

    default:
        json_response(false, "Unknown action: {$action}");
}

// ────────────────────────────────────────────────────────────────────────────
// HELPER FUNCTIONS
// ────────────────────────────────────────────────────────────────────────────

function _parse_vda_input(): array {
    return [
        'coin_symbol'   => strtoupper(sanitize($_POST['coin_symbol']   ?? '')),
        'coin_name'     => sanitize($_POST['coin_name']                ?? ''),
        'coingecko_id'  => sanitize($_POST['coingecko_id']             ?? ''),
        'txn_type'      => strtoupper(sanitize($_POST['txn_type']      ?? 'SELL')),
        'buy_date'      => sanitize($_POST['buy_date']                 ?? ''),
        'buy_quantity'  => (float)($_POST['buy_quantity']              ?? 0),
        'buy_price_inr' => (float)($_POST['buy_price_inr']             ?? 0),
        'buy_cost_inr'  => (float)($_POST['buy_cost_inr']              ?? 0), // override if provided
        'buy_fees_inr'  => (float)($_POST['buy_fees_inr']              ?? 0),
        'buy_exchange'  => sanitize($_POST['buy_exchange']             ?? ''),
        'sell_date'     => sanitize($_POST['sell_date']                ?? ''),
        'sell_quantity' => (float)($_POST['sell_quantity']             ?? 0),
        'sell_price_inr'=> (float)($_POST['sell_price_inr']            ?? 0),
        'sell_fees_inr' => (float)($_POST['sell_fees_inr']             ?? 0),
        'sell_exchange' => sanitize($_POST['sell_exchange']            ?? ''),
        'tds_deducted'  => (float)($_POST['tds_deducted']              ?? 0),
        'tds_date'      => sanitize($_POST['tds_date']                 ?? ''),
        'tds_cert'      => sanitize($_POST['tds_certificate_no']       ?? ''),
        'txn_hash'      => sanitize($_POST['txn_hash']                 ?? ''),
        'notes'         => sanitize($_POST['notes']                    ?? ''),
    ];
}

function _validate_vda_input(array &$d): void {
    if (empty($d['coin_symbol']))  json_response(false, 'coin_symbol required');
    $validTypes = ['BUY','SELL','GIFT_RECEIVED','GIFT_GIVEN','MINING','STAKING','AIRDROP'];
    if (!in_array($d['txn_type'], $validTypes, true)) {
        json_response(false, 'Invalid txn_type. Must be: ' . implode('|', $validTypes));
    }
    if ($d['txn_type'] === 'SELL' && empty($d['sell_date'])) {
        json_response(false, 'sell_date required for SELL transactions');
    }
}

function _compute_vda_tax(array &$d): void {
    $qty       = max($d['buy_quantity'], $d['sell_quantity']);
    // Cost of Acquisition = buy cost + buy fees (only allowable deduction)
    $coa = $d['buy_cost_inr'] > 0
        ? $d['buy_cost_inr'] + $d['buy_fees_inr']
        : ($d['buy_price_inr'] * $qty + $d['buy_fees_inr']);
    // Note: sell_fees_inr is NOT deductible under 115BBH
    $proceeds  = $d['sell_price_inr'] * $d['sell_quantity'];

    $gain      = $proceeds - $coa;
    $isGain    = $gain > 0;
    $tax30     = $isGain ? round($gain * VDA_TAX_RATE, 2) : 0;
    $cess      = round($tax30 * VDA_CESS_RATE, 2);

    $d['cost_of_acquisition'] = round($coa, 2);
    $d['gross_proceeds']      = round($proceeds, 2);
    $d['gain_loss']           = round($gain, 2);
    $d['is_gain']             = $isGain ? 1 : 0;
    $d['tax_30pct']           = $tax30;
    $d['cess_4pct']           = $cess;
    $d['total_tax']           = round($tax30 + $cess, 2);

    // FY determination from sell_date (or buy_date for incoming)
    $dateForFy = $d['sell_date'] ?: $d['buy_date'] ?: date('Y-m-d');
    $d['fy']   = _vda_fy_from_date($dateForFy);
    $d['fy_quarter'] = _vda_quarter($dateForFy);

    // TDS: auto-compute 1% if not provided
    if ($d['tds_deducted'] <= 0 && $proceeds > 0) {
        $d['tds_deducted'] = round($proceeds * VDA_TDS_RATE, 2);
    }
}

function _enrich_vda_row(array $r): array {
    $r['gain_label']        = (float)$r['gain_loss_inr'] >= 0 ? 'Gain' : 'Loss';
    $r['gain_class']        = (float)$r['gain_loss_inr'] >= 0 ? 'text-success' : 'text-danger';
    $r['net_after_tds']     = max(0, round((float)$r['total_tax_payable'] - (float)$r['tds_deducted'], 2));
    $r['holding_days']      = ($r['buy_date'] && $r['sell_date'])
        ? (int)(new DateTime($r['sell_date']))->diff(new DateTime($r['buy_date']))->days
        : null;
    return $r;
}

function _verify_vda_ownership(int $id, int $portfolioId): void {
    $row = DB::fetchOne(
        'SELECT id FROM vda_transactions WHERE id = ? AND portfolio_id = ?',
        [$id, $portfolioId]
    );
    if (!$row) json_response(false, 'VDA transaction not found or access denied');
}

function _vda_fy_from_date(string $date): string {
    $y = (int)date('Y', strtotime($date));
    $m = (int)date('n', strtotime($date));
    $fys = $m >= 4 ? $y : $y - 1;
    return $fys . '-' . ($fys + 1);
}

function _vda_quarter(string $date): int {
    $m = (int)date('n', strtotime($date));
    // FY quarters: Q1=Apr-Jun, Q2=Jul-Sep, Q3=Oct-Dec, Q4=Jan-Mar
    if ($m >= 4 && $m <= 6)  return 1;
    if ($m >= 7 && $m <= 9)  return 2;
    if ($m >= 10 && $m <= 12) return 3;
    return 4;
}

function _vda_current_fy(): string {
    $y = (int)date('Y');
    $m = (int)date('n');
    $fys = $m >= 4 ? $y : $y - 1;
    return $fys . '-' . ($fys + 1);
}

function _vda_fy_dates(string $fy = ''): array {
    if ($fy && preg_match('/^(\d{4})-(\d{4})$/', $fy, $m)) {
        return [$m[1] . '-04-01', $m[2] . '-03-31'];
    }
    $label = _vda_current_fy();
    [$a, $b] = explode('-', $label);
    return [$a . '-04-01', $b . '-03-31'];
}
