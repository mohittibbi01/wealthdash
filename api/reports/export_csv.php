<?php
/**
 * WealthDash — Export CSV / Tax Report
 * POST /api/?action=export_holdings_csv
 * POST /api/?action=export_tax_report_csv
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_POST['portfolio_id'] ?? $_SESSION['selected_portfolio_id'] ?? 0);
$exportType  = clean($_POST['export_type'] ?? 'holdings'); // holdings | tax | transactions | full

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

// Get portfolio name for filename
$portName = DB::fetchVal('SELECT name FROM portfolios WHERE id = ?', [$portfolioId]);
$portSlug = preg_replace('/[^a-z0-9]+/i', '_', strtolower($portName ?? 'portfolio'));
$dateStr  = date('d-m-Y');

/* ─── Helper: send CSV to browser ─────────────────────────────────────────── */
function send_csv(string $filename, array $headers, array $rows): void {
    // Clean output buffer
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/* ─── EXPORT 1: MF Holdings CSV ───────────────────────────────────────────── */
if ($exportType === 'holdings' || $exportType === 'mf_holdings') {
    $rows = DB::fetchAll(
        "SELECT h.folio_number, f.scheme_name, fh.name AS fund_house,
                f.category, f.sub_category, h.total_units, h.avg_cost_nav,
                h.total_invested, h.value_now, h.gain_loss, h.gain_pct, h.cagr,
                h.first_purchase_date, h.ltcg_date, h.lock_in_date,
                h.withdrawable_date, h.investment_fy, h.withdrawable_fy,
                h.gain_type, f.latest_nav, f.latest_nav_date
         FROM mf_holdings h
         JOIN funds f ON f.id = h.fund_id
         JOIN fund_houses fh ON fh.id = f.fund_house_id
         WHERE h.portfolio_id = ? AND h.is_active = 1
         ORDER BY f.category, f.scheme_name",
        [$portfolioId]
    );

    $headers = [
        'Folio Number', 'Fund Name', 'Fund House', 'Category', 'Sub-Category',
        'Total Units', 'Avg Cost NAV (₹)', 'Total Invested (₹)', 'Current Value (₹)',
        'Gain/Loss (₹)', 'Gain %', 'XIRR %', 'First Purchase', 'LTCG Eligible From',
        'Lock-in Ends', 'Withdrawable From', 'Investment FY', 'Withdrawable FY',
        'Gain Type', 'Latest NAV (₹)', 'NAV Date'
    ];

    $csvRows = [];
    foreach ($rows as $r) {
        $csvRows[] = [
            $r['folio_number'] ?? '',
            $r['scheme_name'],
            $r['fund_house'],
            $r['category'],
            $r['sub_category'],
            number_format((float)$r['total_units'], 4, '.', ''),
            number_format((float)$r['avg_cost_nav'], 4, '.', ''),
            number_format((float)$r['total_invested'], 2, '.', ''),
            number_format((float)$r['value_now'], 2, '.', ''),
            number_format((float)$r['gain_loss'], 2, '.', ''),
            number_format((float)$r['gain_pct'], 2, '.', '') . '%',
            $r['cagr'] ? number_format((float)$r['cagr'], 2, '.', '') . '%' : '',
            $r['first_purchase_date'] ? format_date($r['first_purchase_date']) : '',
            $r['ltcg_date'] ? format_date($r['ltcg_date']) : '',
            $r['lock_in_date'] ? format_date($r['lock_in_date']) : '',
            $r['withdrawable_date'] ? format_date($r['withdrawable_date']) : '',
            $r['investment_fy'] ?? '',
            $r['withdrawable_fy'] ?? '',
            $r['gain_type'],
            number_format((float)$r['latest_nav'], 4, '.', ''),
            $r['latest_nav_date'] ? format_date($r['latest_nav_date']) : '',
        ];
    }

    send_csv("wealthdash_mf_holdings_{$portSlug}_{$dateStr}.csv", $headers, $csvRows);
}

/* ─── EXPORT 2: Tax Report CSV ────────────────────────────────────────────── */
if ($exportType === 'tax_report') {
    $fy = clean($_POST['fy'] ?? '');
    [$fyStartY, $fyEndY2] = explode('-', $fy ?: (date('n') >= 4 ? date('Y') . '-' . substr((string)(date('Y')+1),2) : (date('Y')-1) . '-' . substr(date('Y'),2)));
    $fyStart = $fyStartY . '-04-01';
    $fyEnd   = ('20' . $fyEndY2) . '-03-31';

    // MF sells
    $mfSells = DB::fetchAll(
        "SELECT t.txn_date, f.scheme_name, fh.name AS fund_house,
                f.category, t.folio_number, t.units AS sell_units,
                t.nav AS sell_nav, t.value_at_cost AS proceeds
         FROM mf_transactions t
         JOIN funds f ON f.id = t.fund_id
         JOIN fund_houses fh ON fh.id = f.fund_house_id
         WHERE t.portfolio_id = ?
           AND t.transaction_type IN ('SELL','SWITCH_OUT','SWP')
           AND t.txn_date BETWEEN ? AND ?
         ORDER BY t.txn_date",
        [$portfolioId, $fyStart, $fyEnd]
    );

    $headers = [
        'Date', 'Asset Type', 'Name', 'Fund House/Exchange', 'Category/Sector',
        'Folio/Symbol', 'Units/Qty', 'Sell NAV/Price (₹)', 'Proceeds (₹)',
        'Cost Basis (₹)', 'Gain/Loss (₹)', 'Holding Days', 'Gain Type', 'Tax Rate %', 'Approx Tax (₹)'
    ];

    $csvRows = [];
    foreach ($mfSells as $sell) {
        // Simple cost: use avg_cost_nav from holdings for now (full FIFO would need more processing)
        $h = DB::fetchRow(
            "SELECT avg_cost_nav, first_purchase_date FROM mf_holdings
             WHERE portfolio_id = ? AND fund_id = (SELECT id FROM funds WHERE scheme_name = ? LIMIT 1) LIMIT 1",
            [$portfolioId, $sell['scheme_name']]
        );
        $cost = (float)$sell['sell_units'] * (float)($h['avg_cost_nav'] ?? $sell['sell_nav']);
        $gain = (float)$sell['proceeds'] - $cost;
        $firstBuy = $h['first_purchase_date'] ?? $sell['txn_date'];
        $days = (int)(new DateTime($sell['txn_date']))->diff(new DateTime($firstBuy))->days;
        $assetType = (strpos(strtolower($sell['category']),'debt') !== false) ? 'debt' : 'equity';
        $taxInfo = TaxEngine::mf_gain_tax($gain, $firstBuy, $sell['txn_date'], $assetType);

        $csvRows[] = [
            format_date($sell['txn_date']),
            'Mutual Fund',
            $sell['scheme_name'],
            $sell['fund_house'],
            $sell['category'],
            $sell['folio_number'] ?? '',
            number_format((float)$sell['sell_units'], 4, '.', ''),
            number_format((float)$sell['sell_nav'], 4, '.', ''),
            number_format((float)$sell['proceeds'], 2, '.', ''),
            number_format($cost, 2, '.', ''),
            number_format($gain, 2, '.', ''),
            $days,
            $taxInfo['gain_type'],
            $taxInfo['tax_rate'] ?? 'Slab',
            $taxInfo['tax_amount'] ? number_format((float)$taxInfo['tax_amount'], 2, '.', '') : 'As per slab',
        ];
    }

    // Stock sells
    $stSells = DB::fetchAll(
        "SELECT t.txn_date, sm.company_name, sm.exchange, sm.symbol,
                t.quantity AS sell_qty, t.price AS sell_price, t.value_at_cost AS proceeds,
                t.brokerage, t.stt, t.exchange_charges
         FROM stock_transactions t
         JOIN stock_master sm ON sm.id = t.stock_id
         WHERE t.portfolio_id = ? AND t.txn_type = 'SELL'
           AND t.txn_date BETWEEN ? AND ?
         ORDER BY t.txn_date",
        [$portfolioId, $fyStart, $fyEnd]
    );

    foreach ($stSells as $sell) {
        $h = DB::fetchRow(
            "SELECT avg_buy_price, first_buy_date FROM stock_holdings
             WHERE portfolio_id = ? AND stock_id = (SELECT id FROM stock_master WHERE symbol = ? LIMIT 1) LIMIT 1",
            [$portfolioId, $sell['symbol']]
        );
        $cost = (float)$sell['sell_qty'] * (float)($h['avg_buy_price'] ?? $sell['sell_price']);
        $charges = (float)$sell['brokerage'] + (float)$sell['stt'] + (float)$sell['exchange_charges'];
        $gain = (float)$sell['proceeds'] - $cost - $charges;
        $firstBuy = $h['first_buy_date'] ?? $sell['txn_date'];
        $days = (int)(new DateTime($sell['txn_date']))->diff(new DateTime($firstBuy))->days;
        $taxInfo = TaxEngine::stock_gain_tax($gain, $firstBuy, $sell['txn_date']);

        $csvRows[] = [
            format_date($sell['txn_date']),
            'Stock',
            $sell['company_name'],
            $sell['exchange'],
            '',
            $sell['symbol'],
            number_format((float)$sell['sell_qty'], 0, '.', ''),
            number_format((float)$sell['sell_price'], 2, '.', ''),
            number_format((float)$sell['proceeds'], 2, '.', ''),
            number_format($cost, 2, '.', ''),
            number_format($gain, 2, '.', ''),
            $days,
            $taxInfo['gain_type'],
            $taxInfo['tax_rate'] ?? 'Slab',
            $taxInfo['tax_amount'] ? number_format((float)$taxInfo['tax_amount'], 2, '.', '') : '',
        ];
    }

    send_csv("wealthdash_tax_report_{$portSlug}_{$fy}_{$dateStr}.csv", $headers, $csvRows);
}

/* ─── EXPORT 3: All MF Transactions ──────────────────────────────────────── */
if ($exportType === 'mf_transactions') {
    $rows = DB::fetchAll(
        "SELECT t.txn_date, f.scheme_name, fh.name AS fund_house,
                f.category, t.folio_number, t.transaction_type, t.platform,
                t.units, t.nav, t.value_at_cost, t.stamp_duty, t.investment_fy, t.notes
         FROM mf_transactions t
         JOIN funds f ON f.id = t.fund_id
         JOIN fund_houses fh ON fh.id = f.fund_house_id
         WHERE t.portfolio_id = ?
         ORDER BY t.txn_date DESC",
        [$portfolioId]
    );

    $headers = ['Date','Fund Name','Fund House','Category','Folio','Type','Platform','Units','NAV (₹)','Value (₹)','Stamp Duty (₹)','FY','Notes'];
    $csvRows = [];
    foreach ($rows as $r) {
        $csvRows[] = [
            format_date($r['txn_date']),
            $r['scheme_name'],
            $r['fund_house'],
            $r['category'],
            $r['folio_number'] ?? '',
            $r['transaction_type'],
            $r['platform'] ?? '',
            number_format((float)$r['units'], 4, '.', ''),
            number_format((float)$r['nav'], 4, '.', ''),
            number_format((float)$r['value_at_cost'], 2, '.', ''),
            number_format((float)$r['stamp_duty'], 2, '.', ''),
            $r['investment_fy'] ?? '',
            $r['notes'] ?? '',
        ];
    }
    send_csv("wealthdash_mf_transactions_{$portSlug}_{$dateStr}.csv", $headers, $csvRows);
}

/* ─── EXPORT 4: Stock Holdings ────────────────────────────────────────────── */
if ($exportType === 'stock_holdings') {
    $rows = DB::fetchAll(
        "SELECT sm.symbol, sm.company_name, sm.exchange, sm.sector,
                h.quantity, h.avg_buy_price, h.total_invested, h.current_value,
                h.gain_loss, h.gain_pct, h.cagr, h.first_buy_date, h.ltcg_date, h.gain_type,
                sm.latest_price, sm.latest_price_date
         FROM stock_holdings h
         JOIN stock_master sm ON sm.id = h.stock_id
         WHERE h.portfolio_id = ? AND h.is_active = 1
         ORDER BY h.current_value DESC",
        [$portfolioId]
    );

    $headers = ['Symbol','Company','Exchange','Sector','Quantity','Avg Buy Price (₹)','Total Invested (₹)','Current Value (₹)','Gain/Loss (₹)','Gain %','XIRR %','First Buy','LTCG From','Gain Type','Latest Price (₹)','Price Date'];
    $csvRows = [];
    foreach ($rows as $r) {
        $csvRows[] = [
            $r['symbol'],
            $r['company_name'],
            $r['exchange'],
            $r['sector'] ?? '',
            number_format((float)$r['quantity'], 0, '.', ''),
            number_format((float)$r['avg_buy_price'], 2, '.', ''),
            number_format((float)$r['total_invested'], 2, '.', ''),
            number_format((float)$r['current_value'], 2, '.', ''),
            number_format((float)$r['gain_loss'], 2, '.', ''),
            number_format((float)$r['gain_pct'], 2, '.', '') . '%',
            $r['cagr'] ? number_format((float)$r['cagr'], 2, '.', '') . '%' : '',
            $r['first_buy_date'] ? format_date($r['first_buy_date']) : '',
            $r['ltcg_date'] ? format_date($r['ltcg_date']) : '',
            $r['gain_type'],
            number_format((float)$r['latest_price'], 2, '.', ''),
            $r['latest_price_date'] ? format_date($r['latest_price_date']) : '',
        ];
    }
    send_csv("wealthdash_stocks_{$portSlug}_{$dateStr}.csv", $headers, $csvRows);
}

/* ─── EXPORT 5: Full Net Worth summary ────────────────────────────────────── */
if ($exportType === 'net_worth') {
    $today = date('Y-m-d');

    $mfTotal = DB::fetchVal("SELECT COALESCE(SUM(value_now),0) FROM mf_holdings WHERE portfolio_id=? AND is_active=1", [$portfolioId]);
    $stTotal = DB::fetchVal("SELECT COALESCE(SUM(current_value),0) FROM stock_holdings WHERE portfolio_id=? AND is_active=1", [$portfolioId]);
    $npsTotal= DB::fetchVal("SELECT COALESCE(SUM(latest_value),0) FROM nps_holdings WHERE portfolio_id=?", [$portfolioId]);
    $fdRow   = DB::fetchRow("SELECT COALESCE(SUM(principal),0) AS p, COALESCE(SUM(accrued_interest),0) AS ai FROM fd_accounts WHERE portfolio_id=? AND status='active'", [$portfolioId]);
    $savTotal= DB::fetchVal("SELECT COALESCE(SUM(current_balance),0) FROM savings_accounts WHERE portfolio_id=? AND is_active=1", [$portfolioId]);
    $fdTotal = (float)$fdRow['p'] + (float)$fdRow['ai'];

    $grand = (float)$mfTotal + (float)$stTotal + (float)$npsTotal + $fdTotal + (float)$savTotal;

    $headers = ['Asset Class', 'Current Value (₹)', 'Allocation %'];
    $csvRows = [
        ['Mutual Funds',    number_format((float)$mfTotal, 2, '.', ''), $grand > 0 ? round(((float)$mfTotal/$grand)*100,2).'%' : '0%'],
        ['Stocks & ETF',    number_format((float)$stTotal, 2, '.', ''), $grand > 0 ? round(((float)$stTotal/$grand)*100,2).'%' : '0%'],
        ['NPS',             number_format((float)$npsTotal,2, '.', ''), $grand > 0 ? round(((float)$npsTotal/$grand)*100,2).'%' : '0%'],
        ['Fixed Deposits',  number_format($fdTotal, 2, '.', ''),         $grand > 0 ? round(($fdTotal/$grand)*100,2).'%' : '0%'],
        ['Savings Accounts',number_format((float)$savTotal,2, '.', ''), $grand > 0 ? round(((float)$savTotal/$grand)*100,2).'%' : '0%'],
        ['TOTAL NET WORTH', number_format($grand, 2, '.', ''),           '100%'],
    ];
    send_csv("wealthdash_networth_{$portSlug}_{$dateStr}.csv", $headers, $csvRows);
}

json_response(false, 'Invalid export type. Use: holdings, tax_report, mf_transactions, stock_holdings, net_worth');

