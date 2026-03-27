<?php
/**
 * WealthDash — NPS Statement Downloader (t101)
 * GET /api/?action=nps_statement
 *
 * Params:
 *   format      = csv | html | pdf (pdf = html with print trigger)
 *   portfolio_id= int
 *   tier        = tier1 | tier2 | '' (all)
 *   fy          = '2024-25' | '' (all years)
 *   date_from   = YYYY-MM-DD
 *   date_to     = YYYY-MM-DD
 *   type        = statement | annual
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');
require_once APP_ROOT . '/includes/helpers.php';

$format      = clean($_GET['format']       ?? 'html');
$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
$tier        = clean($_GET['tier']         ?? '');
$fy          = clean($_GET['fy']           ?? '');
$dateFrom    = clean($_GET['date_from']    ?? '');
$dateTo      = clean($_GET['date_to']      ?? date('Y-m-d'));
$stmtType    = clean($_GET['type']         ?? 'statement');

if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);
if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid portfolio.');
}

// ── Determine date range ───────────────────────────────────────────────────────
if ($fy) {
    // FY: 2024-25 → 2024-04-01 to 2025-03-31
    [$fyY1, $fyY2Short] = explode('-', $fy);
    $fyY2 = strlen($fyY2Short) === 2 ? '20' . $fyY2Short : $fyY2Short;
    $dateFrom = $dateFrom ?: "{$fyY1}-04-01";
    $dateTo   = $dateTo   ?: "{$fyY2}-03-31";
}
if (!$dateFrom) $dateFrom = '2000-01-01';

// ── Portfolio info ─────────────────────────────────────────────────────────────
$portfolio = DB::fetchRow("SELECT * FROM portfolios WHERE id=?", [$portfolioId]);
$user      = DB::fetchRow("SELECT name, email FROM users WHERE id=?", [$userId]);

// ── Transactions query ─────────────────────────────────────────────────────────
$tierCond = $tier ? "AND t.tier = " . DB::pdo()->quote($tier) : '';
$txns = DB::fetchAll(
    "SELECT t.txn_date, t.contribution_type, t.tier, t.units, t.nav, t.amount,
            t.investment_fy, t.notes,
            s.scheme_name, s.pfm_name, s.asset_class,
            -- Cumulative units per scheme (running total up to this row)
            (SELECT SUM(t2.units) FROM nps_transactions t2
             WHERE t2.scheme_id = t.scheme_id
               AND t2.portfolio_id = t.portfolio_id
               AND t2.tier = t.tier
               AND t2.txn_date <= t.txn_date
               AND t2.id <= t.id) AS cumulative_units
     FROM nps_transactions t
     JOIN nps_schemes s ON s.id = t.scheme_id
     WHERE t.portfolio_id = ?
       AND t.txn_date BETWEEN ? AND ?
       {$tierCond}
     ORDER BY t.txn_date ASC, s.pfm_name, s.scheme_name",
    [$portfolioId, $dateFrom, $dateTo]
);

// ── Holdings summary (current snapshot) ───────────────────────────────────────
$holdingsSumm = DB::fetchAll(
    "SELECT h.tier, h.total_units, h.total_invested, h.latest_value, h.gain_loss,
            h.cagr, h.first_contribution_date,
            s.scheme_name, s.pfm_name, s.asset_class, s.latest_nav, s.latest_nav_date
     FROM nps_holdings h
     JOIN nps_schemes  s ON s.id = h.scheme_id
     WHERE h.portfolio_id = ?
     {$tierCond}
     ORDER BY s.pfm_name, h.tier, s.scheme_name",
    [$portfolioId]
);

// ── FY-wise annual summary ────────────────────────────────────────────────────
$fyBreakdown = DB::fetchAll(
    "SELECT t.investment_fy, t.contribution_type,
            COUNT(*) AS txn_count,
            SUM(t.amount) AS total_amount,
            SUM(t.units)  AS total_units
     FROM nps_transactions t
     JOIN portfolios p ON p.id = t.portfolio_id
     WHERE t.portfolio_id = ? {$tierCond}
     GROUP BY t.investment_fy, t.contribution_type
     ORDER BY t.investment_fy DESC, t.contribution_type",
    [$portfolioId]
);

// ── Totals ────────────────────────────────────────────────────────────────────
$totalInvested = array_sum(array_column($holdingsSumm, 'total_invested'));
$totalValue    = array_sum(array_column($holdingsSumm, 'latest_value'));
$totalGain     = $totalValue - $totalInvested;
$gainPct       = $totalInvested > 0 ? round($totalGain / $totalInvested * 100, 2) : 0;
$txnTotal      = array_sum(array_column($txns, 'amount'));
$txnSelf       = array_sum(array_map(fn($t) => $t['contribution_type']==='SELF' ? (float)$t['amount'] : 0, $txns));
$txnEmployer   = array_sum(array_map(fn($t) => $t['contribution_type']==='EMPLOYER' ? (float)$t['amount'] : 0, $txns));

// ── CSV format ────────────────────────────────────────────────────────────────
if ($format === 'csv') {
    $filename = 'NPS_Statement_' . ($portfolio['name'] ?? 'Portfolio') . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

    // Header info
    fputcsv($out, ['WealthDash — NPS Statement']);
    fputcsv($out, ['Portfolio', $portfolio['name'] ?? '']);
    fputcsv($out, ['Member', $user['name'] ?? '']);
    fputcsv($out, ['Period', $dateFrom . ' to ' . $dateTo]);
    fputcsv($out, ['Generated', date('d-m-Y H:i')]);
    fputcsv($out, []);

    // Holdings summary
    fputcsv($out, ['=== Current Holdings ===']);
    fputcsv($out, ['Scheme', 'PFM', 'Tier', 'Asset Class', 'Units', 'NAV (₹)', 'Invested (₹)', 'Current Value (₹)', 'Gain/Loss (₹)', 'CAGR %', 'First Contribution']);
    foreach ($holdingsSumm as $h) {
        fputcsv($out, [
            $h['scheme_name'], $h['pfm_name'],
            strtoupper($h['tier']), $h['asset_class'],
            number_format((float)$h['total_units'], 4),
            number_format((float)$h['latest_nav'], 4),
            number_format((float)$h['total_invested'], 2),
            number_format((float)$h['latest_value'], 2),
            number_format((float)$h['gain_loss'], 2),
            $h['cagr'] !== null ? number_format((float)$h['cagr'], 2) . '%' : 'N/A',
            $h['first_contribution_date'] ?? '',
        ]);
    }
    fputcsv($out, ['Total', '', '', '', '', '', number_format($totalInvested,2), number_format($totalValue,2), number_format($totalGain,2), $gainPct . '%', '']);
    fputcsv($out, []);

    // Transaction history
    fputcsv($out, ['=== Contribution History ===']);
    fputcsv($out, ['Date', 'Scheme', 'PFM', 'Tier', 'Asset Class', 'Type', 'NAV (₹)', 'Units', 'Amount (₹)', 'Cumulative Units', 'FY', 'Notes']);
    foreach ($txns as $t) {
        fputcsv($out, [
            $t['txn_date'], $t['scheme_name'], $t['pfm_name'],
            strtoupper($t['tier']), $t['asset_class'],
            $t['contribution_type'],
            number_format((float)$t['nav'], 4),
            number_format((float)$t['units'], 4),
            number_format((float)$t['amount'], 2),
            number_format((float)$t['cumulative_units'], 4),
            $t['investment_fy'] ?? '',
            $t['notes'] ?? '',
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Total Self Contribution', $txnSelf]);
    fputcsv($out, ['Total Employer Contribution', $txnEmployer]);
    fputcsv($out, ['Grand Total', $txnTotal]);

    fclose($out);
    exit;
}

// ── HTML / PDF format ─────────────────────────────────────────────────────────
$isPdf    = $format === 'pdf';
$rangeStr = date('d M Y', strtotime($dateFrom)) . ' to ' . date('d M Y', strtotime($dateTo));
$genDate  = date('d M Y, H:i');
$pName    = $portfolio['name'] ?? 'My Portfolio';
$uName    = $user['name'] ?? '';

// Do NOT use layout.php here — standalone printable page
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NPS Statement — <?= e($pName) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#1e293b;background:#fff;padding:24px}
h1{font-size:18px;font-weight:800;color:#0369a1;margin-bottom:2px}
h2{font-size:13px;font-weight:700;color:#1e293b;margin:18px 0 8px;padding-bottom:4px;border-bottom:2px solid #e2e8f0}
.meta{font-size:11px;color:#64748b;margin-bottom:4px}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:14px 0 20px}
.sum-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px}
.sum-val{font-size:18px;font-weight:800;color:#1e293b}
.sum-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-top:2px;font-weight:700}
.sum-sub{font-size:11px;color:#64748b;margin-top:2px}
table{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:12px}
th{background:#f1f5f9;padding:7px 8px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#64748b;border:1px solid #e2e8f0}
td{padding:6px 8px;border:1px solid #e2e8f0;vertical-align:top}
tr:nth-child(even) td{background:#fafbff}
.text-right{text-align:right}
.badge{font-size:10px;font-weight:700;padding:1px 6px;border-radius:99px;display:inline-block}
.tier1{background:#eff6ff;color:#1d4ed8}
.tier2{background:#faf5ff;color:#7e22ce}
.self{background:#f0fdf4;color:#15803d}
.employer{background:#fff7ed;color:#c2410c}
.pos{color:#15803d;font-weight:700}
.neg{color:#dc2626;font-weight:700}
.logo{display:flex;align-items:center;gap:8px;margin-bottom:14px}
.logo-badge{background:linear-gradient(135deg,#0369a1,#7c3aed);color:#fff;border-radius:8px;padding:6px 10px;font-size:14px;font-weight:800;letter-spacing:-.3px}
.print-btn{position:fixed;top:16px;right:16px;padding:8px 18px;background:#0369a1;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:12px;font-weight:700;z-index:100}
@media print{.print-btn{display:none}body{padding:12px}h2{page-break-after:avoid}table{page-break-inside:avoid}}
.tfoot-row td{background:#f1f5f9;font-weight:700}
.disclaimer{font-size:10px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:12px;line-height:1.7}
</style>
</head>
<body>
<?php if ($isPdf): ?>
<button class="print-btn" onclick="window.print()">🖨️ Print / Save PDF</button>
<?php endif; ?>

<div class="logo">
  <div class="logo-badge">WD</div>
  <div>
    <div style="font-size:15px;font-weight:800;color:#1e293b">WealthDash</div>
    <div style="font-size:11px;color:#64748b">National Pension System Statement</div>
  </div>
</div>

<h1>NPS Statement</h1>
<div class="meta"><strong>Portfolio:</strong> <?= e($pName) ?> &nbsp;|&nbsp; <strong>Member:</strong> <?= e($uName) ?></div>
<div class="meta"><strong>Period:</strong> <?= $rangeStr ?> &nbsp;|&nbsp; <strong>Tier:</strong> <?= $tier ? strtoupper($tier) : 'All (Tier I & II)' ?></div>
<div class="meta" style="color:#94a3b8">Generated: <?= $genDate ?></div>

<!-- Summary Cards -->
<div class="summary-grid">
  <div class="sum-card">
    <div class="sum-val">₹<?= number_format($totalInvested / 100000, 2) ?>L</div>
    <div class="sum-lbl">Total Invested</div>
  </div>
  <div class="sum-card">
    <div class="sum-val">₹<?= number_format($totalValue / 100000, 2) ?>L</div>
    <div class="sum-lbl">Current Value</div>
  </div>
  <div class="sum-card">
    <div class="sum-val <?= $totalGain >= 0 ? 'pos' : 'neg' ?>">
      <?= $totalGain >= 0 ? '+' : '' ?>₹<?= number_format(abs($totalGain), 0) ?>
    </div>
    <div class="sum-lbl">Total Gain/Loss</div>
    <div class="sum-sub"><?= ($gainPct >= 0 ? '+' : '') . $gainPct ?>%</div>
  </div>
  <div class="sum-card">
    <div class="sum-val"><?= count($holdingsSumm) ?></div>
    <div class="sum-lbl">Active Schemes</div>
  </div>
</div>

<!-- Holdings Summary -->
<h2>📊 Holdings Summary (as of today)</h2>
<table>
  <thead>
    <tr>
      <th>Scheme</th><th>PFM</th><th>Tier</th><th>Class</th>
      <th class="text-right">Units</th><th class="text-right">NAV (₹)</th>
      <th class="text-right">Invested (₹)</th><th class="text-right">Current Value (₹)</th>
      <th class="text-right">Gain/Loss (₹)</th><th class="text-right">CAGR %</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($holdingsSumm as $h): ?>
    <tr>
      <td><?= e($h['scheme_name']) ?></td>
      <td><?= e($h['pfm_name']) ?></td>
      <td><span class="badge <?= $h['tier'] ?>"><?= strtoupper($h['tier']) ?></span></td>
      <td><?= e($h['asset_class']) ?></td>
      <td class="text-right"><?= number_format((float)$h['total_units'], 4) ?></td>
      <td class="text-right"><?= number_format((float)$h['latest_nav'], 4) ?></td>
      <td class="text-right"><?= number_format((float)$h['total_invested'], 2) ?></td>
      <td class="text-right"><?= number_format((float)$h['latest_value'], 2) ?></td>
      <td class="text-right <?= (float)$h['gain_loss'] >= 0 ? 'pos' : 'neg' ?>">
        <?= ((float)$h['gain_loss'] >= 0 ? '+' : '') . number_format((float)$h['gain_loss'], 2) ?>
      </td>
      <td class="text-right <?= ($h['cagr'] ?? 0) >= 0 ? 'pos' : 'neg' ?>">
        <?= $h['cagr'] !== null ? (((float)$h['cagr'] >= 0 ? '+' : '') . number_format((float)$h['cagr'], 2) . '%') : 'N/A' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="tfoot-row">
      <td colspan="6"><strong>Total</strong></td>
      <td class="text-right"><strong>₹<?= number_format($totalInvested, 2) ?></strong></td>
      <td class="text-right"><strong>₹<?= number_format($totalValue, 2) ?></strong></td>
      <td class="text-right <?= $totalGain >= 0 ? 'pos' : 'neg' ?>"><strong><?= ($totalGain >= 0 ? '+' : '') . '₹' . number_format($totalGain, 2) ?></strong></td>
      <td class="text-right <?= $gainPct >= 0 ? 'pos' : 'neg' ?>"><strong><?= ($gainPct >= 0 ? '+' : '') . $gainPct ?>%</strong></td>
    </tr>
  </tfoot>
</table>

<!-- FY Breakdown -->
<?php if (!empty($fyBreakdown)): ?>
<h2>📅 Year-wise Contribution Summary</h2>
<table>
  <thead>
    <tr><th>Financial Year</th><th>Type</th><th class="text-right">Transactions</th><th class="text-right">Units</th><th class="text-right">Amount (₹)</th></tr>
  </thead>
  <tbody>
    <?php foreach ($fyBreakdown as $fy): ?>
    <tr>
      <td><?= e($fy['investment_fy'] ?? '—') ?></td>
      <td><span class="badge <?= strtolower($fy['contribution_type']) ?>"><?= e($fy['contribution_type']) ?></span></td>
      <td class="text-right"><?= (int)$fy['txn_count'] ?></td>
      <td class="text-right"><?= number_format((float)$fy['total_units'], 4) ?></td>
      <td class="text-right">₹<?= number_format((float)$fy['total_amount'], 2) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- Transaction History -->
<?php if (!empty($txns)): ?>
<h2>📋 Contribution History (<?= $rangeStr ?>)</h2>
<table>
  <thead>
    <tr>
      <th>Date</th><th>Scheme</th><th>PFM</th><th>Tier</th><th>Type</th>
      <th class="text-right">NAV (₹)</th><th class="text-right">Units</th>
      <th class="text-right">Amount (₹)</th><th class="text-right">Cumul. Units</th><th>FY</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($txns as $t): ?>
    <tr>
      <td><?= date('d-m-Y', strtotime($t['txn_date'])) ?></td>
      <td><?= e($t['scheme_name']) ?></td>
      <td><?= e($t['pfm_name']) ?></td>
      <td><span class="badge <?= str_replace(' ','_',$t['tier']) ?>"><?= strtoupper($t['tier']) ?></span></td>
      <td><span class="badge <?= strtolower($t['contribution_type']) ?>"><?= e($t['contribution_type']) ?></span></td>
      <td class="text-right"><?= number_format((float)$t['nav'], 4) ?></td>
      <td class="text-right"><?= number_format((float)$t['units'], 4) ?></td>
      <td class="text-right">₹<?= number_format((float)$t['amount'], 2) ?></td>
      <td class="text-right"><?= number_format((float)$t['cumulative_units'], 4) ?></td>
      <td><?= e($t['investment_fy'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="tfoot-row">
      <td colspan="7"><strong>Total</strong> (Self: ₹<?= number_format($txnSelf,2) ?> | Employer: ₹<?= number_format($txnEmployer,2) ?>)</td>
      <td class="text-right"><strong>₹<?= number_format($txnTotal, 2) ?></strong></td>
      <td colspan="2"></td>
    </tr>
  </tfoot>
</table>
<?php else: ?>
<p style="color:#64748b;padding:16px 0">No transactions found for selected period.</p>
<?php endif; ?>

<div class="disclaimer">
  <strong>Disclaimer:</strong> This statement is generated from data entered in WealthDash and is for reference purposes only.
  For official PFRDA statements, please visit your NPS CRA (NSDL/KARVY) or npstrust.org.in.
  NAV values and returns are indicative. Past performance does not guarantee future results.
  Please consult a SEBI-registered investment advisor before making investment decisions.
</div>

<?php if ($isPdf): ?>
<script>setTimeout(() => window.print(), 500);</script>
<?php endif; ?>
</body>
</html>
<?php
exit;
