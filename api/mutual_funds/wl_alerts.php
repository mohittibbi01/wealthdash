<?php
/**
 * WealthDash — tv10: MF Watchlist Alerts API
 *
 * GET  ?action=wl_alerts_list              → all alerts for user
 * POST action=wl_alert_save               → add/update alert
 * POST action=wl_alert_delete             → delete alert
 * GET  ?action=wl_alerts_check            → check which alerts triggered today
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// ── Auto-create table if missing ─────────────────────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS `mf_watchlist_alerts` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`      INT UNSIGNED NOT NULL,
        `fund_id`      INT UNSIGNED NOT NULL,
        `alert_type`   ENUM('nav_above','nav_below','return_1y_above','drawdown_below') NOT NULL DEFAULT 'nav_above',
        `threshold`    DECIMAL(12,4) NOT NULL,
        `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
        `triggered_at` DATETIME DEFAULT NULL,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_fund` (`user_id`, `fund_id`),
        KEY `idx_active`    (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

switch ($action) {

    // ── LIST all alerts for current user ─────────────────────────────────
    case 'wl_alerts_list': {
        $stmt = $db->prepare("
            SELECT
                a.id, a.fund_id, a.alert_type, a.threshold,
                a.is_active, a.triggered_at, a.created_at,
                f.scheme_name, f.latest_nav, f.returns_1y, f.drawdown_pct,
                COALESCE(fh.short_name, fh.name) AS amc_name
            FROM mf_watchlist_alerts a
            JOIN funds f  ON f.id  = a.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            WHERE a.user_id = ?
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$userId]);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check triggered status for each active alert
        $now = date('Y-m-d');
        foreach ($alerts as &$al) {
            $al['threshold']  = (float)$al['threshold'];
            $al['latest_nav'] = (float)($al['latest_nav'] ?? 0);
            $al['returns_1y'] = $al['returns_1y'] !== null ? (float)$al['returns_1y'] : null;
            $al['drawdown_pct'] = $al['drawdown_pct'] !== null ? (float)$al['drawdown_pct'] : null;

            // Live trigger check
            $triggered = false;
            switch ($al['alert_type']) {
                case 'nav_above':
                    $triggered = $al['latest_nav'] > 0 && $al['latest_nav'] >= $al['threshold'];
                    break;
                case 'nav_below':
                    $triggered = $al['latest_nav'] > 0 && $al['latest_nav'] <= $al['threshold'];
                    break;
                case 'return_1y_above':
                    $triggered = $al['returns_1y'] !== null && $al['returns_1y'] >= $al['threshold'];
                    break;
                case 'drawdown_below':
                    $triggered = $al['drawdown_pct'] !== null && $al['drawdown_pct'] <= $al['threshold'];
                    break;
            }
            $al['currently_triggered'] = $triggered;
        }
        unset($al);

        json_response(true, '', ['alerts' => $alerts]);
        break;
    }

    // ── SAVE (add or update) alert ────────────────────────────────────────
    case 'wl_alert_save': {
        $fundId    = (int)($_POST['fund_id']    ?? 0);
        $alertType = clean($_POST['alert_type'] ?? 'nav_above');
        $threshold = (float)($_POST['threshold'] ?? 0);
        $alertId   = (int)($_POST['alert_id']   ?? 0); // 0 = new

        if (!$fundId) json_response(false, 'fund_id required.');

        $validTypes = ['nav_above', 'nav_below', 'return_1y_above', 'drawdown_below'];
        if (!in_array($alertType, $validTypes)) json_response(false, 'Invalid alert_type.');
        if ($threshold <= 0 && $alertType !== 'drawdown_below') json_response(false, 'Threshold must be positive.');

        // Verify fund exists
        $f = $db->prepare("SELECT id, scheme_name, latest_nav FROM funds WHERE id = ?");
        $f->execute([$fundId]);
        $fund = $f->fetch();
        if (!$fund) json_response(false, 'Fund not found.');

        if ($alertId > 0) {
            // Update existing
            $db->prepare("
                UPDATE mf_watchlist_alerts
                SET alert_type = ?, threshold = ?, is_active = 1, triggered_at = NULL, updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ")->execute([$alertType, $threshold, $alertId, $userId]);
            json_response(true, 'Alert updated successfully.', ['alert_id' => $alertId]);
        } else {
            // Insert new
            $stmt = $db->prepare("
                INSERT INTO mf_watchlist_alerts (user_id, fund_id, alert_type, threshold, is_active, created_at)
                VALUES (?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$userId, $fundId, $alertType, $threshold]);
            $newId = (int)$db->lastInsertId();
            json_response(true, 'Alert set successfully.', [
                'alert_id'    => $newId,
                'fund_name'   => $fund['scheme_name'],
                'latest_nav'  => (float)$fund['latest_nav'],
            ]);
        }
        break;
    }

    // ── DELETE alert ──────────────────────────────────────────────────────
    case 'wl_alert_delete': {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        if (!$alertId) json_response(false, 'alert_id required.');
        $db->prepare("DELETE FROM mf_watchlist_alerts WHERE id = ? AND user_id = ?")
           ->execute([$alertId, $userId]);
        json_response(true, 'Alert deleted.');
        break;
    }

    // ── CHECK which alerts triggered (for notification bell) ─────────────
    case 'wl_alerts_check': {
        $stmt = $db->prepare("
            SELECT
                a.id, a.fund_id, a.alert_type, a.threshold,
                f.scheme_name, f.latest_nav, f.returns_1y, f.drawdown_pct
            FROM mf_watchlist_alerts a
            JOIN funds f ON f.id = a.fund_id
            WHERE a.user_id = ? AND a.is_active = 1 AND a.triggered_at IS NULL
        ");
        $stmt->execute([$userId]);
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $triggered = [];
        $updateIds = [];

        foreach ($pending as $al) {
            $nav  = (float)($al['latest_nav'] ?? 0);
            $r1y  = $al['returns_1y']   !== null ? (float)$al['returns_1y']   : null;
            $dd   = $al['drawdown_pct'] !== null ? (float)$al['drawdown_pct'] : null;
            $thr  = (float)$al['threshold'];
            $hit  = false;

            switch ($al['alert_type']) {
                case 'nav_above':        $hit = $nav > 0 && $nav >= $thr; break;
                case 'nav_below':        $hit = $nav > 0 && $nav <= $thr; break;
                case 'return_1y_above':  $hit = $r1y !== null && $r1y >= $thr; break;
                case 'drawdown_below':   $hit = $dd  !== null && $dd  <= $thr; break;
            }

            if ($hit) {
                $triggered[] = [
                    'alert_id'   => (int)$al['id'],
                    'fund_id'    => (int)$al['fund_id'],
                    'fund_name'  => $al['scheme_name'],
                    'alert_type' => $al['alert_type'],
                    'threshold'  => $thr,
                    'current'    => match($al['alert_type']) {
                        'nav_above', 'nav_below' => $nav,
                        'return_1y_above'        => $r1y,
                        'drawdown_below'         => $dd,
                        default                  => null,
                    },
                ];
                $updateIds[] = (int)$al['id'];
            }
        }

        // Mark triggered alerts
        if (!empty($updateIds)) {
            $placeholders = implode(',', array_fill(0, count($updateIds), '?'));
            $db->prepare("
                UPDATE mf_watchlist_alerts SET triggered_at = NOW() WHERE id IN ({$placeholders})
            ")->execute($updateIds);
        }

        json_response(true, '', [
            'triggered' => $triggered,
            'count'     => count($triggered),
        ]);
        break;
    }

    default:
        json_response(false, 'Unknown action.');
}
