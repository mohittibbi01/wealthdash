<?php
/**
 * WealthDash — MF CSV Import
 * POST /api/mutual_funds/mf_import_csv.php (multipart/form-data)
 * Fields: portfolio_id, csv_format (auto|wealthdash|cams|kfintech|groww), csrf_token
 * File:   csv_file
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/holding_calculator.php';

header('Content-Type: application/json');
$currentUser = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false,'message'=>'POST only']); exit;
}
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Invalid CSRF']); exit;
}

$portfolio_id = (int)($_POST['portfolio_id'] ?? 0);
$csv_format   = strtolower(trim($_POST['csv_format'] ?? 'auto'));

if ($portfolio_id <= 0) {
    echo json_encode(['success'=>false,'message'=>'portfolio_id required']); exit;
}
if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'message'=>'CSV file upload failed']); exit;
}

$ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','txt'])) {
    echo json_encode(['success'=>false,'message'=>'Only CSV/TXT files allowed']); exit;
}
if ($_FILES['csv_file']['size'] > 5*1024*1024) {
    echo json_encode(['success'=>false,'message'=>'File too large (max 5MB)']); exit;
}

$tmpPath = $_FILES['csv_file']['tmp_name'];
$content = file_get_contents($tmpPath);
// Handle BOM
$content = ltrim($content, "\xEF\xBB\xBF");

// Auto-detect format
if ($csv_format === 'auto') {
    $csv_format = detect_csv_format($content);
}

try {
    $transactions = parse_csv($content, $csv_format);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>'Parse error: '.$e->getMessage()]); exit;
}
$content_original = $content; // keep for annotated CSV

if (empty($transactions)) {
    echo json_encode(['success'=>false,'message'=>'No valid transactions found in CSV']); exit;
}

try {
    $db = DB::conn();

    // Verify portfolio access
    $pStmt = $db->prepare("SELECT id, user_id FROM portfolios WHERE id=?");
    $pStmt->execute([$portfolio_id]);
    $portfolio = $pStmt->fetch();
    if (!$portfolio || ($portfolio['user_id'] != $currentUser['id'] && $currentUser['role'] !== 'admin')) {
        echo json_encode(['success'=>false,'message'=>'Portfolio access denied']); exit;
    }

    $imported = 0; $skipped = 0; $errors_list = [];
    $row_results = []; // track per-row status for annotated CSV

    $db->beginTransaction();

    foreach ($transactions as $idx => $row) {
        try {
            // Lookup fund by scheme_code or name
            $fund = find_fund($db, $row);
            if (!$fund) {
                $reason = "Fund not found in database: \"" . $row['fund_name'] . "\"";
                $skipped++;
                $errors_list[] = "Row ".($idx+2).": ".$reason;
                $row_results[$idx] = ['status'=>'SKIPPED','reason'=>$reason,'matched_fund'=>''];
                continue;
            }

            $txn_date = normalize_date($row['txn_date'] ?? '');
            if (!$txn_date) {
                $reason = "Invalid date format: " . ($row['txn_date'] ?? '');
                $skipped++;
                $errors_list[] = "Row ".($idx+2).": ".$reason;
                $row_results[$idx] = ['status'=>'SKIPPED','reason'=>$reason,'matched_fund'=>$fund['scheme_name']];
                continue;
            }

            // Block future dates
            $tz = new DateTimeZone('Asia/Kolkata');
            $todayIST = (new DateTime('now', $tz))->format('Y-m-d');
            if ($txn_date > $todayIST) {
                $reason = "Future date ({$txn_date}) not allowed";
                $skipped++;
                $errors_list[] = "Row ".($idx+2).": ".$reason;
                $row_results[$idx] = ['status'=>'SKIPPED','reason'=>$reason,'matched_fund'=>$fund['scheme_name']];
                continue;
            }

            $units = (float)($row['units'] ?? 0);
            $nav   = (float)($row['nav'] ?? 0);
            if ($units <= 0 || $nav <= 0) {
                $reason = "Invalid units ({$units}) or NAV ({$nav})";
                $skipped++;
                $errors_list[] = "Row ".($idx+2).": ".$reason;
                $row_results[$idx] = ['status'=>'SKIPPED','reason'=>$reason,'matched_fund'=>$fund['scheme_name']];
                continue;
            }

            $txn_type = normalize_txn_type($row['txn_type'] ?? 'BUY');
            $value_at_cost = round($units * $nav, 4);
            $investment_fy = get_investment_fy($txn_date);

            // ── Folio auto-resolve ─────────────────────────────────────
            // 1) Use folio from CSV if present
            // 2) Else look up existing transaction for same fund+portfolio
            // 3) Else auto-generate a placeholder folio
            $folio = trim($row['folio'] ?? '');
            if (empty($folio)) {
                $fStmt = $db->prepare("
                    SELECT folio_number FROM mf_transactions
                    WHERE portfolio_id=? AND fund_id=? AND folio_number IS NOT NULL
                      AND folio_number != ''
                    ORDER BY txn_date ASC LIMIT 1
                ");
                $fStmt->execute([$portfolio_id, $fund['id']]);
                $existingFolio = $fStmt->fetchColumn();
                if ($existingFolio) {
                    $folio = $existingFolio;
                } else {
                    // Generate placeholder: AUTO-{portfolio_id}-{fund_id}
                    $folio = 'AUTO-' . $portfolio_id . '-' . $fund['id'];
                }
            }

            // Check duplicate
            $dupStmt = $db->prepare("
                SELECT id FROM mf_transactions
                WHERE portfolio_id=? AND fund_id=? AND txn_date=?
                  AND units=? AND nav=? AND transaction_type=?
                LIMIT 1
            ");
            $dupStmt->execute([$portfolio_id, $fund['id'], $txn_date, $units, $nav, $txn_type]);
            if ($dupStmt->fetch()) {
                $skipped++;
                $row_results[$idx] = ['status'=>'SKIPPED','reason'=>'Duplicate transaction (already imported)','matched_fund'=>$fund['scheme_name']];
                continue;
            }

            // Check units availability for SELL/SWITCH_OUT
            if (in_array($txn_type, ['SELL', 'SWITCH_OUT'])) {
                // $folio already resolved above

                // Units bought ON OR BEFORE sell date (tax harvesting: same-day sell allowed
                // if units were purchased on earlier dates)
                $bQ = $db->prepare("
                    SELECT COALESCE(SUM(units),0) FROM mf_transactions
                    WHERE portfolio_id=? AND fund_id=? AND folio_number<=>?
                      AND transaction_type IN ('BUY','SWITCH_IN','DIV_REINVEST')
                      AND txn_date <= ?
                ");
                $bQ->execute([$portfolio_id, $fund['id'], $folio, $txn_date]);
                $bought = (float)$bQ->fetchColumn();

                // Already sold up to (but not including) this transaction
                $sQ = $db->prepare("
                    SELECT COALESCE(SUM(units),0) FROM mf_transactions
                    WHERE portfolio_id=? AND fund_id=? AND folio_number<=>?
                      AND transaction_type IN ('SELL','SWITCH_OUT')
                      AND txn_date < ?
                ");
                $sQ->execute([$portfolio_id, $fund['id'], $folio, $txn_date]);
                $sold = (float)$sQ->fetchColumn();

                $available = round($bought - $sold, 6);
                if ($available <= 0) {
                    $reason = "No units available to sell on {$txn_date} — ensure BUY transactions exist before this date";
                    $skipped++;
                    $errors_list[] = "Row ".($idx+2).": ".$reason;
                    $row_results[$idx] = ['status'=>'SKIPPED','reason'=>$reason,'matched_fund'=>$fund['scheme_name']];
                    continue;
                }
                if ($units > $available + 0.001) {
                    $reason = "Cannot sell {$units} units — only ".number_format($available,4)." available on {$txn_date}";
                    $skipped++;
                    $errors_list[] = "Row ".($idx+2).": ".$reason;
                    $row_results[$idx] = ['status'=>'SKIPPED','reason'=>$reason,'matched_fund'=>$fund['scheme_name']];
                    continue;
                }
            }

            $ins = $db->prepare("
                INSERT INTO mf_transactions
                (portfolio_id, fund_id, folio_number, transaction_type, platform,
                 txn_date, units, nav, value_at_cost, investment_fy, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");
            $ins->execute([
                $portfolio_id, $fund['id'],
                $folio,
                $txn_type,
                $row['platform'] ?? 'CSV Import',
                $txn_date, $units, $nav, $value_at_cost,
                $investment_fy,
                $row['notes'] ?? ''
            ]);
            $imported++;
            $row_results[$idx] = ['status'=>'IMPORTED','reason'=>'','matched_fund'=>$fund['scheme_name']];

        } catch (Exception $rowEx) {
            $skipped++;
            $reason = $rowEx->getMessage();
            $errors_list[] = "Row ".($idx+2).": ".$reason;
            $row_results[$idx] = ['status'=>'SKIPPED','reason'=>$reason,'matched_fund'=>''];
        }
    }

    // Recalculate holdings for all affected fund+folio combos
    $holdingStmt = $db->prepare("
        SELECT DISTINCT fund_id, folio_number FROM mf_transactions WHERE portfolio_id=?
    ");
    $holdingStmt->execute([$portfolio_id]);
    foreach ($holdingStmt->fetchAll() as $h) {
        recalculate_mf_holdings($db, $portfolio_id, $h['fund_id'], $h['folio_number']);
    }

    audit_log_pdo($db, $currentUser['id'], 'mf_csv_import',
              "portfolio:$portfolio_id", "imported:$imported,skipped:$skipped");

    $db->commit();

    // Build annotated CSV and store in session for download
    $annotated_csv = build_annotated_csv($transactions, $row_results, $content_original ?? '');
    $csv_filename  = 'import_result_' . date('Y-m-d_His') . '.csv';
    $token         = bin2hex(random_bytes(16));
    $session_key   = 'import_result_' . $token;
    $_SESSION[$session_key] = [
        'csv'      => $annotated_csv,
        'filename' => $csv_filename,
        'expires'  => time() + 3600,
    ];

    echo json_encode([
        'success'      => true,
        'format'       => $csv_format,
        'total'        => count($transactions),
        'imported'     => $imported,
        'skipped'      => $skipped,
        'errors'       => array_slice($errors_list, 0, 20),
        'message'      => "$imported transactions imported, $skipped skipped",
        'download_token' => $token,
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}

// ════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ════════════════════════════════════════════════════════════

function detect_csv_format(string $content): string {
    $first = strtolower(substr($content, 0, 500));
    if (strpos($first, 'computer age management') !== false ||
        strpos($first, 'cams') !== false) return 'cams';
    if (strpos($first, 'kfintech') !== false ||
        strpos($first, 'karvy') !== false) return 'kfintech';
    if (strpos($first, 'groww') !== false) return 'groww';
    if (strpos($first, 'zerodha') !== false) return 'zerodha';
    if (strpos($first, 'portfolio,fund_name') !== false ||
        strpos($first, 'scheme_code') !== false) return 'wealthdash';
    return 'wealthdash'; // default
}

function parse_csv(string $content, string $format): array {
    $lines = array_filter(array_map('trim', explode("\n", $content)));

    return match($format) {
        'cams'       => parse_cams($lines),
        'kfintech'   => parse_kfintech($lines),
        'groww'      => parse_groww($lines),
        'wealthdash' => parse_wealthdash($lines),
        default      => parse_wealthdash($lines),
    };
}

function parse_wealthdash(array $lines): array {
    // Headers: portfolio,fund_name,scheme_code,folio,platform,txn_type,txn_date,units,nav,notes
    $result = []; $header = null;
    foreach ($lines as $line) {
        if (empty($line) || $line[0] === '#') continue;
        $cols = str_getcsv($line);
        if (!$header) {
            $header = array_map('strtolower', array_map('trim', $cols));
            continue;
        }
        $r = array_combine($header, array_pad($cols, count($header), ''));
        $result[] = [
            'fund_name'  => $r['fund_name'] ?? '',
            'scheme_code'=> $r['scheme_code'] ?? '',
            'folio'      => $r['folio'] ?? '',
            'platform'   => $r['platform'] ?? '',
            'txn_type'   => $r['txn_type'] ?? 'BUY',
            'txn_date'   => $r['txn_date'] ?? '',
            'units'      => $r['units'] ?? 0,
            'nav'        => $r['nav'] ?? 0,
            'notes'      => $r['notes'] ?? '',
        ];
    }
    return $result;
}

function parse_cams(array $lines): array {
    // CAMS CAS statement parser
    $result = []; $currentFund = ''; $currentFolio = '';
    foreach ($lines as $line) {
        // Folio line: "Folio No: 12345678 / 0"
        if (preg_match('/Folio No[:\s]+([0-9\/\s]+)/i', $line, $m)) {
            $currentFolio = trim($m[1]);
            continue;
        }
        // Fund name line (usually before transactions)
        if (preg_match('/^([A-Z].*(?:Fund|Growth|Dividend|Direct|Regular).*)$/i', $line, $m)) {
            $currentFund = trim($m[1]);
            continue;
        }
        // Transaction line: DD-Mon-YYYY  Type  Amount  Units  NAV  Balance
        if (preg_match('/^(\d{2}-\w{3}-\d{4})\s+(.+?)\s+([\d,]+\.?\d*)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)/', $line, $m)) {
            $result[] = [
                'fund_name' => $currentFund,
                'folio'     => $currentFolio,
                'txn_type'  => map_cams_type($m[2]),
                'txn_date'  => $m[1],
                'units'     => str_replace(',', '', $m[4]),
                'nav'       => str_replace(',', '', $m[5]),
                'platform'  => 'CAMS',
            ];
        }
    }
    return $result;
}

function parse_kfintech(array $lines): array {
    $result = []; $currentFund = ''; $currentFolio = '';
    foreach ($lines as $line) {
        if (preg_match('/Folio\s*:\s*([0-9\/\s]+)/i', $line, $m)) {
            $currentFolio = trim($m[1]); continue;
        }
        if (preg_match('/^Scheme\s*:\s*(.+)$/i', $line, $m)) {
            $currentFund = trim($m[1]); continue;
        }
        if (preg_match('/^(\d{2}[\/\-]\d{2}[\/\-]\d{4})\s+(.+?)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)/', $line, $m)) {
            $result[] = [
                'fund_name' => $currentFund,
                'folio'     => $currentFolio,
                'txn_type'  => map_cams_type($m[2]),
                'txn_date'  => $m[1],
                'units'     => str_replace(',', '', $m[3]),
                'nav'       => str_replace(',', '', $m[4]),
                'platform'  => 'KFintech',
            ];
        }
    }
    return $result;
}

function parse_groww(array $lines): array {
    // Groww export: Fund Name, Date, Type, Units, NAV, Amount
    $result = []; $header = null;
    foreach ($lines as $line) {
        if (empty($line)) continue;
        $cols = str_getcsv($line);
        if (!$header) { $header = array_map('strtolower', array_map('trim', $cols)); continue; }
        $r = array_combine($header, array_pad($cols, count($header), ''));
        $result[] = [
            'fund_name' => $r['fund name'] ?? $r['scheme name'] ?? '',
            'txn_type'  => $r['type'] ?? $r['transaction type'] ?? 'BUY',
            'txn_date'  => $r['date'] ?? '',
            'units'     => $r['units'] ?? 0,
            'nav'       => $r['nav'] ?? 0,
            'platform'  => 'Groww',
        ];
    }
    return $result;
}

function map_cams_type(string $type): string {
    $type = strtolower(trim($type));
    if (strpos($type, 'redemption') !== false || strpos($type, 'sell') !== false) return 'SELL';
    if (strpos($type, 'dividend') !== false && strpos($type, 'reinvest') !== false) return 'DIV_REINVEST';
    if (strpos($type, 'switch in') !== false) return 'SWITCH_IN';
    if (strpos($type, 'switch out') !== false) return 'SWITCH_OUT';
    return 'BUY';
}

function normalize_txn_type(string $t): string {
    $t = strtoupper(trim($t));
    $map = ['PURCHASE'=>'BUY','P'=>'BUY','B'=>'BUY',
            'REDEMPTION'=>'SELL','R'=>'SELL','S'=>'SELL',
            'DIV REINVEST'=>'DIV_REINVEST','DIVIDEND REINVESTMENT'=>'DIV_REINVEST'];
    return $map[$t] ?? (in_array($t, ['BUY','SELL','DIV_REINVEST','SWITCH_IN','SWITCH_OUT']) ? $t : 'BUY');
}

function normalize_date(string $d): ?string {
    $d = trim($d);
    if (empty($d)) return null;

    // Handle DD-MM-YY (e.g. "11-03-19" → 2019-03-11)
    // This must be checked BEFORE generic formats to avoid misparse
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{2})$/', $d, $m)) {
        $day   = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $year  = (int)$m[3] >= 0 ? '20' . str_pad($m[3], 2, '0', STR_PAD_LEFT) : null;
        if ($year && checkdate((int)$month, (int)$day, (int)$year)) {
            return "$year-$month-$day";
        }
    }

    // Handle DD-MM-YYYY or DD/MM/YYYY (e.g. "11-03-2019")
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $d, $m)) {
        $day   = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $year  = $m[3];
        if (checkdate((int)$month, (int)$day, (int)$year)) {
            return "$year-$month-$day";
        }
    }

    // Handle YYYY-MM-DD (already correct)
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return $d;
        }
    }

    // Handle DD-Mon-YYYY (e.g. "11-Mar-2019") — CAMS format
    $dt = DateTime::createFromFormat('d-M-Y', $d);
    if ($dt) return $dt->format('Y-m-d');

    $dt = DateTime::createFromFormat('d M Y', $d);
    if ($dt) return $dt->format('Y-m-d');

    return null;
}

function normalize_fund_name(string $name): string {
    $name = strtolower(trim($name));
    $name = preg_replace('/\s+/', ' ', $name);           // multiple spaces -> single
    $name = str_replace(['–', '—', '−'], '-', $name);    // normalize dashes
    $name = preg_replace('/\s*-\s*/', '-', $name);      // spaces around dashes
    $name = preg_replace('/[^a-z0-9\-&()]/', ' ', $name); // remove special chars
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function find_fund(PDO $db, array $row): ?array {
    // 1) by scheme_code (fastest)
    if (!empty($row['scheme_code'])) {
        $s = $db->prepare("SELECT id, scheme_name FROM funds WHERE scheme_code = ? LIMIT 1");
        $s->execute([trim($row['scheme_code'])]);
        $f = $s->fetch();
        if ($f) return $f;
    }
    if (empty($row['fund_name'])) return null;

    $name = trim($row['fund_name']);

    // 2) Exact match
    $s = $db->prepare("SELECT id, scheme_name FROM funds WHERE scheme_name = ? LIMIT 1");
    $s->execute([$name]);
    $f = $s->fetch();
    if ($f) return $f;

    // 3) Case-insensitive exact match
    $s = $db->prepare("SELECT id, scheme_name FROM funds WHERE LOWER(scheme_name) = LOWER(?) LIMIT 1");
    $s->execute([$name]);
    $f = $s->fetch();
    if ($f) return $f;

    // 4) LIKE search (original name)
    $s = $db->prepare("SELECT id, scheme_name FROM funds WHERE scheme_name LIKE ? LIMIT 1");
    $s->execute(['%'.$name.'%']);
    $f = $s->fetch();
    if ($f) return $f;

    // 5) Fuzzy: normalize both sides and compare key words
    $normInput = normalize_fund_name($name);
    // Extract meaningful keywords (length > 3, skip common words)
    $stopWords = ['fund', 'plan', 'direct', 'growth', 'option', 'the', 'and', 'for'];
    $words = array_filter(explode(' ', $normInput), fn($w) => strlen($w) > 3 && !in_array($w, $stopWords));
    $words = array_values($words);

    if (count($words) >= 2) {
        // Build a query matching multiple keywords
        $conditions = array_map(fn($w) => "LOWER(scheme_name) LIKE ?", $words);
        $sql = "SELECT id, scheme_name FROM funds WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
        $params = array_map(fn($w) => '%'.$w.'%', $words);
        $s = $db->prepare($sql);
        $s->execute($params);
        $f = $s->fetch();
        if ($f) return $f;

        // 6) Try with first 2 keywords only (more lenient)
        $twoWords = array_slice($words, 0, 2);
        $conditions2 = array_map(fn($w) => "LOWER(scheme_name) LIKE ?", $twoWords);
        $sql2 = "SELECT id, scheme_name FROM funds WHERE " . implode(' AND ', $conditions2) . " LIMIT 1";
        $params2 = array_map(fn($w) => '%'.$w.'%', $twoWords);
        $s2 = $db->prepare($sql2);
        $s2->execute($params2);
        return $s2->fetch() ?: null;
    }

    return null;
}

