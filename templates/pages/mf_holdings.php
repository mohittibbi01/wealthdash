<?php
/**
 * WealthDash — Mutual Fund Holdings Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Mutual Funds';
$activePage  = 'mf_holdings';

$db = DB::conn();
// ── Resolve portfolio for current user ──────────────────────
$portfolioId = get_user_portfolio_id((int)$currentUser['id']);

$summaryStmt = $db->prepare("
    SELECT COUNT(DISTINCT h.fund_id) AS fund_count,
           SUM(h.total_invested) AS total_invested,
           SUM(h.value_now) AS value_now,
           SUM(h.gain_loss) AS gain_loss
    FROM mf_holdings h
    JOIN portfolios p ON p.id = h.portfolio_id
    WHERE p.user_id = ? AND h.is_active = 1
");
$summaryStmt->execute([$currentUser['id']]);
$summary = $summaryStmt->fetch();

$totalInvested = (float)($summary['total_invested'] ?? 0);
$valueNow      = (float)($summary['value_now'] ?? 0);
$gainLoss      = (float)($summary['gain_loss'] ?? 0);
$gainPct       = $totalInvested > 0 ? round(($gainLoss / $totalInvested) * 100, 2) : 0;
$fundCount     = (int)($summary['fund_count'] ?? 0);

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Mutual Funds</h1>
    <p class="page-subtitle">Mutual fund holdings &amp; transactions</p>
  </div>
  <div class="page-header-actions">
    <!-- t143: Zoom Out Toggle -->
    <button class="btn btn-ghost" id="btnZoomOut" onclick="toggleZoomOut()" title="Zoom Out — Long-term view, hide daily noise">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
      Zoom Out
    </button>
    <!-- t140: Panic Mode -->
    <button class="btn btn-ghost" id="btnPanicMode" onclick="togglePanicMode()" title="Panic Mode — Market crash? Focus on units, not losses">
      🧘 Calm Mode
    </button>
    <!-- t112: Fund Finder Quick Add -->
    <button class="btn btn-ghost" id="btnFindFund" onclick="openFundFinderModal()" title="Search and add any MF fund">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
      Find & Add Fund
    </button>
    <button class="btn btn-ghost" id="btnImportCsv">
      <!-- t03: Upload / import icon → arrow pointing UP into tray -->
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="17 8 12 3 7 8"/>
        <line x1="12" y1="3" x2="12" y2="15"/>
      </svg>
      Import CSV
    </button>
    <button class="btn btn-ghost" id="btnDownloadExcel" title="Download Excel">
      <!-- t03: Excel download icon -->
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="8" y1="13" x2="16" y2="13"/>
        <line x1="8" y1="17" x2="16" y2="17"/>
        <line x1="8" y1="9" x2="10" y2="9"/>
      </svg>
      Download Excel
    </button>
    <!-- t186: Print/PDF Holdings -->
    <button class="btn btn-ghost" id="btnPrintHoldings" onclick="printHoldings()" title="Print / Save as PDF">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="6 9 6 2 18 2 18 9"/>
        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
        <rect x="6" y="14" width="12" height="8"/>
      </svg>
      Print / PDF
    </button>
    <button class="btn btn-primary" id="btnAddTransaction">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Transaction
    </button>
    <!-- t170: SIP Return Analysis button -->
    <button class="btn btn-ghost" id="btnSipAnalysis" onclick="openSipReturnAnalysis(0,'My Portfolio',0,null,<?= json_encode($totalInvested) ?>,<?= json_encode($valueNow) ?>)" title="SIP vs Lump Sum — Kaunsa better tha?">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      SIP Analysis
    </button>
    <button class="btn btn-primary" id="btnAddSipHoldings" onclick="openQuickSip(0,'',0,'','')">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add SIP/SWP
    </button>
  </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:24px;">

  <!-- Total Invested -->
  <div class="stat-card">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
      <span style="font-size:22px;line-height:1;">💰</span>
      <div class="stat-label" style="margin:0;">Total Invested</div>
    </div>
    <div class="stat-value" id="mfTotalInvested"><?= format_inr($totalInvested) ?></div>
  </div>

  <!-- Current Value -->
  <div class="stat-card">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
      <span style="line-height:1;display:flex;align-items:center;">
        <!-- fa-hand-holding-usd style icon -->
        <svg width="30" height="26" viewBox="0 0 576 512" xmlns="http://www.w3.org/2000/svg">
          <!-- hand -->
          <path fill="#f59e0b" d="M271.06 144.3l-32.89-9.9c-5.77-1.74-9.7-7.2-9.7-13.35 0-7.68 5.92-13.91 13.19-13.91h20.6c5.45 0 10.65 1.73 14.97 4.93 2.64 1.97 6.21 1.67 8.53-.56l12.16-11.86c2.9-2.83 2.62-7.57-.57-10.06-8.28-6.39-18.29-10.2-28.29-11.2V64c0-4.42-3.58-8-8-8h-16c-4.42 0-8 3.58-8 8v19.43c-22.19 2.29-39.93 21.19-39.93 44.28 0 19.47 12.32 36.88 30.61 42.64l32.89 9.9c5.77 1.74 9.7 7.2 9.7 13.35 0 7.68-5.92 13.91-13.19 13.91h-20.6c-5.45 0-10.65-1.73-14.97-4.93-2.64-1.97-6.21-1.67-8.53.56l-12.16 11.86c-2.9 2.83-2.62 7.57.57 10.06 8.28 6.39 18.29 10.2 28.29 11.2V256c0 4.42 3.58 8 8 8h16c4.42 0 8-3.58 8-8v-19.43c22.19-2.29 39.93-21.19 39.93-44.28 0-19.47-12.32-36.88-30.61-42.64z"/>
          <!-- holding hand base -->
          <path fill="#fbbf24" d="M544 288c-17.67 0-34.47 5.1-48.64 14.47l-54.9 36.59c-4.88-34.29-34.47-60.78-70.46-60.78H192c-9.87 0-19.44 2.78-27.72 7.86L120.35 312H80c-26.47 0-48 21.53-48 48v112c0 8.84 7.16 16 16 16h48c8.84 0 16-7.16 16-16v-8h279.05c26.55 0 52.36-9.93 72.28-27.94l96.79-89.69c6.57-6.09 10.46-14.62 10.4-23.5-.15-18.1-15.23-32.87-33.52-32.87z"/>
        </svg>
      </span>
      <div class="stat-label" style="margin:0;">Current Value</div>
    </div>
    <div class="stat-value" id="mfValueNow"><?= format_inr($valueNow) ?></div>
  </div>

  <!-- Gain / Loss -->
  <div class="stat-card">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
      <span id="mfGainIcon">
        <?php if ($gainLoss >= 0): ?>
          <svg width="26" height="24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
            <polyline points="1,17 6,10 10,14 15,7 19,11 23,4"/>
            <polyline points="19,4 23,4 23,8"/>
          </svg>
        <?php else: ?>
          <svg width="26" height="24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
            <polyline points="1,7 6,14 10,10 15,17 19,13 23,20"/>
            <polyline points="19,20 23,20 23,16"/>
          </svg>
        <?php endif; ?>
      </span>
      <div class="stat-label" style="margin:0;">Gain / Loss</div>
    </div>
    <div class="stat-value <?= $gainLoss >= 0 ? 'positive' : 'negative' ?>" id="mfGainLoss">
      <?= ($gainLoss >= 0 ? '+' : '') . format_inr($gainLoss) ?>
    </div>
    <div id="mfGainPct" style="font-size:13px;font-weight:500;margin-top:3px;color:<?= $gainLoss >= 0 ? 'var(--gain,#16a34a)' : 'var(--loss,#dc2626)' ?>;">
      (<?= $gainPct ?>%)
    </div>
  </div>

  <!-- Total Funds -->
  <div class="stat-card">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
      <span style="line-height:1;display:flex;align-items:center;">
        <!-- fa-briefcase colorful -->
        <svg width="26" height="24" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
          <rect x="32" y="160" width="448" height="288" rx="32" ry="32" fill="#6366f1"/>
          <rect x="32" y="160" width="448" height="80" rx="0" fill="#4f46e5"/>
          <path d="M176 160v-32a32 32 0 0 1 32-32h96a32 32 0 0 1 32 32v32" fill="none" stroke="#4f46e5" stroke-width="28" stroke-linecap="round"/>
          <rect x="220" y="240" width="72" height="44" rx="8" fill="#fff" opacity=".9"/>
        </svg>
      </span>
      <div class="stat-label" style="margin:0;">Total Funds</div>
    </div>
    <div class="stat-value" id="mfFundCount"><?= $fundCount ?></div>
  </div>

  <!-- 1 Day Change -->
  <div class="stat-card">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
      <span id="stat1dIcon">
        <!-- default neutral icon; JS will replace with up/down arrow -->
        <svg width="26" height="24" fill="none" stroke="#94a3b8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
          <line x1="12" y1="5" x2="12" y2="19"/><polyline points="8,9 12,5 16,9"/>
          <line x1="8" y1="15" x2="16" y2="15"/>
        </svg>
      </span>
      <div class="stat-label" style="margin:0;">1D Change</div>
    </div>
    <div class="stat-value" id="stat1dAmt" style="font-size:1.3rem;color:var(--text-muted);">—</div>
    <div id="stat1dPct" style="font-size:13px;font-weight:500;margin-top:3px;color:var(--text-muted);"></div>
  </div>

  <!-- t141: SIP Streak Card -->
  <div class="stat-card" id="sipStreakCard" style="display:none;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
      <span style="font-size:22px;">🔥</span>
      <div class="stat-label" style="margin:0;">SIP Streak</div>
    </div>
    <div class="stat-value" id="sipStreakVal" style="font-size:1.5rem;">—</div>
    <div id="sipStreakSub" style="font-size:12px;color:var(--text-muted);margin-top:3px;"></div>
  </div>

</div>

<!-- ═══ PAGE TABS ═══ -->
<div class="mf-page-tabs" style="display:flex;gap:4px;border-bottom:2px solid var(--border);margin-bottom:20px;">
  <button class="mf-tab active" data-tab="holdings" style="padding:10px 20px;font-size:14px;font-weight:500;background:none;border:none;border-bottom:2px solid var(--accent);margin-bottom:-2px;color:var(--accent);cursor:pointer;">
    📊 Holdings
  </button>
  <button class="mf-tab" data-tab="realized" style="padding:10px 20px;font-size:14px;font-weight:500;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-secondary);cursor:pointer;">
    ✅ Realized Gains
  </button>
  <button class="mf-tab" data-tab="dividends" style="padding:10px 20px;font-size:14px;font-weight:500;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-secondary);cursor:pointer;">
    💰 Dividends
  </button>
  <!-- t74: Capital Gains tab -->
  <button class="mf-tab" data-tab="capgains" style="padding:10px 20px;font-size:14px;font-weight:500;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-secondary);cursor:pointer;">
    🧾 Capital Gains
  </button>
  <!-- t75: Investment Calendar tab -->
  <button class="mf-tab" data-tab="calendar" style="padding:10px 20px;font-size:14px;font-weight:500;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-secondary);cursor:pointer;">
    📅 Calendar
  </button>
</div>

<!-- ═══ TAB: HOLDINGS ═══ -->
<div id="tabHoldings">
<div class="mf-filter-bar">
  <div class="mf-filter-selects">
    <select id="filterCategory" class="filter-select" data-custom-dropdown>
      <option value="">All Categories</option>
      <option value="Equity">Equity</option>
      <option value="Debt">Debt</option>
      <option value="Hybrid">Hybrid</option>
      <option value="Index">Index</option>
      <option value="ELSS">ELSS</option>
      <option value="Liquid">Liquid</option>
    </select>
    <select id="filterGainType" class="filter-select" data-custom-dropdown>
      <option value="">All Types</option>
      <option value="LTCG">LTCG</option>
      <option value="STCG">STCG</option>
    </select>
  </div>

</div>

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
    <div style="display:flex;align-items:center;gap:10px;">
      <h3 class="card-title" style="margin:0;">Holdings</h3>
      <!-- Sort menu button — moved here -->
      <button id="btnSortMenu" onclick="toggleSortMenu(event)"
        title="Sort holdings"
        style="background:none;border:1px solid var(--border-color);border-radius:6px;padding:4px 10px;cursor:pointer;font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:5px;white-space:nowrap;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/></svg>
        Sort
      </button>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <!-- Bulk-delete bar — shown when ≥1 fund selected -->
      <div id="bulkDeleteBar" style="display:none;align-items:center;gap:8px;padding:5px 10px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:8px;flex-wrap:wrap;">
        <span id="bulkSelectedCount" style="font-size:13px;font-weight:600;color:#dc2626;"></span>
        <button class="btn btn-sm" onclick="openBulkDeleteModal()"
          style="background:#dc2626;color:#fff;border:none;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">
          🗑 Delete Selected
        </button>
        <!-- t91: Bulk Export -->
        <button class="btn btn-ghost btn-sm" onclick="bulkExportSelected()" style="font-size:12px;display:inline-flex;align-items:center;gap:5px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/>
            <line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
          Export CSV
        </button>
        <!-- t91: Combined P&L -->
        <button class="btn btn-ghost btn-sm" onclick="showCombinedPL()" style="font-size:12px;">
          📊 Combined P&L
        </button>
        <button class="btn btn-ghost btn-sm" onclick="clearFundSelection()" style="font-size:12px;">✕ Clear</button>
      </div>
      <input type="search" id="searchFund" class="form-control form-control-sm" placeholder="Search fund..." style="width:200px;">
      <span id="holdingsCount" style="color:var(--text-muted);font-size:13px;"></span>
    </div>
  </div>
  <div class="table-wrapper">
    <table class="table table-hover table-grid mf-holdings-table" id="holdingsTable" style="table-layout:fixed;width:100%;">
      <colgroup>
        <col style="width:2.5%;"><!-- Checkbox -->
        <col style="width:20%;"><!-- Fund -->
        <col style="width:13%;"><!-- Invested / Value / Gain -->
        <col style="width:9%;"><!-- Returns / XIRR -->
        <col style="width:9%;"><!-- Units -->
        <col style="width:8%;"><!-- NAV -->
        <col style="width:8%;"><!-- Peak NAV -->
        <col style="width:7%;"><!-- Drawdown -->
        <col style="width:8%;"><!-- 1D Change -->
        <col style="width:9%;"><!-- Actions -->
      </colgroup>
      <thead>
        <tr>
          <th style="text-align:center;padding:8px 4px;">
            <input type="checkbox" id="selectAllFunds" title="Select all visible" onchange="toggleSelectAllFunds(this.checked)"
              style="width:15px;height:15px;cursor:pointer;accent-color:#3b82f6;">
          </th>
          <th>Fund</th>
          <th class="text-center">
            <div style="line-height:1.3;">
              <span style="display:block;font-size:11px;color:var(--text-muted);font-weight:500;">Invested</span>
              <span style="display:block;font-size:11px;color:var(--text-muted);font-weight:500;">Current Value</span>
              <span style="display:block;font-size:11px;color:var(--text-muted);font-weight:500;">Gain / Loss</span>
            </div>
          </th>
          <th class="text-center">
            <div style="line-height:1.3;">
              <span style="display:block;font-size:11px;color:var(--text-muted);font-weight:500;">Returns</span>
              <span style="display:block;font-size:11px;color:var(--text-muted);font-weight:500;">XIRR <i class="wd-info-btn tip-left" data-tip="XIRR (Extended IRR): Cash flow-weighted annual return. Accounts for timing of each SIP/lump sum investment. Best measure of YOUR actual return.">i</i></span>
            </div>
          </th>
          <th class="text-center">Units</th>
          <th class="text-center">NAV</th>
          <th class="text-center">Peak NAV <i class="wd-info-btn tip-left" data-tip="Highest NAV ever reached by this fund. Drawdown = how far current NAV is from peak.">i</i></th>
          <th class="text-center">Drawdown <i class="wd-info-btn tip-left" data-tip="Drawdown = % fall from all-time peak NAV. Example: Peak ₹100, Current ₹85 → Drawdown = -15%. Lower is better.">i</i></th>
          <th class="text-center">1D Change</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="holdingsBody">
        <tr><td colspan="11" class="text-center" style="padding:40px;">
          <div class="spinner"></div>
          <p style="color:var(--text-muted);margin-top:12px;">Loading holdings...</p>
        </td></tr>
      </tbody>

    </table>
  </div>
  <!-- Pagination -->
  <div class="card-footer" id="holdingsPaginationWrap" style="display:none;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:8px;">
      <label style="font-size:12px;color:var(--text-muted);">Show</label>
      <select id="holdingsPerPageSelect" class="form-select" style="width:75px;padding:4px 8px;font-size:12px;" onchange="changeHoldingsPerPage(this.value)">
        <option value="10" selected>10</option>
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
      <span style="font-size:12px;color:var(--text-muted);">per page</span>
    </div>
    <div id="holdingsPaginationInfo" style="font-size:13px;color:var(--text-muted);"></div>
    <div id="holdingsPagination" style="display:flex;gap:4px;"></div>
  </div>
</div>
</div><!-- end tabHoldings -->

<!-- t172: Folio Consolidation Alert -->
<div id="folioAlertBanner" style="display:none;margin-top:16px;"></div>

<!-- t175: Rebalancing Alert -->
<div id="rebalAlertWrap" style="display:none;margin-top:12px;"></div>

<!-- ═══ ANALYTICS SECTION (below Holdings table, always visible) ═══ -->
<div id="mfAnalyticsSection" style="display:none;margin-top:24px;">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

    <!-- t71: Asset Allocation Donut -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">📊 Asset Allocation</h3>
        <div id="allocToggle" style="display:flex;gap:4px;">
          <button class="btn btn-ghost btn-xs active" id="allocByValue" onclick="renderAllocChart('value')">By Value</button>
          <button class="btn btn-ghost btn-xs" id="allocByInvest" onclick="renderAllocChart('invested')">By Invested</button>
        </div>
      </div>
      <div class="card-body" style="display:flex;align-items:center;gap:20px;padding:16px;">
        <div style="position:relative;width:160px;height:160px;flex-shrink:0;">
          <canvas id="allocChartCanvas" width="160" height="160"></canvas>
          <div id="allocCenter" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
            <div id="allocCenterPct" style="font-size:18px;font-weight:800;color:var(--text-primary);">—</div>
            <div id="allocCenterLabel" style="font-size:10px;color:var(--text-muted);text-align:center;">Hover a segment</div>
          </div>
        </div>
        <div id="allocLegend" style="flex:1;display:flex;flex-direction:column;gap:6px;"></div>
      </div>
    </div>

    <!-- t73: Portfolio XIRR -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">📈 Portfolio Returns</h3>
      </div>
      <div class="card-body" style="padding:16px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
          <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;text-align:center;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Portfolio XIRR <i class="wd-info-btn" data-tip="XIRR: Considers exact dates & amounts of every SIP/lump sum. Most accurate measure of YOUR return. Different from fund's published return.">i</i></div>
            <div id="portfolioXirr" style="font-size:26px;font-weight:800;color:var(--text-muted);">—</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Annualised return</div>
          </div>
          <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;text-align:center;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Simple CAGR <i class="wd-info-btn" data-tip="CAGR: Compound Annual Growth Rate from your first purchase to today. Ignores SIP timing. Formula: (Current/Invested)^(1/Years) - 1">i</i></div>
            <div id="portfolioCagr" style="font-size:26px;font-weight:800;color:var(--text-muted);">—</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Based on first purchase</div>
          </div>
        </div>
        <div id="fundXirrList" style="display:flex;flex-direction:column;gap:4px;max-height:180px;overflow-y:auto;"></div>
      </div>
    </div>

  </div>

  <!-- t125: TWRR + t129: Stress Test row -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

    <!-- t125: TWRR tile -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">⏱️ TWRR — Time Weighted Return</h3>
        <span style="font-size:11px;color:var(--text-muted);">Cashflow-independent</span>
      </div>
      <div class="card-body" style="padding:16px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;text-align:center;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">TWRR (Portfolio) <i class="wd-info-btn" data-tip="TWRR: Time-Weighted Return. Eliminates effect of cash flows. Shows fund's pure performance regardless of when you invested. Used by fund managers to report performance.">i</i></div>
            <div id="twrrValue" style="font-size:26px;font-weight:800;color:var(--text-muted);">—</div>
            <div style="font-size:11px;color:var(--text-muted);">Annualised</div>
          </div>
          <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;text-align:center;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">XIRR vs TWRR</div>
            <div id="twrrVsXirr" style="font-size:14px;font-weight:700;color:var(--text-muted);">—</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;" id="twrrExplain"></div>
          </div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);padding:8px;background:rgba(99,102,241,.06);border-radius:6px;">
          💡 <strong>TWRR &gt; XIRR</strong> = Fund performs well but your timing was average.<br>
          <strong>XIRR &gt; TWRR</strong> = You invested at the right times! Good timing.
        </div>
      </div>
    </div>

    <!-- t129: Stress Test -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">💥 Stress Test — Market Crash Simulation</h3>
      </div>
      <div class="card-body" style="padding:16px;">
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
          <button class="btn btn-ghost btn-xs active" id="stressBtn2008" onclick="runStressTest('2008',this)">2008 Crisis (-55%)</button>
          <button class="btn btn-ghost btn-xs" id="stressBtnCovid" onclick="runStressTest('covid',this)">COVID 2020 (-38%)</button>
          <button class="btn btn-ghost btn-xs" id="stressBtn2013" onclick="runStressTest('2013',this)">2013 Taper (-27%)</button>
          <button class="btn btn-ghost btn-xs" id="stressBtnDotcom" onclick="runStressTest('dotcom',this)">Dot-com (-49%)</button>
        </div>
        <div id="stressResult" style="font-size:13px;">
          <div style="color:var(--text-muted);text-align:center;padding:20px;">Loading portfolio data...</div>
        </div>
      </div>
    </div>

  </div>

  <!-- t127: Correlation Matrix -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header">
      <h3 class="card-title">🔗 Correlation Matrix — Diversification Score</h3>
      <span style="font-size:11px;color:var(--text-muted);">Based on category overlap</span>
    </div>
    <div class="card-body" style="padding:16px;">
      <div id="corrMatrixWrap"></div>
    </div>
  </div>

  <!-- t93+t94: Alpha & Beta + Rolling Returns -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header">
      <h3 class="card-title">⚡ Alpha & Beta — Risk-Adjusted Performance</h3>
      <span style="font-size:11px;color:var(--text-muted);">+ Rolling Returns 1Y/3Y/5Y</span>
    </div>
    <div class="card-body" style="padding:16px;">
      <div id="alphaBetaCard">
        <div style="text-align:center;color:var(--text-muted);padding:20px;font-size:13px;">Loading analytics…</div>
      </div>
    </div>
  </div>

  <!-- t97: Sector Allocation -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header">
      <h3 class="card-title">🏭 Sector Allocation</h3>
      <span style="font-size:11px;color:var(--text-muted);">Estimated from fund categories</span>
    </div>
    <div class="card-body" style="padding:16px;">
      <div id="sectorAllocWrap">
        <div style="text-align:center;color:var(--text-muted);padding:20px;">Loading…</div>
      </div>
    </div>
  </div>

  <!-- t70 + t176: Portfolio Overlap (real AMFI data when available, proxy fallback) -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
      <div>
        <h3 class="card-title">🔄 Fund Overlap Matrix</h3>
        <span style="font-size:11px;color:var(--text-muted);">Real AMFI stock-level holdings · auto-proxy fallback</span>
      </div>
      <div style="display:flex;gap:6px;align-items:center;">
        <span id="overlapDataTag" style="font-size:10px;padding:2px 8px;border-radius:99px;background:var(--bg-secondary,#f4f6fb);color:var(--text-muted);border:1px solid var(--border);">Checking…</span>
        <button onclick="refreshOverlapData()" class="btn btn-sm btn-outline" style="font-size:11px;padding:4px 10px;">↻ Refresh</button>
      </div>
    </div>
    <div class="card-body" style="padding:16px;">
      <div id="overlapWrap">
        <div style="text-align:center;color:var(--text-muted);padding:20px;">Loading…</div>
      </div>
    </div>
  </div>

</div>

<!-- ═══ TAB: REALIZED GAINS ═══ -->
<div id="tabRealized" style="display:none;">

<!-- Summary cards -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:20px;" id="realizedSummaryCards">
  <div class="stat-card">
    <div class="stat-label">Total Proceeds</div>
    <div class="stat-value" id="rSumProceeds">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Cost</div>
    <div class="stat-value" id="rSumCost">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Gain</div>
    <div class="stat-value" id="rSumGain">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">LTCG</div>
    <div class="stat-value" id="rSumLtcg">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">STCG</div>
    <div class="stat-value" id="rSumStcg">—</div>
  </div>
</div>

<!-- Filter bar -->
<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
  <select id="rFilterFy" class="filter-select" data-custom-dropdown>
    <option value="">All FYs</option>
    <?php
    $curYear = (int)date('Y'); $curMon = (int)date('n');
    $fyStart = $curMon >= 4 ? $curYear : $curYear - 1;
    for ($i = 0; $i < 8; $i++) {
        $y = $fyStart - $i;
        $fy = $y . '-' . substr((string)($y+1), 2);
        echo "<option value=\"$fy\">FY $fy</option>\n";
    }
    ?>
  </select>
  <select id="rFilterType" class="filter-select" data-custom-dropdown>
    <option value="">LTCG + STCG</option>
    <option value="LTCG">LTCG only</option>
    <option value="STCG">STCG only</option>
  </select>
  <input type="search" id="rSearchFund" class="form-control form-control-sm" placeholder="Search fund..." style="width:200px;">
</div>

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <h3 class="card-title" style="margin:0;">Realized Gains — Sold Transactions</h3>
    <span id="rCount" style="color:var(--text-muted);font-size:13px;"></span>
  </div>
  <div class="table-wrapper">
    <table class="table table-hover" id="realizedTable">
      <thead>
        <tr>
          <th>Fund</th>
          <th class="text-center">Sell Date</th>
          <th class="text-center">Units Sold</th>
          <th class="text-center">Sell NAV</th>
          <th class="text-center">Proceeds</th>
          <th class="text-center">Cost</th>
          <th class="text-center">Gain / Loss</th>
          <th class="text-center">Holding Days</th>
          <th class="text-center">Type</th>
          <th class="text-center">Tax Rate</th>
          <th class="text-center">FY</th>
        </tr>
      </thead>
      <tbody id="realizedBody">
        <tr><td colspan="10" class="text-center" style="padding:40px;color:var(--text-muted);">
          No realized gains found.
        </td></tr>
      </tbody>
      <tfoot id="realizedTfoot" style="display:none;">
        <tr style="background:var(--bg-secondary);font-weight:600;">
          <td colspan="4">Total</td>
          <td class="text-center" id="rFootProceeds"></td>
          <td class="text-center" id="rFootCost"></td>
          <td class="text-center" id="rFootGain"></td>
          <td colspan="5"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
</div><!-- end tabRealized -->

<!-- ═══ TAB: DIVIDENDS ═══ -->
<div id="tabDividends" style="display:none;">

<!-- Summary -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:20px;">
  <div class="stat-card">
    <div class="stat-label">Total Dividend</div>
    <div class="stat-value" id="dSumTotal">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">This FY</div>
    <div class="stat-value" id="dSumThisFy">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Dividend Entries</div>
    <div class="stat-value" id="dSumCount">—</div>
  </div>
</div>

<!-- Filter -->
<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
  <select id="dFilterFy" class="filter-select" data-custom-dropdown>
    <option value="">All FYs</option>
    <?php
    for ($i = 0; $i < 8; $i++) {
        $y = $fyStart - $i;
        $fy = $y . '-' . substr((string)($y+1), 2);
        echo "<option value=\"$fy\">FY $fy</option>\n";
    }
    ?>
  </select>
  <input type="search" id="dSearchFund" class="form-control form-control-sm" placeholder="Search fund..." style="width:200px;">
</div>

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <h3 class="card-title" style="margin:0;">MF Dividends Received</h3>
    <span id="dCount" style="color:var(--text-muted);font-size:13px;"></span>
  </div>
  <div class="table-wrapper">
    <table class="table table-hover" id="dividendsTable">
      <thead>
        <tr>
          <th>Fund</th>
          <th class="text-center">Date</th>
          <th class="text-center">Div / Unit (₹)</th>
          <th class="text-center">Units</th>
          <th class="text-center">Amount</th>
          <th class="text-center">Type</th>
          <th class="text-center">FY</th>
        </tr>
      </thead>
      <tbody id="dividendsBody">
        <tr><td colspan="7" class="text-center" style="padding:40px;color:var(--text-muted);">
          No dividend entries found.
        </td></tr>
      </tbody>
      <tfoot id="dividendsTfoot" style="display:none;">
        <tr style="background:var(--bg-secondary);font-weight:600;">
          <td colspan="4">Total</td>
          <td class="text-center" id="dFootTotal"></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
</div><!-- end tabDividends -->

<!-- ═══ TAB: CAPITAL GAINS (t74) ═══ -->
<div id="tabCapgains" style="display:none;">
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <h3 class="card-title" style="margin:0;">🧾 Capital Gains — ITR Ready</h3>
      <div style="display:flex;gap:8px;align-items:center;">
        <select id="cgFyFilter" class="filter-select" style="font-size:12px;">
          <option value="">All FYs</option>
        </select>
        <select id="cgTypeFilter" class="filter-select" style="font-size:12px;">
          <option value="">All Types</option>
          <option value="LTCG">LTCG</option>
          <option value="STCG">STCG</option>
          <option value="Slab">Slab (Debt)</option>
        </select>
        <button onclick="exportCapgainsCsv()" class="btn btn-outline btn-sm">
          ↓ ITR CSV
        </button>
      </div>
    </div>
    <!-- Summary pills -->
    <div id="cgSummaryPills" style="display:flex;gap:10px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--border);background:var(--bg-secondary);"></div>
    <div class="table-wrapper">
      <table class="table table-hover" id="cgTable">
        <thead>
          <tr>
            <th>FY</th>
            <th>Fund</th>
            <th>Folio</th>
            <th class="text-right">Units</th>
            <th class="text-right">Buy NAV</th>
            <th class="text-right">Sell NAV</th>
            <th class="text-right">Cost (₹)</th>
            <th class="text-right">Proceeds (₹)</th>
            <th class="text-right">Gain (₹)</th>
            <th class="text-right">Days</th>
            <th>Type</th>
            <th class="text-right">Tax (₹)</th>
          </tr>
        </thead>
        <tbody id="cgBody">
          <tr><td colspan="12" class="text-center" style="padding:40px;color:var(--text-muted);">Select a FY to load capital gains data</td></tr>
        </tbody>
        <tfoot id="cgTfoot" style="display:none;">
          <tr style="font-weight:700;background:var(--bg-secondary);">
            <td colspan="6">Total</td>
            <td class="text-right" id="cgTotCost">—</td>
            <td class="text-right" id="cgTotProceeds">—</td>
            <td class="text-right" id="cgTotGain">—</td>
            <td colspan="2"></td>
            <td class="text-right" id="cgTotTax">—</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <!-- LTCG Exemption tracker -->
  <div class="card" id="cgExemptCard" style="display:none;">
    <div class="card-header"><h3 class="card-title" style="margin:0;">LTCG Exemption — ₹1.25L Limit</h3></div>
    <div class="card-body" style="padding:16px;">
      <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px;">
        <span>Used: <strong id="cgExemptUsed" class="text-danger">₹0</strong></span>
        <span>Limit: <strong>₹1,25,000</strong></span>
        <span>Remaining: <strong id="cgExemptLeft" class="text-success">₹1,25,000</strong></span>
      </div>
      <div style="height:8px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
        <div id="cgExemptBar" style="height:100%;width:0%;background:#16a34a;border-radius:99px;transition:width .5s;"></div>
      </div>
      <div id="cgExemptMsg" style="font-size:12px;color:var(--text-muted);margin-top:8px;"></div>
    </div>
  </div>
</div>

<!-- ═══ TAB: INVESTMENT CALENDAR (t75) ═══ -->
<div id="tabCalendarWrap" style="display:none;">
  <div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="padding:12px 20px;">
      <p style="font-size:13px;color:var(--text-muted);margin:0;">
        📅 <strong>Investment Calendar</strong> — Transaction heatmap. <span style="color:#16a34a;font-weight:700;">■</span> Buy &nbsp;<span style="color:#dc2626;font-weight:700;">■</span> Sell &nbsp;<span style="color:#a78bfa;font-weight:700;">■</span> Both. Darker = larger amount. Click a day to see details.
      </p>
    </div>
  </div>
  <div class="card">
    <div class="card-body" style="padding:0 16px 16px;" id="tabCalendar">
      <!-- Calendar rendered by renderCalendar() -->
    </div>
  </div>
</div>

<!-- ═══ ADD/EDIT TRANSACTION MODAL ═══ -->
<div class="modal-overlay" id="modalAddTxn" style="display:none;">
  <div class="modal" style="max-width:600px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title" id="modalTxnTitle">Add MF Transaction</h3>
      <button class="modal-close btn btn-ghost btn-sm" id="btnCloseTxnModal">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="txnEditId">
      <input type="hidden" id="txnCsrf" value="<?= generate_csrf() ?>">

      <!-- portfolio_id passed via JS from WD.selectedPortfolio -->
      <div class="form-group">
        <label class="form-label">Transaction Type *</label>
        <select id="txnType" class="form-control">
          <option value="BUY">BUY — Purchase</option>
          <option value="SELL">SELL — Redemption</option>
          <option value="DIV_REINVEST">Dividend Reinvestment</option>
          <option value="SWITCH_IN">Switch In</option>
          <option value="SWITCH_OUT">Switch Out</option>
        </select>
      </div>

      <div class="form-group" style="position:relative;">
        <label class="form-label">Fund Name *</label>
        <input type="text" id="txnFundSearch" class="form-control" placeholder="Type fund name or scheme code..." autocomplete="off">
        <div id="fundSearchDropdown" style="display:none;position:absolute;left:0;right:0;top:100%;background:var(--bg-card);border:1px solid var(--border-color);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);max-height:280px;overflow-y:auto;z-index:2000;"></div>
        <input type="hidden" id="txnFundId">
        <div id="txnFundInfo" style="margin-top:4px;font-size:12px;color:var(--text-muted);min-height:18px;"></div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Date *</label>
          <input type="date" id="txnDate" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Folio Number</label>
          <input type="text" id="txnFolio" class="form-control" placeholder="Optional">
        </div>
      </div>

      <!-- Available units banner — shown only for SELL/SWITCH_OUT -->
      <div id="availableUnitsBanner" style="display:none;margin-bottom:8px;padding:10px 14px;
           border-radius:8px;background:rgba(234,179,8,.1);border:1px solid rgba(234,179,8,.3);
           font-size:12.5px;line-height:1.5;">
        <span style="color:var(--text-muted);">Available units to sell:</span>
        <strong id="availableUnitsVal" style="color:#b45309;margin-left:6px;font-size:14px;">—</strong>
        <span id="availableUnitsDate" style="color:var(--text-muted);margin-left:8px;font-size:11px;"></span>
        <br>
        <span style="color:var(--text-muted);">Avg cost NAV:</span>
        <strong id="availableAvgNav" style="margin-left:4px;">—</strong>
        <span style="margin-left:12px;color:var(--text-muted);">Total invested:</span>
        <strong id="availableTotalInvested" style="margin-left:4px;">—</strong>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label" id="txnUnitsLabel">Units *</label>
          <input type="number" id="txnUnits" class="form-control" placeholder="0.000" step="0.001" min="0.001">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">NAV (₹) *</label>
          <input type="number" id="txnNav" class="form-control" placeholder="0.0000" step="0.0001" min="0.0001">
        </div>
        <div class="form-group" style="flex:0 0 130px;">
          <label class="form-label">Stamp Duty (₹)</label>
          <input type="number" id="txnStampDuty" class="form-control" value="0" step="0.01" min="0">
        </div>
      </div>

      <div id="txnValuePreview" style="display:none;background:var(--bg-secondary);border-radius:8px;padding:12px;margin:-8px 0 16px;font-size:13px;">
        <span style="color:var(--text-muted);">Total Amount: </span>
        <strong id="previewValue">₹0</strong>
        <span style="margin-left:16px;color:var(--text-muted);">Latest NAV: </span>
        <span id="previewCurrentNav">—</span>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Platform</label>
          <select id="txnPlatform" class="form-control">
            <option value="">— Select —</option>
            <option>Direct AMC</option><option>MF Central</option>
            <option>Groww</option><option>Zerodha Coin</option>
            <option>Kuvera</option><option>Paytm Money</option>
            <option>ET Money</option><option>Other</option>
          </select>
        </div>
        <div class="form-group" style="flex:2;">
          <label class="form-label">Notes</label>
          <input type="text" id="txnNotes" class="form-control" placeholder="Optional...">
        </div>
      </div>

      <div id="txnError" style="display:none;color:var(--danger);font-size:13px;padding:8px 12px;background:rgba(239,68,68,.1);border-radius:6px;margin-top:8px;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="btnCancelTxn">Cancel</button>
      <button class="btn btn-primary" id="btnSaveTxn">
        <span id="btnSaveTxnLabel">Save Transaction</span>
        <div class="spinner-sm" id="btnSaveTxnSpinner" style="display:none;"></div>
      </button>
    </div>
  </div>
</div>

<!-- ═══ t186: PRINT / PDF AREA (hidden, shown only on @media print) ═══ -->
<div id="mfPrintArea" style="display:none;">
  <!-- populated dynamically by printHoldings() -->
</div>

<!-- ═══ IMPORT CSV MODAL ═══ -->
<div class="modal-overlay" id="modalImportCsv" style="display:none;">
  <div class="modal" style="max-width:620px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">📥 Import Portfolio — CAS / CSV</h3>
      <button class="modal-close btn btn-ghost btn-sm" id="btnCloseImportModal">✕</button>
    </div>
    <div class="modal-body" style="padding:0;">

      <!-- Tabs: CAS Import vs CSV Import vs History -->
      <div style="display:flex;border-bottom:2px solid var(--border);">
        <button class="cas-tab active" onclick="switchCasTab('cas',this)" style="flex:1;padding:10px;border:none;background:none;cursor:pointer;font-weight:700;font-size:13px;border-bottom:2px solid var(--accent);color:var(--accent);">
          🏦 CAS Auto-Import
        </button>
        <button class="cas-tab" onclick="switchCasTab('csv',this)" style="flex:1;padding:10px;border:none;background:none;cursor:pointer;font-weight:600;font-size:13px;color:var(--text-muted);">
          📄 CSV Manual Import
        </button>
        <button class="cas-tab" onclick="switchCasTab('history',this)" style="flex:1;padding:10px;border:none;background:none;cursor:pointer;font-weight:600;font-size:13px;color:var(--text-muted);">
          🕓 Import History
        </button>
      </div>

      <!-- CAS Import Tab -->
      <div id="casTabContent" style="padding:16px;">
        <div style="padding:10px;background:rgba(99,102,241,.06);border-radius:8px;margin-bottom:14px;font-size:12px;">
          <strong>CAMS + KFintech CAS</strong> — Upload your Consolidated Account Statement PDF or TXT.
          <br>Get it: <a href="https://www.camsonline.com/InvestorServices/MFAccountSummary.aspx" target="_blank" style="color:var(--accent);">CAMS</a> |
          <a href="https://mfs.kfintech.com/investor/General/Consent" target="_blank" style="color:var(--accent);">KFintech</a> |
          <a href="https://www.mfuindia.com/" target="_blank" style="color:var(--accent);">MFU</a>
          &nbsp;→ Request CAS by email → Save/upload here
        </div>

        <div class="form-group">
          <label class="form-label">Portfolio *</label>
          <select id="casPortfolioId" class="form-control">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">CAS File (PDF, TXT, or CSV)</label>
          <label for="casFile" id="casFileLabel"
            style="display:flex;align-items:center;gap:10px;padding:14px;border:2px dashed var(--border);border-radius:8px;cursor:pointer;background:var(--bg-secondary);transition:.2s;">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <div>
              <div id="casFileName" style="font-weight:600;font-size:13px;">Click to select CAS file…</div>
              <div style="font-size:11px;color:var(--text-muted);">PDF / TXT / CSV · Max 10MB · Password-protected PDF = save as text first</div>
            </div>
          </label>
          <input type="file" id="casFile" accept=".pdf,.txt,.csv" style="display:none;" onchange="onCasFileSelect(this)">
        </div>

        <!-- Parse preview area -->
        <div id="casParseResult" style="display:none;"></div>

        <div id="casImportResult" style="display:none;margin-top:12px;padding:12px;border-radius:8px;font-size:13px;"></div>
      </div>

      <!-- CSV Import Tab (existing) -->
      <div id="csvTabContent" style="padding:16px;display:none;">
        <div class="form-group">
          <label class="form-label">Source Format</label>
          <select id="importFormat" class="form-control" onchange="onImportFormatChange()">
            <option value="auto">🔍 Auto-detect</option>
            <option value="wealthdash">WealthDash Custom</option>
            <option value="cams">CAMS Statement</option>
            <option value="kfintech">KFintech / Karvy</option>
            <option value="groww">Groww Export</option>
            <option value="zerodha">Zerodha Coin</option>
            <option value="kuvera">Kuvera</option>
            <option value="mfcentral">MFCentral / Paytm Money</option>
          </select>
        </div>
        <div id="importFormatHint" style="font-size:11px;color:var(--text-muted);padding:6px 10px;background:var(--bg-secondary);border-radius:6px;margin-bottom:12px;display:none;"></div>
        <div class="form-group">
          <label class="form-label">CSV File</label>
          <label for="importFile" id="importFileLabel"
            style="display:flex;align-items:center;gap:10px;padding:9px 14px;border:1.5px dashed var(--border);border-radius:8px;cursor:pointer;background:var(--card-bg);font-size:13px;color:var(--text-muted);">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <span id="importFileText">Choose CSV file…</span>
          </label>
          <input type="file" id="importFile" accept=".csv,.txt" style="display:none;" onchange="onImportFileChange(this)">
        </div>
        <div id="importPreviewWrap" style="display:none;margin-top:12px;">
          <div style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:6px;">Preview (first 5 rows)</div>
          <div id="importPreview" style="overflow-x:auto;font-size:11px;background:var(--bg-secondary);border-radius:6px;padding:8px;max-height:160px;overflow-y:auto;"></div>
        </div>
        <div class="import-template-hint" style="margin-top:10px;">
          <a href="<?= APP_URL ?>/public/downloads/wealthdash_mf_template.csv" download>⬇ Download template</a>
          &nbsp;|&nbsp;
          <span style="color:var(--text-muted);">Supported: WealthDash · CAMS · KFintech · Groww · Zerodha · Kuvera</span>
        </div>
        <div id="importResult" style="display:none;margin-top:16px;padding:12px;border-radius:8px;font-size:13px;"></div>
      </div>

      <!-- t190: Import History Tab -->
      <div id="historyTabContent" style="padding:16px;display:none;min-height:200px;max-height:420px;overflow-y:auto;">
        <div id="importHistoryBody">
          <div style="text-align:center;padding:30px;color:var(--text-muted);">
            <span class="spinner"></span>
          </div>
        </div>
      </div>

    </div>
    <div class="modal-footer" style="gap:8px;">
      <button class="btn btn-ghost" id="btnCancelImport">Cancel</button>
      <!-- CAS buttons -->
      <div id="casButtons">
        <button class="btn btn-outline" id="btnCasParse" onclick="parseCasFile()" style="display:none;">🔍 Parse & Preview</button>
        <button class="btn btn-primary" id="btnCasImport" onclick="commitCasImport()" style="display:none;">✅ Import Transactions</button>
      </div>
      <!-- CSV button -->
      <button class="btn btn-primary" id="btnStartImport" style="display:none;">
        <span id="btnImportLabel">Import</span>
        <div class="spinner-sm" id="btnImportSpinner" style="display:none;"></div>
      </button>
    </div>
  </div>
</div>

<!-- Transaction History Drawer -->
<div id="txnDrawerOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:998;" onclick="closeTxnDrawer()"></div>
<div id="txnDrawer" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(960px,95vw);max-height:85vh;background:var(--bg-card);border-radius:var(--radius-lg);z-index:999;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.3);flex-direction:column;">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
    <h3 id="drawerTitle" style="margin:0;font-size:16px;font-weight:600;">Transactions</h3>
    <button class="btn btn-ghost btn-sm" onclick="closeTxnDrawer()">✕ Close</button>
  </div>
  <div id="drawerContent" style="padding:20px;overflow-y:auto;flex:1;"></div>
</div>



<!-- ═══ SORT MENU DROPDOWN ═══ -->
<div id="sortMenuDropdown" style="display:none;position:fixed;z-index:9999;background:var(--bg-card);border:1px solid var(--border-color);border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.18);min-width:230px;padding:0;overflow:hidden;">
  <div style="padding:9px 14px 7px;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.6px;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Sort Holdings By</div>

  <?php
  /* ── Group structure: [col, label, divider_after?] ── */
  $sortGroups = [
    // Group 1: Fund identity
    ['col'=>'scheme_name',       'label'=>'Fund Name (A–Z)',       'div'=>false],
    ['col'=>'fund_house',        'label'=>'Fund House (A–Z)',       'div'=>true],
    // Group 2: Value
    ['col'=>'total_invested',    'label'=>'Invested Amount',        'div'=>false],
    ['col'=>'value_now',         'label'=>'Current Value',          'div'=>false],
    ['col'=>'gain_loss',         'label'=>'Gain / Loss (₹)',        'div'=>true],
    // Group 3: Returns
    ['col'=>'gain_pct',          'label'=>'Returns (%)',            'div'=>false],
    ['col'=>'cagr',              'label'=>'XIRR',                   'div'=>true],
    // Group 4: Units
    ['col'=>'total_units',       'label'=>'Units (Total)',          'div'=>false],
    ['col'=>'ltcg_units',        'label'=>'Units (LTCG)',           'div'=>false],
    ['col'=>'stcg_units',        'label'=>'Units (STCG)',           'div'=>true],
    // Group 5: NAV
    ['col'=>'latest_nav',        'label'=>'NAV',                    'div'=>false],
    ['col'=>'highest_nav',       'label'=>'Peak NAV',               'div'=>true],
    // Group 6: 1D Change
    ['col'=>'one_day_change_pct','label'=>'1 Day Change %',         'div'=>false],
    ['col'=>'one_day_change_val','label'=>'1 Day Change (₹/unit)',  'div'=>true],
    // Group 7: Drawdown
    ['col'=>'drawdown_pct',      'label'=>'Drawdown %',             'div'=>false],
    ['col'=>'drawdown_nav',      'label'=>'Drawdown (₹ from Peak)', 'div'=>false],
  ];
  foreach ($sortGroups as $opt): ?>
  <button class="sort-menu-item" data-col="<?= $opt['col'] ?>"
    onclick="applySortMenu('<?= $opt['col'] ?>')"
    style="display:flex;align-items:center;justify-content:space-between;width:100%;padding:7px 14px;background:none;border:none;cursor:pointer;font-size:13px;color:var(--text-primary);text-align:left;transition:background .15s;"
    onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background='none'">
    <span><?= $opt['label'] ?></span>
    <span class="sort-dir-indicator" data-col="<?= $opt['col'] ?>" style="font-size:10px;color:var(--accent);font-weight:700;display:none;"></span>
  </button>
  <?php if ($opt['div']): ?>
  <div style="height:1px;background:var(--border-color);margin:3px 0;"></div>
  <?php endif; endforeach; ?>
