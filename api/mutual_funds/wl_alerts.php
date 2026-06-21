<?php
/**
 * WealthDash — tv10 + t405: MF Watchlist Alerts API (v2)
 *
 * tv10 (original):
 *   GET  ?action=wl_alerts_list              → all alerts for user
 *   POST action=wl_alert_save               → add/update single-condition alert
 *   POST action=wl_alert_delete             → delete alert
 *   GET  ?action=wl_alerts_check            → check which alerts triggered today
 *
 * t405 (new multi-condition + extended types):
 *   POST action=wl_alert_save_multi         → save multi-condition alert (AND logic)
 *   GET  ?action=wl_alerts_history          → trigger history (last 30 days)
 *   GET  ?action=wl_alerts_simulate         → simulate: if invested last year, returns?
 *   POST action=wl_alert_snooze             → snooze triggered alert for N days
 *
 * Extended alert types (t405):
 *   nav_above, nav_below       (existing)
 *   return_1y_above            (existing)
 *   drawdown_below             (existing)
 *   sharpe_above               NEW: Sharpe ratio > threshold
 *   aum_above                  NEW: AUM (crore) > threshold
 *   expense_below              NEW: Expense ratio < threshold
 *   return_3y_above            NEW: 3Y return > threshold
 *   multi_condition            NEW: JSON array of conditions (AND logic)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// ── Auto-create / migrate tables ─────────────────────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS `mf_watchlist_alerts` (
        `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`         INT UNSIGNED NOT NULL,
        `fund_id`         INT UNSIGNED NOT NULL,
        `alert_type`      VARCHAR(30) NOT NULL DEFAULT 'nav_above',
        `threshold`       DECIMAL(12,4) NOT NULL DEFAULT 0,
        `conditions`      JSON DEFAULT NULL COMMENT 'Multi-condition array [{type,threshold,operator}]',
        `label`           VARCHAR(120) DEFAULT NULL COMMENT 'User-friendly label for the alert',
        `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
        `snoozed_until`   DATE DEFAULT NULL,
        `triggered_at`    DATETIME DEFAULT NULL,
        `trigger_count`   INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_fund` (`user_id`, `fund_id`),
        KEY `idx_active`    (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Alert trigger history (t405)
$db->exec("
    CREATE TABLE IF NOT EXISTS `mf_alert_history` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `alert_id`    INT UNSIGNED NOT NULL,
        `user_id`     INT UNSIGNED NOT NULL,
        `fund_id`     INT UNSIGNED NOT NULL,
        `alert_type`  VARCHAR(30) NOT NULL,
        `threshold`   DECIMAL(12,4) DEFAULT NULL,
        `actual_value`DECIMAL(14,4) DEFAULT NULL,
        `triggered_at`DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_hist`  (`user_id`, `triggered_at`),
        KEY `idx_alert_hist` (`alert_id`),
        CONSTRAINT `fk_ah_alert` FOREIGN KEY (`alert_id`) REFERENCES `mf_watchlist_alerts`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Migrate: add new columns if upgrading from v1
try { $db->exec("ALTER TABLE `mf_watchlist_alerts` ADD COLUMN IF NOT EXISTS `conditions`    JSON DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `mf_watchlist_alerts` ADD COLUMN IF NOT EXISTS `label`         VARCHAR(120) DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `mf_watchlist_alerts` ADD COLUMN IF NOT EXISTS `snoozed_until` DATE DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `mf_watchlist_alerts` ADD COLUMN IF NOT EXISTS `trigger_count` INT UNSIGNED NOT NULL DEFAULT 0"); } catch(Exception $e) {}

// ── Valid alert types ─────────────────────────────────────────────────────
const ALERT_TYPES = [
    'nav_above', 'nav_below',
    'return_1y_above', 'return_3y_above',
    'drawdown_below',
    'sharpe_above',
    'aum_above',
    'expense_below',
    'multi_condition',
];

/**
 * Evaluate a single condition against fund metrics.
 * Returns [bool $hit, float|null $actual]
 */
