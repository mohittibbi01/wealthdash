/**
 * WealthDash — Post Office Schemes JS
 * Handles: list, add, edit, delete, close, filter, preview
 */
'use strict';

const PO = (() => {


  // ── Direct API helpers — calls po_schemes.php directly (bypasses router) ──
  const PO_API_BASE = document.querySelector('meta[name="app-url"]')?.content || '';
  const PO_ENDPOINT = PO_API_BASE + '/api/post_office/po_schemes.php';
  async function poApiPost(data) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const resp = await fetch(PO_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: new URLSearchParams(data).toString(),
    });
    try {
      return await resp.json();
    } catch(e) {
      const txt = await resp.text().catch(() => 'empty');
      return { success: false, message: 'Server response error: ' + txt.substring(0, 200) };
    }
  }
  async function poApiGet(params) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const resp = await fetch(PO_ENDPOINT + '?' + new URLSearchParams(params).toString(), {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf },
    });
    try {
      return await resp.json();
    } catch(e) {
      return { success: false, message: 'Failed to load data. Please refresh.', data: [] };
    }
  }

  // ── State ────────────────────────────────────────────────────────────────
  let rows         = [];
  let currentType  = '';
  let currentStatus= 'active';
  let editingId    = null;
  let selectedType = '';
  let meta         = {};
  let deletingId   = null;

  // ── Scheme metadata (mirrors PHP, for preview calc) ──────────────────────
  const SCHEME_META = {
    savings_account: { label:'PO Savings Account', short:'PO Savings', rate:4.0, icon:'🏦', color:'#0369a1', hasMaturity:false, desc:'Basic savings @ 4% p.a.', compounding:'simple', freq:'yearly' },
    rd:   { label:'Post Office RD',   short:'PO RD',  rate:6.7, icon:'🔄', color:'#7c3aed', hasMaturity:true,  tenure:5,  desc:'5yr RD @ 6.7% quarterly compounding', compounding:'compound', freq:'quarterly' },
    td:   { label:'Post Office TD',   short:'PO TD',  rate:7.5, icon:'📅', color:'#0891b2', hasMaturity:true,  desc:'1/2/3/5yr TD up to 7.5%', compounding:'compound', freq:'yearly' },
    mis:  { label:'MIS',              short:'MIS',    rate:7.4, icon:'💰', color:'#059669', hasMaturity:true,  tenure:5,  desc:'5yr MIS @ 7.4% monthly payouts', compounding:'simple', freq:'monthly' },
    scss: { label:'SCSS',             short:'SCSS',   rate:8.2, icon:'👴', color:'#d97706', hasMaturity:true,  tenure:5,  desc:'5yr SCSS @ 8.2% quarterly (60+)', compounding:'simple', freq:'quarterly' },
    ppf:  { label:'PPF',              short:'PPF',    rate:7.1, icon:'🛡️', color:'#1d4ed8', hasMaturity:true,  tenure:15, desc:'15yr PPF @ 7.1% tax-free', compounding:'compound', freq:'yearly' },
    ssy:  { label:'SSY',              short:'SSY',    rate:8.2, icon:'👧', color:'#be185d', hasMaturity:true,  tenure:21, desc:'21yr SSY @ 8.2% for girl child', compounding:'compound', freq:'yearly' },
    nsc:  { label:'NSC',              short:'NSC',    rate:7.7, icon:'📜', color:'#0f766e', hasMaturity:true,  tenure:5,  desc:'5yr NSC @ 7.7% annually compounded', compounding:'compound', freq:'yearly' },
    kvp:  { label:'Kisan Vikas Patra',short:'KVP',   rate:7.5, icon:'🌾', color:'#15803d', hasMaturity:true,  desc:'Doubles in ~115 months @ 7.5%', compounding:'compound', freq:'yearly' },
  };

  // ── Helpers ───────────────────────────────────────────────────────────────
  const $  = id => document.getElementById(id);
  const fmtINR = n => {
    if (n == null || isNaN(n)) return '—';
    return '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };
  const fmtDate = str => {
    if (!str) return '—';
    const d = new Date(str);
    return d.toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' });
  };
  const daysDiff = dateStr => {
    if (!dateStr) return null;
    return Math.ceil((new Date(dateStr) - new Date()) / 86400000);
  };

  // ── Maturity preview calc (JS mirrors PHP) ────────────────────────────────
  function calcMaturity(type, principal, rate, openDate, maturityDate, depositAmt = 0) {
    if (!openDate || !maturityDate || rate <= 0) return null;
    const d1 = new Date(openDate), d2 = new Date(maturityDate);
    const days  = (d2 - d1) / 86400000;
    const years = days / 365;

    if (type === 'rd') {
      const P = depositAmt || principal;
      const r = rate / 100;
      const mat = P * ((Math.pow(1 + r/4, 4*years) - 1) / (1 - Math.pow(1 + r/4, -1/3)));
      return isFinite(mat) ? Math.round(mat * 100) / 100 : null;
    }
    if (['mis','scss','savings_account'].includes(type)) {
      return Math.round((principal + principal * (rate/100) * years) * 100) / 100;
    }
    // compound annually
    const mat = principal * Math.pow(1 + rate/100, years);
    return isFinite(mat) ? Math.round(mat * 100) / 100 : null;
  }

  // ── Build scheme grid in modal ────────────────────────────────────────────
  function buildSchemeGrid() {
    const grid = $('poSchemeGrid');
    if (!grid) return;
    grid.innerHTML = Object.entries(SCHEME_META).map(([key, sm]) => `
      <div class="po-scheme-card" data-type="${key}" onclick="PO.selectScheme('${key}')">
        <div class="sc-icon">${sm.icon}</div>
        <div class="sc-label">${sm.short}</div>
        <div class="sc-rate">${sm.rate}% p.a.</div>
      </div>
    `).join('');
  }

  // ── Select scheme type ─────────────────────────────────────────────────────
  function selectScheme(type) {
    selectedType = type;
    const sm = SCHEME_META[type];
    if (!sm) return;

    // highlight card
    document.querySelectorAll('.po-scheme-card').forEach(c =>
      c.classList.toggle('selected', c.dataset.type === type));

    // show/hide RD deposit field
    const isRD = type === 'rd';
    $('poRdGroup').style.display    = isRD ? '' : 'none';
    $('poPrincipalLabel').textContent = isRD ? 'Total Deposit (optional)' : 'Principal Amount (₹) *';

    // rate autofill
    if (!editingId) $('poRate').value = sm.rate;

    // auto-set maturity date if tenure known and openDate filled
    autoFillMaturity();

    // banner
    const banner = $('poSchemeBanner');
    banner.style.background = sm.color + '15';
    banner.style.border = `1px solid ${sm.color}40`;
    banner.innerHTML = `<strong style="color:${sm.color}">${sm.icon} ${sm.label}</strong> — ${sm.desc}
      ${sm.hasMaturity && sm.tenure ? `<span style="color:var(--text-muted);margin-left:8px">Tenure: ${sm.tenure} years</span>` : ''}`;

    $('poFormFields').style.display = '';
    $('savePo').style.display = '';
    $('schemeTypeGroup').querySelector('label').textContent = 'Scheme Type *  ✓ ' + sm.short;

    calcPreview();
  }

  function autoFillMaturity() {
    if (editingId) return;
    const sm = SCHEME_META[selectedType];
    if (!sm || !sm.tenure) return;
    const od = $('poOpenDate').value;
    if (!od) return;
    const d = new Date(od);
    d.setFullYear(d.getFullYear() + sm.tenure);
    $('poMaturityDate').value = d.toISOString().slice(0, 10);
  }

  // ── Preview calculation ────────────────────────────────────────────────────
  function calcPreview() {
    const principal   = parseFloat($('poPrincipal')?.value)   || 0;
    const depositAmt  = parseFloat($('poDeposit')?.value)     || 0;
    const rate        = parseFloat($('poRate')?.value)         || 0;
    const openDate    = $('poOpenDate')?.value;
    const matDate     = $('poMaturityDate')?.value;
    const type        = selectedType;

    if ((!principal && !depositAmt) || !rate || !openDate || !matDate || !type) {
      $('poPreview').style.display = 'none';
      return;
    }

    const mat = calcMaturity(type, principal, rate, openDate, matDate, depositAmt);
    if (!mat) { $('poPreview').style.display = 'none'; return; }

    const d1 = new Date(openDate), d2 = new Date(matDate);
    const days  = Math.round((d2 - d1) / 86400000);
    const years = (days / 365).toFixed(1);
    const p = principal || depositAmt;
    const interest = type === 'rd'
      ? mat - (depositAmt * Math.round(days / 30))
      : mat - p;

    $('prevTenure').textContent = `${years} yrs (${days} days)`;
    $('prevMat').textContent    = fmtINR(mat);
    $('prevInt').textContent    = fmtINR(Math.max(0, interest));
    $('poPreview').style.display = '';
  }

  // ── Load schemes ──────────────────────────────────────────────────────────
  async function load() {
    const body        = $('poBody');
    const portfolioId = $('poFilterPortfolio')?.value || '';
    body.innerHTML = `<tr><td colspan="13" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span> Loading...</td></tr>`;

    try {
      const res = await poApiGet({ action:'po_list', status:currentStatus, scheme_type:currentType, portfolio_id:portfolioId });
      if (!res.success) throw new Error(res.message);
      rows = res.data || [];
      renderTable();
    } catch(e) {
      body.innerHTML = `<tr><td colspan="13" class="text-center text-danger" style="padding:32px">Error: ${e.message}</td></tr>`;
    }
  }

  // ── Render table ──────────────────────────────────────────────────────────
  function renderTable() {
    const body   = $('poBody');
    const search = ($('poSearch')?.value || '').toLowerCase();

    let data = rows;
    if (search) data = data.filter(r =>
      (r.holder_name||'').toLowerCase().includes(search) ||
      (r.account_number||'').toLowerCase().includes(search) ||
      (r.post_office||'').toLowerCase().includes(search)
    );
    if (currentType) data = data.filter(r => r.scheme_type === currentType);

    if (!data.length) {
      body.innerHTML = `<tr><td colspan="13" class="text-center" style="padding:48px;color:var(--text-muted)">
        <div style="font-size:36px;margin-bottom:8px">📮</div>
        No ${currentStatus || ''} schemes found
      </td></tr>`;
      return;
    }

    body.innerHTML = data.map(r => {
      const sm      = SCHEME_META[r.scheme_type] || {};
      const days    = daysDiff(r.maturity_date);
      const interest = (parseFloat(r.maturity_amount)||0) - (parseFloat(r.principal)||0);
      const isActive = r.status === 'active';

      let statusBadge;
      if (!isActive) {
        statusBadge = `<span class="badge badge-neutral">${r.status}</span>`;
      } else if (days !== null && days <= 30) {
        statusBadge = `<span class="badge badge-danger">${days}d left</span>`;
      } else if (days !== null && days <= 90) {
        statusBadge = `<span class="badge badge-warning">${days}d left</span>`;
      } else {
        statusBadge = `<span class="badge badge-success">Active</span>`;
      }

      const dataEnc = encodeURIComponent(JSON.stringify(r));

      return `<tr>
        <td>
          <span style="display:inline-flex;align-items:center;gap:6px;">
            <span style="background:${sm.color}20;color:${sm.color};border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;">
              ${sm.icon||'📋'} ${sm.short||r.scheme_type}
            </span>
          </span>
        </td>
        <td><strong>${escapeHtml(r.holder_name||'—')}</strong>
          ${r.post_office ? `<br><small style="color:var(--text-muted);font-size:11px">${escapeHtml(r.post_office)}</small>` : ''}
        </td>
        <td style="font-family:monospace;font-size:12px;color:var(--text-muted)">
          ${r.account_number ? '••••' + String(r.account_number).slice(-4) : '—'}
        </td>
        <td>${escapeHtml(r.portfolio_name||'—')}</td>
        <td class="text-right">${fmtINR(r.principal)}</td>
        <td class="text-right">${parseFloat(r.interest_rate||0).toFixed(2)}%</td>
        <td>${fmtDate(r.open_date)}</td>
        <td>${r.maturity_date ? fmtDate(r.maturity_date) : '<span style="color:var(--text-muted)">Ongoing</span>'}</td>
        <td class="text-right text-success">${r.maturity_amount ? fmtINR(r.maturity_amount) : '—'}</td>
        <td class="text-right text-success">${interest > 0 ? fmtINR(interest) : '—'}</td>
        <td class="text-right">
          ${days !== null
            ? `<span style="color:${days<=30?'#dc2626':days<=90?'#d97706':'var(--text-muted)'};font-weight:${days<=90?'700':'400'}">${days}d</span>`
            : '—'}
        </td>
        <td>${statusBadge}</td>
        <td class="text-center">
          <div style="display:flex;gap:4px;justify-content:center">
            ${isActive ? `<button class="btn btn-xs btn-ghost" onclick="PO.closeScheme(${r.id})" title="Mark Matured / Close">✓</button>` : ''}
            <button class="btn btn-xs btn-ghost" onclick="PO.openEdit(decodeURIComponent('${dataEnc}'))" title="Edit">✎</button>
            <button class="btn btn-xs" style="background:#fee2e2;color:#dc2626;border:none;cursor:pointer;padding:4px 8px;border-radius:4px;font-size:12px"
                    onclick="PO.confirmDelete(${r.id})" title="Delete">✕</button>
          </div>
        </td>
      </tr>`;
    }).join('');
  }

  function filterTable() { renderTable(); }

  // ── Open Add Modal ─────────────────────────────────────────────────────────
  function openAdd() {
    editingId    = null;
    selectedType = '';
    $('poModalTitle').textContent = 'Add Post Office Scheme';
    $('poEditId').value = '';
    $('poFormFields').style.display = 'none';
    $('savePo').style.display = 'none';
    $('poError').style.display = 'none';
    $('poPreview').style.display = 'none';
    $('poHolder').value = '';
    $('poAccountNum').value = '';
    $('poPostOffice').value = '';
    $('poPrincipal').value = '';
    $('poDeposit').value = '';
    $('poRate').value = '';
    $('poOpenDate').value = '';
    $('poMaturityDate').value = '';
    $('poNominee').value = '';
    $('poJoint').value = '0';
    $('poNotes').value = '';
    $('poRdGroup').style.display = 'none';
    $('schemeTypeGroup').querySelector('label').textContent = 'Scheme Type *';
    buildSchemeGrid();
    document.querySelectorAll('.po-scheme-card').forEach(c => c.classList.remove('selected'));
    $('modalAddPo').style.display = 'flex';
  }

  // ── Open Edit Modal ────────────────────────────────────────────────────────
  function openEdit(jsonStr) {
    const r = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr;
    editingId    = r.id;
    selectedType = r.scheme_type;

    $('poModalTitle').textContent = 'Edit Scheme';
    $('poEditId').value = r.id;
    buildSchemeGrid();
    selectScheme(r.scheme_type); // sets banner, shows fields

    $('poPortfolio').value    = r.portfolio_id || '';
    $('poHolder').value       = r.holder_name  || '';
    $('poAccountNum').value   = r.account_number || '';
    $('poPostOffice').value   = r.post_office  || '';
    $('poPrincipal').value    = r.principal    || '';
    $('poDeposit').value      = r.deposit_amount || '';
    $('poRate').value         = r.interest_rate || '';
    $('poOpenDate').value     = r.open_date    || '';
    $('poMaturityDate').value = r.maturity_date || '';
    $('poNominee').value      = r.nominee      || '';
    $('poJoint').value        = r.is_joint     || '0';
    $('poNotes').value        = r.notes        || '';
    $('poError').style.display = 'none';

    calcPreview();
    $('modalAddPo').style.display = 'flex';
  }

  // ── Save ───────────────────────────────────────────────────────────────────
  async function save() {
    const btn = $('savePo');
    const errEl = $('poError');
    errEl.style.display = 'none';

    if (!selectedType) { errEl.textContent = 'Select a scheme type.'; errEl.style.display = 'block'; return; }
    const holder    = $('poHolder').value.trim();
    const principal = parseFloat($('poPrincipal').value) || 0;
    const deposit   = parseFloat($('poDeposit').value)   || 0;
    const rate      = parseFloat($('poRate').value)       || 0;
    const openDate  = $('poOpenDate').value;
    const isRD      = selectedType === 'rd';

    if (!holder)                        { errEl.textContent = 'Holder name is required.'; errEl.style.display = 'block'; return; }
    if (isRD && deposit <= 0)           { errEl.textContent = 'Monthly deposit is required for RD.'; errEl.style.display = 'block'; return; }
    if (!isRD && principal <= 0)        { errEl.textContent = 'Principal amount is required.'; errEl.style.display = 'block'; return; }
    if (rate <= 0)                      { errEl.textContent = 'Interest rate is required.'; errEl.style.display = 'block'; return; }
    if (!openDate)                      { errEl.textContent = 'Open date is required.'; errEl.style.display = 'block'; return; }

    btn.disabled = true; btn.textContent = 'Saving...';

    try {
      const payload = {
        action:         editingId ? 'po_edit' : 'po_add',
        id:             editingId || '',
        portfolio_id:   $('poPortfolio').value,
        scheme_type:    selectedType,
        holder_name:    holder,
        account_number: $('poAccountNum').value,
        post_office:    $('poPostOffice').value,
        principal:      principal,
        deposit_amount: deposit,
        interest_rate:  rate,
        open_date:      openDate,
        maturity_date:  $('poMaturityDate').value,
        nominee:        $('poNominee').value,
        is_joint:       $('poJoint').value,
        notes:          $('poNotes').value,
      };
      const res = await poApiPost(payload);
      if (!res.success) throw new Error(res.message);
      $('modalAddPo').style.display = 'none';
      load();
    } catch(e) {
      errEl.textContent = '✗ ' + e.message;
      errEl.style.display = 'block';
    } finally {
      btn.disabled = false; btn.textContent = 'Save Scheme';
    }
  }

  // ── Close / Mature ─────────────────────────────────────────────────────────
  async function closeScheme(id) {
    const status = confirm('Mark as Matured? (Click Cancel for "Closed")') ? 'matured' : 'closed';
    try {
      const res = await poApiPost({ action:'po_close', id, status });
      if (!res.success) throw new Error(res.message);
      load();
    } catch(e) { alert('Error: ' + e.message); }
  }

  // ── Delete ─────────────────────────────────────────────────────────────────
  function confirmDelete(id) {
    deletingId = id;
    $('modalDelPo').style.display = 'flex';
  }

  async function doDelete() {
    try {
      const res = await poApiPost({ action:'po_delete', id: deletingId });
      if (!res.success) throw new Error(res.message);
      $('modalDelPo').style.display = 'none';
      load();
    } catch(e) { alert('Error: ' + e.message); }
  }

  // ── Status tab toggle ──────────────────────────────────────────────────────
  function initTabs() {
    document.querySelectorAll('.mf-view-toggle .view-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.mf-view-toggle .view-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentStatus = btn.dataset.status || '';
        load();
      });
    });
  }

  // ── Scheme chips ───────────────────────────────────────────────────────────
  function initChips() {
    document.querySelectorAll('.po-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        document.querySelectorAll('.po-chip').forEach(c => c.classList.remove('po-chip-active'));
        chip.classList.add('po-chip-active');
        currentType = chip.dataset.type || '';
        renderTable(); // client-side filter
      });
    });
  }

  // ── Event listeners ────────────────────────────────────────────────────────
  function initEvents() {
    $('btnAddPo')?.addEventListener('click', openAdd);
    $('closePo')?.addEventListener('click', () => { $('modalAddPo').style.display = 'none'; });
    $('cancelPo')?.addEventListener('click', () => { $('modalAddPo').style.display = 'none'; });
    $('savePo')?.addEventListener('click', save);

    $('closeDelPo')?.addEventListener('click',   () => { $('modalDelPo').style.display = 'none'; });
    $('cancelDelPo')?.addEventListener('click',  () => { $('modalDelPo').style.display = 'none'; });
    $('confirmDelPo')?.addEventListener('click', doDelete);

    // Close modals on overlay click
    [$('modalAddPo'), $('modalDelPo')].forEach(modal => {
      modal?.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });
    });

    $('poOpenDate')?.addEventListener('change', autoFillMaturity);
  }

  // ── Init ──────────────────────────────────────────────────────────────────
  function init() {
    initTabs();
    initChips();
    initEvents();
    load();
  }

  document.addEventListener('DOMContentLoaded', init);

  // Public API
  return { load, filterTable, calcPreview, selectScheme, openEdit, confirmDelete, closeScheme };
})();