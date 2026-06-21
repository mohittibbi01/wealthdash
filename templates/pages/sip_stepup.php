<?php
/**
 * WealthDash — SIP Step-Up Nudge Page
 * Task: t144
 * Path: templates/pages/sip_stepup.php
 */
defined('WEALTHDASH') or die();
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'SIP Step-Up';
$activePage  = 'sip_stepup';

ob_start();
?>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">SIP Step-Up Nudge</h1>
    <p class="page-subtitle">Salary hike ke saath SIP badhao — auto step-up planner</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost btn-sm" id="btnSimulator">📊 Simulator</button>
    <button class="btn btn-ghost btn-sm" id="btnSalaryHike">💼 Got a Hike?</button>
    <button class="btn btn-primary" id="btnAddStepup">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="margin-right:5px;vertical-align:-2px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Step-Up
    </button>
  </div>
</div>

<!-- ═══ NUDGE BANNER (shown when upcoming step-ups exist) ═══ -->
<div id="stepupNudgeBanner" style="display:none;margin-bottom:18px;padding:16px 20px;background:linear-gradient(135deg,var(--primary-light,#eff6ff),var(--bg-card));border:1px solid var(--primary);border-radius:12px;">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
      <div style="font-weight:700;font-size:15px;margin-bottom:2px;">🚀 Step-Up Due!</div>
      <div id="nudgeBannerText" style="font-size:13px;color:var(--text-muted);"></div>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-primary btn-sm" id="btnApplyDueStepups">Apply Now</button>
      <button class="btn btn-ghost btn-sm" id="btnDismissNudge">Dismiss</button>
    </div>
  </div>
</div>

<!-- ═══ SUMMARY CARDS ═══ -->
<div class="stats-grid" style="margin-bottom:18px;">
  <div class="stat-card">
    <div class="stat-label">Active Step-Up Plans</div>
    <div class="stat-value" id="statStepupCount">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Next Step-Up</div>
    <div class="stat-value" id="statNextStepup" style="font-size:18px;">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Step-Ups Applied</div>
    <div class="stat-value" id="statTotalApplied">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Upcoming (30 days)</div>
    <div class="stat-value" id="statUpcomingCount">—</div>
  </div>
</div>

<!-- ═══ STEP-UP CONFIG TABLE ═══ -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <h3 class="card-title" style="margin:0;">Step-Up Plans</h3>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-ghost btn-sm" id="btnViewHistory">History</button>
    </div>
  </div>
  <div class="table-wrapper">
    <table class="table table-hover" id="stepupTable">
      <thead>
        <tr>
          <th>Fund</th>
          <th class="text-right">Current SIP</th>
          <th>Step-Up</th>
          <th>Frequency</th>
          <th>Next Date</th>
          <th>Last Applied</th>
          <th>Max Cap</th>
          <th>Status</th>
          <th style="width:100px;">Actions</th>
        </tr>
      </thead>
      <tbody id="stepupBody">
        <tr><td colspan="9" class="text-center" style="padding:40px;"><div class="spinner"></div></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ HISTORY PANEL ═══ -->
<div id="historyPanel" style="display:none;margin-bottom:20px;">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Step-Up History</h3>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('historyPanel').style.display='none'">✕ Close</button>
    </div>
    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th><th>Fund</th>
            <th class="text-right">From</th>
            <th class="text-right">To</th>
            <th class="text-right">Increase</th>
            <th>Type</th><th>Applied By</th>
          </tr>
        </thead>
        <tbody id="historyBody">
          <tr><td colspan="7" class="text-center" style="padding:20px;">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══ SIMULATOR PANEL ═══ -->
<div id="simulatorPanel" style="display:none;margin-bottom:20px;">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Step-Up Simulator</h3>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('simulatorPanel').style.display='none'">✕ Close</button>
    </div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Monthly SIP (₹)</label>
          <input type="number" id="simSipAmount" class="form-control" value="5000" step="500" min="100">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Step-Up Type</label>
          <select id="simStepupType" class="form-control">
            <option value="percentage">Percentage (%)</option>
            <option value="fixed_amount">Fixed Amount (₹)</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Step-Up Value</label>
          <input type="number" id="simStepupValue" class="form-control" value="10" step="1" min="1">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Expected XIRR (%)</label>
          <input type="number" id="simXirr" class="form-control" value="12" step="0.5" min="1" max="50">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Duration (Years)</label>
          <input type="number" id="simYears" class="form-control" value="10" step="1" min="1" max="30">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Max SIP Cap (₹)</label>
          <input type="number" id="simMaxCap" class="form-control" placeholder="No cap" step="500" min="0">
        </div>
      </div>
      <button class="btn btn-primary" id="btnRunSimulator">Run Simulation</button>

      <div id="simResults" style="display:none;margin-top:20px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px;" id="simSummaryCards"></div>
        <div class="table-wrapper" style="max-height:400px;overflow-y:auto;">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Year</th>
                <th class="text-right">SIP Amount</th>
                <th class="text-right">Annual Step-Up</th>
                <th class="text-right">Cumulative Invested</th>
                <th class="text-right">Projected Corpus</th>
                <th class="text-right">Wealth Multiple</th>
              </tr>
            </thead>
            <tbody id="simResultsBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ ADD / EDIT STEP-UP MODAL ═══ -->
