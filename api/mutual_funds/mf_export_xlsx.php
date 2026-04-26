<?php
/**
 * WealthDash — MF Holdings Excel Export (t375)
 * Generates a proper .xlsx file using OpenXML (SpreadsheetML).
 * No external library needed — pure PHP with ZipArchive + OpenXML.
 *
 * GET /api/mutual_funds/mf_export_xlsx.php
 *   ?portfolio_id=X   (optional, defaults to user's active portfolio)
 *   &category=Equity  (optional filter)
 *   &gain_type=LTCG   (optional filter)
 *   &sort=value_now   (optional sort)
 *   &dir=DESC
 */

define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/holding_calculator.php';

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// ─── Suppress any stray output so binary file is clean ──────────────────────
ob_start();

// ─── Helpers ─────────────────────────────────────────────────────────────────
function xlsx_escape(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Build Excel column letter(s) from 1-based index */
function col_letter(int $n): string
{
    $l = '';
    while ($n > 0) {
        $n--;
        $l  = chr(65 + ($n % 26)) . $l;
        $n  = (int)($n / 26);
    }
    return $l;
}

/** Cell ref e.g. A1 */
function cell(int $col, int $row): string
{
    return col_letter($col) . $row;
}

// ─── Fetch holdings ──────────────────────────────────────────────────────────
$portfolioId = get_user_portfolio_id($userId);

$allowedSorts = ['value_now','total_invested','gain_loss','gain_pct','cagr',
                 'scheme_name','fund_house','category','total_units','latest_nav'];
$sortBy  = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'value_now';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$where   = "h.portfolio_id = :pid AND h.is_active = 1";
$params  = [':pid' => $portfolioId];

if (!empty($_GET['category'])) {
    $where .= ' AND f.category = :cat';
    $params[':cat'] = $_GET['category'];
}
if (!empty($_GET['gain_type'])) {
    $where .= ' AND h.gain_type = :gt';
    $params[':gt'] = $_GET['gain_type'];
}

$sql = "
    SELECT
        h.id            AS holding_id,
        h.fund_id,
        h.total_units,
        h.avg_buy_nav,
        h.latest_nav,
        h.highest_nav,
        h.total_invested,
        h.value_now,
        h.gain_loss,
        h.gain_pct,
        h.cagr          AS xirr,
        h.first_purchase_date,
        h.folio_number,
        h.gain_type,
        h.ltcg_date,
        h.one_day_change_pct,
        h.one_day_change_val,
        h.drawdown_pct,
        f.scheme_name,
        f.fund_house,
        f.category,
        f.sub_category,
        f.expense_ratio,
        f.returns_1y,
        f.returns_3y,
        f.returns_5y
    FROM mf_holdings h
    JOIN funds f ON f.id = h.fund_id
    WHERE $where
    ORDER BY h.$sortBy $sortDir
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Fetch transactions for XIRR calculation ─────────────────────────────────
$holdingIds = array_column($holdings, 'holding_id');
$txnMap     = [];

if (!empty($holdingIds)) {
    $ph    = implode(',', array_fill(0, count($holdingIds), '?'));
    $txns  = $db->prepare("
        SELECT holding_id, tx_type, units, price_per_unit, amount, tx_date
        FROM mf_transactions
        WHERE holding_id IN ($ph) AND tx_type IN ('BUY','SELL','SWITCH_IN','SWITCH_OUT')
        ORDER BY tx_date ASC
    ");
    $txns->execute($holdingIds);
    foreach ($txns->fetchAll(PDO::FETCH_ASSOC) as $tx) {
        $txnMap[$tx['holding_id']][] = $tx;
    }
}

// ─── Recalculate XIRR per holding ────────────────────────────────────────────
foreach ($holdings as &$h) {
    $txns   = $txnMap[$h['holding_id']] ?? [];
    $amounts = [];
    $dates   = [];
    foreach ($txns as $tx) {
        $sign      = in_array($tx['tx_type'], ['BUY','SWITCH_IN']) ? -1 : 1;
        $amounts[] = $sign * (float)$tx['amount'];
        $dates[]   = $tx['tx_date'];
    }
    // Add current value as final positive cash flow
    if (!empty($amounts) && (float)$h['value_now'] > 0) {
        $amounts[] = (float)$h['value_now'];
        $dates[]   = date('Y-m-d');
    }
    if (count($amounts) >= 2) {
        $xirrVal = HoldingCalculator::xirr($amounts, $dates);
        if ($xirrVal !== null) {
            $h['xirr_calc'] = $xirrVal;
        }
    }
    $h['xirr_display'] = $h['xirr_calc'] ?? $h['xirr'];
}
unset($h);

// ─── Totals row ───────────────────────────────────────────────────────────────
$totInvested = array_sum(array_column($holdings, 'total_invested'));
$totValueNow = array_sum(array_column($holdings, 'value_now'));
$totGainLoss = $totValueNow - $totInvested;
$totGainPct  = $totInvested > 0 ? round(($totGainLoss / $totInvested) * 100, 2) : 0;

// ─── Build OpenXML XLSX ──────────────────────────────────────────────────────
// Column definitions: [header, width, type]
//   type: n=number, p=percent, s=string, d=date, f=formula
$COLS = [
    ['#',                    5,  'n'],
    ['Fund Name',           36,  's'],
    ['Fund House',          20,  's'],
    ['Category',            16,  's'],
    ['Sub-Category',        18,  's'],
    ['Folio',               14,  's'],
    ['Units',               14,  'n'],
    ['Avg Buy NAV (₹)',     16,  'n'],
    ['Current NAV (₹)',     16,  'n'],
    ['Peak NAV (₹)',        14,  'n'],
    ['Invested (₹)',        18,  'n'],
    ['Current Value (₹)',   18,  'n'],
    ['Gain/Loss (₹)',       18,  'n'],
    ['Gain % (formula)',    18,  'f'],   // Formula: =(L-K)/K*100
    ['XIRR % (approx)',     16,  'n'],
    ['1D Change %',         14,  'n'],
    ['1D Change (₹/unit)',  16,  'n'],
    ['Drawdown %',          14,  'n'],
    ['Type',                10,  's'],
    ['LTCG Date',           14,  'd'],
    ['First Purchase',      14,  'd'],
    ['Expense Ratio %',     16,  'n'],
    ['Returns 1Y %',        14,  'n'],
    ['Returns 3Y %',        14,  'n'],
    ['Returns 5Y %',        14,  'n'],
];

// Map column name to letter for formula use
$COL_INVESTED     = col_letter(11); // K
$COL_VALUE_NOW    = col_letter(12); // L
$COL_GAIN         = col_letter(13); // M

// ─── Shared strings ──────────────────────────────────────────────────────────
$strings     = [];
$strIndex    = [];

function shared_str(string $s): int
{
    global $strings, $strIndex;
    if (!isset($strIndex[$s])) {
        $strIndex[$s]  = count($strings);
        $strings[]     = $s;
    }
    return $strIndex[$s];
}

// Pre-register header strings + static strings
foreach ($COLS as [$hdr]) {
    shared_str($hdr);
}
// Report title
shared_str('WealthDash — Mutual Fund Holdings');
shared_str('Generated: ' . date('d M Y H:i'));
shared_str('Fund Name');
shared_str('TOTAL');
shared_str('');

// ─── Build worksheet rows ────────────────────────────────────────────────────
/*
 * We collect rows as arrays of cell specs:
 * ['t'=>'s','v'=><sharedStringIndex>]  for strings
 * ['t'=>'n','v'=><number>]              for numbers
 * ['t'=>'f','v'=><formula>]             for formulas
 * ['t'=>'d','v'=><dateString>]          for dates (stored as number)
 */

function date_to_serial(string $date): float
{
    // Excel serial: days since 1899-12-30
    $ts   = strtotime($date);
    if (!$ts) return 0;
    return ($ts / 86400) + 25569;
}

$sheetRows = []; // array of arrays of cells

// Row 1: Report title (merged later via mergeCells)
$sheetRows[] = [
    ['t' => 's', 'v' => shared_str('WealthDash — Mutual Fund Holdings')],
];

// Row 2: Generated date
$sheetRows[] = [
    ['t' => 's', 'v' => shared_str('Generated: ' . date('d M Y H:i'))],
];

// Row 3: blank
$sheetRows[] = [];

// Row 4: Headers
$headerRow = [];
foreach ($COLS as [$hdr]) {
    $headerRow[] = ['t' => 's', 'v' => shared_str($hdr)];
}
$sheetRows[] = $headerRow;

$dataStartRow = 5;  // 1-based, rows 1-4 are title/blank/header

foreach ($holdings as $i => $h) {
    $rowNum  = $dataStartRow + $i; // 1-based in sheet
    $row     = [];

    // #1 Serial
    $row[] = ['t' => 'n', 'v' => $i + 1];
    // #2 Fund Name
    $row[] = ['t' => 's', 'v' => shared_str($h['scheme_name'] ?? '')];
    // #3 Fund House
    $row[] = ['t' => 's', 'v' => shared_str($h['fund_house'] ?? '')];
    // #4 Category
    $row[] = ['t' => 's', 'v' => shared_str($h['category'] ?? '')];
    // #5 Sub-Category
    $row[] = ['t' => 's', 'v' => shared_str($h['sub_category'] ?? '')];
    // #6 Folio
    $row[] = ['t' => 's', 'v' => shared_str($h['folio_number'] ?? '')];
    // #7 Units
    $row[] = ['t' => 'n', 'v' => (float)($h['total_units'] ?? 0)];
    // #8 Avg Buy NAV
    $row[] = ['t' => 'n', 'v' => round((float)($h['avg_buy_nav'] ?? 0), 4)];
    // #9 Current NAV
    $row[] = ['t' => 'n', 'v' => round((float)($h['latest_nav'] ?? 0), 4)];
    // #10 Peak NAV
    $row[] = ['t' => 'n', 'v' => round((float)($h['highest_nav'] ?? 0), 4)];
    // #11 Invested (K)
    $row[] = ['t' => 'n', 'v' => round((float)($h['total_invested'] ?? 0), 2)];
    // #12 Current Value (L) = Units * Current NAV   FORMULA
    $row[] = ['t' => 'f', 'v' => "=G{$rowNum}*I{$rowNum}"];
    // #13 Gain/Loss (M) = L - K                     FORMULA
    $row[] = ['t' => 'f', 'v' => "=L{$rowNum}-K{$rowNum}"];
    // #14 Gain % (formula) = (L-K)/K*100            FORMULA
    $row[] = ['t' => 'f', 'v' => "=IF(K{$rowNum}>0,(L{$rowNum}-K{$rowNum})/K{$rowNum}*100,0)"];
    // #15 XIRR %
    $row[] = ['t' => 'n', 'v' => round((float)($h['xirr_display'] ?? 0), 4)];
    // #16 1D Change %
    $row[] = ['t' => 'n', 'v' => round((float)($h['one_day_change_pct'] ?? 0), 4)];
    // #17 1D Change ₹/unit
    $row[] = ['t' => 'n', 'v' => round((float)($h['one_day_change_val'] ?? 0), 4)];
    // #18 Drawdown %
    $row[] = ['t' => 'n', 'v' => round((float)($h['drawdown_pct'] ?? 0), 2)];
    // #19 Type
    $row[] = ['t' => 's', 'v' => shared_str($h['gain_type'] ?? '')];
    // #20 LTCG Date
    $ltcgDate = $h['ltcg_date'] ?? '';
    $row[] = $ltcgDate ? ['t' => 'd', 'v' => date_to_serial($ltcgDate), 'raw' => $ltcgDate] : ['t' => 's', 'v' => shared_str('')];
    // #21 First Purchase
    $fpDate = $h['first_purchase_date'] ?? '';
    $row[] = $fpDate ? ['t' => 'd', 'v' => date_to_serial($fpDate), 'raw' => $fpDate] : ['t' => 's', 'v' => shared_str('')];
    // #22 Expense Ratio
    $row[] = ['t' => 'n', 'v' => round((float)($h['expense_ratio'] ?? 0), 4)];
    // #23 Returns 1Y
    $row[] = ['t' => 'n', 'v' => round((float)($h['returns_1y'] ?? 0), 4)];
    // #24 Returns 3Y
    $row[] = ['t' => 'n', 'v' => round((float)($h['returns_3y'] ?? 0), 4)];
    // #25 Returns 5Y
    $row[] = ['t' => 'n', 'v' => round((float)($h['returns_5y'] ?? 0), 4)];

    $sheetRows[] = $row;
}

// Totals row
$totRow      = count($sheetRows) + 1;  // 1-based
$lastDataRow = $totRow - 1;
$totalsRow   = [];
$totalsRow[] = ['t' => 's', 'v' => shared_str('TOTAL')];
$totalsRow[] = ['t' => 's', 'v' => shared_str('')];
$totalsRow[] = ['t' => 's', 'v' => shared_str('')];
$totalsRow[] = ['t' => 's', 'v' => shared_str('')];
$totalsRow[] = ['t' => 's', 'v' => shared_str('')];
$totalsRow[] = ['t' => 's', 'v' => shared_str('')];
// Units total (G)
$totalsRow[] = ['t' => 'f', 'v' => "=SUM(G{$dataStartRow}:G{$lastDataRow})"];
// Avg Buy NAV blank
$totalsRow[] = ['t' => 's', 'v' => shared_str('')];
// Current NAV blank
$totalsRow[] = ['t' => 's', 'v' => shared_str('')];
// Peak blank
$totalsRow[] = ['t' => 's', 'v' => shared_str('')];
// Invested SUM (K)
$totalsRow[] = ['t' => 'f', 'v' => "=SUM(K{$dataStartRow}:K{$lastDataRow})"];
// Current Value SUM (L)
$totalsRow[] = ['t' => 'f', 'v' => "=SUM(L{$dataStartRow}:L{$lastDataRow})"];
// Gain/Loss SUM (M)
$totalsRow[] = ['t' => 'f', 'v' => "=SUM(M{$dataStartRow}:M{$lastDataRow})"];
// Gain% (N) = total gain / total invested * 100
$totalsRow[] = ['t' => 'f', 'v' => "=IF(K{$totRow}>0,(L{$totRow}-K{$totRow})/K{$totRow}*100,0)"];
// rest blank
for ($c = 15; $c <= 25; $c++) {
    $totalsRow[] = ['t' => 's', 'v' => shared_str('')];
}
$sheetRows[] = $totalsRow;

// ─── Build XML for shared strings ────────────────────────────────────────────
$ssXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
$ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
foreach ($strings as $s) {
    $ssXml .= '<si><t xml:space="preserve">' . xlsx_escape($s) . '</t></si>';
}
$ssXml .= '</sst>';

// ─── Build XML for styles ────────────────────────────────────────────────────
// Style indices:
//  0 = default
//  1 = header (bold, bg color, border)
//  2 = number 2dp
//  3 = number 4dp
//  4 = number 0dp (units)
//  5 = date (DD MMM YYYY)
//  6 = total row (bold, bg)
//  7 = title (large bold)
//  8 = positive gain (green)
//  9 = negative gain (red)
// 10 = formula cell (light blue bg)
$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="3">
    <numFmt numFmtId="164" formatCode="#,##0.00"/>
    <numFmt numFmtId="165" formatCode="#,##0.0000"/>
    <numFmt numFmtId="166" formatCode="DD-MMM-YYYY"/>
  </numFmts>
  <fonts count="4">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="14"/><name val="Calibri"/><color rgb="FF1F4E79"/></font>
    <font><sz val="11"/><name val="Calibri"/><color rgb="FF595959"/></font>
  </fonts>
  <fills count="6">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F1"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE2EFDA"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDFE1FF"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFB8CCE4"/></left>
      <right style="thin"><color rgb="FFB8CCE4"/></right>
      <top style="thin"><color rgb="FFB8CCE4"/></top>
      <bottom style="thin"><color rgb="FFB8CCE4"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="11">
    <xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0"   fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1">
      <alignment horizontal="right"/>
    </xf>
    <xf numFmtId="165" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1">
      <alignment horizontal="right"/>
    </xf>
    <xf numFmtId="1"   fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1">
      <alignment horizontal="right"/>
    </xf>
    <xf numFmtId="166" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1">
      <alignment horizontal="center"/>
    </xf>
    <xf numFmtId="0"   fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">
      <alignment wrapText="0"/>
    </xf>
    <xf numFmtId="164" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1">
      <alignment horizontal="right"/>
    </xf>
    <xf numFmtId="2"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <xf numFmtId="164" fontId="0" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1">
      <alignment horizontal="right"/>
    </xf>
    <xf numFmtId="164" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1">
      <alignment horizontal="center"/>
    </xf>
  </cellXfs>
</styleSheet>';

// ─── Build worksheet XML ─────────────────────────────────────────────────────
$numCols = count($COLS);
$lastCol = col_letter($numCols);

$wsXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
$wsXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

// Column widths
$wsXml .= '<cols>';
foreach ($COLS as $ci => [$hdr, $w]) {
    $c1 = $ci + 1;
    $wsXml .= "<col min=\"{$c1}\" max=\"{$c1}\" width=\"{$w}\" customWidth=\"1\"/>";
}
$wsXml .= '</cols>';

$wsXml .= '<sheetData>';

foreach ($sheetRows as $ri => $row) {
    $rowNum1 = $ri + 1;  // 1-based

    if (empty($row)) {
        $wsXml .= "<row r=\"{$rowNum1}\"/>";
        continue;
    }

    // Determine row height for header (row 4)
    $ht     = '';
    if ($rowNum1 === 4) {
        $ht = ' ht="30" customHeight="1"';
    } elseif ($rowNum1 === 1) {
        $ht = ' ht="22" customHeight="1"';
    }

    $wsXml .= "<row r=\"{$rowNum1}\"{$ht}>";

    foreach ($row as $ci => $cell) {
        $colLetter = col_letter($ci + 1);
        $ref       = $colLetter . $rowNum1;
        $t         = $cell['t'];
        $v         = $cell['v'];

        // Determine style index
        $s = 0;
        if ($rowNum1 === 1) {
            $s = 8;  // title style
        } elseif ($rowNum1 === 4) {
            $s = 1;  // header
        } elseif ($rowNum1 === count($sheetRows)) {
            // Totals row
            if ($t === 'f') {
                $s = 7; // bold total number
            } else {
                $s = 10; // bold string center
            }
        } elseif ($t === 'f') {
            $s = 9; // formula blue bg
        } elseif ($t === 'n') {
            // Decide number format by column position
            $colPos = $ci + 1;
            if ($colPos === 7) { $s = 4; }        // units: integer
            elseif (in_array($colPos, [8,9,10])) { $s = 3; } // NAV 4dp
            else { $s = 2; }                        // money 2dp
        } elseif ($t === 'd') {
            $s = 5;
        } else {
            $s = 6; // string cell
        }

        if ($t === 's') {
            $wsXml .= "<c r=\"{$ref}\" t=\"s\" s=\"{$s}\"><v>{$v}</v></c>";
        } elseif ($t === 'n') {
            $wsXml .= "<c r=\"{$ref}\" s=\"{$s}\"><v>{$v}</v></c>";
        } elseif ($t === 'f') {
            $wsXml .= "<c r=\"{$ref}\" s=\"{$s}\"><f>" . xlsx_escape($v) . "</f></c>";
        } elseif ($t === 'd') {
            $wsXml .= "<c r=\"{$ref}\" s=\"{$s}\"><v>{$v}</v></c>";
        }
    }

    $wsXml .= '</row>';
}

$wsXml .= '</sheetData>';

// Freeze panes: freeze first 4 rows (title+blank+header) + col B
$wsXml .= '<sheetViews>
  <sheetView workbookViewId="0">
    <pane ySplit="4" xSplit="1" topLeftCell="B5" activePane="bottomRight" state="frozen"/>
    <selection pane="bottomRight" activeCell="B5" sqref="B5"/>
  </sheetView>
</sheetViews>';

// Merge title row across all columns
$wsXml .= "<mergeCells count=\"2\">";
$wsXml .= "<mergeCell ref=\"A1:{$lastCol}1\"/>";
$wsXml .= "<mergeCell ref=\"A2:{$lastCol}2\"/>";
$wsXml .= "</mergeCells>";

// Auto filter on header row
$wsXml .= "<autoFilter ref=\"A4:{$lastCol}4\"/>";

$wsXml .= '</worksheet>';

// ─── Build workbook XML ───────────────────────────────────────────────────────
$wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <bookViews><workbookView activeTab="0"/></bookViews>
  <sheets>
    <sheet name="MF Holdings" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';

// ─── Relationships ────────────────────────────────────────────────────────────
$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

$pkgRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"   ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml"       ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/xl/styles.xml"              ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

// ─── Assemble XLSX (zip) ─────────────────────────────────────────────────────
$tmpFile = tempnam(sys_get_temp_dir(), 'wd_mf_') . '.xlsx';

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not create ZIP archive']);
    exit;
}

$zip->addFromString('[Content_Types].xml',           $contentTypes);
$zip->addFromString('_rels/.rels',                   $pkgRels);
$zip->addFromString('xl/workbook.xml',               $wbXml);
$zip->addFromString('xl/_rels/workbook.xml.rels',    $wbRels);
$zip->addFromString('xl/worksheets/sheet1.xml',      $wsXml);
$zip->addFromString('xl/sharedStrings.xml',          $ssXml);
$zip->addFromString('xl/styles.xml',                 $stylesXml);
$zip->close();

// ─── Send file ────────────────────────────────────────────────────────────────
ob_end_clean();

$filename = 'MF_Holdings_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($tmpFile);
unlink($tmpFile);
exit;
