<?php
/**
 * WealthDash — MF Import CSV / CAS Import
 * Tasks: t187 (CAMS CAS Import), t190 (Import History)
 * Supports: CAMS CAS PDF, Groww CSV, Zerodha CSV, Kuvera JSON, MF Central
 * Actions: mf_import_csv | import_history | import_preview | import_rollback
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'mf_import_csv';
$db          = DB::conn();

// Ensure import_logs table
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS import_logs (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id        INT UNSIGNED NOT NULL,
            filename       VARCHAR(200) NOT NULL,
            format         VARCHAR(30)  NOT NULL,
            imported_count INT UNSIGNED DEFAULT 0,
            skipped_count  INT UNSIGNED DEFAULT 0,
            failed_count   INT UNSIGNED DEFAULT 0,
            error_json     TEXT DEFAULT NULL,
            status         ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
            imported_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), INDEX idx_date (imported_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// mf_import_csv — Parse and import CSV/JSON file
// ══════════════════════════════════════════════════════════════════════════
case 'mf_import_csv':
    if (empty($_FILES['file'])) {
        echo json_encode(['success' => false, 'msg' => 'No file uploaded']); break;
    }
    $file     = $_FILES['file'];
    $filename = $file['name'];
    $tmpPath  = $file['tmp_name'];
    $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // Validate file type
    $allowedExt  = ['csv', 'txt', 'json'];
    $allowedMime = ['text/csv', 'text/plain', 'application/json', 'application/vnd.ms-excel'];
    $mime        = mime_content_type($tmpPath);

    if (!in_array($ext, $allowedExt)) {
        echo json_encode(['success' => false, 'msg' => "Invalid file type: $ext. Allowed: csv, txt, json"]); break;
    }

    $preview    = isset($_POST['preview']) && $_POST['preview'] === '1';
    $format     = $_POST['format'] ?? 'auto';
    $portfolioId = getOrCreatePortfolio($db, $userId);

    $rows    = [];
    $errors  = [];
    $format  = detectFormat($filename, $tmpPath, $format);

    try {
        switch ($format) {
            case 'groww_csv':    $rows = parseGrowwCsv($tmpPath);     break;
            case 'zerodha_csv':  $rows = parseZerodhaCsv($tmpPath);   break;
            case 'kuvera_json':  $rows = parseKuveraJson($tmpPath);   break;
            case 'cas_txt':      $rows = parseCasTxt($tmpPath);       break;
            case 'mfcentral_csv': $rows = parseMfCentralCsv($tmpPath); break;
            default:             $rows = parseGenericCsv($tmpPath);   break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => 'Parse error: ' . $e->getMessage()]); break;
    }

    if ($preview) {
        echo json_encode(['success' => true, 'preview' => array_slice($rows, 0, 20),
                          'total' => count($rows), 'format' => $format]);
        break;
    }

    // Import rows
    $imported = $skipped = $failed = 0;

    $db->beginTransaction();
    try {
        foreach ($rows as $row) {
            try {
                $result = importTransaction($db, $userId, $portfolioId, $row);
                $result ? $imported++ : $skipped++;
            } catch (Exception $e) {
                $errors[] = ['row' => $row, 'error' => $e->getMessage()];
                $failed++;
            }
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'msg' => 'Import failed: ' . $e->getMessage()]); break;
    }

    // Log import
    $status = $failed === 0 ? 'success' : ($imported > 0 ? 'partial' : 'failed');
    $db->prepare("
        INSERT INTO import_logs (user_id, filename, format, imported_count, skipped_count, failed_count, error_json, status)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([$userId, $filename, $format, $imported, $skipped, $failed,
                 $errors ? json_encode(array_slice($errors, 0, 20)) : null, $status]);

    echo json_encode([
        'success'  => $status !== 'failed',
        'imported' => $imported,
        'skipped'  => $skipped,
        'failed'   => $failed,
        'status'   => $status,
        'errors'   => array_slice($errors, 0, 5),
        'log_id'   => $db->lastInsertId(),
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// import_history — t190
// ══════════════════════════════════════════════════════════════════════════
case 'import_history':
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 15;
    $offset  = ($page - 1) * $perPage;

    $total = (int)$db->prepare("SELECT COUNT(*) FROM import_logs WHERE user_id=?")->execute([$userId])
             ? 0 : 0;
    $c = $db->prepare("SELECT COUNT(*) FROM import_logs WHERE user_id=?");
    $c->execute([$userId]); $total = (int)$c->fetchColumn();

    $stmt = $db->prepare("
        SELECT id, filename, format, imported_count, skipped_count, failed_count,
               status, imported_at, error_json
        FROM import_logs WHERE user_id=?
        ORDER BY imported_at DESC LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute([$userId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 'data' => $logs,
        'meta'    => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => ceil($total / $perPage)],
    ]);
    break;

default:
    echo json_encode(['success' => false, 'msg' => "Unknown action: $action"]);
}

// ── Parsers ───────────────────────────────────────────────────────────────

function detectFormat(string $filename, string $path, string $hint): string {
    if ($hint !== 'auto') return $hint;
    $nameLower = strtolower($filename);
    if (str_contains($nameLower, 'groww'))   return 'groww_csv';
    if (str_contains($nameLower, 'zerodha')) return 'zerodha_csv';
    if (str_contains($nameLower, 'kuvera'))  return 'kuvera_json';
    if (str_contains($nameLower, 'mfcentral')) return 'mfcentral_csv';
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if ($ext === 'json') return 'kuvera_json';
    // Peek at file content
    $head = file_get_contents($path, false, null, 0, 500);
    if (str_contains($head, 'Transaction Type') && str_contains($head, 'ISIN')) return 'groww_csv';
    if (str_contains($head, 'Folio No')) return 'cas_txt';
    return 'generic_csv';
}

function parseCasTxt(string $path): array {
    $content = file_get_contents($path);
    $rows    = [];
    // Simplified CAS parser — real implementation needs PDF extraction
    // For TXT format exported from CAMS/Karvy
    $lines   = explode("\n", $content);
    $currentFund = $currentFolio = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/Folio No\.?\s*:\s*([0-9\/\-]+)/i', $line, $m)) {
            $currentFolio = trim($m[1]);
        }
        if (preg_match('/^([A-Z].{10,60})\s+ISIN:\s*([A-Z0-9]{12})/i', $line, $m)) {
            $currentFund = trim($m[1]);
        }
        // Transaction line: date, description, amount, units, nav, balance
        if (preg_match('/^(\d{2}-[A-Z]{3}-\d{4})\s+(.+?)\s+([\d,]+\.\d+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)/i', $line, $m)) {
            $type = strtolower($m[2]);
            $txType = 'buy';
            if (str_contains($type, 'redemption') || str_contains($type, 'sale')) $txType = 'sell';
            if (str_contains($type, 'sip')) $txType = 'sip';
            if (str_contains($type, 'swp')) $txType = 'swp';

            $rows[] = [
                'fund_name'    => $currentFund,
                'folio_number' => $currentFolio,
                'tx_date'      => date('Y-m-d', strtotime($m[1])),
                'tx_type'      => $txType,
                'amount'       => (float)str_replace(',', '', $m[3]),
                'units'        => (float)$m[4],
                'nav'          => (float)$m[5],
            ];
        }
    }
    return $rows;
}

function parseGrowwCsv(string $path): array {
    $rows = []; $header = null;
    if (($fh = fopen($path, 'r')) === false) return [];
    while (($line = fgetcsv($fh)) !== false) {
        if (!$header) { $header = array_map('trim', $line); continue; }
        $row = array_combine($header, array_pad($line, count($header), ''));
        $type = strtolower($row['Transaction Type'] ?? '');
        $rows[] = [
            'fund_name' => $row['Scheme Name'] ?? '',
            'isin'      => $row['ISIN'] ?? '',
            'tx_date'   => date('Y-m-d', strtotime($row['Date'] ?? '')),
            'tx_type'   => str_contains($type, 'buy') || str_contains($type, 'sip') ? 'sip' : 'sell',
            'amount'    => (float)str_replace(',', '', $row['Amount'] ?? 0),
            'units'     => (float)($row['Units'] ?? 0),
            'nav'       => (float)($row['NAV'] ?? 0),
        ];
    }
    fclose($fh);
    return array_filter($rows, fn($r) => !empty($r['fund_name']) && !empty($r['tx_date']));
}

function parseZerodhaCsv(string $path): array {
    $rows = []; $header = null;
    if (($fh = fopen($path, 'r')) === false) return [];
    while (($line = fgetcsv($fh)) !== false) {
        if (!$header) { $header = array_map('trim', $line); continue; }
        $row = array_combine($header, array_pad($line, count($header), ''));
        $rows[] = [
            'fund_name' => $row['Fund'] ?? $row['Scheme'] ?? '',
            'tx_date'   => date('Y-m-d', strtotime($row['Date'] ?? '')),
            'tx_type'   => strtolower($row['Type'] ?? '') === 'purchase' ? 'buy' : 'sell',
            'amount'    => (float)str_replace(',', '', $row['Amount'] ?? 0),
            'units'     => (float)($row['Units'] ?? 0),
            'nav'       => (float)($row['NAV'] ?? 0),
        ];
    }
    fclose($fh);
    return array_filter($rows, fn($r) => !empty($r['fund_name']));
}

function parseKuveraJson(string $path): array {
    $data = json_decode(file_get_contents($path), true);
    if (!$data) return [];
    $rows = [];
    $funds = $data['data']['mf'] ?? $data['funds'] ?? [];
    foreach ($funds as $fund) {
        $transactions = $fund['transactions'] ?? $fund['txns'] ?? [];
        foreach ($transactions as $txn) {
            $rows[] = [
                'fund_name' => $fund['name'] ?? $fund['scheme_name'] ?? '',
                'isin'      => $fund['isin'] ?? '',
                'tx_date'   => substr($txn['date'] ?? '', 0, 10),
                'tx_type'   => in_array(strtolower($txn['type'] ?? ''), ['buy','purchase','sip']) ? 'sip' : 'sell',
                'amount'    => (float)($txn['amount'] ?? 0),
                'units'     => (float)($txn['units'] ?? 0),
                'nav'       => (float)($txn['nav'] ?? $txn['price'] ?? 0),
            ];
        }
    }
    return $rows;
}

function parseMfCentralCsv(string $path): array {
    return parseGenericCsv($path); // MF Central uses standard format
}

function parseGenericCsv(string $path): array {
    $rows = []; $header = null;
    if (($fh = fopen($path, 'r')) === false) return [];
    while (($line = fgetcsv($fh)) !== false) {
        if (!$header) {
            $header = array_map(fn($h) => strtolower(trim(str_replace([' ', '-', '/'], '_', $h))), $line);
            continue;
        }
        $row = array_combine($header, array_pad($line, count($header), ''));
        $fundName = $row['fund_name'] ?? $row['scheme_name'] ?? $row['scheme'] ?? $row['fund'] ?? '';
        if (!$fundName) continue;

        $date  = $row['date'] ?? $row['tx_date'] ?? $row['transaction_date'] ?? '';
        $type  = strtolower($row['type'] ?? $row['tx_type'] ?? $row['transaction_type'] ?? 'buy');
        $rows[] = [
            'fund_name' => $fundName,
            'tx_date'   => $date ? date('Y-m-d', strtotime($date)) : null,
            'tx_type'   => str_contains($type, 'sell') || str_contains($type, 'redeem') ? 'sell' : 'buy',
            'amount'    => (float)str_replace(',', '', $row['amount'] ?? 0),
            'units'     => (float)($row['units'] ?? 0),
            'nav'       => (float)($row['nav'] ?? $row['price'] ?? 0),
        ];
    }
    fclose($fh);
    return array_filter($rows, fn($r) => !empty($r['fund_name']) && !empty($r['tx_date']));
}

// ── Import transaction into DB ─────────────────────────────────────────────

function importTransaction(PDO $db, int $userId, int $portfolioId, array $row): bool {
    $fundName = trim($row['fund_name'] ?? '');
    $txDate   = $row['tx_date'] ?? null;
    if (!$fundName || !$txDate) return false;

    // Find fund by name (fuzzy match)
    $fund = $db->prepare("SELECT id, current_nav FROM funds WHERE fund_name LIKE ? LIMIT 1");
    $fund->execute(['%' . substr($fundName, 0, 30) . '%']);
    $fundRow = $fund->fetch(PDO::FETCH_ASSOC);

    if (!$fundRow) {
        // Try ISIN match
        if (!empty($row['isin'])) {
            $byIsin = $db->prepare("SELECT id, current_nav FROM funds WHERE isin=? LIMIT 1");
            $byIsin->execute([$row['isin']]);
            $fundRow = $byIsin->fetch(PDO::FETCH_ASSOC);
        }
        if (!$fundRow) return false; // Fund not in our DB — skip
    }

    $fundId = (int)$fundRow['id'];
    $nav    = (float)($row['nav'] ?? $fundRow['current_nav']);
    $units  = (float)($row['units'] ?? 0);
    $amount = (float)($row['amount'] ?? ($units * $nav));
    $txType = $row['tx_type'] ?? 'buy';

    if ($units <= 0 && $amount > 0 && $nav > 0) $units = round($amount / $nav, 4);
    if ($amount <= 0 && $units > 0 && $nav > 0)  $amount = round($units * $nav, 2);
    if ($units <= 0) return false;

    // Get or create holding
    $h = $db->prepare("SELECT id, units, invested_amount, avg_buy_nav FROM mf_holdings WHERE portfolio_id=? AND fund_id=? AND is_active=1 LIMIT 1");
    $h->execute([$portfolioId, $fundId]);
    $holding = $h->fetch(PDO::FETCH_ASSOC);

    $isBuy = in_array($txType, ['buy','sip','lumpsum','switch_in']);

    if (!$holding) {
        $db->prepare("INSERT INTO mf_holdings (portfolio_id, fund_id, units, avg_buy_nav, current_nav, invested_amount, current_value, first_investment_date, is_active, created_at)
            VALUES (?,?,?,?,?,?,?,?,1,NOW())")
           ->execute([$portfolioId, $fundId, $units, $nav, $nav, $isBuy ? $amount : 0, $units * $nav, $txDate]);
        $holdingId = (int)$db->lastInsertId();
    } else {
        $holdingId = (int)$holding['id'];
        $newUnits  = $isBuy ? (float)$holding['units'] + $units : max(0, (float)$holding['units'] - $units);
        $newInv    = $isBuy ? (float)$holding['invested_amount'] + $amount : max(0, (float)$holding['invested_amount'] - $amount);
        $newAvg    = ($isBuy && $newUnits > 0) ? (((float)$holding['units'] * (float)$holding['avg_buy_nav']) + ($units * $nav)) / $newUnits : (float)$holding['avg_buy_nav'];
        $db->prepare("UPDATE mf_holdings SET units=?, avg_buy_nav=?, invested_amount=?, current_value=?*current_nav, updated_at=NOW() WHERE id=?")
           ->execute([$newUnits, round($newAvg, 4), $newInv, $newUnits, $holdingId]);
    }

    // Check for duplicate transaction
    $dup = $db->prepare("SELECT 1 FROM mf_transactions WHERE holding_id=? AND tx_type=? AND tx_date=? AND ABS(amount-?)<1 LIMIT 1");
    $dup->execute([$holdingId, $txType, $txDate, $amount]);
    if ($dup->fetchColumn()) return false; // Skip duplicate

    $db->prepare("INSERT INTO mf_transactions (holding_id, user_id, tx_type, units, price_per_unit, amount, tx_date, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
       ->execute([$holdingId, $userId, $txType, $units, $nav, $amount, $txDate]);

    return true;
}

function getOrCreatePortfolio(PDO $db, int $userId): int {
    $s = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
    $s->execute([$userId]); $pid = $s->fetchColumn();
    if ($pid) return (int)$pid;
    $db->prepare("INSERT INTO portfolios (user_id, name, is_default, created_at) VALUES (?,?,1,NOW())")->execute([$userId, 'My Portfolio']);
    return (int)$db->lastInsertId();
}
