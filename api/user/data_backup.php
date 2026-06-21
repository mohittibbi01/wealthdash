<?php
/**
 * WealthDash — Data Backup
 * Task t259: JSON export of all user data — portfolios, holdings, transactions, FDs, NPS, goals
 * Action: data_backup_download | data_backup_status
 * Note: PHP-only JSON export (no mysqldump needed — portable and safe)
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'data_backup_download';
$db          = DB::conn();

if ($action === 'data_backup_status') {
    // Quick stats about user data
    $stats = [];
    $tables = [
        'portfolios'      => "SELECT COUNT(*) FROM portfolios WHERE user_id=?",
        'mf_holdings'     => "SELECT COUNT(*) FROM mf_holdings mh JOIN portfolios p ON p.id=mh.portfolio_id WHERE p.user_id=?",
        'mf_transactions' => "SELECT COUNT(*) FROM mf_transactions t JOIN mf_holdings mh ON mh.id=t.holding_id JOIN portfolios p ON p.id=mh.portfolio_id WHERE p.user_id=?",
        'fd_holdings'     => "SELECT COUNT(*) FROM fd_holdings WHERE user_id=?",
        'nps_holdings'    => "SELECT COUNT(*) FROM nps_holdings nh JOIN portfolios p ON p.id=nh.portfolio_id WHERE p.user_id=?",
        'goals'           => "SELECT COUNT(*) FROM goals WHERE user_id=?",
        'savings_accounts'=> "SELECT COUNT(*) FROM savings_accounts WHERE user_id=?",
    ];
    foreach ($tables as $tbl => $sql) {
        try {
            $s = $db->prepare($sql); $s->execute([$userId]);
            $stats[$tbl] = (int)$s->fetchColumn();
        } catch(Exception $e) { $stats[$tbl] = 0; }
    }
    echo json_encode(['success'=>true,'data'=>$stats]);
    return;
}

// ── Full JSON backup ───────────────────────────────────────────────
if ($action !== 'data_backup_download') {
    echo json_encode(['success'=>false,'error'=>'Unknown action']); return;
}

$backup = [
    'exported_at' => date('c'),
    'version'     => 'WealthDash-v49',
    'user_id'     => $userId,
    'data'        => [],
];

// Helper
$export = function(string $key, string $sql, array $params) use ($db, &$backup, $userId) {
    try {
        $params_final = array_map(fn($p) => $p === '__uid__' ? (string)$userId : $p, $params);
        $s = $db->prepare($sql);
        $s->execute($params_final);
        $backup['data'][$key] = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        $backup['data'][$key] = [];
        $backup['errors'][$key] = $e->getMessage();
    }
};

// Portfolios
$export('portfolios',
    "SELECT * FROM portfolios WHERE user_id=?", ['__uid__']);

// MF Holdings + Transactions
$export('mf_holdings',
    "SELECT mh.* FROM mf_holdings mh JOIN portfolios p ON p.id=mh.portfolio_id WHERE p.user_id=?", ['__uid__']);
$export('mf_transactions',
    "SELECT t.* FROM mf_transactions t JOIN mf_holdings mh ON mh.id=t.holding_id JOIN portfolios p ON p.id=mh.portfolio_id WHERE p.user_id=?", ['__uid__']);

// FD
$export('fd_holdings', "SELECT * FROM fd_holdings WHERE user_id=?", ['__uid__']);

// NPS
$export('nps_holdings',
    "SELECT nh.* FROM nps_holdings nh JOIN portfolios p ON p.id=nh.portfolio_id WHERE p.user_id=?", ['__uid__']);
$export('nps_transactions', "SELECT * FROM nps_transactions WHERE user_id=?", ['__uid__']);

// Savings
$export('savings_accounts', "SELECT * FROM savings_accounts WHERE user_id=?", ['__uid__']);

// Goals
$export('goals', "SELECT * FROM goals WHERE user_id=?", ['__uid__']);

// Stocks
$export('stock_holdings',
    "SELECT sh.* FROM stock_holdings sh JOIN portfolios p ON p.id=sh.portfolio_id WHERE p.user_id=?", ['__uid__']);
$export('stock_transactions',
    "SELECT st.* FROM stock_transactions st JOIN stock_holdings sh ON sh.id=st.stock_id JOIN portfolios p ON p.id=sh.portfolio_id WHERE p.user_id=?", ['__uid__']);

// Investment Journal
try {
    $export('investment_journal', "SELECT * FROM investment_journal WHERE user_id=?", ['__uid__']);
} catch(Exception $e) {}

// Credit Cards
try {
    $export('credit_cards', "SELECT * FROM credit_cards WHERE user_id=?", ['__uid__']);
} catch(Exception $e) {}

// Watchlist
try {
    $export('watchlist',
        "SELECT w.* FROM mf_watchlist w WHERE w.user_id=?", ['__uid__']);
} catch(Exception $e) {}

// Audit log (last 6 months only to keep size manageable)
try {
    $export('audit_log',
        "SELECT * FROM audit_log WHERE user_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) ORDER BY created_at DESC LIMIT 1000",
        ['__uid__']);
} catch(Exception $e) {}

$json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Strip sensitive fields
$json = preg_replace('/"password"\s*:\s*"[^"]*"/', '"password": "[REDACTED]"', $json);
$json = preg_replace('/"pan_number"\s*:\s*"[^"]*"/', '"pan_number": "[REDACTED]"', $json);

$filename = 'wealthdash_backup_' . date('Ymd_His') . '_user' . $userId . '.json';

header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
header('Cache-Control: no-cache, no-store');
header('X-Backup-Records: ' . array_sum(array_map('count', $backup['data'])));
echo $json;
exit;
