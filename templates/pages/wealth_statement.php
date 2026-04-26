<?php
/**
 * WealthDash — t313: Wealth Statement (CA-ready)
 * Comprehensive asset + liability snapshot with print view
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser   = require_auth();
$pageTitle     = 'Wealth Statement';
$activePage    = 'wealth_statement';
$activeSection = 'reports';

ob_start();
?>
<style>
/* ── Wealth Statement styles ── */
.ws-hero {
    background: linear-gradient(135deg, var(--accent) 0%, #7c3aed 100%);
    color: #fff; border-radius: var(--radius-lg); padding: 28px 32px;
    margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 24px;
}
.ws-hero-left h2 { margin: 0; font-size: 13px; font-weight: 600; opacity: .8; letter-spacing: .5px; text-transform: uppercase; }
.ws-hero-nw { font-size: 42px; font-weight: 800; line-height: 1.1; margin: 6px 0 4px; }
.ws-hero-meta { font-size: 13px; opacity: .85; display: flex; gap: 20px; flex-wrap: wrap; }
.ws-hero-right { text-align: right; flex-shrink: 0; }
.ws-hero-badge { background: rgba(255,255,255,.18); border-radius: 8px; padding: 8px 16px; font-size: 12px; font-weight: 600; }
@media (max-width: 640px) { .ws-hero { flex-direction: column; align-items: flex-start; } .ws-hero-right { text-align: left; } .ws-hero-nw { font-size: 28px; } }

.ws-section-title { font-size: 11px; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; color: var(--text-muted); padding: 10px 16px 6px; border-bottom: 1px solid var(--border); }
.ws-cat-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px 8px; background: var(--bg-secondary); }
.ws-cat-name { font-size: 13px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
.ws-cat-badge { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 99px; background: var(--accent-light); color: var(--accent); }
.ws-cat-total { font-size: 14px; font-weight: 800; color: var(--text-primary); }
.ws-row { display: grid; grid-template-columns: 1fr 120px 130px 130px 80px; gap: 0; }
.ws-row > * { padding: 9px 16px; font-size: 12.5px; border-bottom: 1px solid var(--border); }
.ws-row-header > * { font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: var(--text-muted); padding: 7px 16px; background: var(--bg-secondary); border-bottom: 2px solid var(--border); }
.ws-row-total > * { font-size: 13px; font-weight: 700; background: var(--bg-secondary); border-top: 2px solid var(--border); border-bottom: none; }
.ws-gain-pos { color: #16a34a; font-weight: 700; }
.ws-gain-neg { color: #dc2626; font-weight: 700; }
.ws-liab-row { display: grid; grid-template-columns: 1fr 100px 130px 130px; gap: 0; }
.ws-liab-row > * { padding: 9px 16px; font-size: 12.5px; border-bottom: 1px solid var(--border); }
.ws-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
.ws-summary-card { border: 1.5px solid var(--border); border-radius: var(--radius-md); padding: 16px 18px; }
.ws-summary-label { font-size: 11px; color: var(--text-muted); font-weight: 600; letter-spacing: .3px; text-transform: uppercase; }
.ws-summary-value { font-size: 22px; font-weight: 800; color: var(--text-primary); margin-top: 4px; }
.ws-summary-sub { font-size: 11px; color: var(--text-muted); margin-top: 3px; }
.ws-alloc-bar { height: 8px; background: var(--bg-secondary); border-radius: 99px; overflow: hidden; margin-top: 6px; }
.ws-alloc-fill { height: 100%; background: var(--accent); border-radius: 99px; transition: width .4s; }
.ws-pill-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.ws-pill { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 99px; }

/* Print styles */
@media print {
    .page-header, .topbar, .sidebar, .main-wrapper > .topbar,
    #wsPrintBtn, #wsDatePicker, .ws-print-hide { display: none !important; }
    .page-content { padding: 0 !important; }
    .ws-hero { background: #f8fafc !important; color: #111 !important; border: 2px solid #e5e7eb; }
    .ws-hero-nw { color: #111 !important; }
    .ws-print-header { display: block !important; }
    body { font-size: 12px; }
    .card { box-shadow: none !important; border: 1px solid #e5e7eb !important; }
    .ws-row, .ws-row-header, .ws-liab-row { break-inside: avoid; }
}
.ws-print-header { display: none; text-align: center; padding: 0 0 20px; }
.ws-print-header h1 { font-size: 20px; font-weight: 800; margin: 0 0 4px; }
.ws-print-header p { font-size: 12px; color: #6b7280; margin: 0; }
@media (max-width: 700px) {
    .ws-row, .ws-row-header, .ws-row-total { grid-template-columns: 1fr 100px 110px; }
    .ws-row > *:nth-child(2), .ws-row-header > *:nth-child(2), .ws-row-total > *:nth-child(2) { display: none; }
    .ws-liab-row { grid-template-columns: 1fr 110px 110px; }
    .ws-liab-row > *:nth-child(2) { display: none; }
}
</style>

<!-- Print header (hidden on screen) -->
<div class="ws-print-header">
    <h1>Wealth Statement</h1>
    <p>Prepared for: <span id="pPrintName"></span> &nbsp;|&nbsp; As of: <span id="pPrintDate"></span></p>
</div>

<div class="page-header">
    <div>
        <h1 class="page-title">Wealth Statement</h1>
        <p class="page-subtitle">CA-ready comprehensive asset & liability snapshot</p>
    </div>
    <div class="page-actions">
        <div style="display:flex;align-items:center;gap:8px;" id="wsDatePicker">
            <label style="font-size:12px;color:var(--text-muted);font-weight:600;">As of:</label>
            <input type="date" id="wsAsOf" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"
                style="font-size:13px;padding:5px 10px;border-radius:7px;border:1.5px solid var(--border);background:var(--bg-card);color:var(--text-primary);cursor:pointer;"
                onchange="wsLoad()">
        </div>
        <button class="btn btn-outline ws-print-hide" id="wsPrintBtn" onclick="window.print()">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print / PDF
        </button>
    </div>
</div>

<!-- Hero card -->
<div class="ws-hero" id="wsHero">
    <div class="ws-hero-left">
        <h2>Net Worth</h2>
        <div class="ws-hero-nw" id="wsHeroNW">Loading…</div>
        <div class="ws-hero-meta">
            <span>Total Invested: <strong id="wsHeroInv">—</strong></span>
            <span>Overall Gain: <strong id="wsHeroGain">—</strong></span>
            <span>Return: <strong id="wsHeroRet">—</strong></span>
        </div>
    </div>
    <div class="ws-hero-right" id="wsHeroRight" style="display:none">
        <div class="ws-hero-badge" id="wsHeroAsOf"></div>
        <div style="margin-top:8px;font-size:12px;opacity:.8;" id="wsHeroPortfolios"></div>
    </div>
</div>

<!-- Summary cards -->
<div class="ws-summary-grid mb-4" id="wsSummaryGrid" style="display:none"></div>

<!-- Allocation pills + bar -->
<div class="card mb-4" id="wsAllocCard" style="display:none">
    <div class="card-header"><h3 class="card-title">Asset Allocation</h3></div>
    <div class="card-body" id="wsAllocBody"></div>
</div>

<!-- Assets table -->
<div class="card mb-4" id="wsAssetsCard" style="display:none">
    <div class="ws-section-title">📈 ASSETS</div>
    <div id="wsAssetsBody"></div>
    <div style="padding:12px 16px;border-top:2px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:13px;font-weight:700;">TOTAL ASSETS</span>
        <span style="font-size:18px;font-weight:800;color:var(--accent);" id="wsTotalAssets">—</span>
    </div>
</div>

<!-- Liabilities table -->
<div class="card mb-4" id="wsLiabCard" style="display:none">
    <div class="ws-section-title">🏦 LIABILITIES</div>
    <div class="ws-liab-row ws-row-header">
        <div>Loan Type</div><div class="text-right">Count</div>
        <div class="text-right">Original Amt</div><div class="text-right">Outstanding</div>
    </div>
    <div id="wsLiabBody"></div>
    <div style="padding:12px 16px;border-top:2px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:13px;font-weight:700;">TOTAL LIABILITIES</span>
        <span style="font-size:18px;font-weight:800;color:#dc2626;" id="wsTotalLiab">—</span>
    </div>
</div>

<!-- Net worth final line -->
<div class="card mb-4" id="wsNwFinalCard" style="display:none">
    <div style="padding:20px 24px;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-muted);">NET WORTH (Assets − Liabilities)</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;" id="wsNwAsOf"></div>
        </div>
        <div style="font-size:28px;font-weight:800;" id="wsNwFinal">—</div>
    </div>
</div>

<!-- Footer note -->
<p style="font-size:11px;color:var(--text-muted);text-align:center;padding-bottom:24px;">
    * Current values based on latest available NAV / prices. Statement is indicative and not a CA-certified document.
    Generated by WealthDash on <?= date('d M Y H:i') ?> IST.
</p>

<script>
(function() {
const APP = window.WD?.appUrl || document.querySelector('meta[name="app-url"]')?.content || '';
const PALETTE = ['#4f46e5','#2563eb','#0891b2','#16a34a','#d97706','#dc2626','#7c3aed','#db2777'];

function fmt(v) {
    if (!v && v !== 0) return '—';
    v = parseFloat(v);
    if (Math.abs(v) >= 1e7) return '₹' + (v/1e7).toFixed(2) + ' Cr';
    if (Math.abs(v) >= 1e5) return '₹' + (v/1e5).toFixed(2) + ' L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
}
function fmtPct(v) { return (v >= 0 ? '+' : '') + parseFloat(v).toFixed(2) + '%'; }
function gainCls(v) { return parseFloat(v) >= 0 ? 'ws-gain-pos' : 'ws-gain-neg'; }

async function wsLoad() {
    const asOf = document.getElementById('wsAsOf').value || '<?= date('Y-m-d') ?>';
    document.getElementById('wsHeroNW').textContent = 'Loading…';
    document.getElementById('wsAssetsCard').style.display = 'none';
    document.getElementById('wsLiabCard').style.display = 'none';
    document.getElementById('wsNwFinalCard').style.display = 'none';
    document.getElementById('wsSummaryGrid').style.display = 'none';
    document.getElementById('wsAllocCard').style.display = 'none';

    try {
        const r = await fetch(`${APP}/api/router.php?action=wealth_statement&as_of=${encodeURIComponent(asOf)}`, {
            headers: {'X-Requested-With':'XMLHttpRequest'}
        });
        const d = await r.json();
        if (!d.success) { document.getElementById('wsHeroNW').textContent = 'Error loading'; return; }
        renderAll(d.data);
    } catch(e) {
        document.getElementById('wsHeroNW').textContent = 'Load failed';
    }
}

function renderAll(data) {
    const s = data.summary;
    const asOfFmt = new Date(data.as_of).toLocaleDateString('en-IN',{day:'2-digit',month:'long',year:'numeric'});

    // Print header
    document.getElementById('pPrintName').textContent = data.user_name;
    document.getElementById('pPrintDate').textContent = asOfFmt;

    // Hero
    document.getElementById('wsHeroNW').textContent = fmt(s.net_worth);
    document.getElementById('wsHeroInv').textContent = fmt(s.total_invested);
    const gainEl = document.getElementById('wsHeroGain');
    gainEl.textContent = fmt(s.total_gain_loss) + ' (' + fmtPct(s.overall_return_pct) + ')';
    gainEl.style.color = s.total_gain_loss >= 0 ? '#86efac' : '#fca5a5';
    document.getElementById('wsHeroRet').textContent = fmtPct(s.overall_return_pct);
    document.getElementById('wsHeroAsOf').textContent = 'As of ' + asOfFmt;
    document.getElementById('wsHeroPortfolios').textContent = data.portfolio_count + ' portfolio(s)';
    document.getElementById('wsHeroRight').style.display = 'block';

    // Summary grid
    const grid = document.getElementById('wsSummaryGrid');
    const sp = data.type_split;
    const totalA = s.total_assets || 1;
    const cards = [
        {label:'Equity', value: fmt(sp.equity), sub: ((sp.equity/totalA*100).toFixed(1))+'%', color:'#2563eb'},
        {label:'Debt / FI', value: fmt(sp.debt), sub: ((sp.debt/totalA*100).toFixed(1))+'%', color:'#d97706'},
        {label:'Retirement', value: fmt(sp.retirement), sub: ((sp.retirement/totalA*100).toFixed(1))+'%', color:'#7c3aed'},
        {label:'Alternative', value: fmt(sp.alternative), sub: ((sp.alternative/totalA*100).toFixed(1))+'%', color:'#db2777'},
        {label:'Liquid / Cash', value: fmt(sp.liquid), sub: ((sp.liquid/totalA*100).toFixed(1))+'%', color:'#16a34a'},
        {label:'Total Liabilities', value: fmt(s.total_liabilities), sub:'Outstanding', color:'#dc2626'},
    ];
    grid.innerHTML = cards.map(c => `
        <div class="ws-summary-card">
            <div class="ws-summary-label" style="color:${c.color}">${c.label}</div>
            <div class="ws-summary-value">${c.value}</div>
            <div class="ws-summary-sub">${c.sub} of total</div>
        </div>
    `).join('');
    grid.style.display = 'grid';

    // Allocation bar
    const alloc = data.allocation;
    if (alloc && alloc.length) {
        const allocBody = document.getElementById('wsAllocBody');
        let html = '<div style="display:flex;gap:0;height:16px;border-radius:99px;overflow:hidden;margin-bottom:14px;">';
        alloc.forEach((a, i) => { html += `<div style="width:${a.pct}%;background:${PALETTE[i%PALETTE.length]};transition:width .4s;" title="${a.label}: ${a.pct}%"></div>`; });
        html += '</div><div class="ws-pill-row">';
        alloc.forEach((a, i) => {
            html += `<span class="ws-pill" style="background:${PALETTE[i%PALETTE.length]}22;color:${PALETTE[i%PALETTE.length]};border:1px solid ${PALETTE[i%PALETTE.length]}44;">
                ${a.label}: ${fmt(a.value)} (${a.pct}%)
            </span>`;
        });
        html += '</div>';
        allocBody.innerHTML = html;
        document.getElementById('wsAllocCard').style.display = '';
    }

    // Assets
    const assetsBody = document.getElementById('wsAssetsBody');
    let aHtml = `<div class="ws-row ws-row-header">
        <div>Asset / Instrument</div><div class="text-right">Count</div>
        <div class="text-right">Invested</div><div class="text-right">Current Value</div>
        <div class="text-right">Gain/Loss</div>
    </div>`;

    let totalAssetsVal = 0;
    const groups = data.assets;
    Object.keys(groups).forEach((cat, ci) => {
        const g = groups[cat];
        aHtml += `<div class="ws-cat-header">
            <span class="ws-cat-name">
                <span style="width:10px;height:10px;border-radius:50%;background:${PALETTE[ci%PALETTE.length]};display:inline-block;"></span>
                ${esc(cat)}
            </span>
            <span class="ws-cat-total">${fmt(g.total_current)}</span>
        </div>`;
        g.items.forEach(item => {
            aHtml += `<div class="ws-row">
                <div style="padding-left:28px;">${esc(item.sub_category)}</div>
                <div class="text-right" style="color:var(--text-muted);">${item.count}</div>
                <div class="text-right">${fmt(item.invested)}</div>
                <div class="text-right" style="font-weight:600;">${fmt(item.current_value)}</div>
                <div class="text-right ${gainCls(item.gain_loss)}">${fmt(item.gain_loss)}</div>
            </div>`;
        });
        totalAssetsVal += g.total_current;
    });

    assetsBody.innerHTML = aHtml;
    document.getElementById('wsTotalAssets').textContent = fmt(totalAssetsVal);
    document.getElementById('wsAssetsCard').style.display = '';

    // Liabilities
    const liabBody = document.getElementById('wsLiabBody');
    let lHtml = '';
    let totalLiab = 0;
    if (data.liabilities && data.liabilities.length) {
        data.liabilities.forEach(l => {
            totalLiab += l.outstanding;
            lHtml += `<div class="ws-liab-row">
                <div>${esc(l.sub_category)}</div>
                <div class="text-right" style="color:var(--text-muted);">${l.count}</div>
                <div class="text-right">${fmt(l.original)}</div>
                <div class="text-right" style="color:#dc2626;font-weight:600;">${fmt(l.outstanding)}</div>
            </div>`;
        });
    } else {
        lHtml = '<div style="padding:20px 16px;text-align:center;color:var(--text-muted);font-size:13px;">✅ No active liabilities found</div>';
    }
    liabBody.innerHTML = lHtml;
    document.getElementById('wsTotalLiab').textContent = fmt(s.total_liabilities);
    document.getElementById('wsLiabCard').style.display = '';

    // Net Worth final line
    const nwEl = document.getElementById('wsNwFinal');
    nwEl.textContent = fmt(s.net_worth);
    nwEl.style.color = s.net_worth >= 0 ? 'var(--accent)' : '#dc2626';
    document.getElementById('wsNwAsOf').textContent = 'As of ' + asOfFmt;
    document.getElementById('wsNwFinalCard').style.display = '';
}

function esc(s) { const d=document.createElement('div'); d.appendChild(document.createTextNode(String(s||''))); return d.innerHTML; }

window.wsLoad = wsLoad;
document.addEventListener('DOMContentLoaded', wsLoad);
})();
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