</div>

<!-- ═══ DELETE FUND MODAL (single) ═══ -->
<div class="modal-overlay" id="modalDeleteFund" style="display:none;">
  <div class="modal" style="max-width:460px;width:95%;">
    <div class="modal-header" style="border-bottom:2px solid rgba(239,68,68,.2);">
      <h3 class="modal-title" style="color:#dc2626;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-3px;margin-right:6px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        Delete Fund
      </h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="closeDeleteFundModal()">✕</button>
    </div>
    <div class="modal-body">
      <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:14px;margin-bottom:16px;">
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:4px;">Fund to delete:</div>
        <div id="deleteFundName" style="font-weight:600;font-size:15px;color:var(--text-primary);"></div>
        <div id="deleteFundMeta" style="font-size:12px;color:var(--text-muted);margin-top:4px;"></div>
      </div>
      <div style="background:rgba(239,68,68,.05);border-left:3px solid #dc2626;padding:10px 14px;border-radius:0 6px 6px 0;margin-bottom:18px;font-size:13px;color:var(--text-muted);">
        ⚠️ This will permanently delete <strong>all transactions</strong> for this fund. This action <strong>cannot be undone</strong>.
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label" style="font-size:13px;">
          To confirm, type <strong id="deleteConfirmPhrase" style="color:#dc2626;font-family:monospace;font-size:13px;letter-spacing:.5px;"></strong> below:
        </label>
        <input type="text" id="deleteFundConfirmInput" class="form-control"
          placeholder="Type the code here..." autocomplete="off"
          oninput="checkDeleteFundConfirm()"
          style="margin-top:6px;font-family:monospace;letter-spacing:1px;">
        <div id="deleteFundConfirmHint" style="font-size:12px;margin-top:5px;color:var(--text-muted);min-height:16px;"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeDeleteFundModal()">Cancel</button>
      <button class="btn btn-sm" id="btnConfirmDeleteFund" disabled
        onclick="confirmDeleteFund()"
        style="background:#dc2626;color:#fff;border:none;padding:7px 18px;border-radius:6px;font-weight:600;cursor:not-allowed;opacity:.45;transition:opacity .2s,cursor .2s;">
        <span id="btnConfirmDeleteFundLabel">Delete Fund</span>
        <div class="spinner-sm" id="btnConfirmDeleteFundSpinner" style="display:none;"></div>
      </button>
    </div>
  </div>
