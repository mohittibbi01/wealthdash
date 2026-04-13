<?php
/**
 * WealthDash — t417: DB Migration Runner v2
 * database/migrate.php
 *
 * Smart migration runner — tracks which migrations have already run.
 * Usage: php database/migrate.php [--dry-run] [--from=25]
 *
 * TODO: implement migration_log table tracking
 * TODO: implement --dry-run flag
 * TODO: implement rollback support
 * TODO: implement --from=N (run from migration N only)
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

if (php_sapi_name() !== 'cli') { die('CLI only'); }

$db   = DB::conn();
$opts = getopt('', ['dry-run', 'from:']);

// TODO: implement migration runner
echo "WealthDash Migration Runner — t417 — TODO: implement\n";