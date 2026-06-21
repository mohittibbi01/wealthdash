<?php
/**
 * WealthDash — tg003: Retirement Corpus Calculator Page
 * File: pages/goal/retirement_calculator.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle    = 'Retirement Calculator';
$activePage   = 'goal';
$activeSection= 'goal';
ob_start();
?>
<div class="page-header">
  <h1 class="page-title">🏖️ Retirement Corpus Calculator</h1>
  <p class="page-subtitle">Plan how much corpus you need to retire comfortably.</p>
</div>

<div style="display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start;" class="responsive-grid-1col">

  <!-- INPUT PANEL -->
  <div class="card" style="position:sticky;top:80px;">
    <div class="card-header"><span class="card-title">⚙️ Your Details</span></div>
    <div class="card-body">

      <div class="form-group">
        <label class="form-label">Current Age</label>
        <div style="display:flex;align-items:center;gap:10px;">
          <input type="range" id="rc-current-age" min="20" max="60" value="30" class="form-range" oninput="RC.syncRange(this,'rc-current-age-val')">
          <span id="rc-current-age-val" style="min-width:36px;font-weight:700;font-size:15px;">30</span>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Retirement Age</label>
        <div style="display:flex;align-items:center;gap:10px;">
          <input type="range" id="rc-retire-age" min="40" max="70" value="60" class="form-range" oninput="RC.syncRange(this,'rc-retire-age-val')">
          <span id="rc-retire-age-val" style="min-width:36px;font-weight:700;font-size:15px;">60</span>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Life Expectancy</label>
        <div style="display:flex;align-items:center;gap:10px;">
          <input type="range" id="rc-life-exp" min="70" max="100" value="85" class="form-range" oninput="RC.syncRange(this,'rc-life-exp-val')">
          <span id="rc-life-exp-val" style="min-width:36px;font-weight:700;font-size:15px;">85</span>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Monthly Expenses Today (₹)</label>
        <input type="number" id="rc-expenses" class="form-control" value="50000" min="1000" step="1000">
      </div>

      <div class="form-group">
        <label class="form-label">Existing Corpus / Investments (₹)</label>
        <input type="number" id="rc-existing" class="form-control" value="0" min="0" step="10000">
      </div>

      <div class="form-group">
        <label class="form-label">Current Monthly SIP / Investment (₹)</label>
        <input type="number" id="rc-sip" class="form-control" value="10000" min="0" step="500">
      </div>

      <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:4px;">
        <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px;">Assumptions</div>

        <div class="form-group">
          <label class="form-label">Inflation Rate (%)</label>
          <div style="display:flex;align-items:center;gap:10px;">
            <input type="range" id="rc-inflation" min="3" max="12" value="6" step="0.5" class="form-range" oninput="RC.syncRange(this,'rc-inflation-val')">
            <span id="rc-inflation-val" style="min-width:40px;font-weight:700;font-size:15px;">6%</span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Pre-Retirement Return (%)</label>
          <div style="display:flex;align-items:center;gap:10px;">
            <input type="range" id="rc-pre-return" min="6" max="18" value="12" step="0.5" class="form-range" oninput="RC.syncRange(this,'rc-pre-return-val')">
            <span id="rc-pre-return-val" style="min-width:40px;font-weight:700;font-size:15px;">12%</span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Post-Retirement Return (%)</label>
          <div style="display:flex;align-items:center;gap:10px;">
            <input type="range" id="rc-post-return" min="4" max="12" value="7" step="0.5" class="form-range" oninput="RC.syncRange(this,'rc-post-return-val')">
            <span id="rc-post-return-val" style="min-width:40px;font-weight:700;font-size:15px;">7%</span>
          </div>
        </div>
      </div>

      <button class="btn btn-primary" style="width:100%;margin-top:8px;" onclick="RC.calculate()">Calculate 🚀</button>

      <div style="display:flex;gap:8px;margin-top:10px;">
        <button class="btn btn-secondary btn-sm" style="flex:1;" onclick="RC.savePlan()">💾 Save Plan</button>
        <button class="btn btn-ghost btn-sm" style="flex:1;" onclick="RC.loadPlans()">📂 Load</button>
      </div>
    </div>
  </div>

  <!-- RESULTS PANEL -->
  <div>
    <!-- Key metrics -->
    <div id="rc-results-cards" style="display:none;">
      <div class="dashboard-grid" id="rc-cards" style="margin-bottom:20px;"></div>

      <!-- On-track banner -->
      <div id="rc-track-banner" class="alert" style="margin-bottom:20px;font-size:14px;font-weight:600;"></div>

      <!-- Corpus chart -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><span class="card-title">📈 Corpus Growth Projection</span></div>
        <div class="card-body" style="height:280px;"><canvas id="rc-chart"></canvas></div>
      </div>

      <!-- Breakdown table -->
      <div class="card">
        <div class="card-header"><span class="card-title">📋 Summary</span></div>
        <div class="card-body">
          <div id="rc-summary-table"></div>
        </div>
      </div>
    </div>

    <div id="rc-empty" class="card">
      <div class="card-body">
        <div class="empty-state">
          <div class="empty-icon">🏖️</div>
          <div>Fill in your details and click <strong>Calculate</strong> to see your retirement plan.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Saved plans modal -->
<div id="rc-plans-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)document.getElementById('rc-plans-modal').style.display='none'">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <span class="modal-title">📂 Saved Plans</span>
      <button class="modal-close" onclick="document.getElementById('rc-plans-modal').style.display='none'">×</button>
    </div>
    <div class="modal-body" id="rc-plans-list"></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const RC = {
  _chart: null,
  _lastResult: null,

  syncRange(el, valId) {
    const suffix = el.id.includes('inflation') || el.id.includes('return') ? '%' : '';
    document.getElementById(valId).textContent = el.value + suffix;
    this._debounceCalc();
  },

  _debTimer: null,
  _debounceCalc() {
    clearTimeout(this._debTimer);
    this._debTimer = setTimeout(() => this.calculate(), 600);
  },

  _inputs() {
    return {
      current_age:     document.getElementById('rc-current-age').value,
      retirement_age:  document.getElementById('rc-retire-age').value,
      life_expectancy: document.getElementById('rc-life-exp').value,
      monthly_expenses:document.getElementById('rc-expenses').value,
      existing_corpus: document.getElementById('rc-existing').value,
      monthly_sip:     document.getElementById('rc-sip').value,
      inflation_rate:  document.getElementById('rc-inflation').value,
      pre_return_rate: document.getElementById('rc-pre-return').value,
      post_return_rate:document.getElementById('rc-post-return').value,
    };
  },

  calculate() {
    apiPost({ action: 'retirement_calculate', ...this._inputs() }).then(r => {
      if (!r.ok) { showToast(r.message || 'Error', 'error'); return; }
      this._lastResult = r.data;
      this._render(r.data);
    });
  },

  _render(d) {
    document.getElementById('rc-empty').style.display           = 'none';
    document.getElementById('rc-results-cards').style.display   = '';

    // Cards
    document.getElementById('rc-cards').innerHTML = `
      <div class="stat-card">
        <div class="stat-label">Required Corpus</div>
        <div class="stat-value wd-num-xl">${formatINR(d.required_corpus)}</div>
        <div class="stat-sub">At retirement age</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Projected Corpus</div>
        <div class="stat-value wd-num-xl ${d.on_track?'wd-gain':'wd-loss'}">${formatINR(d.projected_corpus)}</div>
        <div class="stat-sub">Existing + SIP growth</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">SIP Needed (to fill gap)</div>
        <div class="stat-value wd-num-xl">${d.sip_needed > 0 ? formatINR(d.sip_needed)+'/mo' : '—'}</div>
        <div class="stat-sub">${d.sip_needed > 0 ? 'Additional monthly investment' : 'Already on track!'}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Monthly Expenses at Retirement</div>
        <div class="stat-value wd-num-xl">${formatINR(d.monthly_at_retirement)}</div>
        <div class="stat-sub">Inflation-adjusted (×${d.inflation_factor})</div>
      </div>`;

    // Banner
    const banner = document.getElementById('rc-track-banner');
    if (d.on_track) {
      banner.className = 'alert alert-success';
      banner.innerHTML = `✅ You are ON TRACK! Projected surplus: <strong>${formatINR(Math.abs(d.surplus_deficit))}</strong>`;
    } else {
      banner.className = 'alert alert-danger';
      banner.innerHTML = `⚠️ Corpus shortfall of <strong>${formatINR(Math.abs(d.surplus_deficit))}</strong>. Increase SIP by <strong>${formatINR(d.sip_needed)}/month</strong>.`;
    }

    // Chart
    const labels = d.yearly_data.map(y => 'Age ' + y.year);
    const corpus = d.yearly_data.map(y => y.corpus);
    const reqLine = d.yearly_data.map(() => d.required_corpus);
    if (this._chart) this._chart.destroy();
    this._chart = new Chart(document.getElementById('rc-chart'), {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Projected Corpus', data: corpus, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.08)', fill: true, tension: 0.4, borderWidth: 2 },
          { label: 'Required Corpus',  data: reqLine, borderColor: '#dc2626', borderDash: [6,3], fill: false, tension: 0, borderWidth: 2, pointRadius: 0 },
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { labels: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim() } },
          tooltip: { callbacks: { label: c => ` ${c.dataset.label}: ${formatINR(c.raw)}` } }
        },
        scales: {
          x: { ticks: { color: '#6b7280', maxTicksLimit: 10 }, grid: { display: false } },
          y: { ticks: { color: '#6b7280', callback: v => formatINR(v, 0) }, grid: { color: 'var(--border)' } }
        }
      }
    });

    // Summary table
    document.getElementById('rc-summary-table').innerHTML = `
      <table class="data-table">
        <tbody>
          <tr><td>Years to Retirement</td><td class="text-right wd-num"><strong>${d.years_to_retirement} years</strong></td></tr>
          <tr><td>Retirement Duration</td><td class="text-right wd-num">${d.retirement_duration} years</td></tr>
          <tr><td>FV of Existing Corpus</td><td class="text-right wd-num">${formatINR(d.fv_existing)}</td></tr>
          <tr><td>FV of Monthly SIP</td><td class="text-right wd-num">${formatINR(d.fv_sip)}</td></tr>
          <tr><td><strong>Required Corpus</strong></td><td class="text-right wd-num"><strong>${formatINR(d.required_corpus)}</strong></td></tr>
          <tr><td><strong>Projected Corpus</strong></td><td class="text-right wd-num ${d.on_track?'wd-gain':'wd-loss'}"><strong>${formatINR(d.projected_corpus)}</strong></td></tr>
          <tr><td>${d.surplus_deficit >= 0 ? 'Surplus' : 'Deficit'}</td>
              <td class="text-right wd-num ${d.surplus_deficit >= 0 ?'wd-gain':'wd-loss'}"><strong>${formatINR(Math.abs(d.surplus_deficit))}</strong></td></tr>
        </tbody>
      </table>`;
  },

  savePlan() {
    if (!this._lastResult) { showToast('Calculate first', 'warning'); return; }
    const name = prompt('Plan name:', 'My Retirement Plan');
    if (!name) return;
    apiPost({
      action: 'retirement_save_plan',
      plan_name: name,
      inputs: JSON.stringify(this._inputs()),
      results: JSON.stringify(this._lastResult),
    }).then(r => showToast(r.message, r.ok ? 'success' : 'error'));
  },

  loadPlans() {
    apiPost({ action: 'retirement_load_plan' }).then(r => {
      if (!r.ok) return;
      const plans = r.data.plans || [];
      const modal = document.getElementById('rc-plans-modal');
      const list  = document.getElementById('rc-plans-list');
      if (!plans.length) {
        list.innerHTML = '<div class="empty-state"><div>No saved plans.</div></div>';
      } else {
        list.innerHTML = plans.map(p => `
          <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);">
            <div>
              <div style="font-weight:600;font-size:14px;">${esc(p.plan_name)}</div>
              <div style="font-size:12px;color:var(--text-muted);">Updated: ${esc(p.updated_at?.substring(0,10))}</div>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn btn-secondary btn-sm" onclick="RC.applyPlan(${p.id})">Load</button>
              <button class="btn btn-danger btn-sm" onclick="RC.deletePlan(${p.id})">✕</button>
            </div>
          </div>`).join('');
      }
      modal.style.display = '';
    });
  },

  applyPlan(id) {
    apiPost({ action: 'retirement_load_plan', plan_id: id }).then(r => {
      if (!r.ok || !r.data.plan) return;
      const inp = r.data.plan.inputs;
      document.getElementById('rc-current-age').value    = inp.current_age;
      document.getElementById('rc-retire-age').value     = inp.retirement_age;
      document.getElementById('rc-life-exp').value       = inp.life_expectancy;
      document.getElementById('rc-expenses').value       = inp.monthly_expenses;
      document.getElementById('rc-existing').value       = inp.existing_corpus;
      document.getElementById('rc-sip').value            = inp.monthly_sip;
      document.getElementById('rc-inflation').value      = inp.inflation_rate;
      document.getElementById('rc-pre-return').value     = inp.pre_return_rate;
      document.getElementById('rc-post-return').value    = inp.post_return_rate;
      // sync labels
      ['rc-current-age','rc-retire-age','rc-life-exp','rc-inflation','rc-pre-return','rc-post-return'].forEach(id => {
        const el = document.getElementById(id);
        const valEl = document.getElementById(id+'-val');
        if (el && valEl) {
          const suffix = id.includes('inflation') || id.includes('return') ? '%' : '';
          valEl.textContent = el.value + suffix;
        }
      });
      document.getElementById('rc-plans-modal').style.display = 'none';
      this.calculate();
    });
  },

  deletePlan(id) {
    if (!confirm('Delete this plan?')) return;
    apiPost({ action: 'retirement_delete_plan', plan_id: id }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
      if (r.ok) this.loadPlans();
    });
  }
};

document.addEventListener('DOMContentLoaded', () => RC.calculate());
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
