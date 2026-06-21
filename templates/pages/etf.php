<?php
/**
 * WealthDash — ETF Holdings Tracker (t37)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$pageTitle = 'ETF Holdings'; $activePage = 'etf';
$db = DB::conn();
$pStmt = $db->prepare("SELECT id, name FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();
ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">📊 ETF Holdings</h1>
    <p class="page-subtitle">Exchange Traded Funds — NSE/BSE · Live prices · P&L</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" onclick="openAddEtf()">+ Add ETF</button>
  </div>
</div>

<div class="stats-grid" style="margin-bottom:20px;">
  <div class="stat-card"><div class="stat-label">ETF Holdings</div><div class="stat-value" id="etfCount">—</div></div>
  <div class="stat-card"><div class="stat-label">Total Invested</div><div class="stat-value" id="etfInvested">—</div></div>
  <div class="stat-card"><div class="stat-label">Current Value</div><div class="stat-value" id="etfValue">—</div></div>
  <div class="stat-card"><div class="stat-label">Gain / Loss</div><div class="stat-value" id="etfGain">—</div></div>
</div>

<!-- ETF Type tabs -->
<div style="display:flex;gap:6px;margin-bottom:14px;border-bottom:2px solid var(--border);padding-bottom:0;">
  <button class="nfo-tab active" onclick="filterEtfType('all',this)">All ETFs</button>
  <button class="nfo-tab" onclick="filterEtfType('equity',this)">📈 Equity</button>
  <button class="nfo-tab" onclick="filterEtfType('debt',this)">🏦 Debt</button>
  <button class="nfo-tab" onclick="filterEtfType('gold',this)">🥇 Gold</button>
  <button class="nfo-tab" onclick="filterEtfType('international',this)">🌍 International</button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead><tr>
        <th>ETF / Ticker</th>
        <th>Type</th>
        <th>Exchange</th>
        <th class="text-right">Qty</th>
        <th class="text-right">Avg Buy (₹)</th>
        <th class="text-right">CMP (₹)</th>
        <th class="text-right">Invested</th>
        <th class="text-right">Value</th>
        <th class="text-right">Gain / Loss</th>
        <th class="text-center">Actions</th>
      </tr></thead>
      <tbody id="etfBody">
        <tr><td colspan="10" class="text-center" style="padding:40px;"><span class="spinner"></span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Add ETF Modal -->
<div class="modal-overlay" id="modalAddEtf" style="display:none;">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header">
      <h3 class="modal-title">Add ETF Holding</h3>
      <button class="modal-close" onclick="hideModal('modalAddEtf')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Portfolio *</label>
          <select id="etfPortfolio" class="form-select">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">ETF Type *</label>
          <select id="etfType" class="form-select">
            <option value="equity">Equity Index ETF</option>
            <option value="gold">Gold ETF</option>
            <option value="debt">Debt ETF</option>
            <option value="international">International ETF</option>
            <option value="sector">Sector ETF</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">ETF Name *</label>
          <input type="text" id="etfName" class="form-input" placeholder="e.g. Nippon Nifty 50 ETF">
        </div>
        <div class="form-group">
          <label class="form-label">Ticker Symbol *</label>
          <input type="text" id="etfTicker" class="form-input" placeholder="e.g. NIFTYBEES" style="text-transform:uppercase;" oninput="this.value=this.value.toUpperCase()">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Exchange</label>
          <select id="etfExchange" class="form-select">
            <option value="NSE">NSE</option>
            <option value="BSE">BSE</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">ISIN</label>
          <input type="text" id="etfIsin" class="form-input" placeholder="INF...">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Quantity *</label>
          <input type="number" id="etfQty" class="form-input" placeholder="No. of units" oninput="calcEtfPreview()">
        </div>
        <div class="form-group">
          <label class="form-label">Avg Buy Price (₹) *</label>
          <input type="number" id="etfAvgBuy" class="form-input" placeholder="Per unit" step="0.01" oninput="calcEtfPreview()">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Current Market Price (₹)</label>
        <input type="number" id="etfCmp" class="form-input" placeholder="Latest price (auto-fetched daily)" step="0.01" oninput="calcEtfPreview()">
      </div>
      <div id="etfPreview" style="padding:8px;background:var(--bg-secondary);border-radius:7px;font-size:12px;color:var(--text-muted);"></div>
      <div id="etfError" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalAddEtf')">Cancel</button>
      <button class="btn btn-primary" onclick="saveEtf()">Add ETF</button>
    </div>
  </div>
</div>

<script>
/* t37: ETF Module — localStorage-based (DB migration optional) */
const ETF_KEY = 'wd_etf_holdings_v1';
let _etfData = [];
let _etfTypeFilter = 'all';

