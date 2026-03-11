<?php
/**
 * WealthDash — FD Maturity Alert Cron
 * Schedule: Daily at 9:00 AM IST
 * Sends email alerts for FDs maturing in next 30 days
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

echo "[" . date('Y-m-d H:i:s') . "] Checking FD maturity alerts...\n";

// Find FDs maturing in next 30 days (not already alerted today)
$fds = DB::fetchAll(
    "SELECT fd.*, p.user_id, u.name as user_name, u.email as user_email
     FROM fd_accounts fd
     JOIN portfolios p ON p.id = fd.portfolio_id
     JOIN users u ON u.id = p.user_id
     WHERE fd.status = 'active'
     AND fd.maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY u.id, fd.maturity_date ASC"
);

if (empty($fds)) {
    echo "No FDs maturing in next 30 days.\n";
    exit(0);
}

// Group by user
$byUser = [];
foreach ($fds as $fd) {
    $byUser[$fd['user_id']][] = $fd;
}

$sent = 0;
foreach ($byUser as $userId => $userFds) {
    $user = DB::fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user || !$user['email']) continue;

    $result = Notification::send_fd_maturity_alert(
        $user['email'],
        $user['name'],
        $userFds
    );

    if ($result) {
        echo "Alert sent to {$user['email']} for " . count($userFds) . " FD(s).\n";
        $sent++;
    }
}

echo "Done. Alerts sent to {$sent} users.\n";

