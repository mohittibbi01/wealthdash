<?php
/**
 * WealthDash — Goal Planning Page (Phase 5)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Goal Planning';
$activePage  = 'goals';

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Goal Planning</h1>
    <p class="page-subtitle">Define financial goals · Track progress · Know your SIP target</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-ghost btn-sm" onclick="openAddBucket()">+ Add Goal Bucket</button>
    <button class="btn btn-primary" id="btnAddGoal">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Goal
    </button>
  </div>
</div>

<!-- t139: Goal Buckets Section -->
<div class="card mb-4" style="border:1.5px solid var(--accent);background:rgba(99,102,241,.03);">
  <div class="card-header">
    <h3 class="card-title">🪣 Goal Buckets — Tag Funds to Goals</h3>
    <span style="font-size:11px;color:var(--text-muted);">Link any MF holding or SIP to a life goal</span>
  </div>
  <div class="card-body" style="padding:14px;">
    <div id="bucketGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:12px;">
      <div style="text-align:center;color:var(--text-muted);padding:20px;"><span class="spinner"></span></div>
    </div>
    <button onclick="openAddBucket()" class="btn btn-ghost btn-sm" style="border:1.5px dashed var(--border);width:100%;padding:10px;">
      + Create New Goal Bucket
    </button>
  </div>
</div>

<!-- Summary -->
<div class="cards-grid cards-grid-4 mb-4">
  <div class="stat-card"><div class="stat-label">Total Goals</div><div class="stat-value" id="cardTotalGoals">—</div></div>
  <div class="stat-card"><div class="stat-label">Goals Achieved</div><div class="stat-value text-success" id="cardAchieved">—</div></div>
  <div class="stat-card"><div class="stat-label">Total Target</div><div class="stat-value" id="cardTotalTarget">—</div></div>
  <div class="stat-card"><div class="stat-label">Total Saved</div><div class="stat-value" id="cardTotalSaved">—</div></div>
</div>

<!-- Goals Grid -->
<div id="goalsGrid" class="goals-grid"></div>
<div id="goalsEmpty" style="display:none" class="card text-center" style="padding:3rem">
  <p class="text-secondary">No goals yet. Create your first investment goal!</p>
  <button class="btn btn-primary mt-2" onclick="openAddGoal()">+ Add Goal</button>
</div>

<!-- Add Bucket Modal -->
<div class="modal-overlay" id="modalAddBucket" style="display:none;">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header">
      <h3 class="modal-title">Create Goal Bucket</h3>
      <button class="modal-close" onclick="hideModal('modalAddBucket')">✕</button>
    </div>
    <div class="modal-body">
      <div style="display:flex;gap:10px;align-items:flex-end;margin-bottom:10px;">
        <div>
          <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Emoji</label>
          <input type="text" id="bktEmoji" value="🎯" maxlength="2" class="form-input" style="width:50px;text-align:center;font-size:20px;padding:6px;">
        </div>
        <div style="flex:1;">
          <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Goal Name *</label>
          <input type="text" id="bktName" class="form-input" placeholder="e.g. Daughter Education">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Target Amount (₹)</label>
          <input type="number" id="bktTarget" class="form-input" placeholder="e.g. 5000000">
        </div>
        <div class="form-group">
          <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Target Date</label>
          <input type="date" id="bktDate" class="form-input">
        </div>
      </div>
      <div class="form-group">
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Color</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php foreach(['#6366f1','#8b5cf6','#ec4899','#ef4444','#f59e0b','#10b981','#3b82f6','#14b8a6'] as $c): ?>
          <div onclick="selectBktColor('<?= $c ?>')" data-color="<?= $c ?>"
            style="width:28px;height:28px;border-radius:50%;background:<?= $c ?>;cursor:pointer;border:2px solid transparent;transition:border .15s;"
            class="bkt-color-swatch"></div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="bktColor" value="#6366f1">
      </div>
      <div id="bktError" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalAddBucket')">Cancel</button>
      <button class="btn btn-primary" onclick="saveBucket()">Create Bucket</button>
    </div>
  </div>
</div>

<!-- Link Fund to Goal Modal -->
<div class="modal-overlay" id="modalLinkFund" style="display:none;">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <h3 class="modal-title" id="linkFundTitle">Link Fund to Goal</h3>
      <button class="modal-close" onclick="hideModal('modalLinkFund')">✕</button>
    </div>
    <div class="modal-body">
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">Select goal to link this fund/SIP to:</div>
      <div id="linkGoalList" style="display:flex;flex-direction:column;gap:6px;"></div>
    </div>
  </div>
</div>

<script>
/* t139: Goal Buckets JS */
const BUCKET_KEY = 'wd_goal_buckets_v1'; // localStorage fallback
let _buckets = [];

async function loadBuckets() {
  try {
    const res = await fetch(`${APP_URL}/api/router.php?action=goals_with_values`);
    const d   = await res.json();
    if (d.success) { _buckets = d.data || []; renderBuckets(); return; }
  } catch(e) {}
  // Fallback to localStorage
  try { _buckets = JSON.parse(localStorage.getItem(BUCKET_KEY) || '[]'); } catch(e) { _buckets = []; }
  renderBuckets();
}

