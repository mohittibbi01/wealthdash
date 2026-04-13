<?php
/**
 * WealthDash — t498: Investment Calendar 2025-26
 *
 * Financial year important dates for Indian investors:
 *   - Tax deadlines (ITR, Advance Tax, Form 26AS)
 *   - SEBI compliance dates
 *   - SIP execution dates (user-specific)
 *   - FD maturity alerts
 *   - NFO open/close dates
 *   - Budget / RBI policy dates
 *   - SGB subscription windows
 *   - LTCG 1-year completion alerts (user holdings)
 *
 * GET  ?action=calendar_events           → All FY 2025-26 events (static + user)
 * GET  ?action=upcoming_events           → Next 30 days events
 * GET  ?action=user_events               → User-specific events (SIP dates, FD maturities)
 * POST action=add_custom_event           → User adds custom reminder
 * POST action=delete_custom_event        → Delete custom event
 *
 * Files affected (existing):
 *   templates/pages/mf_holdings.php  — Calendar tab enhancement
 *   public/js/mf.js                  — renderInvestmentCalendar() function
 *   api/router.php                   — route registration
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

$action = $_GET['action'] ?? $_POST['action'] ?? 'calendar_events';

// TODO: implement calendar_events action — static FY 2025-26 dates array
// TODO: implement upcoming_events action — filter next 30 days
// TODO: implement user_events action — SIP dates, FD maturities, LTCG alerts
// TODO: implement add_custom_event POST handler
// TODO: implement delete_custom_event POST handler

echo json_encode(['success' => false, 'message' => 'Not yet implemented — t498']);
