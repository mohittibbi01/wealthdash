<?php
/**
 * WealthDash — NFO Tracker (t64)
 * Track Open/Upcoming NFOs — localStorage + AMFI data
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$pageTitle = 'NFO Tracker'; $activePage = 'nfo';
ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">📋 NFO Tracker</h1>
    <p class="page-subtitle">New Fund Offers — Open · Upcoming · Your Watchlist</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost btn-sm" onclick="loadAmfiNfos()">🔄 Refresh</button>
    <button class="btn btn-primary btn-sm" onclick="openAddNfo()">+ Add NFO</button>
  </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border);padding-bottom:0;">
  <button class="nfo-tab active" data-tab="open" onclick="switchNfoTab('open',this)">🟢 Open Now</button>
  <button class="nfo-tab" data-tab="upcoming" onclick="switchNfoTab('upcoming',this)">📅 Upcoming</button>
  <button class="nfo-tab" data-tab="watchlist" onclick="switchNfoTab('watchlist',this)">⭐ My Watchlist</button>
</div>
<style>
.nfo-tab { padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;color:var(--text-muted);border-bottom:2px solid transparent;margin-bottom:-2px; }
.nfo-tab.active { color:var(--accent);border-bottom-color:var(--accent); }
.nfo-card { background:var(--bg-surface);border:1.5px solid var(--border);border-radius:10px;padding:14px;margin-bottom:12px;transition:box-shadow .15s; }
.nfo-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.1); }
</style>

<div id="nfoOpen">
  <div id="nfoOpenBody"><div style="text-align:center;padding:40px;"><span class="spinner"></span> Loading NFOs…</div></div>
</div>
<div id="nfoUpcoming" style="display:none;">
  <div id="nfoUpcomingBody"><div style="text-align:center;padding:40px;color:var(--text-muted);">Loading…</div></div>
</div>
<div id="nfoWatchlist" style="display:none;">
  <div id="nfoWatchlistBody"></div>
</div>

<!-- Add NFO Modal -->
<div class="modal-overlay" id="modalAddNfo" style="display:none;">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><h3 class="modal-title">Add NFO to Watchlist</h3><button class="modal-close" onclick="hideModal('modalAddNfo')">✕</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Fund Name *</label><input type="text" id="nfoName" class="form-input" placeholder="e.g. Mirae Asset Nifty 200 Quality 30 ETF"></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">AMC</label><input type="text" id="nfoAmc" class="form-input" placeholder="Fund house"></div>
        <div class="form-group"><label class="form-label">Category</label>
          <select id="nfoCat" class="form-input">
            <option>Equity — Large Cap</option><option>Index Fund</option><option>ELSS</option>
            <option>Equity — Mid Cap</option><option>Equity — Small Cap</option>
            <option>Debt — Short Duration</option><option>Hybrid — Aggressive</option><option>Other</option>
          </select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Open Date</label><input type="date" id="nfoOpen" class="form-input"></div>
        <div class="form-group"><label class="form-label">Close Date</label><input type="date" id="nfoClose" class="form-input"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Min Investment (₹)</label><input type="number" id="nfoMin" class="form-input" value="5000"></div>
        <div class="form-group"><label class="form-label">NFO Price (₹)</label><input type="number" id="nfoPrice" class="form-input" value="10"></div>
      </div>
      <div class="form-group"><label class="form-label">Notes</label><input type="text" id="nfoNotes" class="form-input" placeholder="Why interested?"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalAddNfo')">Cancel</button>
      <button class="btn btn-primary" onclick="saveNfo()">Add to Watchlist</button>
    </div>
  </div>
</div>

<script>
const NFO_KEY = 'wd_nfo_watchlist_v1';
let _nfoCurrentTab = 'open';

// Sample NFO data (real data would come from AMFI scraper)
const SAMPLE_NFOS = [
  { name:'Motilal Oswal Nifty India Defence Index Fund', amc:'Motilal Oswal', cat:'Index Fund', open:'2025-03-01', close:'2025-03-14', price:10, min:500, type:'open', label:'Index · Defence theme' },
  { name:'SBI Long Duration Fund', amc:'SBI MF', cat:'Debt — Long Duration', open:'2025-03-05', close:'2025-03-19', price:10, min:5000, type:'open', label:'Debt · Long Duration' },
  { name:'HDFC Manufacturing Fund', amc:'HDFC MF', cat:'Equity — Sectoral', open:'2025-03-20', close:'2025-04-03', price:10, min:5000, type:'upcoming', label:'Equity · Manufacturing' },
  { name:'Nippon India Innovation Fund', amc:'Nippon India', cat:'Equity — Thematic', open:'2025-03-25', close:'2025-04-08', price:10, min:1000, type:'upcoming', label:'Equity · Innovation theme' },
];

function switchNfoTab(tab, el) {
  _nfoCurrentTab = tab;
  document.querySelectorAll('.nfo-tab').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('nfoOpen').style.display     = tab==='open'      ? '' : 'none';
  document.getElementById('nfoUpcoming').style.display = tab==='upcoming'  ? '' : 'none';
  document.getElementById('nfoWatchlist').style.display= tab==='watchlist' ? '' : 'none';
  if (tab === 'watchlist') renderWatchlist();
}

function loadAmfiNfos() {
  const open     = SAMPLE_NFOS.filter(n => n.type === 'open');
  const upcoming = SAMPLE_NFOS.filter(n => n.type === 'upcoming');
  renderNfoList('nfoOpenBody', open, 'open');
  renderNfoList('nfoUpcomingBody', upcoming, 'upcoming');
}

function renderNfoList(bodyId, nfos, type) {
  const body = document.getElementById(bodyId);
  if (!nfos.length) { body.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:40px;">No NFOs found</div>'; return; }
  const today = new Date();
  body.innerHTML = nfos.map(n => {
    const closeDate = new Date(n.close);
    const daysLeft  = Math.ceil((closeDate - today) / 86400000);
    const watched   = isWatchlisted(n.name);
    return `<div class="nfo-card">
      <div style="display:flex;align-items:flex-start;gap:12px;">
        <div style="flex:1;">
          <div style="font-size:14px;font-weight:700;margin-bottom:4px;">${n.name}</div>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">${n.amc} · ${n.label}</div>
          <div style="display:flex;gap:12px;flex-wrap:wrap;font-size:12px;">
            <span>📅 Open: <strong>${n.open}</strong></span>
            <span>🔒 Close: <strong>${n.close}</strong></span>
            <span>💰 Min: <strong>₹${Number(n.min).toLocaleString('en-IN')}</strong></span>
            <span>💲 Price: <strong>₹${n.price}</strong></span>
          </div>
        </div>
        <div style="flex-shrink:0;text-align:center;">
          ${type==='open' ? `<div style="font-size:11px;font-weight:800;color:${daysLeft<=3?'#dc2626':'#16a34a'};">${daysLeft} days left</div>` : `<div style="font-size:11px;color:var(--text-muted);">Opens ${n.open}</div>`}
          <button onclick="toggleWatchlistNfo(${JSON.stringify(n).replace(/"/g,'&quot;')})" style="margin-top:6px;padding:4px 10px;border-radius:6px;border:1.5px solid ${watched?'#f59e0b':'var(--border)'};background:${watched?'#fef3c7':'none'};cursor:pointer;font-size:12px;">
            ${watched ? '⭐ Watching' : '☆ Watch'}
          </button>
        </div>
      </div>
    </div>`;
  }).join('');
}

function isWatchlisted(name) {
  try { return JSON.parse(localStorage.getItem(NFO_KEY)||'[]').some(n=>n.name===name); } catch(e){return false;}
}
function toggleWatchlistNfo(nfo) {
  try {
    let list = JSON.parse(localStorage.getItem(NFO_KEY)||'[]');
    const idx = list.findIndex(n=>n.name===nfo.name);
    if (idx>=0) { list.splice(idx,1); showToast('Removed from watchlist','info'); }
    else { list.unshift({...nfo, added: new Date().toISOString()}); showToast('Added to NFO watchlist ⭐','success'); }
    localStorage.setItem(NFO_KEY, JSON.stringify(list));
    loadAmfiNfos();
  } catch(e){}
}

function renderWatchlist() {
  const body = document.getElementById('nfoWatchlistBody');
  try {
    const list = JSON.parse(localStorage.getItem(NFO_KEY)||'[]');
    if (!list.length) { body.innerHTML='<div style="text-align:center;color:var(--text-muted);padding:40px;">No NFOs in watchlist. Star any NFO to add.</div>'; return; }
    renderNfoList('nfoWatchlistBody', list, 'watchlist');
  } catch(e){}
}

function openAddNfo() { showModal('modalAddNfo'); }
function saveNfo() {
  const nfo = {
    name:  document.getElementById('nfoName').value,
    amc:   document.getElementById('nfoAmc').value,
    cat:   document.getElementById('nfoCat').value,
    open:  document.getElementById('nfoOpen').value,
    close: document.getElementById('nfoClose').value,
    min:   parseFloat(document.getElementById('nfoMin').value)||5000,
    price: parseFloat(document.getElementById('nfoPrice').value)||10,
    notes: document.getElementById('nfoNotes').value,
    type:  'manual', label: document.getElementById('nfoCat').value,
    added: new Date().toISOString(),
  };
  if (!nfo.name) { showToast('Fund name required','error'); return; }
  try {
    let list = JSON.parse(localStorage.getItem(NFO_KEY)||'[]');
    list.unshift(nfo);
    localStorage.setItem(NFO_KEY, JSON.stringify(list));
    hideModal('modalAddNfo');
    showToast(`✅ ${nfo.name} added to NFO watchlist`,'success');
    if (_nfoCurrentTab === 'watchlist') renderWatchlist();
  } catch(e){}
}

document.addEventListener('DOMContentLoaded', loadAmfiNfos);
</script>
<?php $pageContent=ob_get_clean(); require_once APP_ROOT.'/templates/layout.php'; ?>
