<?php
/**
 * WealthDash — SIP vs Lumpsum Historical Backtest Page (t234)
 * Depends on: t160 (nav_history table), nav_history data
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'SIP vs Lumpsum Backtest';
$activePage  = 'mf_sip_backtest';

$fundId = (int)($_GET['fund_id'] ?? 0);
$period = in_array($_GET['period'] ?? '', ['1Y','2Y','3Y','5Y','7Y','10Y','15Y','20Y']) ? $_GET['period'] : '10Y';

ob_start();
?>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">SIP vs Lumpsum Backtest</h1>
    <p class="page-subtitle">Historical simulation — real NAV data, real performance</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/templates/pages/mf_holdings.php" class="btn btn-ghost btn-sm">← Holdings</a>
    <a href="<?= APP_URL ?>/templates/pages/mf_benchmark.php" class="btn btn-ghost btn-sm">Benchmark →</a>
  </div>
</div>

<!-- ═══ PARAMETERS PANEL ═══ -->
<div class="card" style="margin-bottom:16px;overflow:visible;">
  <div class="card-body" style="padding:16px;overflow:visible;">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">

      <!-- Fund Search -->
      <div style="flex:1;min-width:260px;position:relative;">
        <label class="form-label" style="margin-bottom:4px;">Fund *</label>
        <input type="text" id="btFundSearch" class="form-control"
               placeholder="Type fund name…" autocomplete="off">
        <div id="btFundDropdown"
             style="display:none;position:absolute;left:0;right:0;top:100%;
                    background:var(--bg-card);border:1px solid var(--border-color);
                    border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);
                    max-height:260px;overflow-y:auto;z-index:2000;margin-top:2px;"></div>
        <input type="hidden" id="btFundId" value="<?= $fundId ?>">
        <div id="btFundInfo" style="font-size:11px;color:var(--text-muted);margin-top:3px;min-height:16px;"></div>
      </div>

      <!-- Monthly SIP Amount -->
      <div style="flex:0 0 160px;">
        <label class="form-label" style="margin-bottom:4px;">Monthly SIP (₹)</label>
        <input type="number" id="btSipAmount" class="form-control"
               value="5000" min="500" step="500" placeholder="5000">
      </div>

      <!-- Period -->
      <div style="flex:0 0 auto;">
        <label class="form-label" style="margin-bottom:4px;">Period</label>
        <div style="display:flex;gap:4px;flex-wrap:wrap;" id="btPeriodBtns">
          <?php foreach (['1Y','2Y','3Y','5Y','7Y','10Y','15Y','20Y'] as $p): ?>
          <button class="btn btn-sm <?= $p === $period ? 'btn-primary' : 'btn-ghost' ?>"
                  data-period="<?= $p ?>" onclick="setBtPeriod('<?= $p ?>')"><?= $p ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- SIP Day -->
      <div style="flex:0 0 110px;">
        <label class="form-label" style="margin-bottom:4px;">SIP Day</label>
        <select id="btSipDay" class="form-control" style="padding:8px;">
          <?php foreach ([1,5,7,10,15,20,25,28] as $d): ?>
          <option value="<?= $d ?>" <?= $d === 1 ? 'selected' : '' ?>><?= $d ?><?= match($d){1=>'st',2=>'nd',3=>'rd',default=>'th'} ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button class="btn btn-primary" id="btnRunBacktest" style="margin-bottom:1px;">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"
             viewBox="0 0 24 24" style="margin-right:5px;vertical-align:-2px;">
          <polygon points="5 3 19 12 5 21 5 3"/>
        </svg>
        Run Backtest
      </button>

    </div>
  </div>
</div>

<!-- ═══ LOADING ═══ -->
<div id="btLoading" hidden style="text-align:center;padding:60px;">
  <div class="spinner" style="width:32px;height:32px;margin:0 auto 12px;"></div>
  <p style="color:var(--text-muted);">Running historical simulation…</p>
</div>

<!-- ═══ ERROR ═══ -->
<div id="btError" hidden style="padding:0 0 16px;">
  <div class="alert alert-danger" id="btErrorMsg" style="margin:0;"></div>
</div>

<!-- ═══ RESULTS ═══ -->
<div id="btResults" hidden>

  <!-- Summary Cards -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;" id="btCompareGrid">

    <!-- SIP Card -->
    <div class="card" id="btSipCard" style="border:2px solid transparent;">
      <div class="card-body" style="padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
          <div>
            <div style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);">SIP</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;" id="btSipSubtitle">Monthly investment</div>
          </div>
          <div id="btSipWinnerBadge" style="display:none;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;"></div>
        </div>
        <div style="margin-bottom:12px;">
          <div style="font-size:12px;color:var(--text-muted);">Total Invested</div>
          <div style="font-size:22px;font-weight:700;" id="btSipInvested">—</div>
        </div>
        <div style="margin-bottom:12px;">
          <div style="font-size:12px;color:var(--text-muted);">Final Value</div>
          <div style="font-size:28px;font-weight:700;color:var(--success);" id="btSipFinal">—</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
          <div>
            <div style="font-size:11px;color:var(--text-muted);">Gain</div>
            <div style="font-size:15px;font-weight:600;" id="btSipGain">—</div>
          </div>
          <div>
            <div style="font-size:11px;color:var(--text-muted);">XIRR</div>
            <div style="font-size:15px;font-weight:600;" id="btSipXirr">—</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Lumpsum Card -->
    <div class="card" id="btLsCard" style="border:2px solid transparent;">
      <div class="card-body" style="padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
          <div>
            <div style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);">Lumpsum</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;" id="btLsSubtitle">One-time investment</div>
          </div>
          <div id="btLsWinnerBadge" style="display:none;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;"></div>
        </div>
        <div style="margin-bottom:12px;">
          <div style="font-size:12px;color:var(--text-muted);">Total Invested</div>
          <div style="font-size:22px;font-weight:700;" id="btLsInvested">—</div>
        </div>
        <div style="margin-bottom:12px;">
          <div style="font-size:12px;color:var(--text-muted);">Final Value</div>
          <div style="font-size:28px;font-weight:700;color:var(--success);" id="btLsFinal">—</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
          <div>
            <div style="font-size:11px;color:var(--text-muted);">Gain</div>
            <div style="font-size:15px;font-weight:600;" id="btLsGain">—</div>
          </div>
          <div>
            <div style="font-size:11px;color:var(--text-muted);">CAGR</div>
            <div style="font-size:15px;font-weight:600;" id="btLsCagr">—</div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- Verdict Banner -->
  <div id="btVerdict" class="card" style="margin-bottom:16px;background:var(--bg-secondary);">
    <div class="card-body" style="padding:14px 20px;display:flex;align-items:center;gap:12px;">
      <div id="btVerdictEmoji" style="font-size:24px;"></div>
      <div>
        <div style="font-weight:600;" id="btVerdictTitle">—</div>
        <div style="font-size:13px;color:var(--text-muted);" id="btVerdictDesc">—</div>
      </div>
    </div>
  </div>

  <!-- Growth Chart -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Portfolio Growth Over Time</h3>
      <div style="display:flex;gap:12px;font-size:12px;">
        <span style="display:flex;align-items:center;gap:5px;">
          <span style="width:12px;height:3px;background:var(--primary);display:inline-block;border-radius:2px;"></span>SIP
        </span>
        <span style="display:flex;align-items:center;gap:5px;">
          <span style="width:12px;height:3px;background:var(--warning,#f59e0b);display:inline-block;border-radius:2px;"></span>Lumpsum
        </span>
        <span style="display:flex;align-items:center;gap:5px;">
          <span style="width:12px;height:3px;background:var(--text-muted);display:inline-block;border-radius:2px;border-style:dashed;"></span>Invested
        </span>
      </div>
    </div>
    <div class="card-body" style="padding:8px 16px 16px;">
      <canvas id="btGrowthChart" height="80"></canvas>
    </div>
  </div>

  <!-- Rolling Backtest + Best Entry (tabs) -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="border-bottom:1px solid var(--border-color);">
      <div style="display:flex;gap:0;">
        <button class="btn btn-ghost btn-sm" id="tabRolling"
                onclick="switchBtTab('rolling')"
                style="border-radius:6px 6px 0 0;border-bottom:2px solid var(--primary);">
          Rolling Windows
        </button>
        <button class="btn btn-ghost btn-sm" id="tabBestEntry"
                onclick="switchBtTab('bestentry')"
                style="border-radius:6px 6px 0 0;">
          Best Entry Points
        </button>
      </div>
    </div>

    <!-- Rolling Tab -->
    <div id="tabContentRolling">
      <div class="card-body" style="padding:12px 16px 8px;">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span style="font-size:13px;color:var(--text-muted);">Rolling window:</span>
          <?php foreach (['1Y','3Y','5Y'] as $w): ?>
          <button class="btn btn-ghost btn-sm" data-window="<?= $w ?>"
                  onclick="loadRolling('<?= $w ?>')"><?= $w ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="btRollingStats" style="padding:0 16px 12px;display:none;">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:12px;">
          <div class="stat-card" style="padding:12px;">
            <div class="stat-label">Total Windows</div>
            <div class="stat-value" id="btRollingTotal" style="font-size:20px;">—</div>
          </div>
          <div class="stat-card" style="padding:12px;">
            <div class="stat-label">SIP Wins</div>
            <div class="stat-value" style="font-size:20px;color:var(--primary);" id="btRollingSipWins">—</div>
          </div>
          <div class="stat-card" style="padding:12px;">
            <div class="stat-label">Lumpsum Wins</div>
            <div class="stat-value" style="font-size:20px;color:var(--warning,#f59e0b);" id="btRollingLsWins">—</div>
          </div>
          <div class="stat-card" style="padding:12px;">
            <div class="stat-label">SIP Win %</div>
            <div class="stat-value" style="font-size:20px;" id="btRollingSipPct">—</div>
          </div>
        </div>
        <div id="btRollingVerdict" style="font-size:13px;color:var(--text-muted);padding:8px 12px;background:var(--bg-secondary);border-radius:6px;"></div>
      </div>
      <div id="btRollingLoading" style="text-align:center;padding:30px;display:none;">
        <div class="spinner"></div>
      </div>
    </div>

    <!-- Best Entry Tab -->
    <div id="tabContentBestEntry" style="display:none;">
      <div class="card-body" style="padding:14px 16px 8px;">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <label class="form-label" style="margin:0;">Lumpsum Amount (₹)</label>
          <input type="number" id="btLsAmount" class="form-control"
                 value="100000" min="10000" step="10000"
                 style="width:130px;padding:6px 10px;">
          <button class="btn btn-outline btn-sm" onclick="loadBestEntry()">Analyse</button>
        </div>
      </div>
      <div id="btBestEntryContent" style="padding:0 16px 16px;display:none;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px;">
          <div>
            <div style="font-size:12px;font-weight:600;color:var(--success);margin-bottom:8px;">✅ Top 5 Best Entry Dates</div>
            <table class="table table-hover" style="font-size:12px;">
              <thead><tr><th>Date</th><th class="text-right">Final Value</th><th class="text-right">CAGR</th></tr></thead>
              <tbody id="btBestEntryBest"></tbody>
            </table>
          </div>
          <div>
            <div style="font-size:12px;font-weight:600;color:var(--danger);margin-bottom:8px;">⚠️ Top 5 Worst Entry Dates</div>
            <table class="table table-hover" style="font-size:12px;">
              <thead><tr><th>Date</th><th class="text-right">Final Value</th><th class="text-right">CAGR</th></tr></thead>
              <tbody id="btBestEntryWorst"></tbody>
            </table>
          </div>
        </div>
        <div id="btBestEntryInsight"
             style="margin-top:12px;font-size:13px;color:var(--text-muted);
                    padding:10px 14px;background:var(--bg-secondary);border-radius:6px;"></div>
      </div>
      <div id="btBestEntryLoading" style="text-align:center;padding:30px;display:none;">
        <div class="spinner"></div>
      </div>
    </div>

  </div><!-- end tabs card -->

</div><!-- #btResults -->

<!-- ═══ EMPTY STATE ═══ -->
<div id="btEmpty" style="text-align:center;padding:60px 20px;">
  <svg width="48" height="48" fill="none" stroke="var(--text-muted)" stroke-width="1.5"
       viewBox="0 0 24 24" style="margin-bottom:12px;">
    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
    <polyline points="17 6 23 6 23 12"/>
  </svg>
  <p style="color:var(--text-muted);margin:0;">Search for a fund and click <strong>Run Backtest</strong></p>
  <p style="color:var(--text-muted);font-size:12px;margin-top:6px;">Uses real historical NAV data from your database</p>
</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="' . APP_URL . '/public/js/mf_sip_vs_lumpsum.js?v=' . time() . '"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  initSipLumpsumBacktest(' . $fundId . ', "' . $period . '");
});
</script>';

require_once APP_ROOT . '/templates/layout.php';
