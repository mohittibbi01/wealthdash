<?php
/**
 * WealthDash — REST-style Dispatcher for ID-W3 Orphan Tasks
 * File: api/rest_dispatch.php
 *
 * BACKGROUND: 10 tasks (t38, t39, t114, t116, t118, t121, t145, t432, t435,
 * t436) were built by worker ID-W3 as PHP classes with REST-style methods
 * (getHoldings, addHolding, etc.) instead of the action=xxx switch-case
 * convention every other route in this app uses. This file bridges them
 * into the existing api/router.php WITHOUT rewriting the 10 classes.
 *
 * SECURITY FIX APPLIED: the original classes read user_id from
 * $_GET['user_id'] / $_POST['user_id'], which would let any logged-in
 * user pass someone else's user_id and see their data. This dispatcher
 * OVERWRITES that value with the real $_SESSION['user_id'] before the
 * class ever runs, so the unsafe param is neutralised — but the
 * underlying classes were NOT rewritten. Treat this as a stop-gap, not
 * a long-term fix. Recommended real fix: edit each class's methods to
 * use require_auth()/$_SESSION directly like the rest of the app does,
 * then remove this dispatcher and merge them the normal way.
 *
 * HOW THIS IS WIRED IN:
 * api/router.php's switch($action) has one case per "action" name below
 * (e.g. 'gold_action') that includes this file. This file then reads a
 * second parameter, 'method', to know which class method to call.
 *
 * Frontend call pattern (instead of REST verbs):
 *   apiPost({action:'gold_action', method:'getHoldings', ...other params})
 *   apiPost({action:'gold_action', method:'addHolding', ...})
 *
 * This keeps the app consistent with its existing action=xxx pattern
 * instead of introducing real REST routing (GET/PUT/DELETE on paths),
 * which this router was never built to parse.
 */

defined('WEALTHDASH') or die('Direct access not allowed.');

// $userId is already set by router.php from $_SESSION before this runs.
// Force-overwrite any user_id sent in the request so the orphan classes
// (which trust $_GET/$_POST user_id) can't be spoofed.
$_GET['user_id']  = $userId;
$_POST['user_id'] = $userId;

$method = clean($_POST['method'] ?? $_GET['method'] ?? '');
$db     = DB::conn();

if ($method === '') {
    json_response(false, 'Missing required "method" parameter.', [], 400);
}

// Map action -> [class file, class name, allowed methods]
$dispatchMap = [
    'gold_action'        => ['/api/gold/gold.php',                  'GoldTracker'],
    'bonds_action'        => ['/api/bonds/bonds.php',                 'BondsTracker'],
    'rbi_sec_action'      => ['/api/rbi/rbi_securities.php',          'RBISecurities'],
    'watchlist_action'    => ['/api/watchlist/watchlist.php',         'WatchlistManager'],
    'stocks_tax_action'   => ['/api/stocks/tax_report.php',           'StocksTaxReport'],
    'portfolio_pe_action' => ['/api/stocks/portfolio_pe.php',         'PortfolioPE'],
    'stock_sip_action'    => ['/api/stocks/stock_sip.php',            'StockSIP'],
    'reality_chk_action'  => ['/api/stocks/reality_check.php',        'StockPickerRealityCheck'],
    'screener_action'     => ['/api/screener/screener.php',           'StocksScreener'],
    'intl_stocks_action'  => ['/api/international/international.php', 'InternationalStocks'],
];

if (!isset($dispatchMap[$action])) {
    json_response(false, "Unknown REST-dispatch action: {$action}", [], 400);
}

[$file, $className] = $dispatchMap[$action];
require_once APP_ROOT . $file;

if (!class_exists($className)) {
    json_response(false, "Class {$className} not found in {$file}.", [], 500);
}

$instance = new $className($db);

if (!method_exists($instance, $method)) {
    json_response(false, "Method {$method} not found on {$className}.", [], 400);
}

// Some methods take an :id or similar int param (e.g. updateHolding(int $id))
// — pull it from the request if the method signature expects an argument.
$ref = new ReflectionMethod($instance, $method);
$params = $ref->getParameters();

if (count($params) === 0) {
    $instance->$method();
} else {
    // Single positional int param convention used by all 10 classes (id-style)
    $argName = $params[0]->getName();
    $argVal  = (int)($_POST[$argName] ?? $_GET[$argName] ?? $_POST['id'] ?? $_GET['id'] ?? 0);
    $instance->$method($argVal);
}
exit;
