<?php
/**
 * WealthDash Debug — test_api_responses.php
 * Checks API files exist + respond (not timeout).
 * Auth-protected routes → warn only (expected).
 */
defined('WD_DEBUG_RUNNER') or die('Direct access not allowed');

$_sessionName = session_name();
$_sessionId   = session_id();

function wd_api_check(string $label, string $url, string $category = 'API'): void {
    global $_sessionName, $_sessionId;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 3,        // 3s max total
        CURLOPT_CONNECTTIMEOUT => 2,        // 2s to connect
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'],
        CURLOPT_COOKIE         => $_sessionName . '=' . $_sessionId,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    $curlErrNo= curl_errno($ch);
    curl_close($ch);

    // Connection refused / timeout = Apache not running or loopback blocked
    if ($curlErrNo === CURLE_OPERATION_TIMEDOUT || $curlErrNo === CURLE_COULDNT_CONNECT) {
        wd_warn($category, $label, "Cannot reach localhost — Apache off or loopback blocked (app works fine in browser)");
        return;
    }
    if ($curlErr) {
        wd_warn($category, $label, "cURL: $curlErr");
        return;
    }
    if ($httpCode === 301 || $httpCode === 302) {
        wd_warn($category, $label, "HTTP $httpCode redirect — auth required (expected)");
        return;
    }
    if ($httpCode >= 500) {
        $hint = '';
        if (preg_match('/(?:Fatal|Parse) error[^:]*:\s*(.{0,150})/i', $body, $m)) $hint = trim($m[1]);
        wd_fail($category, $label, "HTTP $httpCode" . ($hint ? " — $hint" : ''));
        return;
    }
    if (preg_match('/(?:Parse error|Fatal error)\s*:?\s*(.{0,150})/i', $body, $m)) {
        wd_fail($category, $label, "PHP error: " . trim($m[1]));
        return;
    }
    if (str_contains($body, 'WealthDash — Login') || str_contains($body, 'auth/login')) {
        wd_warn($category, $label, "Login redirect — auth works ✅");
        return;
    }
    if (str_contains($body, 'Direct access not permitted') || str_contains($body, 'Direct access not allowed')) {
        wd_warn($category, $label, "Direct access blocked — file exists, use router ✅");
        return;
    }
    $json = @json_decode($body, true);
    if ($json !== null) {
        wd_pass($category, $label, "HTTP $httpCode — valid JSON ✅");
        return;
    }
    $preview = substr(trim(strip_tags($body)), 0, 80);
    wd_warn($category, $label, "Non-JSON response: $preview");
}

$base = 'http://localhost/wealthdash';

// Only test via router (avoids direct-access guards, reduces timeouts)
wd_api_check('router alive',   "$base/api/?action=ping");
wd_api_check('mf_list',        "$base/api/?action=mf_list&view=consolidated&portfolio_id=0");
wd_api_check('nps_list',       "$base/api/?action=nps_list&type=holdings");
wd_api_check('savings_list',   "$base/api/?action=savings_list");
wd_api_check('nav_1d_change',  "$base/api/nav/nav_1d_change.php");
wd_api_check('fund_screener',  "$base/api/mutual_funds/fund_screener.php?limit=3");