</div>

<!-- ═══ BULK DELETE MODAL (multiple funds) ═══ -->
<div class="modal-overlay" id="modalBulkDelete" style="display:none;">
  <div class="modal" style="max-width:520px;width:95%;">
    <div class="modal-header" style="border-bottom:2px solid rgba(239,68,68,.2);">
      <h3 class="modal-title" style="color:#dc2626;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-3px;margin-right:6px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        Delete <span id="bulkModalCount"></span> Fund(s)
      </h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="closeBulkDeleteModal()">✕</button>
    </div>
    <div class="modal-body">
      <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:12px 14px;margin-bottom:14px;max-height:180px;overflow-y:auto;">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;font-weight:600;">FUNDS TO BE DELETED:</div>
        <ul id="bulkDeleteFundList" style="margin:0;padding-left:16px;font-size:13px;line-height:1.8;"></ul>
      </div>
      <div style="background:rgba(239,68,68,.05);border-left:3px solid #dc2626;padding:10px 14px;border-radius:0 6px 6px 0;margin-bottom:18px;font-size:13px;color:var(--text-muted);">
        ⚠️ All transactions for these funds will be permanently deleted. <strong>This cannot be undone.</strong>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label" style="font-size:13px;">
          Type <strong style="color:#dc2626;font-family:monospace;">DELETE</strong> to confirm:
        </label>
        <input type="text" id="bulkDeleteConfirmInput" class="form-control"
          placeholder='Type "DELETE" here...' autocomplete="off"
          oninput="checkBulkDeleteConfirm()"
          style="margin-top:6px;font-family:monospace;letter-spacing:1px;text-transform:uppercase;">
        <div id="bulkDeleteConfirmHint" style="font-size:12px;margin-top:5px;color:var(--text-muted);min-height:16px;"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeBulkDeleteModal()">Cancel</button>
      <button class="btn btn-sm" id="btnConfirmBulkDelete" disabled
        onclick="confirmBulkDelete()"
        style="background:#dc2626;color:#fff;border:none;padding:7px 18px;border-radius:6px;font-weight:600;cursor:not-allowed;opacity:.45;transition:opacity .2s;">
        <span id="btnConfirmBulkDeleteLabel">Delete All</span>
        <div class="spinner-sm" id="btnConfirmBulkDeleteSpinner" style="display:none;"></div>
      </button>
    </div>
  </div>
