<?php
/**
 * WealthDash — t406: Anonymous Benchmarking Page
 * File: pages/benchmarking/peer_compare.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Peer Benchmarking'; $activePage='insights'; $activeSection='benchmarking';
ob_start();
?>
<div class="page-header"><h1 class="page-title">📊 Peer Benchmarking</h1><p class="page-subtitle">Anonymously compare your financial habits with similar investors.</p></div>

<!-- Privacy notice + opt-in -->
<div id="bm-optin-card" class="card" style="margin-bottom:20px;display:none;">
  <div class="card-body" style="text-align:center;padding:32px 20px;">
    <div style="font-size:3rem;margin-bottom:12px;">🔒</div>
    <h3 style="font-size:16px;margin-bottom:10px;">Privacy-First Benchmarking</h3>
    <p style="font-size:13px;color:var(--text-muted);max-width:480px;margin:0 auto 20px;line-height:1.6;">
      See how your savings rate, returns, and SIP habits compare to peers in your age group and risk profile —
      completely anonymously. We never share fund names, exact amounts, or any personally identifiable data.
      Comparisons only shown when 5+ peers exist in your cohort.
    </p>
    <button class="btn btn-primary" onclick="BM.optIn()">✅ Opt In to Benchmarking</button>
  </div>
</div>

<div id="bm-results" style="display:none;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <span id="bm-cohort-label" style="font-size:13px;color:var(--text-muted);"></span>
    <button class="btn btn-ghost btn-sm" onclick="BM.optOut()">Opt Out</button>
  </div>

  <div id="bm-not-enough" class="card" style="display:none;">
    <div class="card-body"><div class="empty-state"><div class="empty-icon">👥</div><div id="bm-not-enough-msg"></div></div></div>
  </div>

  <div id="bm-comparison" style="display:none;">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;"></div>
  </div>
</div>

<style>
.bm-compare-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:18px;}
.bm-compare-title{font-size:12px;color:var(--text-muted);margin-bottom:10px;}
.bm-compare-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;}
.bm-compare-bar{height:6px;background:var(--bg-secondary);border-radius:3px;overflow:hidden;margin-top:6px;}
.bm-you{color:var(--accent);font-weight:700;}
.bm-peer{color:var(--text-muted);}
</style>

<script>
const BM={
  init(){
    apiPost({action:'benchmark_status'}).then(r=>{
      if(!r.ok)return;
      if(r.data.opted_in)this.load();
      else document.getElementById('bm-optin-card').style.display='';
    });
  },
  optIn(){apiPost({action:'benchmark_opt_in'}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){document.getElementById('bm-optin-card').style.display='none';this.load();}});},
  optOut(){if(!confirm('Opt out of benchmarking? Your anonymized data will be removed.'))return;apiPost({action:'benchmark_opt_out'}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){document.getElementById('bm-results').style.display='none';document.getElementById('bm-optin-card').style.display='';}});},
  load(){
    document.getElementById('bm-results').style.display='';
    apiPost({action:'benchmark_compare'}).then(r=>{
      if(!r.ok){showToast(r.message,'error');return;}
      const d=r.data;
      if(!d.enough_data){
        document.getElementById('bm-not-enough').style.display='';
        document.getElementById('bm-comparison').style.display='none';
        document.getElementById('bm-not-enough-msg').textContent=d.message;
        return;
      }
      document.getElementById('bm-not-enough').style.display='none';
      document.getElementById('bm-comparison').style.display='';
      document.getElementById('bm-cohort-label').textContent=`Comparing with ${d.cohort_size} peers in: ${d.cohort_label}`;

      const metrics=[
        {key:'savings_rate',label:'Savings Rate',unit:'%',higher_better:true},
        {key:'gain_pct',label:'Portfolio Returns',unit:'%',higher_better:true},
        {key:'sip_pct_income',label:'SIP % of Income',unit:'%',higher_better:true},
        {key:'num_holdings',label:'Fund Diversification',unit:' funds',higher_better:true},
      ];

      const wrap=document.querySelector('#bm-comparison > div');
      wrap.innerHTML=metrics.map(m=>{
        const you=d.me[m.key]; const peer=d.peer_avg[m.key];
        const max=Math.max(you,peer,1);
        const youPct=Math.min(100,you/max*100); const peerPct=Math.min(100,peer/max*100);
        const better=m.higher_better?you>=peer:you<=peer;
        return `<div class="bm-compare-card">
          <div class="bm-compare-title">${esc(m.label)}</div>
          <div class="bm-compare-row"><span class="bm-you">You: ${you}${m.unit}</span>${better?'<span style="color:var(--gain);">✓ Above avg</span>':''}</div>
          <div class="bm-compare-bar"><div style="width:${youPct}%;height:100%;background:var(--accent);"></div></div>
          <div class="bm-compare-row" style="margin-top:8px;"><span class="bm-peer">Peer avg: ${peer}${m.unit}</span></div>
          <div class="bm-compare-bar"><div style="width:${peerPct}%;height:100%;background:var(--text-muted);"></div></div>
        </div>`;
      }).join('');
    });
  }
};
document.addEventListener('DOMContentLoaded',()=>BM.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
