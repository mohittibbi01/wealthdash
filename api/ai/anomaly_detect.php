<?php
/**
 * WealthDash — t384 / t246: AI Anomaly Detector
 * Unusual portfolio activity aur pattern flagging
 * Actions: ai_anomaly_detect | ai_anomaly_list | ai_anomaly_dismiss
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'ai_anomaly_detect';

RateLimit::check('ai_anomaly', $userId);

// Ensure anomalies table
try {
    DB::conn()->exec("
        CREATE TABLE IF NOT EXISTS ai_anomalies (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id      INT UNSIGNED NOT NULL,
            anomaly_type VARCHAR(60)  NOT NULL,
            severity     ENUM('info','warning','critical') DEFAULT 'warning',
            title        VARCHAR(200) NOT NULL,
            description  TEXT,
            data_json    TEXT,
            is_dismissed TINYINT(1) NOT NULL DEFAULT 0,
            detected_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            dismissed_at DATETIME DEFAULT NULL,
            INDEX idx_user (user_id),
            INDEX idx_severity (severity),
            INDEX idx_dismissed (is_dismissed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// ── Rule-based anomaly detection (fast, no AI needed) ─────────
function detectRuleBasedAnomalies(int $userId): array {
    $anomalies = [];
    $now = time();

    try {
        // 1. FD maturing in 30 days
        $fds = DB::fetchAll(
            "SELECT id, bank_name, principal_amount, maturity_date
             FROM fd_investments
             WHERE user_id = ? AND status = 'active'
               AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
            [$userId]
        );
        foreach ($fds as $fd) {
            $days = (int) ceil((strtotime($fd['maturity_date']) - $now) / 86400);
            $anomalies[] = [
                'type'     => 'fd_maturity_soon',
                'severity' => $days <= 7 ? 'critical' : 'warning',
                'title'    => "FD Matures in {$days} Days",
                'desc'     => "{$fd['bank_name']} FD of ₹" . number_format($fd['principal_amount']) . " matures on {$fd['maturity_date']}. Renewal ya reinvestment decide karo.",
                'data'     => ['fd_id' => $fd['id'], 'days_left' => $days],
            ];
        }

        // 2. SIP failed (no transaction in last 35 days for monthly SIP)
        $sips = DB::fetchAll(
            "SELECT s.id, f.fund_name, s.amount,
                    MAX(tr.transaction_date) AS last_sip_date
             FROM sip_swp s
             JOIN mf_holdings mh ON mh.id = s.holding_id
             JOIN funds f ON f.id = mh.fund_id
             LEFT JOIN mf_transactions tr ON tr.holding_id = s.holding_id
               AND tr.transaction_type = 'sip_buy'
             WHERE s.user_id = ? AND s.type = 'SIP' AND s.status = 'active'
               AND s.frequency = 'monthly'
             GROUP BY s.id, f.fund_name, s.amount
             HAVING last_sip_date IS NULL OR last_sip_date < DATE_SUB(CURDATE(), INTERVAL 35 DAY)",
            [$userId]
        );
        foreach ($sips as $sip) {
            $anomalies[] = [
                'type'     => 'sip_missed',
                'severity' => 'warning',
                'title'    => "SIP Missed: {$sip['fund_name']}",
                'desc'     => "₹{$sip['amount']}/month SIP ka koi transaction last 35 days mein nahi aaya. Bank balance ya mandate check karo.",
                'data'     => ['sip_id' => $sip['id']],
            ];
        }

        // 3. Single fund > 30% of portfolio
        $total = (float)(DB::fetchVal(
            "SELECT SUM(mh.current_value) FROM mf_holdings mh
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ? AND mh.is_active = 1",
            [$userId]
        ) ?? 0);

        if ($total > 0) {
            $concentrated = DB::fetchAll(
                "SELECT f.fund_name, mh.current_value,
                        ROUND(mh.current_value / ? * 100, 1) AS pct
                 FROM mf_holdings mh
                 JOIN funds f ON f.id = mh.fund_id
                 JOIN portfolios p ON p.id = mh.portfolio_id
                 WHERE p.user_id = ? AND mh.is_active = 1 AND mh.current_value / ? > 0.30",
                [$total, $userId, $total]
            );
            foreach ($concentrated as $f) {
                $anomalies[] = [
                    'type'     => 'concentration_risk',
                    'severity' => 'warning',
                    'title'    => "Concentration Risk: {$f['fund_name']}",
                    'desc'     => "{$f['pct']}% portfolio sirf ek fund mein hai. 30% se zyada concentration risky ho sakta hai.",
                    'data'     => ['fund_name' => $f['fund_name'], 'pct' => $f['pct']],
                ];
            }
        }

        // 4. LTCG exemption underutilized (₹1L available, > ₹50K unrealized equity LTCG)
        $ltcgGains = (float)(DB::fetchVal(
            "SELECT SUM(mh.current_value - mh.invested_amount)
             FROM mf_holdings mh
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ? AND mh.is_active = 1
               AND f.asset_class = 'equity'
               AND DATEDIFF(NOW(), mh.first_investment_date) > 365
               AND mh.current_value > mh.invested_amount",
            [$userId]
        ) ?? 0);

        if ($ltcgGains > 50000 && (int)date('n') >= 9) { // Sept onwards — still time
            $anomalies[] = [
                'type'     => 'ltcg_opportunity',
                'severity' => 'info',
                'title'    => '₹1L LTCG Tax Exemption Opportunity',
                'desc'     => "Tumhare paas ₹" . number_format($ltcgGains) . " ka unrealized equity LTCG hai. ₹1L tak redeem karo aur reinvest karo — zero tax mein gains book ho jayenge.",
                'data'     => ['unrealized_ltcg' => $ltcgGains],
            ];
        }

        // 5. NAV data stale (> 3 days)
        $stale = DB::fetchVal(
            "SELECT COUNT(*) FROM mf_holdings mh
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ? AND mh.is_active = 1
               AND (mh.nav_updated_at IS NULL OR mh.nav_updated_at < DATE_SUB(NOW(), INTERVAL 3 DAY))",
            [$userId]
        );
        if ((int)$stale > 0) {
            $anomalies[] = [
                'type'     => 'nav_stale',
                'severity' => 'info',
                'title'    => "{$stale} Funds ka NAV Outdated Hai",
                'desc'     => "Kuch funds ka NAV 3+ din se update nahi hua. Portfolio value accurate nahi ho sakti.",
                'data'     => ['stale_count' => $stale],
            ];
        }

    } catch (Exception $e) {
        // Non-critical — return what we have
    }

    return $anomalies;
}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── DETECT ANOMALIES ───────────────────────────────────────
    case 'ai_anomaly_detect':
        $ruledBased = detectRuleBasedAnomalies($userId);

        // Save new anomalies (skip duplicates from today)
        $saved = 0;
        foreach ($ruledBased as $a) {
            $existing = DB::fetchVal(
                "SELECT id FROM ai_anomalies
                 WHERE user_id = ? AND anomaly_type = ? AND is_dismissed = 0
                   AND DATE(detected_at) = CURDATE()",
                [$userId, $a['type']]
            );
            if (!$existing) {
                DB::run(
                    "INSERT INTO ai_anomalies (user_id, anomaly_type, severity, title, description, data_json)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$userId, $a['type'], $a['severity'], $a['title'], $a['desc'], json_encode($a['data'])]
                );
                $saved++;
            }
        }

        // Return active undismissed anomalies
        $active = DB::fetchAll(
            "SELECT id, anomaly_type, severity, title, description, detected_at
             FROM ai_anomalies
             WHERE user_id = ? AND is_dismissed = 0
             ORDER BY FIELD(severity,'critical','warning','info'), detected_at DESC",
            [$userId]
        );

        json_response(true, '', [
            'anomalies'   => $active,
            'total'       => count($active),
            'new_found'   => $saved,
            'critical'    => count(array_filter($active, fn($a) => $a['severity'] === 'critical')),
            'warnings'    => count(array_filter($active, fn($a) => $a['severity'] === 'warning')),
        ]);

    // ── LIST ANOMALIES ─────────────────────────────────────────
    case 'ai_anomaly_list':
        $showDismissed = (bool) ($_GET['dismissed'] ?? false);
        $where = $showDismissed ? '' : 'AND is_dismissed = 0';
        $anomalies = DB::fetchAll(
            "SELECT id, anomaly_type, severity, title, description, is_dismissed, detected_at
             FROM ai_anomalies WHERE user_id = ? {$where}
             ORDER BY detected_at DESC LIMIT 50",
            [$userId]
        );
        json_response(true, '', ['anomalies' => $anomalies]);

    // ── DISMISS ────────────────────────────────────────────────
    case 'ai_anomaly_dismiss':
        csrf_verify();
        $anomalyId = (int) ($_POST['anomaly_id'] ?? 0);
        if (!$anomalyId) json_response(false, 'Invalid anomaly ID.');

        $affected = DB::run(
            "UPDATE ai_anomalies SET is_dismissed = 1, dismissed_at = NOW()
             WHERE id = ? AND user_id = ?",
            [$anomalyId, $userId]
        )->rowCount();

        json_response($affected > 0, $affected > 0 ? 'Dismissed.' : 'Not found.');

    default:
        json_response(false, 'Unknown anomaly action.', [], 400);
}
