<?php
/**
 * WealthDash — Fund ISIN Validator (t483)
 *
 * Validates MF scheme codes against AMFI's official fund list.
 * AMFI provides a free text file at:
 *   https://www.amfiindia.com/spages/NAVAll.txt
 * Format: SchemeCode;ISINGrowth;ISINDividend;SchemeName;...
 *
 * Actions:
 *   GET  ?action=isin_validate&fund_id=X          → validate single fund
 *   GET  ?action=isin_validate_code&scheme_code=X → validate by scheme code
 *   POST action=isin_batch_validate               → validate all holdings
 *   POST action=isin_fix&fund_id=X&new_code=Y    → apply suggested fix
 *   GET  ?action=isin_cache_refresh               → refresh AMFI cache (admin)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// Ensure isin column exists on funds
try { $db->exec("ALTER TABLE `funds` ADD COLUMN IF NOT EXISTS `isin_growth`   VARCHAR(15) DEFAULT NULL COMMENT 'ISIN for Growth option'"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD COLUMN IF NOT EXISTS `isin_idcw`     VARCHAR(15) DEFAULT NULL COMMENT 'ISIN for IDCW/Dividend option'"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD COLUMN IF NOT EXISTS `isin_verified` TINYINT(1)  DEFAULT NULL COMMENT '1=valid, 0=invalid, NULL=unchecked'"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD COLUMN IF NOT EXISTS `isin_checked_at` DATETIME  DEFAULT NULL"); } catch(Exception $e) {}

// ── AMFI data loader ──────────────────────────────────────────────────────
const AMFI_URL        = 'https://www.amfiindia.com/spages/NAVAll.txt';
const AMFI_CACHE_FILE_PATH = '/tmp/wd_amfi_nav_all.txt';
const AMFI_CACHE_TTL  = 86400; // 1 day

/**
 * Fetch and parse AMFI NAVAll.txt into a lookup map.
 * Returns: ['scheme_code' => ['isin_growth'=>..., 'isin_idcw'=>..., 'scheme_name'=>...], ...]
 */
function load_amfi_data(): array {
    // Try cache
    if (file_exists(AMFI_CACHE_FILE_PATH) && (time() - filemtime(AMFI_CACHE_FILE_PATH)) < AMFI_CACHE_TTL) {
        $raw = @file_get_contents(AMFI_CACHE_FILE_PATH);
        if ($raw) return parse_amfi($raw);
    }

    // Fetch from AMFI
    $ctx = stream_context_create(['http' => [
        'timeout'    => 15,
        'user_agent' => 'Mozilla/5.0 WealthDash/2.0',
    ], 'ssl' => ['verify_peer' => false]]);

    $raw = @file_get_contents(AMFI_URL, false, $ctx);
    if (!$raw || strlen($raw) < 1000) {
        // Fallback: use cached even if stale
        $stale = @file_get_contents(AMFI_CACHE_FILE_PATH);
        return $stale ? parse_amfi($stale) : [];
    }

    @file_put_contents(AMFI_CACHE_FILE_PATH, $raw);
    return parse_amfi($raw);
}

/**
 * Parse AMFI NAVAll.txt.
 * Line format: SchemeCode;ISINGrowth;ISINDivReinvest;SchemeName;AMC;Date;Nav
 * Blank lines and header lines skipped.
 */
function parse_amfi(string $raw): array {
    $map   = [];
    $lines = explode("\n", $raw);
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line || str_starts_with($line, 'Scheme') || str_starts_with($line, 'Open') ||
            str_starts_with($line, 'Close') || str_starts_with($line, '---')) continue;

        $parts = explode(';', $line);
        if (count($parts) < 6) continue;

        $code = trim($parts[0]);
        if (!$code || !is_numeric($code)) continue;

        $isinGrowth = trim($parts[1] ?? '');
        $isinIdcw   = trim($parts[2] ?? '');
        $name       = trim($parts[3] ?? '');

        $map[$code] = [
            'scheme_code'  => $code,
            'isin_growth'  => strlen($isinGrowth) === 12 ? $isinGrowth : null,
            'isin_idcw'    => strlen($isinIdcw)   === 12 ? $isinIdcw   : null,
            'scheme_name'  => $name,
        ];
    }
    return $map;
}

/**
 * Find closest matching fund by scheme_code (levenshtein distance).
 * Returns top 3 suggestions.
 */
