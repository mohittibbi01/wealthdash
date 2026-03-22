<?php
/**
 * WealthDash Debug — test_ui_elements.php
 * Tests: Critical HTML element IDs exist in templates,
 *        JS functions exist in public/js files.
 * This is a static source analysis — no browser needed.
 */
defined('WD_DEBUG_RUNNER') or die('Direct access not allowed');

$BASE = dirname(__DIR__, 2); // debug/tests/ → debug/ → wealthdash/

// ── Helper: grep for string in file ───────────────────────────────────────
function wd_grep(string $needle, string $file): bool {
    if (!file_exists($file)) return false;
    return str_contains(file_get_contents($file), $needle);
}

// ── 1. MF Holdings page — critical element IDs ────────────────────────────
$holdingsFile = $BASE . '/templates/pages/mf_holdings.php';
$holdingsIds  = [
    // Core table
    'holdingsBody'            => 'Holdings table body',
    'holdingsTable'           => 'Holdings <table>',
    'holdingsPaginationWrap'  => 'Pagination wrapper',
    'holdingsPagination'      => 'Pagination buttons',
    'holdingsPaginationInfo'  => 'Pagination info text',
    'holdingsCount'           => 'Fund count label',
    // Controls
    'selectAllFunds'          => 'Select-all checkbox',
    'searchFund'              => 'Search input',
    'filterCategory'          => 'Category filter',
    'filterGainType'          => 'Gain type filter',
    'btnSortMenu'             => 'Sort button',
    'sortMenuDropdown'        => 'Sort dropdown menu',
    // Action buttons
    'btnAddTransaction'       => 'Add transaction button',
    'btnImportCsv'            => 'Import CSV button',
    // Stat tiles
    'stat1dAmt'               => '1D change amount tile',
    'stat1dPct'               => '1D change % tile',
    // Modals
    'modalDeleteFund'         => 'Delete fund modal',
    'txnDrawer'               => 'Transaction drawer',
];
foreach ($holdingsIds as $id => $label) {
    $found = wd_grep("id=\"$id\"", $holdingsFile) || wd_grep("id='$id'", $holdingsFile);
    if ($found)
        wd_pass('UI Element', "mf_holdings: #$id", $label);
    else
        wd_fail('UI Element', "mf_holdings: #$id", "MISSING — \"$label\" not found in template");
}

// ── 2. MF Holdings — delete/close/sort button existence ───────────────────
// These functions are called dynamically from mf.js (renderTask builds onclick HTML)
// so we check BOTH the PHP template AND the JS file
$mfPhp = $BASE . '/templates/pages/mf_holdings.php';
$mfJsFile = $BASE . '/public/js/mf.js';
$uiActions = [
    'openDeleteFundModal' => 'Delete button onclick',
    'closeDeleteFundModal'=> 'Delete modal close button',
    'toggleSortMenu'      => 'Sort menu toggle',
    'openTxnDrawer'       => 'Transaction drawer open',
    'closeTxnDrawer'      => 'Transaction drawer close',
];
$phpContent = file_exists($mfPhp) ? file_get_contents($mfPhp) : '';
$jsContent  = file_exists($mfJsFile) ? file_get_contents($mfJsFile) : '';
foreach ($uiActions as $fn => $label) {
    $inPhp = str_contains($phpContent, $fn);
    $inJs  = str_contains($jsContent, $fn);
    if ($inPhp || $inJs) {
        $where = $inPhp ? 'mf_holdings.php' : 'mf.js (dynamic)';
        wd_pass('UI Action', $fn, "$label — found in $where");
    } else {
        wd_fail('UI Action', $fn, "$label — NOT found in template or mf.js");
    }
}

// ── 3. JS functions in mf.js ──────────────────────────────────────────────
$mfJs = $BASE . '/public/js/mf.js';
$mfFunctions = [
    'renderHoldings'         => 'Main holdings renderer',
    'render1DCell'           => '1D change inline cell (t01 fix)',
    'inject1DayStatCard'     => '1D stat tile updater',
    'load1DayChange'         => '1D change API fetch',
    'applyHoldingsFilter'    => 'Filter handler',
    'applySortMenu'          => 'Sort apply',
    'toggleSortMenu'         => 'Sort menu open/close',
    '_positionSortMenu'      => 'Sort menu positioning',
    'goHoldingsPage'         => 'Pagination page change',
    'changeHoldingsPerPage'  => 'Per-page selector',
    'openDeleteFundModal'    => 'Delete modal open',
    'closeDeleteFundModal'   => 'Delete modal close',
    'openTxnDrawer'          => 'Transaction drawer open',
    'closeTxnDrawer'         => 'Transaction drawer close',
    'loadHoldings'           => 'Holdings API load',
    'saveTransaction'        => 'Save transaction',
    'openAddTxnModal'        => 'Add transaction modal',
    'fmtFull'                => 'Full Indian number format',
    'fmtInr'                 => 'Short/toggle format',
];
foreach ($mfFunctions as $fn => $label) {
    if (wd_grep("function $fn", $mfJs))
        wd_pass('JS Function', "mf.js: $fn()", $label);
    else
        wd_fail('JS Function', "mf.js: $fn()", "MISSING — $label");
}

// ── 4. JS functions in app.js ─────────────────────────────────────────────
$appJs = $BASE . '/public/js/app.js';
$appFunctions = [
    'toggleNumFormat'  => 'Number format toggle',
    'fmtINR'           => 'App-level INR formatter',
    'indianComma'      => 'Indian comma formatter',
];
foreach ($appFunctions as $fn => $label) {
    if (wd_grep("function $fn", $appJs))
        wd_pass('JS Function', "app.js: $fn()", $label);
    else
        wd_fail('JS Function', "app.js: $fn()", "MISSING — $label");
}

// ── 5. CSS — sticky thead rule exists ─────────────────────────────────────
$css = $BASE . '/public/css/app.css';
if (wd_grep('position: sticky', $css) && wd_grep('topbar-height', $css))
    wd_pass('CSS', 'Sticky thead', 'Sticky header rule with topbar-height offset found');
else
    wd_warn('CSS', 'Sticky thead', 'Sticky header CSS missing — table header will not stick');

if (wd_grep('is-scrolled', $css))
    wd_pass('CSS', '.is-scrolled shadow', 'Scroll shadow class found');
else
    wd_warn('CSS', '.is-scrolled shadow', '.is-scrolled not defined in CSS');
