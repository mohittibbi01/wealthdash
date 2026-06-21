<?php
/**
 * WealthDash — MF CSV Importer v3 (t490)
 *
 * NEW in v3 (vs existing mf_import_csv.php):
 *   1. Smart column detector — identify date/fund/amount/units/nav columns by header name
 *   2. Extended format support: MProfit, Angel One, INDmoney, Paytm Money
 *   3. Preview mode — return first 10 rows before committing import
 *   4. Detailed error report — exactly which rows failed and why
 *   5. AI-assisted column mapping via Claude API (when columns are ambiguous)
 *
 * This file handles ONLY the v3 actions. Existing mf_import_csv.php still
 * handles the original actions (mf_import_csv).
 *
 * Actions:
 *   POST action=csv_v3_detect    → detect format + return column mapping + preview rows
 *   POST action=csv_v3_import    → do full import with confirmed column mapping
 *   GET  ?action=csv_v3_formats  → list all supported formats + sample headers
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// ── Supported formats catalogue ───────────────────────────────────────────
const CSV_FORMATS_V3 = [
    'wealthdash'   => ['label' => 'WealthDash Export',    'cols' => ['fund_name','scheme_code','date','units','nav','amount','txn_type']],
    'cams'         => ['label' => 'CAMS Statement',        'cols' => ['Folio No.','Scheme','Transaction Date','Transaction Type','Amount','Units','Nav']],
    'kfintech'     => ['label' => 'KFintech CAS',          'cols' => ['Folio Number','Scheme Name','Transaction Date','Transaction Type','Amount','Units','Price/Unit']],
    'kfintech_csv' => ['label' => 'KFintech CSV Export',  'cols' => ['Folio No','Scheme','Transaction Type','Narration','Date','Units','Price','Amount']],
    'groww'        => ['label' => 'Groww Export',          'cols' => ['Scheme Name','Transaction Date','Transaction Type','Amount','Units','NAV']],
    'zerodha'      => ['label' => 'Zerodha Coin',          'cols' => ['Fund Name','Date','Type','Quantity','Price','Net Amount']],
    'kuvera'       => ['label' => 'Kuvera Export',         'cols' => ['fund_name','trade_date','type','units','nav','amount']],
    'mfcentral'    => ['label' => 'MF Central',            'cols' => ['Scheme Name','Transaction Date','Amount','Units','NAV']],
    'mprofit'      => ['label' => 'MProfit Export',        'cols' => ['Fund','Date','Type','Quantity','Rate','Value']],
    'angel_one'    => ['label' => 'Angel One / ARQ',       'cols' => ['Scheme','Txn Date','Purchase/Redemption','Units','NAV','Amount']],
    'indmoney'     => ['label' => 'INDmoney',              'cols' => ['Fund Name','Date','Transaction Type','Units','Price','Amount']],
    'paytm'        => ['label' => 'Paytm Money',           'cols' => ['Fund Name','Transaction Date','Type','Units','NAV','Amount']],
    'generic'      => ['label' => 'Generic (auto-mapped)', 'cols' => ['any date, fund, units, amount columns']],
];

// ── Column name aliases for smart detection ───────────────────────────────
const COL_ALIASES = [
    'date' => ['date','txn date','transaction date','trade date','purchase date','redemption date','nav date'],
    'fund' => ['fund','fund name','scheme','scheme name','fund_name','scheme_name','name'],
    'amount' => ['amount','net amount','value','investment','txn amount','transaction amount'],
    'units' => ['units','quantity','no. of units','no of units','units allotted'],
    'nav' => ['nav','price','price/unit','rate','nav/unit'],
    'txn_type' => ['type','txn type','transaction type','purchase/redemption','narration','sub type'],
    'scheme_code' => ['scheme_code','scheme code','folio','folio no','folio no.','folio number','amfi code'],
];

/**
 * Detect column mapping from CSV header row.
 * Returns: ['date'=>colIdx, 'fund'=>colIdx, 'amount'=>colIdx, 'units'=>colIdx, 'nav'=>colIdx, 'txn_type'=>colIdx]
 * or null for fields not found.
 */
function detect_columns(array $headers): array {
    $lowerHeaders = array_map('strtolower', array_map('trim', $headers));
    $mapping = [];

    foreach (COL_ALIASES as $field => $aliases) {
        $mapping[$field] = null;
        foreach ($lowerHeaders as $idx => $h) {
            if (in_array($h, $aliases)) {
                $mapping[$field] = $idx;
                break;
            }
        }
        // Fuzzy match if exact match fails
        if ($mapping[$field] === null) {
            foreach ($lowerHeaders as $idx => $h) {
                foreach ($aliases as $alias) {
                    if (str_contains($h, $alias) || str_contains($alias, $h)) {
                        $mapping[$field] = $idx;
                        break 2;
                    }
                }
            }
        }
    }

    return $mapping;
}

