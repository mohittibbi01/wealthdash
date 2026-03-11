<?php
/**
 * WealthDash — NPS NAV Update (PFRDA scraper + manual fallback)
 * POST /api/?action=nps_nav_update
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');
require_once APP_ROOT . '/includes/holding_calculator.php';

$schemeId = (int)($_POST['scheme_id'] ?? 0);
$manualNav = (float)($_POST['manual_nav'] ?? 0);
$manualDate = clean($_POST['nav_date'] ?? date('Y-m-d'));

// Manual NAV update path (always available)
if ($manualNav > 0 && $schemeId) {
    DB::run(
        "UPDATE nps_schemes SET latest_nav = ?, latest_nav_date = ? WHERE id = ?",
        [$manualNav, $manualDate, $schemeId]
    );
    // Recalculate all holdings for this scheme
    $portfolios = DB::fetchAll("SELECT DISTINCT portfolio_id, tier FROM nps_holdings WHERE scheme_id = ?", [$schemeId]);
    foreach ($portfolios as $p) {
        HoldingCalculator::recalculate_nps_holding($p['portfolio_id'], $schemeId, $p['tier']);
    }
    audit_log('nps_nav_update', 'nps_schemes', $schemeId);
    json_response(true, "NPS NAV updated to {$manualNav} for " . date('d-m-Y', strtotime($manualDate)));
}

// Auto-fetch from PFRDA (best-effort scraping)
if ($schemeId || isset($_POST['fetch_all'])) {
    $schemes = $schemeId
        ? DB::fetchAll("SELECT id, scheme_code, scheme_name, pfm_name FROM nps_schemes WHERE id = ?", [$schemeId])
        : DB::fetchAll("SELECT id, scheme_code, scheme_name, pfm_name FROM nps_schemes ORDER BY pfm_name");

    $updated = 0;
    $failed  = 0;

    foreach ($schemes as $scheme) {
        try {
            // PFRDA NAV URL (best-effort, may need updating)
            $url = 'https://npstrust.org.in/content/nav-list-all-pfms';
            // Note: PFRDA doesn't have a clean public API; scraping is fragile.
            // For now, log the attempt and recommend manual update.
            // Full scraper implementation goes in cron/nps_nav_scraper.php
            $failed++;
        } catch (Exception $e) {
            $failed++;
        }
    }

    if ($updated > 0) {
        json_response(true, "Updated {$updated} NPS NAVs. {$failed} failed (use manual update for those).");
    } else {
        json_response(false, "Auto-fetch not available. PFRDA does not provide a public API. Please use Manual NAV Update.", [
            'manual_update_required' => true,
            'tip' => 'Visit npstrust.org.in to check latest NAV, then enter manually.',
        ]);
    }
}

json_response(false, 'Please provide scheme_id and either manual_nav or fetch_all.');

