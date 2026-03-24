<?php
/**
 * WealthDash — t148: CAMS + KFintech CAS Auto-Import
 * Handles: PDF text extraction, CAS parsing, duplicate detection, bulk import
 *
 * Actions:
 *   cas_parse   — parse uploaded file, return preview (no DB write)
 *   cas_import  — commit parsed transactions to DB
 *   cas_status  — get last import status for user
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

// ── Route ───────────────────────────────────────────────────────────────────
if ($action === 'cas_parse')   { cas_handle_parse($userId, $isAdmin);  exit; }
if ($action === 'cas_import')  { cas_handle_import($userId, $isAdmin); exit; }
if ($action === 'cas_status')  { cas_handle_status($userId);           exit; }

// ════════════════════════════════════════════════════════════════════════════
// PARSE — extract transactions from uploaded file, return preview
// ════════════════════════════════════════════════════════════════════════════
function cas_handle_parse(int $userId, bool $isAdmin): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(false, 'POST required'); exit;
    }

    $file    = $_FILES['cas_file']   ?? null;
    $format  = clean($_POST['format'] ?? 'auto');
    $portId  = (int)($_POST['portfolio_id'] ?? 0);

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        json_response(false, 'File upload failed. Error: ' . ($file['error'] ?? 'unknown'));
    }

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $size = $file['size'];

    if ($size > 10 * 1024 * 1024) json_response(false, 'File too large (max 10MB)');
    if (!in_array($ext, ['pdf','txt','csv'])) json_response(false, 'Only PDF, TXT, CSV allowed');

    // Extract text content
    $content = '';
    if ($ext === 'pdf') {
        $content = cas_extract_pdf_text($file['tmp_name']);
        if (!$content) {
            json_response(false, 'PDF text extraction failed. Try saving as text from your PDF viewer, then upload the .txt file.');
        }
    } else {
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) json_response(false, 'Could not read file');
    }

    // Detect + parse
    if ($format === 'auto') $format = cas_detect_format($content);
    $transactions = cas_parse_content($content, $format);

    if (empty($transactions)) {
        json_response(false, "No transactions found in file. Detected format: {$format}. Make sure you're uploading a CAS statement (not a NAV file).");
    }

    // Dedup check against existing transactions
    $existing = cas_get_existing_fingerprints($userId);
    $dupes    = 0;
    $fresh    = 0;
    foreach ($transactions as &$txn) {
        $fp = cas_fingerprint($txn);
        $txn['fingerprint'] = $fp;
        $txn['is_duplicate'] = isset($existing[$fp]);
        if ($txn['is_duplicate']) $dupes++;
        else $fresh++;
    }
    unset($txn);

    // Fund name → fund_id mapping preview
    $fundMap = cas_preview_fund_map($transactions);

    json_response(true, "Parsed {$format} format. {$fresh} new, {$dupes} duplicates.", [
        'format'         => $format,
        'total'          => count($transactions),
        'new_count'      => $fresh,
        'duplicate_count'=> $dupes,
        'transactions'   => array_slice($transactions, 0, 50), // preview: first 50
        'fund_map'       => $fundMap,
        'portfolio_id'   => $portId,
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
// IMPORT — commit non-duplicate transactions to DB
// ════════════════════════════════════════════════════════════════════════════
function cas_handle_import(int $userId, bool $isAdmin): void {
    $portId = (int)($_POST['portfolio_id'] ?? 0);
    $txnsJson = $_POST['transactions'] ?? '';
    if (!$portId || !$txnsJson) json_response(false, 'portfolio_id and transactions required');

    $txns = json_decode($txnsJson, true);
    if (!is_array($txns) || empty($txns)) json_response(false, 'Invalid transactions data');

    // Verify portfolio access
    if (!can_access_portfolio($portId, $userId, $isAdmin)) {
        json_response(false, 'Access denied to portfolio');
    }

    $db       = DB::conn();
    $imported = 0; $failed = 0; $dupes = 0;
    $errors   = [];

    // Get existing fingerprints again (fresh)
    $existing = cas_get_existing_fingerprints($userId);

    $db->beginTransaction();
    try {
        foreach ($txns as $txn) {
            if (!empty($txn['is_duplicate'])) { $dupes++; continue; }

            $fp = $txn['fingerprint'] ?? cas_fingerprint($txn);
            if (isset($existing[$fp])) { $dupes++; continue; }

            // Resolve fund_id
            $fundId = cas_resolve_fund_id($txn['fund_name'] ?? '', $txn['isin'] ?? '');
            if (!$fundId) {
                $errors[] = "Fund not found: " . substr($txn['fund_name'] ?? '', 0, 50);
                $failed++;
                continue;
            }

            // Resolve folio → get/create folio record
            $folio = trim($txn['folio'] ?? '');

            // Normalize date
            $txnDate = normalize_date_cas($txn['txn_date'] ?? '');
            if (!$txnDate) { $failed++; $errors[] = "Bad date: " . ($txn['txn_date'] ?? ''); continue; }

            $units  = (float) str_replace(',', '', $txn['units']  ?? 0);
            $nav    = (float) str_replace(',', '', $txn['nav']    ?? 0);
            $amount = (float) str_replace(',', '', $txn['amount'] ?? ($units * $nav));
            $type   = normalize_txn_type($txn['txn_type'] ?? 'BUY');

            if ($units <= 0 && !in_array($type, ['SWITCH_OUT','SELL'])) {
                $failed++; continue;
            }

            // Insert transaction
            DB::run(
                "INSERT INTO mf_transactions
                    (portfolio_id, fund_id, txn_type, txn_date, units, nav, amount,
                     folio_number, import_source, import_fingerprint, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
                [
                    $portId, $fundId, $type, $txnDate,
                    $units, $nav, $amount,
                    $folio,
                    $txn['platform'] ?? 'CAS',
                    $fp,
                ]
            );
            $imported++;
            $existing[$fp] = true; // prevent within-batch dupes
        }

        // Recalculate holdings after import
        if ($imported > 0) {
            cas_recalculate_holdings($portId);
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, 'Import failed: ' . $e->getMessage());
    }

    // Log import
    try {
        DB::run(
            "INSERT INTO import_logs (user_id, portfolio_id, source, imported_count, failed_count, imported_at)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE imported_at=NOW()",
            [$userId, $portId, 'CAS', $imported, $failed]
        );
    } catch (Exception $e) { /* table may not exist */ }

    json_response(true, "Import complete.", [
        'imported'   => $imported,
        'duplicates' => $dupes,
        'failed'     => $failed,
        'errors'     => array_slice($errors, 0, 10),
    ]);
}