function find_closest(string $query, array $amfiMap, string $nameHint = ''): array {
    $candidates = [];
    $nameLower  = strtolower($nameHint);

    foreach ($amfiMap as $code => $entry) {
        $dist = levenshtein($query, $code);
        // Bonus for name match
        if ($nameLower && str_contains(strtolower($entry['scheme_name']), $nameLower)) {
            $dist -= 3;
        }
        $candidates[] = ['dist' => $dist, 'code' => $code, 'name' => $entry['scheme_name'],
                         'isin_growth' => $entry['isin_growth'], 'isin_idcw' => $entry['isin_idcw']];
    }
    usort($candidates, fn($a, $b) => $a['dist'] <=> $b['dist']);
    return array_slice($candidates, 0, 3);
}

/**
 * Validate a single scheme_code against AMFI data.
 * Returns validation result array.
 */
function validate_scheme(string $schemeCode, string $schemeName, array $amfiMap): array {
    if (isset($amfiMap[$schemeCode])) {
        $entry = $amfiMap[$schemeCode];
        $nameMatch = similar_text(strtolower($schemeName), strtolower($entry['scheme_name'])) > 40;
        return [
            'valid'          => true,
            'scheme_code'    => $schemeCode,
            'amfi_name'      => $entry['scheme_name'],
            'isin_growth'    => $entry['isin_growth'],
            'isin_idcw'      => $entry['isin_idcw'],
            'name_match'     => $nameMatch,
            'name_warning'   => !$nameMatch ? 'Scheme name mismatch with AMFI records' : null,
            'suggestions'    => [],
        ];
    }

    // Not found — provide suggestions
    $suggestions = find_closest($schemeCode, $amfiMap, $schemeName);
    return [
        'valid'          => false,
        'scheme_code'    => $schemeCode,
        'amfi_name'      => null,
        'isin_growth'    => null,
        'isin_idcw'      => null,
        'name_match'     => false,
        'name_warning'   => null,
        'error'          => "Scheme code '{$schemeCode}' not found in AMFI database",
        'suggestions'    => $suggestions,
    ];
}

