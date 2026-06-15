<?php
/**
 * WealthDash — t443: Morning Briefing Page
 * File: pages/dashboard/morning_briefing.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Morning Briefing'; $activePage='dashboard'; $activeSection='dashboard';
ob_start();
?>
<div class="page-header"><h1 class="page-title">☀️ Morning Briefing</h1><p class="page-subtitle">Your daily market + portfolio digest.</p></div>

<div id="mb-loading" style="text-align:center;padding:40px;"><div style="font-size:2.5rem;">☀️</div><div style="color:var(--text-muted);margin-top:8px;">Loading your briefing…</div></div>

<div id="mb-content" style="display:none;">
  <!-- Hero -->
  <div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,#f59e0b 0%,#ef4444 100%);border:none;">
    <div class="card-body" style="padding:28px;">
      <div id="mb-greeting" style="font-size:22px;font-weight:800;color:#fff;margin-bottom:4px;"></div>
      <div id="mb-date" style="font-size:13px;color:rgba(255,255,255,.8);margin-bottom:14px;"></div>
      <div id="mb-narrative" style="font-size:14px;color:#fff;line-height:1.7;"></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" class="responsive-grid-1col">
    <!-- Market -->
    <div class="card">
      <div class="card-header"><span class="card-title">📡 Market Snapshot</span></div>
      <div class="card-body" id="mb-market"></div>
    </div>

    <!-- Today's items -->
    <div class="card">
      <div class="card-header"><span class="card-title">📋 Today</span></div>
      <div class="card-body" id="mb-today"></div>
    </div>
  </div>

  <div style="text-align:center;margin-top:16px;">
    <button class="btn btn-ghost btn-sm" onclick="MB.load(true)">🔄 Refresh Briefing</button>
  </div>
</div>

<script>
const MB={
  load(force=false){
    document.getElementById('mb-loading').style.display='';
    document.getElementById('mb-content').style.display='none';
    apiPost({action:'morning_briefing_get',force:force?1:0}).then(r=>{
      document.getElementById('mb-loading').style.display='none';
      if(!r.ok){showToast(r.message,'error');return;}
      this._render(r.data);
    });
  },
  _render(d){
    document.getElementById('mb-content').style.display='';
    document.getElementById('mb-greeting').textContent=`${d.greeting}! ☀️`;
    document.getElementById('mb-date').textContent=d.date+(d._cached?' · 📦 cached':'');
    document.getElementById('mb-narrative').textContent=d.narrative;

    // Market
    const mkt=d.market||[];
    document.getElementById('mb-market').innerHTML=mkt.length
      ? mkt.map(m=>`<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);"><span style="font-weight:600;">${esc(m.name)}</span><span><strong>${esc(m.value)}</strong> <span class="${m.change_pct>=0?'wd-gain':'wd-loss'}">${m.change_pct>=0?'+':''}${m.change_pct}%</span></span></div>`).join('')
      : '<div style="color:var(--text-muted);font-size:13px;">Market data unavailable right now.</div>';

    // Today's items
    let th=`<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);"><span>💰 Portfolio Value</span><strong>${formatINR(d.portfolio.value)}</strong></div>`;
    th+=`<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);"><span>📈 Total Return</span><strong class="${d.portfolio.gain_pct>=0?'wd-gain':'wd-loss'}">${d.portfolio.gain_pct>=0?'+':''}${d.portfolio.gain_pct}%</strong></div>`;
    if(d.sips_today.length){
      th+=`<div style="padding:8px 0;border-bottom:1px solid var(--border);"><div>🔁 SIPs Today (${d.sips_today.length})</div>`;
      for(const s of d.sips_today){th+=`<div style="font-size:12px;color:var(--text-muted);padding-left:20px;">${esc(s.fund_name)}: ${formatINR(s.sip_amount)}</div>`;}
      th+='</div>';
    }
    th+=`<div style="display:flex;justify-content:space-between;padding:8px 0;"><span>🔔 Unread Alerts</span><strong>${d.alerts_count}</strong></div>`;
    document.getElementById('mb-today').innerHTML=th;
  }
};
document.addEventListener('DOMContentLoaded',()=>MB.load(false));
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
