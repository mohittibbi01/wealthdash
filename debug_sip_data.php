<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);

header('Content-Type: text/plain');

echo "=== SIP Data Check ===\n";
echo "userId=$userId portfolioId=$portfolioId\n\n";

// Check sip_schedules
$sips = DB::fetchAll("SELECT s.id, s.fund_id, s.sip_amount, s.frequency, s.is_active, f.scheme_name
    FROM sip_schedules s JOIN funds f ON f.id=s.fund_id
    WHERE s.portfolio_id=?", [$portfolioId]);
echo "SIPs in DB (" . count($sips) . "):\n";
foreach ($sips as $s) {
    echo "  id={$s['id']} fund_id={$s['fund_id']} amt={$s['sip_amount']} freq={$s['frequency']} active={$s['is_active']} fund={$s['scheme_name']}\n";
}

echo "\n=== mf_list holdings with SIP data ===\n";
$holdings = DB::fetchAll("
    SELECT h.fund_id, f.scheme_name,
        (SELECT sip_amount FROM sip_schedules s WHERE s.fund_id=h.fund_id AND s.portfolio_id=h.portfolio_id AND s.is_active=1 LIMIT 1) AS active_sip_amount,
        (SELECT COUNT(*) FROM sip_schedules s WHERE s.fund_id=h.fund_id AND s.portfolio_id=h.portfolio_id AND s.is_active=1) AS active_sip_count
    FROM mf_holdings h JOIN funds f ON f.id=h.fund_id
    WHERE h.portfolio_id=? AND h.is_active=1
    ORDER BY h.total_invested DESC LIMIT 5", [$portfolioId]);
foreach ($holdings as $h) {
    echo "  {$h['scheme_name']}: sip_count={$h['active_sip_count']} sip_amount={$h['active_sip_amount']}\n";
}
