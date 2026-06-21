<?php
/**
 * WealthDash — Get Historical NAV for specific date
 * GET /api/nav/get_historical_nav.php?fund_id=&date=YYYY-MM-DD
 * Delegates to mf_nav_history.php
 */
define('WEALTHDASH', true);
require_once dirname(dirname(__FILE__)) . '/mutual_funds/mf_nav_history.php';