function cas_handle_status(int $userId): void {
    try {
        $row = DB::fetchOne(
            "SELECT source, imported_count, failed_count, imported_at
             FROM import_logs WHERE user_id=? ORDER BY imported_at DESC LIMIT 1",
            [$userId]
        );
        json_response(true, '', $row ?: []);
    } catch (Exception $e) {
        json_response(true, '', []);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// PDF TEXT EXTRACTION (no external lib needed — PHP native)
// ════════════════════════════════════════════════════════════════════════════
function cas_extract_pdf_text(string $filepath): string {
    // Method 1: Try to extract text directly from PDF binary
    // PDF stores text in streams — we do basic extraction
    $content = file_get_contents($filepath);
    if ($content === false) return '';

    $text = '';
    // Extract text between BT (begin text) and ET (end text) markers
    // Also handle Tj, TJ operators
    preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $content, $m1);
    preg_match_all('/\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\s*TJ/s', $content, $m2);

    foreach ($m1[1] as $s) {
        $text .= cas_pdf_unescape($s) . ' ';
    }
    foreach ($m2[1] as $s) {
        preg_match_all('/\(([^)]*)\)/', $s, $m3);
        foreach ($m3[1] as $p) {
            $text .= cas_pdf_unescape($p);
        }
        $text .= ' ';
    }

    // Clean up
    $text = preg_replace('/\s+/', ' ', $text);
    $text = preg_replace('/[^\x20-\x7E\n]/', '', $text);

    // Method 2: Look for plain text in PDF (some PDFs are text-based)
    if (strlen(trim($text)) < 100) {
        // Try to find readable lines
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $clean = preg_replace('/[^\x20-\x7E]/', '', $line);
            if (strlen(trim($clean)) > 10) {
                $text .= $clean . "\n";
            }
        }
    }

    return $text;
}