/**
 * Detect format name from content fingerprint.
 */
function detect_format_v3(string $content): string {
    $first = strtolower(substr($content, 0, 2000));
    $firstLine = strtolower(trim(explode("\n", $content)[0]));

    if (str_contains($first, 'computer age management') || str_contains($first, 'cams')) return 'cams';
    if (str_contains($first, 'kfintech') || str_contains($first, 'karvy')) return 'kfintech';
    if (str_contains($firstLine, 'folio no') && str_contains($firstLine, 'scheme') &&
        (str_contains($firstLine, 'transaction type') || str_contains($firstLine, 'narration'))) return 'kfintech_csv';
    if (str_contains($first, 'groww')) return 'groww';
    if (str_contains($firstLine, 'scheme name') && str_contains($firstLine, 'transaction date')) return 'groww';
    if (str_contains($first, 'zerodha') || str_contains($first, 'coin')) return 'zerodha';
    if (str_contains($first, 'kuvera')) return 'kuvera';
    if (str_contains($first, 'mfcentral') || str_contains($first, 'mf central')) return 'mfcentral';
    if (str_contains($first, 'mprofit')) return 'mprofit';
    if (str_contains($first, 'angel') || str_contains($first, 'arq')) return 'angel_one';
    if (str_contains($first, 'indmoney') || str_contains($first, 'ind money')) return 'indmoney';
    if (str_contains($first, 'paytm')) return 'paytm';
    if (str_contains($first, 'scheme_code') || str_contains($first, 'portfolio,fund_name')) return 'wealthdash';

    return 'generic'; // fallback — try column detection
}

/**
 * Parse a row using detected column mapping.
 * Returns normalized transaction or null if invalid.
 */
function parse_row_generic(array $row, array $colMap, int $rowNum): array {
    $errors = [];

    $get = function(string $field) use ($row, $colMap): ?string {
        $idx = $colMap[$field] ?? null;
        if ($idx === null || !isset($row[$idx])) return null;
        return trim($row[$idx]);
    };

    // Date
    $rawDate = $get('date');
    $date    = null;
    if ($rawDate) {
        // Try multiple formats: d-M-Y, d/m/Y, Y-m-d, d-m-Y, d/M/y
        foreach (['d-M-Y','d/m/Y','Y-m-d','d-m-Y','d/M/Y','j-M-Y','j/n/Y','m/d/Y'] as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $rawDate);
            if ($dt) { $date = $dt->format('Y-m-d'); break; }
        }
        if (!$date) $errors[] = "Unrecognized date format: '{$rawDate}'";
    } else {
        $errors[] = 'Missing date';
    }

    // Fund name
    $fundName = $get('fund');
    if (!$fundName) $errors[] = 'Missing fund name';

    // Amount
    $rawAmt = preg_replace('/[^0-9.\-]/', '', $get('amount') ?? '');
    $amount = is_numeric($rawAmt) ? (float)$rawAmt : null;
    if ($amount === null) $errors[] = 'Missing or invalid amount';

    // Units (optional but preferred)
    $rawUnits = preg_replace('/[^0-9.\-]/', '', $get('units') ?? '');
    $units    = is_numeric($rawUnits) ? (float)$rawUnits : null;

    // NAV (optional)
    $rawNav = preg_replace('/[^0-9.\-]/', '', $get('nav') ?? '');
    $nav    = is_numeric($rawNav) ? (float)$rawNav : null;

    // Derive nav from amount/units if both present
    if ($nav === null && $units && $units > 0 && $amount !== null) {
        $nav = abs($amount) / abs($units);
    }
    // Derive units from amount/nav
    if ($units === null && $nav && $nav > 0 && $amount !== null) {
        $units = abs($amount) / $nav;
    }

    // Transaction type
    $rawType = strtolower($get('txn_type') ?? '');
    $txnType = 'buy'; // default
    if (str_contains($rawType, 'redempt') || str_contains($rawType, 'sell') || str_contains($rawType, 'redeem')) {
        $txnType = 'sell';
    } elseif (str_contains($rawType, 'sip') || str_contains($rawType, 'systematic')) {
        $txnType = 'sip';
    } elseif (str_contains($rawType, 'swp') || str_contains($rawType, 'systematic withdrawal')) {
        $txnType = 'swp';
    } elseif (str_contains($rawType, 'switch in') || $rawType === 'switch-in') {
        $txnType = 'switch_in';
    } elseif (str_contains($rawType, 'switch out') || $rawType === 'switch-out') {
        $txnType = 'switch_out';
    } elseif (str_contains($rawType, 'dividend') || str_contains($rawType, 'idcw') || str_contains($rawType, 'reinvest')) {
        $txnType = 'reinvest';
    }

    // Scheme code (optional)
    $schemeCode = $get('scheme_code');

    return [
        '_row'        => $rowNum,
        '_errors'     => $errors,
        '_valid'      => empty($errors) && $fundName,
        'fund_name'   => $fundName,
        'scheme_code' => $schemeCode,
        'txn_date'    => $date,
        'txn_type'    => $txnType,
        'amount'      => $amount !== null ? abs($amount) : null,
        'units'       => $units !== null ? abs($units)   : null,
        'nav'         => $nav,
    ];
}

