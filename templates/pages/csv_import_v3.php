<?php
/**
 * WealthDash — CSV Importer v3 Page (t490)
 * Path: templates/pages/csv_import_v3.php
 * Extends existing mf_import_csv_v3.php functionality with UI
 */
defined('WEALTHDASH') or die();
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'CSV Import v3';
$activePage  = 'csv_import_v3';

ob_start();
?>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">CSV Importer v3</h1>
    <p class="page-subtitle">Auto-detect any broker CSV format — CAMS, Groww, Zerodha, Kuvera & more</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost btn-sm" id="btnV3History">History</button>
    <button class="btn btn-ghost btn-sm" id="btnV3Presets">📋 Saved Presets</button>
  </div>
</div>

<!-- ═══ SUPPORTED FORMATS ═══ -->
<div id="formatsBar" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:20px;"></div>

<!-- ═══ MAIN IMPORT PANEL ═══ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <!-- Left: Upload + Detect -->
  <div class="card">
    <div class="card-header"><h3 class="card-title" style="margin:0;">Upload CSV</h3></div>
    <div class="card-body">

      <div id="v3DropZone"
           style="border:2px dashed var(--border-color);border-radius:12px;padding:36px;text-align:center;cursor:pointer;transition:border-color .2s;margin-bottom:16px;"
           ondragover="event.preventDefault();this.style.borderColor='var(--primary)'"
           ondragleave="this.style.borderColor='var(--border-color)'"
           ondrop="v3HandleDrop(event)"
           onclick="document.getElementById('v3FileInput').click()">
        <div id="v3DropIcon" style="font-size:36px;margin-bottom:8px;">📁</div>
        <div id="v3DropLabel" style="font-weight:600;">Drop CSV file here</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">CAMS · Groww · Zerodha · Kuvera · KFintech · any broker</div>
        <input type="file" id="v3FileInput" accept=".csv" style="display:none;" onchange="v3HandleFile(this.files[0])">
      </div>

      <div class="form-group">
        <label class="form-label">Portfolio *</label>
        <select id="v3PortfolioId" class="form-control">
          <option value="">— Select Portfolio —</option>
        </select>
      </div>

      <!-- Detected format info -->
      <div id="v3DetectBox" style="display:none;background:var(--bg-secondary);border-radius:10px;padding:14px;margin-top:4px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
          <strong id="v3FormatName" style="color:var(--primary);">—</strong>
          <span id="v3ConfidencePill" style="font-size:11px;padding:2px 8px;border-radius:10px;background:var(--success);color:#fff;">—</span>
        </div>
        <div style="font-size:12px;color:var(--text-muted);">
          Rows: <span id="v3RowCount">—</span> &nbsp;|&nbsp;
          Header at row: <span id="v3HeaderRow">—</span>
        </div>
        <div id="v3DetectWarning" style="display:none;font-size:12px;color:var(--warning,#f59e0b);margin-top:6px;"></div>
      </div>

      <!-- Column mapping (shown when columns detected) -->
      <div id="v3ColMapPanel" style="display:none;margin-top:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
          <label class="form-label" style="margin:0;">Column Mapping</label>
          <div style="display:flex;gap:6px;">
            <button class="btn btn-ghost btn-xs" id="btnSavePreset">Save as Preset</button>
            <select id="v3PresetSelect" class="form-control" style="width:140px;padding:4px 8px;font-size:12px;" onchange="applyPreset(this.value)">
              <option value="">Load preset…</option>
            </select>
          </div>
        </div>
        <div id="v3ColMapGrid" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;max-height:240px;overflow-y:auto;"></div>
      </div>
    </div>
  </div>

  <!-- Right: Preview + Import -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Preview & Import</h3>
      <button class="btn btn-ghost btn-xs" id="btnV3Refresh" style="display:none;" onclick="v3Detect()">Re-detect</button>
    </div>
    <div class="card-body">

      <div id="v3PreviewPlaceholder" style="color:var(--text-muted);font-size:13px;text-align:center;padding:40px;">
        Upload a CSV to preview
      </div>

      <!-- Preview table -->
      <div id="v3PreviewWrap" style="display:none;">
        <div style="max-height:240px;overflow:auto;border:1px solid var(--border-color);border-radius:8px;margin-bottom:12px;">
          <table class="table table-sm" id="v3PreviewTable">
            <thead><tr id="v3PreviewHeader"></tr></thead>
            <tbody id="v3PreviewBody"></tbody>
          </table>
        </div>

        <!-- Import progress -->
        <div id="v3ImportProgress" style="display:none;margin-bottom:12px;">
          <div style="font-size:13px;margin-bottom:6px;" id="v3ProgressLabel">Importing…</div>
          <div style="background:var(--bg-secondary);border-radius:20px;height:6px;">
            <div id="v3ProgressBar" style="background:var(--primary);height:6px;border-radius:20px;width:0;transition:width .3s;"></div>
          </div>
        </div>

        <!-- Import result -->
        <div id="v3ImportResult" style="display:none;margin-bottom:12px;"></div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="btn btn-ghost btn-sm" id="btnV3Preview">Preview</button>
          <button class="btn btn-primary" id="btnV3Import">Import</button>
          <div style="flex:1;"></div>
          <button class="btn btn-ghost btn-sm" id="btnV3SaveSession" style="font-size:12px;">Save Session</button>
        </div>

        <!-- Error summary -->
        <div id="v3ErrorSummary" style="display:none;margin-top:12px;font-size:12px;color:var(--danger);max-height:120px;overflow-y:auto;"></div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ SESSION HISTORY ═══ -->