function cas_pdf_unescape(string $s): string {
    return str_replace(['\\n','\\r','\\t','\\\\','\\(','\\)'], ["\n","\r","\t",'\\','(',')'], $s);
}

// ════════════════════════════════════════════════════════════════════════════
// FORMAT DETECTION
// ════════════════════════════════════════════════════════════════════════════
function cas_detect_format(string $content): string {
    $lower = strtolower(substr($content, 0, 3000));

    if (strpos($lower, 'consolidated account statement') !== false
        || strpos($lower, 'cams') !== false
        || strpos($lower, 'computer age management') !== false) {
        return 'cams_cas';
    }
    if (strpos($lower, 'kfintech') !== false
        || strpos($lower, 'karvy') !== false
        || strpos($lower, 'k fin') !== false) {
        return 'kfintech_cas';
    }
    if (strpos($lower, 'mf utility') !== false || strpos($lower, 'mfu') !== false) {
        return 'mfu';
    }
    if (strpos($lower, 'groww') !== false) return 'groww';
    if (strpos($lower, 'zerodha') !== false || strpos($lower, 'trade_date') !== false) return 'zerodha';
    if (strpos($lower, 'kuvera') !== false) return 'kuvera';
    // Generic CSV check
    $firstLine = strtolower(trim(explode("\n", $content)[0] ?? ''));
    if (strpos($firstLine, 'fund name') !== false || strpos($firstLine, 'scheme') !== false) {
        return 'csv_generic';
    }
    return 'cams_cas'; // default
}

// ════════════════════════════════════════════════════════════════════════════
// ENHANCED PARSERS
// ════════════════════════════════════════════════════════════════════════════
function cas_parse_content(string $content, string $format): array {
    $lines = array_filter(array_map('trim', explode("\n", $content)));
    $lines = array_values($lines);

    return match($format) {
        'cams_cas'     => cas_parse_cams($lines),
        'kfintech_cas' => cas_parse_kfintech($lines),
        'mfu'          => cas_parse_mfu($lines),
        'groww'        => cas_parse_csv_generic($lines, 'groww'),
        'zerodha'      => cas_parse_csv_generic($lines, 'zerodha'),
        'kuvera'       => cas_parse_csv_generic($lines, 'kuvera'),
        default        => cas_parse_cams($lines), // try CAMS as default
    };
}

