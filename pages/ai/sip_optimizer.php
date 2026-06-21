<?php
/**
 * WealthDash — t331: AI SIP Optimizer Page
 * File: pages/ai/sip_optimizer.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI SIP Optimizer'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📈 AI SIP Optimizer</h1><p class="page-subtitle">Smart SIP allocation based on your risk profile and goals.</p></div>
  <button class="btn btn-primary" id="so-btn" onclick="SO.run()">✨ Optimize My SIPs</button>
</div>

<div id="so-loading" style="display:none;text-align:center;padding:60px;"><div style="font-size:3rem;">⚙️</div><div style="margin-top:12px;color:var(--text-muted);">Analysing your SIP portfolio…</div></div>

<div id="so-results" style="display:none;">
  <!-- Summary cards -->
  <div class="dashboard-grid" id="so-cards" style="margin-bottom:20px;"></div>

  <!-- AI Narrative -->
  <div id="so-narrative-wrap" class="card" style="margin-bottom:20px;display:none;">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;">
      <span class="card-title">🤖 AI Analysis</span><span id="so-mode" class="badge"></span>
    </div>
    <div class="card-body"><div id="so-narrative" style="font-size:14px;line-height:1.7;white-space:pre-wrap;"></div></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-grid-1col">
    <!-- Allocation chart -->
    <div class="card">
      <div class="card-header"><span class="card-title">📊 Current vs Ideal Allocation</span></div>
      <div class="card-body p-0" id="so-alloc-table"></div>
    </div>
    <!-- Recommendations -->
    <div class="card">
      <div class="card-header"><span class="card-title">💡 Optimization Suggestions</span></div>
      <div class="card-body p-0" id="so-recs-list"></div>
    </div>
  </div>

  <!-- Current SIPs table -->
  <div class="card">
    <div class="card-header"><span class="card-title">📋 Current SIPs</span></div>
    <div class="card-body p-0"><div id="so-sips-table"></div></div>
  </div>
</div>

<div id="so-empty" class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">📈</div><div>Click "Optimize My SIPs" to get AI-powered recommendations.</div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const SO = {
  run() {
    document.getElementById('so-btn').disabled = true;
    document.getElementById('so-loading').style.display = '';
    document.getElementById('so-results').style.display = 'none';
    document.getElementById('so-empty').style.display = 'none';
    apiPost({action:'ai_sip_optimize'}).then(r => {
      document.getElementById('so-loading').style.display = '';
      document.getElementById('so-btn').disabled = false;
      document.getElementById('so-loading').style.display = 'none';
      if (!r.ok) { showToast(r.message, 'error'); document.getElementById('so-empty').style.display = ''; return; }
      this._render(r.data);
    }).catch(() => { document.getElementById('so-loading').style.display='none'; document.getElementById('so-btn').disabled=false; });
  },
  _render(d) {
    document.getElementById('so-results').style.display = '';
    const srColor = d.savings_rate_ok ? 'wd-gain' : 'wd-loss';
    document.getElementById('so-cards').innerHTML = `
      <div class="stat-card"><div class="stat-label">Monthly SIP Total</div><div class="stat-value wd-num-xl">${formatINR(d.total_sip)}<span style="font-size:12px;font-weight:400;">/mo</span></div><div class="stat-sub">${d.sip_count} active SIPs</div></div>
      <div class="stat-card"><div class="stat-label">Savings Rate</div><div class="stat-value wd-num-xl ${srColor}">${d.savings_rate_pct}%</div><div class="stat-sub">${d.savings_rate_ok ? '✅ Good (≥20%)' : '⚠️ Target: 20%+ of income'}</div></div>
      <div class="stat-card"><div class="stat-label">Annual Step-Up Suggestion</div><div class="stat-value wd-num-xl">${formatINR(d.step_up_suggestion)}<span style="font-size:12px;font-weight:400;">/mo</span></div><div class="stat-sub">10% increase this year</div></div>
      <div class="stat-card"><div class="stat-label">Risk Profile</div><div class="stat-value" style="font-size:18px;font-weight:700;text-transform:capitalize;">${esc(d.risk_profile)}</div><div class="stat-sub">${d.recommendations.length} gaps found</div></div>`;

    // AI narrative
    if (d.ai_narrative) {
      document.getElementById('so-narrative-wrap').style.display = '';
      document.getElementById('so-narrative').textContent = d.ai_narrative;
      document.getElementById('so-mode').textContent = d.mode === 'ai' ? '🤖 AI' : '📊 Rule-based';
    }

    // Allocation comparison table
    const allCats = new Set([...Object.keys(d.current_alloc_pct), ...Object.keys(d.ideal_alloc_pct)]);
    let ah = `<table class="data-table"><thead><tr><th>Category</th><th class="text-right">Current</th><th class="text-right">Ideal</th><th class="text-right">Gap</th></tr></thead><tbody>`;
    for (const cat of allCats) {
      const curr = d.current_alloc_pct[cat] ?? 0;
      const ideal = d.ideal_alloc_pct[cat] ?? 0;
      const gap = ideal - curr;
      const gapColor = Math.abs(gap) > 8 ? (gap > 0 ? 'wd-loss' : 'wd-gain') : '';
      ah += `<tr><td style="font-size:13px;">${esc(cat)}</td><td class="text-right wd-num">${curr}%</td><td class="text-right wd-num">${ideal}%</td><td class="text-right wd-num ${gapColor}">${gap > 0 ? '+' : ''}${gap.toFixed(1)}%</td></tr>`;
    }
    ah += '</tbody></table>';
    document.getElementById('so-alloc-table').innerHTML = ah;

    // Recommendations
    const recs = d.recommendations || [];
    if (!recs.length) {
      document.getElementById('so-recs-list').innerHTML = '<div class="empty-state" style="padding:20px;"><div>✅ SIP allocation looks balanced!</div></div>';
    } else {
      let rh = '';
      for (const rec of recs) {
        const icon = rec.action === 'increase' ? '⬆️' : '⬇️';
        const cls  = rec.action === 'increase' ? 'alert-info' : 'alert-warning';
        rh += `<div class="alert ${cls}" style="margin:8px 12px;font-size:13px;padding:10px 14px;">
          <div style="font-weight:600;">${icon} ${esc(rec.category)}</div>
          <div>${esc(rec.suggestion)}</div>
          <div style="font-size:11px;margin-top:4px;">Current: ${rec.current_pct}% → Ideal: ${rec.ideal_pct}%</div>
        </div>`;
      }
      document.getElementById('so-recs-list').innerHTML = rh;
    }

    // SIPs table
    if (!d.sips.length) {
      document.getElementById('so-sips-table').innerHTML = '<div class="empty-state" style="padding:20px;"><div>No active SIPs found.</div></div>';
    } else {
      let st = `<div class="table-responsive"><table class="data-table"><thead><tr><th>Fund</th><th class="text-right">SIP/mo</th><th>Frequency</th><th>Date</th><th class="text-right">Current Value</th></tr></thead><tbody>`;
      for (const s of d.sips) {
        st += `<tr><td style="font-size:13px;">${esc(s.fund_name)}</td><td class="text-right wd-num">${formatINR(s.sip_amount)}</td><td style="text-transform:capitalize;font-size:12px;">${esc(s.sip_frequency||'Monthly')}</td><td style="font-size:12px;">${esc(s.sip_date||'—')}</td><td class="text-right wd-num">${s.current_value?formatINR(s.current_value):'—'}</td></tr>`;
      }
      st += '</tbody></table></div>';
      document.getElementById('so-sips-table').innerHTML = st;
    }
  }
};
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
