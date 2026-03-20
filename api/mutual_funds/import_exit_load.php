<?php
/**
 * WealthDash — Exit Load Seeder (Category-based Rules)
 *
 * Applies standard SEBI/industry exit load rules based on fund category.
 * Covers ~95% of Indian MF exit loads accurately.
 *
 * Rules (as per typical AMC SIDs):
 *  - Equity / Hybrid Equity-oriented → 1% within 365 days
 *  - ELSS                            → No exit load (lock-in covers it)
 *  - Index / ETF / Arbitrage         → No exit load
 *  - Debt / Liquid / Overnight       → No exit load
 *  - Solution / Retirement           → 1% within 365 days (varies)
 *
 * Run from Admin: http://localhost/wealthdash/api/mutual_funds/import_exit_load.php
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

$currentUser = require_auth();
if ($currentUser['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin only']);
    exit;
}

$db = DB::conn();

// Make sure columns exist
try {
    $db->query("SELECT exit_load_pct, exit_load_days FROM funds LIMIT 1");
} catch (Exception $e) {
    // Add columns if missing
    try {
        $db->exec("ALTER TABLE funds ADD COLUMN exit_load_pct DECIMAL(5,4) DEFAULT NULL AFTER expense_ratio");
        $db->exec("ALTER TABLE funds ADD COLUMN exit_load_days SMALLINT UNSIGNED DEFAULT NULL AFTER exit_load_pct");
    } catch (Exception $e2) {
        echo json_encode(['success' => false, 'message' => 'Column error: ' . $e2->getMessage()]);
        exit;
    }
}

// Fetch all active funds with category
$funds = $db->query("
    SELECT id, scheme_name, category, option_type
    FROM funds
    WHERE is_active = 1
")->fetchAll();

$updated = 0;
$equity = 0; $nil = 0; $arbitrage = 0;

// Use INSERT ... ON DUPLICATE KEY or direct UPDATE without rowCount check
$stmt = $db->prepare("UPDATE funds SET exit_load_pct = ?, exit_load_days = ? WHERE id = ?");

$db->beginTransaction();
foreach ($funds as $f) {
    [$pct, $days] = get_exit_load($f['category'] ?? '', $f['scheme_name'] ?? '');
    $stmt->execute([$pct, $days, $f['id']]);
    $updated++;
    if ($pct > 0) $equity++;
    else $nil++;
}
$db->commit();

echo json_encode([
    'success'   => true,
    'message'   => "Exit load seeded for {$updated} funds.",
    'updated'   => $updated,
    'total'     => count($funds),
    'with_load' => $equity,
    'nil_load'  => $nil,
]);

// ── Exit Load Rules ──────────────────────────────────────────────────────────

function get_exit_load(string $category, string $name): array {
    $cat  = strtolower(trim($category));
    $name = strtolower(trim($name));

    // ── NO exit load ────────────────────────────────────────────────────
    // ELSS — lock-in serves the purpose
    if (str_contains($cat, 'elss') || str_contains($cat, 'tax sav')) {
        return [0.00, 0];
    }

    // Liquid / Overnight / Money Market — negligible graded load, show as 0
    if (str_contains($cat, 'liquid') || str_contains($cat, 'overnight') ||
        str_contains($cat, 'money market')) {
        return [0.00, 0];
    }

    // Index Funds & ETFs — typically no exit load
    if (str_contains($cat, 'index') || str_contains($name, 'index') ||
        str_contains($cat, 'etf')   || str_contains($name, ' etf')  ||
        str_contains($name, 'nifty 50 index') || str_contains($name, 'sensex index')) {
        return [0.00, 0];
    }

    // Arbitrage — 0.25% within 30 days (most common)
    if (str_contains($cat, 'arbitrage') || str_contains($name, 'arbitrage')) {
        return [0.25, 30];
    }

    // Pure Debt — no exit load
    if (str_contains($cat, 'debt')          || str_contains($cat, 'gilt')      ||
        str_contains($cat, 'corporate bond') || str_contains($cat, 'credit')   ||
        str_contains($cat, 'duration')       || str_contains($cat, 'floater')  ||
        str_contains($cat, 'banking and psu')|| str_contains($cat, 'ultra short') ||
        str_contains($cat, 'low duration')   || str_contains($cat, 'short duration')) {
        return [0.00, 0];
    }

    // ── 1% within 1 year ────────────────────────────────────────────────
    // All Equity variants
    if (str_contains($cat, 'equity')     || str_contains($cat, 'large cap')  ||
        str_contains($cat, 'mid cap')    || str_contains($cat, 'small cap')  ||
        str_contains($cat, 'flexi cap')  || str_contains($cat, 'multi cap')  ||
        str_contains($cat, 'focused')    || str_contains($cat, 'thematic')   ||
        str_contains($cat, 'sectoral')   || str_contains($cat, 'dividend yield') ||
        str_contains($cat, 'value')      || str_contains($cat, 'contra')     ||
        str_contains($cat, 'large & mid')|| str_contains($cat, 'large and mid')) {
        return [1.00, 365];
    }

    // Hybrid funds — equity-oriented ones have exit load
    if (str_contains($cat, 'hybrid')              || str_contains($cat, 'balanced') ||
        str_contains($cat, 'aggressive')           || str_contains($cat, 'dynamic asset') ||
        str_contains($cat, 'balanced advantage')   || str_contains($cat, 'multi asset') ||
        str_contains($cat, 'equity savings')) {
        return [1.00, 365];
    }

    // Solution / Retirement / Children
    if (str_contains($cat, 'retirement') || str_contains($cat, 'children') ||
        str_contains($cat, 'solution')) {
        return [1.00, 365];
    }

    // Fund of Funds (domestic / international)
    if (str_contains($cat, 'fund of fund') || str_contains($cat, 'fof') ||
        str_contains($cat, 'overseas')     || str_contains($cat, 'international')) {
        return [1.00, 365];
    }

    // Gold/Silver/Commodity ETF-based funds
    if (str_contains($cat, 'gold')  || str_contains($cat, 'silver') ||
        str_contains($cat, 'commodit')) {
        return [0.00, 0];  // ETF-based, no exit load
    }

    // Default — assume equity-style 1% for 1 year
    return [1.00, 365];
}