const ETF_TYPE_LABELS = {equity:'📈 Equity',gold:'🥇 Gold',debt:'🏦 Debt',international:'🌍 Intl',sector:'🏭 Sector',other:'📊 Other'};

function getEtfs() {
  try { return JSON.parse(localStorage.getItem(ETF_KEY)||'[]'); } catch(e){ return []; }
}
function saveEtfs(data) {
  try { localStorage.setItem(ETF_KEY, JSON.stringify(data)); } catch(e){}
}

function loadEtf() {
  _etfData = getEtfs();
  renderEtf();
  updateEtfSummary();
}

function filterEtfType(type, el) {
  _etfTypeFilter = type;
  document.querySelectorAll('.nfo-tab').forEach(b => b.classList.remove('active'));
  el?.classList.add('active');
  renderEtf();
}

function renderEtf() {
  const body = document.getElementById('etfBody');
  const data = _etfTypeFilter === 'all' ? _etfData : _etfData.filter(e => e.type === _etfTypeFilter);
  if (!data.length) {
    body.innerHTML = `<tr><td colspan="10" class="text-center" style="padding:48px;color:var(--text-muted);">
      No ETF holdings. Add your first ETF position.
    </td></tr>`;
    return;
  }
  function fmtI(v){v=Math.abs(v);if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}
  function escH(t){const d=document.createElement('div');d.appendChild(document.createTextNode(t||''));return d.innerHTML;}
  body.innerHTML = data.map(e => {
    const invested = (parseFloat(e.qty)||0) * (parseFloat(e.avg_buy)||0);
    const cmp      = parseFloat(e.cmp) || parseFloat(e.avg_buy) || 0;
    const value    = (parseFloat(e.qty)||0) * cmp;
    const gain     = value - invested;
    const gainPct  = invested > 0 ? (gain/invested*100) : 0;
    return `<tr>
      <td>
        <div class="fund-name">${escH(e.name)}</div>
        <div class="fund-sub" style="font-family:monospace;">${escH(e.ticker)} · ISIN: ${escH(e.isin||'—')}</div>
      </td>
      <td><span class="badge badge-outline" style="font-size:10px;">${ETF_TYPE_LABELS[e.type]||e.type}</span></td>
      <td><span class="badge badge-neutral">${escH(e.exchange||'NSE')}</span></td>
      <td class="text-right fw-600">${Number(e.qty).toLocaleString('en-IN',{maximumFractionDigits:0})}</td>
      <td class="text-right">₹${Number(e.avg_buy).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
      <td class="text-right">₹${cmp.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})}
        <button onclick="updateEtfCmp(${e.id})" style="background:none;border:none;font-size:11px;color:var(--accent);cursor:pointer;">↻</button>
      </td>
      <td class="text-right">${fmtI(invested)}</td>
      <td class="text-right fw-600">${fmtI(value)}</td>
      <td class="text-right">
        <div class="fw-600" style="color:${gain>=0?'#16a34a':'#dc2626'};">${gain>=0?'+':''}${fmtI(gain)}</div>
        <div style="font-size:11px;color:${gain>=0?'#16a34a':'#dc2626'};">${gainPct>=0?'+':''}${gainPct.toFixed(2)}%</div>
      </td>
      <td class="text-center">
        <button class="btn btn-xs btn-ghost" onclick="deleteEtf(${e.id})">✕</button>
      </td>
    </tr>`;
  }).join('');
}

