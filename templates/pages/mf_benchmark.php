<?php
/**
 * WealthDash — Benchmark Comparison Page (tv11)
 * Fund NAV vs Index (Nifty 50 / Sensex / Midcap etc.) with alpha calculation
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Benchmark Comparison';
$activePage  = 'mf_benchmark';

$fundId  = (int)($_GET['fund_id'] ?? 0);
$period  = in_array($_GET['period'] ?? '', ['1M','3M','6M','1Y','3Y','5Y']) ? $_GET['period'] : '1Y';

ob_start();
?>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">Benchmark Comparison</h1>
    <p class="page-subtitle">Fund performance vs Index — alpha, outperformance &amp; rolling returns</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/templates/pages/mf_holdings.php" class="btn btn-ghost btn-sm">← Holdings</a>
  </div>
</div>

<!-- ═══ FUND SELECTOR + PERIOD ═══ -->
<div class="card" style="margin-bottom:16px;overflow:visible;">
  <div class="card-body" style="padding:14px 16px;overflow:visible;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">

      <div style="flex:1;min-width:260px;position:relative;">
        <label class="form-label" style="margin-bottom:4px;">Fund</label>
        <input type="text" id="bmFundSearch" class="form-control"
               placeholder="Search fund name or scheme code…"
               autocomplete="off" value="">
        <div id="bmFundDropdown"
             style="display:none;position:absolute;left:0;right:0;top:100%;
                    background:var(--bg-card);border:1px solid var(--border-color);
                    border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);
                    max-height:260px;overflow-y:auto;z-index:2000;margin-top:2px;"></div>
        <input type="hidden" id="bmFundId" value="<?= $fundId ?>">
      </div>

      <div>
        <label class="form-label" style="margin-bottom:4px;">Benchmark</label>
        <select id="bmBenchmarkSelect" class="filter-select" style="min-width:160px;">
          <option value="">Auto (by category)</option>
          <option value="^NSEI">Nifty 50</option>
          <option value="^BSESN">BSE Sensex</option>
          <option value="^NSMIDCP">Nifty Midcap 150</option>
          <option value="^NSSC250">Nifty Smallcap 250</option>
          <option value="^NSNXT50">Nifty Next 50</option>
        </select>
      </div>

      <div>
        <label class="form-label" style="margin-bottom:4px;">Period</label>
        <div style="display:flex;gap:4px;" id="bmPeriodBtns">
          <?php foreach (['1M','3M','6M','1Y','3Y','5Y'] as $p): ?>
          <button class="btn btn-sm <?= $p === $period ? 'btn-primary' : 'btn-ghost' ?>"
                  data-period="<?= $p ?>" onclick="setBmPeriod('<?= $p ?>')"><?= $p ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <button class="btn btn-primary btn-sm" id="btnLoadBenchmark" style="margin-bottom:1px;">
        Compare
      </button>
    </div>
  </div>
</div>

<!-- ═══ ALPHA SUMMARY CARDS ═══ -->
<div class="stats-grid" style="margin-bottom:16px;" id="bmStatsGrid" hidden>
  <div class="stat-card">
    <div class="stat-label">Fund Return</div>
    <div class="stat-value" id="bmFundReturn">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label" id="bmBenchLabel">Benchmark Return</div>
    <div class="stat-value" id="bmBenchReturn">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Alpha</div>
    <div class="stat-value" id="bmAlpha">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Outperformed</div>
    <div class="stat-value" id="bmOutperformed">—</div>
  </div>
</div>

<!-- ═══ MAIN CHART ═══ -->
<div class="card" style="margin-bottom:16px;" id="bmChartCard" hidden>
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <h3 class="card-title" style="margin:0;" id="bmChartTitle">Fund vs Benchmark (normalized to 100)</h3>
    <div style="display:flex;gap:12px;font-size:12px;" id="bmChartLegend"></div>
  </div>
  <div class="card-body" style="padding:8px 16px 16px;">
    <canvas id="bmChart" height="80"></canvas>
  </div>
</div>

<!-- ═══ ALPHA ACROSS ALL PERIODS ═══ -->
<div class="card" style="margin-bottom:16px;" id="bmAlphaCard" hidden>
  <div class="card-header">
    <h3 class="card-title" style="margin:0;">Alpha Across Periods</h3>
  </div>
  <div class="table-wrapper">
    <table class="table table-hover" id="bmAlphaTable">
      <thead>
        <tr>
          <th>Period</th>
          <th class="text-right">Fund Return</th>
          <th class="text-right" id="bmAlphaThBench">Benchmark Return</th>
          <th class="text-right">Alpha</th>
          <th class="text-center">Result</th>
        </tr>
      </thead>
      <tbody id="bmAlphaBody">
        <tr><td colspan="5" class="text-center" style="padding:30px;">
          <div class="spinner"></div>
        </td></tr>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <div id="bmConsistencyBadge" style="font-size:13px;"></div>
  </div>
</div>

<!-- ═══ BENCHMARK ASSIGN (per-fund override) ═══ -->
<div class="card" id="bmAssignCard" hidden>
  <div class="card-header">
    <h3 class="card-title" style="margin:0;">Assign Benchmark to Fund</h3>
  </div>
  <div class="card-body" style="padding:14px 16px;">
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">
      Override the auto-detected benchmark for this fund. Saved permanently.
    </p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <select id="bmAssignSelect" class="filter-select" style="min-width:180px;">
        <option value="^NSEI">Nifty 50</option>
        <option value="^BSESN">BSE Sensex</option>
        <option value="^NSMIDCP">Nifty Midcap 150</option>
        <option value="^NSSC250">Nifty Smallcap 250</option>
        <option value="^NSNXT50">Nifty Next 50</option>
        <option value="^CRISIL">CRISIL Bond (proxy)</option>
      </select>
      <button class="btn btn-outline btn-sm" id="btnBmAssign">Save Benchmark</button>
      <span id="bmAssignMsg" style="font-size:12px;color:var(--success);"></span>
    </div>
  </div>
</div>

<!-- ═══ EMPTY STATE ═══ -->
<div id="bmEmptyState" style="text-align:center;padding:60px 20px;">
  <svg width="48" height="48" fill="none" stroke="var(--text-muted)" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom:12px;">
    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
  </svg>
  <p style="color:var(--text-muted);margin:0;">Search for a fund and click <strong>Compare</strong> to see benchmark analysis</p>
</div>

<!-- ═══ LOADING STATE ═══ -->
<div id="bmLoading" hidden style="text-align:center;padding:60px;">
  <div class="spinner" style="width:32px;height:32px;margin:0 auto 12px;"></div>
  <p style="color:var(--text-muted);">Fetching benchmark data…</p>
</div>

<!-- ═══ ERROR STATE ═══ -->
<div id="bmError" hidden style="padding:20px;">
  <div class="alert alert-danger" id="bmErrorMsg"></div>
</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="' . APP_URL . '/public/js/mf_benchmark.js?v=' . time() . '"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  initBenchmarkPage(' . $fundId . ', "' . $period . '");
});
</script>';

require_once APP_ROOT . '/templates/layout.php';
