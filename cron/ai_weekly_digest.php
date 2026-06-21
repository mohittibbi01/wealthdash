<?php
/**
 * WealthDash — t329: AI Weekly Digest (Cron)
 * TODO: implement
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/cron_logger.php';
$_cronLog = new CronLogger('ai_weekly_digest');
$_cronLog->start();

if (php_sapi_name() !== 'cli' && !defined('WD_CRON_FORCE')) { die('CLI only'); }
$db = DB::conn();
// TODO: implement
echo date('[Y-m-d H:i:s]') . " t329 cron stub\n";
\$_cronLog->finish('success', 'AI weekly digest run');
