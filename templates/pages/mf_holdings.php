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
$pStmt = $db->prepare("SELECT p.id, p.name, p.color FROM portfolios p WHERE p.user_id=? ORDER BY p.name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();

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
    <p class="page-subtitle">Holdings across all portfolios</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost" id="btnImportCsv">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
  <polyline points="7 10 12 15 17 10"/>
  <line x1="12" y1="15" x2="12" y2="3"/>
</svg>
      Import CSV
    </button>
    <button class="btn btn-ghost" id="btnDownloadExcel" title="Download Excel">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      Download Excel
    </button>
    <button class="btn btn-primary" id="btnAddTransaction">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Transaction
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
</div>

<!-- ═══ TAB: HOLDINGS ═══ -->
<div id="tabHoldings">
<div class="mf-filter-bar">
  <div class="mf-filter-selects">
    <select id="filterPortfolio" class="filter-select" data-custom-dropdown>
      <option value="">All Portfolios</option>
      <?php foreach ($portfolios as $p): ?>
      <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
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
  <div class="mf-view-toggle">
    <button class="view-btn active" id="viewCombined">Combined</button>
    <button class="view-btn" id="viewFolio">Folio-wise</button>
  </div>
</div>

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <h3 class="card-title" style="margin:0;">Holdings</h3>
    <div style="display:flex;gap:8px;align-items:center;">
      <input type="search" id="searchFund" class="form-control form-control-sm" placeholder="Search fund..." style="width:200px;">
      <span id="holdingsCount" style="color:var(--text-muted);font-size:13px;"></span>
    </div>
  </div>
  <div class="table-wrapper">
    <table class="table table-hover table-grid" id="holdingsTable">
      <thead>
        <tr>
          <th class="sortable" data-col="scheme_name">Fund</th>
          <th class="text-center sortable" data-col="total_invested">Invested</th>
          <th class="text-center sortable" data-col="value_now">Current Value</th>
          <th class="text-center sortable" data-col="gain_loss">Gain/Loss</th>
          <th class="text-center sortable" data-col="gain_pct">Returns</th>
          <th class="text-center sortable" data-col="cagr">XIRR</th>
          <th class="text-center sortable" data-col="total_units">Units</th>
          <th class="text-center sortable" data-col="latest_nav">NAV</th>
          <th class="text-center" style="width:80px;">Actions</th>
        </tr>
      </thead>
      <tbody id="holdingsBody">
        <tr><td colspan="9" class="text-center" style="padding:40px;">
          <div class="spinner"></div>
          <p style="color:var(--text-muted);margin-top:12px;">Loading holdings...</p>
        </td></tr>
      </tbody>
      <tfoot id="holdingsTfoot" style="display:none;">
        <tr style="background:var(--bg-secondary);font-weight:600;">
          <td>Total</td>
          <td class="text-center" id="footInvested"></td>
          <td class="text-center" id="footValue"></td>
          <td class="text-center" id="footGain"></td>
          <td class="text-center" id="footGainPct"></td>
          <td colspan="5"></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <!-- Pagination -->
  <div class="card-footer" id="holdingsPaginationWrap" style="display:none;justify-content:space-between;align-items:center;">
    <div id="holdingsPaginationInfo" style="font-size:13px;color:var(--text-muted);"></div>
    <div id="holdingsPagination" style="display:flex;gap:4px;"></div>
  </div>
</div>
</div><!-- end tabHoldings -->

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
  <select id="rFilterPortfolio" class="filter-select" data-custom-dropdown>
    <option value="">All Portfolios</option>
    <?php foreach ($portfolios as $p): ?>
    <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
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
        <tr><td colspan="9" class="text-center" style="padding:40px;color:var(--text-muted);">
          Select a portfolio to load realized gains.
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
  <select id="dFilterPortfolio" class="filter-select" data-custom-dropdown>
    <option value="">All Portfolios</option>
    <?php foreach ($portfolios as $p): ?>
    <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
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
          Select a portfolio to load dividends.
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

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Portfolio *</label>
          <select id="txnPortfolio" class="form-control">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Transaction Type *</label>
          <select id="txnType" class="form-control">
            <option value="BUY">BUY — Purchase</option>
            <option value="SELL">SELL — Redemption</option>
            <option value="DIV_REINVEST">Dividend Reinvestment</option>
            <option value="SWITCH_IN">Switch In</option>
            <option value="SWITCH_OUT">Switch Out</option>
          </select>
        </div>
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

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Units *</label>
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

<!-- ═══ IMPORT CSV MODAL ═══ -->
<div class="modal-overlay" id="modalImportCsv" style="display:none;">
  <div class="modal" style="max-width:480px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">Import CSV</h3>
      <button class="modal-close btn btn-ghost btn-sm" id="btnCloseImportModal">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Portfolio</label>
        <select id="importPortfolio" class="form-control">
          <?php foreach ($portfolios as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Source Format</label>
        <select id="importFormat" class="form-control">
          <option value="auto">Auto-detect</option>
          <option value="wealthdash">WealthDash Custom</option>
          <option value="cams">CAMS Statement</option>
          <option value="kfintech">KFintech / Karvy</option>
          <option value="groww">Groww Export</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">CSV File</label>
        <input type="file" id="importFile" class="form-control" accept=".csv,.txt">
      </div>
      <div class="import-template-hint">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <a href="<?= APP_URL ?>/public/downloads/wealthdash_mf_template.csv" download>Download sample CSV template</a>
        &nbsp;|&nbsp;
        <span style="color:var(--text-muted);">Supported: WealthDash, CAMS, KFintech, Groww</span>
      </div>
      <div id="importResult" style="display:none;margin-top:16px;padding:12px;border-radius:8px;font-size:13px;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="btnCancelImport">Cancel</button>
      <button class="btn btn-primary" id="btnStartImport">
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

<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/mf.js?v=4"></script>';
require_once APP_ROOT . '/templates/layout.php';
?>