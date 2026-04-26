<?php
/**
 * WealthDash — Tax Slab Calculator 2024-25
 * Task t496: Old vs New regime side-by-side with all deductions
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser = require_auth();
$pageTitle   = 'Tax Calculator 2024-25';
$activePage  = 'report_tax';
$activeSection = 'reports';

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">🧾 Tax Calculator 2024-25</h1>
    <p class="page-subtitle">Old vs New regime — find which saves more for you</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start;" id="taxCalcLayout">

  <!-- ── Input Panel ── -->
  <div class="card" style="position:sticky;top:80px;">
    <div class="card-header"><span style="font-weight:600;">💰 Your Income & Deductions</span></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">

      <div>
        <label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px;font-weight:600;">Gross Annual Income (₹) *</label>
        <input type="number" id="tcIncome" placeholder="e.g. 1200000" min="0" step="10000"
               style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg-secondary);color:var(--text);font-size:15px;box-sizing:border-box;"
               oninput="tcCalc()">
        <div style="font-size:11px;color:var(--text-secondary);margin-top:3px;">Salary / Business income before any deductions</div>
      </div>

      <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;">
        <div style="font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Old Regime Deductions</div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label style="font-size:11px;color:var(--text-secondary);display:block;margin-bottom:3px;">80C (ELSS/PF/LIC)</label>
            <input type="number" id="tc80c" placeholder="max ₹1.5L" max="150000" step="5000"
                   style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:13px;box-sizing:border-box;"
                   oninput="tcCalc()">
          </div>
          <div>
            <label style="font-size:11px;color:var(--text-secondary);display:block;margin-bottom:3px;">HRA Exemption</label>
            <input type="number" id="tcHra" placeholder="e.g. 120000" step="5000"
                   style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:13px;box-sizing:border-box;"
                   oninput="tcCalc()">
          </div>
          <div>
            <label style="font-size:11px;color:var(--text-secondary);display:block;margin-bottom:3px;">Home Loan Int. (Sec 24b)</label>
            <input type="number" id="tcHomeLoan" placeholder="max ₹2L" max="200000" step="5000"
                   style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:13px;box-sizing:border-box;"
                   oninput="tcCalc()">
          </div>
          <div>
            <label style="font-size:11px;color:var(--text-secondary);display:block;margin-bottom:3px;">NPS 80CCD(1B)</label>
            <input type="number" id="tcNps" placeholder="max ₹50K" max="50000" step="5000"
                   style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:13px;box-sizing:border-box;"
                   oninput="tcCalc()">
          </div>
          <div style="grid-column:span 2;">
            <label style="font-size:11px;color:var(--text-secondary);display:block;margin-bottom:3px;">Other Deductions (80D, 80E, etc.)</label>
            <input type="number" id="tcOther" placeholder="e.g. 25000" step="5000"
                   style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:13px;box-sizing:border-box;"
                   oninput="tcCalc()">
          </div>
        </div>
      </div>

      <button onclick="tcCalc()" style="width:100%;padding:12px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;">
        Calculate Tax →
      </button>

      <div style="font-size:11px;color:var(--text-secondary);line-height:1.5;">
        💡 New regime: ₹75K std. deduction, rebate u/s 87A up to ₹7L income.<br>
        Old regime: ₹50K std. deduction + all claimed deductions.<br>
        Surcharge & 4% cess included.
      </div>
    </div>
  </div>

  <!-- ── Results Panel ── -->
  <div id="tcResults" style="display:none;">

    <!-- Verdict banner -->
    <div id="tcVerdict" style="padding:16px 20px;border-radius:12px;margin-bottom:16px;font-size:14px;font-weight:600;"></div>

    <!-- Side-by-side comparison -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

      <!-- New Regime -->
      <div class="card" id="newRegimeCard">
        <div class="card-header" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:10px 10px 0 0;">
          <span style="color:#fff;font-weight:700;font-size:15px;">🆕 New Regime</span>
          <span id="newRegimeBadge" style="display:none;background:#fff;color:#6366f1;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:700;margin-left:8px;">BETTER</span>
        </div>
        <div class="card-body" id="newRegimeBody" style="padding:16px;"></div>
      </div>

      <!-- Old Regime -->
      <div class="card" id="oldRegimeCard">
        <div class="card-header" style="background:linear-gradient(135deg,#0891b2,#0e7490);border-radius:10px 10px 0 0;">
          <span style="color:#fff;font-weight:700;font-size:15px;">📋 Old Regime</span>
          <span id="oldRegimeBadge" style="display:none;background:#fff;color:#0891b2;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:700;margin-left:8px;">BETTER</span>
        </div>
        <div class="card-body" id="oldRegimeBody" style="padding:16px;"></div>
      </div>
    </div>

    <!-- Slab breakdown -->
    <div class="card">
      <div class="card-header"><span style="font-weight:600;">📊 Slab-wise Breakdown</span></div>
      <div class="card-body" style="padding:16px;overflow-x:auto;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;" id="tcSlabGrid"></div>
      </div>
    </div>

  </div>

  <!-- Empty state -->
  <div id="tcEmpty" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 20px;color:var(--text-secondary);">
    <div style="font-size:48px;margin-bottom:12px;">🧾</div>
    <div style="font-size:15px;font-weight:600;margin-bottom:6px;">Enter your income to calculate</div>
    <div style="font-size:13px;">Compare Old vs New regime for FY 2024-25</div>
  </div>
</div>

<script>
const BASE = window.APP_URL || window.WD?.appUrl || '';
const CSRF = window.WD?.csrf || window.CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '';

const inr = v => '₹' + Number(v||0).toLocaleString('en-IN',{maximumFractionDigits:0});
const pct = v => Number(v||0).toFixed(2) + '%';

let _tcTimer = null;
function tcCalc() {
  clearTimeout(_tcTimer);
  _tcTimer = setTimeout(_doTcCalc, 400);
}

async function _doTcCalc() {
  const income = parseFloat(document.getElementById('tcIncome')?.value || 0);
  if (!income || income <= 0) {
    document.getElementById('tcResults').style.display = 'none';
    document.getElementById('tcEmpty').style.display   = '';
    return;
  }

  const fd = new FormData();
  fd.append('action','tax_slab_calc');
  fd.append('income', income);
  fd.append('deductions_80c',    document.getElementById('tc80c')?.value     || 0);
  fd.append('hra',               document.getElementById('tcHra')?.value      || 0);
  fd.append('home_loan_interest',document.getElementById('tcHomeLoan')?.value || 0);
  fd.append('nps_80ccd',         document.getElementById('tcNps')?.value      || 0);
  fd.append('other_deductions',  document.getElementById('tcOther')?.value    || 0);
  if (CSRF) fd.append('_csrf_token', CSRF);

  try {
    const res  = await fetch(`${BASE}/api/?action=tax_slab_calc`, {method:'POST',body:fd});
    const data = await res.json();
    if (!data.success) { console.error(data); return; }
    renderTaxResult(data.data);
  } catch(e) { console.error(e); }
}

function renderTaxResult(d) {
  document.getElementById('tcResults').style.display = '';
  document.getElementById('tcEmpty').style.display   = 'none';

  const better = d.better_regime;
  const nr = d.new_regime;
  const or = d.old_regime;

  // Verdict
  const verdict = document.getElementById('tcVerdict');
  if (verdict) {
    const color = better === 'new' ? '#6366f1' : '#0891b2';
    verdict.style.background = color + '15';
    verdict.style.border     = `1px solid ${color}44`;
    verdict.style.color      = color;
    verdict.innerHTML = (better === 'new'
      ? `🆕 <strong>New Regime is better</strong> — saves ₹${Number(d.savings||0).toLocaleString('en-IN')} more. ${d.recommendation}`
      : `📋 <strong>Old Regime is better</strong> — saves ₹${Number(d.savings||0).toLocaleString('en-IN')} more. ${d.recommendation}`);
  }

  // Badges
  document.getElementById('newRegimeBadge').style.display = better==='new' ? '' : 'none';
  document.getElementById('oldRegimeBadge').style.display = better==='old' ? '' : 'none';

  // Render regime card
  function regimeCard(r) {
    const rows = [
      ['Gross Income',       inr(r.gross_income)],
      ['Std. Deduction',     '- ' + inr(r.std_deduction)],
      r.deductions_80c   ? ['80C Deductions',    '- ' + inr(r.deductions_80c)]   : null,
      r.hra_exemption    ? ['HRA Exemption',      '- ' + inr(r.hra_exemption)]    : null,
      r.home_loan_int    ? ['Home Loan Int.',      '- ' + inr(r.home_loan_int)]    : null,
      r.nps_80ccd        ? ['NPS 80CCD(1B)',       '- ' + inr(r.nps_80ccd)]        : null,
      r.other_deductions ? ['Other Deductions',   '- ' + inr(r.other_deductions)] : null,
      ['<strong>Taxable Income</strong>', `<strong>${inr(r.taxable_income)}</strong>`],
      ['Income Tax',        inr(r.income_tax)],
      r.rebate_87a > 0 ? ['Rebate 87A',        '- ' + inr(r.rebate_87a)] : null,
      r.surcharge > 0  ? ['Surcharge',          inr(r.surcharge)] : null,
      ['Health & Edu. Cess (4%)', inr(r.health_edu_cess)],
      ['<strong>Total Tax</strong>', `<strong style="font-size:16px;">${inr(r.total_tax)}</strong>`],
      ['Effective Rate',    `<span style="color:var(--accent)">${pct(r.effective_rate)}</span>`],
      ['<strong>Take-Home</strong>', `<strong style="color:#16a34a;font-size:16px;">${inr(r.take_home)}</strong>`],
    ].filter(Boolean);

    return rows.map(([k,v]) => `
      <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">
        <span style="color:var(--text-secondary);">${k}</span>
        <span style="text-align:right;">${v}</span>
      </div>`).join('');
  }

  document.getElementById('newRegimeBody').innerHTML = regimeCard(nr);
  document.getElementById('oldRegimeBody').innerHTML = regimeCard(or);

  // Slab breakdown (visual bars)
  const newSlabs = [
    { range:'₹0 – ₹3L',     rate:'0%',   regime:'new' },
    { range:'₹3L – ₹7L',    rate:'5%',   regime:'new' },
    { range:'₹7L – ₹10L',   rate:'10%',  regime:'new' },
    { range:'₹10L – ₹12L',  rate:'15%',  regime:'new' },
    { range:'₹12L – ₹15L',  rate:'20%',  regime:'new' },
    { range:'Above ₹15L',   rate:'30%',  regime:'new' },
  ];
  const oldSlabs = [
    { range:'₹0 – ₹2.5L',   rate:'0%',   regime:'old' },
    { range:'₹2.5L – ₹5L',  rate:'5%',   regime:'old' },
    { range:'₹5L – ₹10L',   rate:'20%',  regime:'old' },
    { range:'Above ₹10L',   rate:'30%',  regime:'old' },
  ];

  const slabHtml = (slabs, label, color) => `
    <div>
      <div style="font-size:12px;font-weight:700;color:${color};margin-bottom:8px;">${label}</div>
      ${slabs.map(s => `
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
          <span style="font-size:11px;width:130px;color:var(--text-secondary);">${s.range}</span>
          <div style="flex:1;height:20px;background:var(--border);border-radius:4px;overflow:hidden;">
            <div style="height:100%;width:${parseInt(s.rate)||0}%;background:${color};opacity:.8;border-radius:4px;min-width:${parseInt(s.rate)>0?2:0}px;"></div>
          </div>
          <span style="font-size:12px;font-weight:700;width:32px;text-align:right;color:${parseInt(s.rate)>0?color:'var(--text-secondary)'};">${s.rate}</span>
        </div>`).join('')}
    </div>`;

  document.getElementById('tcSlabGrid').innerHTML =
    slabHtml(newSlabs, '🆕 New Regime Slabs', '#6366f1') +
    slabHtml(oldSlabs, '📋 Old Regime Slabs', '#0891b2');
}

// Auto-calculate if income pre-filled
document.addEventListener('DOMContentLoaded', () => {
  const inc = document.getElementById('tcIncome');
  if (inc) inc.focus();
});
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
