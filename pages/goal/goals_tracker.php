<?php
/**
 * WealthDash — tg005: Goals vs Actual Tracking Page
 * File: pages/goal/goals_tracker.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle    = 'Goals vs Actual';
$activePage   = 'goal';
$activeSection= 'goal';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 class="page-title">🎯 Goals vs Actual Tracking</h1>
    <p class="page-subtitle">Monthly on-track check for all your financial goals.</p>
  </div>
  <button class="btn btn-primary" onclick="GT.openCheckin(null)">+ Log Investment</button>
</div>

<!-- Summary cards -->
<div id="gt-summary-cards" class="dashboard-grid" style="margin-bottom:24px;"></div>

<!-- Goals list -->
<div id="gt-goals-wrap"></div>

<!-- Checkin Modal -->
<div id="gt-checkin-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)GT.closeCheckin()">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header">
      <span class="modal-title">📝 Log Monthly Investment</span>
      <button class="modal-close" onclick="GT.closeCheckin()">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Goal</label>
        <select id="gt-checkin-goal" class="form-control"></select>
      </div>
      <div class="form-group">
        <label class="form-label">Amount Invested (₹)</label>
        <input type="number" id="gt-checkin-amount" class="form-control" placeholder="e.g. 10000" min="1" step="100">
      </div>
      <div class="form-group">
        <label class="form-label">Date</label>
        <input type="date" id="gt-checkin-date" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Notes (optional)</label>
        <input type="text" id="gt-checkin-notes" class="form-control" placeholder="e.g. SIP + lumpsum">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="GT.closeCheckin()">Cancel</button>
      <button class="btn btn-primary" onclick="GT.saveCheckin()">Save</button>
    </div>
  </div>
</div>

<!-- History Modal -->
<div id="gt-history-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)GT.closeHistory()">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">
      <span class="modal-title" id="gt-history-title">Checkin History</span>
      <button class="modal-close" onclick="GT.closeHistory()">×</button>
    </div>
    <div class="modal-body" id="gt-history-body"></div>
  </div>
</div>

<style>
.goal-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:16px;}
.goal-card-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px;gap:12px;}
.goal-name{font-size:15px;font-weight:700;}
.goal-meta{font-size:12px;color:var(--text-muted);margin-top:2px;}
.goal-status-badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;}
.st-on_track{background:#dcfce7;color:#16a34a;}
.st-completed{background:#dbeafe;color:#1d4ed8;}
.st-behind{background:#fee2e2;color:#dc2626;}
.st-slightly_behind{background:#fef9c3;color:#a16207;}
.progress-row{margin-bottom:8px;}
.progress-labels{display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:4px;}
.progress-bar-wrap{height:8px;background:var(--bg-secondary);border-radius:4px;overflow:hidden;}
.progress-bar-fill{height:100%;border-radius:4px;transition:width .4s;}
.goal-stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px;}
.goal-stat{background:var(--bg-secondary);border-radius:8px;padding:8px 12px;text-align:center;}
.goal-stat-label{font-size:11px;color:var(--text-muted);}
.goal-stat-val{font-size:13px;font-weight:700;margin-top:2px;}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const GT = {
  _goals: [],

  init() { this.loadData(); },

  loadData() {
    apiPost({ action: 'goals_vs_actual' }).then(r => {
      if (!r.ok) return;
      this._goals = r.data.goals || [];
      this._renderSummary(r.data.summary);
      this._renderGoals();
    });
  },

  _renderSummary(s) {
    const pct = s.total_target > 0 ? Math.round((s.total_invested/s.total_target)*100) : 0;
    document.getElementById('gt-summary-cards').innerHTML = `
      <div class="stat-card">
        <div class="stat-label">Total Goals</div>
        <div class="stat-value wd-num-xl">${s.total}</div>
        <div class="stat-sub">${s.on_track} on track · ${s.behind} behind · ${s.completed} done</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total Target</div>
        <div class="stat-value wd-num-xl">${formatINR(s.total_target)}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total Invested</div>
        <div class="stat-value wd-num-xl wd-gain">${formatINR(s.total_invested)}</div>
        <div class="stat-sub">${pct}% of total target</div>
      </div>`;
  },

  _statusLabel(s) {
    return { on_track:'✅ On Track', completed:'🏆 Completed', behind:'🔴 Behind', slightly_behind:'🟡 Slightly Behind' }[s] || s;
  },

  _renderGoals() {
    const wrap = document.getElementById('gt-goals-wrap');
    if (!this._goals.length) {
      wrap.innerHTML = '<div class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">🎯</div><div>No active goals found. Add goals from the Goals section.</div></div></div></div>';
      return;
    }
    let html = '';
    for (const g of this._goals) {
      const stClass  = 'st-' + g.status;
      const amtColor = g.variance >= 0 ? 'wd-gain' : 'wd-loss';
      const barColor = g.status === 'on_track' || g.status === 'completed' ? '#16a34a'
                     : g.status === 'slightly_behind' ? '#eab308' : '#dc2626';

      html += `<div class="goal-card">
        <div class="goal-card-header">
          <div>
            <div class="goal-name">${esc(g.goal_name)}</div>
            <div class="goal-meta">${esc(g.goal_type)} · Target: ${esc(g.target_date)} · ${g.months_left}m left</div>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
            <span class="goal-status-badge ${stClass}">${this._statusLabel(g.status)}</span>
            <button class="btn btn-ghost btn-sm" onclick="GT.openCheckin(${g.id})" title="Log investment">+</button>
            <button class="btn btn-ghost btn-sm" onclick="GT.showHistory(${g.id},'${esc(g.goal_name)}')" title="History">📋</button>
          </div>
        </div>

        <!-- Amount progress -->
        <div class="progress-row">
          <div class="progress-labels">
            <span>Invested: <strong>${formatINR(g.actual_invested)}</strong></span>
            <span>Target: <strong>${formatINR(g.target_amount)}</strong></span>
            <span class="${amtColor}"><strong>${g.amt_progress}%</strong></span>
          </div>
          <div class="progress-bar-wrap">
            <div class="progress-bar-fill" style="width:${g.amt_progress}%;background:${barColor};"></div>
          </div>
        </div>

        <!-- Time progress -->
        <div class="progress-row" style="margin-bottom:0;">
          <div class="progress-labels">
            <span style="color:var(--text-muted);">Time elapsed: ${g.time_progress}%</span>
            <span style="color:var(--text-muted);">Expected by now: ${formatINR(g.expected_by_now)}</span>
          </div>
          <div class="progress-bar-wrap" style="height:4px;">
            <div class="progress-bar-fill" style="width:${g.time_progress}%;background:#6b7280;"></div>
          </div>
        </div>

        <div class="goal-stats-grid">
          <div class="goal-stat">
            <div class="goal-stat-label">Variance</div>
            <div class="goal-stat-val ${amtColor}">${g.variance >= 0 ? '+' : ''}${formatINR(g.variance)}</div>
          </div>
          <div class="goal-stat">
            <div class="goal-stat-label">Monthly Needed</div>
            <div class="goal-stat-val">${formatINR(g.monthly_needed)}/mo</div>
          </div>
          <div class="goal-stat">
            <div class="goal-stat-label">SIP Gap</div>
            <div class="goal-stat-val ${g.sip_gap > 0 ? 'wd-loss' : 'wd-gain'}">${g.sip_gap > 0 ? formatINR(g.sip_gap)+' short' : 'Covered ✓'}</div>
          </div>
        </div>
        ${g.last_checkin ? `<div style="margin-top:8px;font-size:11px;color:var(--text-muted);">Last checkin: ${esc(g.last_checkin.substring(0,10))}</div>` : ''}
      </div>`;
    }
    wrap.innerHTML = html;
  },

  openCheckin(goalId) {
    // Populate goal dropdown
    const sel = document.getElementById('gt-checkin-goal');
    sel.innerHTML = this._goals.map(g =>
      `<option value="${g.id}" ${g.id === goalId ? 'selected' : ''}>${esc(g.goal_name)}</option>`
    ).join('');
    document.getElementById('gt-checkin-amount').value = '';
    document.getElementById('gt-checkin-date').value   = new Date().toISOString().substring(0,10);
    document.getElementById('gt-checkin-notes').value  = '';
    document.getElementById('gt-checkin-modal').style.display = '';
    setTimeout(() => document.getElementById('gt-checkin-amount').focus(), 100);
  },

  closeCheckin() { document.getElementById('gt-checkin-modal').style.display = 'none'; },

  saveCheckin() {
    const goalId = document.getElementById('gt-checkin-goal').value;
    const amount = document.getElementById('gt-checkin-amount').value;
    const date   = document.getElementById('gt-checkin-date').value;
    const notes  = document.getElementById('gt-checkin-notes').value;
    if (!amount || +amount <= 0) { showToast('Enter valid amount', 'warning'); return; }
    apiPost({ action: 'goal_checkin_save', goal_id: goalId, amount, checkin_date: date, notes }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
      if (r.ok) { this.closeCheckin(); this.loadData(); }
    });
  },

  showHistory(goalId, goalName) {
    document.getElementById('gt-history-title').textContent = '📋 ' + goalName + ' — Checkin History';
    document.getElementById('gt-history-body').innerHTML = '<div class="loading-row">Loading…</div>';
    document.getElementById('gt-history-modal').style.display = '';
    apiPost({ action: 'goal_checkin_history', goal_id: goalId }).then(r => {
      if (!r.ok) { document.getElementById('gt-history-body').innerHTML = '<div class="alert alert-danger">Error</div>'; return; }
      const rows = r.data.checkins || [];
      if (!rows.length) {
        document.getElementById('gt-history-body').innerHTML = '<div class="empty-state"><div>No checkins yet.</div></div>';
        return;
      }
      let html = `<div style="margin-bottom:12px;font-size:14px;font-weight:600;">Total invested: <span class="wd-gain">${formatINR(r.data.total)}</span></div>`;
      html += `<table class="data-table"><thead><tr><th>Date</th><th class="text-right">Amount</th><th>Notes</th></tr></thead><tbody>`;
      for (const c of rows) {
        html += `<tr>
          <td class="wd-num">${esc(c.checkin_date?.substring(0,10))}</td>
          <td class="text-right wd-num wd-gain">${formatINR(c.amount)}</td>
          <td style="font-size:12px;color:var(--text-muted);">${esc(c.notes || '—')}</td>
        </tr>`;
      }
      html += '</tbody></table>';
      document.getElementById('gt-history-body').innerHTML = html;
    });
  },

  closeHistory() { document.getElementById('gt-history-modal').style.display = 'none'; }
};

document.addEventListener('DOMContentLoaded', () => GT.init());
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