</div>

<!-- t112: Fund Finder Quick Add Modal -->
<div class="modal-overlay" id="modalFundFinder" style="display:none;">
  <div class="modal" style="max-width:560px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">🔍 Find & Add Fund</h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="hideFundFinderModal()">✕</button>
    </div>
    <div class="modal-body">
      <div style="position:relative;margin-bottom:12px;">
        <input type="search" id="ffSearch" class="form-control" placeholder="Type fund name, AMC, or scheme code…" autocomplete="off"
          style="padding-left:36px;" oninput="onFfSearch(this.value)">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
          style="position:absolute;left:11px;top:50%;transform:translateY(-50%);opacity:.4;pointer-events:none;">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
      </div>
      <!-- Filter pills -->
      <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap;" id="ffFilterPills">
        <span class="ff-pill active" data-cat="" onclick="setFfFilter('',this)">All</span>
        <span class="ff-pill" data-cat="Equity" onclick="setFfFilter('Equity',this)">Equity</span>
        <span class="ff-pill" data-cat="Index" onclick="setFfFilter('Index',this)">Index</span>
        <span class="ff-pill" data-cat="ELSS" onclick="setFfFilter('ELSS',this)">ELSS</span>
        <span class="ff-pill" data-cat="Debt" onclick="setFfFilter('Debt',this)">Debt</span>
        <span class="ff-pill" data-cat="Hybrid" onclick="setFfFilter('Hybrid',this)">Hybrid</span>
      </div>
      <div id="ffResults" style="max-height:340px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;">
        <div style="padding:30px;text-align:center;color:var(--text-muted);font-size:13px;">Type to search funds…</div>
      </div>
    </div>
  </div>
