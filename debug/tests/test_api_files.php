<?php
/**
 * WealthDash Debug — test_api_files.php
 * Tests: Required files exist, PHP syntax clean
 */
defined('WD_DEBUG_RUNNER') or die('Direct access not allowed');

$BASE = dirname(__DIR__, 2); // debug/tests/ → debug/ → wealthdash/

// ── 1. Critical files must exist ──────────────────────────────────────────
$required_files = [
    // Config
    'config/database.php',
    'config/config.php',
    'includes/helpers.php',
    'includes/auth_check.php',
    'includes/holding_calculator.php',
    'includes/tax_engine.php',
    // Router
    'api/router.php',
    // MF APIs
    'api/mutual_funds/mf_list.php',
    'api/mutual_funds/mf_add.php',
    'api/mutual_funds/mf_edit.php',
    'api/mutual_funds/mf_delete.php',
    'api/mutual_funds/mf_search.php',
    'api/mutual_funds/mf_import_csv.php',
    'api/mutual_funds/fund_screener.php',
    'api/mutual_funds/mf_nav_history.php',
    // Reports
    'api/reports/fy_gains.php',
    'api/reports/net_worth.php',
    'api/reports/tax_planning.php',
    // NPS
    'api/nps/nps_list.php',
    'api/nps/nps_add.php',
    // Savings
    'api/savings/savings_list.php',
    'api/savings/savings_add.php',
    // Crons
    'cron/update_nav_daily.php',
    'cron/update_stocks_daily.php',
    'cron/nps_nav_scraper.php',
    'cron/fd_maturity_alert.php',
    // Templates
    'templates/layout.php',
    'templates/sidebar.php',
    'templates/topbar.php',
    'templates/pages/mf_holdings.php',
    'templates/pages/dashboard.php',
    'templates/pages/admin.php',
    // JS/CSS
    'public/js/app.js',
    'public/js/mf.js',
    'public/css/app.css',
    // Env
    '.env',
    '.htaccess',
];

foreach ($required_files as $rel) {
    $full = $BASE . '/' . $rel;
    if (file_exists($full))
        wd_pass('File', $rel, 'Found');
    else
        wd_fail('File', $rel, 'MISSING — check deployment');
}

// ── 2. PHP syntax check — uses PHP built-in token_get_all() ───────────────
// NEVER use exec('php -l ...') on Windows XAMPP — it spawns new Apache
// child processes causing "AH02965" crashes. token_get_all() runs in-process.
$phpDirs = ['api', 'includes', 'config', 'cron'];
$syntaxErrors = 0;
foreach ($phpDirs as $dir) {
    $path = $BASE . '/' . $dir;
    if (!is_dir($path)) continue;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $path, RecursiveDirectoryIterator::SKIP_DOTS
    ));
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') continue;
        $rel = ltrim(str_replace([$BASE, '\\', '/'], ['', '/', '/'], $file->getPathname()), '/');
        $src = @file_get_contents($file->getPathname());
        if ($src === false) { wd_warn('PHP Syntax', $rel, 'Could not read file'); continue; }
        // Suppress errors, catch them via set_error_handler
        $parseError = null;
        set_error_handler(function($no, $msg) use (&$parseError) { $parseError = $msg; return true; });
        try {
            token_get_all($src, TOKEN_PARSE);
        } catch (\ParseError $e) {
            $parseError = $e->getMessage() . ' on line ' . $e->getLine();
        }
        restore_error_handler();
        if ($parseError) {
            wd_fail('PHP Syntax', $rel, $parseError);
            $syntaxErrors++;
        } else {
            wd_pass('PHP Syntax', $rel, 'OK');
        }
    }
}
if ($syntaxErrors === 0 && isset($rel)) {
    // Already reported per-file above — summary note only
}

// ── 3. .env has required keys ─────────────────────────────────────────────
$envFile = $BASE . '/.env';
if (file_exists($envFile)) {
    $env = file_get_contents($envFile);
    $requiredKeys = ['DB_HOST', 'DB_NAME', 'DB_USER'];
    foreach ($requiredKeys as $key) {
        if (preg_match('/^' . $key . '\s*=/m', $env))
            wd_pass('Config', ".env: $key", 'Set');
        else
            wd_warn('Config', ".env: $key", 'Not found in .env');
    }
    // ANTHROPIC key — warn only (optional)
    if (preg_match('/^ANTHROPIC_API_KEY\s*=\s*.+/m', $env))
        wd_pass('Config', '.env: ANTHROPIC_API_KEY', 'Set (AI features enabled)');
    else
        wd_warn('Config', '.env: ANTHROPIC_API_KEY', 'Not set — AI features disabled');
} else {
    wd_fail('Config', '.env file', 'Not found — copy .env.example');
}
