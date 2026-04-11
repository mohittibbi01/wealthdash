<?php
/**
 * WealthDash — tv08: NFO Tracker API
 * GET ?action=nfo_list
 *
 * Returns NFOs categorised as:
 *   open_now     — inception within last 30 days, still accepting
 *   closing_soon — closing within next 48 hours
 *   upcoming     — fund announced but not yet open
 *   recently_closed — closed within last 30 days
 *
 * Since XAMPP setup has no live NFO feed, we derive from the funds table:
 *   - "New" = inception_date within last 90 days (is_active=1)
 *   - Supplement with a simple nfo_tracker table if it exists
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$db = DB::conn();

$result = [
    'open_now'        => [],
    'closing_soon'    => [],
    'upcoming'        => [],
    'recently_closed' => [],
    'source'          => 'funds_table',
];

// ── Check if nfo_tracker table exists ───────────────────────────────────
$hasNfoTable = false;
try {
    $db->query("SELECT 1 FROM nfo_tracker LIMIT 1");
    $hasNfoTable = true;
} catch (Exception $e) {}

if ($hasNfoTable) {
    $result['source'] = 'nfo_tracker';

    $now = date('Y-m-d');

    // Open now (open_date <= today <= close_date)
    $stmt = $db->prepare("
        SELECT n.*, fh.short_name AS amc_short
        FROM nfo_tracker n
        LEFT JOIN fund_houses fh ON fh.id = n.fund_house_id
        WHERE n.status = 'open'
          AND n.open_date <= ?
          AND (n.close_date IS NULL OR n.close_date >= ?)
        ORDER BY n.close_date ASC
        LIMIT 20
    ");
    $stmt->execute([$now, $now]);
    $result['open_now'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Closing soon (closes within 2 days)
    $soon = date('Y-m-d', strtotime('+2 days'));
    $stmt = $db->prepare("
        SELECT n.*, fh.short_name AS amc_short
        FROM nfo_tracker n
        LEFT JOIN fund_houses fh ON fh.id = n.fund_house_id
        WHERE n.status = 'open'
          AND n.close_date BETWEEN ? AND ?
        ORDER BY n.close_date ASC
        LIMIT 10
    ");
    $stmt->execute([$now, $soon]);
    $result['closing_soon'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming
    $stmt = $db->prepare("
        SELECT n.*, fh.short_name AS amc_short
        FROM nfo_tracker n
        LEFT JOIN fund_houses fh ON fh.id = n.fund_house_id
        WHERE n.status = 'upcoming' OR (n.open_date > ?)
        ORDER BY n.open_date ASC
        LIMIT 10
    ");
    $stmt->execute([$now]);
    $result['upcoming'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recently closed (last 30 days)
    $stmt = $db->prepare("
        SELECT n.*, fh.short_name AS amc_short
        FROM nfo_tracker n
        LEFT JOIN fund_houses fh ON fh.id = n.fund_house_id
        WHERE n.status = 'closed'
          AND n.close_date >= DATE_SUB(?, INTERVAL 30 DAY)
        ORDER BY n.close_date DESC
        LIMIT 10
    ");
    $stmt->execute([$now]);
    $result['recently_closed'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {
    // ── Fallback: derive from funds table using inception_date ───────────
    $result['source'] = 'funds_table_derived';

    $hasInc = false;
    try { $db->query("SELECT inception_date FROM funds LIMIT 1"); $hasInc = true; } catch(Exception $e) {}

    if ($hasInc) {
        // "Open Now" = new funds launched in last 30 days (active)
        $stmt = $db->query("
            SELECT
                f.id, f.scheme_name AS fund_name, f.scheme_code, f.category,
                f.option_type AS plan_type, f.expense_ratio,
                f.inception_date AS open_date,
                DATE_ADD(f.inception_date, INTERVAL 30 DAY) AS close_date,
                COALESCE(fh.short_name, fh.name) AS amc_short,
                f.latest_nav,
                DATEDIFF(DATE_ADD(f.inception_date, INTERVAL 30 DAY), CURDATE()) AS days_left
            FROM funds f
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            WHERE f.is_active = 1
              AND f.inception_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY f.inception_date DESC
            LIMIT 20
        ");
        $open = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($open as $row) {
            $daysLeft = (int)$row['days_left'];
            if ($daysLeft >= 0 && $daysLeft <= 2) {
                $result['closing_soon'][] = $row;
            } else {
                $result['open_now'][] = $row;
            }
        }

        // Recently closed = 30-90 days old
        $stmt = $db->query("
            SELECT
                f.id, f.scheme_name AS fund_name, f.scheme_code, f.category,
                f.option_type AS plan_type, f.expense_ratio,
                f.inception_date AS open_date,
                DATE_ADD(f.inception_date, INTERVAL 30 DAY) AS close_date,
                COALESCE(fh.short_name, fh.name) AS amc_short,
                f.latest_nav
            FROM funds f
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            WHERE f.is_active = 1
              AND f.inception_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                                      AND DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY f.inception_date DESC
            LIMIT 10
        ");
        $result['recently_closed'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Counts ───────────────────────────────────────────────────────────────
$result['counts'] = [
    'open_now'        => count($result['open_now']),
    'closing_soon'    => count($result['closing_soon']),
    'upcoming'        => count($result['upcoming']),
    'recently_closed' => count($result['recently_closed']),
];

json_response(true, 'NFO list fetched', $result);
