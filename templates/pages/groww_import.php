<?php
/**
 * WealthDash — Groww Import Page (t302 + t392)
 * Path: templates/pages/groww_import.php
 * Covers: CSV import (t302) + API Sync (t392)
 */
defined('WEALTHDASH') or die();
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Groww Import';
$activePage  = 'groww_import';

ob_start();
?>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">Groww Import</h1>
    <p class="page-subtitle">Import MF transactions and stock holdings from Groww</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost btn-sm" id="btnGrowwHistory">Import History</button>
  </div>
</div>

<!-- ═══ TABS ═══ -->
<div class="card" style="margin-bottom:20px;">
  <div style="display:flex;border-bottom:1px solid var(--border-color);">
    <button class="groww-tab active" data-tab="csv" style="padding:12px 20px;background:none;border:none;border-bottom:2px solid var(--primary);color:var(--primary);font-weight:600;cursor:pointer;font-size:14px;">
      📁 CSV Import
    </button>
    <button class="groww-tab" data-tab="api" style="padding:12px 20px;background:none;border:none;border-bottom:2px solid transparent;color:var(--text-muted);cursor:pointer;font-size:14px;">
      🔗 API Sync
    </button>
    <button class="groww-tab" data-tab="mapping" style="padding:12px 20px;background:none;border:none;border-bottom:2px solid transparent;color:var(--text-muted);cursor:pointer;font-size:14px;">
      🗂️ Fund Mapping
    </button>
  </div>

  <!-- ══ CSV TAB ══ -->
  <div id="tabCsv" class="groww-tab-content" style="padding:24px;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

      <!-- Step 1: Upload -->
      <div>
        <h3 style="margin:0 0 4px;font-size:15px;">Step 1 — Upload CSV</h3>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">
          Download from Groww app: <strong>Portfolio → Export → CSV</strong>
        </p>

        <div id="csvDropZone"
             style="border:2px dashed var(--border-color);border-radius:12px;padding:32px;text-align:center;cursor:pointer;transition:all .2s;"
             ondragover="event.preventDefault();this.style.borderColor='var(--primary)'"
             ondragleave="this.style.borderColor='var(--border-color)'"
             ondrop="growwHandleDrop(event)">
          <div style="font-size:32px;margin-bottom:8px;">📤</div>
          <div style="font-weight:600;">Drop Groww CSV here</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">or click to browse</div>
          <input type="file" id="csvFileInput" accept=".csv" style="display:none;" onchange="growwHandleFile(this.files[0])">
        </div>
        <button class="btn btn-ghost btn-sm" style="margin-top:8px;width:100%;" onclick="document.getElementById('csvFileInput').click()">Browse File</button>

        <div style="margin-top:16px;">
          <label class="form-label">Portfolio</label>
          <select id="csvPortfolioId" class="form-control">
            <option value="">Loading portfolios…</option>
          </select>
        </div>
      </div>

      <!-- Step 2: Preview -->
      <div>
        <h3 style="margin:0 0 4px;font-size:15px;">Step 2 — Preview & Import</h3>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">
          Review detected format and preview rows before committing
        </p>

        <div id="csvDetectResult" style="display:none;">
          <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
              <strong id="csvFormatLabel">—</strong>
              <span id="csvConfidenceBadge" style="font-size:11px;padding:2px 8px;border-radius:10px;background:var(--success);color:#fff;">—</span>
            </div>
            <div style="font-size:12px;color:var(--text-muted);">
              Type: <span id="csvTypeLabel">—</span> &nbsp;|&nbsp;
              Rows: <span id="csvRowCount">—</span>
            </div>
          </div>

          <!-- Preview table -->
          <div style="max-height:200px;overflow:auto;border:1px solid var(--border-color);border-radius:8px;margin-bottom:16px;">
            <table class="table table-sm" id="csvPreviewTable">
              <thead><tr id="csvPreviewHeaders"></tr></thead>
              <tbody id="csvPreviewBody"></tbody>
            </table>
          </div>

          <div style="display:flex;gap:8px;">
            <button class="btn btn-ghost btn-sm" id="btnCsvPreviewOnly">Preview Import</button>
            <button class="btn btn-primary" id="btnCsvImport">Import Now</button>
          </div>
        </div>

        <div id="csvDetectPlaceholder" style="color:var(--text-muted);font-size:13px;text-align:center;padding:40px 0;">
          Upload a CSV file to see preview
        </div>

        <!-- Progress -->
        <div id="csvImportProgress" style="display:none;margin-top:16px;">
          <div style="font-size:13px;font-weight:600;margin-bottom:8px;" id="csvProgressLabel">Importing…</div>
          <div style="background:var(--bg-secondary);border-radius:20px;height:8px;">
            <div id="csvProgressBar" style="background:var(--primary);height:8px;border-radius:20px;width:0;transition:width .3s;"></div>
          </div>
        </div>

        <!-- Result -->
        <div id="csvImportResult" style="display:none;margin-top:16px;"></div>
      </div>
    </div>

    <!-- Unmapped funds warning -->
    <div id="unmappedFundsAlert" style="display:none;margin-top:20px;padding:14px;background:rgba(245,158,11,.08);border:1px solid var(--warning,#f59e0b);border-radius:10px;">
      <strong>⚠️ Some funds could not be matched</strong>
      <p style="font-size:13px;margin:4px 0 8px;">These Groww fund names did not match any fund in WealthDash. Go to "Fund Mapping" tab to resolve them.</p>
      <div id="unmappedFundsList" style="font-size:12px;"></div>
    </div>
  </div>

  <!-- ══ API TAB ══ -->
  <div id="tabApi" class="groww-tab-content" style="display:none;padding:24px;">

    <!-- Connection Status -->
    <div id="apiStatusCard" style="background:var(--bg-secondary);border-radius:12px;padding:20px;margin-bottom:24px;">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
          <div style="font-weight:700;font-size:15px;" id="apiStatusTitle">Checking connection…</div>
          <div style="font-size:13px;color:var(--text-muted);margin-top:2px;" id="apiStatusSub">—</div>
        </div>
        <div style="display:flex;gap:8px;" id="apiStatusActions"></div>
      </div>
    </div>

    <!-- Connect Form (shown when not connected) -->
    <div id="apiConnectForm" style="display:none;">
      <h3 style="margin:0 0 4px;">Link Groww Account</h3>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">
        Enter your Groww access token. Get it from Groww Developer Portal (when available) or contact Groww support.
      </p>
      <div style="background:rgba(59,130,246,.06);border:1px solid var(--primary);border-radius:10px;padding:14px;margin-bottom:20px;font-size:13px;">
        <strong>ℹ️ Note:</strong> Groww does not have an official public API as of 2026. Currently, CSV import (left tab) is the recommended method.
        API sync will activate automatically when Groww launches their official API.
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:2;">
          <label class="form-label">Access Token</label>
          <input type="password" id="apiAccessToken" class="form-control" placeholder="Paste your Groww access token">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Linked Email</label>
          <input type="email" id="apiEmail" class="form-control" placeholder="your@email.com">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Scope</label>
          <input type="text" id="apiScope" class="form-control" value="mf,stocks,profile" placeholder="mf,stocks">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Token Expires In (seconds)</label>
          <input type="number" id="apiExpiresIn" class="form-control" value="3600" min="60">
        </div>
      </div>
      <button class="btn btn-primary" id="btnApiConnect">Link Account</button>
    </div>

    <!-- Sync Panel (shown when connected) -->
    <div id="apiSyncPanel" style="display:none;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
        <div class="card" style="padding:20px;">
          <h4 style="margin:0 0 8px;">🏦 MF Holdings</h4>
          <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">Sync mutual fund holdings and NAV from Groww</p>
          <div style="margin-bottom:12px;">
            <label class="form-label">Portfolio</label>
            <select id="apiMfPortfolioId" class="form-control api-portfolio-select"></select>
          </div>
          <button class="btn btn-primary" id="btnSyncMf">Sync MF Holdings</button>
        </div>
        <div class="card" style="padding:20px;">
          <h4 style="margin:0 0 8px;">📈 Stock Holdings</h4>
          <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">Sync equity portfolio from Groww Demat</p>
          <div style="margin-bottom:12px;">
            <label class="form-label">Portfolio</label>
            <select id="apiStockPortfolioId" class="form-control api-portfolio-select"></select>
          </div>
          <button class="btn btn-primary" id="btnSyncStocks">Sync Stocks</button>
        </div>
      </div>

      <!-- Sync Log -->
      <div class="card">
        <div class="card-header"><h3 class="card-title" style="margin:0;">Sync History</h3></div>
        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr><th>Date</th><th>Type</th><th>MF Synced</th><th>Stocks Synced</th><th>Errors</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody id="apiSyncLogBody">
              <tr><td colspan="7" class="text-center" style="padding:20px;">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ FUND MAPPING TAB ══ -->
  <div id="tabMapping" class="groww-tab-content" style="display:none;padding:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <div>
        <h3 style="margin:0 0 2px;">Unresolved Fund Mappings</h3>
        <p style="font-size:13px;color:var(--text-muted);margin:0;">Match Groww fund names to WealthDash funds</p>
      </div>
      <button class="btn btn-ghost btn-sm" id="btnRefreshMappings">Refresh</button>
    </div>

    <div id="mappingTableWrap">
      <table class="table">
        <thead>
          <tr><th>Groww Fund Name</th><th>Suggested Match</th><th>WealthDash Fund</th><th style="width:100px;">Action</th></tr>
        </thead>
        <tbody id="mappingBody">
          <tr><td colspan="4" class="text-center" style="padding:30px;color:var(--text-muted);">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ═══ HISTORY PANEL ═══ -->
<div id="importHistoryPanel" style="display:none;">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Import History</h3>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('importHistoryPanel').style.display='none'">✕ Close</button>
    </div>
    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr><th>Date</th><th>File</th><th>Type</th><th class="text-right">Imported</th><th class="text-right">Skipped</th><th class="text-right">Errors</th><th>Status</th></tr>
        </thead>
        <tbody id="historyBody">
          <tr><td colspan="7" class="text-center" style="padding:20px;">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/groww_import.js?v=' . time() . '"></script>';
require_once APP_ROOT . '/templates/layout.php';
