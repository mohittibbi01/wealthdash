<?php
/**
 * WealthDash — SIP On-Demand NAV Fetcher
 * Path: wealthdash/api/sip/sip_nav_fetch.php
 *
 * Called non-blocking when user adds a SIP with missing NAV data.
 * Downloads NAV history for ONE specific fund from mfapi.in
 * Saves to nav_history table.
 *
 * Security: token = md5(fund_id + from_date + APP_KEY)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/helpers.php';

// No auth check — secured by token instead (called server-side)
header('Content-Type: application/json');
set_time_limit(60);

$fundId     = (int) ($_GET['fund_id']     ?? 0);
$fromDate   = trim($_GET['from_date']     ?? '');
$schemeCode = trim($_GET['scheme_code']   ?? '');
$token      = trim($_GET['token']         ?? '');

// Validate token
$expectedToken = md5($fundId . $fromDate . env('APP_KEY', 'wealthdash'));
if ($token !== $expectedToken || !$fundId || !$fromDate || !$schemeCode) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

try {
    $db = DB::conn();

    // Check if already downloading / recently completed
    $existing = $db->prepare(
        "SELECT status, last_downloaded_date, from_date FROM nav_download_progress WHERE scheme_code = ?"
    );
    $existing->execute([$schemeCode]);
    $row = $existing->fetch();

    if ($row && $row['status'] === 'in_progress') {
        // Already running — skip
        echo json_encode(['success' => true, 'message' => 'Already downloading', 'status' => 'in_progress']);
        exit;
    }

    if ($row && $row['status'] === 'completed' && $row['from_date'] <= $fromDate) {
        // Already done for this date range
        echo json_encode(['success' => true, 'message' => 'Already downloaded', 'status' => 'completed']);
        exit;
    }

    // Mark as in_progress
    $db->prepare(
        "INSERT INTO nav_download_progress (scheme_code, fund_id, status, from_date)
         VALUES (?, ?, 'in_progress', ?)
         ON DUPLICATE KEY UPDATE status='in_progress', from_date=LEAST(from_date, ?)"
    )->execute([$schemeCode, $fundId, $fromDate, $fromDate]);

    // Fetch from mfapi.in
    $ctx = stream_context_create([
        'http' => [
            'timeout'    => 30,
            'user_agent' => 'WealthDash-SIP/1.0',
        ],
        'ssl' => ['verify_peer' => false],
    ]);

    $url = "https://api.mfapi.in/mf/{$schemeCode}";
    $raw = @file_get_contents($url, false, $ctx);

    if (!$raw) {
        $db->prepare(
            "UPDATE nav_download_progress SET status='error', error_message='mfapi.in fetch failed' WHERE scheme_code=?"
        )->execute([$schemeCode]);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch NAV data from mfapi.in']);
        exit;
    }

    $json = @json_decode($raw, true);
    if (empty($json['data'])) {
        $db->prepare(
            "UPDATE nav_download_progress SET status='completed', last_downloaded_date=CURDATE(), records_saved=0 WHERE scheme_code=?"
        )->execute([$schemeCode]);
        echo json_encode(['success' => true, 'message' => 'No NAV data found for this fund', 'records' => 0]);
        exit;
    }

    // Insert nav_history records
    $insStmt = $db->prepare(
        "INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?, ?, ?)"
    );

    $saved       = 0;
    $latestDate  = null;
    $earliestDate = null;

    foreach ($json['data'] as $entry) {
        // mfapi format: DD-MM-YYYY
        $parts = explode('-', $entry['date'] ?? '');
        if (count($parts) !== 3) continue;
        $isoDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";

        // Skip dates before fromDate
        if ($isoDate < $fromDate) continue;

        $nav = (float) ($entry['nav'] ?? 0);
        if ($nav <= 0) continue;

        $insStmt->execute([$fundId, $isoDate, $nav]);
        if ($db->lastInsertId()) $saved++;

        if (!$latestDate  || $isoDate > $latestDate)  $latestDate  = $isoDate;
        if (!$earliestDate || $isoDate < $earliestDate) $earliestDate = $isoDate;
    }

    // Also update funds table with latest NAV if newer
    if ($latestDate && $saved > 0) {
        $latestNavRow = $db->prepare(
            "SELECT nav FROM nav_history WHERE fund_id=? ORDER BY nav_date DESC LIMIT 1"
        );
        $latestNavRow->execute([$fundId]);
        $latestNavData = $latestNavRow->fetch();

        if ($latestNavData) {
            $db->prepare(
                "UPDATE funds SET
                    prev_nav        = IF(latest_nav_date < ?, latest_nav, prev_nav),
                    prev_nav_date   = IF(latest_nav_date < ?, latest_nav_date, prev_nav_date),
                    latest_nav      = IF(latest_nav_date < ? OR latest_nav_date IS NULL, ?, latest_nav),
                    latest_nav_date = IF(latest_nav_date < ? OR latest_nav_date IS NULL, ?, latest_nav_date),
                    updated_at      = NOW()
                 WHERE id = ?"
            )->execute([
                $latestDate, $latestDate,
                $latestDate, $latestNavData['nav'],
                $latestDate, $latestDate,
                $fundId
            ]);
        }
    }

    // Update progress
    $db->prepare(
        "UPDATE nav_download_progress
         SET status='completed', last_downloaded_date=?, records_saved=records_saved+?, error_message=NULL
         WHERE scheme_code=?"
    )->execute([$latestDate ?? date('Y-m-d'), $saved, $schemeCode]);

    echo json_encode([
        'success'      => true,
        'message'      => "Downloaded {$saved} NAV records",
        'records'      => $saved,
        'from'         => $earliestDate,
        'to'           => $latestDate,
        'fund_id'      => $fundId,
        'scheme_code'  => $schemeCode,
    ]);

} catch (Exception $e) {
    try {
        DB::run(
            "UPDATE nav_download_progress SET status='error', error_message=? WHERE scheme_code=?",
            [$e->getMessage(), $schemeCode]
        );
    } catch (\Exception $ignored) {}

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