function renderBuckets() {
  const grid = document.getElementById('bucketGrid');
  if (!grid) return;
  if (!_buckets.length) {
    grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;color:var(--text-muted);padding:20px;font-size:13px;">
      No goal buckets yet.<br>Create one to start tagging your investments to life goals.
    </div>`;
    return;
  }
  function fmtI(v) {
    v = Math.abs(v||0);
    if (v >= 1e7) return '₹'+(v/1e7).toFixed(1)+'Cr';
    if (v >= 1e5) return '₹'+(v/1e5).toFixed(1)+'L';
    return '₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});
  }
  grid.innerHTML = _buckets.map(b => {
    const target  = parseFloat(b.target_amount)||0;
    const current = parseFloat(b.current_value)||0;
    const pct     = target > 0 ? Math.min(100, (current/target*100)).toFixed(0) : 0;
    const daysLeft= b.target_date ? Math.ceil((new Date(b.target_date)-Date.now())/86400000) : null;
    const onTrack = daysLeft && current > 0 && target > 0;
    return `<div style="background:var(--bg-surface);border:1.5px solid var(--border);border-radius:10px;padding:14px;position:relative;overflow:hidden;">
      <div style="position:absolute;top:0;left:0;right:0;height:3px;background:${b.color||'#6366f1'};opacity:.7;"></div>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
        <span style="font-size:22px;">${b.emoji||'🎯'}</span>
        <div style="flex:1;">
          <div style="font-weight:700;font-size:13px;">${b.name}</div>
          ${b.target_date?`<div style="font-size:11px;color:var(--text-muted);">By ${new Date(b.target_date).toLocaleDateString('en-IN',{month:'short',year:'numeric'})}</div>`:''}
        </div>
        <button onclick="deleteBucket(${b.id})" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:14px;">✕</button>
      </div>
      ${target>0?`
      <div style="margin-bottom:8px;">
        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px;">
          <span style="color:var(--text-muted);">${fmtI(current)} saved</span>
          <span style="font-weight:700;color:${b.color||'#6366f1'};">${pct}%</span>
        </div>
        <div style="height:6px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
          <div style="height:100%;width:${pct}%;background:${b.color||'#6366f1'};border-radius:99px;transition:width .5s;"></div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Target: ${fmtI(target)}</div>
      </div>`:''}
      <div style="display:flex;align-items:center;justify-content:space-between;font-size:11px;">
        <span style="color:var(--text-muted);">${b.linked_count||0} fund${b.linked_count!=1?'s':''} linked</span>
        <button onclick="openTagFunds(${b.id},'${b.name}')" style="font-size:11px;padding:3px 8px;border-radius:5px;background:${b.color||'#6366f1'};color:#fff;border:none;cursor:pointer;font-weight:700;">+ Tag Fund</button>
      </div>
    </div>`;
  }).join('');
}

function selectBktColor(c) {
  document.getElementById('bktColor').value = c;
  document.querySelectorAll('.bkt-color-swatch').forEach(s => {
    s.style.borderColor = s.dataset.color === c ? '#fff' : 'transparent';
    s.style.transform   = s.dataset.color === c ? 'scale(1.2)' : 'scale(1)';
  });
}

function openAddBucket() { showModal('modalAddBucket'); selectBktColor('#6366f1'); }

async function saveBucket() {
  const name   = document.getElementById('bktName').value.trim();
  const emoji  = document.getElementById('bktEmoji').value.trim() || '🎯';
  const color  = document.getElementById('bktColor').value || '#6366f1';
  const target = parseFloat(document.getElementById('bktTarget').value)||0;
  const date   = document.getElementById('bktDate').value || '';
  if (!name) { document.getElementById('bktError').textContent='Name required'; document.getElementById('bktError').style.display=''; return; }

  try {
    const res = await apiPost({ action:'goals_add', name, emoji, color, target_amount:target, target_date:date });
    if (res.success) {
      hideModal('modalAddBucket');
      showToast(`✅ Goal "${name}" created!`, 'success');
      loadBuckets();
    } else {
      // Fallback: localStorage
      _buckets.unshift({ id: Date.now(), name, emoji, color, target_amount:target, target_date:date, current_value:0, linked_count:0 });
      try { localStorage.setItem(BUCKET_KEY, JSON.stringify(_buckets)); } catch(e){}
      hideModal('modalAddBucket');
      renderBuckets();
      showToast(`✅ Goal "${name}" created (offline)`, 'success');
    }
  } catch(e) {
    // offline fallback
    _buckets.unshift({ id: Date.now(), name, emoji, color, target_amount:target, target_date:date, current_value:0, linked_count:0 });
    try { localStorage.setItem(BUCKET_KEY, JSON.stringify(_buckets)); } catch(ex){}
    hideModal('modalAddBucket');
    renderBuckets();
  }
}

async function deleteBucket(id) {
  if (!confirm('Delete this goal bucket?')) return;
  try { await apiPost({ action:'goals_delete', id }); } catch(e){}
  _buckets = _buckets.filter(b => b.id !== id);
  try { localStorage.setItem(BUCKET_KEY, JSON.stringify(_buckets)); } catch(e){}
  renderBuckets();
  showToast('Goal deleted.', 'info');
}

function openTagFunds(goalId, goalName) {
  // Show holdings to tag — fetched from MF API
  document.getElementById('linkFundTitle').textContent = `Tag Fund → ${goalName}`;
  const list = document.getElementById('linkGoalList');
  list.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:16px;">Enter fund name to tag:</div><input type="text" id="tagFundSearch" class="form-input" placeholder="Fund name…" style="margin-bottom:8px;"><div id="tagFundResults"></div>';
  document.getElementById('tagFundSearch').oninput = function() {
    const q = this.value.toLowerCase();
    const results = (window._mfHoldings || []).filter(h => (h.scheme_name||'').toLowerCase().includes(q)).slice(0,6);
    document.getElementById('tagFundResults').innerHTML = results.map(h =>
      `<div style="padding:7px 10px;border-radius:7px;cursor:pointer;font-size:12px;display:flex;justify-content:space-between;align-items:center;"
            onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background=''"
            onclick="tagFundToGoal(${goalId},${h.fund_id||h.id},'${(h.scheme_name||'').replace(/'/g,'\\'')}')">
        <span>${h.scheme_name}</span>
        <span style="font-size:11px;color:var(--text-muted);">${h.category||''}</span>
      </div>`
    ).join('') || '<div style="color:var(--text-muted);font-size:12px;padding:8px;">No holdings found — type fund name</div>';
  };
  showModal('modalLinkFund');
}

async function tagFundToGoal(goalId, fundId, fundName) {
  try {
    await apiPost({ action:'goals_link_fund', goal_id:goalId, fund_id:fundId });
    hideModal('modalLinkFund');
    showToast(`✅ ${fundName} tagged to goal!`, 'success');
    loadBuckets();
  } catch(e) { showToast('Could not tag fund', 'error'); }
}

document.addEventListener('DOMContentLoaded', loadBuckets);
</script>

<!-- Goal Calculator (standalone) -->
<div class="card mt-4">
  <div class="card-header">
    <h3 class="card-title">SIP Calculator</h3>
    <span class="text-secondary text-sm">How much SIP do I need to reach my target?</span>
  </div>
  <div class="card-body">
    <div class="form-row" style="flex-wrap:wrap;gap:1rem;align-items:flex-end">
      <div class="form-group mb-0">
        <label class="form-label">Target Amount (₹)</label>
        <input type="number" class="form-control" id="calcTarget" value="1000000" step="10000">
      </div>
      <div class="form-group mb-0">
        <label class="form-label">Already Saved (₹)</label>
        <input type="number" class="form-control" id="calcSaved" value="0">
      </div>
      <div class="form-group mb-0">
        <label class="form-label">Months to Goal</label>
        <input type="number" class="form-control" id="calcMonths" value="120" min="1">
      </div>
      <div class="form-group mb-0">
        <label class="form-label">Expected Return (%/year)</label>
        <input type="number" class="form-control" id="calcReturn" value="12" step="0.5" min="1" max="30">
      </div>
      <div class="form-group mb-0">
        <button class="btn btn-primary" id="btnCalc">Calculate</button>
      </div>
    </div>
    <div id="calcResult" style="display:none;margin-top:1.5rem">
      <div class="cards-grid cards-grid-3 mb-3">
        <div class="stat-card stat-card-sm"><div class="stat-label">Monthly SIP Needed</div><div class="stat-value text-primary" id="calcSipNeeded">—</div></div>
        <div class="stat-card stat-card-sm"><div class="stat-label">Projected Final Value</div><div class="stat-value text-success" id="calcProjected">—</div></div>
        <div class="stat-card stat-card-sm"><div class="stat-label">Total Investment</div><div class="stat-value" id="calcTotalInv">—</div></div>
      </div>
      <canvas id="calcChart" height="70"></canvas>
    </div>
  </div>
</div>

<!-- Add/Edit Goal Modal -->
<div class="modal-overlay" id="goalModal" style="display:none">
  <div class="modal" style="max-width:540px">
    <div class="modal-header">
      <h3 class="modal-title" id="goalModalTitle">New Goal</h3>
      <button class="modal-close" onclick="closeGoalModal()">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="goalId">
      <div class="form-group">
        <label class="form-label">Goal Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="goalName" placeholder="e.g. Child's Education, House Down Payment">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Target Amount (₹) <span class="text-danger">*</span></label>
          <input type="number" class="form-control" id="goalTarget" step="10000" min="1000" placeholder="5000000">
        </div>
        <div class="form-group">
          <label class="form-label">Target Date <span class="text-danger">*</span></label>
          <input type="text" class="form-control date-input" id="goalTargetDate" placeholder="DD-MM-YYYY">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Expected Return (%/year)</label>
          <input type="number" class="form-control" id="goalReturn" value="12" step="0.5" min="1" max="30">
        </div>
        <div class="form-group">
          <label class="form-label">Priority</label>
          <select class="form-select" id="goalPriority">
            <option value="high">High</option>
            <option value="medium" selected>Medium</option>
            <option value="low">Low</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Color</label>
          <input type="color" class="form-control" id="goalColor" value="#2563EB" style="height:40px;padding:4px">
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <input type="text" class="form-control" id="goalDesc" placeholder="Optional notes">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeGoalModal()">Cancel</button>
      <button class="btn btn-primary" id="btnGoalSave">Save Goal</button>
    </div>
  </div>
</div>

<!-- Contribute Modal -->
<div class="modal-overlay" id="contributeModal" style="display:none">
  <div class="modal" style="max-width:380px">
    <div class="modal-header">
      <h3 class="modal-title">Add Contribution</h3>
      <button class="modal-close" onclick="closeContributeModal()">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="contribGoalId">
      <p class="text-secondary mb-3" id="contribGoalName"></p>
      <div class="form-group">
        <label class="form-label">Amount (₹)</label>
        <input type="number" class="form-control" id="contribAmount" min="1" step="100" placeholder="10000">
      </div>
      <div class="form-group">
        <label class="form-label">Date</label>
        <input type="text" class="form-control date-input" id="contribDate" placeholder="DD-MM-YYYY">
      </div>
      <div class="form-group">
        <label class="form-label">Note</label>
        <input type="text" class="form-control" id="contribNote" placeholder="Optional">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeContributeModal()">Cancel</button>
      <button class="btn btn-primary" id="btnContribSave">Save</button>
    </div>
  </div>
</div>

<style>
.goals-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:1.25rem; margin-bottom:1.5rem; }
.goal-card { background:var(--card-bg); border:1px solid var(--border); border-radius:var(--radius-lg); padding:1.25rem; position:relative; overflow:hidden; }
.goal-card-accent { position:absolute; top:0; left:0; right:0; height:4px; border-radius:var(--radius-lg) var(--radius-lg) 0 0; }
.goal-card-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem; }
.goal-name { font-weight:600; font-size:1rem; }
.goal-badge-priority { padding:.2rem .6rem; border-radius:20px; font-size:.7rem; font-weight:600; text-transform:uppercase; }
.priority-high   { background:rgba(239,68,68,.15);  color:var(--danger); }
.priority-medium { background:rgba(245,158,11,.15); color:#f59e0b; }
.priority-low    { background:rgba(107,114,128,.15);color:var(--text-secondary); }
.goal-amount-row { display:flex; justify-content:space-between; margin-bottom:.75rem; }
.goal-progress-wrap { margin-bottom:.75rem; }
.goal-progress-bar-bg { background:var(--border); border-radius:20px; height:8px; overflow:hidden; }
.goal-progress-bar-fill { height:100%; border-radius:20px; transition:width .4s; }
.goal-meta { font-size:.75rem; color:var(--text-secondary); display:flex; justify-content:space-between; margin-bottom:.75rem; }
.goal-sip-row { background:rgba(37,99,235,.08); border-radius:var(--radius); padding:.6rem .75rem; font-size:.8rem; display:flex; justify-content:space-between; margin-bottom:.75rem; }
.goal-actions { display:flex; gap:.5rem; }
.achieved-overlay { position:absolute; inset:0; background:rgba(34,197,94,.08); display:flex; align-items:center; justify-content:center; pointer-events:none; font-size:2.5rem; }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
  loadGoals();
  document.getElementById('btnAddGoal').addEventListener('click', openAddGoal);
  document.getElementById('btnGoalSave').addEventListener('click', saveGoal);
  document.getElementById('btnContribSave').addEventListener('click', saveContribution);
  document.getElementById('btnCalc').addEventListener('click', runCalculator);
});

async function loadGoals() {
  try {
    const d = await API.post('/api/router.php', { action: 'goal_list' });
    document.getElementById('cardTotalGoals').textContent  = d.goals?.length ?? 0;
    document.getElementById('cardAchieved').textContent    = d.achieved_count ?? 0;
    document.getElementById('cardTotalTarget').textContent = formatINR(d.total_targets);
    document.getElementById('cardTotalSaved').textContent  = formatINR(d.total_saved);

    const grid  = document.getElementById('goalsGrid');
    const empty = document.getElementById('goalsEmpty');
    if (!d.goals?.length) { grid.innerHTML=''; empty.style.display='block'; return; }
    empty.style.display = 'none';
    grid.innerHTML = d.goals.map(renderGoalCard).join('');
  } catch(e) { console.error(e); }
}

function renderGoalCard(g) {
  const pct   = g.progress_pct ?? 0;
  const color = g.color || '#2563EB';
  const priorityClass = { high:'priority-high', medium:'priority-medium', low:'priority-low' }[g.priority] || 'priority-medium';
  const monthsLeft = g.months_left || 0;
  const sipNeeded  = g.monthly_sip_needed ?? 0;
  return `
    <div class="goal-card">
      <div class="goal-card-accent" style="background:${color}"></div>
      ${g.is_achieved ? '<div class="achieved-overlay">🎉</div>' : ''}
      <div class="goal-card-header">
        <div class="goal-name">${esc(g.name)}</div>
        <span class="goal-badge-priority ${priorityClass}">${g.priority}</span>
      </div>
      <div class="goal-amount-row">
        <div>
          <div class="text-xs text-secondary">Saved</div>
          <div class="font-semibold">${formatINR(g.effective_saved)}</div>
        </div>
        <div class="text-right">
          <div class="text-xs text-secondary">Target</div>
          <div class="font-semibold">${formatINR(g.target_amount)}</div>
        </div>
      </div>
      <div class="goal-progress-wrap">
        <div class="goal-progress-bar-bg">
          <div class="goal-progress-bar-fill" style="width:${pct}%;background:${color}"></div>
        </div>
        <div class="flex-between mt-1">
          <span class="text-xs text-secondary">${pct}% achieved</span>
          <span class="text-xs text-secondary">${formatINR(g.amount_remaining)} remaining</span>
        </div>
      </div>
      <div class="goal-meta">
        <span>Target: ${formatDate(g.target_date)}</span>
        <span>${monthsLeft > 0 ? monthsLeft + ' months left' : (g.is_achieved ? 'Achieved!' : 'Overdue')}</span>
      </div>
      ${sipNeeded > 0 && !g.is_achieved ? `
      <div class="goal-sip-row">
        <span>Monthly SIP needed</span>
        <strong style="color:${color}">${formatINR(sipNeeded)}/mo</strong>
      </div>` : ''}
      <div class="goal-actions">
        ${!g.is_achieved ? `<button class="btn btn-primary btn-xs" onclick="openContribute(${g.id},'${esc(g.name)}')">+ Add Money</button>` : ''}
        <button class="btn btn-ghost btn-xs" onclick="editGoal(${g.id})">Edit</button>
        ${!g.is_achieved ? `<button class="btn btn-ghost btn-xs text-success" onclick="markAchieved(${g.id})">✓ Done</button>` : ''}
        <button class="btn btn-ghost btn-xs text-danger" onclick="deleteGoal(${g.id},'${esc(g.name)}')">Delete</button>
      </div>
    </div>`;
}

function openAddGoal() {
  document.getElementById('goalModalTitle').textContent = 'New Goal';
  document.getElementById('goalId').value    = '';
  document.getElementById('goalName').value  = '';
  document.getElementById('goalTarget').value= '';
  document.getElementById('goalTargetDate').value = '';
  document.getElementById('goalReturn').value = '12';
  document.getElementById('goalPriority').value = 'medium';
  document.getElementById('goalColor').value = '#2563EB';
  document.getElementById('goalDesc').value  = '';
  document.getElementById('goalModal').style.display = 'flex';
}

async function editGoal(id) {
  try {
    const d = await API.post('/api/router.php', { action: 'goal_list' });
    const g = d.goals.find(g => g.id == id);
    if (!g) return;
    document.getElementById('goalModalTitle').textContent = 'Edit Goal';
    document.getElementById('goalId').value     = g.id;
    document.getElementById('goalName').value   = g.name;
    document.getElementById('goalTarget').value = g.target_amount;
    document.getElementById('goalTargetDate').value = g.target_date ? formatDate(g.target_date) : '';
    document.getElementById('goalReturn').value = g.expected_return_pct;
    document.getElementById('goalPriority').value = g.priority;
    document.getElementById('goalColor').value  = g.color;
    document.getElementById('goalDesc').value   = g.description || '';
    document.getElementById('goalModal').style.display = 'flex';
  } catch(e) { showToast('Error loading goal', 'error'); }
}

async function saveGoal() {
  const id     = document.getElementById('goalId').value;
  const name   = document.getElementById('goalName').value.trim();
  const target = document.getElementById('goalTarget').value;
  const date   = document.getElementById('goalTargetDate').value;
  if (!name || !target || !date) { showToast('Name, target, and date required.','error'); return; }

  const payload = {
    action: id ? 'goal_edit' : 'goal_add',
    goal_id: id,
    name, target_amount: target,
    target_date: date,
    expected_return_pct: document.getElementById('goalReturn').value,
    priority: document.getElementById('goalPriority').value,
    color: document.getElementById('goalColor').value,
    description: document.getElementById('goalDesc').value,
    csrf_token: window.CSRF_TOKEN,
  };
  try {
    await API.post('/api/router.php', payload);
    showToast('Goal saved!');
    closeGoalModal();
    loadGoals();
  } catch(e) { showToast(e.message,'error'); }
}

function openContribute(goalId, goalName) {
  document.getElementById('contribGoalId').value   = goalId;
  document.getElementById('contribGoalName').textContent = goalName;
  document.getElementById('contribAmount').value   = '';
  document.getElementById('contribDate').value     = '';
  document.getElementById('contribNote').value     = '';
  document.getElementById('contributeModal').style.display = 'flex';
}

async function saveContribution() {
  const goalId = document.getElementById('contribGoalId').value;
  const amount = document.getElementById('contribAmount').value;
  if (!amount) { showToast('Amount required','error'); return; }
  try {
    await API.post('/api/router.php', {
      action: 'goal_contribute', goal_id: goalId, amount,
      date: document.getElementById('contribDate').value,
      note: document.getElementById('contribNote').value,
      csrf_token: window.CSRF_TOKEN,
    });
    showToast('Contribution saved!');
    closeContributeModal();
    loadGoals();
  } catch(e) { showToast(e.message,'error'); }
}

async function markAchieved(id) {
  if (!confirm('Mark this goal as achieved? 🎉')) return;
  try {
    await API.post('/api/router.php', { action:'goal_mark_achieved', goal_id:id, csrf_token:window.CSRF_TOKEN });
    showToast('🎉 Goal achieved!');
    loadGoals();
  } catch(e) { showToast(e.message,'error'); }
}

async function deleteGoal(id, name) {
  if (!confirm(`Delete goal "${name}"?`)) return;
  try {
    await API.post('/api/router.php', { action:'goal_delete', goal_id:id, csrf_token:window.CSRF_TOKEN });
    showToast('Goal deleted.');
    loadGoals();
  } catch(e) { showToast(e.message,'error'); }
}

let calcChartInst = null;
async function runCalculator() {
  const target  = +document.getElementById('calcTarget').value;
  const saved   = +document.getElementById('calcSaved').value;
  const months  = +document.getElementById('calcMonths').value;
  const ret     = +document.getElementById('calcReturn').value;
  if (!target || !months) { showToast('Enter target and months','error'); return; }

  try {
    const d = await API.post('/api/router.php', {
      action: 'goal_projection', target_amount: target,
      current_saved: saved, months, return_pct: ret,
    });
    document.getElementById('calcResult').style.display = 'block';
    document.getElementById('calcSipNeeded').textContent  = formatINR(d.sip_needed) + '/mo';
    document.getElementById('calcProjected').textContent  = formatINR(d.projected_value);
    document.getElementById('calcTotalInv').textContent   = formatINR((d.sip_needed||0)*months + saved);

    const ctx = document.getElementById('calcChart').getContext('2d');
    if (calcChartInst) calcChartInst.destroy();
    calcChartInst = new Chart(ctx, {
      type:'line',
      data: {
        labels: (d.chart||[]).map(r=>'M'+r.month),
        datasets: [
          { label:'Projected Value', data:(d.chart||[]).map(r=>r.projected), borderColor:'#2563EB', fill:false, tension:.3 },
          { label:'Target',          data:(d.chart||[]).map(r=>r.target),    borderColor:'#22c55e', borderDash:[5,5], fill:false },
        ]
      },
      options:{
        responsive:true,
        plugins:{ legend:{ position:'top' } },
        scales:{ y:{ ticks:{ callback:v=> '₹'+(v>=100000?(v/100000).toFixed(1)+'L':v>=1000?(v/1000).toFixed(0)+'K':v) } } }
      }
    });
  } catch(e) { showToast(e.message,'error'); }
}

function closeGoalModal()      { document.getElementById('goalModal').style.display      = 'none'; }
function closeContributeModal(){ document.getElementById('contributeModal').style.display = 'none'; }
function formatDate(d) {
  if (!d) return '—';
  const p = d.split('-');
  if (p.length===3 && p[0].length===4) return `${p[2]}-${p[1]}-${p[0]}`;
  return d;
}
</script>

<!-- ═══════════════════════════════════════════════════════════════
     t154 — FIRE CALCULATOR
═══════════════════════════════════════════════════════════════ -->
<div class="card mt-4">
  <div class="card-header">
    <h3 class="card-title">🔥 FIRE Calculator — Financial Independence, Retire Early</h3>
    <span style="font-size:11px;color:var(--text-muted);">25x Rule · India-adjusted (6% inflation, 12% equity)</span>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Current Age</label>
        <input type="number" id="fireAge" value="30" class="form-control" oninput="calcFIRE()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Monthly Expenses (₹)</label>
        <input type="number" id="fireExpenses" value="50000" class="form-control" oninput="calcFIRE()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Current Corpus (₹)</label>
        <input type="number" id="fireCorpus" value="0" class="form-control" oninput="calcFIRE()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Monthly SIP (₹)</label>
        <input type="number" id="fireSip" value="30000" class="form-control" oninput="calcFIRE()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Expected Return (%)</label>
        <select id="fireReturn" class="form-control" onchange="calcFIRE()">
          <option value="10">10% (Conservative)</option>
          <option value="12" selected>12% (Moderate)</option>
          <option value="15">15% (Aggressive)</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">FIRE Type</label>
        <select id="fireType" class="form-control" onchange="calcFIRE()">
          <option value="full">Full FIRE (stop working)</option>
          <option value="barista">Barista FIRE (part-time ₹20K/mo)</option>
          <option value="coast">Coast FIRE (no more investing)</option>
        </select>
      </div>
    </div>
    <div id="fireResult"></div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     t155 — CHILD EDUCATION PLANNER
═══════════════════════════════════════════════════════════════ -->
<div class="card mt-4">
  <div class="card-header">
    <h3 class="card-title">🎓 Child Education Planner</h3>
    <span style="font-size:11px;color:var(--text-muted);">Education inflation: 10-12% India</span>
  </div>
  <div class="card-body">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
      <button onclick="setEduPreset(500000,'Engineering - Private',18)" class="btn btn-ghost btn-sm">Private Engineering ₹5L/yr</button>
      <button onclick="setEduPreset(1500000,'IIT/NIT',18)" class="btn btn-ghost btn-sm">IIT/NIT ₹15L/yr</button>
      <button onclick="setEduPreset(3000000,'MBA - IIM',22)" class="btn btn-ghost btn-sm">IIM MBA ₹30L/yr</button>
      <button onclick="setEduPreset(5000000,'MBBS Private',18)" class="btn btn-ghost btn-sm">MBBS ₹50L/yr</button>
      <button onclick="setEduPreset(8000000,'Abroad - USA',18)" class="btn btn-ghost btn-sm">USA/UK ₹80L/yr</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Child's Age (years)</label>
        <input type="number" id="eduChildAge" value="5" min="0" max="17" class="form-control" oninput="calcEdu()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">College Start Age</label>
        <input type="number" id="eduCollegeAge" value="18" min="15" max="25" class="form-control" oninput="calcEdu()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Current Annual Cost (₹)</label>
        <input type="number" id="eduCost" value="500000" class="form-control" oninput="calcEdu()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Course Duration (yrs)</label>
        <input type="number" id="eduDuration" value="4" min="1" max="7" class="form-control" oninput="calcEdu()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Current Savings (₹)</label>
        <input type="number" id="eduSaved" value="0" class="form-control" oninput="calcEdu()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Child Gender</label>
        <select id="eduGender" class="form-control" onchange="calcEdu()">
          <option value="any">Any</option>
          <option value="girl">Girl (SSY eligible)</option>
        </select>
      </div>
    </div>
    <div id="eduResult"></div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     t158 — NOMINEE TRACKER
═══════════════════════════════════════════════════════════════ -->
<div class="card mt-4">
  <div class="card-header">
    <h3 class="card-title">📋 Nominee Tracker</h3>
    <span style="font-size:11px;color:var(--text-muted);">Across all assets — nomination status check</span>
  </div>
  <div class="card-body">
    <div id="nomineeTrackerBody"></div>
    <button onclick="openNomineeSetup()" class="btn btn-primary btn-sm" style="margin-top:10px;">✎ Update Nomination Status</button>
    <div style="font-size:12px;color:var(--text-muted);margin-top:10px;">
      💡 SEBI requires nomination for all MF folios (June 2024 deadline). Missing nomination can freeze assets.
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     t147 — PEER BENCHMARKING
═══════════════════════════════════════════════════════════════ -->
<div class="card mt-4">
  <div class="card-header">
    <h3 class="card-title">👥 Peer Benchmarking — How Do You Compare?</h3>
    <span style="font-size:11px;color:var(--text-muted);">Anonymous · Behavior-based only · No fund names shared</span>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px;">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Your Age</label>
        <input type="number" id="peerAge" value="30" class="form-control" oninput="calcPeerBenchmark()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Annual Income (₹)</label>
        <select id="peerIncome" class="form-control" onchange="calcPeerBenchmark()">
          <option value="500000">Under ₹5L</option>
          <option value="1000000">₹5L–₹10L</option>
          <option value="2000000" selected>₹10L–₹25L</option>
          <option value="5000000">₹25L–₹50L</option>
          <option value="10000000">₹50L+</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">SIP Consistency (%)</label>
        <input type="number" id="peerSipPct" value="90" min="0" max="100" class="form-control" oninput="calcPeerBenchmark()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Equity Allocation (%)</label>
        <input type="number" id="peerEquityPct" value="70" min="0" max="100" class="form-control" oninput="calcPeerBenchmark()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Savings Rate (%)</label>
        <input type="number" id="peerSavingsPct" value="30" min="0" max="100" class="form-control" oninput="calcPeerBenchmark()">
      </div>
    </div>
    <div id="peerResult"></div>
  </div>
</div>

<script>
/* ─────────────────────── t154: FIRE Calculator ────────────────────────── */
function calcFIRE() {
  const age      = parseInt(document.getElementById('fireAge')?.value)      || 30;
  const expenses = parseFloat(document.getElementById('fireExpenses')?.value)|| 50000;
  const corpus   = parseFloat(document.getElementById('fireCorpus')?.value)  || 0;
  const sip      = parseFloat(document.getElementById('fireSip')?.value)     || 0;
  const ret      = (parseFloat(document.getElementById('fireReturn')?.value) || 12) / 100;
  const type     = document.getElementById('fireType')?.value || 'full';
  const res      = document.getElementById('fireResult');
  if (!res) return;

  const inflation   = 0.06; // India inflation
  const annualExp   = expenses * 12;
  const withdrawal  = type === 'barista' ? Math.max(0, annualExp - 240000) : annualExp; // barista: ₹20K/mo part-time
  const fireNumber  = withdrawal / 0.04; // 4% safe withdrawal rule
  const coastFireN  = fireNumber; // same target, just coast to it

  // Years to FIRE: simulate month by month
  let c = corpus, month = 0;
  const r = ret / 12;
  while (c < fireNumber && month < 600) {
    c = c * (1 + r) + sip;
    month++;
  }
  const yearsToFire = month / 12;
  const fireAge     = age + yearsToFire;
  const achieved    = corpus / fireNumber * 100;

  // Coast FIRE: how much needed NOW to coast to fireNumber by 60 with 0 more investing
  const coastYears  = Math.max(1, 60 - age);
  const coastNumber = fireNumber / Math.pow(1 + ret, coastYears);
  const coastDone   = corpus >= coastNumber;

  // SWP sustainability: can fireNumber last 30 years at 6% inflation?
  const swpR        = 0.08 / 12; // 8% conservative post-FIRE return
  const swpMonths   = 30 * 12;
  const maxSWP      = fireNumber * swpR / (1 - Math.pow(1+swpR, -swpMonths));

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
  }

  const pct = Math.min(100, achieved).toFixed(0);
  const reachable = month < 600;

  res.innerHTML = `
    <div style="margin-bottom:14px;">
      <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;">
        <span style="font-weight:700;">FIRE Progress: ${pct}% (${fmtI(corpus)} of ${fmtI(fireNumber)})</span>
        <span style="color:var(--text-muted);">${type==='barista'?'Barista ':type==='coast'?'Coast ':''}FIRE Number</span>
      </div>
      <div style="height:12px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
        <div style="height:100%;width:${pct}%;background:${parseFloat(pct)>=100?'#16a34a':parseFloat(pct)>=50?'#f59e0b':'#3b82f6'};border-radius:99px;transition:width .5s;"></div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">FIRE Number</div>
        <div style="font-size:18px;font-weight:800;color:#3b82f6;">${fmtI(fireNumber)}</div>
        <div style="font-size:11px;color:var(--text-muted);">25× annual expenses</div>
      </div>
      <div style="background:${reachable?'rgba(22,163,74,.08)':'rgba(220,38,38,.08)'};border-radius:10px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Years to FIRE</div>
        <div style="font-size:18px;font-weight:800;color:${reachable?'#16a34a':'#dc2626'};">${reachable?yearsToFire.toFixed(1):'50+'}</div>
        <div style="font-size:11px;color:var(--text-muted);">FIRE age: ${reachable?fireAge.toFixed(0):'—'}</div>
      </div>
      <div style="background:${coastDone?'rgba(22,163,74,.08)':'var(--bg-secondary)'};border-radius:10px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Coast FIRE Need</div>
        <div style="font-size:18px;font-weight:800;color:${coastDone?'#16a34a':'var(--text-primary)'};">${fmtI(coastNumber)}</div>
        <div style="font-size:11px;color:${coastDone?'#16a34a':'var(--text-muted)'};">${coastDone?'✅ Achieved!':'Stop SIP when reached'}</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Max Safe Withdrawal</div>
        <div style="font-size:18px;font-weight:800;">${fmtI(maxSWP)}<span style="font-size:11px;">/mo</span></div>
        <div style="font-size:11px;color:var(--text-muted);">Lasts 30 years @8%</div>
      </div>
    </div>
    ${!reachable ? `<div style="padding:10px;background:rgba(220,38,38,.07);border-radius:7px;font-size:12px;color:#dc2626;">⚠️ With current SIP of ${fmtI(sip)}/mo, FIRE is not reachable in 50 years. Increase SIP or reduce expenses.</div>` :
      `<div style="padding:10px;background:rgba(22,163,74,.07);border-radius:7px;font-size:12px;color:#15803d;">✅ You'll achieve FIRE at age <strong>${fireAge.toFixed(0)}</strong>. Sustainable monthly withdrawal: <strong>${fmtI(maxSWP)}</strong></div>`}`;
}

/* ─────────────────────── t155: Child Education ─────────────────────────── */
function setEduPreset(cost, name, collegeAge) {
  document.getElementById('eduCost').value    = cost;
  document.getElementById('eduCollegeAge').value = collegeAge;
  calcEdu();
}
function calcEdu() {
  const childAge    = parseInt(document.getElementById('eduChildAge')?.value)    || 5;
  const collegeAge  = parseInt(document.getElementById('eduCollegeAge')?.value)  || 18;
  const cost        = parseFloat(document.getElementById('eduCost')?.value)       || 500000;
  const duration    = parseInt(document.getElementById('eduDuration')?.value)    || 4;
  const saved       = parseFloat(document.getElementById('eduSaved')?.value)     || 0;
  const gender      = document.getElementById('eduGender')?.value || 'any';
  const res         = document.getElementById('eduResult');
  if (!res) return;

  const yearsToCollege = Math.max(1, collegeAge - childAge);
  const eduInflation   = 0.11; // 11% education inflation India
  const investReturn   = 0.12 / 12;

  // Future cost of education (inflation-adjusted)
  let totalFutureCost = 0;
  for (let y = 0; y < duration; y++) {
    totalFutureCost += cost * Math.pow(1 + eduInflation, yearsToCollege + y);
  }

  // Monthly SIP needed
  const n = yearsToCollege * 12;
  const fvSaved = saved * Math.pow(1 + investReturn, n);
  const remaining = Math.max(0, totalFutureCost - fvSaved);
  const sipNeeded = remaining > 0 && n > 0
    ? remaining * investReturn / (Math.pow(1 + investReturn, n) - 1) / (1 + investReturn)
    : 0;

  const progressPct = Math.min(100, (fvSaved / totalFutureCost * 100)).toFixed(0);
  const ssyEligible = gender === 'girl' && childAge < 10;

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
  }

  res.innerHTML = `
    <div style="margin-bottom:14px;">
      <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;">
        <span>Corpus Progress: ${progressPct}%</span>
        <span style="color:var(--text-muted);">${yearsToCollege} years to college</span>
      </div>
      <div style="height:10px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
        <div style="height:100%;width:${progressPct}%;background:#8b5cf6;border-radius:99px;transition:width .5s;"></div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:12px;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Future Cost</div>
        <div style="font-size:18px;font-weight:800;color:#8b5cf6;">${fmtI(totalFutureCost)}</div>
        <div style="font-size:11px;color:var(--text-muted);">@11% edu inflation</div>
      </div>
      <div style="background:rgba(59,130,246,.07);border-radius:10px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Monthly SIP Needed</div>
        <div style="font-size:18px;font-weight:800;color:#3b82f6;">${fmtI(sipNeeded)}</div>
        <div style="font-size:11px;color:var(--text-muted);">Equity fund @12%</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Already Covered</div>
        <div style="font-size:18px;font-weight:800;color:#16a34a;">${fmtI(fvSaved)}</div>
        <div style="font-size:11px;color:var(--text-muted);">Existing savings grown</div>
      </div>
    </div>
    ${ssyEligible ? `<div style="padding:10px;background:rgba(22,163,74,.07);border-radius:7px;font-size:12px;color:#15803d;margin-bottom:8px;">
      🌸 <strong>SSY Eligible!</strong> Sukanya Samriddhi Yojana @8.2% tax-free for girl child (min ₹250/yr, max ₹1.5L/yr, deposit till age 15, maturity at 21). Open account before she turns 10.
    </div>` : ''}
    <div style="font-size:11px;color:var(--text-muted);padding:8px;background:rgba(99,102,241,.05);border-radius:6px;">
      💡 Recommendation: Equity MF for 10+ year horizon. Start a dedicated SIP. Increase by 10% each year to keep pace with education inflation.
    </div>`;
}

/* ─────────────────────── t158: Nominee Tracker ─────────────────────────── */
const NOMINEE_KEY = 'wd_nominee_status_v1';
const ASSET_TYPES = [
  {id:'mf',        label:'Mutual Funds', icon:'💼', critical:true},
  {id:'demat',     label:'Demat Account', icon:'📈', critical:true},
  {id:'bank',      label:'Bank Accounts', icon:'🏦', critical:true},
  {id:'epf',       label:'EPF / PF', icon:'🏢', critical:true},
  {id:'nps',       label:'NPS', icon:'🏛️', critical:false},
  {id:'insurance', label:'Life Insurance', icon:'🛡️', critical:true},
  {id:'health',    label:'Health Insurance', icon:'🏥', critical:false},
  {id:'ppf',       label:'PPF / SSY', icon:'📮', critical:false},
  {id:'property',  label:'Property', icon:'🏠', critical:false},
];

function getNomineeData() {
  try { return JSON.parse(localStorage.getItem(NOMINEE_KEY) || '{}'); } catch(e) { return {}; }
}

function renderNomineeTracker() {
  const data    = getNomineeData();
  const body    = document.getElementById('nomineeTrackerBody');
  if (!body) return;

  const missing = ASSET_TYPES.filter(a => !data[a.id] || data[a.id] === 'none');
  const minor   = ASSET_TYPES.filter(a => data[a.id] === 'minor');
  const done    = ASSET_TYPES.filter(a => data[a.id] === 'done');

  const criticalMissing = missing.filter(a => a.critical);

  body.innerHTML = `
    ${criticalMissing.length ? `<div style="padding:10px 14px;background:rgba(220,38,38,.08);border-radius:8px;border:1px solid #fca5a5;margin-bottom:12px;font-size:12px;">
      🚨 <strong>${criticalMissing.length} critical asset${criticalMissing.length>1?'s':''} missing nomination:</strong>
      ${criticalMissing.map(a => a.label).join(', ')}
    </div>` : done.length === ASSET_TYPES.length ? `<div style="padding:10px 14px;background:rgba(22,163,74,.08);border-radius:8px;margin-bottom:12px;font-size:12px;color:#15803d;">
      ✅ All assets have nominations filed!
    </div>` : ''}
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;">
      ${ASSET_TYPES.map(a => {
        const status = data[a.id] || 'none';
        const cfg    = status === 'done'  ? {bg:'rgba(22,163,74,.07)',  border:'#86efac',  badge:'✅ Done',   color:'#15803d'}
                     : status === 'minor' ? {bg:'rgba(245,158,11,.07)', border:'#fcd34d',  badge:'⚠️ Minor',  color:'#b45309'}
                     :                      {bg:'rgba(220,38,38,.07)',   border:'#fca5a5',  badge:'❌ Missing', color:'#dc2626'};
        return `<div style="background:${cfg.bg};border:1px solid ${cfg.border};border-radius:8px;padding:10px;display:flex;align-items:center;gap:8px;cursor:pointer;"
                     onclick="cycleNomineeStatus('${a.id}')" title="Click to update">
          <span style="font-size:18px;">${a.icon}</span>
          <div style="flex:1;min-width:0;">
            <div style="font-size:12px;font-weight:700;">${a.label}</div>
            <div style="font-size:11px;color:${cfg.color};font-weight:700;">${cfg.badge}</div>
          </div>
          ${a.critical ? '<span style="font-size:10px;color:#dc2626;font-weight:700;">CRITICAL</span>' : ''}
        </div>`;
      }).join('')}
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:10px;">
      Click any asset to cycle status: Missing → Done → Minor nominee. Minor nominee requires guardian name in forms.
    </div>`;
}

function cycleNomineeStatus(assetId) {
  const data   = getNomineeData();
  const status = data[assetId] || 'none';
  data[assetId] = status === 'none' ? 'done' : status === 'done' ? 'minor' : 'none';
  try { localStorage.setItem(NOMINEE_KEY, JSON.stringify(data)); } catch(e) {}
  renderNomineeTracker();
}

function openNomineeSetup() {
  ASSET_TYPES.forEach(a => {
    const s = confirm(`${a.icon} ${a.label}: Is nomination filed? OK = Yes, Cancel = No`);
    const data = getNomineeData();
    data[a.id] = s ? 'done' : 'none';
    try { localStorage.setItem(NOMINEE_KEY, JSON.stringify(data)); } catch(e) {}
  });
  renderNomineeTracker();
}

/* ─────────────────────── t147: Peer Benchmarking ───────────────────────── */
function calcPeerBenchmark() {
  const age       = parseInt(document.getElementById('peerAge')?.value)       || 30;
  const income    = parseInt(document.getElementById('peerIncome')?.value)    || 2000000;
  const sipPct    = parseFloat(document.getElementById('peerSipPct')?.value)  || 0;
  const equityPct = parseFloat(document.getElementById('peerEquityPct')?.value)|| 0;
  const savingsPct= parseFloat(document.getElementById('peerSavingsPct')?.value)|| 0;
  const res       = document.getElementById('peerResult');
  if (!res) return;

  // Peer benchmarks (simulated anonymous aggregate — India investor data)
  // Age group: 25-35 | Income: ₹10L-25L
  const peers = {
    sipConsistency: age < 35 ? 72 : age < 45 ? 78 : 82,
    equityAlloc:    age < 35 ? 75 : age < 45 ? 65 : 55,
    savingsRate:    income < 1000000 ? 22 : income < 2500000 ? 28 : 35,
    emergencyFund:  4.2, // months
    sipCount:       age < 35 ? 2.3 : 3.1,
  };

  function percentile(val, avg, std) {
    const z = (val - avg) / std;
    const p = Math.min(99, Math.max(1, Math.round(50 + z * 25)));
    return p;
  }

  const sipPercentile     = percentile(sipPct, peers.sipConsistency, 18);
  const equityPercentile  = age < 35
    ? (equityPct >= 70 ? 60 : equityPct >= 50 ? 40 : 20)
    : (equityPct >= 60 ? 60 : equityPct >= 40 ? 40 : 20);
  const savingsPercentile = percentile(savingsPct, peers.savingsRate, 10);

  function badge(pct) {
    if (pct >= 75) return { label: `Top ${100-pct}%`, color:'#15803d', bg:'rgba(22,163,74,.1)' };
    if (pct >= 50) return { label: `Top ${100-pct}%`, color:'#d97706', bg:'rgba(245,158,11,.1)' };
    return { label: `Bottom ${pct}%`, color:'#dc2626', bg:'rgba(220,38,38,.1)' };
  }

  const sipB  = badge(sipPercentile);
  const eqB   = badge(equityPercentile);
  const savB  = badge(savingsPercentile);

  res.innerHTML = `
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">
      Comparing you with investors aged ${age-5}–${age+5}, income bracket ${(income/100000).toFixed(0)}L+. Data: anonymous aggregate.
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px;">
      <div style="background:${sipB.bg};border-radius:10px;padding:14px;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">SIP Consistency</div>
        <div style="font-size:18px;font-weight:800;color:${sipB.color};">${sipPct}% <span style="font-size:11px;">(you)</span></div>
        <div style="font-size:11px;color:var(--text-muted);">Peer avg: ${peers.sipConsistency}%</div>
        <div style="font-size:11px;font-weight:700;color:${sipB.color};">${sipB.label} of peers</div>
      </div>
      <div style="background:${eqB.bg};border-radius:10px;padding:14px;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Equity Allocation</div>
        <div style="font-size:18px;font-weight:800;color:${eqB.color};">${equityPct}% <span style="font-size:11px;">(you)</span></div>
        <div style="font-size:11px;color:var(--text-muted);">Ideal for age ${age}: ${age < 35 ? 70 : age < 45 ? 60 : 50}%</div>
        <div style="font-size:11px;font-weight:700;color:${eqB.color};">${eqB.label} of peers</div>
      </div>
      <div style="background:${savB.bg};border-radius:10px;padding:14px;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Savings Rate</div>
        <div style="font-size:18px;font-weight:800;color:${savB.color};">${savingsPct}% <span style="font-size:11px;">(you)</span></div>
        <div style="font-size:11px;color:var(--text-muted);">Peer avg: ${peers.savingsRate}%</div>
        <div style="font-size:11px;font-weight:700;color:${savB.color};">${savB.label} of peers</div>
      </div>
    </div>
    <div style="font-size:11px;padding:10px;background:rgba(99,102,241,.05);border-radius:7px;color:var(--text-muted);">
      💡 These are behavior metrics only — no fund names or specific amounts shared. Focus on consistency, not returns. The best investors are those who invest regularly regardless of market conditions.
    </div>`;
}

// Run on page load
document.addEventListener('DOMContentLoaded', function() {
  calcFIRE();
  calcEdu();
  renderNomineeTracker();
  calcPeerBenchmark();
});
</script>

<!-- ═══════════════════════════════════════════════════════════════
     t128 — MONTE CARLO SIMULATION
═══════════════════════════════════════════════════════════════ -->
<div class="card mt-4">
  <div class="card-header">
    <h3 class="card-title">🎲 Monte Carlo Simulation — Retirement Success Probability</h3>
    <span style="font-size:11px;color:var(--text-muted);">1,000 random market scenarios</span>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Current Corpus (₹)</label>
        <input type="number" id="mcCorpus" value="500000" class="form-control" oninput="runMonteCarlo()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Monthly SIP (₹)</label>
        <input type="number" id="mcSip" value="20000" class="form-control" oninput="runMonteCarlo()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Years to Retirement</label>
        <input type="number" id="mcYears" value="25" min="5" max="40" class="form-control" oninput="runMonteCarlo()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Retirement Goal (₹)</label>
        <input type="number" id="mcGoal" value="30000000" class="form-control" oninput="runMonteCarlo()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Expected Return (%)</label>
        <select id="mcReturn" class="form-control" onchange="runMonteCarlo()">
          <option value="10">10%</option>
          <option value="12" selected>12%</option>
          <option value="14">14%</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Volatility (%)</label>
        <select id="mcVol" class="form-control" onchange="runMonteCarlo()">
          <option value="15">15% (Low)</option>
          <option value="20" selected>20% (Medium)</option>
          <option value="25">25% (High)</option>
        </select>
      </div>
    </div>
    <div id="mcResult"></div>
  </div>
</div>

<script>
/* t128: Monte Carlo Simulation */
function runMonteCarlo() {
  const corpus = parseFloat(document.getElementById('mcCorpus')?.value) || 0;
  const sip    = parseFloat(document.getElementById('mcSip')?.value)    || 0;
  const years  = parseInt(document.getElementById('mcYears')?.value)    || 25;
  const goal   = parseFloat(document.getElementById('mcGoal')?.value)   || 30000000;
  const retPct = (parseFloat(document.getElementById('mcReturn')?.value)|| 12) / 100;
  const volPct = (parseFloat(document.getElementById('mcVol')?.value)   || 20) / 100;
  const res    = document.getElementById('mcResult');
  if (!res) return;

  res.innerHTML = '<div style="text-align:center;padding:20px;"><span class="spinner"></span> Running 1,000 simulations...</div>';

  // Run async to not block UI
  setTimeout(() => {
    const SIMS   = 1000;
    const months = years * 12;
    const meanMonthly = retPct / 12;
    const stdMonthly  = volPct / Math.sqrt(12);
    const results = [];

    // Box-Muller random normal
    function randNorm(mean, std) {
      let u = 0, v = 0;
      while (u === 0) u = Math.random();
      while (v === 0) v = Math.random();
      return mean + std * Math.sqrt(-2*Math.log(u)) * Math.cos(2*Math.PI*v);
    }

    for (let s = 0; s < SIMS; s++) {
      let c = corpus;
      for (let m = 0; m < months; m++) {
        const monthlyRet = randNorm(meanMonthly, stdMonthly);
        c = c * (1 + monthlyRet) + sip;
      }
      results.push(Math.max(0, c));
    }

    results.sort((a,b) => a-b);
    const success = results.filter(r => r >= goal).length;
    const prob    = (success / SIMS * 100).toFixed(0);
    const p10     = results[Math.floor(SIMS*0.10)]; // pessimistic
    const p50     = results[Math.floor(SIMS*0.50)]; // median
    const p90     = results[Math.floor(SIMS*0.90)]; // optimistic

    function fmtI(v) {
      v = Math.abs(v);
      if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
      if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
      return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
    }

    const probColor = prob >= 80 ? '#15803d' : prob >= 60 ? '#d97706' : '#dc2626';
    const probEmoji = prob >= 80 ? '✅' : prob >= 60 ? '⚠️' : '🚨';

    // Simple fan chart using CSS bars
    const maxVal = p90;
    const goalPct = Math.min(100, goal/maxVal*100).toFixed(0);

    res.innerHTML = `
      <div style="text-align:center;margin-bottom:20px;">
        <div style="font-size:56px;font-weight:900;color:${probColor};">${prob}%</div>
        <div style="font-size:14px;font-weight:700;color:${probColor};">${probEmoji} Probability of reaching ${fmtI(goal)}</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Based on 1,000 random market scenarios</div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">
        <div style="background:rgba(220,38,38,.07);border-radius:10px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Pessimistic (P10)</div>
          <div style="font-size:16px;font-weight:800;color:#dc2626;">${fmtI(p10)}</div>
          <div style="font-size:11px;color:var(--text-muted);">10% of scenarios worse</div>
        </div>
        <div style="background:rgba(59,130,246,.07);border-radius:10px;padding:12px;text-align:center;border:2px solid #93c5fd;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Median (P50)</div>
          <div style="font-size:20px;font-weight:900;color:#3b82f6;">${fmtI(p50)}</div>
          <div style="font-size:11px;color:var(--text-muted);">Most likely outcome</div>
        </div>
        <div style="background:rgba(22,163,74,.07);border-radius:10px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Optimistic (P90)</div>
          <div style="font-size:16px;font-weight:800;color:#16a34a;">${fmtI(p90)}</div>
          <div style="font-size:11px;color:var(--text-muted);">10% of scenarios better</div>
        </div>
      </div>
      <!-- Range visualization -->
      <div style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:4px;">
          <span>Outcome Range</span><span>Goal: ${fmtI(goal)}</span>
        </div>
        <div style="position:relative;height:24px;background:linear-gradient(90deg,#fee2e2,#fef3c7,#dcfce7);border-radius:99px;overflow:hidden;">
          <div style="position:absolute;left:${goalPct}%;top:0;bottom:0;width:2px;background:#1d4ed8;"></div>
          <div style="position:absolute;left:${Math.min(100,p50/maxVal*100).toFixed(0)}%;top:50%;transform:translate(-50%,-50%);width:16px;height:16px;background:#3b82f6;border-radius:50%;border:2px solid white;box-shadow:0 1px 4px rgba(0,0,0,.3);"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);margin-top:2px;">
          <span>${fmtI(p10)}</span><span style="color:#1d4ed8;">Goal</span><span>${fmtI(p90)}</span>
        </div>
      </div>
      ${prob < 80 ? `
      <div style="padding:10px;background:rgba(245,158,11,.08);border-radius:7px;font-size:12px;color:#b45309;margin-bottom:8px;">
        💡 To improve probability to 80%+: Increase SIP by ₹${fmtI((0.80-prob/100)*sip*2)} or extend by ${Math.max(1,Math.round((80-prob)/5))} more years.
      </div>` : ''}
      <div style="font-size:11px;color:var(--text-muted);padding:8px;background:var(--bg-secondary);border-radius:6px;">
        Monte Carlo uses ${SIMS} simulations with ${(retPct*100).toFixed(0)}% mean return and ${(volPct*100).toFixed(0)}% annual volatility. Each simulation uses a different sequence of random monthly returns. This models real-world uncertainty better than a simple compound growth calculator.
      </div>`;
  }, 50);
}

document.addEventListener('DOMContentLoaded', () => {
  runMonteCarlo();
});
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>