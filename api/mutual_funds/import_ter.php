<?php
/**
 * WealthDash — TER (Expense Ratio) Import
 * Source: https://raw.githubusercontent.com/captn3m0/india-mutual-fund-ter-tracker/main/data.csv
 *
 * CSV Columns (actual format):
 *   Scheme Name | Regular Plan - Base TER (%) | ... | Regular Plan - Total TER (%)
 *               | Direct Plan - Base TER (%)  | ... | Direct Plan - Total TER (%)
 *
 * Strategy: match scheme_name in DB → set expense_ratio from Total TER
 * For each row we update BOTH the direct and regular variants in DB.
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: application/json');
    $currentUser = require_auth();
    if ($currentUser['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin only']);
        exit;
    }
}

set_time_limit(180);
ini_set('memory_limit', '256M');

$terUrl = 'https://raw.githubusercontent.com/captn3m0/india-mutual-fund-ter-tracker/main/data.csv';

$ctx  = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'WealthDash/1.0']]);
$data = @file_get_contents($terUrl, false, $ctx);

if (!$data) {
    $msg = ['success' => false, 'message' => 'Failed to fetch TER data. Check internet connection.'];
    if ($isCli) { echo "ERROR: " . $msg['message'] . "\n"; exit(1); }
    echo json_encode($msg); exit;
}

$lines  = explode("\n", trim($data));
$header = str_getcsv(array_shift($lines));

// Find column indexes
$nameIdx    = null;
$regTerIdx  = null; // Regular Plan - Total TER (%)
$dirTerIdx  = null; // Direct Plan - Total TER (%)

foreach ($header as $i => $col) {
    $col = trim($col);
    if ($nameIdx === null && stripos($col, 'scheme name') !== false) {
        $nameIdx = $i;
    }
    if ($regTerIdx === null && stripos($col, 'regular') !== false && stripos($col, 'total') !== false) {
        $regTerIdx = $i;
    }
    if ($dirTerIdx === null && stripos($col, 'direct') !== false && stripos($col, 'total') !== false) {
        $dirTerIdx = $i;
    }
}

if ($nameIdx === null) {
    echo json_encode(['success' => false,
        'message' => 'Scheme Name column not found. Columns: ' . implode(' | ', array_slice($header, 0, 12))]);
    exit;
}

$db = DB::conn();

// Load all fund names from DB into memory for fast fuzzy matching
$allFunds = $db->query("SELECT id, scheme_name, option_type FROM funds WHERE is_active = 1")->fetchAll();

// Build a normalized name → id map
$nameMap = [];
foreach ($allFunds as $f) {
    $norm = normName($f['scheme_name']);
    $nameMap[$norm][] = ['id' => $f['id'], 'option_type' => $f['option_type']];
}

$updateStmt = $db->prepare("UPDATE funds SET expense_ratio = ? WHERE id = ?");
$updated = 0; $skipped = 0; $notFound = 0;

foreach ($lines as $line) {
    if (empty(trim($line))) continue;
    $cols = str_getcsv($line);
    if (count($cols) <= $nameIdx) continue;

    $schemeName = trim($cols[$nameIdx]);
    if (empty($schemeName)) { $skipped++; continue; }

    // Get TER values
    $regTer = ($regTerIdx !== null && isset($cols[$regTerIdx])) ? cleanTer($cols[$regTerIdx]) : null;
    $dirTer = ($dirTerIdx !== null && isset($cols[$dirTerIdx])) ? cleanTer($cols[$dirTerIdx]) : null;

    // If neither TER is valid, skip
    if ($regTer === null && $dirTer === null) { $skipped++; continue; }

    // Try exact match first, then fuzzy
    $normSearch = normName($schemeName);
    $matched    = matchFund($normSearch, $nameMap);

    if ($matched === null) { $notFound++; continue; }

    // Update matched funds with appropriate TER
    foreach ($matched as $fund) {
        $ter = ($fund['option_type'] === 'growth' || $fund['option_type'] === 'bonus')
            ? ($dirTer ?? $regTer)   // prefer direct for growth/bonus
            : $regTer ?? $dirTer;    // prefer regular for idcw/dividend

        if ($ter === null) continue;
        $updateStmt->execute([$ter, $fund['id']]);
        if ($updateStmt->rowCount() > 0) $updated++;
    }
}

// Save last-run timestamp
DB::run("INSERT INTO app_settings (setting_key, setting_val) VALUES ('ter_last_updated', NOW())
         ON DUPLICATE KEY UPDATE setting_val = NOW()");

$result = [
    'success'   => true,
    'message'   => "TER import complete. Updated {$updated} funds.",
    'updated'   => $updated,
    'not_found' => $notFound,
    'skipped'   => $skipped,
];

if ($isCli) {
    echo "Updated: $updated | Not found: $notFound | Skipped: $skipped\n";
    exit(0);
}
echo json_encode($result);

// ── Helpers ──────────────────────────────────────────────────────────────────

function cleanTer(string $v): ?float {
    $v = trim($v);
    if ($v === '' || strtolower($v) === 'n/a' || $v === '-' || $v === '0') return null;
    $clean = (float)preg_replace('/[^0-9.]/', '', $v);
    if ($clean <= 0 || $clean > 5) return null;
    return round($clean, 4);
}

function normName(string $name): string {
    $name = strtolower(trim($name));
    // Remove plan/option suffixes
    $name = preg_replace('/\s*[-–]\s*(direct|regular)\s*(plan)?\s*[-–]?\s*(growth|idcw|dividend|bonus|payout|reinvest|monthly|weekly|quarterly|annual|daily|fortnightly).*$/i', '', $name);
    $name = preg_replace('/\s*[-–]\s*(direct|regular)\s*(plan)?\s*$/i', '', $name);
    $name = preg_replace('/\s*[-–]\s*(growth|idcw|dividend|bonus|payout|reinvest|monthly|weekly|quarterly|annual|daily|fortnightly|income distribution).*$/i', '', $name);
    // Remove common noise words
    $name = preg_replace('/(fund|scheme|mutual|plan|option|series|sr|st|nd|rd|th)/i', ' ', $name);
    // Normalize special chars
    $name = preg_replace('/[^a-z0-9 &]/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function matchFund(string $search, array $nameMap): ?array {
    // 1. Exact match
    if (isset($nameMap[$search])) return $nameMap[$search];

    // 2. Prefix match (search starts with key or vice versa)
    $best = null; $bestLen = 0;
    foreach ($nameMap as $key => $funds) {
        if (str_starts_with($search, $key) || str_starts_with($key, $search)) {
            $len = min(strlen($search), strlen($key));
            if ($len > $bestLen) { $bestLen = $len; $best = $funds; }
        }
    }
    if ($best !== null && $bestLen >= 8) return $best;

    // 3. Word overlap matching — count common words
    $searchWords = array_filter(explode(' ', $search), fn($w) => strlen($w) >= 3);
    if (count($searchWords) < 2) return null;

    $best = null; $bestScore = 0;
    foreach ($nameMap as $key => $funds) {
        $keyWords = array_filter(explode(' ', $key), fn($w) => strlen($w) >= 3);
        $common   = count(array_intersect($searchWords, $keyWords));
        $total    = max(count($searchWords), count($keyWords));
        if ($total === 0) continue;
        $score = $common / $total;
        // Also require at least 2 common words and 75% overlap
        if ($common >= 2 && $score >= 0.75 && $score > $bestScore) {
            $bestScore = $score;
            $best = $funds;
        }
    }
    if ($best !== null) return $best;

    // 4. similar_text for remaining (only if search is long enough)
    if (strlen($search) < 8) return null;
    $bestPct = 0;
    foreach ($nameMap as $key => $funds) {
        similar_text($search, $key, $pct);
        if ($pct >= 82 && $pct > $bestPct) {
            $bestPct = $pct;
            $best    = $funds;
        }
    }
    return $best;
}