// ════════════════════════════════════════════════════════════
// BUILD ANNOTATED CSV
// Adds import_status + import_remark + matched_fund columns
// ════════════════════════════════════════════════════════════
function build_annotated_csv(array $transactions, array $row_results, string $original_content): string {
    // Normalize line endings (handle Windows \r\n and Unix \n)
    $original_content = str_replace("\r\n", "\n", $original_content);
    $original_content = str_replace("\r", "\n", $original_content);

    $lines = array_values(array_filter(array_map('trim', explode("\n", $original_content))));
    if (count($lines) < 2) return '';

    $output = [];

    // Header row — add 3 new columns
    $header = str_getcsv($lines[0]);
    $header = array_filter($header, fn($h) => !in_array(strtolower(trim($h)), ['import_status','import_remark','matched_fund']));
    $header = array_values($header);
    $numOrigCols = count($header);
    $header[] = 'import_status';
    $header[] = 'import_remark';
    $header[] = 'matched_fund';
    $output[] = implode(',', array_map(fn($h) => '"'.str_replace('"','""',trim($h)).'"', $header));

    // Data rows — $idx matches $row_results keys (0-based transaction index)
    $txnIdx = 0;
    $dataLines = array_slice($lines, 1);
    foreach ($dataLines as $line) {
        if (empty(trim($line))) continue;

        $cols = str_getcsv($line);
        $cols = array_slice($cols, 0, $numOrigCols); // strip old status cols
        while (count($cols) < $numOrigCols) $cols[] = '';

        $result = $row_results[$txnIdx] ?? ['status' => 'NOT_PROCESSED', 'reason' => '', 'matched_fund' => ''];
        $cols[] = $result['status']        ?? '';
        $cols[] = $result['reason']        ?? '';
        $cols[] = $result['matched_fund']  ?? '';

        $output[] = implode(',', array_map(fn($c) => '"'.str_replace('"','""',(string)$c).'"', $cols));
        $txnIdx++;
    }

    return "\xEF\xBB\xBF" . implode("\r\n", $output); // UTF-8 BOM for Excel
}