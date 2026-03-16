<?php
/**
 * PATCHER - Run once: localhost/wealthdash/patch_mf_list.php
 * Directly patches mf_list.php on the server
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
if ($currentUser['role'] !== 'admin') die('Admin only');

header('Content-Type: text/plain');

$file = APP_ROOT . '/api/mutual_funds/mf_list.php';
$content = file_get_contents($file);

// Backup
file_put_contents($file . '.bak_' . date('YmdHis'), $content);
echo "Backup created\n";

// Check if already patched
if (substr_count($content, 'active_sip_count') >= 3) {
    echo "File already has active_sip_count in multiple places\n";
    echo "Occurrences: " . substr_count($content, 'active_sip_count') . "\n";
    
    // Check if return array has it
    if (strpos($content, "'active_sip_count'") !== false) {
        echo "Return array: YES\n";
        echo "\nFILE IS CORRECT. Issue must be browser cache.\n";
        echo "Open Chrome DevTools > Network > find mf_list.php request > check Response for active_sip_count\n";
    }
    die();
}

// Patch 1: Add subqueries to combined view SELECT
$old1 = "                GROUP_CONCAT(DISTINCT h.folio_number ORDER BY h.folio_number SEPARATOR ', ') AS folios,
                COUNT(DISTINCT h.folio_number) AS folio_count
            FROM mf_holdings h";

$new1 = "                GROUP_CONCAT(DISTINCT h.folio_number ORDER BY h.folio_number SEPARATOR ', ') AS folios,
                COUNT(DISTINCT h.folio_number) AS folio_count,
                (SELECT COUNT(*) FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.asset_type = 'mf'
                   AND s.schedule_type = 'SIP') AS active_sip_count,
                (SELECT COUNT(*) FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.asset_type = 'mf'
                   AND s.schedule_type = 'SWP') AS active_swp_count,
                (SELECT s.sip_amount FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.schedule_type = 'SIP'
                 ORDER BY s.created_at DESC LIMIT 1) AS active_sip_amount,
                (SELECT s.frequency FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.schedule_type = 'SIP'
                 ORDER BY s.created_at DESC LIMIT 1) AS active_sip_frequency,
                (SELECT s.sip_amount FROM sip_schedules s
                 WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                   AND s.is_active = 1 AND s.schedule_type = 'SWP'
                 ORDER BY s.created_at DESC LIMIT 1) AS active_swp_amount
            FROM mf_holdings h";

// Patch 2: Add fields to return array
$old2 = "                'drawdown_pct'     => (\$r['highest_nav'] && (float)\$r['highest_nav'] > 0 && \$r['latest_nav'])
                                        ? round(((float)\$r['highest_nav'] - (float)\$r['latest_nav']) / (float)\$r['highest_nav'] * 100, 2)
                                        : null,
            ];
        }, \$rows);

        // Summary totals";

$new2 = "                'drawdown_pct'     => (\$r['highest_nav'] && (float)\$r['highest_nav'] > 0 && \$r['latest_nav'])
                                        ? round(((float)\$r['highest_nav'] - (float)\$r['latest_nav']) / (float)\$r['highest_nav'] * 100, 2)
                                        : null,
                'active_sip_count'     => (int)(\$r['active_sip_count'] ?? 0),
                'active_swp_count'     => (int)(\$r['active_swp_count'] ?? 0),
                'active_sip_amount'    => isset(\$r['active_sip_amount']) && \$r['active_sip_amount'] !== null ? (float)\$r['active_sip_amount'] : null,
                'active_sip_frequency' => \$r['active_sip_frequency'] ?? null,
                'active_swp_amount'    => isset(\$r['active_swp_amount']) && \$r['active_swp_amount'] !== null ? (float)\$r['active_swp_amount'] : null,
            ];
        }, \$rows);

        // Summary totals";

$patched = 0;

if (strpos($content, $old1) !== false) {
    $content = str_replace($old1, $new1, $content);
    $patched++;
    echo "Patch 1 (SQL subqueries): APPLIED\n";
} else {
    echo "Patch 1: old text not found - may already be applied\n";
}

if (strpos($content, $old2) !== false) {
    $content = str_replace($old2, $new2, $content);
    $patched++;
    echo "Patch 2 (return array): APPLIED\n";
} else {
    echo "Patch 2: old text not found - may already be applied\n";
}

if ($patched > 0) {
    file_put_contents($file, $content);
    echo "\nSaved! $patched patch(es) applied.\n";
    echo "active_sip_count occurrences now: " . substr_count($content, 'active_sip_count') . "\n";
} else {
    echo "\nNo patches needed or patterns not found.\n";
}

echo "\nVerification:\n";
$verify = file_get_contents($file);
echo "active_sip_count in file: " . substr_count($verify, 'active_sip_count') . " times\n";
echo "active_sip_amount in return: " . (strpos($verify, "'active_sip_amount'") !== false ? "YES" : "NO") . "\n";
echo "\nDone. Now hard refresh mf_holdings.php with Ctrl+Shift+R\n";
