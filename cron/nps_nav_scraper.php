<?php
/**
 * WealthDash — NPS NAV Scraper
 * Attempts to fetch NAV from PFRDA website
 * Falls back to manual entry if scraping fails
 * Schedule: Weekly on Monday
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting NPS NAV update...\n";

// PFRDA NAV URL (may change — check pfrda.org.in)
$npsNavUrl = 'https://www.pfrda.org.in/index7.cshtml';

$context = stream_context_create([
    'http' => ['timeout' => 20, 'user_agent' => 'Mozilla/5.0 WealthDash/1.0'],
    'ssl'  => ['verify_peer' => false],
]);

$html = @file_get_contents($npsNavUrl, false, $context);

if (!$html) {
    echo "WARNING: Could not fetch PFRDA website. Manual NAV entry required.\n";
    exit(1);
}

// Basic scraping attempt — PFRDA site structure may vary
// Pattern: scheme code and NAV value extraction
// This is a best-effort scraper; manual entry is the fallback
$updated = 0;
$schemes = DB::fetchAll('SELECT * FROM nps_schemes WHERE is_active = 1');

foreach ($schemes as $scheme) {
    // Try to find NAV in page content
    // Pattern varies by PFM — simplified regex
    $code = preg_quote($scheme['scheme_code'], '/');
    if (preg_match('/NAV[^0-9]*([0-9]+\.[0-9]{4})/i', $html, $m)) {
        $nav = (float) $m[1];
        if ($nav > 0) {
            DB::run(
                'UPDATE nps_schemes SET latest_nav = ?, latest_nav_date = CURDATE(), updated_at = NOW() WHERE id = ?',
                [$nav, $scheme['id']]
            );
            $updated++;
        }
    }
}

echo "NPS NAV update complete. Updated: {$updated} schemes.\n";
echo "NOTE: If 0 updated, please enter NAV manually via Admin panel.\n";

