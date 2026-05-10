<?php
/**
 * WealthDash — t134: 54EC Bond Tracker
 * Section 54EC: Capital Gains Reinvestment in REC / NHAI / PFC / IRFC bonds
 * Lock-in: 5 years | Max exemption: ₹50L per FY | Must invest within 6 months of sale
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = '54EC Bond Tracker';
$activePage  = 'bonds_54ec';

ob_start();
?>
<style>
/* ── 54EC Page Styles ─────────────────────────────────────────────── */
.ec-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px}
.ec-sum-card{background:var(--card-bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px;position:relative;overflow:hidden}
.ec-sum-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.ec-sum-card.invested::before{background:var(--primary)}
.ec-sum-card.maturity::before{background:var(--green,#22c55e)}
.ec-sum-card.ltcg::before{background:var(--orange,#f97316)}
.ec-sum-card.interest::before{background:var(--teal,#14b8a6)}
.ec-sum-card.count::before{background:var(--purple,#a855f7)}
.ec-sum-label{font-size:11px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.ec-sum-val{font-size:20px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums}
.ec-sum-sub{font-size:11px;color:var(--text-secondary);margin-top:3px}
.ec-table-wrap{overflow-x:auto}
.ec-table{width:100%;border-collapse:collapse;font-size:13px}
.ec-table th{background:var(--bg-secondary,var(--bg));padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:var(--text-secondary);border-bottom:2px solid var(--border);white-space:nowrap;text-transform:uppercase;letter-spacing:.4px}
.ec-table td{padding:11px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
.ec-table tr:last-child td{border-bottom:none}
.ec-table tbody tr:hover{background:var(--bg-hover,rgba(0,0,0,.03))}
.ec-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;text-transform:uppercase}
.ec-badge.active{background:#dcfce7;color:#166534}
.ec-badge.matured{background:#fef3c7;color:#92400e}
.ec-badge.lockin{background:#ede9fe;color:#5b21b6}
.ec-issuer-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700}
.ec-issuer-badge.REC{background:#eff6ff;color:#1d4ed8}
.ec-issuer-badge.NHAI{background:#fef3c7;color:#92400e}
.ec-issuer-badge.PFC{background:#f0fdf4;color:#166534}
.ec-issuer-badge.IRFC{background:#fdf4ff;color:#7e22ce}
.ec-issuer-badge.OTHER{background:var(--bg-secondary);color:var(--text-secondary)}
.ec-progress-bar{height:5px;background:var(--border);border-radius:3px;overflow:hidden;margin-top:4px;width:100px}
.ec-progress-fill{height:100%;border-radius:3px;transition:width .6s ease}
.ec-act-btn{padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:none;cursor:pointer;font-size:12px;color:var(--text-secondary)}
.ec-act-btn:hover{background:var(--bg-hover);color:var(--text)}
.ec-act-btn.del:hover{border-color:#fca5a5;color:#dc2626;background:#fef2f2}
.ec-info-box{background:linear-gradient(135deg,#eff6ff,#f0fdf4);border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#1e40af}
.ec-info-box b{color:#1e3a8a}
.ec-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;align-items:center;justify-content:center}
.ec-modal-overlay.open{display:flex}
.ec-modal{background:var(--card-bg);border-radius:14px;width:520px;max-width:94vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.ec-modal-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px 14px;border-bottom:1px solid var(--border)}
.ec-modal-head h3{margin:0;font-size:16px;font-weight:700}
.ec-modal-body{padding:20px}
.ec-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ec-form-group{display:flex;flex-direction:column;gap:4px}
.ec-form-group.full{grid-column:1/-1}
.ec-form-group label{font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.4px}
.ec-form-group input,.ec-form-group select,.ec-form-group textarea{padding:9px 11px;border:1px solid var(--border);border-radius:8px;font-size:13px;background:var(--bg);color:var(--text);outline:none}
.ec-form-group input:focus,.ec-form-group select:focus{border-color:var(--primary)}
.ec-form-actions{display:flex;gap:8px;justify-content:flex-end;padding:14px 20px 20px;border-top:1px solid var(--border)}
.ec-calc-box{background:var(--bg-secondary,var(--bg));border:1px dashed var(--border);border-radius:8px;padding:12px 14px;margin-top:10px;font-size:12px;display:grid;grid-template-columns:1fr 1fr;gap:6px}
.ec-calc-row{display:flex;justify-content:space-between;align-items:center;padding:2px 0}
.ec-calc-row span:first-child{color:var(--text-secondary)}
.ec-calc-row span:last-child{font-weight:700;color:var(--text)}
.ec-deadline-warn{padding:8px 12px;border-radius:7px;font-size:12px;font-weight:600;margin-top:6px}
.ec-deadline-warn.ok{background:#dcfce7;color:#166534}
.ec-deadline-warn.warn{background:#fef3c7;color:#92400e}
.ec-deadline-warn.over{background:#fee2e2;color:#991b1b}
.ec-empty{text-align:center;padding:60px 20px;color:var(--text-secondary)}
.ec-empty svg{opacity:.25;margin-bottom:14px}
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">54EC Bond Tracker</h1>
    <p class="page-subtitle">Section 54EC — LTCG reinvestment exemption · REC / NHAI / PFC / IRFC</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" onclick="ecOpenAdd()">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Bond
    </button>
  </div>
</div>

<!-- Info Banner -->
<div class="ec-info-box">
  <b>📘 Section 54EC:</b> Invest LTCG from property/asset sale into notified bonds (REC, NHAI, PFC, IRFC) within <b>6 months</b> of sale to claim capital gains exemption.
  Max exemption: <b>₹50,00,000 per FY</b>. Lock-in period: <b>5 years</b>. Interest income is taxable.
</div>

<!-- Summary Cards -->
<div class="ec-summary-grid" id="ecSummaryGrid">
  <div class="ec-sum-card invested"><div class="ec-sum-label">Total Invested</div><div class="ec-sum-val" id="ecSumInvested">—</div><div class="ec-sum-sub" id="ecSumCount">—</div></div>
  <div class="ec-sum-card maturity"><div class="ec-sum-label">Maturity Value</div><div class="ec-sum-val" id="ecSumMaturity">—</div><div class="ec-sum-sub">at 5yr maturity</div></div>
  <div class="ec-sum-card ltcg"><div class="ec-sum-label">LTCG Exempted</div><div class="ec-sum-val" id="ecSumLtcg">—</div><div class="ec-sum-sub">claimed u/s 54EC</div></div>
  <div class="ec-sum-card interest"><div class="ec-sum-label">Interest Earned</div><div class="ec-sum-val" id="ecSumInterest">—</div><div class="ec-sum-sub">accrued so far</div></div>
</div>

<!-- Bonds Table -->
<div class="card" style="padding:0;overflow:hidden">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)">
    <div style="font-size:15px;font-weight:700">🏦 My 54EC Bonds</div>
    <input id="ecSearch" placeholder="Search bonds…" style="padding:7px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;background:var(--bg);color:var(--text);outline:none;width:200px" oninput="ecFilterTable(this.value)">
  </div>
  <div class="ec-table-wrap">
    <table class="ec-table" id="ecTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Issuer</th>
          <th>Investment Date</th>
          <th>Maturity Date</th>
          <th>Face Val / Bond</th>
          <th>Bonds</th>
          <th>Total Invested</th>
          <th>LTCG Exempted</th>
          <th>Interest Rate</th>
          <th>Interest Earned</th>
          <th>Maturity Value</th>
          <th>Lock-in</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="ecTbody">
        <tr><td colspan="14" class="ec-empty"><div>Loading…</div></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ ADD / EDIT MODAL ═══ -->
<div class="ec-modal-overlay" id="ecModal">
  <div class="ec-modal">
    <div class="ec-modal-head">
      <h3 id="ecModalTitle">Add 54EC Bond</h3>
      <button onclick="ecCloseModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-secondary)">✕</button>
    </div>
    <div class="ec-modal-body">
      <input type="hidden" id="ecEditId">
      <div class="ec-form-grid">
        <div class="ec-form-group">
          <label>Bond Issuer</label>
          <select id="ecIssuer" onchange="ecUpdateIssuerName()">
            <option value="REC">REC — Rural Electrification</option>
            <option value="NHAI">NHAI — National Highways</option>
            <option value="PFC">PFC — Power Finance Corp</option>
            <option value="IRFC">IRFC — Indian Railway Finance</option>
            <option value="OTHER">OTHER — Specify below</option>
          </select>
        </div>
        <div class="ec-form-group" id="ecIssuerNameGroup" style="display:none">
          <label>Issuer Name</label>
          <input type="text" id="ecIssuerName" placeholder="Custom issuer name">
        </div>
        <div class="ec-form-group">
          <label>Sale of Original Asset Date</label>
          <input type="date" id="ecSaleDate" onchange="ecUpdateDeadline()">
        </div>
        <div class="ec-form-group">
          <label>Investment Date</label>
          <input type="date" id="ecInvDate" onchange="ecCalcMaturity(); ecUpdateDeadline()">
        </div>
        <div class="ec-form-group">
          <label>Face Value / Bond (₹)</label>
          <input type="number" id="ecFaceVal" value="10000" min="10000" step="10000" oninput="ecCalcTotal()">
        </div>
        <div class="ec-form-group">
          <label>No. of Bonds</label>
          <input type="number" id="ecNumBonds" value="1" min="1" max="500" oninput="ecCalcTotal()">
        </div>
        <div class="ec-form-group">
          <label>Maturity Date</label>
          <input type="date" id="ecMatDate" readonly style="opacity:.7;cursor:not-allowed">
        </div>
        <div class="ec-form-group">
          <label>Interest Rate (% p.a.)</label>
          <input type="number" id="ecRate" value="5.00" min="0" max="15" step="0.01">
        </div>
        <div class="ec-form-group">
          <label>Interest Payout</label>
          <select id="ecFreq">
            <option value="annual">Annual</option>
            <option value="cumulative">Cumulative (at maturity)</option>
          </select>
        </div>
        <div class="ec-form-group">
          <label>LTCG Amount Exempted (₹)</label>
          <input type="number" id="ecLtcg" placeholder="e.g. 1500000" min="0">
        </div>
        <div class="ec-form-group">
          <label>Folio / Certificate No.</label>
          <input type="text" id="ecFolio" placeholder="Optional">
        </div>
        <div class="ec-form-group full">
          <label>Notes</label>
          <textarea id="ecNotes" rows="2" placeholder="e.g. Property sale in Jaipur, registered deed date…"></textarea>
        </div>
      </div>
      <!-- Live calc preview -->
      <div class="ec-calc-box" id="ecCalcBox">
        <div class="ec-calc-row"><span>Total Invested</span><span id="ecCalcTotal">₹0</span></div>
        <div class="ec-calc-row"><span>Maturity Value (est.)</span><span id="ecCalcMaturity">₹0</span></div>
        <div class="ec-calc-row"><span>Total Interest (5yr)</span><span id="ecCalcInterest">₹0</span></div>
        <div class="ec-calc-row"><span>Effective LTCG Saved</span><span id="ecCalcTaxSaved" style="color:var(--green,#22c55e)">₹0</span></div>
      </div>
      <div id="ecDeadlineWarn" style="display:none"></div>
    </div>
    <div class="ec-form-actions">
      <button class="btn btn-secondary" onclick="ecCloseModal()">Cancel</button>
      <button class="btn btn-primary" onclick="ecSave()">💾 Save Bond</button>
    </div>
  </div>
</div>

<script>
// ── State ─────────────────────────────────────────────────────────────
let ecBonds = [], ecSearchVal = '';

const INR = v => '₹' + Number(v||0).toLocaleString('en-IN',{maximumFractionDigits:0});

// ── Load ──────────────────────────────────────────────────────────────
async function ecLoad() {
  const res = await fetch('<?= APP_URL ?>/api/index.php?action=bonds54ec_list', {credentials:'include'});
  const j = await res.json();
  if (!j.success) { console.error(j.message); return; }
  ecBonds = j.data.bonds || [];
  ecRenderSummary(j.data.summary || {});
  ecRenderTable();
}

function ecRenderSummary(s) {
  document.getElementById('ecSumInvested').textContent  = INR(s.total_invested);
  document.getElementById('ecSumCount').textContent      = (s.count || 0) + ' bonds';
  document.getElementById('ecSumMaturity').textContent  = INR(s.total_maturity);
  document.getElementById('ecSumLtcg').textContent      = INR(s.total_ltcg_saved);
  document.getElementById('ecSumInterest').textContent  = INR(s.total_interest);
}

function ecFilterTable(v) { ecSearchVal = v.toLowerCase(); ecRenderTable(); }

function ecRenderTable() {
  const tbody = document.getElementById('ecTbody');
  const data = ecBonds.filter(b =>
    !ecSearchVal ||
    (b.bond_issuer||'').toLowerCase().includes(ecSearchVal) ||
    (b.issuer_name||'').toLowerCase().includes(ecSearchVal) ||
    (b.folio_number||'').toLowerCase().includes(ecSearchVal)
  );

  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="14">
      <div class="ec-empty">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <p style="font-size:15px;font-weight:600;margin:0 0 6px">No 54EC Bonds found</p>
        <p style="font-size:13px;margin:0">Add bonds to track your LTCG exemption reinvestments.</p>
      </div>
    </td></tr>`;
    return;
  }

  tbody.innerHTML = data.map((b, i) => {
    const c = b.calc || {};
    const totalYrs = parseFloat(c.total_years) || 5;
    const elapsedYrs = parseFloat(c.elapsed_years) || 0;
    const pct = Math.min(100, Math.round((elapsedYrs / totalYrs) * 100));
    const isMatured = c.is_matured;
    const lockMsg = c.lock_status || '';

    let statusHtml = '';
    if (isMatured) {
      statusHtml = '<span class="ec-badge matured">Matured</span>';
    } else if (lockMsg) {
      statusHtml = '<span class="ec-badge lockin">Lock-in</span>';
    } else {
      statusHtml = '<span class="ec-badge active">Active</span>';
    }

    const issuer = b.bond_issuer === 'OTHER' ? (b.issuer_name || 'OTHER') : b.bond_issuer;

    return `<tr>
      <td style="color:var(--text-secondary);font-size:12px">${i+1}</td>
      <td><span class="ec-issuer-badge ${b.bond_issuer}">${issuer}</span></td>
      <td>${fmtDate(b.investment_date)}</td>
      <td>${fmtDate(b.maturity_date)}</td>
      <td>${INR(b.face_value)}</td>
      <td style="text-align:center;font-weight:700">${b.num_bonds}</td>
      <td style="font-weight:700">${INR(b.total_invested)}</td>
      <td style="color:var(--orange,#f97316);font-weight:600">${INR(b.ltcg_exempted)}</td>
      <td style="text-align:center">${parseFloat(b.interest_rate).toFixed(2)}%</td>
      <td style="color:var(--teal,#14b8a6);font-weight:600">${INR(c.interest_earned)}</td>
      <td style="font-weight:700;color:var(--green,#22c55e)">${INR(c.maturity_value)}</td>
      <td>
        <div style="font-size:11px;color:var(--text-secondary)">${pct}%</div>
        <div class="ec-progress-bar"><div class="ec-progress-fill" style="width:${pct}%;background:var(--primary)"></div></div>
        ${lockMsg ? `<div style="font-size:10px;color:var(--text-secondary);margin-top:3px">${lockMsg}</div>` : ''}
      </td>
      <td>${statusHtml}</td>
      <td>
        <div style="display:flex;gap:4px">
          <button class="ec-act-btn" onclick="ecOpenEdit(${b.id})">✏️</button>
          <button class="ec-act-btn del" onclick="ecDelete(${b.id},'${issuer}')">🗑</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d + 'T00:00:00');
  return dt.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
}

// ── Modal ─────────────────────────────────────────────────────────────
function ecOpenAdd() {
  document.getElementById('ecModalTitle').textContent = 'Add 54EC Bond';
  document.getElementById('ecEditId').value = '';
  document.getElementById('ecIssuer').value = 'REC';
  document.getElementById('ecIssuerName').value = '';
  document.getElementById('ecSaleDate').value = '';
  document.getElementById('ecInvDate').value = '';
  document.getElementById('ecFaceVal').value = '10000';
  document.getElementById('ecNumBonds').value = '1';
  document.getElementById('ecMatDate').value = '';
  document.getElementById('ecRate').value = '5.00';
  document.getElementById('ecFreq').value = 'annual';
  document.getElementById('ecLtcg').value = '';
  document.getElementById('ecFolio').value = '';
  document.getElementById('ecNotes').value = '';
  ecUpdateIssuerName();
  ecCalcTotal();
  document.getElementById('ecModal').classList.add('open');
}

function ecOpenEdit(id) {
  const b = ecBonds.find(x => x.id == id);
  if (!b) return;
  document.getElementById('ecModalTitle').textContent = 'Edit 54EC Bond';
  document.getElementById('ecEditId').value = b.id;
  document.getElementById('ecIssuer').value = b.bond_issuer;
  document.getElementById('ecIssuerName').value = b.issuer_name || '';
  document.getElementById('ecSaleDate').value = b.sale_asset_date || '';
  document.getElementById('ecInvDate').value = b.investment_date;
  document.getElementById('ecFaceVal').value = b.face_value;
  document.getElementById('ecNumBonds').value = b.num_bonds;
  document.getElementById('ecMatDate').value = b.maturity_date;
  document.getElementById('ecRate').value = b.interest_rate;
  document.getElementById('ecFreq').value = b.interest_freq;
  document.getElementById('ecLtcg').value = b.ltcg_exempted || '';
  document.getElementById('ecFolio').value = b.folio_number || '';
  document.getElementById('ecNotes').value = b.notes || '';
  ecUpdateIssuerName();
  ecCalcTotal();
  ecUpdateDeadline();
  document.getElementById('ecModal').classList.add('open');
}

function ecCloseModal() { document.getElementById('ecModal').classList.remove('open'); }

function ecUpdateIssuerName() {
  const v = document.getElementById('ecIssuer').value;
  document.getElementById('ecIssuerNameGroup').style.display = v === 'OTHER' ? '' : 'none';
}

function ecCalcMaturity() {
  const inv = document.getElementById('ecInvDate').value;
  if (!inv) return;
  const d = new Date(inv);
  d.setFullYear(d.getFullYear() + 5);
  document.getElementById('ecMatDate').value = d.toISOString().split('T')[0];
  ecCalcTotal();
}

function ecCalcTotal() {
  const face = parseFloat(document.getElementById('ecFaceVal').value) || 10000;
  const num  = parseInt(document.getElementById('ecNumBonds').value) || 1;
  const rate = parseFloat(document.getElementById('ecRate').value) || 5;
  const total = face * num;
  const interest = total * (rate / 100) * 5;
  const matVal = total + interest;
  const ltcg = parseFloat(document.getElementById('ecLtcg').value) || 0;
  const taxSaved = ltcg * 0.20; // 20% LTCG on property

  document.getElementById('ecCalcTotal').textContent    = INR(total);
  document.getElementById('ecCalcMaturity').textContent = INR(matVal);
  document.getElementById('ecCalcInterest').textContent = INR(interest);
  document.getElementById('ecCalcTaxSaved').textContent = ltcg > 0 ? INR(taxSaved) + ' saved' : '—';
}

function ecUpdateDeadline() {
  const sale = document.getElementById('ecSaleDate').value;
  const inv  = document.getElementById('ecInvDate').value;
  const el   = document.getElementById('ecDeadlineWarn');
  if (!sale) { el.style.display = 'none'; return; }

  const saleD = new Date(sale);
  const deadlineD = new Date(sale);
  deadlineD.setMonth(deadlineD.getMonth() + 6);
  const invD = inv ? new Date(inv) : new Date();
  const daysLeft = Math.ceil((deadlineD - new Date()) / 86400000);
  const daysElapsed = Math.ceil((invD - saleD) / 86400000);

  el.style.display = '';
  if (inv && invD > deadlineD) {
    el.className = 'ec-deadline-warn over';
    el.textContent = '⚠️ Investment date exceeds 6-month window from sale date! Exemption may be disallowed.';
  } else if (daysLeft < 0) {
    el.className = 'ec-deadline-warn over';
    el.textContent = '❌ 6-month investment window has expired.';
  } else if (daysLeft < 30) {
    el.className = 'ec-deadline-warn warn';
    el.textContent = `⏳ Only ${daysLeft} days left to invest! Deadline: ${fmtDate(deadlineD.toISOString().split('T')[0])}`;
  } else {
    el.className = 'ec-deadline-warn ok';
    el.textContent = `✅ Investment deadline: ${fmtDate(deadlineD.toISOString().split('T')[0])} (${daysLeft} days left)`;
  }
}

// ── Save ──────────────────────────────────────────────────────────────
async function ecSave() {
  const editId = document.getElementById('ecEditId').value;
  const action = editId ? 'bonds54ec_edit' : 'bonds54ec_add';

  const body = new FormData();
  body.append('action', action);
  if (editId) body.append('id', editId);
  body.append('bond_issuer',     document.getElementById('ecIssuer').value);
  body.append('issuer_name',     document.getElementById('ecIssuerName').value);
  body.append('sale_asset_date', document.getElementById('ecSaleDate').value);
  body.append('investment_date', document.getElementById('ecInvDate').value);
  body.append('maturity_date',   document.getElementById('ecMatDate').value);
  body.append('face_value',      document.getElementById('ecFaceVal').value);
  body.append('num_bonds',       document.getElementById('ecNumBonds').value);
  body.append('interest_rate',   document.getElementById('ecRate').value);
  body.append('interest_freq',   document.getElementById('ecFreq').value);
  body.append('ltcg_exempted',   document.getElementById('ecLtcg').value || 0);
  body.append('folio_number',    document.getElementById('ecFolio').value);
  body.append('notes',           document.getElementById('ecNotes').value);

  // CSRF
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) body.append('csrf_token', csrfMeta.content);

  const invDate = document.getElementById('ecInvDate').value;
  if (!invDate) { alert('Investment date is required.'); return; }

  const res = await fetch('<?= APP_URL ?>/api/index.php', {method:'POST', credentials:'include', body});
  const j = await res.json();
  if (j.success) {
    ecCloseModal();
    ecLoad();
    if (window.showToast) showToast(j.message || 'Bond saved!', 'success');
  } else {
    alert(j.message || 'Save failed.');
  }
}

// ── Delete ────────────────────────────────────────────────────────────
async function ecDelete(id, name) {
  if (!confirm(`Delete ${name} bond? This cannot be undone.`)) return;
  const body = new FormData();
  body.append('action', 'bonds54ec_delete');
  body.append('id', id);
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) body.append('csrf_token', csrfMeta.content);
  const res = await fetch('<?= APP_URL ?>/api/index.php', {method:'POST', credentials:'include', body});
  const j = await res.json();
  if (j.success) { ecLoad(); if (window.showToast) showToast('Bond deleted.', 'success'); }
  else alert(j.message || 'Delete failed.');
}

// Close modal on overlay click
document.getElementById('ecModal').addEventListener('click', function(e) {
  if (e.target === this) ecCloseModal();
});

// ── Init ──────────────────────────────────────────────────────────────
ecLoad();
</script>

<?php
$content = ob_get_clean();
require APP_ROOT . '/templates/layout.php';
