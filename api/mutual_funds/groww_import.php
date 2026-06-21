<?php
/**
 * WealthDash — t302: Groww Portfolio Import (CSV + API Sync)
 * Path: api/mutual_funds/groww_import.php
 *
 * Actions:
 *   groww_detect        — detect & preview uploaded Groww CSV
 *   groww_import_csv    — commit Groww CSV import (MF + Stocks)
 *   groww_sessions      — list past import sessions
 *   groww_session_detail — detail of one import session
 *   groww_fund_map_list  — list unresolved fund mappings
 *   groww_fund_map_save  — confirm a groww_name → fund_id mapping
 *   groww_import_status  — import session status poll
 */

if (!defined('WEALTHDASH')) {
    define('WEALTHDASH', true);
    ob_start();
    require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
    require_once APP_ROOT . '/includes/auth_check.php';
    require_once APP_ROOT . '/includes/helpers.php';
    header('Content-Type: application/json; charset=utf-8');
}

defined('WEALTHDASH') or die();

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Groww CSV column definitions ─────────────────────────────────────────────
// Groww exports two types of CSVs: MF transactions and Stocks
// MF: Scheme Name, Transaction Date, Transaction Type, Amount, Units, NAV, Folio Number
// Stock: Symbol, Company Name, Exchange, Quantity, Average Cost Price, Current Price, P&L

const GROWW_MF_HEADERS = ['scheme name', 'transaction date', 'transaction type', 'amount', 'units', 'nav'];
const GROWW_STOCK_HEADERS = ['symbol', 'company name', 'exchange', 'quantity', 'average cost price'];
const GROWW_TXN_TYPE_MAP = [
    'purchase'              => 'BUY',
    'sip'                   => 'BUY',
    'systematic investment' => 'BUY',
    'redemption'            => 'SELL',
    'redeem'                => 'SELL',
    'switch in'             => 'SWITCH_IN',
    'switch out'            => 'SWITCH_OUT',
    'dividend reinvestment' => 'DIV_REINVEST',
    'dividend'              => 'DIV_REINVEST',
    'idcw'                  => 'DIV_REINVEST',
];

switch ($action) {

// ── DETECT & PREVIEW ────────────────────────────────────────────────────────
case 'groww_detect': {
    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        json_response(false, 'CSV file required');
    }
    if ($_FILES['csv_file']['size'] > 10 * 1024 * 1024) {
        json_response(false, 'File too large (max 10MB)');
    }

    $raw     = _groww_read_file($_FILES['csv_file']['tmp_name']);
    $type    = _groww_detect_type($raw);
    $rows    = _groww_parse_csv($raw);
    $headers = $rows[0] ?? [];
    $preview = array_slice($rows, 1, 10);

    $previewParsed = [];
    if ($type === 'mf') {
        foreach ($preview as $i => $row) {
            $parsed = _groww_parse_mf_row($row, $headers);
            $previewParsed[] = ['row' => $i + 1, 'data' => $parsed, 'valid' => empty($parsed['_errors']), 'errors' => $parsed['_errors'] ?? []];
        }
    } elseif ($type === 'stock') {
        foreach ($preview as $i => $row) {
            $parsed = _groww_parse_stock_row($row, $headers);
            $previewParsed[] = ['row' => $i + 1, 'data' => $parsed, 'valid' => empty($parsed['_errors']), 'errors' => $parsed['_errors'] ?? []];
        }
    }

    // Save raw for later import (session-level temp)
    $sessionKey = 'groww_' . $userId . '_' . time();
    $_SESSION['groww_raw'][$sessionKey] = base64_encode($raw);

    json_response(true, '', [
        'type'          => $type,
        'type_label'    => $type === 'mf' ? 'Mutual Fund Transactions' : ($type === 'stock' ? 'Stock Holdings' : 'Unknown'),
        'total_rows'    => count($rows) - 1,
        'headers'       => $headers,
        'preview'       => $previewParsed,
        'session_key'   => $sessionKey,
        'ambiguous'     => $type === 'unknown',
    ]);
    break;
}