function eval_condition(string $type, float $threshold, array $fund): array {
    $nav     = isset($fund['latest_nav'])   ? (float)$fund['latest_nav']   : 0;
    $r1y     = isset($fund['returns_1y'])   && $fund['returns_1y']   !== null ? (float)$fund['returns_1y']   : null;
    $r3y     = isset($fund['returns_3y'])   && $fund['returns_3y']   !== null ? (float)$fund['returns_3y']   : null;
    $dd      = isset($fund['drawdown_pct']) && $fund['drawdown_pct'] !== null ? (float)$fund['drawdown_pct'] : null;
    $sharpe  = isset($fund['sharpe_ratio']) && $fund['sharpe_ratio'] !== null ? (float)$fund['sharpe_ratio'] : null;
    $aum     = isset($fund['aum_crore'])    && $fund['aum_crore']    !== null ? (float)$fund['aum_crore']    : null;
    $exp     = isset($fund['expense_ratio'])&& $fund['expense_ratio']!== null ? (float)$fund['expense_ratio']: null;

    return match($type) {
        'nav_above'       => [$nav > 0 && $nav >= $threshold,    $nav],
        'nav_below'       => [$nav > 0 && $nav <= $threshold,    $nav],
        'return_1y_above' => [$r1y !== null && $r1y >= $threshold, $r1y],
        'return_3y_above' => [$r3y !== null && $r3y >= $threshold, $r3y],
        'drawdown_below'  => [$dd  !== null && $dd  <= $threshold, $dd],
        'sharpe_above'    => [$sharpe !== null && $sharpe >= $threshold, $sharpe],
        'aum_above'       => [$aum   !== null && $aum   >= $threshold, $aum],
        'expense_below'   => [$exp   !== null && $exp   <= $threshold, $exp],
        default           => [false, null],
    };
}

/**
 * Evaluate multi-condition alert (AND logic).
 * All conditions must be true.
 */
function eval_multi_condition(array $conditions, array $fund): array {
    $allHit  = true;
    $details = [];
    foreach ($conditions as $cond) {
        $type      = $cond['type']      ?? '';
        $threshold = (float)($cond['threshold'] ?? 0);
        [$hit, $actual] = eval_condition($type, $threshold, $fund);
        $details[] = compact('type', 'threshold', 'hit', 'actual');
        if (!$hit) $allHit = false;
    }
    return [$allHit, $details];
}

/**
 * Auto-generate a human-readable label for an alert.
 */
function auto_label(string $type, float $threshold, ?array $conditions = null): string {
    if ($type === 'multi_condition' && $conditions) {
        $parts = [];
        foreach ($conditions as $c) {
            $parts[] = auto_label($c['type'], (float)$c['threshold']);
        }
        return 'All: ' . implode(' AND ', $parts);
    }
    return match($type) {
        'nav_above'       => "NAV ≥ ₹" . number_format($threshold, 2),
        'nav_below'       => "NAV ≤ ₹" . number_format($threshold, 2),
        'return_1y_above' => "1Y Return ≥ {$threshold}%",
        'return_3y_above' => "3Y Return ≥ {$threshold}%",
        'drawdown_below'  => "Drawdown ≤ {$threshold}%",
        'sharpe_above'    => "Sharpe ≥ {$threshold}",
        'aum_above'       => "AUM ≥ ₹{$threshold}Cr",
        'expense_below'   => "Expense ≤ {$threshold}%",
        default           => "{$type} {$threshold}",
    };
}

// ── Fund columns that may not exist yet ──────────────────────────────────
$extraFundCols = '';
foreach (['sharpe_ratio','aum_crore','expense_ratio','returns_3y','drawdown_pct'] as $col) {
    try { $db->query("SELECT {$col} FROM funds LIMIT 1"); $extraFundCols .= ", f.{$col}"; } catch(Exception $e) {}
}