</div>

<!-- t81: Notification Center Modal -->
<div class="modal-overlay" id="modalNotifications" style="display:none;">
  <div class="modal" style="max-width:480px;width:95%;max-height:80vh;display:flex;flex-direction:column;">
    <div class="modal-header" style="flex-shrink:0;">
      <h3 class="modal-title">🔔 Alerts & Notifications</h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="hideModal('modalNotifications')">✕</button>
    </div>
    <div class="modal-body" style="overflow-y:auto;flex:1;" id="notifBody">
      <div style="padding:30px;text-align:center;color:var(--text-muted);">No alerts configured</div>
    </div>
    <div class="modal-footer" style="flex-shrink:0;">
      <button class="btn btn-ghost btn-sm" onclick="clearAllAlerts()">🗑 Clear All</button>
      <button class="btn btn-ghost" onclick="hideModal('modalNotifications')">Close</button>
    </div>
  </div>
</div>

<!-- ═══ t90: Fund NAV History Chart Modal ═══ -->
<div class="modal-overlay" id="modalFundChart" style="display:none;z-index:1100;">
  <div class="modal" style="max-width:900px;width:97%;max-height:92vh;display:flex;flex-direction:column;padding:0;overflow:hidden;">

    <!-- Header -->
    <div class="modal-header" style="flex-shrink:0;padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
      <div style="min-width:0;">
        <div id="fcFundName" style="font-size:15px;font-weight:700;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:720px;">—</div>
        <div id="fcFundMeta" style="font-size:11px;color:var(--text-muted);margin-top:4px;display:flex;gap:6px;flex-wrap:wrap;"></div>
      </div>
      <button class="btn btn-ghost btn-sm" onclick="closeFundChartModal()" style="flex-shrink:0;font-size:16px;line-height:1;padding:6px 10px;">✕</button>
    </div>

    <!-- Stats row -->
    <div id="fcStats" style="flex-shrink:0;display:grid;grid-template-columns:repeat(6,1fr);gap:0;border-bottom:1px solid var(--border);background:var(--bg-secondary);"></div>

    <!-- Chart area -->
    <div style="flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden;">

      <!-- Range + toggle controls -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px 0;flex-shrink:0;flex-wrap:wrap;gap:8px;">
        <div style="display:flex;gap:4px;" id="fcRangeBtns">
          <button class="fc-range-btn" data-range="1M" onclick="setFcRange('1M',this)">1M</button>
          <button class="fc-range-btn" data-range="3M" onclick="setFcRange('3M',this)">3M</button>
          <button class="fc-range-btn" data-range="6M" onclick="setFcRange('6M',this)">6M</button>
          <button class="fc-range-btn active" data-range="1Y" onclick="setFcRange('1Y',this)">1Y</button>
          <button class="fc-range-btn" data-range="3Y" onclick="setFcRange('3Y',this)">3Y</button>
          <button class="fc-range-btn" data-range="ALL" onclick="setFcRange('ALL',this)">All</button>
        </div>
        <div style="display:flex;align-items:center;gap:10px;font-size:11px;color:var(--text-muted);flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;">
            <input type="checkbox" id="fcShowTxns" checked onchange="toggleFcTxnMarkers()" style="accent-color:var(--accent);"> Transactions
          </label>
          <!-- t95: Benchmark toggle -->
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;">
            <input type="checkbox" id="fcShowBenchmark" onchange="toggleFcBenchmark()" style="accent-color:#f97316;"> vs Benchmark
          </label>
          <select id="fcBenchmarkSelect" onchange="setFcBenchmark(this.value)"
            style="display:none;font-size:10px;padding:2px 6px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-secondary);color:var(--text-primary);cursor:pointer;">
            <option value="^NSEI">Nifty 50</option>
            <option value="^BSESN">Sensex</option>
            <option value="^NSMIDCP">Nifty Midcap</option>
          </select>
          <span id="fcBenchmarkStatus" style="color:#f97316;font-weight:700;display:none;"></span>
          <span id="fcDataStatus" style="color:var(--text-muted);"></span>
        </div>
      </div>

      <!-- Canvas wrapper -->
      <div style="flex:1;min-height:280px;padding:8px 16px 0;position:relative;" id="fcCanvasWrap">
        <div id="fcChartSpinner" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:var(--bg-card);z-index:5;">
          <div class="spinner"></div>
        </div>
        <div id="fcNoData" style="display:none;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:var(--text-muted);">
          <div style="font-size:28px;">📉</div>
          <div style="font-size:13px;font-weight:600;">NAV history not available</div>
          <div style="font-size:12px;">Download history from Admin → NAV &amp; Data first</div>
        </div>
        <canvas id="fcChartCanvas" style="width:100%!important;height:100%!important;display:none;"></canvas>
        <div id="fcNormalizedNote" style="display:none;position:absolute;bottom:4px;left:16px;" class="fc-normalized-note">Indexed to 100 at range start — showing % returns</div>
      </div>

      <!-- Transactions pills -->
      <div style="flex-shrink:0;padding:10px 16px 14px;border-top:1px solid var(--border);margin-top:8px;max-height:150px;overflow-y:auto;" id="fcTxnSection" style="display:none;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Transactions</div>
        <div id="fcTxnList" style="display:flex;flex-wrap:wrap;gap:5px;"></div>
      </div>

    </div>
  </div>
