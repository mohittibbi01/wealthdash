<?php
/**
 * WealthDash — Goal-Based Buckets (t139)
 * Retirement · Education · Emergency · Custom goal buckets
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Goal Buckets';
$activePage  = 'goal_buckets';

ob_start();
?>
<style>
/* ── Goal Buckets — t139 ─────────────────────────────────── */
.gb-header-strip {
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:14px;margin-bottom:22px;
}
.gb-quick-add {
  display:flex;gap:8px;flex-wrap:wrap;
}
.gb-quick-btn {
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:20px;
  font-size:12px;font-weight:700;cursor:pointer;border:2px solid;
  transition:all .15s;background:transparent;
}
.gb-quick-btn:hover { transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.12); }

/* Summary strip */
.gb-summary {
  display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;
}
@media(max-width:800px){ .gb-summary { grid-template-columns:repeat(2,1fr); } }
@media(max-width:480px){ .gb-summary { grid-template-columns:1fr 1fr; } }
.gb-stat {
  background:var(--card-bg);border:1px solid var(--border);
  border-radius:12px;padding:16px 18px;
}
.gb-stat-label { font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-secondary);margin-bottom:4px; }
.gb-stat-value { font-size:22px;font-weight:800;line-height:1; }

/* Filter tabs */
.gb-tabs {
  display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;
}
.gb-tab {
  padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;
  cursor:pointer;border:1.5px solid var(--border);background:transparent;
  color:var(--text-secondary);transition:all .15s;
}
.gb-tab.active, .gb-tab:hover {
  background:var(--accent);color:#fff;border-color:var(--accent);
}

/* Bucket cards grid */
.gb-grid {
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(300px,1fr));
  gap:16px;
  margin-bottom:28px;
}

/* Individual bucket card */
.gb-card {
  background:var(--card-bg);border:2px solid var(--border);
  border-radius:16px;overflow:hidden;
  transition:box-shadow .2s,transform .15s;
  position:relative;
}
.gb-card:hover { box-shadow:0 8px 28px rgba(0,0,0,.1);transform:translateY(-2px); }
.gb-card.achieved { opacity:.7; }
.gb-card.achieved::after {
  content:'✅ ACHIEVED';position:absolute;top:12px;right:12px;
  background:#16a34a;color:#fff;font-size:9px;font-weight:800;
  padding:3px 8px;border-radius:12px;letter-spacing:.5px;
}

