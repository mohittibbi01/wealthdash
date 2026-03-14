<?php
/**
 * WealthDash — Admin: Recalculate All MF Holdings
 * POST /api/?action=admin_recalc_holdings
 * Included by router.php — no declare(strict_types) here
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

set_time_limit(300);

$db  = DB::conn();
$ok  = 0;
$err = 0;
$errMsgs = [];

// Get all unique portfolio+fund combos from transactions
$rows = $db->query("
    SELECT DISTINCT portfolio_id, fund_id
    FROM mf_transactions
    ORDER BY portfolio_id, fund_id
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);

if ($total === 0) {
    json_response(true, 'No transactions found — nothing to recalculate.', [
        'total' => 0, 'ok' => 0, 'errors' => 0
    ]);
}

// Include holding_calculator only if function not yet defined
if (!function_exists('recalculate_mf_holdings')) {
    require_once APP_ROOT . '/includes/holding_calculator.php';
}

foreach ($rows as $r) {
    try {
        recalculate_mf_holdings(
            $db,
            (int) $r['portfolio_id'],
            (int) $r['fund_id'],
            null
        );
        $ok++;
    } catch (Exception $e) {
        $err++;
        $errMsgs[] = "portfolio={$r['portfolio_id']} fund={$r['fund_id']}: " . $e->getMessage();
    }
}

// Save last recalc timestamp
try {
    $chk = $db->prepare("SELECT COUNT(*) FROM app_settings WHERE setting_key = 'last_recalc_holdings'");
    $chk->execute();
    if ((int) $chk->fetchColumn() > 0) {
        $db->prepare("UPDATE app_settings SET setting_val = NOW() WHERE setting_key = 'last_recalc_holdings'")->execute();
    } else {
        $db->prepare("INSERT INTO app_settings (setting_key, setting_val) VALUES ('last_recalc_holdings', NOW())")->execute();
    }
} catch (Exception $e) {
    // non-critical
}

$msg = "Recalculation complete. {$ok} of {$total} combinations updated.";
if ($err > 0) $msg .= " {$err} error(s).";

json_response(true, $msg, [
    'total'    => $total,
    'ok'       => $ok,
    'errors'   => $err,
    'err_msgs' => $errMsgs,
]);