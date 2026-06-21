<?php
/**
 * WealthDash — tc006: Cold Wallet Tracker Page
 * File: pages/crypto/cold_wallet.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Cold Wallet Tracker'; $activePage='crypto'; $activeSection='crypto';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🔐 Cold Wallet Tracker</h1><p class="page-subtitle">Track hardware & paper wallets — Ledger, Trezor, air-gapped.</p></div>
  <button class="btn btn-primary" onclick="CW.openAdd()">+ Add Wallet</button>
</div>
<div id="cw-summary" class="dashboard-grid" style="margin-bottom:24px;"></div>
<div id="cw-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:24px;"></div>
<div id="cw-holdings-panel" class="card" style="display:none;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <span class="card-title" id="cw-holdings-title">Holdings</span>
    <button class="btn btn-secondary btn-sm" onclick="CW.openAddHolding()">+ Add Coin</button>
  </div>
  <div class="card-body p-0"><div id="cw-holdings-table"></div></div>
</div>
<div id="cw-wallet-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)CW.closeAdd()">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header"><span class="modal-title">Add Cold Wallet</span><button class="modal-close" onclick="CW.closeAdd()">×</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Name *</label><input type="text" id="cw-w-name" class="form-control" placeholder="Ledger Primary"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label class="form-label">Type</label><select id="cw-w-type" class="form-control"><option value="hardware">Hardware</option><option value="paper">Paper</option><option value="mobile">Mobile</option><option value="air_gapped">Air-Gapped</option></select></div>
        <div class="form-group"><label class="form-label">Device</label><input type="text" id="cw-w-device" class="form-control" placeholder="Ledger Nano X"></div>
      </div>
      <div class="form-group"><label class="form-label">Address (optional)</label><input type="text" id="cw-w-address" class="form-control" placeholder="0x..."></div>
      <div class="form-group"><label class="form-label">Network</label><input type="text" id="cw-w-network" class="form-control" placeholder="BTC / ETH / Multi"></div>
      <div class="form-group"><label class="form-label">Notes</label><input type="text" id="cw-w-notes" class="form-control" placeholder="Stored in..."></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="CW.closeAdd()">Cancel</button><button class="btn btn-primary" onclick="CW.saveWallet()">Save</button></div>
  </div>
</div>
<div id="cw-holding-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)CW.closeHolding()">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header"><span class="modal-title">Add Coin Holding</span><button class="modal-close" onclick="CW.closeHolding()">×</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Coin *</label><input type="text" id="cw-h-coin" class="form-control" placeholder="BTC"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label class="form-label">Quantity</label><input type="number" id="cw-h-qty" class="form-control" step="any" min="0"></div>
        <div class="form-group"><label class="form-label">Buy Price (₹)</label><input type="number" id="cw-h-buy" class="form-control" step="any" min="0"></div>
      </div>
      <div class="form-group"><label class="form-label">Current Value (₹)</label><input type="number" id="cw-h-val" class="form-control" step="any" min="0" placeholder="Auto if blank"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="CW.closeHolding()">Cancel</button><button class="btn btn-primary" onclick="CW.saveHolding()">Save</button></div>
  </div>
</div>
<style>
.cw-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:16px;cursor:pointer;transition:border-color .2s;}
.cw-card:hover,.cw-card.active{border-color:var(--accent);}
.type-hardware{background:#dbeafe;color:#1d4ed8;}.type-paper{background:#fef9c3;color:#92400e;}.type-mobile{background:#dcfce7;color:#15803d;}.type-air_gapped{background:#f3e8ff;color:#6b21a8;}
.cw-type-badge{padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600;}
</style>
<script>
const CW={_wallets:[],_activeId:null,
init(){this.loadSummary();this.loadWallets();},
loadSummary(){apiPost({action:'cold_wallet_summary'}).then(r=>{if(!r.ok)return;const d=r.data;const pc=d.unrealised_pnl>=0?'wd-gain':'wd-loss';document.getElementById('cw-summary').innerHTML=`<div class="stat-card"><div class="stat-label">Total Value</div><div class="stat-value wd-num-xl">${formatINR(d.grand_total_inr)}</div><div class="stat-sub">${d.wallets.length} wallets</div></div><div class="stat-card"><div class="stat-label">Total Cost</div><div class="stat-value wd-num-xl">${formatINR(d.total_cost)}</div></div><div class="stat-card"><div class="stat-label">Unrealised P&L</div><div class="stat-value wd-num-xl ${pc}">${d.unrealised_pnl>=0?'+':''}${formatINR(d.unrealised_pnl)}</div></div>`;});},
loadWallets(){apiPost({action:'cold_wallet_list'}).then(r=>{if(!r.ok)return;this._wallets=r.data.wallets||[];this._renderWallets();});},
_icon(t){return{hardware:'🔑',paper:'📄',mobile:'📱',air_gapped:'🛡️'}[t]||'💼';},
_renderWallets(){const wrap=document.getElementById('cw-grid');if(!this._wallets.length){wrap.innerHTML='<div class="card" style="grid-column:1/-1;"><div class="card-body"><div class="empty-state"><div class="empty-icon">🔐</div><div>No wallets yet.</div></div></div></div>';return;}wrap.innerHTML=this._wallets.map(w=>`<div class="cw-card ${this._activeId===w.id?'active':''}" onclick="CW.select(${w.id})"><div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;"><div><div style="font-weight:700;">${this._icon(w.type)} ${esc(w.name)}</div><div style="font-size:12px;color:var(--text-muted);">${esc(w.device||w.type)}</div></div><div style="display:flex;gap:6px;align-items:center;"><span class="cw-type-badge type-${esc(w.type)}">${esc(w.type)}</span><button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();CW.del(${w.id})">✕</button></div></div><div style="display:flex;justify-content:space-between;"><span style="font-size:12px;color:var(--text-muted);">${w.coin_count} coin${w.coin_count!=1?'s':''}</span><span style="font-weight:700;">${formatINR(w.total_value_inr)}</span></div></div>`).join('');},
select(id){this._activeId=id;this._renderWallets();const w=this._wallets.find(x=>x.id===id);document.getElementById('cw-holdings-title').textContent='📦 '+(w?.name||'Wallet')+'  — Holdings';document.getElementById('cw-holdings-panel').style.display='';this.loadHoldings();document.getElementById('cw-holdings-panel').scrollIntoView({behavior:'smooth'});},
loadHoldings(){if(!this._activeId)return;apiPost({action:'cold_wallet_holdings_list',wallet_id:this._activeId}).then(r=>{if(!r.ok)return;const h=r.data.holdings||[];const wrap=document.getElementById('cw-holdings-table');if(!h.length){wrap.innerHTML='<div class="empty-state"><div class="empty-icon">🪙</div><div>No coins yet.</div></div>';return;}let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Coin</th><th class="text-right">Qty</th><th class="text-right">Buy ₹</th><th class="text-right">Value ₹</th><th class="text-right">P&L</th><th></th></tr></thead><tbody>`;for(const c of h){const cost=(+c.buy_price||0)*(+c.quantity||0);const pnl=(+c.value_inr||0)-cost;html+=`<tr><td style="font-weight:700;">🪙 ${esc(c.coin)}</td><td class="text-right wd-num">${(+c.quantity).toLocaleString('en-IN',{maximumFractionDigits:8})}</td><td class="text-right wd-num">${formatINR(c.buy_price)}</td><td class="text-right wd-num" style="font-weight:700;">${formatINR(c.value_inr)}</td><td class="text-right wd-num ${pnl>=0?'wd-gain':'wd-loss'}">${pnl>=0?'+':''}${formatINR(pnl)}</td><td><button class="btn btn-danger btn-sm" onclick="CW.delHolding(${c.id})">✕</button></td></tr>`;}html+='</tbody></table></div>';wrap.innerHTML=html;});},
openAdd(){['cw-w-name','cw-w-device','cw-w-address','cw-w-network','cw-w-notes'].forEach(i=>document.getElementById(i).value='');document.getElementById('cw-wallet-modal').style.display='';},
closeAdd(){document.getElementById('cw-wallet-modal').style.display='none';},
saveWallet(){const name=document.getElementById('cw-w-name').value.trim();if(!name){showToast('Name required','warning');return;}apiPost({action:'cold_wallet_add',name,type:document.getElementById('cw-w-type').value,device:document.getElementById('cw-w-device').value,address:document.getElementById('cw-w-address').value,network:document.getElementById('cw-w-network').value,notes:document.getElementById('cw-w-notes').value}).then(r=>{if(!r.ok){showToast(r.message,'error');return;}showToast('Added!','success');this.closeAdd();this.loadWallets();this.loadSummary();});},
del(id){if(!confirm('Delete wallet and all holdings?'))return;apiPost({action:'cold_wallet_delete',wallet_id:id}).then(r=>{showToast(r.message,r.ok?'info':'error');if(r.ok){if(this._activeId===id){this._activeId=null;document.getElementById('cw-holdings-panel').style.display='none';}this.loadWallets();this.loadSummary();}});},
openAddHolding(){if(!this._activeId){showToast('Select wallet first','warning');return;}['cw-h-coin','cw-h-qty','cw-h-buy','cw-h-val'].forEach(i=>document.getElementById(i).value='');document.getElementById('cw-holding-modal').style.display='';},
closeHolding(){document.getElementById('cw-holding-modal').style.display='none';},
saveHolding(){const coin=document.getElementById('cw-h-coin').value.trim().toUpperCase();const qty=document.getElementById('cw-h-qty').value;if(!coin||!qty){showToast('Coin and qty required','warning');return;}apiPost({action:'cold_wallet_holdings_add',wallet_id:this._activeId,coin,quantity:qty,buy_price:document.getElementById('cw-h-buy').value,value_inr:document.getElementById('cw-h-val').value}).then(r=>{if(!r.ok){showToast(r.message,'error');return;}showToast(r.message,'success');this.closeHolding();this.loadHoldings();this.loadSummary();this.loadWallets();});},
delHolding(id){if(!confirm('Remove?'))return;apiPost({action:'cold_wallet_holdings_delete',holding_id:id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.loadHoldings();this.loadSummary();this.loadWallets();}});}};
document.addEventListener('DOMContentLoaded',()=>CW.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
