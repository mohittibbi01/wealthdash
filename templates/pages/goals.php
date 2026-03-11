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
    <button class="btn btn-primary" id="btnAddGoal">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Goal
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

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>