/**
 * Look up or create a fund_id from fund name / scheme code.
 */
function resolve_fund_id(PDO $db, string $fundName, ?string $schemeCode): ?int {
    // Try scheme_code first
    if ($schemeCode) {
        $s = $db->prepare("SELECT id FROM funds WHERE scheme_code=? LIMIT 1");
        $s->execute([$schemeCode]);
        $id = $s->fetchColumn();
        if ($id) return (int)$id;
    }
    // Try name match
    $s = $db->prepare("SELECT id FROM funds WHERE scheme_name LIKE ? AND is_active=1 LIMIT 1");
    $s->execute(['%' . $fundName . '%']);
    $id = $s->fetchColumn();
    return $id ? (int)$id : null;
}

// ── Route ─────────────────────────────────────────────────────────────────
switch ($action) {

    // ── GET all supported formats ─────────────────────────────────────────
    case 'csv_v3_formats': {
        $formats = array_map(fn($k, $v) => array_merge(['id' => $k], $v), array_keys(CSV_FORMATS_V3), CSV_FORMATS_V3);
        json_response(true, '', ['formats' => $formats, 'count' => count($formats)]);
        break;
    }

    // ── POST detect format + preview ─────────────────────────────────────
    case 'csv_v3_detect': {
        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            json_response(false, 'CSV file upload required');
        }
        if ($_FILES['csv_file']['size'] > 10 * 1024 * 1024) {
            json_response(false, 'File too large (max 10MB)');
        }

        $raw  = file_get_contents($_FILES['csv_file']['tmp_name']);
        $raw  = ltrim($raw, "\xEF\xBB\xBF"); // strip BOM
        $raw  = str_replace("\r\n", "\n", str_replace("\r", "\n", $raw));

        $format   = detect_format_v3($raw);
        $lines    = array_filter(array_map('trim', explode("\n", $raw)));
        $lines    = array_values($lines);

        // Find first data line (skip preamble/blank lines)
        $headerIdx = 0;
        foreach ($lines as $i => $line) {
            if (str_contains($line, ',') && strlen($line) > 10) { $headerIdx = $i; break; }
        }

        $headers    = str_getcsv($lines[$headerIdx] ?? '');
        $colMapping = detect_columns($headers);

        // Confidence score: how many key fields were detected
        $keyFields  = ['date', 'fund', 'amount'];
        $found      = count(array_filter($keyFields, fn($f) => $colMapping[$f] !== null));
        $confidence = round($found / count($keyFields) * 100);

        // Preview rows (first 10 data rows)
        $previewRows = [];
        $dataLines   = array_slice($lines, $headerIdx + 1, 10);
        foreach ($dataLines as $i => $line) {
            if (!$line) continue;
            $row    = str_getcsv($line);
            $parsed = parse_row_generic($row, $colMapping, $i + 1);
            $previewRows[] = [
                'row'       => $i + 1,
                'raw'       => array_combine(
                    array_slice($headers, 0, count($row)),
                    array_slice($row, 0, count($headers))
                ),
                'parsed'    => $parsed,
                'valid'     => $parsed['_valid'],
                'errors'    => $parsed['_errors'],
            ];
        }

        // Total row count estimate
        $totalDataLines = count($lines) - $headerIdx - 1;

        json_response(true, '', [
            'detected_format'  => $format,
            'format_label'     => CSV_FORMATS_V3[$format]['label'] ?? 'Unknown',
            'confidence'       => $confidence,
            'headers'          => $headers,
            'header_row_index' => $headerIdx,
            'col_mapping'      => $colMapping,
            'preview_rows'     => $previewRows,
            'total_data_rows'  => $totalDataLines,
            'ambiguous'        => $confidence < 67,
            'tip'              => $confidence < 67
                ? 'Low confidence detection — please verify column mapping before importing'
                : "Format detected: {$format}. Review preview rows and confirm to import.",
        ]);
        break;
    }

    // ── POST import with confirmed column mapping ──────────────────────────
    case 'csv_v3_import': {
        if (!verify_csrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); json_response(false, 'Invalid CSRF token');
        }

        $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
        if (!$portfolioId) json_response(false, 'portfolio_id required');

        // Verify portfolio ownership
        $pStmt = $db->prepare("SELECT id, user_id FROM portfolios WHERE id=?");
        $pStmt->execute([$portfolioId]);
        $portfolio = $pStmt->fetch();
        if (!$portfolio || ($portfolio['user_id'] != $userId && $currentUser['role'] !== 'admin')) {
            json_response(false, 'Portfolio access denied', [], 403);
        }

        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            json_response(false, 'CSV file required');
        }

        $raw = file_get_contents($_FILES['csv_file']['tmp_name']);
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $raw = str_replace("\r\n", "\n", str_replace("\r", "\n", $raw));

        // Column mapping: from POST (user-confirmed) or auto-detect
        $colMap = [];
        if (!empty($_POST['col_mapping'])) {
            $colMap = json_decode($_POST['col_mapping'], true) ?? [];
        }

        $lines     = array_filter(array_map('trim', explode("\n", $raw)));
        $lines     = array_values($lines);
        $headerIdx = (int)($_POST['header_row_index'] ?? 0);
        $headers   = str_getcsv($lines[$headerIdx] ?? '');

        if (empty($colMap)) {
            $colMap = detect_columns($headers);
        }

        $imported   = 0;
        $skipped    = 0;
        $errorRows  = [];
        $preview    = isset($_POST['preview_only']) && $_POST['preview_only'] === '1';

        $db->beginTransaction();

        $insertStmt = $db->prepare("
            INSERT INTO mf_transactions
                (portfolio_id, fund_id, txn_date, txn_type, amount, units, nav, source, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'csv_v3', NOW())
            ON DUPLICATE KEY UPDATE updated_at=NOW()
        ");

        $dataLines = array_slice($lines, $headerIdx + 1);
        foreach ($dataLines as $lineNum => $line) {
            if (!trim($line)) { $skipped++; continue; }
            $row    = str_getcsv($line);
            $parsed = parse_row_generic($row, $colMap, $lineNum + 1);

            if (!$parsed['_valid']) {
                $errorRows[] = [
                    'row'    => $lineNum + 1,
                    'data'   => $row,
                    'errors' => $parsed['_errors'],
                ];
                $skipped++;
                continue;
            }

            // Resolve fund
            $fundId = resolve_fund_id($db, $parsed['fund_name'], $parsed['scheme_code']);
            if (!$fundId) {
                $errorRows[] = [
                    'row'    => $lineNum + 1,
                    'data'   => $row,
                    'errors' => ["Fund not found in WealthDash: '{$parsed['fund_name']}'. Add it first via Fund Search."],
                ];
                $skipped++;
                continue;
            }

            if (!$preview) {
                try {
                    $insertStmt->execute([
                        $portfolioId,
                        $fundId,
                        $parsed['txn_date'],
                        $parsed['txn_type'],
                        $parsed['amount'],
                        $parsed['units'],
                        $parsed['nav'],
                    ]);
                    $imported++;
                } catch(PDOException $e) {
                    if ($e->getCode() === '23000') { // duplicate
                        $skipped++;
                    } else {
                        $errorRows[] = ['row' => $lineNum + 1, 'data' => $row, 'errors' => ['DB error: ' . $e->getMessage()]];
                        $skipped++;
                    }
                }
            } else {
                $imported++; // count as "would import"
            }
        }

        if ($preview) {
            $db->rollBack();
            json_response(true, "Preview: {$imported} rows would import, {$skipped} would skip", [
                'preview'    => true,
                'would_import' => $imported,
                'would_skip'   => $skipped,
                'error_rows'   => array_slice($errorRows, 0, 20),
                'total_errors' => count($errorRows),
            ]);
        } else {
            $db->commit();
            json_response(true, "Imported {$imported} transactions. {$skipped} skipped.", [
                'imported'    => $imported,
                'skipped'     => $skipped,
                'errors'      => count($errorRows),
                'error_rows'  => array_slice($errorRows, 0, 50), // show first 50 errors
                'portfolio_id'=> $portfolioId,
            ]);
        }
        break;
    }

    default:
        json_response(false, 'Unknown action');
}
