<?php
/**
 * WealthDash — t334: Bulk Import (Excel Template, 50 fields)
 * Path: api/mutual_funds/bulk_import.php
 *
 * Actions:
 *   bulk_template_download  — generate & stream the Excel (.xlsx) import template
 *   bulk_validate           — validate uploaded file, return error report
 *   bulk_import             — commit validated rows to DB
 *   bulk_session_list       — list past import sessions
 *   bulk_session_detail     — detail with row-level errors
 *   bulk_template_fields    — return field definitions JSON
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

// ── 50-field MF Template definition ─────────────────────────────────────────
const BULK_MF_FIELDS = [
    // Group: Fund Identity
    ['col' => 'A', 'field' => 'fund_name',        'label' => 'Fund Name',            'required' => true,  'type' => 'text',   'example' => 'Axis Bluechip Fund - Growth'],
    ['col' => 'B', 'field' => 'scheme_code',       'label' => 'Scheme Code (AMFI)',   'required' => false, 'type' => 'text',   'example' => '120503'],
    ['col' => 'C', 'field' => 'isin',              'label' => 'ISIN',                 'required' => false, 'type' => 'text',   'example' => 'INF846K01DP8'],
    ['col' => 'D', 'field' => 'amc',               'label' => 'AMC / Fund House',     'required' => false, 'type' => 'text',   'example' => 'Axis Mutual Fund'],
    ['col' => 'E', 'field' => 'category',          'label' => 'Category',             'required' => false, 'type' => 'text',   'example' => 'Equity'],
    ['col' => 'F', 'field' => 'sub_category',      'label' => 'Sub Category',         'required' => false, 'type' => 'text',   'example' => 'Large Cap'],
    ['col' => 'G', 'field' => 'plan_type',         'label' => 'Plan (Direct/Regular)','required' => false, 'type' => 'text',   'example' => 'Direct'],
    ['col' => 'H', 'field' => 'option_type',       'label' => 'Option (Growth/IDCW)', 'required' => false, 'type' => 'text',   'example' => 'Growth'],
    ['col' => 'I', 'field' => 'folio_number',      'label' => 'Folio Number',         'required' => false, 'type' => 'text',   'example' => '1234567/89'],
    ['col' => 'J', 'field' => 'portfolio_name',    'label' => 'Portfolio Name',       'required' => false, 'type' => 'text',   'example' => 'My Portfolio'],
    // Group: Transaction
    ['col' => 'K', 'field' => 'transaction_type',  'label' => 'Transaction Type',     'required' => true,  'type' => 'enum',   'example' => 'BUY', 'options' => ['BUY','SELL','SIP','SWP','SWITCH_IN','SWITCH_OUT','DIV_REINVEST']],
    ['col' => 'L', 'field' => 'txn_date',          'label' => 'Transaction Date',     'required' => true,  'type' => 'date',   'example' => '2024-01-15'],
    ['col' => 'M', 'field' => 'units',             'label' => 'Units',                'required' => true,  'type' => 'number', 'example' => '100.5000'],
    ['col' => 'N', 'field' => 'nav',               'label' => 'NAV (₹)',              'required' => true,  'type' => 'number', 'example' => '52.4500'],
    ['col' => 'O', 'field' => 'amount',            'label' => 'Amount (₹)',           'required' => true,  'type' => 'number', 'example' => '5270.23'],
    // Group: Charges
    ['col' => 'P', 'field' => 'stamp_duty',        'label' => 'Stamp Duty (₹)',       'required' => false, 'type' => 'number', 'example' => '2.65'],
    ['col' => 'Q', 'field' => 'exit_load',         'label' => 'Exit Load (₹)',        'required' => false, 'type' => 'number', 'example' => '0.00'],
    ['col' => 'R', 'field' => 'stt',               'label' => 'STT (₹)',              'required' => false, 'type' => 'number', 'example' => '0.00'],
    ['col' => 'S', 'field' => 'gst',               'label' => 'GST (₹)',              'required' => false, 'type' => 'number', 'example' => '0.00'],
    ['col' => 'T', 'field' => 'brokerage',         'label' => 'Brokerage (₹)',        'required' => false, 'type' => 'number', 'example' => '0.00'],
    // Group: Platform / Payment
    ['col' => 'U', 'field' => 'platform',          'label' => 'Platform',             'required' => false, 'type' => 'text',   'example' => 'Groww'],
    ['col' => 'V', 'field' => 'advisor',           'label' => 'Advisor / Distributor','required' => false, 'type' => 'text',   'example' => 'Self'],
    ['col' => 'W', 'field' => 'bank_account',      'label' => 'Bank Account (last4)', 'required' => false, 'type' => 'text',   'example' => 'HDFC-1234'],
    ['col' => 'X', 'field' => 'payment_mode',      'label' => 'Payment Mode',         'required' => false, 'type' => 'text',   'example' => 'Net Banking'],
    ['col' => 'Y', 'field' => 'cheque_number',     'label' => 'Cheque No.',           'required' => false, 'type' => 'text',   'example' => ''],
    ['col' => 'Z', 'field' => 'utr_number',        'label' => 'UTR / Ref No.',        'required' => false, 'type' => 'text',   'example' => 'HDFC202401150001'],
    // Group: SIP Details
    ['col' => 'AA', 'field' => 'sip_id',           'label' => 'SIP ID (WealthDash)',  'required' => false, 'type' => 'number', 'example' => ''],
    ['col' => 'AB', 'field' => 'sip_frequency',    'label' => 'SIP Frequency',        'required' => false, 'type' => 'text',   'example' => 'monthly'],
    ['col' => 'AC', 'field' => 'sip_day',          'label' => 'SIP Day',              'required' => false, 'type' => 'number', 'example' => '5'],
    ['col' => 'AD', 'field' => 'sip_start_date',   'label' => 'SIP Start Date',       'required' => false, 'type' => 'date',   'example' => '2022-04-05'],
    ['col' => 'AE', 'field' => 'sip_end_date',     'label' => 'SIP End Date',         'required' => false, 'type' => 'date',   'example' => ''],
    ['col' => 'AF', 'field' => 'lumpsum_flag',     'label' => 'Lumpsum? (Y/N)',       'required' => false, 'type' => 'bool',   'example' => 'N'],
    // Group: Switch
    ['col' => 'AG', 'field' => 'switch_from_fund', 'label' => 'Switch From Fund',     'required' => false, 'type' => 'text',   'example' => ''],
    ['col' => 'AH', 'field' => 'switch_to_fund',   'label' => 'Switch To Fund',       'required' => false, 'type' => 'text',   'example' => ''],
    ['col' => 'AI', 'field' => 'switch_units',     'label' => 'Switch Units',          'required' => false, 'type' => 'number', 'example' => ''],
    // Group: Redemption
    ['col' => 'AJ', 'field' => 'redemption_bank',  'label' => 'Redemption Bank',      'required' => false, 'type' => 'text',   'example' => 'HDFC Bank'],
    ['col' => 'AK', 'field' => 'redemption_ifsc',  'label' => 'Redemption IFSC',      'required' => false, 'type' => 'text',   'example' => 'HDFC0001234'],
    ['col' => 'AL', 'field' => 'redemption_account','label' => 'Redemption Acct No',  'required' => false, 'type' => 'text',   'example' => ''],
    // Group: Dividend
    ['col' => 'AM', 'field' => 'dividend_type',    'label' => 'Dividend Type',        'required' => false, 'type' => 'text',   'example' => 'IDCW Payout'],
    ['col' => 'AN', 'field' => 'dividend_amount',  'label' => 'Dividend Amount (₹)',  'required' => false, 'type' => 'number', 'example' => ''],
    ['col' => 'AO', 'field' => 'dividend_date',    'label' => 'Dividend Date',        'required' => false, 'type' => 'date',   'example' => ''],
    // Group: Performance / Tax
    ['col' => 'AP', 'field' => 'xirr',             'label' => 'XIRR (%)',             'required' => false, 'type' => 'number', 'example' => ''],
    ['col' => 'AQ', 'field' => 'investment_fy',    'label' => 'Investment FY',        'required' => false, 'type' => 'text',   'example' => '2024-25'],
    ['col' => 'AR', 'field' => 'cost_of_acquisition','label' => 'Cost of Acquisition', 'required' => false, 'type' => 'number', 'example' => ''],
    ['col' => 'AS', 'field' => 'indexed_cost',     'label' => 'Indexed Cost',         'required' => false, 'type' => 'number', 'example' => ''],
    ['col' => 'AT', 'field' => 'capital_gain_type','label' => 'Capital Gain Type',    'required' => false, 'type' => 'text',   'example' => 'LTCG'],
    ['col' => 'AU', 'field' => 'stcg_amount',      'label' => 'STCG Amount (₹)',      'required' => false, 'type' => 'number', 'example' => ''],
    ['col' => 'AV', 'field' => 'ltcg_amount',      'label' => 'LTCG Amount (₹)',      'required' => false, 'type' => 'number', 'example' => ''],
    ['col' => 'AW', 'field' => 'grandfathered_nav','label' => 'Grandfathered NAV',    'required' => false, 'type' => 'number', 'example' => ''],
    // Group: Notes
    ['col' => 'AX', 'field' => 'notes',            'label' => 'Notes',                'required' => false, 'type' => 'text',   'example' => 'Imported from CAMS'],
];

switch ($action) {

// ── TEMPLATE FIELDS ──────────────────────────────────────────────────────────
case 'bulk_template_fields': {
    json_response(true, '', [
        'fields'      => BULK_MF_FIELDS,
        'field_count' => count(BULK_MF_FIELDS),
        'version'     => 'mf_v1',
        'required'    => array_values(array_filter(array_map(fn($f) => $f['required'] ? $f['field'] : null, BULK_MF_FIELDS))),
    ]);
    break;
}

// ── DOWNLOAD TEMPLATE ────────────────────────────────────────────────────────
case 'bulk_template_download': {
    // Build xlsx using OpenXML (pure PHP, no external lib needed)
    ob_clean();

    $fields    = BULK_MF_FIELDS;
    $filename  = 'wealthdash_bulk_import_template_v1.xlsx';

    // Build worksheet XML
    $sharedStrings = [];
    $ssIdx = function(string $s) use (&$sharedStrings): int {
        $idx = array_search($s, $sharedStrings, true);
        if ($idx === false) { $sharedStrings[] = $s; return count($sharedStrings) - 1; }
        return $idx;
    };

    // Row 1: Headers (bold)
    // Row 2: Required/Optional markers
    // Row 3: Examples
    // Row 4+: Data rows (blank)
    $headerRow  = '';
    $typeRow    = '';
    $exampleRow = '';

    foreach ($fields as $i => $f) {
        $colNum  = $i + 1;
        $cellRef = _bulk_col_letter($colNum);

        $label   = $f['label'] . ($f['required'] ? ' *' : '');
        $typeStr = ($f['required'] ? 'REQUIRED' : 'optional') . ' | ' . strtoupper($f['type']);
        $example = $f['example'];

        $headerRow  .= "<c r=\"{$cellRef}1\" t=\"s\"><v>{$ssIdx($label)}</v></c>";
        $typeRow    .= "<c r=\"{$cellRef}2\" t=\"s\"><v>{$ssIdx($typeStr)}</v></c>";
        if ($example !== '') {
            if (in_array($f['type'], ['number']) && is_numeric($example)) {
                $exampleRow .= "<c r=\"{$cellRef}3\"><v>{$example}</v></c>";
            } else {
                $exampleRow .= "<c r=\"{$cellRef}3\" t=\"s\"><v>{$ssIdx($example)}</v></c>";
            }
        }
    }

    $wsXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetViews><sheetView workbookViewId="0" tabSelected="1"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
  <sheetFormatPr defaultRowHeight="15"/>
  <sheetData>
    <row r="1" customFormat="1" ht="20" customHeight="1">{$headerRow}</row>
    <row r="2">{$typeRow}</row>
    <row r="3">{$exampleRow}</row>
  </sheetData>
</worksheet>
XML;

    // Shared strings XML
    $ssCount = count($sharedStrings);
    $ssXml   = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"{$ssCount}\" uniqueCount=\"{$ssCount}\">\n";
    foreach ($sharedStrings as $s) {
        $ssXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1, 'UTF-8') . '</t></si>' . "\n";
    }
    $ssXml .= '</sst>';

    $wbXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="MF Transactions" sheetId="1" r:id="rId1"/></sheets>
</workbook>
XML;

    $wbRels = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;

    $stylesXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
  </fonts>
  <fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
</styleSheet>
XML;

    $contentTypes = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;

    $rootRels = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

    // Build zip
    $tmp = tempnam(sys_get_temp_dir(), 'wd_bulk_') . '.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        json_response(false, 'Failed to create template file');
    }
    $zip->addFromString('[Content_Types].xml',         $contentTypes);
    $zip->addFromString('_rels/.rels',                  $rootRels);
    $zip->addFromString('xl/workbook.xml',              $wbXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels',   $wbRels);
    $zip->addFromString('xl/worksheets/sheet1.xml',     $wsXml);
    $zip->addFromString('xl/sharedStrings.xml',         $ssXml);
    $zip->addFromString('xl/styles.xml',                $stylesXml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: max-age=0');
    readfile($tmp);
    unlink($tmp);
    exit;
}

// ── VALIDATE ─────────────────────────────────────────────────────────────────
case 'bulk_validate': {
    if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        json_response(false, 'File required');
    }

    $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
    if (!$portfolioId) json_response(false, 'portfolio_id required');

    $rows       = _bulk_read_file($_FILES['import_file']['tmp_name'], $_FILES['import_file']['name']);
    $fields     = BULK_MF_FIELDS;
    $fieldNames = array_column($fields, 'field');

    // Header row detection
    $headerRow = $rows[0] ?? [];
    $colMap    = _bulk_detect_columns($headerRow, $fields);

    $validRows   = 0;
    $errorRows   = [];
    $totalRows   = count($rows) - 1; // exclude header

    foreach (array_slice($rows, 1) as $i => $row) {
        $rowNum  = $i + 2;
        $rowErrs = _bulk_validate_row($row, $colMap, $fields, $rowNum);
        if (empty($rowErrs)) {
            $validRows++;
        } else {
            $errorRows[] = ['row' => $rowNum, 'errors' => $rowErrs, 'data' => array_slice($row, 0, 15)];
        }
    }

    // Create session
    $db->prepare("
        INSERT INTO bulk_import_sessions
            (user_id, portfolio_id, import_source, asset_type, filename, status, total_rows, valid_rows, error_count)
        VALUES (?,?,'excel','mf',?,?,?,?,?)
    ")->execute([$userId, $portfolioId, $_FILES['import_file']['name'], 'validating', $totalRows, $validRows, count($errorRows)]);
    $sessionId = (int)$db->lastInsertId();

    $db->prepare("UPDATE bulk_import_sessions SET status='pending', validation_log=? WHERE id=?")
       ->execute([json_encode(array_slice($errorRows, 0, 100)), $sessionId]);

    json_response(true, "Validation complete: {$validRows}/{$totalRows} rows valid", [
        'session_id'  => $sessionId,
        'total_rows'  => $totalRows,
        'valid_rows'  => $validRows,
        'error_count' => count($errorRows),
        'error_rows'  => array_slice($errorRows, 0, 50),
        'ready'       => $validRows > 0,
        'col_map'     => $colMap,
    ]);
    break;
}

// ── IMPORT ───────────────────────────────────────────────────────────────────
case 'bulk_import': {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403); json_response(false, 'Invalid CSRF token');
    }

    $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
    $sessionId   = (int)($_POST['session_id'] ?? 0);
    $skipErrors  = ($_POST['skip_errors'] ?? '1') === '1';

    if (!$portfolioId) json_response(false, 'portfolio_id required');

    $pCheck = $db->prepare("SELECT id FROM portfolios WHERE id=? AND user_id=?");
    $pCheck->execute([$portfolioId, $userId]);
    if (!$pCheck->fetchColumn()) json_response(false, 'Portfolio not found');

    if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        json_response(false, 'Import file required');
    }

    $rows    = _bulk_read_file($_FILES['import_file']['tmp_name'], $_FILES['import_file']['name']);
    $fields  = BULK_MF_FIELDS;
    $headers = $rows[0] ?? [];
    $colMap  = _bulk_detect_columns($headers, $fields);

    $imported  = 0;
    $skipped   = 0;
    $errors    = 0;
    $importLog = [];

    $db->beginTransaction();

    $insStmt = $db->prepare("
        INSERT IGNORE INTO mf_transactions
            (portfolio_id, fund_id, folio_number, transaction_type, txn_date,
             units, nav, value_at_cost, stamp_duty, platform, notes, import_source, investment_fy, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'bulk_excel',?,NOW())
    ");

    foreach (array_slice($rows, 1) as $i => $row) {
        $rowNum = $i + 2;
        $errs   = _bulk_validate_row($row, $colMap, $fields, $rowNum);

        if (!empty($errs) && !$skipErrors) {
            $importLog[] = ['row' => $rowNum, 'status' => 'skipped', 'errors' => $errs];
            $skipped++; continue;
        }

        // Map field values
        $get = fn(string $field) => trim($row[$colMap[$field] ?? -1] ?? '');

        $fundName  = $get('fund_name');
        $fundId    = _bulk_resolve_fund($db, $fundName, $get('scheme_code'), $get('isin'));

        if (!$fundId) {
            $importLog[] = ['row' => $rowNum, 'status' => 'error', 'errors' => ["Fund not found: '{$fundName}'"]];
            $errors++; continue;
        }

        $date   = _bulk_parse_date($get('txn_date'));
        $fy     = $get('investment_fy') ?: _bulk_get_fy($date);
        $txnType = strtoupper(trim($get('transaction_type') ?: 'BUY'));

        try {
            $insStmt->execute([
                $portfolioId,
                $fundId,
                $get('folio_number') ?: null,
                $txnType,
                $date,
                (float)preg_replace('/[^0-9.]/', '', $get('units')),
                (float)preg_replace('/[^0-9.]/', '', $get('nav')),
                (float)preg_replace('/[^0-9.]/', '', $get('amount')),
                (float)preg_replace('/[^0-9.]/', '', $get('stamp_duty') ?: '0'),
                $get('platform') ?: null,
                $get('notes') ?: null,
                $fy,
            ]);
            if ($insStmt->rowCount()) { $imported++; $importLog[] = ['row' => $rowNum, 'status' => 'imported']; }
            else { $skipped++; $importLog[] = ['row' => $rowNum, 'status' => 'duplicate']; }
        } catch (PDOException $e) {
            $errors++;
            $importLog[] = ['row' => $rowNum, 'status' => 'error', 'errors' => [$e->getMessage()]];
        }
    }

    $db->commit();

    // Update session
    if ($sessionId) {
        $db->prepare("
            UPDATE bulk_import_sessions SET
                status=?, imported=?, skipped=?, error_count=?, import_log=?
            WHERE id=? AND user_id=?
        ")->execute([
            $errors > 0 && $imported === 0 ? 'failed' : ($errors > 0 ? 'partial' : 'done'),
            $imported, $skipped, $errors,
            json_encode(array_slice($importLog, 0, 200)),
            $sessionId, $userId
        ]);
    }

    json_response(true, "Imported {$imported}. Skipped {$skipped}. Errors {$errors}.", [
        'imported'   => $imported,
        'skipped'    => $skipped,
        'errors'     => $errors,
        'import_log' => array_slice($importLog, 0, 100),
        'session_id' => $sessionId,
    ]);
    break;
}

// ── SESSION LIST ─────────────────────────────────────────────────────────────
case 'bulk_session_list': {
    $stmt = $db->prepare("
        SELECT id, asset_type, filename, status, total_rows, valid_rows, imported, skipped, error_count, created_at
        FROM bulk_import_sessions WHERE user_id=? ORDER BY created_at DESC LIMIT 20
    ");
    $stmt->execute([$userId]);
    json_response(true, '', ['sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;
}

// ── SESSION DETAIL ───────────────────────────────────────────────────────────
case 'bulk_session_detail': {
    $sid = (int)($_GET['id'] ?? 0);
    if (!$sid) json_response(false, 'id required');
    $stmt = $db->prepare("SELECT * FROM bulk_import_sessions WHERE id=? AND user_id=?");
    $stmt->execute([$sid, $userId]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) json_response(false, 'Session not found');
    $s['validation_log'] = $s['validation_log'] ? json_decode($s['validation_log'], true) : [];
    $s['import_log']     = $s['import_log']     ? json_decode($s['import_log'], true)     : [];
    json_response(true, '', ['session' => $s]);
    break;
}

default:
    json_response(false, "Unknown action: {$action}");
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function _bulk_col_letter(int $n): string {
    $l = '';
    while ($n > 0) { $n--; $l = chr(65 + ($n % 26)) . $l; $n = (int)($n / 26); }
    return $l;
}

function _bulk_read_file(string $path, string $name): array {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $rows = [];

    if ($ext === 'csv') {
        $raw = file_get_contents($path);
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $raw = str_replace("\r\n", "\n", str_replace("\r", "\n", $raw));
        foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
            $rows[] = str_getcsv($line);
        }
    } elseif (in_array($ext, ['xlsx', 'xls'])) {
        // Parse xlsx using ZipArchive + SimpleXML (no external lib)
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $sharedStrings = [];
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml) {
                $ss = simplexml_load_string($ssXml);
                foreach ($ss->si as $si) {
                    $sharedStrings[] = (string)($si->t ?? '');
                }
            }
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml) {
                $sheet = simplexml_load_string($sheetXml);
                foreach ($sheet->sheetData->row as $row) {
                    $rowData = [];
                    foreach ($row->c as $cell) {
                        $type = (string)($cell['t'] ?? '');
                        $val  = (string)($cell->v ?? '');
                        $rowData[] = ($type === 's') ? ($sharedStrings[(int)$val] ?? '') : $val;
                    }
                    $rows[] = $rowData;
                }
            }
            $zip->close();
        }
    }

    return $rows;
}

function _bulk_detect_columns(array $headers, array $fields): array {
    $lower  = array_map('strtolower', array_map('trim', $headers));
    $colMap = [];
    foreach ($fields as $f) {
        $field = $f['field'];
        $label = strtolower($f['label']);
        $idx   = array_search($label, $lower);
        if ($idx === false) $idx = array_search($field, $lower);
        $colMap[$field] = $idx !== false ? $idx : null;
    }
    return $colMap;
}

function _bulk_validate_row(array $row, array $colMap, array $fields, int $rowNum): array {
    $errors = [];
    foreach ($fields as $f) {
        if (!$f['required']) continue;
        $idx = $colMap[$f['field']] ?? null;
        $val = $idx !== null ? trim($row[$idx] ?? '') : '';
        if ($val === '') $errors[] = "{$f['label']} is required (col {$f['col']})";
    }
    // Date format check
    $dateIdx = $colMap['txn_date'] ?? null;
    if ($dateIdx !== null) {
        $dateVal = trim($row[$dateIdx] ?? '');
        if ($dateVal && !_bulk_parse_date($dateVal)) {
            $errors[] = "Invalid date format: '{$dateVal}' (use YYYY-MM-DD)";
        }
    }
    // Units / NAV / Amount numeric check
    foreach (['units', 'nav', 'amount'] as $numField) {
        $idx = $colMap[$numField] ?? null;
        if ($idx !== null) {
            $v = preg_replace('/[^0-9.\-]/', '', $row[$idx] ?? '');
            if ($v !== '' && !is_numeric($v)) $errors[] = "Invalid number in {$numField}: '{$row[$idx]}'";
        }
    }
    return $errors;
}

function _bulk_parse_date(string $raw): ?string {
    if (!$raw) return null;
    foreach (['Y-m-d', 'd-M-Y', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd/M/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt) return $dt->format('Y-m-d');
    }
    return null;
}

function _bulk_resolve_fund(PDO $db, string $name, string $code = '', string $isin = ''): ?int {
    if ($code) {
        $s = $db->prepare("SELECT id FROM funds WHERE scheme_code=? LIMIT 1");
        $s->execute([$code]); $id = $s->fetchColumn(); if ($id) return (int)$id;
    }
    if ($isin) {
        $s = $db->prepare("SELECT id FROM funds WHERE isin=? LIMIT 1");
        $s->execute([$isin]); $id = $s->fetchColumn(); if ($id) return (int)$id;
    }
    $s = $db->prepare("SELECT id FROM funds WHERE fund_name LIKE ? AND is_active=1 LIMIT 1");
    $s->execute(['%' . trim($name) . '%']); $id = $s->fetchColumn();
    return $id ? (int)$id : null;
}

function _bulk_get_fy(string $date): string {
    $y = (int)date('Y', strtotime($date));
    $m = (int)date('n', strtotime($date));
    return $m >= 4 ? "{$y}-" . substr((string)($y + 1), 2) : ($y - 1) . '-' . substr((string)$y, 2);
}
