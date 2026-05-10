<?php
/**
 * WealthDash — t241: Monthly Portfolio Email Summary (Cron)
 * TODO: implement
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/cron_logger.php';
$_cronLog = new CronLogger('monthly_summary');
$_cronLog->start();

if (php_sapi_name() !== 'cli' && !defined('WD_CRON_FORCE')) { die('CLI only'); }
$db = DB::conn();
// TODO: implement
echo date('[Y-m-d H:i:s]') . " t241 cron stub\n";
\$_cronLog->finish('success', 'Monthly summary generated');