</div>

<style>
.fc-range-btn{padding:4px 12px;border-radius:99px;border:1.5px solid var(--border);background:var(--bg-secondary);color:var(--text-muted);font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;}
.fc-range-btn:hover{border-color:var(--accent);color:var(--accent);}
.fc-range-btn.active{background:var(--accent);color:#fff;border-color:var(--accent);}
.fc-stat-cell{padding:10px 12px;border-right:1px solid var(--border);text-align:center;}
.fc-stat-cell:last-child{border-right:none;}
.fc-stat-label{font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;}
.fc-stat-val{font-size:13px;font-weight:800;color:var(--text-primary);}
.fc-stat-sub{font-size:10px;color:var(--text-muted);margin-top:2px;}
.fund-title.fc-clickable{cursor:pointer;transition:color .15s;}
.fund-title.fc-clickable:hover{color:var(--accent);text-decoration:underline;}
/* t95 — benchmark badge */
.fc-bench-badge{display:inline-flex;align-items:center;gap:4px;padding:1px 8px;border-radius:99px;font-size:10px;font-weight:700;background:rgba(249,115,22,.1);color:#ea580c;border:1px solid rgba(249,115,22,.25);}
.fc-normalized-note{font-size:10px;color:var(--text-muted);font-style:italic;margin-top:2px;}

.ff-pill { padding:4px 12px;border-radius:99px;font-size:11px;font-weight:700;cursor:pointer;border:1.5px solid var(--border);background:var(--bg-secondary);color:var(--text-muted);transition:all .15s; }
.ff-pill.active { background:var(--accent);color:#fff;border-color:var(--accent); }
.ff-result-row { display:flex;align-items:center;gap:12px;padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border);transition:background .1s; }
.ff-result-row:hover { background:var(--bg-secondary); }
.ff-result-row:last-child { border-bottom:none; }

/* ── t186: Print / PDF Holdings ──────────────────────────────── */
@media print {
  /* Hide everything except print area */
  body * { visibility: hidden; }
  #mfPrintArea, #mfPrintArea * { visibility: visible; }
  #mfPrintArea { position: absolute; inset: 0; padding: 20px; }

  /* Reset colours for print */
  #mfPrintArea { background: #fff !important; color: #111 !important; }
  .mfp-card { break-inside: avoid; }

  /* Hide nav, sidebar, buttons, modals */
  .sidebar, .topbar, .page-header-actions,
  .modal-overlay, #txnDrawer, #txnDrawerOverlay,
  .sort-menu, #sortMenuDropdown { display: none !important; }

  /* Page margins */
  @page { margin: 15mm 12mm; size: A4 portrait; }
}
</style>

<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/charts.js?v=' . filemtime(APP_ROOT.'/public/js/charts.js') . '"></script>'
             . '<script src="' . APP_URL . '/public/js/mf.js?v=' . filemtime(APP_ROOT.'/public/js/mf.js') . '"></script>'
             ;
require_once APP_ROOT . '/templates/layout.php';
?>