<div id="v3HistoryPanel" style="display:none;margin-bottom:20px;">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Import History</h3>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('v3HistoryPanel').style.display='none'">✕</button>
    </div>
    <div class="table-wrapper">
      <table class="table table-sm">
        <thead>
          <tr><th>Date</th><th>File</th><th>Format</th><th>Rows</th><th class="text-right">Imported</th><th class="text-right">Errors</th><th>Status</th><th>Retry</th></tr>
        </thead>
        <tbody id="v3HistoryBody">
          <tr><td colspan="8" class="text-center" style="padding:20px;">Loading…</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Stats -->
    <div class="card-footer" id="v3HistoryStats" style="font-size:12px;color:var(--text-muted);"></div>
  </div>
</div>

<!-- ═══ PRESETS PANEL ═══ -->
<div id="v3PresetsPanel" style="display:none;margin-bottom:20px;">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Saved Column Mapping Presets</h3>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('v3PresetsPanel').style.display='none'">✕</button>
    </div>
    <div class="table-wrapper">
      <table class="table table-sm">
        <thead>
          <tr><th>Name</th><th>Format Hint</th><th class="text-right">Used</th><th>Last Used</th><th>Actions</th></tr>
        </thead>
        <tbody id="v3PresetsList">
          <tr><td colspan="5" class="text-center" style="padding:20px;">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══ SAVE PRESET MODAL ═══ -->
<div class="modal-overlay" id="modalSavePreset" style="display:none;">
  <div class="modal" style="max-width:380px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">Save as Preset</h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="document.getElementById('modalSavePreset').style.display='none'">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Preset Name *</label>
        <input type="text" id="presetName" class="form-control" placeholder="e.g. My CAMS Format">
      </div>
      <div class="form-group">
        <label class="form-label">Format Hint</label>
        <input type="text" id="presetFormatHint" class="form-control" placeholder="e.g. cams, groww, custom">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="document.getElementById('modalSavePreset').style.display='none'">Cancel</button>
      <button class="btn btn-primary" id="btnConfirmSavePreset">Save</button>
    </div>
  </div>
</div>

<input type="hidden" id="v3Csrf" value="<?= generate_csrf() ?>">

<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/csv_import_v3.js?v=' . time() . '"></script>';
require_once APP_ROOT . '/templates/layout.php';
