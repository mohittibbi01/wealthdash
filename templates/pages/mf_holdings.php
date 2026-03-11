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
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
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
  <div class="stat-card">
    <div class="stat-label">Total Invested</div>
    <div class="stat-value" id="mfTotalInvested"><?= format_inr($totalInvested) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Current Value</div>
    <div class="stat-value" id="mfValueNow"><?= format_inr($valueNow) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Gain / Loss</div>
    <div class="stat-value <?= $gainLoss >= 0 ? 'positive' : 'negative' ?>" id="mfGainLoss">
      <?= ($gainLoss >= 0 ? '+' : '') . format_inr($gainLoss) ?> (<?= $gainPct ?>%)
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Funds Held</div>
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
    <select id="filterPortfolio" class="filter-select">
      <option value="">All Portfolios</option>
      <?php foreach ($portfolios as $p): ?>
      <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="filterCategory" class="filter-select">
      <option value="">All Categories</option>
      <option value="Equity">Equity</option>
      <option value="Debt">Debt</option>
      <option value="Hybrid">Hybrid</option>
      <option value="Index">Index</option>
      <option value="ELSS">ELSS</option>
      <option value="Liquid">Liquid</option>
    </select>
    <select id="filterGainType" class="filter-select">
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
          <th class="text-center sortable" data-col="gain_type">Type</th>
          <th class="text-center sortable" data-col="ltcg_date">LTCG Date</th>
          <th class="text-center" style="width:80px;">Actions</th>
        </tr>
      </thead>
      <tbody id="holdingsBody">
        <tr><td colspan="11" class="text-center" style="padding:40px;">
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
          <td colspan="6"></td>
        </tr>
      </tfoot>
    </table>
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
  <select id="rFilterPortfolio" class="filter-select">
    <option value="">All Portfolios</option>
    <?php foreach ($portfolios as $p): ?>
    <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select id="rFilterFy" class="filter-select">
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
  <select id="rFilterType" class="filter-select">
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
        <tr><td colspan="11" class="text-center" style="padding:40px;color:var(--text-muted);">
          Select a portfolio to load realized gains.
        </td></tr>
      </tbody>
      <tfoot id="realizedTfoot" style="display:none;">
        <tr style="background:var(--bg-secondary);font-weight:600;">
          <td colspan="4">Total</td>
          <td class="text-center" id="rFootProceeds"></td>
          <td class="text-center" id="rFootCost"></td>
          <td class="text-center" id="rFootGain"></td>
          <td colspan="4"></td>
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
  <select id="dFilterPortfolio" class="filter-select">
    <option value="">All Portfolios</option>
    <?php foreach ($portfolios as $p): ?>
    <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select id="dFilterFy" class="filter-select">
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
<div id="txnDrawerOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:998;" onclick="closeTxnDrawer()"></div>
<div id="txnDrawer" style="display:none;position:fixed;top:0;right:0;width:min(680px,100vw);height:100vh;background:var(--bg-card);border-left:1px solid var(--border-color);z-index:999;overflow-y:auto;box-shadow:-4px 0 24px rgba(0,0,0,.15);">
  <div style="padding:20px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--bg-card);z-index:1;">
    <h3 id="drawerTitle" style="margin:0;font-size:16px;">Transactions</h3>
    <button class="btn btn-ghost btn-sm" onclick="closeTxnDrawer()">✕ Close</button>
  </div>
  <div id="drawerContent" style="padding:20px;"></div>
</div>

<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/mf.js?v=2"></script>';
require_once APP_ROOT . '/templates/layout.php';
?>