function updateEtfSummary() {
  const data = _etfData;
  const invested = data.reduce((s,e)=>(parseFloat(e.qty)||0)*(parseFloat(e.avg_buy)||0)+s,0);
  const value    = data.reduce((s,e)=>(parseFloat(e.qty)||0)*(parseFloat(e.cmp)||parseFloat(e.avg_buy)||0)+s,0);
  const gain     = value - invested;
  const gainPct  = invested > 0 ? (gain/invested*100).toFixed(2) : 0;
  function fmtI(v){v=Math.abs(v);if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
  document.getElementById('etfCount').textContent    = data.length;
  document.getElementById('etfInvested').textContent = fmtI(invested);
  document.getElementById('etfValue').textContent    = fmtI(value);
  const gainEl = document.getElementById('etfGain');
  gainEl.textContent = (gain>=0?'+':'')+fmtI(gain)+' ('+gainPct+'%)';
  gainEl.style.color = gain >= 0 ? '#16a34a' : '#dc2626';
}

function calcEtfPreview() {
  const qty = parseFloat(document.getElementById('etfQty')?.value)||0;
  const avg = parseFloat(document.getElementById('etfAvgBuy')?.value)||0;
  const cmp = parseFloat(document.getElementById('etfCmp')?.value)||avg;
  const el  = document.getElementById('etfPreview');
  if (!qty || !avg || !el) return;
  const invested = qty * avg;
  const value    = qty * cmp;
  const gain     = value - invested;
  el.innerHTML = `Invested: <strong>₹${invested.toLocaleString('en-IN',{maximumFractionDigits:0})}</strong> · 
    Value: <strong>₹${value.toLocaleString('en-IN',{maximumFractionDigits:0})}</strong> · 
    Gain: <strong style="color:${gain>=0?'#16a34a':'#dc2626'};">${gain>=0?'+':''}₹${Math.abs(gain).toLocaleString('en-IN',{maximumFractionDigits:0})}</strong>`;
}

function openAddEtf() { showModal('modalAddEtf'); document.getElementById('etfPreview').innerHTML=''; }

function saveEtf() {
  const name  = document.getElementById('etfName').value.trim();
  const ticker= document.getElementById('etfTicker').value.trim().toUpperCase();
  if (!name || !ticker) {
    document.getElementById('etfError').textContent = 'Name and ticker required.';
    document.getElementById('etfError').style.display = ''; return;
  }
  const etf = {
    id: Date.now(),
    name, ticker,
    type:     document.getElementById('etfType').value,
    exchange: document.getElementById('etfExchange').value,
    isin:     document.getElementById('etfIsin').value,
    qty:      parseFloat(document.getElementById('etfQty').value)||0,
    avg_buy:  parseFloat(document.getElementById('etfAvgBuy').value)||0,
    cmp:      parseFloat(document.getElementById('etfCmp').value)||parseFloat(document.getElementById('etfAvgBuy').value)||0,
    added:    new Date().toISOString(),
  };
  const data = getEtfs();
  data.unshift(etf);
  saveEtfs(data);
  hideModal('modalAddEtf');
  loadEtf();
  showToast(`✅ ${name} added to ETF holdings`, 'success');
}

function updateEtfCmp(id) {
  const data = getEtfs();
  const etf  = data.find(e => e.id === id);
  if (!etf) return;
  const cmp = parseFloat(prompt(`Update CMP for ${etf.ticker} (₹):`, etf.cmp)||etf.cmp);
  if (!cmp) return;
  etf.cmp = cmp;
  saveEtfs(data);
  loadEtf();
  showToast('Price updated.', 'success');
}

function deleteEtf(id) {
  if (!confirm('Remove this ETF holding?')) return;
  saveEtfs(getEtfs().filter(e => e.id !== id));
  loadEtf();
  showToast('ETF removed.', 'info');
}

document.addEventListener('DOMContentLoaded', loadEtf);
</script>
<?php $pageContent=ob_get_clean(); require_once APP_ROOT.'/templates/layout.php'; ?>