function cas_parse_cams(array $lines): array {
    $txns = []; $fund = ''; $folio = ''; $isin = ''; $pan = '';

    foreach ($lines as $i => $line) {
        // PAN
        if (preg_match('/PAN\s*[:\-]\s*([A-Z]{5}[0-9]{4}[A-Z])/i', $line, $m)) {
            $pan = strtoupper(trim($m[1]));
        }
        // Folio: "Folio No: 1234567890 / 0" or "Folio: 1234567890"
        if (preg_match('/Folio(?:\s*No)?[:\s]+([0-9]+(?:\s*\/\s*[0-9]+)?)/i', $line, $m)) {
            $folio = trim($m[1]);
        }
        // ISIN: "ISIN: INF204K01I26"
        if (preg_match('/ISIN[:\s]+([A-Z]{2}[A-Z0-9]{10})/i', $line, $m)) {
            $isin = strtoupper(trim($m[1]));
        }
        // Fund name — line ending with (Dividend|Growth|Direct|Regular|IDCW)
        if (preg_match('/^(.+(?:Fund|Scheme|Growth|Direct|Regular|IDCW|Dividend|STP|SWP).*)\s*$/i', $line, $m)
            && strlen($line) > 15 && !preg_match('/^\d/', $line)) {
            $candidate = trim($m[1]);
            // Exclude header lines
            if (!preg_match('/folio|date|amount|units|nav|balance|transaction/i', $candidate)) {
                $fund = $candidate;
            }
        }
        // Transaction line patterns:
        // DD-Mon-YYYY   Type                  Amount(+/-)    Units      NAV       Balance
        // 01-Jan-2024   Purchase (SIP)         5,000.00      45.678    109.452    45.678
        if (preg_match(
            '/^(\d{2}-(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)-\d{4})\s+(.+?)\s+([\-\d,]+\.?\d*)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)/i',
            $line, $m
        )) {
            $amount = (float)str_replace([',', ' '], '', $m[3]);
            $units  = (float)str_replace(',', '', $m[4]);
            $nav    = (float)str_replace(',', '', $m[5]);
            $type   = map_cams_type_enhanced($m[2]);

            if ($nav > 0 || $units > 0) {
                $txns[] = [
                    'fund_name' => $fund,
                    'folio'     => $folio,
                    'isin'      => $isin,
                    'pan'       => $pan,
                    'txn_type'  => $type,
                    'txn_date'  => $m[1],
                    'amount'    => abs($amount),
                    'units'     => $units,
                    'nav'       => $nav,
                    'raw_type'  => trim($m[2]),
                    'platform'  => 'CAMS',
                ];
            }
        }
    }
    return $txns;
}

function cas_parse_kfintech(array $lines): array {
    $txns = []; $fund = ''; $folio = ''; $isin = ''; $pan = '';

    foreach ($lines as $line) {
        if (preg_match('/PAN\s*[:\-]\s*([A-Z]{5}[0-9]{4}[A-Z])/i', $line, $m)) $pan = strtoupper($m[1]);
        if (preg_match('/Folio\s*(?:No)?[:\s]+([0-9\/\s]+)/i', $line, $m)) $folio = trim($m[1]);
        if (preg_match('/ISIN[:\s]+([A-Z]{2}[A-Z0-9]{10})/i', $line, $m)) $isin = strtoupper(trim($m[1]));
        if (preg_match('/^Scheme\s*[:\-]\s*(.+)$/i', $line, $m)) $fund = trim($m[1]);

        // KFintech transaction: DD/MM/YYYY  Type  Amount  Units  NAV  Balance
        if (preg_match(
            '/^(\d{2}[\/\-]\d{2}[\/\-]\d{4})\s+(.+?)\s+([\-\d,]+\.?\d*)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)/',
            $line, $m
        )) {
            $txns[] = [
                'fund_name' => $fund,
                'folio'     => $folio,
                'isin'      => $isin,
                'pan'       => $pan,
                'txn_type'  => map_cams_type_enhanced($m[2]),
                'txn_date'  => $m[1],
                'amount'    => abs((float)str_replace([',',' '],'',$m[3])),
                'units'     => (float)str_replace(',','',$m[4]),
                'nav'       => (float)str_replace(',','',$m[5]),
                'raw_type'  => trim($m[2]),
                'platform'  => 'KFintech',
            ];
        }
    }
    return $txns;
}

function cas_parse_mfu(array $lines): array {
    // MFU format similar to CAMS but different headers
    $txns = []; $fund = ''; $folio = '';
    foreach ($lines as $line) {
        if (preg_match('/Scheme\s*:\s*(.+)/i', $line, $m)) $fund = trim($m[1]);
        if (preg_match('/Folio\s*:\s*([0-9]+)/i', $line, $m)) $folio = trim($m[1]);
        if (preg_match('/^(\d{2}-\w{3}-\d{4})\s+(.+?)\s+([\d,]+\.?\d*)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)/', $line, $m)) {
            $txns[] = [
                'fund_name' => $fund, 'folio' => $folio,
                'txn_type'  => map_cams_type_enhanced($m[2]),
                'txn_date'  => $m[1],
                'amount'    => (float)str_replace(',','',$m[3]),
                'units'     => (float)str_replace(',','',$m[4]),
                'nav'       => (float)str_replace(',','',$m[5]),
                'platform'  => 'MFU',
            ];
        }
    }
    return $txns;
}