switch ($action) {

    // ── LIST all alerts for current user ─────────────────────────────────
    case 'wl_alerts_list': {
        $stmt = $db->prepare("
            SELECT
                a.id, a.fund_id, a.alert_type, a.threshold,
                a.conditions, a.label, a.snoozed_until,
                a.is_active, a.triggered_at, a.trigger_count, a.created_at,
                f.scheme_name, f.latest_nav, f.returns_1y
                {$extraFundCols},
                COALESCE(fh.short_name, fh.name) AS amc_name
            FROM mf_watchlist_alerts a
            JOIN funds f  ON f.id  = a.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            WHERE a.user_id = ?
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$userId]);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');
        foreach ($alerts as &$al) {
            $al['threshold']    = (float)$al['threshold'];
            $al['latest_nav']   = (float)($al['latest_nav'] ?? 0);
            $al['returns_1y']   = isset($al['returns_1y'])   && $al['returns_1y']   !== null ? (float)$al['returns_1y']   : null;
            $al['returns_3y']   = isset($al['returns_3y'])   && $al['returns_3y']   !== null ? (float)$al['returns_3y']   : null;
            $al['drawdown_pct'] = isset($al['drawdown_pct']) && $al['drawdown_pct'] !== null ? (float)$al['drawdown_pct'] : null;
            $al['sharpe_ratio'] = isset($al['sharpe_ratio']) && $al['sharpe_ratio'] !== null ? (float)$al['sharpe_ratio'] : null;
            $al['aum_crore']    = isset($al['aum_crore'])    && $al['aum_crore']    !== null ? (float)$al['aum_crore']    : null;
            $al['expense_ratio']= isset($al['expense_ratio'])&& $al['expense_ratio']!== null ? (float)$al['expense_ratio']: null;
            $al['trigger_count']= (int)($al['trigger_count'] ?? 0);
            $al['conditions']   = $al['conditions'] ? json_decode($al['conditions'], true) : null;
            $al['snoozed']      = $al['snoozed_until'] && $al['snoozed_until'] >= $today;

            if ($al['alert_type'] === 'multi_condition' && $al['conditions']) {
                [$hit] = eval_multi_condition($al['conditions'], $al);
                $al['currently_triggered'] = $hit && !$al['snoozed'];
            } else {
                [$hit] = eval_condition($al['alert_type'], $al['threshold'], $al);
                $al['currently_triggered'] = $hit && !$al['snoozed'];
            }
            if (!$al['label']) {
                $al['label'] = auto_label($al['alert_type'], $al['threshold'], $al['conditions']);
            }
        }
        unset($al);

        json_response(true, '', ['alerts' => $alerts]);
        break;
    }

    // ── SAVE (add or update) single-condition alert ───────────────────────
    case 'wl_alert_save': {
        $fundId    = (int)($_POST['fund_id']    ?? 0);
        $alertType = clean($_POST['alert_type'] ?? 'nav_above');
        $threshold = (float)($_POST['threshold'] ?? 0);
        $alertId   = (int)($_POST['alert_id']   ?? 0);
        $label     = trim($_POST['label']        ?? '');

        if (!$fundId) json_response(false, 'fund_id required.');
        if (!in_array($alertType, ALERT_TYPES)) json_response(false, 'Invalid alert_type.');
        if ($alertType !== 'multi_condition' && $alertType !== 'drawdown_below' && $threshold <= 0) {
            json_response(false, 'Threshold must be positive.');
        }

        $f = $db->prepare("SELECT id, scheme_name, latest_nav FROM funds WHERE id = ?");
        $f->execute([$fundId]);
        $fund = $f->fetch();
        if (!$fund) json_response(false, 'Fund not found.');

        $autoLabel = $label ?: auto_label($alertType, $threshold);

        if ($alertId > 0) {
            $db->prepare("
                UPDATE mf_watchlist_alerts
                SET alert_type=?, threshold=?, label=?, is_active=1, triggered_at=NULL, updated_at=NOW()
                WHERE id=? AND user_id=?
            ")->execute([$alertType, $threshold, $autoLabel, $alertId, $userId]);
            json_response(true, 'Alert updated.', ['alert_id' => $alertId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO mf_watchlist_alerts (user_id, fund_id, alert_type, threshold, label, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$userId, $fundId, $alertType, $threshold, $autoLabel]);
            json_response(true, 'Alert set.', [
                'alert_id'   => (int)$db->lastInsertId(),
                'fund_name'  => $fund['scheme_name'],
                'latest_nav' => (float)$fund['latest_nav'],
                'label'      => $autoLabel,
            ]);
        }
        break;
    }

    // ── SAVE multi-condition alert (t405) ─────────────────────────────────
    case 'wl_alert_save_multi': {
        $fundId     = (int)($_POST['fund_id'] ?? 0);
        $alertId    = (int)($_POST['alert_id'] ?? 0);
        $label      = trim($_POST['label'] ?? '');
        $conditions = json_decode($_POST['conditions'] ?? '[]', true);

        if (!$fundId) json_response(false, 'fund_id required.');
        if (empty($conditions) || !is_array($conditions)) {
            json_response(false, 'conditions JSON array required.');
        }
        if (count($conditions) < 2) {
            json_response(false, 'Multi-condition alert requires at least 2 conditions. Use wl_alert_save for single.');
        }
        if (count($conditions) > 5) {
            json_response(false, 'Maximum 5 conditions per alert.');
        }

        // Validate each condition
        foreach ($conditions as $i => $cond) {
            $type = $cond['type'] ?? '';
            if (!in_array($type, ALERT_TYPES) || $type === 'multi_condition') {
                json_response(false, "Condition #{$i}: invalid type '{$type}'.");
            }
            if (!isset($cond['threshold']) || !is_numeric($cond['threshold'])) {
                json_response(false, "Condition #{$i}: threshold required.");
            }
        }

        $f = $db->prepare("SELECT id, scheme_name FROM funds WHERE id=?");
        $f->execute([$fundId]);
        $fund = $f->fetch();
        if (!$fund) json_response(false, 'Fund not found.');

        $autoLabel = $label ?: auto_label('multi_condition', 0, $conditions);

        if ($alertId > 0) {
            $db->prepare("
                UPDATE mf_watchlist_alerts
                SET alert_type='multi_condition', threshold=0, conditions=?,
                    label=?, is_active=1, triggered_at=NULL, updated_at=NOW()
                WHERE id=? AND user_id=?
            ")->execute([json_encode($conditions), $autoLabel, $alertId, $userId]);
            json_response(true, 'Multi-condition alert updated.', ['alert_id' => $alertId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO mf_watchlist_alerts (user_id, fund_id, alert_type, threshold, conditions, label, is_active, created_at)
                VALUES (?, ?, 'multi_condition', 0, ?, ?, 1, NOW())
            ");
            $stmt->execute([$userId, $fundId, json_encode($conditions), $autoLabel]);
            json_response(true, 'Multi-condition alert set.', [
                'alert_id'    => (int)$db->lastInsertId(),
                'fund_name'   => $fund['scheme_name'],
                'conditions'  => $conditions,
                'label'       => $autoLabel,
                'tip'         => 'All conditions must be true simultaneously to trigger.',
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
        $today = date('Y-m-d');
        $stmt  = $db->prepare("
            SELECT a.id, a.fund_id, a.alert_type, a.threshold, a.conditions, a.label,
                   a.snoozed_until,
                   f.scheme_name, f.latest_nav, f.returns_1y
                   {$extraFundCols}
            FROM mf_watchlist_alerts a
            JOIN funds f ON f.id = a.fund_id
            WHERE a.user_id = ? AND a.is_active = 1
              AND a.triggered_at IS NULL
              AND (a.snoozed_until IS NULL OR a.snoozed_until < ?)
        ");
        $stmt->execute([$userId, $today]);
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $triggered = [];
        $updateIds = [];

        foreach ($pending as $al) {
            $conditions = $al['conditions'] ? json_decode($al['conditions'], true) : null;

            if ($al['alert_type'] === 'multi_condition' && $conditions) {
                [$hit, $detail] = eval_multi_condition($conditions, $al);
                if ($hit) {
                    $triggered[] = [
                        'alert_id'   => (int)$al['id'],
                        'fund_id'    => (int)$al['fund_id'],
                        'fund_name'  => $al['scheme_name'],
                        'alert_type' => 'multi_condition',
                        'label'      => $al['label'] ?? auto_label('multi_condition', 0, $conditions),
                        'conditions' => $detail,
                    ];
                    $updateIds[] = (int)$al['id'];
                }
            } else {
                $thr = (float)$al['threshold'];
                [$hit, $actual] = eval_condition($al['alert_type'], $thr, $al);
                if ($hit) {
                    $triggered[] = [
                        'alert_id'    => (int)$al['id'],
                        'fund_id'     => (int)$al['fund_id'],
                        'fund_name'   => $al['scheme_name'],
                        'alert_type'  => $al['alert_type'],
                        'label'       => $al['label'] ?? auto_label($al['alert_type'], $thr),
                        'threshold'   => $thr,
                        'current'     => $actual,
                    ];
                    $updateIds[] = (int)$al['id'];

                    // Log to history
                    try {
                        $db->prepare("
                            INSERT INTO mf_alert_history (alert_id, user_id, fund_id, alert_type, threshold, actual_value)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ")->execute([(int)$al['id'], $userId, (int)$al['fund_id'], $al['alert_type'], $thr, $actual]);
                    } catch(Exception $e) {}
                }
            }
        }

        if (!empty($updateIds)) {
            $ph = implode(',', array_fill(0, count($updateIds), '?'));
            $db->prepare("
                UPDATE mf_watchlist_alerts
                SET triggered_at=NOW(), trigger_count=trigger_count+1
                WHERE id IN ({$ph})
            ")->execute($updateIds);
        }

        json_response(true, '', ['triggered' => $triggered, 'count' => count($triggered)]);
        break;
    }

    // ── GET alert trigger history (t405) ──────────────────────────────────
    case 'wl_alerts_history': {
        $days  = min(90, max(7, (int)($_GET['days'] ?? 30)));
        $since = date('Y-m-d', strtotime("-{$days} days"));

        $stmt = $db->prepare("
            SELECT h.id, h.alert_id, h.alert_type, h.threshold, h.actual_value, h.triggered_at,
                   f.scheme_name,
                   COALESCE(fh.short_name, fh.name) AS amc_name,
                   a.label
            FROM mf_alert_history h
            JOIN funds f  ON f.id = h.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            LEFT JOIN mf_watchlist_alerts a ON a.id = h.alert_id
            WHERE h.user_id = ? AND h.triggered_at >= ?
            ORDER BY h.triggered_at DESC
            LIMIT 200
        ");
        $stmt->execute([$userId, $since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_response(true, '', [
            'history' => $rows,
            'count'   => count($rows),
            'days'    => $days,
        ]);
        break;
    }

    // ── POST snooze alert (t405) ──────────────────────────────────────────
    case 'wl_alert_snooze': {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        $days    = min(30, max(1, (int)($_POST['days'] ?? 7)));
        if (!$alertId) json_response(false, 'alert_id required.');

        $until = date('Y-m-d', strtotime("+{$days} days"));
        $db->prepare("
            UPDATE mf_watchlist_alerts
            SET snoozed_until=?, triggered_at=NULL
            WHERE id=? AND user_id=?
        ")->execute([$until, $alertId, $userId]);

        json_response(true, "Alert snoozed for {$days} day(s).", [
            'alert_id'      => $alertId,
            'snoozed_until' => $until,
        ]);
        break;
    }

    // ── GET portfolio simulation — if invested when alert fired (t405) ────
    case 'wl_alerts_simulate': {
        $alertId = (int)($_GET['alert_id'] ?? 0);
        if (!$alertId) json_response(false, 'alert_id required.');

        // Get alert + fund info
        $stmt = $db->prepare("
            SELECT a.*, f.scheme_name, f.latest_nav, f.returns_1y
            FROM mf_watchlist_alerts a
            JOIN funds f ON f.id = a.fund_id
            WHERE a.id=? AND a.user_id=?
        ");
        $stmt->execute([$alertId, $userId]);
        $al = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$al) json_response(false, 'Alert not found.');

        // Get first trigger date from history
        $hist = $db->prepare("SELECT triggered_at, actual_value FROM mf_alert_history WHERE alert_id=? ORDER BY triggered_at ASC LIMIT 1");
        $hist->execute([$alertId]);
        $firstTrigger = $hist->fetch(PDO::FETCH_ASSOC);

        if (!$firstTrigger) {
            json_response(false, 'Alert has not triggered yet — no simulation data available.', [
                'tip' => 'Simulation runs after the alert triggers for the first time.',
            ]);
        }

        $navAtTrigger = (float)($firstTrigger['actual_value'] ?? 0);
        $currentNav   = (float)($al['latest_nav'] ?? 0);
        $hypothetical = 10000; // ₹10,000 investment

        $units = $navAtTrigger > 0 ? $hypothetical / $navAtTrigger : 0;
        $currentValue = $units * $currentNav;
        $gain = $currentValue - $hypothetical;
        $gainPct = $hypothetical > 0 ? round($gain / $hypothetical * 100, 2) : null;

        json_response(true, '', [
            'alert_id'        => $alertId,
            'fund_name'       => $al['scheme_name'],
            'triggered_at'    => $firstTrigger['triggered_at'],
            'nav_at_trigger'  => $navAtTrigger,
            'current_nav'     => $currentNav,
            'hypothetical_investment' => $hypothetical,
            'units'           => round($units, 4),
            'current_value'   => round($currentValue, 2),
            'gain'            => round($gain, 2),
            'gain_pct'        => $gainPct,
            'result_label'    => $gainPct !== null
                ? ($gainPct >= 0 ? "▲ {$gainPct}% gain" : "▼ " . abs($gainPct) . "% loss")
                : 'Insufficient NAV data',
        ]);
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

    default:
        json_response(false, 'Unknown action.');
}
