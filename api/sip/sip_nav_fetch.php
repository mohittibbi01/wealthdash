<?php
/**
 * WealthDash — SIP On-Demand NAV Fetcher + Auto Transaction Generator
 * Path: wealthdash/api/sip/sip_nav_fetch.php
 *
 * Called non-blocking when user adds a SIP with missing NAV data.
 * 1. Downloads ALL NAV history since inception from mfapi.in
 * 2. After download, auto-generates past SIP transactions (BUY/SWP)
 *    from sip start_date to today based on frequency
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
$sipId      = (int) ($_GET['sip_id']      ?? 0);   // optional — if set, generate transactions after download
$portfolioId= (int) ($_GET['portfolio_id'] ?? 0);

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

        // Download ALL history (since inception) so we have complete data
        // (fromDate is used only for transaction generation, not nav download)

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

    // ── Auto-generate past SIP/SWP transactions ────────────────
    $txnsGenerated = 0;
    $txnErrors     = [];

    if ($sipId > 0 && $portfolioId > 0) {
        $sip = $db->prepare(
            "SELECT * FROM sip_schedules WHERE id = ? AND portfolio_id = ? LIMIT 1"
        );
        $sip->execute([$sipId, $portfolioId]);
        $sipRow = $sip->fetch(PDO::FETCH_ASSOC);

        if ($sipRow) {
            $sipStart   = $sipRow['start_date'];
            $sipEnd     = $sipRow['end_date'] ?: date('Y-m-d');
            $sipDay     = (int)$sipRow['sip_day'];
            $sipAmount  = (float)$sipRow['sip_amount'];
            $sipFreq    = $sipRow['frequency'];
            $folio      = $sipRow['folio_number'] ?? null;
            $platform   = $sipRow['platform'] ?? null;
            $isSWP      = ($sipRow['schedule_type'] === 'SWP');
            $txnType    = $isSWP ? 'SWP' : 'BUY';

            // Get installment dates (up to today)
            $today      = date('Y-m-d');
            $endCap     = min($sipEnd, $today);
            $dates      = _generate_sip_dates($sipStart, $endCap, $sipDay, $sipFreq);

            // Prepare statements
            $navStmt = $db->prepare(
                "SELECT nav, nav_date FROM nav_history
                 WHERE fund_id = ? AND nav_date >= ?
                 ORDER BY nav_date ASC LIMIT 1"
            );
            $dupStmt = $db->prepare(
                "SELECT id FROM mf_transactions
                 WHERE portfolio_id=? AND fund_id=? AND transaction_type=? AND txn_date=?
                 LIMIT 1"
            );
            $insStmt2 = $db->prepare(
                "INSERT INTO mf_transactions
                 (portfolio_id, fund_id, folio_number, transaction_type, platform,
                  txn_date, units, nav, value_at_cost, stamp_duty, notes, import_source, investment_fy)
                 VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?)"
            );

            foreach ($dates as $date) {
                // Skip if transaction already exists on this date for this type
                $dupStmt->execute([$portfolioId, $fundId, $txnType, $date]);
                if ($dupStmt->fetch()) continue;

                // Find closest NAV on or after this date
                $navStmt->execute([$fundId, $date]);
                $navRow = $navStmt->fetch(PDO::FETCH_ASSOC);
                if (!$navRow || (float)$navRow['nav'] <= 0) {
                    $txnErrors[] = $date; // no nav available
                    continue;
                }

                $nav     = (float)$navRow['nav'];
                $actualDate = $navRow['nav_date']; // use actual NAV date (next trading day)
                $units   = round($sipAmount / $nav, 4);
                $fy      = _get_fy($actualDate);
                $notes   = 'Auto-generated from SIP #' . $sipId;

                try {
                    $insStmt2->execute([
                        $portfolioId, $fundId, $folio, $txnType, $platform,
                        $actualDate, $units, $nav, $sipAmount,
                        $notes, 'manual', $fy
                    ]);
                    $txnsGenerated++;
                } catch (Exception $txnEx) {
                    $txnErrors[] = $date . ': ' . $txnEx->getMessage();
                }
            }

            // Recalculate holdings after inserting transactions
            if ($txnsGenerated > 0) {
                try {
                    require_once APP_ROOT . '/includes/holding_calculator.php';
                    HoldingCalculator::recalculate_mf_holding($portfolioId, $fundId, $folio);
                } catch (Exception $calcEx) {
                    // non-fatal
                }
            }
        }
    }

    echo json_encode([
        'success'           => true,
        'message'           => "Downloaded {$saved} NAV records" . ($txnsGenerated > 0 ? ", generated {$txnsGenerated} transactions" : ''),
        'records'           => $saved,
        'from'              => $earliestDate,
        'to'                => $latestDate,
        'fund_id'           => $fundId,
        'scheme_code'       => $schemeCode,
        'txns_generated'    => $txnsGenerated,
        'txn_errors'        => count($txnErrors),
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

// ── Local helpers (no autoload here) ─────────────────────────
function _generate_sip_dates(string $start, string $end, int $sipDay, string $freq): array {
    $dates   = [];
    $current = new DateTime($start);
    $endDt   = new DateTime($end);

    if (in_array($freq, ['daily', 'weekly', 'fortnightly'])) {
        // start from exact date
    } else {
        // snap to SIP day
        $current->setDate((int)$current->format('Y'), (int)$current->format('n'), min($sipDay, 28));
        if ($current->format('Y-m-d') < $start) {
            $current->modify(match($freq) {
                'quarterly' => '+3 months',
                'yearly'    => '+1 year',
                default     => '+1 month',
            });
        }
    }

    $max = match($freq) { 'daily' => 3650, 'weekly' => 1500, 'fortnightly' => 800, default => 600 };
    $i = 0;
    while ($current <= $endDt && $i++ < $max) {
        $dates[] = $current->format('Y-m-d');
        $current->modify(match($freq) {
            'daily'       => '+1 day',
            'weekly'      => '+7 days',
            'fortnightly' => '+15 days',
            'quarterly'   => '+3 months',
            'yearly'      => '+1 year',
            default       => '+1 month',
        });
        if (!in_array($freq, ['daily','weekly','fortnightly'])) {
            $current->setDate((int)$current->format('Y'), (int)$current->format('n'),
                min($sipDay, (int)$current->format('t')));
        }
    }
    return $dates;
}

function _get_fy(string $date): string {
    $y = (int)date('Y', strtotime($date));
    $m = (int)date('n', strtotime($date));
    return $m >= 4 ? "{$y}-" . substr((string)($y+1),2) : ($y-1) . '-' . substr((string)$y,2);
}