function cas_parse_csv_generic(array $lines, string $platform): array {
    $result = []; $header = null;
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        $cols = str_getcsv($line);
        if (!$header) {
            $header = array_map('strtolower', array_map('trim', $cols));
            continue;
        }
        if (count($cols) < 3) continue;
        $r = array_combine($header, array_pad($cols, count($header), ''));
        $result[] = [
            'fund_name' => trim($r['fund name'] ?? $r['scheme name'] ?? $r['fund'] ?? $r['scheme'] ?? ''),
            'folio'     => trim($r['folio'] ?? $r['folio no'] ?? ''),
            'txn_type'  => normalize_txn_type($r['type'] ?? $r['transaction type'] ?? 'BUY'),
            'txn_date'  => trim($r['date'] ?? $r['trade_date'] ?? $r['transaction date'] ?? ''),
            'amount'    => (float)str_replace(',','', $r['amount'] ?? 0),
            'units'     => (float)str_replace(',','', $r['units'] ?? 0),
            'nav'       => (float)str_replace(',','', $r['nav'] ?? $r['price'] ?? 0),
            'isin'      => trim($r['isin'] ?? ''),
            'platform'  => ucfirst($platform),
        ];
    }
    return $result;
}

function map_cams_type_enhanced(string $type): string {
    $type = strtolower(trim($type));
    // Purchase variations
    if (preg_match('/purchase|sip|buy|subscription|new registration|installment/i', $type)) return 'BUY';
    // Redemption variations
    if (preg_match('/redemption|redeem|sell|withdrawal|swp/i', $type)) return 'SELL';
    // Dividend
    if (preg_match('/dividend|idcw|payout/i', $type)) {
        if (preg_match('/reinvest/i', $type)) return 'DIV_REINVEST';
        return 'SELL'; // Dividend payout = units reduced
    }
    // Switch
    if (preg_match('/switch.*in/i', $type)) return 'SWITCH_IN';
    if (preg_match('/switch.*out/i', $type)) return 'SWITCH_OUT';
    if (preg_match('/switch/i', $type)) return 'BUY';
    // STP
    if (preg_match('/stp.*in/i', $type)) return 'SWITCH_IN';
    if (preg_match('/stp.*out/i', $type)) return 'SWITCH_OUT';
    // Bonus
    if (preg_match('/bonus/i', $type)) return 'BUY';
    return 'BUY';
}

