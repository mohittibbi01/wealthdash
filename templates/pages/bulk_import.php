<?php
/**
 * WealthDash — Bulk Import Page (t334)
 * Path: templates/pages/bulk_import.php
 */
defined('WEALTHDASH') or die();
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Bulk Import';
$activePage  = 'bulk_import';

ob_start();
?>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">Bulk Import</h1>
    <p class="page-subtitle">Import up to 50 fields per transaction via Excel template</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost btn-sm" id="btnBulkHistory">Session History</button>
    <button class="btn btn-primary" id="btnDownloadTemplate">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:5px;vertical-align:-2px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download Template
    </button>
  </div>
</div>

<!-- ═══ STEPS ═══ -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px;">
  <div class="stat-card" style="text-align:center;padding:20px;">
    <div style="font-size:28px;margin-bottom:6px;">1️⃣</div>
    <div style="font-weight:700;margin-bottom:4px;">Download Template</div>
    <div style="font-size:12px;color:var(--text-muted);">50-field Excel template with examples and validation hints</div>
  </div>
  <div class="stat-card" style="text-align:center;padding:20px;">
    <div style="font-size:28px;margin-bottom:6px;">2️⃣</div>
    <div style="font-weight:700;margin-bottom:4px;">Fill Data</div>
    <div style="font-size:12px;color:var(--text-muted);">Fill in your MF transactions. Required fields marked with *</div>
  </div>
  <div class="stat-card" style="text-align:center;padding:20px;">
    <div style="font-size:28px;margin-bottom:6px;">3️⃣</div>
    <div style="font-weight:700;margin-bottom:4px;">Validate & Import</div>
    <div style="font-size:12px;color:var(--text-muted);">Review errors, fix them, then commit import</div>
  </div>
</div>

<!-- ═══ UPLOAD SECTION ═══ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <!-- Upload card -->
  <div class="card">
    <div class="card-header"><h3 class="card-title" style="margin:0;">Upload File</h3></div>
    <div class="card-body">
      <div id="bulkDropZone"
           style="border:2px dashed var(--border-color);border-radius:12px;padding:32px;text-align:center;cursor:pointer;transition:all .2s;margin-bottom:16px;"
           ondragover="event.preventDefault();this.style.borderColor='var(--primary)'"
           ondragleave="this.style.borderColor='var(--border-color)'"
           ondrop="bulkHandleDrop(event)"
           onclick="document.getElementById('bulkFileInput').click()">
        <div style="font-size:32px;margin-bottom:8px;">📊</div>
        <div style="font-weight:600;">Drop Excel (.xlsx) or CSV here</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">or click to browse</div>
        <input type="file" id="bulkFileInput" accept=".xlsx,.csv" style="display:none;" onchange="bulkHandleFile(this.files[0])">
      </div>

      <div class="form-group">
        <label class="form-label">Portfolio *</label>
        <select id="bulkPortfolioId" class="form-control">
          <option value="">— Select Portfolio —</option>
        </select>
      </div>

      <div style="display:flex;align-items:center;gap:8px;font-size:13px;">
        <input type="checkbox" id="bulkSkipErrors" checked style="width:14px;height:14px;">
        <label for="bulkSkipErrors">Skip error rows and import valid ones</label>
      </div>
    </div>
  </div>

  <!-- Validation results -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Validation</h3>
      <span id="bulkValidStatus" style="font-size:12px;color:var(--text-muted);"></span>
    </div>
    <div class="card-body">
      <div id="bulkValidPlaceholder" style="color:var(--text-muted);font-size:13px;text-align:center;padding:30px;">
        Upload a file to see validation results
      </div>

      <div id="bulkValidResult" style="display:none;">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px;">
          <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:700;" id="bulkTotalRows">—</div>
            <div style="font-size:11px;color:var(--text-muted);">Total Rows</div>
          </div>
          <div style="background:rgba(16,185,129,.08);border-radius:8px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:700;color:var(--success);" id="bulkValidRows">—</div>
            <div style="font-size:11px;color:var(--text-muted);">Valid</div>
          </div>
          <div style="background:rgba(239,68,68,.08);border-radius:8px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:700;color:var(--danger);" id="bulkErrorCount">—</div>
            <div style="font-size:11px;color:var(--text-muted);">Errors</div>
          </div>
        </div>

        <!-- Error rows -->
        <div id="bulkErrorPanel" style="display:none;max-height:200px;overflow-y:auto;border:1px solid rgba(239,68,68,.3);border-radius:8px;margin-bottom:16px;"></div>

        <div style="display:flex;gap:8px;">
          <button class="btn btn-ghost btn-sm" id="btnBulkReValidate">Re-Validate</button>
          <button class="btn btn-primary" id="btnBulkImport" disabled>Import Valid Rows</button>
        </div>

        <div id="bulkImportProgress" style="display:none;margin-top:12px;">
          <div style="font-size:13px;margin-bottom:6px;" id="bulkProgressLabel">Importing…</div>
          <div style="background:var(--bg-secondary);border-radius:20px;height:6px;">
            <div id="bulkProgressBar" style="background:var(--primary);height:6px;border-radius:20px;width:0;transition:width .3s;"></div>
          </div>
        </div>

        <div id="bulkImportResult" style="display:none;margin-top:12px;"></div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ FIELD REFERENCE ═══ -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;cursor:pointer;" onclick="toggleFieldRef()">
    <h3 class="card-title" style="margin:0;">📋 Template Field Reference (50 fields)</h3>
    <span id="fieldRefToggle" style="font-size:12px;color:var(--text-muted);">▼ Expand</span>
  </div>
  <div id="fieldRefContent" style="display:none;">
    <div class="table-wrapper">
      <table class="table table-sm">
        <thead>
          <tr><th>Col</th><th>Field</th><th>Label</th><th>Required</th><th>Type</th><th>Example</th></tr>
        </thead>
        <tbody id="fieldRefBody">
          <tr><td colspan="6" class="text-center" style="padding:20px;color:var(--text-muted);">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══ HISTORY PANEL ═══ -->
<div id="bulkHistoryPanel" style="display:none;">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Import History</h3>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('bulkHistoryPanel').style.display='none'">✕</button>
    </div>
    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr><th>Date</th><th>File</th><th>Rows</th><th>Valid</th><th class="text-right">Imported</th><th class="text-right">Errors</th><th>Status</th><th>Detail</th></tr>
        </thead>
        <tbody id="bulkHistoryBody">
          <tr><td colspan="8" class="text-center" style="padding:20px;">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<input type="hidden" id="bulkCsrf" value="<?= generate_csrf() ?>">
<input type="hidden" id="bulkSessionId" value="">

<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/bulk_import.js?v=' . time() . '"></script>';
require_once APP_ROOT . '/templates/layout.php';
