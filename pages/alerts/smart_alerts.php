<?php
/**
 * WealthDash — t404: Smart Alerts v2 Page
 * File: pages/alerts/smart_alerts.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Smart Alerts'; $activePage='alerts'; $activeSection='alerts';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🔔 Smart Alerts</h1><p class="page-subtitle">Context-aware notifications — SIP, EMI, insurance, portfolio moves.</p></div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-ghost btn-sm" onclick="SA.openSettings()">⚙️ Preferences</button>
    <button class="btn btn-ghost btn-sm" onclick="SA.dismissAll()">✓ Mark All Read</button>
    <button class="btn btn-primary" onclick="SA.check()">🔄 Check Now</button>
  </div>
</div>

<div id="sa-list"></div>

<!-- Settings Modal -->
<div id="sa-settings-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header"><span class="modal-title">⚙️ Alert Preferences</span><button class="modal-close" onclick="document.getElementById('sa-settings-modal').style.display='none'">×</button></div>
    <div class="modal-body" id="sa-settings-body"></div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="document.getElementById('sa-settings-modal').style.display='none'">Cancel</button><button class="btn btn-primary" onclick="SA.saveSettings()">Save</button></div>
  </div>
</div>

<style>
.sa-alert{display:flex;gap:14px;align-items:flex-start;background:var(--bg-surface);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:10px;}
.sa-alert.unread{border-left:4px solid var(--accent);}
.sa-alert.severity-high{border-left-color:#dc2626;}
.sa-alert.severity-medium{border-left-color:#eab308;}
.sa-alert.severity-low{border-left-color:#16a34a;}
.sa-icon{font-size:1.6rem;flex-shrink:0;}
</style>

<script>
const SA={
  _settingsLabels:{sip_due:'🔁 SIP Due Reminders',insurance_due:'🛡 Insurance Premium Due',loan_emi_due:'🏦 Loan EMI Due',drawdown_alert:'📉 Portfolio Drawdown',gain_alert:'🎉 Large Gain Alerts',goal_milestone:'🎯 Goal Milestones'},

  init(){this.load();},

  check(){
    apiPost({action:'smart_alerts_check'}).then(r=>{
      if(!r.ok)return;
      showToast(`${r.data.new_alerts} new alert(s) found`,'success');
      this.load();
    });
  },

  load(){
    apiPost({action:'smart_alerts_list'}).then(r=>{
      if(!r.ok)return;
      const rows=r.data.alerts||[];
      const wrap=document.getElementById('sa-list');
      if(!rows.length){wrap.innerHTML='<div class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">🔔</div><div>No alerts. Click "Check Now" to scan for updates.</div></div></div></div>';return;}
      wrap.innerHTML=rows.map(a=>`
        <div class="sa-alert ${a.is_read?'':'unread'} severity-${esc(a.severity)}">
          <div class="sa-icon">${a.icon}</div>
          <div style="flex:1;">
            <div style="font-size:14px;">${esc(a.message)}</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">${esc(a.created_at?.substring(0,16))} · <span style="text-transform:capitalize;">${esc(a.alert_type.replace('_',' '))}</span></div>
          </div>
          ${a.is_read?'':`<button class="btn btn-ghost btn-sm" onclick="SA.dismiss(${a.id})">✓</button>`}
        </div>`).join('');
    });
  },

  dismiss(id){apiPost({action:'smart_alert_dismiss',id}).then(r=>{if(r.ok)this.load();});},
  dismissAll(){apiPost({action:'smart_alert_dismiss',all:1}).then(r=>{showToast('All marked read','success');this.load();});},

  openSettings(){
    apiPost({action:'smart_alert_settings_get'}).then(r=>{
      const s=r.data.settings||{};
      let html='';
      for(const [key,label] of Object.entries(this._settingsLabels)){
        const checked=(s[key]===undefined||s[key]==1)?'checked':'';
        html+=`<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
          <span style="font-size:13px;">${label}</span>
          <label class="toggle-switch" style="position:relative;width:44px;height:24px;">
            <input type="checkbox" id="sa-set-${key}" ${checked} style="opacity:0;width:0;height:0;">
            <span class="toggle-slider" style="position:absolute;inset:0;background:${checked?'var(--accent)':'#d1d5db'};border-radius:24px;cursor:pointer;" onclick="const cb=document.getElementById('sa-set-${key}');cb.checked=!cb.checked;this.style.background=cb.checked?'var(--accent)':'#d1d5db';this.querySelector('.dot').style.transform=cb.checked?'translateX(20px)':'translateX(0)';">
              <span class="dot" style="position:absolute;height:18px;width:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;transform:${checked?'translateX(20px)':'translateX(0)'};"></span>
            </span>
          </label>
        </div>`;
      }
      document.getElementById('sa-settings-body').innerHTML=html;
      document.getElementById('sa-settings-modal').style.display='';
    });
  },

  saveSettings(){
    const data={action:'smart_alert_settings_save'};
    for(const key of Object.keys(this._settingsLabels)){
      data[key]=document.getElementById('sa-set-'+key).checked?1:0;
    }
    apiPost(data).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)document.getElementById('sa-settings-modal').style.display='none';});
  }
};
document.addEventListener('DOMContentLoaded',()=>SA.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