// ════════════════════════════════════════════════════════════════════════════
// FUND MATCHING — fuzzy match fund name to DB
// ════════════════════════════════════════════════════════════════════════════
function cas_resolve_fund_id(string $fundName, string $isin = ''): ?int {
    static $cache = [];
    $key = md5($isin . '|' . $fundName);
    if (isset($cache[$key])) return $cache[$key];

    // Try ISIN first (most reliable)
    if ($isin) {
        $row = DB::fetchOne("SELECT id FROM funds WHERE isin_growth=? OR isin_div=? LIMIT 1", [$isin, $isin]);
        if ($row) { $cache[$key] = (int)$row['id']; return $cache[$key]; }
    }

    // Exact scheme name match
    if ($fundName) {
        $row = DB::fetchOne("SELECT id FROM funds WHERE scheme_name=? LIMIT 1", [$fundName]);
        if ($row) { $cache[$key] = (int)$row['id']; return $cache[$key]; }
    }

    // Fuzzy match — normalize fund name and use LIKE
    if ($fundName) {
        // Extract key words (remove common suffixes)
        $normalized = preg_replace('/\b(direct|regular|growth|dividend|idcw|plan|option|series)\b/i', '', $fundName);
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));
        $words = explode(' ', $normalized);
        // Use first 3 significant words
        $searchWords = array_filter($words, fn($w) => strlen($w) > 3);
        $searchWords = array_slice($searchWords, 0, 3);

        if ($searchWords) {
            $like = '%' . implode('%', $searchWords) . '%';
            $row = DB::fetchOne("SELECT id FROM funds WHERE scheme_name LIKE ? LIMIT 1", [$like]);
            if ($row) { $cache[$key] = (int)$row['id']; return $cache[$key]; }
        }

        // Last resort: first significant word
        if (count($searchWords) > 0) {
            $like = '%' . $searchWords[array_key_first($searchWords)] . '%';
            $rows = DB::fetchAll("SELECT id, scheme_name FROM funds WHERE scheme_name LIKE ? LIMIT 5", [$like]);
            if ($rows) {
                // Pick best match using similar_text
                $best = null; $bestScore = 0;
                foreach ($rows as $r) {
                    similar_text(strtolower($fundName), strtolower($r['scheme_name']), $pct);
                    if ($pct > $bestScore) { $bestScore = $pct; $best = $r['id']; }
                }
                if ($bestScore > 50) { $cache[$key] = (int)$best; return $cache[$key]; }
            }
        }
    }

    $cache[$key] = null;
    return null;
}

function cas_preview_fund_map(array $txns): array {
    $fundNames = array_unique(array_column($txns, 'fund_name'));
    $map = [];
    foreach ($fundNames as $name) {
        $isin = '';
        foreach ($txns as $t) { if ($t['fund_name'] === $name && !empty($t['isin'])) { $isin = $t['isin']; break; } }
        $fundId = cas_resolve_fund_id($name, $isin);
        $map[$name] = [
            'fund_id'    => $fundId,
            'matched'    => $fundId !== null,
            'fund_name'  => $name,
        ];
    }
    return array_values($map);
}

// ════════════════════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════════════════════
function cas_fingerprint(array $txn): string {
    // Unique fingerprint: fund_name + date + units + nav (enough to detect dupes)
    return md5(
        strtolower(trim($txn['fund_name'] ?? '')) . '|' .
        ($txn['txn_date'] ?? '') . '|' .
        round((float)($txn['units'] ?? 0), 3) . '|' .
        round((float)($txn['nav']   ?? 0), 4)
    );
}

function cas_get_existing_fingerprints(int $userId): array {
    try {
        $rows = DB::fetchAll(
            "SELECT DISTINCT import_fingerprint FROM mf_transactions
             WHERE portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)
               AND import_fingerprint IS NOT NULL",
            [$userId]
        );
        return array_fill_keys(array_column($rows, 'import_fingerprint'), true);
    } catch (Exception $e) {
        return [];
    }
}

function normalize_date_cas(string $d): ?string {
    $d = trim($d);
    if (empty($d)) return null;
    // DD-Mon-YYYY (CAMS)
    $dt = DateTime::createFromFormat('d-M-Y', $d);
    if ($dt) return $dt->format('Y-m-d');
    // DD/MM/YYYY (KFintech)
    $dt = DateTime::createFromFormat('d/m/Y', $d);
    if ($dt) return $dt->format('Y-m-d');
    // DD-MM-YYYY
    $dt = DateTime::createFromFormat('d-m-Y', $d);
    if ($dt) return $dt->format('Y-m-d');
    // YYYY-MM-DD already
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
    return null;
}

function cas_recalculate_holdings(int $portId): void {
    // Trigger holdings recalculation using existing calculator
    try {
        require_once APP_ROOT . '/includes/holding_calculator.php';
        recalculate_holdings($portId);
    } catch (Exception $e) {
        // Non-fatal — holdings can be recalculated separately
    }
}