.gb-card-top {
  padding:16px 18px 0;
  display:flex;align-items:flex-start;justify-content:space-between;gap:8px;
}
.gb-card-icon {
  width:44px;height:44px;border-radius:12px;
  display:flex;align-items:center;justify-content:center;
  font-size:22px;flex-shrink:0;
}
.gb-card-meta { flex:1;min-width:0; }
.gb-card-name {
  font-size:15px;font-weight:800;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.gb-card-type {
  font-size:11px;font-weight:600;text-transform:uppercase;
  letter-spacing:.4px;margin-top:2px;
}
.gb-card-actions {
  display:flex;gap:6px;flex-shrink:0;
}
.gb-icon-btn {
  width:28px;height:28px;border-radius:8px;border:1px solid var(--border);
  background:transparent;cursor:pointer;font-size:13px;
  display:flex;align-items:center;justify-content:center;
  color:var(--text-secondary);transition:all .15s;
}
.gb-icon-btn:hover { background:var(--surface-hover,rgba(0,0,0,.06));color:var(--text-primary); }

/* Progress ring section */
.gb-progress-section {
  padding:14px 18px 10px;
  display:flex;align-items:center;gap:14px;
}
.gb-ring-wrap { position:relative;width:60px;height:60px;flex-shrink:0; }
.gb-ring-svg { transform:rotate(-90deg); }
.gb-ring-bg  { fill:none;stroke:var(--border);stroke-width:5; }
.gb-ring-fill{ fill:none;stroke-width:5;stroke-linecap:round;transition:stroke-dashoffset .8s; }
.gb-ring-pct {
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:800;
}
.gb-progress-nums { flex:1; }
.gb-progress-nums .num-row {
  display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px;
}
.gb-progress-nums .num-row strong { font-size:13px; }

/* Projection strip */
.gb-projection {
  margin:0 18px;padding:10px 12px;border-radius:10px;
  font-size:11px;margin-bottom:12px;
}
.gb-proj-row { display:flex;justify-content:space-between;margin-bottom:3px; }
.gb-proj-row:last-child { margin-bottom:0; }

/* Action bar */
.gb-card-footer {
  display:flex;gap:0;border-top:1px solid var(--border);
}
.gb-footer-btn {
  flex:1;padding:10px 6px;text-align:center;font-size:11px;font-weight:700;
  cursor:pointer;border:none;background:transparent;color:var(--text-secondary);
  transition:background .15s,color .15s;border-right:1px solid var(--border);
}
.gb-footer-btn:last-child { border-right:none; }
.gb-footer-btn:hover { background:rgba(99,102,241,.06);color:var(--accent); }

/* Empty state */
.gb-empty {
  grid-column:1/-1;text-align:center;
  padding:60px 20px;color:var(--text-secondary);
}
.gb-empty-icon { font-size:56px;margin-bottom:14px; }

/* ── Modals ──────────────────────────────────────────────── */
.gb-modal-overlay {
  display:none;position:fixed;inset:0;z-index:9000;
  background:rgba(0,0,0,.5);backdrop-filter:blur(3px);
  align-items:center;justify-content:center;padding:16px;
}
.gb-modal-overlay.open { display:flex; }
.gb-modal {
  background:var(--card-bg);border-radius:18px;
  width:100%;max-width:480px;max-height:90vh;overflow-y:auto;
  box-shadow:0 24px 60px rgba(0,0,0,.25);
  animation:gbModalIn .2s ease;
}
@keyframes gbModalIn {
  from { transform:scale(.95);opacity:0; }
  to   { transform:scale(1);opacity:1; }
}
.gb-modal-hdr {
  padding:20px 22px 16px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
}
.gb-modal-hdr h3 { margin:0;font-size:16px;font-weight:800; }
.gb-modal-close {
  width:30px;height:30px;border-radius:8px;border:1px solid var(--border);
  background:transparent;cursor:pointer;font-size:16px;display:flex;
  align-items:center;justify-content:center;color:var(--text-secondary);
}
.gb-modal-close:hover { background:rgba(0,0,0,.06); }
.gb-modal-body { padding:20px 22px; }
.gb-modal-footer {
  padding:14px 22px;border-top:1px solid var(--border);
  display:flex;gap:10px;justify-content:flex-end;
}

/* Form rows */
.gb-form-row { display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px; }
.gb-form-row.full { grid-template-columns:1fr; }
.gb-form-group { display:flex;flex-direction:column;gap:5px; }
.gb-form-label { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--text-secondary); }
.gb-form-input, .gb-form-select, .gb-form-textarea {
  padding:9px 12px;border:1.5px solid var(--border);border-radius:10px;
  background:var(--input-bg,var(--card-bg));color:var(--text-primary);
  font-size:13px;transition:border .15s;width:100%;box-sizing:border-box;
  font-family:inherit;
}
.gb-form-input:focus, .gb-form-select:focus, .gb-form-textarea:focus {
  outline:none;border-color:var(--accent);
  box-shadow:0 0 0 3px rgba(99,102,241,.12);
}
.gb-form-textarea { resize:vertical;min-height:70px; }
.gb-err { color:#dc2626;font-size:12px;margin-top:8px;display:none; }

/* Type selector pills */
.gb-type-grid {
  display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:4px;
}
.gb-type-pill {
  padding:8px 6px;border-radius:10px;border:2px solid var(--border);
  text-align:center;cursor:pointer;transition:all .15s;font-size:11px;font-weight:600;
}
.gb-type-pill:hover { border-color:var(--accent); }
.gb-type-pill.selected { background:var(--accent);color:#fff;border-color:var(--accent); }
.gb-type-pill .tp-emoji { font-size:18px;display:block;margin-bottom:3px; }

/* Risk profile pills */
.gb-risk-row { display:flex;gap:8px; }
.gb-risk-pill {
  flex:1;padding:8px;border-radius:10px;border:2px solid var(--border);
  text-align:center;cursor:pointer;font-size:11px;font-weight:700;transition:all .15s;
}

/* Priority pills */
.gb-priority-row { display:flex;gap:8px; }
.gb-prio-pill {
  flex:1;padding:8px;border-radius:10px;border:2px solid var(--border);
  text-align:center;cursor:pointer;font-size:12px;font-weight:700;transition:all .15s;
}

/* Contribution history mini-list */
.gb-contrib-list { max-height:180px;overflow-y:auto;margin-top:12px; }
.gb-contrib-row {
  display:flex;align-items:center;justify-content:space-between;
  padding:8px 0;border-bottom:1px solid var(--border);font-size:12px;
}
.gb-contrib-row:last-child { border-bottom:none; }

/* Linked assets */
.gb-link-row {
  display:flex;align-items:center;gap:10px;padding:8px 10px;
  border-radius:8px;background:rgba(0,0,0,.03);margin-bottom:6px;font-size:12px;
}
.gb-link-badge {
  padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;
  background:rgba(99,102,241,.1);color:#6366f1;flex-shrink:0;
}
</style>

<!-- ── Page Header ──────────────────────────────────────────── -->
<div class="gb-header-strip">
  <div>
    <h1 class="page-title">🪣 Goal Buckets</h1>
    <p class="page-subtitle">Retirement · Education · Emergency · Custom life goals</p>
  </div>
  <div class="gb-quick-add">
    <button class="gb-quick-btn" style="color:#6366f1;border-color:#6366f1;"
      onclick="gbOpenAdd('retirement')">🏖️ Retirement</button>
    <button class="gb-quick-btn" style="color:#0ea5e9;border-color:#0ea5e9;"
      onclick="gbOpenAdd('education')">🎓 Education</button>
    <button class="gb-quick-btn" style="color:#ef4444;border-color:#ef4444;"
      onclick="gbOpenAdd('emergency')">🚨 Emergency</button>
    <button class="gb-quick-btn" style="color:var(--accent);border-color:var(--accent);"
      onclick="gbOpenAdd('custom')">＋ Custom</button>
  </div>
</div>

<!-- ── Summary Strip ────────────────────────────────────────── -->
<div class="gb-summary" id="gbSummary">
  <div class="gb-stat">
    <div class="gb-stat-label">Total Buckets</div>
    <div class="gb-stat-value" id="gbStatCount">—</div>
  </div>
  <div class="gb-stat">
    <div class="gb-stat-label">Total Target</div>
    <div class="gb-stat-value" id="gbStatTarget">—</div>
  </div>
  <div class="gb-stat">
    <div class="gb-stat-label">Total Saved</div>
    <div class="gb-stat-value" id="gbStatSaved">—</div>
  </div>
  <div class="gb-stat">
    <div class="gb-stat-label">Overall Progress</div>
    <div class="gb-stat-value" id="gbStatPct" style="color:var(--accent);">—</div>
  </div>
</div>

<!-- ── Filter Tabs ───────────────────────────────────────────── -->
<div class="gb-tabs" id="gbTabs">
  <button class="gb-tab active" data-filter="all">All</button>
  <button class="gb-tab" data-filter="retirement">🏖️ Retirement</button>
  <button class="gb-tab" data-filter="education">🎓 Education</button>
  <button class="gb-tab" data-filter="emergency">🚨 Emergency</button>
  <button class="gb-tab" data-filter="house">🏠 House</button>
  <button class="gb-tab" data-filter="vehicle">🚗 Vehicle</button>
  <button class="gb-tab" data-filter="travel">✈️ Travel</button>
  <button class="gb-tab" data-filter="wedding">💍 Wedding</button>
  <button class="gb-tab" data-filter="custom">🎯 Custom</button>
  <button class="gb-tab" data-filter="achieved">✅ Achieved</button>
</div>

<!-- ── Bucket Cards Grid ─────────────────────────────────────── -->
<div class="gb-grid" id="gbGrid">
  <div style="grid-column:1/-1;text-align:center;padding:50px 20px;color:var(--text-secondary);">
    <span style="font-size:28px;">⏳</span><br>Loading buckets…
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- ADD / EDIT BUCKET MODAL -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="gb-modal-overlay" id="gbModalAdd">
  <div class="gb-modal">
    <div class="gb-modal-hdr">
      <h3 id="gbModalAddTitle">Create Goal Bucket</h3>
      <button class="gb-modal-close" onclick="gbCloseModal('gbModalAdd')">✕</button>
    </div>
    <div class="gb-modal-body">
      <input type="hidden" id="gbEditId" value="">

      <!-- Bucket type selector -->
      <div class="gb-form-group" style="margin-bottom:14px;">
        <div class="gb-form-label">Bucket Type</div>
        <div class="gb-type-grid" id="gbTypeGrid">
          <div class="gb-type-pill" data-type="retirement"><span class="tp-emoji">🏖️</span>Retirement</div>
          <div class="gb-type-pill" data-type="education"><span class="tp-emoji">🎓</span>Education</div>
          <div class="gb-type-pill" data-type="emergency"><span class="tp-emoji">🚨</span>Emergency</div>
          <div class="gb-type-pill" data-type="house"><span class="tp-emoji">🏠</span>House</div>
          <div class="gb-type-pill" data-type="vehicle"><span class="tp-emoji">🚗</span>Vehicle</div>
          <div class="gb-type-pill" data-type="travel"><span class="tp-emoji">✈️</span>Travel</div>
          <div class="gb-type-pill" data-type="wedding"><span class="tp-emoji">💍</span>Wedding</div>
          <div class="gb-type-pill selected" data-type="custom"><span class="tp-emoji">🎯</span>Custom</div>
        </div>
      </div>

      <!-- Name + Emoji -->
      <div class="gb-form-row">
        <div class="gb-form-group" style="max-width:80px;">
          <label class="gb-form-label">Emoji</label>
          <input class="gb-form-input" id="gbEmoji" value="🎯" maxlength="2"
            style="text-align:center;font-size:20px;padding:7px;">
        </div>
        <div class="gb-form-group" style="flex:1;">
          <label class="gb-form-label">Bucket Name *</label>
          <input class="gb-form-input" id="gbName" placeholder="e.g. Daughter's Education">
        </div>
      </div>

      <!-- Target Amount + Monthly Target -->
      <div class="gb-form-row">
        <div class="gb-form-group">
          <label class="gb-form-label">Target Amount (₹)</label>
          <input class="gb-form-input" id="gbTarget" type="number" min="0" placeholder="e.g. 5000000">
        </div>
        <div class="gb-form-group">
          <label class="gb-form-label">Monthly Contribution (₹)</label>
          <input class="gb-form-input" id="gbMonthly" type="number" min="0" placeholder="e.g. 10000">
        </div>
      </div>

      <!-- Target Date + Current Amount -->
      <div class="gb-form-row">
        <div class="gb-form-group">
          <label class="gb-form-label">Target Date</label>
          <input class="gb-form-input" id="gbDate" type="date">
        </div>
        <div class="gb-form-group">
          <label class="gb-form-label">Current Amount (₹)</label>
          <input class="gb-form-input" id="gbCurrent" type="number" min="0" placeholder="e.g. 100000">
        </div>
      </div>

      <!-- Risk Profile -->
      <div class="gb-form-group" style="margin-bottom:14px;">
        <div class="gb-form-label">Risk Profile</div>
        <div class="gb-risk-row" id="gbRiskRow">
          <div class="gb-risk-pill selected" data-risk="conservative"
            style="color:#0ea5e9;" onclick="gbSelectRisk('conservative')">🛡️ Conservative</div>
          <div class="gb-risk-pill" data-risk="moderate"
            style="color:#8b5cf6;" onclick="gbSelectRisk('moderate')">⚖️ Moderate</div>
          <div class="gb-risk-pill" data-risk="aggressive"
            style="color:#16a34a;" onclick="gbSelectRisk('aggressive')">🚀 Aggressive</div>
        </div>
      </div>

      <!-- Priority -->
      <div class="gb-form-group" style="margin-bottom:14px;">
        <div class="gb-form-label">Priority</div>
        <div class="gb-priority-row" id="gbPrioRow">
          <div class="gb-prio-pill" data-prio="high"
            style="color:#ef4444;" onclick="gbSelectPrio('high')">🔴 High</div>
          <div class="gb-prio-pill selected" data-prio="medium"
            style="color:#f59e0b;" onclick="gbSelectPrio('medium')">🟡 Medium</div>
          <div class="gb-prio-pill" data-prio="low"
            style="color:#10b981;" onclick="gbSelectPrio('low')">🟢 Low</div>
        </div>
      </div>

      <!-- Color -->
      <div class="gb-form-group" style="margin-bottom:14px;">
        <div class="gb-form-label">Color</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <?php foreach(['#6366f1','#0ea5e9','#ef4444','#f59e0b','#10b981','#8b5cf6','#ec4899','#14b8a6','#64748b'] as $c): ?>
          <div onclick="gbSelectColor('<?= $c ?>')" data-clr="<?= $c ?>"
            class="gb-clr-dot"
            style="width:26px;height:26px;border-radius:50%;background:<?= $c ?>;cursor:pointer;border:2px solid transparent;transition:border .1s;"></div>
          <?php endforeach; ?>
          <input type="hidden" id="gbColor" value="#6366f1">
        </div>
      </div>

      <!-- Notes -->
      <div class="gb-form-group gb-form-row full" style="margin-bottom:0;">
        <label class="gb-form-label">Notes (optional)</label>
        <textarea class="gb-form-textarea" id="gbNotes" placeholder="Any notes about this goal…"></textarea>
      </div>

      <div class="gb-err" id="gbAddErr"></div>
    </div>
    <div class="gb-modal-footer">
      <button class="btn btn-ghost btn-sm" onclick="gbCloseModal('gbModalAdd')">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="gbSaveBucket()" id="gbSaveBtn">Create Bucket</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- CONTRIBUTE MODAL -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="gb-modal-overlay" id="gbModalContrib">
  <div class="gb-modal" style="max-width:380px;">
    <div class="gb-modal-hdr">
      <h3>💰 Log Contribution</h3>
      <button class="gb-modal-close" onclick="gbCloseModal('gbModalContrib')">✕</button>
    </div>
    <div class="gb-modal-body">
      <input type="hidden" id="gbContribId">
      <div class="gb-form-group" style="margin-bottom:12px;">
        <label class="gb-form-label">Bucket</label>
        <div id="gbContribBucketName" style="font-weight:700;font-size:14px;"></div>
      </div>
      <div class="gb-form-row">
        <div class="gb-form-group">
          <label class="gb-form-label">Amount (₹) *</label>
          <input class="gb-form-input" id="gbContribAmount" type="number" min="1" placeholder="e.g. 5000">
        </div>
        <div class="gb-form-group">
          <label class="gb-form-label">Date</label>
          <input class="gb-form-input" id="gbContribDate" type="date">
        </div>
      </div>
      <div class="gb-form-group" style="margin-bottom:0;">
        <label class="gb-form-label">Note (optional)</label>
        <input class="gb-form-input" id="gbContribNote" placeholder="e.g. Monthly SIP">
      </div>
      <!-- Contribution history -->
      <div style="margin-top:14px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--text-secondary);margin-bottom:6px;">
          Recent Contributions
        </div>
        <div class="gb-contrib-list" id="gbContribHistory">
          <div style="color:var(--text-secondary);font-size:12px;text-align:center;padding:14px;">Loading…</div>
        </div>
      </div>
      <div class="gb-err" id="gbContribErr"></div>
    </div>
    <div class="gb-modal-footer">
      <button class="btn btn-ghost btn-sm" onclick="gbCloseModal('gbModalContrib')">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="gbSaveContrib()">Save Contribution</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- PROJECTION MODAL -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="gb-modal-overlay" id="gbModalProj">
  <div class="gb-modal" style="max-width:420px;">
    <div class="gb-modal-hdr">
      <h3>📈 Goal Projection</h3>
      <button class="gb-modal-close" onclick="gbCloseModal('gbModalProj')">✕</button>
    </div>
    <div class="gb-modal-body" id="gbProjBody">
      Loading…
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- DELETE CONFIRM -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="gb-modal-overlay" id="gbModalDel">
  <div class="gb-modal" style="max-width:360px;">
    <div class="gb-modal-hdr">
      <h3>🗑️ Delete Bucket?</h3>
      <button class="gb-modal-close" onclick="gbCloseModal('gbModalDel')">✕</button>
    </div>
    <div class="gb-modal-body">
      <p style="font-size:14px;color:var(--text-secondary);margin:0;">
        This will permanently delete the bucket <strong id="gbDelName"></strong>
        and all its contributions. This cannot be undone.
      </p>
      <input type="hidden" id="gbDelId">
    </div>
    <div class="gb-modal-footer">
      <button class="btn btn-ghost btn-sm" onclick="gbCloseModal('gbModalDel')">Cancel</button>
      <button class="btn btn-sm" style="background:#dc2626;color:#fff;" onclick="gbConfirmDelete()">Delete</button>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  const API = '<?= APP_URL ?>/api/index.php';
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

  // ── State ────────────────────────────────────────────────
  let _buckets     = [];
  let _activeFilter = 'all';
  let _selType     = 'custom';
  let _selRisk     = 'conservative';
  let _selPrio     = 'medium';
  let _selColor    = '#6366f1';

  const TYPE_META = {
    retirement: { emoji:'🏖️', color:'#6366f1', risk:'aggressive'  },
    education:  { emoji:'🎓', color:'#0ea5e9', risk:'moderate'    },
    emergency:  { emoji:'🚨', color:'#ef4444', risk:'conservative' },
    house:      { emoji:'🏠', color:'#f59e0b', risk:'moderate'    },
    vehicle:    { emoji:'🚗', color:'#10b981', risk:'moderate'    },
    travel:     { emoji:'✈️', color:'#8b5cf6', risk:'moderate'    },
    wedding:    { emoji:'💍', color:'#ec4899', risk:'moderate'    },
    custom:     { emoji:'🎯', color:'#64748b', risk:'moderate'    },
  };

  // ── API helpers ──────────────────────────────────────────
  async function api(params) {
    const fd = new URLSearchParams({ ...params, csrf_token: CSRF });
    const r = await fetch(API, { method: 'POST', body: fd });
    return r.json();
  }
  async function apiGet(action, extra = {}) {
    const q = new URLSearchParams({ action, ...extra });
    const r = await fetch(`${API}?${q}`);
    return r.json();
  }

  // ── Load & Render ─────────────────────────────────────────
  async function gbLoad() {
    try {
      const [listD, sumD] = await Promise.all([
        apiGet('bucket_list'),
        apiGet('bucket_summary'),
      ]);

      _buckets = listD.success ? listD.buckets : [];
      renderSummary(sumD);
      renderGrid();
    } catch (e) {
      document.getElementById('gbGrid').innerHTML =
        `<div class="gb-empty"><div class="gb-empty-icon">⚠️</div>Failed to load buckets. Please refresh.</div>`;
    }
  }

  function renderSummary(d) {
    if (!d.success) return;
    document.getElementById('gbStatCount').textContent  = _buckets.length;
    document.getElementById('gbStatTarget').textContent = '₹' + fmt(d.total_target);
    document.getElementById('gbStatSaved').textContent  = '₹' + fmt(d.total_current);
    document.getElementById('gbStatPct').textContent    = (d.overall_pct ?? 0) + '%';
  }

  function renderGrid() {
    const grid = document.getElementById('gbGrid');
    let list = _buckets;

    if (_activeFilter === 'achieved') {
      list = list.filter(b => b.is_achieved == 1);
    } else if (_activeFilter !== 'all') {
      list = list.filter(b => b.bucket_type === _activeFilter && !b.is_achieved);
    } else {
      list = list.filter(b => !b.is_achieved || true); // show all including achieved
    }

    if (!list.length) {
      grid.innerHTML = `<div class="gb-empty">
        <div class="gb-empty-icon">🪣</div>
        <div style="font-size:16px;font-weight:700;margin-bottom:8px;">No buckets yet</div>
        <div style="font-size:13px;margin-bottom:18px;">Use the quick-add buttons above to create your first goal bucket.</div>
      </div>`;
      return;
    }

    grid.innerHTML = list.map(b => cardHtml(b)).join('');

    // Animate ring fills after render
    requestAnimationFrame(() => {
      list.forEach(b => {
        const fill = document.getElementById('gbRingFill_' + b.id);
        if (fill) {
          const c = 2 * Math.PI * 26;
          const offset = c - (b.progress_pct / 100) * c;
          fill.style.strokeDashoffset = offset;
        }
      });
    });
  }

  function cardHtml(b) {
    const meta     = TYPE_META[b.bucket_type] || TYPE_META.custom;
    const color    = b.color || meta.color;
    const emoji    = b.emoji || meta.emoji;
    const pct      = parseFloat(b.progress_pct) || 0;
    const current  = parseFloat(b.current_amount) || 0;
    const target   = parseFloat(b.target_amount) || 0;
    const monthly  = parseFloat(b.monthly_target) || 0;
    const achieved = b.is_achieved == 1;
    const c        = 2 * Math.PI * 26; // circumference

    // Return rate by risk
    const rateMap  = { conservative: 6.5, moderate: 10, aggressive: 13 };
    const retPct   = rateMap[b.risk_profile] || 10;

    return `
    <div class="gb-card${achieved ? ' achieved' : ''}" data-id="${b.id}" data-type="${b.bucket_type}">
      <!-- Top -->
      <div class="gb-card-top">
        <div class="gb-card-icon" style="background:${color}18;">${emoji}</div>
        <div class="gb-card-meta">
          <div class="gb-card-name" title="${esc(b.name)}">${esc(b.name)}</div>
          <div class="gb-card-type" style="color:${color};">${b.type_label || b.bucket_type}</div>
          ${b.target_date ? `<div style="font-size:10px;color:var(--text-secondary);margin-top:2px;">📅 ${b.target_date}</div>` : ''}
        </div>
        <div class="gb-card-actions">
          <button class="gb-icon-btn" title="Contribute" onclick="gbOpenContrib(${b.id})">💰</button>
          <button class="gb-icon-btn" title="Edit" onclick="gbOpenEdit(${b.id})">✏️</button>
          <button class="gb-icon-btn" title="Delete" onclick="gbOpenDelete(${b.id}, '${esc(b.name)}')">🗑️</button>
        </div>
      </div>

      <!-- Progress ring -->
      <div class="gb-progress-section">
        <div class="gb-ring-wrap">
          <svg class="gb-ring-svg" width="60" height="60" viewBox="0 0 60 60">
            <circle class="gb-ring-bg" cx="30" cy="30" r="26"/>
            <circle class="gb-ring-fill" id="gbRingFill_${b.id}"
              cx="30" cy="30" r="26"
              stroke="${color}"
              stroke-dasharray="${c}"
              stroke-dashoffset="${c}"
              style="transition:stroke-dashoffset .8s ease;"/>
          </svg>
          <div class="gb-ring-pct" style="color:${color};">${pct}%</div>
        </div>
        <div class="gb-progress-nums">
          <div class="num-row">
            <span style="color:var(--text-secondary);">Saved</span>
            <strong style="color:${color};">₹${fmt(current)}</strong>
          </div>
          <div class="num-row">
            <span style="color:var(--text-secondary);">Target</span>
            <strong>₹${fmt(target)}</strong>
          </div>
          ${monthly > 0 ? `<div class="num-row">
            <span style="color:var(--text-secondary);">Monthly</span>
            <strong>₹${fmt(monthly)}</strong>
          </div>` : ''}
        </div>
      </div>

      <!-- Projection mini strip -->
      ${target > 0 && b.target_date ? (() => {
        const months = Math.max(1, Math.ceil((new Date(b.target_date) - Date.now()) / (30.44 * 86400000)));
        const r = retPct / 100 / 12;
        const fvC = r > 0 ? current * Math.pow(1+r, months) : current;
        const fvS = r > 0 ? monthly * ((Math.pow(1+r, months)-1)/r) * (1+r) : monthly * months;
        const proj = fvC + fvS;
        const onTrack = proj >= target;
        return `<div class="gb-projection" style="background:${onTrack ? 'rgba(22,163,74,.07)' : 'rgba(220,38,38,.07)'};border:1px solid ${onTrack ? 'rgba(22,163,74,.2)' : 'rgba(220,38,38,.2)'};">
          <div class="gb-proj-row">
            <span style="color:var(--text-secondary);">Projected @ ${retPct}% CAGR</span>
            <strong style="color:${onTrack ? '#16a34a' : '#dc2626'};">₹${fmt(proj)}</strong>
          </div>
          <div class="gb-proj-row">
            <span style="color:var(--text-secondary);">${onTrack ? '✅ On track' : '⚠️ Shortfall'}</span>
            <span style="font-weight:700;color:${onTrack ? '#16a34a' : '#dc2626'};">
              ${onTrack ? 'Surplus ₹'+fmt(proj-target) : '₹'+fmt(target-proj)+' gap'}
            </span>
          </div>
          <div class="gb-proj-row">
            <span style="color:var(--text-secondary);">Time left</span>
            <span>${months} months</span>
          </div>
        </div>`;
      })() : ''}

      <!-- Priority + risk badges -->
      <div style="padding:0 18px 10px;display:flex;gap:6px;flex-wrap:wrap;">
        <span style="padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;
          background:${{high:'rgba(220,38,38,.1)',medium:'rgba(245,158,11,.1)',low:'rgba(22,163,74,.1)'}[b.priority]||'rgba(0,0,0,.06)'};
          color:${{high:'#dc2626',medium:'#f59e0b',low:'#16a34a'}[b.priority]||'inherit'};">
          ${({high:'🔴 High',medium:'🟡 Medium',low:'🟢 Low'})[b.priority]||b.priority}
        </span>
        <span style="padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;
          background:rgba(99,102,241,.1);color:#6366f1;">
          ${b.risk_profile}
        </span>
        ${b.linked_count > 0 ? `<span style="padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;background:rgba(0,0,0,.06);">🔗 ${b.linked_count} linked</span>` : ''}
      </div>

      <!-- Footer actions -->
      <div class="gb-card-footer">
        <button class="gb-footer-btn" onclick="gbOpenContrib(${b.id})">+ Contribute</button>
        <button class="gb-footer-btn" onclick="gbOpenProjection(${b.id})">📈 Projection</button>
        <button class="gb-footer-btn" onclick="gbToggleAchieved(${b.id}, ${b.is_achieved})"
          style="${achieved ? 'color:#f59e0b;' : ''}">
          ${achieved ? '↩ Reopen' : '✅ Achieved'}
        </button>
      </div>
    </div>`;
  }

  // ── Add / Edit Bucket ─────────────────────────────────────
  window.gbOpenAdd = function (type = 'custom') {
    document.getElementById('gbEditId').value   = '';
    document.getElementById('gbModalAddTitle').textContent = 'Create Goal Bucket';
    document.getElementById('gbSaveBtn').textContent = 'Create Bucket';
    document.getElementById('gbAddErr').style.display = 'none';

    // Reset form
    document.getElementById('gbName').value    = '';
    document.getElementById('gbNotes').value   = '';
    document.getElementById('gbTarget').value  = '';
    document.getElementById('gbMonthly').value = '';
    document.getElementById('gbCurrent').value = '';
    document.getElementById('gbDate').value    = '';

    // Set type
    gbSelectType(type);
    const meta = TYPE_META[type] || TYPE_META.custom;
    document.getElementById('gbEmoji').value = meta.emoji;
    gbSelectColor(meta.color);
    gbSelectRisk(meta.risk);
    gbSelectPrio('medium');

    gbOpenModal('gbModalAdd');
    setTimeout(() => document.getElementById('gbName').focus(), 200);
  };

  window.gbOpenEdit = function (id) {
    const b = _buckets.find(x => x.id == id);
    if (!b) return;

    document.getElementById('gbEditId').value   = b.id;
    document.getElementById('gbModalAddTitle').textContent = 'Edit Bucket';
    document.getElementById('gbSaveBtn').textContent = 'Save Changes';
    document.getElementById('gbAddErr').style.display = 'none';

    document.getElementById('gbName').value    = b.name;
    document.getElementById('gbNotes').value   = b.notes || '';
    document.getElementById('gbTarget').value  = b.target_amount || '';
    document.getElementById('gbMonthly').value = b.monthly_target || '';
    document.getElementById('gbCurrent').value = b.current_amount || '';
    document.getElementById('gbDate').value    = b.target_date || '';
    document.getElementById('gbEmoji').value   = b.emoji || '🎯';

    gbSelectType(b.bucket_type || 'custom');
    gbSelectColor(b.color || '#6366f1');
    gbSelectRisk(b.risk_profile || 'moderate');
    gbSelectPrio(b.priority || 'medium');

    gbOpenModal('gbModalAdd');
  };

  window.gbSaveBucket = async function () {
    const editId = document.getElementById('gbEditId').value;
    const name   = document.getElementById('gbName').value.trim();
    if (!name) { showErr('gbAddErr', 'Bucket name is required.'); return; }

    const btn = document.getElementById('gbSaveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const params = {
      action:         editId ? 'bucket_edit' : 'bucket_add',
      bucket_id:      editId || undefined,
      name,
      bucket_type:    _selType,
      emoji:          document.getElementById('gbEmoji').value.trim() || '🎯',
      color:          _selColor,
      target_amount:  document.getElementById('gbTarget').value || 0,
      monthly_target: document.getElementById('gbMonthly').value || 0,
      current_amount: document.getElementById('gbCurrent').value || 0,
      target_date:    document.getElementById('gbDate').value || '',
      risk_profile:   _selRisk,
      priority:       _selPrio,
      notes:          document.getElementById('gbNotes').value.trim(),
    };
    // Remove undefined
    Object.keys(params).forEach(k => params[k] === undefined && delete params[k]);

    try {
      const d = await api(params);
      if (d.success) {
        gbCloseModal('gbModalAdd');
        await gbLoad();
      } else {
        showErr('gbAddErr', d.message || 'Save failed.');
      }
    } catch (e) {
      showErr('gbAddErr', 'Network error. Please try again.');
    }

    btn.disabled = false;
    btn.textContent = editId ? 'Save Changes' : 'Create Bucket';
  };

  // ── Contribute ────────────────────────────────────────────
  window.gbOpenContrib = async function (id) {
    const b = _buckets.find(x => x.id == id);
    if (!b) return;

    document.getElementById('gbContribId').value = id;
    document.getElementById('gbContribBucketName').textContent = (b.emoji || '') + ' ' + b.name;
    document.getElementById('gbContribAmount').value = '';
    document.getElementById('gbContribDate').value   = new Date().toISOString().split('T')[0];
    document.getElementById('gbContribNote').value   = '';
    document.getElementById('gbContribErr').style.display = 'none';

    // Load history
    const histEl = document.getElementById('gbContribHistory');
    histEl.innerHTML = '<div style="text-align:center;padding:12px;font-size:12px;color:var(--text-secondary);">Loading…</div>';
    gbOpenModal('gbModalContrib');

    try {
      const d = await apiGet('bucket_progress', { bucket_id: id });
      if (d.success && d.history?.length) {
        histEl.innerHTML = d.history.map(h => `
          <div class="gb-contrib-row">
            <span style="color:var(--text-secondary);">${h.contribution_date}</span>
            <span>${h.note || '—'}</span>
            <strong style="color:#16a34a;">+₹${fmt(h.amount)}</strong>
          </div>`).join('');
      } else {
        histEl.innerHTML = '<div style="text-align:center;padding:12px;font-size:12px;color:var(--text-secondary);">No contributions yet.</div>';
      }
    } catch (e) {
      histEl.innerHTML = '<div style="color:#dc2626;font-size:12px;text-align:center;">Failed to load history.</div>';
    }
  };

  window.gbSaveContrib = async function () {
    const id     = document.getElementById('gbContribId').value;
    const amount = parseFloat(document.getElementById('gbContribAmount').value);
    const date   = document.getElementById('gbContribDate').value;
    const note   = document.getElementById('gbContribNote').value.trim();

    if (!amount || amount <= 0) { showErr('gbContribErr', 'Enter a valid amount.'); return; }

    try {
      const d = await api({ action: 'bucket_contribute', bucket_id: id, amount, date, note });
      if (d.success) {
        gbCloseModal('gbModalContrib');
        await gbLoad();
      } else {
        showErr('gbContribErr', d.message || 'Failed to save.');
      }
    } catch (e) {
      showErr('gbContribErr', 'Network error.');
    }
  };

  // ── Projection ────────────────────────────────────────────
  window.gbOpenProjection = async function (id) {
    gbOpenModal('gbModalProj');
    const body = document.getElementById('gbProjBody');
    body.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-secondary);">Loading projection…</div>';

    try {
      const d = await apiGet('bucket_progress', { bucket_id: id });
      if (!d.success) { body.innerHTML = `<p style="color:#dc2626;">${d.message}</p>`; return; }

      const b    = d.bucket;
      const p    = d.projection;
      const color = b.color || '#6366f1';

      body.innerHTML = `
        <div style="text-align:center;margin-bottom:18px;">
          <span style="font-size:32px;">${b.emoji||'🎯'}</span>
          <div style="font-size:16px;font-weight:800;margin-top:6px;">${esc(b.name)}</div>
          <div style="font-size:12px;color:var(--text-secondary);">${b.bucket_type} · ${b.risk_profile}</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
          ${statBox('Current Saved', '₹'+fmt(d.current), color)}
          ${statBox('Target', '₹'+fmt(b.target_amount), '#64748b')}
          ${statBox('Monthly SIP', '₹'+fmt(b.monthly_target), '#8b5cf6')}
          ${statBox('Months Left', p.months_left, '#f59e0b')}
          ${statBox('Projected Value', '₹'+fmt(p.projected_value), p.on_track ? '#16a34a' : '#dc2626')}
          ${statBox(p.on_track ? '🎯 Surplus' : '⚠️ Shortfall',
              '₹'+fmt(p.shortfall || (p.projected_value - b.target_amount)), p.on_track ? '#16a34a' : '#dc2626')}
        </div>

        ${p.required_monthly > 0 && !p.on_track ? `
        <div style="padding:12px;border-radius:10px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);font-size:13px;">
          💡 To reach your target, increase monthly contribution to
          <strong style="color:#f59e0b;">₹${fmt(p.required_monthly + parseFloat(b.monthly_target))}</strong>
          (additional ₹${fmt(p.required_monthly)}/month)
        </div>` : ''}

        ${p.on_track ? `
        <div style="padding:12px;border-radius:10px;background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.3);font-size:13px;margin-top:10px;">
          ✅ You're <strong style="color:#16a34a;">on track</strong> for this goal at a ${d.return_pct}% CAGR!
        </div>` : ''}

        <div style="margin-top:16px;font-size:11px;color:var(--text-secondary);text-align:center;">
          Projection assumes ${d.return_pct}% annual return, compounding monthly.
        </div>`;
    } catch (e) {
      body.innerHTML = '<p style="color:#dc2626;">Failed to load projection.</p>';
    }
  };

  function statBox(label, val, color) {
    return `<div style="background:rgba(0,0,0,.04);border-radius:8px;padding:10px 12px;">
      <div style="font-size:10px;text-transform:uppercase;letter-spacing:.3px;color:var(--text-secondary);margin-bottom:3px;">${label}</div>
      <div style="font-size:14px;font-weight:800;color:${color};">${val}</div>
    </div>`;
  }

  // ── Delete ────────────────────────────────────────────────
  window.gbOpenDelete = function (id, name) {
    document.getElementById('gbDelId').value        = id;
    document.getElementById('gbDelName').textContent = name;
    gbOpenModal('gbModalDel');
  };

  window.gbConfirmDelete = async function () {
    const id = document.getElementById('gbDelId').value;
    try {
      const d = await api({ action: 'bucket_delete', bucket_id: id });
      if (d.success) {
        gbCloseModal('gbModalDel');
        await gbLoad();
      } else {
        alert(d.message || 'Delete failed.');
      }
    } catch (e) {
      alert('Network error.');
    }
  };

  // ── Toggle Achieved ───────────────────────────────────────
  window.gbToggleAchieved = async function (id, current) {
    const newVal = current == 1 ? 0 : 1;
    try {
      const d = await api({ action: 'bucket_mark_achieved', bucket_id: id, achieved: newVal });
      if (d.success) await gbLoad();
      else alert(d.message);
    } catch (e) { alert('Network error.'); }
  };

  // ── Type / Risk / Priority / Color selectors ─────────────
  function gbSelectType(type) {
    _selType = type;
    document.querySelectorAll('.gb-type-pill').forEach(p => {
      p.classList.toggle('selected', p.dataset.type === type);
    });
  }
  function gbSelectRisk(risk) {
    _selRisk = risk;
    document.querySelectorAll('.gb-risk-pill').forEach(p => {
      const s = p.dataset.risk === risk;
      p.classList.toggle('selected', s);
      p.style.background   = s ? p.style.color.replace(')', ', .1)').replace('rgb', 'rgba') : 'transparent';
      p.style.borderColor  = s ? p.style.color : 'var(--border)';
    });
  }
  function gbSelectPrio(prio) {
    _selPrio = prio;
    document.querySelectorAll('.gb-prio-pill').forEach(p => {
      const s = p.dataset.prio === prio;
      p.classList.toggle('selected', s);
      p.style.borderColor = s ? p.style.color : 'var(--border)';
    });
  }
  function gbSelectColor(clr) {
    _selColor = clr;
    document.getElementById('gbColor').value = clr;
    document.querySelectorAll('.gb-clr-dot').forEach(d => {
      d.style.borderColor = d.dataset.clr === clr ? d.style.background : 'transparent';
      d.style.transform   = d.dataset.clr === clr ? 'scale(1.2)' : 'scale(1)';
    });
  }
  window.gbSelectColor = gbSelectColor;

  // Wire type pills
  document.getElementById('gbTypeGrid').addEventListener('click', function (e) {
    const pill = e.target.closest('.gb-type-pill');
    if (!pill) return;
    const type = pill.dataset.type;
    gbSelectType(type);
    const meta = TYPE_META[type] || TYPE_META.custom;
    if (!document.getElementById('gbEditId').value) {
      document.getElementById('gbEmoji').value = meta.emoji;
      gbSelectColor(meta.color);
      gbSelectRisk(meta.risk);
    }
  });

  // Wire risk pills (data-risk attribute)
  document.querySelectorAll('.gb-risk-pill').forEach(p => {
    p.addEventListener('click', () => gbSelectRisk(p.dataset.risk));
  });

  // Wire filter tabs
  document.getElementById('gbTabs').addEventListener('click', function (e) {
    const tab = e.target.closest('.gb-tab');
    if (!tab) return;
    _activeFilter = tab.dataset.filter;
    document.querySelectorAll('.gb-tab').forEach(t => t.classList.toggle('active', t === tab));
    renderGrid();
  });

  // ── Modal helpers ─────────────────────────────────────────
  window.gbOpenModal = function (id) {
    document.getElementById(id).classList.add('open');
  };
  window.gbCloseModal = function (id) {
    document.getElementById(id).classList.remove('open');
  };

  // Close on overlay click
  document.querySelectorAll('.gb-modal-overlay').forEach(ov => {
    ov.addEventListener('click', function (e) {
      if (e.target === ov) ov.classList.remove('open');
    });
  });

  // ── Utilities ─────────────────────────────────────────────
  function fmt(n) {
    n = parseFloat(n) || 0;
    if (n >= 1e7) return (n / 1e7).toFixed(2) + 'Cr';
    if (n >= 1e5) return (n / 1e5).toFixed(2) + 'L';
    return n.toLocaleString('en-IN', { maximumFractionDigits: 0 });
  }
  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function showErr(id, msg) {
    const el = document.getElementById(id);
    el.textContent  = msg;
    el.style.display = 'block';
  }

  // ── Init ──────────────────────────────────────────────────
  gbLoad();
  gbSelectColor('#6366f1');
  gbSelectRisk('conservative');

})();
</script>

<?php
$content = ob_get_clean();
require APP_ROOT . '/templates/layout.php';