// ── IMPORT CSV ───────────────────────────────────────────────────────────────
case 'groww_import_csv': {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403); json_response(false, 'Invalid CSRF token');
    }

    $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
    $importType  = clean($_POST['import_type'] ?? 'mf'); // mf or stock
    $previewOnly = ($_POST['preview_only'] ?? '0') === '1';

    if (!$portfolioId) json_response(false, 'portfolio_id required');

    // Verify portfolio
    $pCheck = $db->prepare("SELECT id FROM portfolios WHERE id=? AND user_id=?");
    $pCheck->execute([$portfolioId, $userId]);
    if (!$pCheck->fetchColumn()) json_response(false, 'Portfolio not found');

    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        json_response(false, 'CSV file required');
    }

    $raw     = _groww_read_file($_FILES['csv_file']['tmp_name']);
    $type    = _groww_detect_type($raw);
    if ($type === 'unknown') json_response(false, 'Could not detect Groww CSV format. Upload a Groww MF or Stock CSV.');

    $rows    = _groww_parse_csv($raw);
    $headers = $rows[0] ?? [];
    $dataRows = array_slice($rows, 1);

    // Create session log
    $db->prepare("
        INSERT INTO groww_import_sessions
            (user_id, portfolio_id, import_type, filename, status, total_rows)
        VALUES (?,?,?,?,?,?)
    ")->execute([$userId, $portfolioId, 'csv', $_FILES['csv_file']['name'], 'processing', count($dataRows)]);
    $sessionId = (int)$db->lastInsertId();

    $imported   = 0;
    $skipped    = 0;
    $errors     = 0;
    $errorLog   = [];
    $mfCount    = 0;
    $stockCount = 0;

    if (!$previewOnly) $db->beginTransaction();

    try {
        if ($type === 'mf') {
            $mfStmt = $db->prepare("
                INSERT IGNORE INTO mf_transactions
                    (portfolio_id, fund_id, folio_number, transaction_type, txn_date,
                     units, nav, value_at_cost, import_source, investment_fy, created_at)
                VALUES (?,?,?,?,?,?,?,?,'groww_csv',?,NOW())
            ");

            foreach ($dataRows as $lineNum => $row) {
                $p = _groww_parse_mf_row($row, $headers);
                if (!empty($p['_errors'])) {
                    $errorLog[] = "Row " . ($lineNum + 2) . ": " . implode(', ', $p['_errors']);
                    $skipped++; $errors++;
                    continue;
                }

                // Resolve fund
                $fundId = _groww_resolve_fund($db, $p['scheme_name'], null);
                if (!$fundId) {
                    // Save unmapped for manual resolution
                    $db->prepare("INSERT IGNORE INTO groww_fund_map (groww_name) VALUES (?)")
                       ->execute([$p['scheme_name']]);
                    $errorLog[] = "Row " . ($lineNum + 2) . ": Fund not mapped — '{$p['scheme_name']}'";
                    $skipped++; $errors++;
                    continue;
                }

                $fy = _groww_get_fy($p['txn_date']);

                if (!$previewOnly) {
                    $mfStmt->execute([
                        $portfolioId, $fundId, $p['folio'] ?? null,
                        $p['txn_type'], $p['txn_date'],
                        $p['units'], $p['nav'], $p['amount'],
                        $fy
                    ]);
                    if ($mfStmt->rowCount()) { $imported++; $mfCount++; }
                    else $skipped++;
                } else {
                    $imported++; $mfCount++;
                }
            }
        } elseif ($type === 'stock') {
            // Stock holdings — insert/update into stock_holdings
            $stockStmt = $db->prepare("
                INSERT INTO stock_holdings
                    (portfolio_id, user_id, symbol, company_name, exchange,
                     quantity, avg_buy_price, total_invested, import_source, updated_at)
                VALUES (?,?,?,?,?,?,?,?,'groww_csv', NOW())
                ON DUPLICATE KEY UPDATE
                    quantity=VALUES(quantity),
                    avg_buy_price=VALUES(avg_buy_price),
                    total_invested=VALUES(total_invested),
                    updated_at=NOW()
            ");

            foreach ($dataRows as $lineNum => $row) {
                $p = _groww_parse_stock_row($row, $headers);
                if (!empty($p['_errors'])) {
                    $errorLog[] = "Row " . ($lineNum + 2) . ": " . implode(', ', $p['_errors']);
                    $skipped++; $errors++;
                    continue;
                }

                if (!$previewOnly) {
                    $stockStmt->execute([
                        $portfolioId, $userId,
                        $p['symbol'], $p['company_name'], $p['exchange'],
                        $p['quantity'], $p['avg_cost'], round($p['quantity'] * $p['avg_cost'], 2)
                    ]);
                    $imported++; $stockCount++;
                } else {
                    $imported++; $stockCount++;
                }
            }
        }

        if (!$previewOnly) {
            $db->commit();
            // Update session
            $db->prepare("
                UPDATE groww_import_sessions SET
                    status=?, imported=?, skipped=?, errors=?,
                    mf_count=?, stock_count=?, error_log=?
                WHERE id=?
            ")->execute([
                $errors > 0 && $imported === 0 ? 'failed' : ($errors > 0 ? 'partial' : 'done'),
                $imported, $skipped, $errors,
                $mfCount, $stockCount,
                implode("\n", array_slice($errorLog, 0, 100)),
                $sessionId
            ]);
        } else {
            $db->rollBack();
            $db->prepare("UPDATE groww_import_sessions SET status='pending' WHERE id=?")->execute([$sessionId]);
        }

    } catch (Throwable $e) {
        if (!$previewOnly) $db->rollBack();
        $db->prepare("UPDATE groww_import_sessions SET status='failed', error_log=? WHERE id=?")
           ->execute([$e->getMessage(), $sessionId]);
        json_response(false, 'Import failed: ' . $e->getMessage());
    }

    json_response(true, $previewOnly
        ? "Preview: {$imported} would import, {$skipped} would skip"
        : "Imported {$imported} records. {$skipped} skipped, {$errors} errors.", [
        'session_id'  => $sessionId,
        'preview'     => $previewOnly,
        'imported'    => $imported,
        'skipped'     => $skipped,
        'errors'      => $errors,
        'mf_count'    => $mfCount,
        'stock_count' => $stockCount,
        'error_log'   => array_slice($errorLog, 0, 50),
    ]);
    break;
}

// ── SESSIONS LIST ────────────────────────────────────────────────────────────
case 'groww_sessions': {
    $stmt = $db->prepare("
        SELECT * FROM groww_import_sessions
        WHERE user_id=? ORDER BY created_at DESC LIMIT 20
    ");
    $stmt->execute([$userId]);
    json_response(true, '', ['sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;
}

// ── SESSION DETAIL ───────────────────────────────────────────────────────────
case 'groww_session_detail': {
    $sid = (int)($_GET['id'] ?? 0);
    if (!$sid) json_response(false, 'id required');
    $stmt = $db->prepare("SELECT * FROM groww_import_sessions WHERE id=? AND user_id=?");
    $stmt->execute([$sid, $userId]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) json_response(false, 'Session not found');
    json_response(true, '', ['session' => $s]);
    break;
}

// ── FUND MAP LIST ────────────────────────────────────────────────────────────
case 'groww_fund_map_list': {
    $stmt = $db->prepare("
        SELECT gm.*, f.fund_name AS resolved_name
        FROM groww_fund_map gm
        LEFT JOIN funds f ON f.id = gm.fund_id
        WHERE gm.is_confirmed = 0
        ORDER BY gm.groww_name
        LIMIT 100
    ");
    $stmt->execute();
    json_response(true, '', ['unmapped' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;
}

// ── FUND MAP SAVE ────────────────────────────────────────────────────────────
case 'groww_fund_map_save': {
    $growwName = clean($_POST['groww_name'] ?? '');
    $fundId    = (int)($_POST['fund_id'] ?? 0);
    if (!$growwName || !$fundId) json_response(false, 'groww_name and fund_id required');

    $db->prepare("
        INSERT INTO groww_fund_map (groww_name, fund_id, is_confirmed)
        VALUES (?,?,1)
        ON DUPLICATE KEY UPDATE fund_id=VALUES(fund_id), is_confirmed=1
    ")->execute([$growwName, $fundId]);

    json_response(true, 'Mapping saved');
    break;
}

// ── IMPORT STATUS ────────────────────────────────────────────────────────────
case 'groww_import_status': {
    $sid = (int)($_GET['id'] ?? 0);
    if (!$sid) json_response(false, 'id required');
    $stmt = $db->prepare("SELECT status, imported, skipped, errors, mf_count, stock_count FROM groww_import_sessions WHERE id=? AND user_id=?");
    $stmt->execute([$sid, $userId]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) json_response(false, 'Session not found');
    json_response(true, '', $s);
    break;
}

default:
    json_response(false, "Unknown action: {$action}");
}

// ── Internal Helpers ─────────────────────────────────────────────────────────

function _groww_read_file(string $path): string {
    $raw = file_get_contents($path);
    $raw = ltrim($raw, "\xEF\xBB\xBF"); // strip BOM
    return str_replace("\r\n", "\n", str_replace("\r", "\n", $raw));
}

function _groww_detect_type(string $raw): string {
    $first = strtolower(substr($raw, 0, 1000));
    $firstLine = strtolower(trim(explode("\n", $raw)[0]));

    if (str_contains($firstLine, 'scheme name') && str_contains($firstLine, 'transaction date')) return 'mf';
    if (str_contains($firstLine, 'symbol') && str_contains($firstLine, 'average cost price')) return 'stock';
    if (str_contains($first, 'groww') && str_contains($first, 'nav')) return 'mf';
    if (str_contains($first, 'p&l') || str_contains($firstLine, 'current price')) return 'stock';

    return 'unknown';
}

function _groww_parse_csv(string $raw): array {
    $lines = array_filter(array_map('trim', explode("\n", $raw)));
    $rows  = [];
    foreach ($lines as $line) {
        if ($line) $rows[] = str_getcsv($line);
    }
    return $rows;
}

function _groww_parse_mf_row(array $row, array $headers): array {
    $h = array_map('strtolower', array_map('trim', $headers));
    $get = fn(string $key) => trim($row[array_search($key, $h) !== false ? array_search($key, $h) : -1] ?? '');

    $errors    = [];
    $schemeName = $get('scheme name');
    $rawDate    = $get('transaction date');
    $rawType    = strtolower($get('transaction type'));
    $rawAmount  = preg_replace('/[^0-9.\-]/', '', $get('amount'));
    $rawUnits   = preg_replace('/[^0-9.\-]/', '', $get('units'));
    $rawNav     = preg_replace('/[^0-9.\-]/', '', $get('nav'));
    $folio      = $get('folio number') ?: $get('folio no') ?: null;

    if (!$schemeName) $errors[] = 'Missing scheme name';

    // Parse date: d-M-Y or d/m/Y or Y-m-d
    $date = null;
    foreach (['d-M-Y', 'd/m/Y', 'Y-m-d', 'd-m-Y', 'd/M/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $rawDate);
        if ($dt) { $date = $dt->format('Y-m-d'); break; }
    }
    if (!$date) $errors[] = "Invalid date: '{$rawDate}'";

    // Txn type
    $txnType = 'BUY';
    foreach (GROWW_TXN_TYPE_MAP as $keyword => $mapped) {
        if (str_contains($rawType, $keyword)) { $txnType = $mapped; break; }
    }

    $amount = is_numeric($rawAmount) ? abs((float)$rawAmount) : null;
    $units  = is_numeric($rawUnits)  ? abs((float)$rawUnits)  : null;
    $nav    = is_numeric($rawNav)    ? abs((float)$rawNav)    : null;

    if (!$amount && !$units) $errors[] = 'Missing amount and units';
    if ($nav === null && $units && $amount) $nav = $amount / $units;

    return [
        'scheme_name' => $schemeName,
        'txn_date'    => $date,
        'txn_type'    => $txnType,
        'amount'      => $amount,
        'units'       => $units,
        'nav'         => $nav,
        'folio'       => $folio,
        '_errors'     => $errors,
    ];
}

function _groww_parse_stock_row(array $row, array $headers): array {
    $h = array_map('strtolower', array_map('trim', $headers));
    $get = function(string $key) use ($row, $h): string {
        $idx = array_search($key, $h);
        return $idx !== false ? trim($row[$idx] ?? '') : '';
    };

    $errors  = [];
    $symbol  = strtoupper($get('symbol'));
    $company = $get('company name') ?: $get('name') ?: $symbol;
    $exchange = strtoupper($get('exchange') ?: 'NSE');
    $rawQty  = preg_replace('/[^0-9.]/', '', $get('quantity'));
    $rawCost = preg_replace('/[^0-9.]/', '', $get('average cost price') ?: $get('avg cost price') ?: $get('avg. cost price'));

    if (!$symbol) $errors[] = 'Missing symbol';
    $qty  = is_numeric($rawQty)  ? (float)$rawQty  : null;
    $cost = is_numeric($rawCost) ? (float)$rawCost : null;
    if (!$qty  || $qty  <= 0) $errors[] = 'Invalid quantity';
    if (!$cost || $cost <= 0) $errors[] = 'Invalid avg cost price';

    return [
        'symbol'       => $symbol,
        'company_name' => $company,
        'exchange'     => $exchange,
        'quantity'     => $qty,
        'avg_cost'     => $cost,
        '_errors'      => $errors,
    ];
}

function _groww_resolve_fund(PDO $db, string $growwName, ?string $isin): ?int {
    // Check saved map first
    $stmt = $db->prepare("SELECT fund_id FROM groww_fund_map WHERE groww_name=? AND fund_id IS NOT NULL LIMIT 1");
    $stmt->execute([$growwName]);
    $fundId = $stmt->fetchColumn();
    if ($fundId) return (int)$fundId;

    // Try ISIN
    if ($isin) {
        $stmt = $db->prepare("SELECT id FROM funds WHERE isin=? LIMIT 1");
        $stmt->execute([$isin]);
        $fundId = $stmt->fetchColumn();
        if ($fundId) return (int)$fundId;
    }

    // Try name match (LIKE)
    $stmt = $db->prepare("SELECT id FROM funds WHERE fund_name LIKE ? AND is_active=1 LIMIT 1");
    $stmt->execute(['%' . trim($growwName) . '%']);
    $fundId = $stmt->fetchColumn();
    if ($fundId) return (int)$fundId;

    // Try partial keyword match (first 40 chars)
    $partial = substr(trim($growwName), 0, 40);
    $stmt = $db->prepare("SELECT id FROM funds WHERE fund_name LIKE ? AND is_active=1 LIMIT 1");
    $stmt->execute(['%' . $partial . '%']);
    $fundId = $stmt->fetchColumn();

    return $fundId ? (int)$fundId : null;
}

function _groww_get_fy(string $date): string {
    $y = (int)date('Y', strtotime($date));
    $m = (int)date('n', strtotime($date));
    return $m >= 4 ? "{$y}-" . substr((string)($y + 1), 2) : ($y - 1) . '-' . substr((string)$y, 2);
}
