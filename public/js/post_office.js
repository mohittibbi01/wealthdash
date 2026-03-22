/**
 * WealthDash — Post Office Schemes JS
 * Handles: list, add, edit, delete, close, filter, preview
 */
'use strict';

const PO = (() => {


  // ── Direct API helpers — calls po_schemes.php directly (bypasses router) ──
  const PO_API_BASE = document.querySelector('meta[name="app-url"]')?.content || '';
  const PO_ENDPOINT = PO_API_BASE + '/api/po_schemes/po_schemes.php';
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
  let selectedTdYears = 0;
  let poPage       = 1;
  let poPerPage    = 10;

  // ── Scheme metadata (mirrors PHP, for preview calc) ──────────────────────
  const SCHEME_META = {
    savings_account: { label:'Post Office Savings Account', short:'PO Savings Account', rate:4.0, icon:'🏦', color:'#0369a1', hasMaturity:false, desc:'Basic savings @ 4% p.a.', compounding:'simple', freq:'yearly' },
    rd:   { label:'Recurring Deposit (RD)',              short:'RD',   rate:6.7, icon:'🔄', color:'#7c3aed', hasMaturity:true,  tenure:5,  desc:'5yr RD @ 6.7% quarterly compounding', compounding:'compound', freq:'quarterly',
            depositMin:100, depositMultiple:10,
            taxInfo: {
              oldRegime: 'No tax benefit',
              newRegime: 'No tax benefit',
              section80C: false,
              tds: false,
              interestTax: 'Interest is taxable on maturity — declare under "Income from Other Sources" as per your income tax slab.',
            },
            depositInfo: 'Min ₹100/month. No maximum limit. Amount must be in multiples of ₹10. Compounding: Quarterly.',
          },
    td: {
      label:'Time Deposit (TD)', short:'TD', icon:'📅', color:'#0891b2', hasMaturity:true,
      desc:'TD — quarterly compounding, interest paid annually', compounding:'compound', freq:'quarterly',
      depositMin: 1000, depositMultiple: 100,
      depositInfo: 'Min ₹1,000. No maximum limit. Amount must be in multiples of ₹100. One-time lump sum per account.',
      subTenures: [
        { years:1, rate:6.9, label:'1 Year',  rateLabel:'6.9% p.a.' },
        { years:2, rate:7.0, label:'2 Years', rateLabel:'7.0% p.a.' },
        { years:3, rate:7.1, label:'3 Years', rateLabel:'7.1% p.a.' },
        { years:5, rate:7.5, label:'5 Years', rateLabel:'7.5% p.a.' },
      ],
      // rate & tenure set dynamically by subTenure selection
      rate: 7.5,
      taxInfo: null, // set dynamically per tenure
      getTaxInfo(years) {
        const is5yr = years === 5;
        return {
          oldRegime: is5yr ? '80C deduction up to ₹1.5L on principal' : 'No tax benefit',
          newRegime: 'No benefit (80C not applicable)',
          section80C: is5yr ? '5-year TD only (old regime)' : false,
          tds: 'TDS if annual interest > ₹40,000 (₹50,000 for seniors)',
          interestTax: 'Interest taxable annually as "Income from Other Sources" per slab. TDS applicable if interest exceeds threshold.',
        };
      },
    },
    mis:  { label:'Monthly Income Scheme (MIS)',         short:'MIS',  rate:7.4, icon:'💰', color:'#059669', hasMaturity:true,  tenure:5,  desc:'5yr MIS @ 7.4% monthly payouts', compounding:'simple', freq:'monthly' },
    scss: { label:'Senior Citizen Savings Scheme (SCSS)',short:'SCSS', rate:8.2, icon:'👴', color:'#d97706', hasMaturity:true,  tenure:5,  desc:'5yr SCSS @ 8.2% quarterly (60+)', compounding:'simple', freq:'quarterly' },
    ppf:  { label:'Public Provident Fund (PPF)',         short:'PPF',  rate:7.1, icon:'🛡️', color:'#1d4ed8', hasMaturity:true,  tenure:15, desc:'15yr PPF @ 7.1% tax-free', compounding:'compound', freq:'yearly' },
    ssy:  { label:'Sukanya Samriddhi Yojana (SSY)',      short:'SSY',  rate:8.2, icon:'👧', color:'#be185d', hasMaturity:true,  tenure:21, desc:'21yr SSY @ 8.2% for girl child', compounding:'compound', freq:'yearly' },
    nsc:  { label:'National Savings Certificate (NSC)',  short:'NSC',  rate:7.7, icon:'📜', color:'#0f766e', hasMaturity:true,  tenure:5,  desc:'5yr NSC @ 7.7% annually compounded', compounding:'compound', freq:'yearly' },
    kvp:  { label:'Kisan Vikas Patra (KVP)',             short:'KVP',  rate:7.5, icon:'🌾', color:'#15803d', hasMaturity:true,  desc:'Doubles in ~115 months @ 7.5%', compounding:'compound', freq:'yearly' },
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

  // Format days into e.g. "12d", "2M 5D", "1Y 4M 3D"
  const fmtDaysLeft = days => {
    if (days === null || days < 0) return null;
    if (days < 30) return `${days}d`;
    const today = new Date();
    const future = new Date(today.getTime() + days * 86400000);
    let y = future.getFullYear() - today.getFullYear();
    let m = future.getMonth()   - today.getMonth();
    let d = future.getDate()    - today.getDate();
    if (d < 0) { m--; d += new Date(future.getFullYear(), future.getMonth(), 0).getDate(); }
    if (m < 0) { y--; m += 12; }
    const parts = [];
    if (y > 0) parts.push(`${y}Y`);
    if (m > 0) parts.push(`${m}M`);
    if (d > 0) parts.push(`${d}D`);
    return parts.join(' ') || '0d';
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
      // Use exact calendar months for RD (not days/365 which gives fractional years)
      const months = (d2.getFullYear() - d1.getFullYear()) * 12 + (d2.getMonth() - d1.getMonth());
      const n = months / 12;
      const mat = P * ((Math.pow(1 + r/4, 4*n) - 1) / (1 - Math.pow(1 + r/4, -1/3)));
      return isFinite(mat) ? Math.round(mat * 100) / 100 : null;
    }
    if (['mis','scss','savings_account'].includes(type)) {
      return Math.round((principal + principal * (rate/100) * years) * 100) / 100;
    }
    if (type === 'td') {
      // TD: quarterly compounding within each year, but interest PAID OUT annually (not reinvested)
      // Annual interest = P × [(1 + r/4)^4 - 1]  (same every year, no inter-year compounding)
      // Maturity = P + (annual_interest × n_years)
      const n = selectedTdYears > 0 ? selectedTdYears : years;
      const annualInterest = principal * (Math.pow(1 + rate/100/4, 4) - 1);
      const mat = principal + annualInterest * n;
      return isFinite(mat) ? Math.round(mat * 100) / 100 : null;
    }
    // compound annually (PPF, SSY, NSC, KVP)
    const mat = principal * Math.pow(1 + rate/100, years);
    return isFinite(mat) ? Math.round(mat * 100) / 100 : null;
  }

  // ── Build scheme grid in modal ────────────────────────────────────────────
  // ── Build tax info HTML (reusable for banner + TD dynamic update) ──────────
  function buildTaxInfoHtml(t) {
    const sec80c = t.section80C
      ? `<div style="font-weight:600;color:#16a34a">✓ ${typeof t.section80C === 'string' ? t.section80C : 'Eligible'}</div>`
      : `<div style="font-weight:600;color:#dc2626">✗ No deduction</div>`;
    const tdsHtml = (!t.tds || t.tds === false)
      ? `<div style="font-weight:600;color:#16a34a">✓ No TDS deducted</div>`
      : `<div style="font-weight:600;color:#d97706">⚠ ${t.tds}</div>`;
    const oldColor = t.section80C ? '#16a34a' : '#dc2626';
    const oldIcon  = t.section80C ? '✓' : '✗';
    return `<div style="margin-top:8px;padding:8px 10px;border-radius:6px;background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.18);font-size:12px">
      <div style="font-weight:700;color:#b91c1c;margin-bottom:6px">📊 Income Tax Details</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:6px">
        <div style="background:rgba(255,255,255,0.6);border-radius:5px;padding:5px 8px">
          <div style="color:var(--text-muted);font-size:10px;margin-bottom:2px">Old Tax Regime</div>
          <div style="font-weight:600;color:${oldColor}">${oldIcon} ${t.oldRegime}</div>
        </div>
        <div style="background:rgba(255,255,255,0.6);border-radius:5px;padding:5px 8px">
          <div style="color:var(--text-muted);font-size:10px;margin-bottom:2px">New Tax Regime</div>
          <div style="font-weight:600;color:#dc2626">✗ ${t.newRegime}</div>
        </div>
        <div style="background:rgba(255,255,255,0.6);border-radius:5px;padding:5px 8px">
          <div style="color:var(--text-muted);font-size:10px;margin-bottom:2px">Section 80C</div>
          ${sec80c}
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 2fr;gap:6px">
        <div style="background:rgba(255,255,255,0.6);border-radius:5px;padding:5px 8px">
          <div style="color:var(--text-muted);font-size:10px;margin-bottom:2px">TDS by Post Office</div>
          ${tdsHtml}
        </div>
        <div style="background:rgba(255,255,255,0.6);border-radius:5px;padding:5px 8px">
          <div style="color:var(--text-muted);font-size:10px;margin-bottom:2px">Interest Taxability</div>
          <div style="font-weight:600;color:#d97706">⚠ ${t.interestTax}</div>
        </div>
      </div>
    </div>`;
  }

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
    const isTD = type === 'td';
    $('poRdGroup').style.display       = isRD ? '' : 'none';
    $('poTdTenureGroup').style.display = isTD ? '' : 'none';
    // For RD, hide principal field; for TD, hide rate field (auto-set by tenure)
    $('poPrincipalGroup').style.display = isRD ? 'none' : '';
    $('poPrincipalLabel').textContent   = 'Principal Amount (₹) *';
    $('poRateGroup').style.display = isTD ? 'none' : '';

    // Make maturity date readonly for fixed-tenure schemes; editable for TD/KVP/savings
    // TD: maturity also locked but set by tenure button, not open date alone
    const fixedTenure = !!sm.tenure;
    const matField = $('poMaturityDate');
    matField.readOnly = fixedTenure || isTD;
    matField.style.background = (fixedTenure || isTD) ? 'var(--bg-secondary,#f3f4f6)' : '';
    matField.style.cursor     = (fixedTenure || isTD) ? 'not-allowed' : '';
    matField.title            = fixedTenure ? `Auto-calculated: ${sm.tenure}-year fixed tenure` : (isTD ? 'Set by selecting tenure above' : '');

    // rate autofill (not for TD — set by tenure selection)
    if (!editingId && !isTD) $('poRate').value = sm.rate;

    // For TD, reset tenure selection if opening fresh (not editing)
    if (isTD && !editingId) {
      $('poRate').value = '';
      $('poMaturityDate').value = '';
      document.querySelectorAll('.td-tenure-btn').forEach(b => b.classList.remove('selected'));
      // store selected TD tenure years
      selectedTdYears = 0;
    }

    // auto-set maturity date if tenure known and openDate filled
    autoFillMaturity();

    // banner
    const banner = $('poSchemeBanner');
    banner.style.background = sm.color + '15';
    banner.style.border = `1px solid ${sm.color}40`;

    let bannerHtml = `<div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <strong style="color:${sm.color}">${sm.icon} ${sm.label}</strong>
        <span style="color:var(--text-muted);margin-left:8px;font-size:12px">${sm.desc}</span>
        ${sm.hasMaturity && sm.tenure ? `<span style="color:var(--text-muted);margin-left:8px;font-size:12px">· Tenure: <strong>${sm.tenure} yrs</strong></span>` : ''}
      </div>
    </div>`;

    if (sm.depositInfo) {
      bannerHtml += `<div style="margin-top:8px;padding-top:8px;border-top:1px solid ${sm.color}25;display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary)">
        <span style="font-size:14px">📋</span><span>${sm.depositInfo}</span>
      </div>`;
    }

    // For TD: show placeholder tax section that updates when tenure is selected
    if (isTD) {
      bannerHtml += `<div id="poTdTaxSection" style="margin-top:8px">
        <div style="font-size:12px;color:var(--text-muted);padding:6px 8px;background:rgba(8,145,178,0.06);border-radius:6px;border:1px solid rgba(8,145,178,0.2)">
          📊 Select a tenure above to see tax details
        </div>
      </div>`;
    } else if (sm.taxInfo) {
      bannerHtml += buildTaxInfoHtml(sm.taxInfo);
    }

    banner.innerHTML = bannerHtml;

    $('poFormFields').style.display = '';
    $('savePo').style.display = '';
    $('schemeTypeGroup').querySelector('label').textContent = 'Scheme Type *  ✓ ' + sm.short;

    calcPreview();
  }

  function autoFillMaturity() {
    const sm = SCHEME_META[selectedType];
    if (!sm || !sm.tenure) return;   // TD/KVP/savings_account — user sets date manually
    const od = $('poOpenDate').value;
    if (!od) return;
    const d = new Date(od);
    d.setFullYear(d.getFullYear() + sm.tenure);
    $('poMaturityDate').value = d.toISOString().slice(0, 10);
    calcPreview();
  }

  // ── TD: select sub-tenure (1/2/3/5 yr) ────────────────────────────────────
  function selectTdTenure(years, rate) {
    selectedTdYears = years;
    // highlight selected button
    document.querySelectorAll('.td-tenure-btn').forEach(b =>
      b.classList.toggle('selected', parseInt(b.dataset.years) === years));
    // set rate field (hidden but used in calcPreview)
    $('poRate').value = rate;
    // auto-calculate maturity date from open date
    const od = $('poOpenDate').value;
    if (od) {
      const d = new Date(od);
      d.setFullYear(d.getFullYear() + years);
      $('poMaturityDate').value = d.toISOString().slice(0, 10);
    }
    // update tax info banner for this tenure
    const sm = SCHEME_META['td'];
    if (sm.getTaxInfo) {
      const taxSection = document.getElementById('poTdTaxSection');
      if (taxSection) taxSection.innerHTML = buildTaxInfoHtml(sm.getTaxInfo(years));
    }
    calcPreview();
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

    // Accurate tenure using calendar diff
    const d1 = new Date(openDate), d2 = new Date(matDate);
    const totalDays = Math.round((d2 - d1) / 86400000);
    let ty = d2.getFullYear() - d1.getFullYear();
    let tm = d2.getMonth()   - d1.getMonth();
    let td = d2.getDate()    - d1.getDate();
    if (td < 0) { tm--; td += new Date(d2.getFullYear(), d2.getMonth(), 0).getDate(); }
    if (tm < 0) { ty--; tm += 12; }
    const tenureParts = [];
    if (ty > 0) tenureParts.push(`${ty}Y`);
    if (tm > 0) tenureParts.push(`${tm}M`);
    if (td > 0) tenureParts.push(`${td}D`);
    const tenureLabel = (tenureParts.join(' ') || '0D') + ` (${totalDays} days)`;

    // RD interest = maturity - total amount deposited (exact calendar months)
    const months  = (d2.getFullYear() - d1.getFullYear()) * 12 + (d2.getMonth() - d1.getMonth());
    const interest = type === 'rd'
      ? mat - (depositAmt * months)
      : mat - principal;

    $('prevTenure').textContent = tenureLabel;
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

    const total  = data.length;
    const pages  = Math.max(1, Math.ceil(total / poPerPage));
    if (poPage > pages) poPage = 1;
    const start  = (poPage - 1) * poPerPage;
    const paged  = poPerPage >= 9999 ? data : data.slice(start, start + poPerPage);

    if (!total) {
      body.innerHTML = `<tr><td colspan="12" class="text-center" style="padding:48px;color:var(--text-muted)">
        <div style="font-size:36px;margin-bottom:8px">📮</div>
        No ${currentStatus || ''} schemes found
      </td></tr>`;
      renderPoPagination(0, 0, 0);
      return;
    }

    body.innerHTML = paged.map(r => {
      const sm      = SCHEME_META[r.scheme_type] || {};
      const days    = daysDiff(r.maturity_date);
      const isRD    = r.scheme_type === 'rd';
      const displayPrincipal = isRD && parseFloat(r.deposit_amount) > 0 ? r.deposit_amount : r.principal;
      const interest = (parseFloat(r.maturity_amount)||0) - (parseFloat(r.principal)||0);
      // Treat as matured if days_left is negative (auto-mature safety net)
      const isOverdue = days !== null && days < 0;
      const isActive = r.status === 'active' && !isOverdue;

      let statusBadge;
      if (r.status !== 'active' || isOverdue) {
        statusBadge = `<span class="badge badge-neutral">Matured</span>`;
      } else if (days !== null && days <= 30) {
        statusBadge = `<span class="badge badge-danger">${fmtDaysLeft(days)} left</span>`;
      } else if (days !== null && days <= 90) {
        statusBadge = `<span class="badge badge-warning">${fmtDaysLeft(days)} left</span>`;
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
        <td>${escapeHtml(r.holder_name||'—')}</strong>
          ${r.post_office ? `<br><small style="color:var(--text-muted);font-size:11px">${escapeHtml(r.post_office)}</small>` : ''}
        </td>
        <td style="font-family:monospace;font-size:12px;color:var(--text-muted)">
          ${r.account_number ? '••••' + String(r.account_number).slice(-4) : '—'}
        </td>
        <td class="text-right">${fmtINR(displayPrincipal)}${isRD ? '<br><small style="color:var(--text-muted);font-size:10px">per month</small>' : ''}</td>
        <td class="text-right">${parseFloat(r.interest_rate||0).toFixed(2)}%</td>
        <td>${fmtDate(r.open_date)}</td>
        <td>${r.maturity_date ? fmtDate(r.maturity_date) : '<span style="color:var(--text-muted)">Ongoing</span>'}</td>
        <td class="text-right text-success">${r.maturity_amount ? fmtINR(r.maturity_amount) : '—'}</td>
        <td class="text-right text-success">${interest > 0 ? fmtINR(interest) : '—'}</td>
        <td class="text-right">
          ${(() => {
            if (days === null || isOverdue) return `<span style="color:var(--text-muted)">—</span>`;
            const label = fmtDaysLeft(days);
            const color = days <= 30 ? '#dc2626' : days <= 90 ? '#d97706' : 'var(--text-muted)';
            const weight = days <= 90 ? '700' : '400';
            return `<span style="color:${color};font-weight:${weight}">${label}</span>`;
          })()}
        </td>
        <td>${statusBadge}</td>
        <td class="text-center">
          <div style="display:flex;gap:4px;justify-content:center">
            <button class="btn btn-xs btn-ghost" onclick="PO.openEdit(decodeURIComponent('${dataEnc}'))" title="Edit">✎</button>
            <button class="btn btn-xs" style="background:#fee2e2;color:#dc2626;border:none;cursor:pointer;padding:4px 8px;border-radius:4px;font-size:12px"
                    onclick="PO.confirmDelete(${r.id})" title="Delete">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/>
              </svg>
            </button>
          </div>
        </td>
      </tr>`;
    }).join('');

    renderPoPagination(total, pages, start);
  }

  function renderPoPagination(total, pages, start) {
    const wrap   = $('poPaginationWrap');
    const infoEl = $('poPaginationInfo');
    const pagEl  = $('poPagination');
    if (!wrap) return;
    wrap.style.display = total > 0 ? 'flex' : 'none';
    if (infoEl) infoEl.textContent = total > 0 ? `${start+1}–${Math.min(start+poPerPage,total)} of ${total}` : '';
    if (!pagEl || pages <= 1) { if (pagEl) pagEl.innerHTML = ''; return; }
    let html = '';
    if (poPage > 1) html += `<button class="btn btn-ghost btn-sm" onclick="PO.goPage(${poPage-1})">‹</button>`;
    const ps = Math.max(1, poPage-2), pe = Math.min(pages, poPage+2);
    for (let p = ps; p <= pe; p++) html += `<button class="btn btn-ghost btn-sm ${p===poPage?'active':''}" onclick="PO.goPage(${p})">${p}</button>`;
    if (poPage < pages) html += `<button class="btn btn-ghost btn-sm" onclick="PO.goPage(${poPage+1})">›</button>`;
    pagEl.innerHTML = html;
  }

  function goPage(p) { poPage = p; renderTable(); }
  function changePerPage(val) { poPerPage = parseInt(val); poPage = 1; renderTable(); }

  function filterTable() { poPage = 1; renderTable(); }

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
    $('poMaturityDate').readOnly = false;
    $('poMaturityDate').style.background = '';
    $('poMaturityDate').style.cursor = '';
    $('poNominee').value = '';
    $('poJoint').value = '0';
    $('poNotes').value = '';
    $('poRdGroup').style.display = 'none';
    $('poTdTenureGroup').style.display = 'none';
    $('poPrincipalGroup').style.display = '';
    selectedTdYears = 0;
    document.querySelectorAll('.td-tenure-btn').forEach(b => b.classList.remove('selected'));
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

    // For TD: restore tenure button selection from saved open/maturity dates
    if (r.scheme_type === 'td' && r.open_date && r.maturity_date) {
      const d1 = new Date(r.open_date), d2 = new Date(r.maturity_date);
      const savedYears = Math.round((d2.getFullYear() - d1.getFullYear()) +
        (d2.getMonth() - d1.getMonth()) / 12);
      const matchYears = [1,2,3,5].includes(savedYears) ? savedYears : 0;
      if (matchYears) {
        const rateMap = {1:6.9, 2:7.0, 3:7.1, 5:7.5};
        selectedTdYears = matchYears;
        document.querySelectorAll('.td-tenure-btn').forEach(b =>
          b.classList.toggle('selected', parseInt(b.dataset.years) === matchYears));
        // restore tax section
        const taxSection = document.getElementById('poTdTaxSection');
        if (taxSection) taxSection.innerHTML = buildTaxInfoHtml(SCHEME_META.td.getTaxInfo(matchYears));
      }
    }

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
    const isTD      = selectedType === 'td';

    if (!holder)                        { errEl.textContent = 'Holder name is required.'; errEl.style.display = 'block'; return; }
    if (isRD && deposit <= 0)           { errEl.textContent = 'Monthly deposit is required for RD.'; errEl.style.display = 'block'; return; }
    if (isRD && deposit < 100)          { errEl.textContent = 'Minimum monthly deposit for RD is ₹100.'; errEl.style.display = 'block'; return; }
    if (isRD && deposit % 10 !== 0)     { errEl.textContent = 'Monthly deposit must be in multiples of ₹10 (e.g. ₹100, ₹500, ₹1,000).'; errEl.style.display = 'block'; return; }
    if (!isRD && principal <= 0)        { errEl.textContent = 'Principal amount is required.'; errEl.style.display = 'block'; return; }
    if (isTD && principal < 1000)       { errEl.textContent = 'Minimum deposit for TD is ₹1,000.'; errEl.style.display = 'block'; return; }
    if (isTD && principal % 100 !== 0)  { errEl.textContent = 'TD deposit must be in multiples of ₹100.'; errEl.style.display = 'block'; return; }
    if (isTD && !selectedTdYears)       { errEl.textContent = 'Please select a tenure (1 / 2 / 3 / 5 years).'; errEl.style.display = 'block'; return; }
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
        poPage = 1;
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
        poPage = 1;
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
  return { load, filterTable, calcPreview, selectScheme, selectTdTenure, openEdit, confirmDelete, closeScheme, goPage, changePerPage };
})();
/* ═══════════════════════════════════════════════════════════════════════════
   t17 — PPF & SSY YEARLY DEPOSIT TRACKER
   localStorage-based FY-wise deposit log with 80C limit check
═══════════════════════════════════════════════════════════════════════════ */
const PPF_TRACKER_KEY = 'wd_ppf_ffy_v1';

function getPpfDeposits() {
  try { return JSON.parse(localStorage.getItem(PPF_TRACKER_KEY) || '{}'); } catch(e) { return {}; }
}
function savePpfDeposits(d) {
  try { localStorage.setItem(PPF_TRACKER_KEY, JSON.stringify(d)); } catch(e) {}
}

function currentFY() {
  const now = new Date();
  const y   = now.getFullYear();
  const m   = now.getMonth() + 1;
  return m >= 4 ? `${y}-${String(y+1).slice(-2)}` : `${y-1}-${String(y).slice(-2)}`;
}

function renderPpfTracker(containerId, schemeType) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const PPF_LIMIT = 150000; // ₹1.5L per year
  const SSY_LIMIT = 150000;
  const LIMIT     = PPF_LIMIT;
  const deposits  = getPpfDeposits();
  const fy        = currentFY();
  const fyData    = deposits[`${schemeType}_${fy}`] || { entries: [], total: 0 };
  const total     = fyData.entries ? fyData.entries.reduce((s,e) => s + e.amt, 0) : (fyData.total || 0);
  const remaining = Math.max(0, LIMIT - total);
  const pct       = Math.min(100, (total / LIMIT) * 100).toFixed(0);

  function fmtI(v) {
    return '₹' + Number(v).toLocaleString('en-IN', {maximumFractionDigits:0});
  }

  container.innerHTML = `
    <div style="margin-top:12px;padding:12px;background:rgba(29,78,216,.06);border-radius:9px;border:1px solid rgba(29,78,216,.2);">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span style="font-size:12px;font-weight:700;color:#1d4ed8;">📊 FY ${fy} Deposits — ${schemeType.toUpperCase()}</span>
        <button onclick="addPpfDeposit('${containerId}','${schemeType}')"
          style="font-size:11px;padding:3px 10px;border-radius:5px;background:#1d4ed8;color:#fff;border:none;cursor:pointer;font-weight:700;">+ Log</button>
      </div>
      <div style="height:8px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;margin-bottom:6px;">
        <div style="height:100%;width:${pct}%;background:${parseInt(pct)>=90?'#dc2626':'#1d4ed8'};border-radius:99px;transition:width .4s;"></div>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);">
        <span>Deposited: <strong style="color:var(--text-primary);">${fmtI(total)}</strong></span>
        <span>${parseInt(pct)}% of ₹1.5L limit</span>
        <span>Remaining: <strong style="color:${remaining>0?'#16a34a':'#dc2626'};">${fmtI(remaining)}</strong></span>
      </div>
      ${parseInt(pct) >= 100 ? '<div style="margin-top:6px;font-size:11px;color:#dc2626;font-weight:700;">⚠️ ₹1.5L limit reached! Additional deposits not 80C eligible.</div>' : ''}
      ${fyData.entries?.length ? `
      <div style="margin-top:8px;max-height:100px;overflow-y:auto;font-size:11px;">
        ${fyData.entries.slice(-5).reverse().map(e =>
          `<div style="display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px solid var(--border);">
            <span>${e.date}</span>
            <span style="font-weight:700;">${fmtI(e.amt)}</span>
          </div>`
        ).join('')}
      </div>` : ''}
    </div>`;
}

function addPpfDeposit(containerId, schemeType) {
  const amt = parseFloat(prompt('Enter deposit amount (₹):') || '0');
  if (!amt || amt <= 0) return;
  const deposits = getPpfDeposits();
  const fy       = currentFY();
  const key      = `${schemeType}_${fy}`;
  if (!deposits[key]) deposits[key] = { entries: [] };
  deposits[key].entries = deposits[key].entries || [];
  deposits[key].entries.push({ amt, date: new Date().toLocaleDateString('en-IN') });
  savePpfDeposits(deposits);
  renderPpfTracker(containerId, schemeType);
  // Check limit
  const total = deposits[key].entries.reduce((s,e) => s + e.amt, 0);
  if (total > 150000 && typeof showToast === 'function') {
    showToast('⚠️ PPF deposit exceeded ₹1.5L limit — excess not 80C eligible!', 'warning');
  } else if (typeof showToast === 'function') {
    showToast(`✅ ₹${amt.toLocaleString('en-IN')} logged for ${schemeType.toUpperCase()} FY ${fy}`, 'success');
  }
}

// Hook into onSelectScheme to show PPF tracker
const _origOnSelectScheme = typeof onSelectScheme !== 'undefined' ? onSelectScheme : null;
function onSelectSchemeHooked(type) {
  if (_origOnSelectScheme) _origOnSelectScheme(type);
  if (type === 'ppf' || type === 'ssy') {
    setTimeout(() => {
      const bannerId = 'ppfTrackerInline';
      const banner   = document.getElementById('poSchemeBanner');
      if (banner && !document.getElementById(bannerId)) {
        const div = document.createElement('div');
        div.id    = bannerId;
        banner.parentNode.insertBefore(div, banner.nextSibling);
      }
      renderPpfTracker(bannerId, type);
    }, 100);
  }
}