// ── Route ─────────────────────────────────────────────────────────────────
switch ($action) {

    // ── GET validate single fund by fund_id ───────────────────────────────
    case 'isin_validate': {
        $fundId = (int)($_GET['fund_id'] ?? 0);
        if (!$fundId) json_response(false, 'fund_id required');

        $stmt = $db->prepare("SELECT id, scheme_code, scheme_name, isin_growth, isin_idcw, isin_verified FROM funds WHERE id=?");
        $stmt->execute([$fundId]);
        $fund = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fund) json_response(false, 'Fund not found');

        $amfiMap = load_amfi_data();
        if (empty($amfiMap)) {
            json_response(false, 'AMFI data unavailable — check network or try isin_cache_refresh');
        }

        $result = validate_scheme($fund['scheme_code'], $fund['scheme_name'], $amfiMap);

        // Persist ISIN + verification status
        if ($result['valid']) {
            try {
                $db->prepare("
                    UPDATE funds SET isin_growth=?, isin_idcw=?, isin_verified=1, isin_checked_at=NOW()
                    WHERE id=?
                ")->execute([$result['isin_growth'], $result['isin_idcw'], $fundId]);
            } catch(Exception $e) {}
        } else {
            try {
                $db->prepare("UPDATE funds SET isin_verified=0, isin_checked_at=NOW() WHERE id=?")->execute([$fundId]);
            } catch(Exception $e) {}
        }

        json_response(true, '', array_merge(['fund_id' => $fundId], $result));
        break;
    }

    // ── GET validate by scheme_code directly ─────────────────────────────
    case 'isin_validate_code': {
        $code = trim($_GET['scheme_code'] ?? '');
        $name = trim($_GET['scheme_name'] ?? '');
        if (!$code) json_response(false, 'scheme_code required');

        $amfiMap = load_amfi_data();
        if (empty($amfiMap)) json_response(false, 'AMFI data unavailable');

        $result = validate_scheme($code, $name, $amfiMap);
        json_response(true, '', $result);
        break;
    }

    // ── POST batch validate all user holdings ─────────────────────────────
    case 'isin_batch_validate': {
        // Get all distinct funds in user's portfolios
        $stmt = $db->prepare("
            SELECT DISTINCT f.id, f.scheme_code, f.scheme_name, f.isin_verified, f.isin_checked_at
            FROM mf_transactions t
            JOIN portfolios p ON p.id = t.portfolio_id
            JOIN funds f ON f.id = t.fund_id
            WHERE p.user_id = ?
            ORDER BY f.scheme_name
        ");
        $stmt->execute([$userId]);
        $funds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($funds)) json_response(false, 'No funds in portfolio to validate');

        $amfiMap = load_amfi_data();
        if (empty($amfiMap)) json_response(false, 'AMFI data unavailable');

        $results  = [];
        $valid    = 0;
        $invalid  = 0;
        $warnings = 0;

        foreach ($funds as $fund) {
            $r = validate_scheme($fund['scheme_code'], $fund['scheme_name'], $amfiMap);
            $r['fund_id'] = (int)$fund['id'];

            // Persist
            if ($r['valid']) {
                try {
                    $db->prepare("UPDATE funds SET isin_growth=?, isin_idcw=?, isin_verified=1, isin_checked_at=NOW() WHERE id=?")
                       ->execute([$r['isin_growth'], $r['isin_idcw'], (int)$fund['id']]);
                } catch(Exception $e) {}
                if ($r['name_warning']) $warnings++;
                $valid++;
            } else {
                try {
                    $db->prepare("UPDATE funds SET isin_verified=0, isin_checked_at=NOW() WHERE id=?")->execute([(int)$fund['id']]);
                } catch(Exception $e) {}
                $invalid++;
            }

            $results[] = $r;
        }

        json_response(true, "Validated {$valid} valid, {$invalid} invalid, {$warnings} name warnings", [
            'summary' => compact('valid', 'invalid', 'warnings'),
            'results' => $results,
            'total'   => count($results),
        ]);
        break;
    }

    // ── POST apply suggested fix to a fund ───────────────────────────────
    case 'isin_fix': {
        if ($currentUser['role'] !== 'admin') json_response(false, 'Admin access required', [], 403);

        $fundId  = (int)($_POST['fund_id']  ?? 0);
        $newCode = trim($_POST['new_code']  ?? '');
        if (!$fundId || !$newCode) json_response(false, 'fund_id and new_code required');
        if (!is_numeric($newCode)) json_response(false, 'new_code must be a numeric AMFI scheme code');

        $amfiMap = load_amfi_data();
        if (!isset($amfiMap[$newCode])) json_response(false, "Scheme code {$newCode} not found in AMFI data");

        $entry = $amfiMap[$newCode];

        $db->prepare("
            UPDATE funds
            SET scheme_code=?, isin_growth=?, isin_idcw=?, isin_verified=1, isin_checked_at=NOW()
            WHERE id=?
        ")->execute([$newCode, $entry['isin_growth'], $entry['isin_idcw'], $fundId]);

        // Also fix nav_history references if any (via fund_id, so no change needed)
        json_response(true, 'Scheme code updated successfully', [
            'fund_id'     => $fundId,
            'new_code'    => $newCode,
            'amfi_name'   => $entry['scheme_name'],
            'isin_growth' => $entry['isin_growth'],
            'isin_idcw'   => $entry['isin_idcw'],
        ]);
        break;
    }

    // ── GET refresh AMFI cache ────────────────────────────────────────────
    case 'isin_cache_refresh': {
        if ($currentUser['role'] !== 'admin') json_response(false, 'Admin access required', [], 403);

        // Force refresh by deleting cache
        @unlink(AMFI_CACHE_FILE_PATH);
        $amfiMap = load_amfi_data();

        json_response(true, 'AMFI cache refreshed', [
            'funds_in_amfi' => count($amfiMap),
            'cache_file'    => AMFI_CACHE_FILE_PATH,
            'refreshed_at'  => date('Y-m-d H:i:s'),
        ]);
        break;
    }

    // ── GET invalid funds summary ─────────────────────────────────────────
    case 'isin_invalid_list': {
        if ($currentUser['role'] !== 'admin') json_response(false, 'Admin access required', [], 403);

        $stmt = $db->query("
            SELECT f.id, f.scheme_code, f.scheme_name,
                   COALESCE(fh.short_name, fh.name) AS fund_house,
                   f.isin_verified, f.isin_checked_at
            FROM funds f
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            WHERE f.isin_verified = 0
            ORDER BY f.scheme_name
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_response(true, '', ['invalid_funds' => $rows, 'count' => count($rows)]);
        break;
    }

    default:
        json_response(false, 'Unknown action');
}
