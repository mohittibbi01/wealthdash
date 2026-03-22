<?php
/**
 * WealthDash Debug — test_db.php
 * Tests: DB connection, required tables, required columns
 */
defined('WD_DEBUG_RUNNER') or die('Direct access not allowed');

// ── 1. Connection ──────────────────────────────────────────────────────────
try {
    $pdo = DB::conn();
    wd_pass('DB', 'Connection', 'Database connected successfully');
} catch (Throwable $e) {
    wd_fail('DB', 'Connection', 'PDO connect failed: ' . $e->getMessage());
    return; // further tests pointless
}

// ── 2. Required tables ─────────────────────────────────────────────────────
$required_tables = [
    'users', 'mf_holdings', 'mf_transactions', 'funds', 'nav_history',
    'nps_holdings', 'fd_accounts', 'savings_accounts', 'sip_schedules',
    'po_schemes',       // Post Office — table is po_schemes (auto-created by po_schemes.php)
    'stock_holdings',   // Stocks — table is stock_holdings (not stocks_holdings)
    'portfolios',
];
foreach ($required_tables as $tbl) {
    $r = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl));
    if ($r && $r->rowCount() > 0)
        wd_pass('DB Table', "`$tbl`", 'Exists');
    else
        wd_fail('DB Table', "`$tbl`", "Table not found — run migrations");
}

// ── 3. Critical columns (mapped to ACTUAL column names in this DB) ────────
$col_checks = [
    // mf_holdings — uses portfolio_id (not user_id), avg_cost_nav, last_calculated
    'mf_holdings'    => ['portfolio_id','fund_id','total_units','avg_cost_nav','total_invested','last_calculated'],
    // mf_transactions — uses portfolio_id, txn_date, value_at_cost (not amount/transaction_date)
    'mf_transactions'=> ['portfolio_id','fund_id','transaction_type','nav','units','value_at_cost','txn_date'],
    // funds — uses scheme_name (not fund_name), exit_load_pct (not exit_load_percent)
    'funds'          => ['scheme_code','scheme_name','category','exit_load_pct'],
    // nav_history — uses fund_id (not scheme_code)
    'nav_history'    => ['fund_id','nav_date','nav'],
    // sip_schedules — uses portfolio_id, sip_amount (not amount)
    'sip_schedules'  => ['portfolio_id','fund_id','sip_amount','frequency','start_date'],
];
foreach ($col_checks as $tbl => $cols) {
    // check table exists before querying columns
    $tblExists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl));
    if (!$tblExists || $tblExists->rowCount() === 0) continue;
    $existing = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $col) {
        if (in_array($col, $existing))
            wd_pass('DB Column', "`$tbl`.`$col`", 'Exists');
        else
            wd_fail('DB Column', "`$tbl`.`$col`", "Column missing — check migrations");
    }
}

// ── 4. Data freshness ─────────────────────────────────────────────────────
try {
    $tblExists = $pdo->query("SHOW TABLES LIKE 'nav_history'")->rowCount() > 0;
    if ($tblExists) {
        $row = $pdo->query("SELECT MAX(nav_date) AS d, COUNT(*) AS cnt FROM nav_history")->fetch();
        if (!$row || !$row['d']) {
            wd_warn('DB Data', 'NAV history', 'Table is empty — run AMFI import');
        } else {
            $age = (int) round((time() - strtotime($row['d'])) / 86400);
            $cnt = (int) $row['cnt'];
            if ($age <= 1)
                wd_pass('DB Data', 'NAV freshness', "Latest: {$row['d']} ({$cnt} rows) — fresh");
            elseif ($age <= 5)
                wd_warn('DB Data', 'NAV freshness', "Latest: {$row['d']} — {$age} days old, run cron");
            else
                wd_fail('DB Data', 'NAV freshness', "STALE: {$age} days old — cron may be broken");
        }
    }
    $fundsCount = $pdo->query("SHOW TABLES LIKE 'funds'")->rowCount() > 0
        ? (int)$pdo->query("SELECT COUNT(*) FROM funds")->fetchColumn()
        : 0;
    if ($fundsCount > 500)
        wd_pass('DB Data', 'Funds table', "$fundsCount fund records");
    elseif ($fundsCount > 0)
        wd_warn('DB Data', 'Funds table', "Only $fundsCount funds — run fetch_amfi_funds.php");
    else
        wd_fail('DB Data', 'Funds table', "Empty — run fetch_amfi_funds.php");
} catch (Throwable $e) {
    wd_warn('DB Data', 'Freshness check', $e->getMessage());
}