<div class="modal-overlay" id="modalStepup" style="display:none;">
  <div class="modal" style="max-width:520px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title" id="modalStepupTitle">Add Step-Up Plan</h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="closeStepupModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="stepupEditId">
      <input type="hidden" id="stepupCsrf" value="<?= generate_csrf() ?>">

      <div class="form-group">
        <label class="form-label">SIP / Fund *</label>
        <select id="stepupSipId" class="form-control">
          <option value="">— Select SIP —</option>
        </select>
        <div id="stepupSipInfo" style="font-size:12px;color:var(--text-muted);margin-top:4px;min-height:16px;"></div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Step-Up Type *</label>
          <select id="stepupType" class="form-control">
            <option value="percentage">Percentage (%)</option>
            <option value="fixed_amount">Fixed Amount (₹)</option>
          </select>
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Step-Up Value *</label>
          <input type="number" id="stepupValue" class="form-control" placeholder="e.g. 10 for 10%" step="0.01" min="0.01">
        </div>
      </div>

      <div id="stepupPreviewBox" style="display:none;background:var(--bg-secondary);border-radius:8px;padding:12px;font-size:13px;margin-bottom:12px;">
        Current: <strong id="prevCurrent">—</strong> → After step-up: <strong id="prevNew" style="color:var(--success);">—</strong>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Frequency *</label>
          <select id="stepupFreq" class="form-control" onchange="toggleCustomInterval()">
            <option value="yearly">Yearly</option>
            <option value="half_yearly">Half-Yearly</option>
            <option value="custom">Custom</option>
          </select>
        </div>
        <div class="form-group" style="flex:1;" id="stepupMonthGroup">
          <label class="form-label">Apply Month</label>
          <select id="stepupMonth" class="form-control">
            <option value="1">January</option><option value="2">February</option>
            <option value="3">March</option><option value="4" selected>April (FY Start)</option>
            <option value="5">May</option><option value="6">June</option>
            <option value="7">July</option><option value="8">August</option>
            <option value="9">September</option><option value="10">October</option>
            <option value="11">November</option><option value="12">December</option>
          </select>
        </div>
        <div class="form-group" style="flex:1;display:none;" id="customIntervalGroup">
          <label class="form-label">Interval (months)</label>
          <input type="number" id="customInterval" class="form-control" placeholder="e.g. 6" min="1" max="60">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Max SIP Cap (₹)</label>
          <input type="number" id="stepupMaxAmount" class="form-control" placeholder="No cap" step="100" min="0">
        </div>
        <div class="form-group" style="flex:1;display:flex;align-items:center;gap:8px;padding-top:24px;">
          <input type="checkbox" id="stepupIsActive" checked style="width:16px;height:16px;">
          <label for="stepupIsActive" class="form-label" style="margin:0;">Active</label>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" id="stepupNotes" class="form-control" placeholder="Optional — e.g. Annual salary hike cycle">
      </div>

      <div id="stepupError" style="display:none;color:var(--danger);font-size:13px;padding:8px 12px;background:rgba(239,68,68,.1);border-radius:6px;margin-top:8px;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeStepupModal()">Cancel</button>
      <button class="btn btn-primary" id="btnSaveStepup">Save Plan</button>
    </div>
  </div>
</div>

<!-- ═══ SALARY HIKE MODAL ═══ -->
<div class="modal-overlay" id="modalSalaryHike" style="display:none;">
  <div class="modal" style="max-width:440px;width:95%;text-align:center;">
    <div class="modal-body" style="padding:32px 24px;">
      <div style="font-size:48px;margin-bottom:12px;">🎉</div>
      <h3 style="margin:0 0 8px;">Got a Salary Hike?</h3>
      <p style="color:var(--text-muted);font-size:14px;margin-bottom:24px;">
        Wealth experts recommend stepping up your SIP by at least 10% every year.<br>
        Apply your pending step-ups now!
      </p>
      <div id="hikeStepupList" style="text-align:left;margin-bottom:20px;max-height:220px;overflow-y:auto;"></div>
      <div style="display:flex;gap:8px;justify-content:center;">
        <button class="btn btn-ghost" onclick="document.getElementById('modalSalaryHike').style.display='none'">Maybe Later</button>
        <button class="btn btn-primary" id="btnApplyAllStepups">Apply All Step-Ups</button>
      </div>
    </div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = '
<script src="' . APP_URL . '/public/js/sip_stepup.js?v=' . time() . '"></script>';

require_once APP_ROOT . '/templates/layout.php';
