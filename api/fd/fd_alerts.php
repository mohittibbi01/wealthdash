<?php
/**
 * WealthDash — t80: FD Maturity Alert
 * 30/7/1 day pehle reminders + t421: FD Rate Tracker
 * Actions: fd_alerts_list | fd_alerts_check | fd_rate_tracker | fd_maturity_calendar
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'fd_alerts_list';

// Ensure alerts table
try {
    DB::conn()->exec("
        CREATE TABLE IF NOT EXISTS fd_maturity_alerts (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id      INT UNSIGNED NOT NULL,
            fd_id        INT UNSIGNED NOT NULL,
            alert_days   TINYINT UNSIGNED NOT NULL,
            is_sent      TINYINT(1) NOT NULL DEFAULT 0,
            sent_at      DATETIME DEFAULT NULL,
            is_dismissed TINYINT(1) NOT NULL DEFAULT 0,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_fd_days (fd_id, alert_days),
            INDEX idx_user (user_id),
            INDEX idx_sent (is_sent)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── LIST UPCOMING FD ALERTS ────────────────────────────────
    case 'fd_alerts_list':
        $days = (int) ($_GET['days'] ?? 60); // Look ahead

        $upcoming = DB::fetchAll(
            "SELECT fd.id, fd.bank_name, fd.principal_amount, fd.interest_rate,
                    fd.maturity_date, fd.maturity_amount, fd.fd_type,
                    DATEDIFF(fd.maturity_date, CURDATE()) AS days_left,
                    fd.auto_renewal
             FROM fd_investments fd
             WHERE fd.user_id = ?
               AND fd.status = 'active'
               AND fd.maturity_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
               AND fd.maturity_date >= CURDATE()
             ORDER BY fd.maturity_date ASC",
            [$userId, $days]
        );

        // Tag urgency
        foreach ($upcoming as &$fd) {
            $d = (int) $fd['days_left'];
            $fd['urgency'] = $d <= 1 ? 'critical' : ($d <= 7 ? 'high' : ($d <= 30 ? 'medium' : 'low'));
            $fd['urgency_label'] = match(true) {
                $d <= 1  => '🔴 Tomorrow/Today!',
                $d <= 7  => '🟠 This Week',
                $d <= 30 => '🟡 This Month',
                default  => '🟢 Upcoming',
            };
            $fd['interest_earned'] = round(
                (float)$fd['maturity_amount'] - (float)$fd['principal_amount'], 2
            );
        }
        unset($fd);

        json_response(true, '', [
            'alerts'   => $upcoming,
            'total'    => count($upcoming),
            'critical' => count(array_filter($upcoming, fn($f) => $f['urgency'] === 'critical')),
            'high'     => count(array_filter($upcoming, fn($f) => $f['urgency'] === 'high')),
        ]);

    // ── CHECK AND CREATE ALERTS (called by cron) ──────────────
    case 'fd_alerts_check':
        if (!is_admin() && !is_cli()) {
            json_response(false, 'Admin/cron only.', [], 403);
        }

        $alertThresholds = [30, 7, 1]; // Days before maturity
        $created = 0; $notified = 0;

        // Get ALL users' active FDs
        $fds = DB::fetchAll(
            "SELECT fd.id, fd.user_id, fd.bank_name, fd.principal_amount,
                    fd.maturity_date, fd.maturity_amount,
                    DATEDIFF(fd.maturity_date, CURDATE()) AS days_left,
                    u.email, u.name
             FROM fd_investments fd
             JOIN users u ON u.id = fd.user_id
             WHERE fd.status = 'active'
               AND fd.maturity_date >= CURDATE()
               AND fd.maturity_date <= DATE_ADD(CURDATE(), INTERVAL 31 DAY)"
        );

        foreach ($fds as $fd) {
            $daysLeft = (int) $fd['days_left'];

            foreach ($alertThresholds as $threshold) {
                if ($daysLeft !== $threshold) continue;

                // Try insert (UNIQUE prevents duplicate)
                try {
                    DB::run(
                        "INSERT INTO fd_maturity_alerts (user_id, fd_id, alert_days) VALUES (?, ?, ?)",
                        [$fd['user_id'], $fd['id'], $threshold]
                    );
                    $created++;

                    // Queue notification (in-app)
                    DB::run(
                        "INSERT INTO notifications (user_id, type, title, body, link)
                         VALUES (?, 'fd_maturity', ?, ?, '/wealthdash/fd')
                         ON DUPLICATE KEY UPDATE created_at = created_at",
                        [
                            $fd['user_id'],
                            "FD Maturity Alert — {$threshold} din bache",
                            "{$fd['bank_name']} ka ₹" . number_format($fd['principal_amount']) . " FD {$threshold} din mein mature hoga ({$fd['maturity_date']}). Maturity amount: ₹" . number_format($fd['maturity_amount']),
                        ]
                    );
                    $notified++;
                } catch (Exception $e) { /* already created */ }
            }
        }

        json_response(true, "Alerts checked. Created: {$created}, Notified: {$notified}.");

    // ── FD RATE TRACKER — t421 ─────────────────────────────────
    case 'fd_rate_tracker':
        // Top bank FD rates (manually maintained / can be updated via admin)
        $rates = DB::fetchAll(
            "SELECT bank_name, tenure_label, rate_general, rate_senior, effective_date, source_url
             FROM fd_market_rates
             ORDER BY tenure_label, rate_general DESC"
        );

        if (empty($rates)) {
            // Seed with common rates (static fallback — update via admin)
            $defaultRates = [
                ['SBI', '1 Year', 6.80, 7.30],
                ['HDFC Bank', '1 Year', 6.60, 7.10],
                ['ICICI Bank', '1 Year', 6.70, 7.20],
                ['Axis Bank', '1 Year', 6.70, 7.45],
                ['Kotak Bank', '1 Year', 7.10, 7.60],
                ['SBI', '2 Year', 7.00, 7.50],
                ['HDFC Bank', '2 Year', 7.00, 7.50],
                ['ICICI Bank', '2 Year', 7.00, 7.50],
                ['SBI', '5 Year', 6.50, 7.50],
                ['HDFC Bank', '5 Year', 7.00, 7.50],
            ];

            try {
                DB::conn()->exec("
                    CREATE TABLE IF NOT EXISTS fd_market_rates (
                        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        bank_name      VARCHAR(80)  NOT NULL,
                        tenure_label   VARCHAR(30)  NOT NULL,
                        rate_general   DECIMAL(5,2) NOT NULL,
                        rate_senior    DECIMAL(5,2) NOT NULL,
                        effective_date DATE DEFAULT (CURDATE()),
                        source_url     VARCHAR(300) DEFAULT NULL,
                        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");

                $stmt = DB::conn()->prepare(
                    "INSERT IGNORE INTO fd_market_rates (bank_name, tenure_label, rate_general, rate_senior) VALUES (?,?,?,?)"
                );
                foreach ($defaultRates as $r) $stmt->execute($r);
                $rates = DB::fetchAll("SELECT * FROM fd_market_rates ORDER BY tenure_label, rate_general DESC");
            } catch (Exception $e) {
                $rates = array_map(fn($r) => [
                    'bank_name' => $r[0], 'tenure_label' => $r[1],
                    'rate_general' => $r[2], 'rate_senior' => $r[3],
                    'effective_date' => date('Y-m-d'),
                ], $defaultRates);
            }
        }

        // Group by tenure
        $byTenure = [];
        foreach ($rates as $r) {
            $byTenure[$r['tenure_label']][] = $r;
        }

        // Best rate per tenure
        $bestRates = [];
        foreach ($byTenure as $tenure => $list) {
            usort($list, fn($a,$b) => $b['rate_general'] <=> $a['rate_general']);
            $bestRates[$tenure] = $list[0];
        }

        json_response(true, '', [
            'rates'          => $rates,
            'by_tenure'      => $byTenure,
            'best_per_tenure'=> $bestRates,
            'last_updated'   => date('Y-m-d'),
            'note'           => 'Rates approximate hain — bank website se verify karo before investing.',
        ]);

    // ── FD MATURITY CALENDAR ──────────────────────────────────
    case 'fd_maturity_calendar':
        $months = min((int)($_GET['months'] ?? 12), 24);

        $fds = DB::fetchAll(
            "SELECT id, bank_name, principal_amount, maturity_amount, interest_rate,
                    maturity_date, fd_type, auto_renewal,
                    DATE_FORMAT(maturity_date, '%Y-%m') AS month_key
             FROM fd_investments
             WHERE user_id = ? AND status = 'active'
               AND maturity_date <= DATE_ADD(CURDATE(), INTERVAL ? MONTH)
             ORDER BY maturity_date ASC",
            [$userId, $months]
        );

        // Group by month
        $byMonth = [];
        foreach ($fds as $fd) {
            $byMonth[$fd['month_key']][] = $fd;
        }

        // Monthly maturity totals
        $monthTotals = [];
        foreach ($byMonth as $month => $list) {
            $monthTotals[$month] = [
                'month'           => $month,
                'count'           => count($list),
                'total_principal' => array_sum(array_column($list, 'principal_amount')),
                'total_maturity'  => array_sum(array_column($list, 'maturity_amount')),
                'fds'             => $list,
            ];
        }

        json_response(true, '', [
            'calendar'       => array_values($monthTotals),
            'total_maturing' => array_sum(array_column($fds, 'maturity_amount')),
            'count'          => count($fds),
        ]);

    default:
        json_response(false, 'Unknown FD alert action.', [], 400);
}
