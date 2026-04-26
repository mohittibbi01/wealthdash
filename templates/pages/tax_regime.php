<?php
/**
 * WealthDash — New vs Old Tax Regime Calculator
 * Task t85: Full-featured standalone calculator — FY 2024-25 (AY 2025-26)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser   = require_auth();
$pageTitle     = 'Tax Regime Calculator';
$activePage    = 'tax_regime';
$activeSection = 'reports';

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">⚖️ New vs Old Tax Regime</h1>
    <p class="page-subtitle">FY 2024-25 (AY 2025-26) — Find which regime saves you more tax</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost btn-sm" onclick="TR.reset()">Reset</button>
    <button class="btn btn-primary btn-sm" onclick="TR.calc()">Calculate →</button>
  </div>
</div>

<div id="trLayout" style="display:grid;grid-template-columns:400px 1fr;gap:20px;align-items:start;">

  <!-- ══ INPUT PANEL ══ -->
  <div style="display:flex;flex-direction:column;gap:16px;position:sticky;top:80px;">

    <!-- Income -->
    <div class="card">
      <div class="card-header"><span style="font-weight:700;">💰 Income Details</span></div>
      <div class="card-body" style="padding:16px;display:flex;flex-direction:column;gap:12px;">
        <div>
          <label class="tr-lbl">Gross Salary / CTC (₹) <span style="color:#ef4444">*</span></label>
          <input type="number" id="trIncome" class="tr-inp" placeholder="e.g. 1500000" oninput="TR.calc()">
          <div class="tr-hint">Annual income before any deductions</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label class="tr-lbl">Other Income (₹)</label>
            <input type="number" id="trOtherIncome" class="tr-inp" placeholder="FD int, rent…" oninput="TR.calc()">
          </div>
          <div>
            <label class="tr-lbl">Capital Gains (₹)</label>
            <input type="number" id="trCapGains" class="tr-inp" placeholder="STCG / LTCG" oninput="TR.calc()">
          </div>
        </div>
        <div>
          <label class="tr-lbl">Age Group</label>
          <select id="trAge" class="tr-inp" onchange="TR.calc()">
            <option value="general">Below 60 years (General)</option>
            <option value="senior">60–80 years (Senior Citizen)</option>
            <option value="supersenior">Above 80 years (Super Senior)</option>
          </select>
          <div class="tr-hint">Old regime basic exemption differs by age</div>
        </div>
      </div>
    </div>

    <!-- Old Regime Deductions -->
    <div class="card">
      <div class="card-header">
        <span style="font-weight:700;">📋 Old Regime Deductions</span>
        <span style="font-size:11px;color:var(--text-muted);">(not available in new regime)</span>
      </div>
      <div class="card-body" style="padding:16px;display:flex;flex-direction:column;gap:10px;">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label class="tr-lbl">80C — ELSS / PF / LIC (₹)</label>
            <input type="number" id="tr80c" class="tr-inp" placeholder="max ₹1,50,000" max="150000" oninput="TR.cap(this,150000);TR.calc()">
            <div class="tr-hint">Cap: ₹1.5L</div>
          </div>
          <div>
            <label class="tr-lbl">80CCD(1B) — NPS (₹)</label>
            <input type="number" id="trNps" class="tr-inp" placeholder="max ₹50,000" max="50000" oninput="TR.cap(this,50000);TR.calc()">
            <div class="tr-hint">Cap: ₹50K (over 80C)</div>
          </div>
          <div>
            <label class="tr-lbl">HRA Exemption (₹)</label>
            <input type="number" id="trHra" class="tr-inp" placeholder="actual exempt amt" oninput="TR.calc()">
          </div>
          <div>
            <label class="tr-lbl">Sec 24b — Home Loan Int. (₹)</label>
            <input type="number" id="trHomeLoan" class="tr-inp" placeholder="max ₹2,00,000" max="200000" oninput="TR.cap(this,200000);TR.calc()">
            <div class="tr-hint">Cap: ₹2L (self-occupied)</div>
          </div>
          <div>
            <label class="tr-lbl">80D — Medical Insurance (₹)</label>
            <input type="number" id="tr80d" class="tr-inp" placeholder="self+parents" oninput="TR.calc()">
            <div class="tr-hint">Self ₹25K + Parents ₹25K (sr. ₹50K)</div>
          </div>
          <div>
            <label class="tr-lbl">80E — Education Loan Int. (₹)</label>
            <input type="number" id="tr80e" class="tr-inp" placeholder="no limit" oninput="TR.calc()">
          </div>
          <div>
            <label class="tr-lbl">80TTA/TTB — Savings Int. (₹)</label>
            <input type="number" id="tr80tta" class="tr-inp" placeholder="max ₹10,000" max="10000" oninput="TR.cap(this,10000);TR.calc()">
            <div class="tr-hint">Cap: ₹10K (₹50K for seniors)</div>
          </div>
          <div>
            <label class="tr-lbl">80G — Donations (₹)</label>
            <input type="number" id="tr80g" class="tr-inp" placeholder="eligible amount" oninput="TR.calc()">
          </div>
        </div>

        <div>
          <label class="tr-lbl">Other Deductions (₹)</label>
          <input type="number" id="trOther" class="tr-inp" placeholder="LTA, 80DD, 80DDB, etc." oninput="TR.calc()">
        </div>

        <!-- Breakeven finder -->
        <div id="trBreakeven" style="display:none;padding:10px 12px;background:rgba(99,102,241,.07);border-radius:8px;border:1px solid rgba(99,102,241,.2);font-size:12px;color:var(--text-muted);line-height:1.6;"></div>
      </div>
    </div>

  </div><!-- /input panel -->

  <!-- ══ RESULTS PANEL ══ -->
  <div>

    <!-- Empty state -->
    <div id="trEmpty" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:80px 20px;color:var(--text-muted);">
      <div style="font-size:56px;margin-bottom:16px;">⚖️</div>
      <div style="font-size:16px;font-weight:600;margin-bottom:6px;">Enter your income to compare</div>
      <div style="font-size:13px;">Old vs New regime — FY 2024-25</div>
    </div>

    <div id="trResults" style="display:none;">

      <!-- Verdict -->
      <div id="trVerdict" style="padding:18px 20px;border-radius:12px;margin-bottom:16px;font-size:14px;font-weight:600;"></div>

      <!-- Side-by-side cards -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

        <!-- New Regime -->
        <div class="card" id="trNewCard">
          <div id="trNewHeader" style="padding:14px 16px;border-radius:10px 10px 0 0;display:flex;align-items:center;justify-content:space-between;">
            <span style="color:#fff;font-weight:700;font-size:15px;">🆕 New Regime</span>
            <span id="trNewBadge" style="display:none;background:#fff;color:#6366f1;font-size:11px;padding:3px 10px;border-radius:10px;font-weight:800;">SAVES MORE</span>
          </div>
          <div class="card-body" id="trNewBody" style="padding:16px;font-size:13px;"></div>
        </div>

        <!-- Old Regime -->
        <div class="card" id="trOldCard">
          <div id="trOldHeader" style="padding:14px 16px;border-radius:10px 0 0;display:flex;align-items:center;justify-content:space-between;">
            <span style="color:#fff;font-weight:700;font-size:15px;">📋 Old Regime</span>
            <span id="trOldBadge" style="display:none;background:#fff;color:#0891b2;font-size:11px;padding:3px 10px;border-radius:10px;font-weight:800;">SAVES MORE</span>
          </div>
          <div class="card-body" id="trOldBody" style="padding:16px;font-size:13px;"></div>
        </div>
      </div>

      <!-- Monthly take-home comparison -->
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span style="font-weight:600;">📅 Monthly Take-Home Comparison</span></div>
        <div class="card-body" id="trMonthly" style="padding:16px;"></div>
      </div>

      <!-- Slab breakdown -->
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span style="font-weight:600;">📊 Tax Slab Breakdown</span></div>
        <div class="card-body" style="padding:16px;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;" id="trSlabGrid"></div>
        </div>
      </div>

      <!-- Deduction Impact Table -->
      <div class="card" id="trDeductCard" style="display:none;">
        <div class="card-header"><span style="font-weight:600;">🎯 Your Deductions — Impact on Old Regime</span></div>
        <div class="card-body" style="padding:0;">
          <table class="table" style="font-size:13px;">
            <thead>
              <tr>
                <th>Deduction</th>
                <th class="text-right">Amount</th>
                <th class="text-right">Tax Saved</th>
                <th class="text-right">Effective Rate</th>
              </tr>
            </thead>
            <tbody id="trDeductBody"></tbody>
          </table>
        </div>
      </div>

    </div><!-- /trResults -->
  </div><!-- /results panel -->
</div>

<style>
.tr-lbl  { display:block;font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px; }
.tr-inp  { width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:7px;background:var(--bg-secondary);color:var(--text-primary);font-size:13px;box-sizing:border-box;transition:border-color .15s; }
.tr-inp:focus { outline:none;border-color:var(--accent); }
.tr-hint { font-size:10px;color:var(--text-muted);margin-top:3px;line-height:1.4; }
.tr-row  { display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px; }
.tr-row:last-child { border-bottom:none; }
@media(max-width:900px){
  #trLayout { grid-template-columns:1fr!important; }
  #trLayout > div:first-child { position:static!important; }
}
</style>

<script>
/* ═══════════════════════════════════════════════════════════════
   t85 — New vs Old Tax Regime Calculator  FY 2024-25 (AY 2025-26)
   Budget 2024 revised new regime slabs applied
   ═══════════════════════════════════════════════════════════════ */
const TR = {

  /* ─── Helpers ─── */
  inr(v) {
    v = Math.round(Math.abs(v));
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + ' Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN');
  },
  pct(v) { return (v||0).toFixed(2) + '%'; },
  cap(el, max) { if (parseFloat(el.value) > max) el.value = max; },
  reset() {
    ['trIncome','trOtherIncome','trCapGains','tr80c','trNps','trHra','trHomeLoan',
     'tr80d','tr80e','tr80tta','tr80g','trOther'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    document.getElementById('trAge').value = 'general';
    document.getElementById('trResults').style.display = 'none';
    document.getElementById('trEmpty').style.display   = '';
    document.getElementById('trBreakeven').style.display = 'none';
  },

  /* ─── NEW REGIME slabs — Budget 2024 ─── */
  calcNew(income) {
    const stdDed   = 75000;   // Budget 2024: ₹75K for salaried
    const taxable  = Math.max(0, income - stdDed);

    // Budget 2024 revised slabs
    const slabs = [
      [300000,  0.00],
      [700000,  0.05],
      [1000000, 0.10],
      [1200000, 0.15],
      [1500000, 0.20],
      [Infinity,0.30],
    ];
    let tax = 0, prev = 0;
    const slabDetails = [];
    for (const [lim, rate] of slabs) {
      if (taxable <= prev) break;
      const chunk = Math.min(taxable, lim) - prev;
      const t     = chunk * rate;
      tax += t;
      if (rate > 0) slabDetails.push({ range: this._slabLabel(prev, lim), rate, chunk, tax: t });
      prev = lim;
    }

    // 87A rebate — new regime: full rebate if taxable ≤ ₹7L
    let rebate = 0;
    if (taxable <= 700000) { rebate = Math.min(tax, 25000); tax = Math.max(0, tax - rebate); }

    const surcharge = this._surcharge(tax, taxable);
    const cess      = (tax + surcharge) * 0.04;
    const total     = tax + surcharge + cess;

    return {
      regime:'New Regime 2024-25', stdDed, taxable: Math.round(taxable),
      taxBeforeRebate: Math.round(tax + rebate), rebate: Math.round(rebate),
      incomeTax: Math.round(tax), surcharge: Math.round(surcharge),
      cess: Math.round(cess), total: Math.round(total),
      effectiveRate: income > 0 ? (total/income*100) : 0,
      takeHome: Math.round(income - total),
      slabDetails,
      deductions: { stdDed },
    };
  },

  /* ─── OLD REGIME slabs ─── */
  calcOld(income, ded, age) {
    const stdDed = 50000;
    const c80c   = Math.min(ded.c80c,    150000);
    const nps    = Math.min(ded.nps,      50000);
    const hra    = ded.hra;
    const hl     = Math.min(ded.homeLoan, 200000);
    const d80d   = ded.d80d;
    const d80e   = ded.d80e;
    const d80tta = Math.min(ded.d80tta, age === 'supersenior' || age === 'senior' ? 50000 : 10000);
    const d80g   = ded.d80g;
    const other  = ded.other;

    const totalDed = stdDed + c80c + nps + hra + hl + d80d + d80e + d80tta + d80g + other;
    const taxable  = Math.max(0, income - totalDed);

    // Basic exemption by age
    const exemptLimit = age === 'supersenior' ? 500000 : age === 'senior' ? 300000 : 250000;

    const slabs = age === 'supersenior'
      ? [[500000,0],[1000000,0.20],[Infinity,0.30]]
      : age === 'senior'
        ? [[300000,0],[500000,0.05],[1000000,0.20],[Infinity,0.30]]
        : [[250000,0],[500000,0.05],[1000000,0.20],[Infinity,0.30]];

    let tax = 0, prev = 0;
    const slabDetails = [];
    for (const [lim, rate] of slabs) {
      if (taxable <= prev) break;
      const chunk = Math.min(taxable, lim) - prev;
      const t     = chunk * rate;
      tax += t;
      if (rate > 0) slabDetails.push({ range: this._slabLabel(prev, lim), rate, chunk, tax: t });
      prev = lim;
    }

    // 87A rebate — old regime: taxable ≤ ₹5L → rebate ₹12,500
    let rebate = 0;
    if (taxable <= 500000) { rebate = Math.min(tax, 12500); tax = Math.max(0, tax - rebate); }

    const surcharge = this._surcharge(tax, taxable);
    const cess      = (tax + surcharge) * 0.04;
    const total     = tax + surcharge + cess;

    return {
      regime:'Old Regime 2024-25', stdDed, taxable: Math.round(taxable),
      taxBeforeRebate: Math.round(tax + rebate), rebate: Math.round(rebate),
      incomeTax: Math.round(tax), surcharge: Math.round(surcharge),
      cess: Math.round(cess), total: Math.round(total),
      effectiveRate: income > 0 ? (total/income*100) : 0,
      takeHome: Math.round(income - total),
      slabDetails,
      deductions: { stdDed, c80c, nps, hra, hl, d80d, d80e, d80tta, d80g, other, totalDed: Math.round(totalDed) },
    };
  },

  _surcharge(tax, taxable) {
    if (taxable > 20000000)  return tax * 0.25;
    if (taxable > 10000000)  return tax * 0.15;
    if (taxable > 5000000)   return tax * 0.10;
    return 0;
  },
  _slabLabel(from, to) {
    const f = this.inr(from);
    return to === Infinity ? `Above ${f}` : `${f} – ${this.inr(to)}`;
  },

  /* ─── Main calculate ─── */
  calc() {
    const income = parseFloat(document.getElementById('trIncome')?.value) || 0;
    if (income <= 0) {
      document.getElementById('trResults').style.display = 'none';
      document.getElementById('trEmpty').style.display   = '';
      return;
    }

    const otherInc  = parseFloat(document.getElementById('trOtherIncome')?.value) || 0;
    const capGains  = parseFloat(document.getElementById('trCapGains')?.value)     || 0;
    const age       = document.getElementById('trAge')?.value || 'general';
    const totalIncome = income + otherInc + capGains;

    const ded = {
      c80c:     parseFloat(document.getElementById('tr80c')?.value)     || 0,
      nps:      parseFloat(document.getElementById('trNps')?.value)      || 0,
      hra:      parseFloat(document.getElementById('trHra')?.value)      || 0,
      homeLoan: parseFloat(document.getElementById('trHomeLoan')?.value) || 0,
      d80d:     parseFloat(document.getElementById('tr80d')?.value)      || 0,
      d80e:     parseFloat(document.getElementById('tr80e')?.value)      || 0,
      d80tta:   parseFloat(document.getElementById('tr80tta')?.value)    || 0,
      d80g:     parseFloat(document.getElementById('tr80g')?.value)      || 0,
      other:    parseFloat(document.getElementById('trOther')?.value)    || 0,
    };

    const nr = this.calcNew(totalIncome);
    const or = this.calcOld(totalIncome, ded, age);

    const savings = or.total - nr.total;    // +ve = new better, -ve = old better
    const better  = savings >= 0 ? 'new' : 'old';

    document.getElementById('trResults').style.display = '';
    document.getElementById('trEmpty').style.display   = 'none';

    this._renderVerdict(better, Math.abs(savings), nr, or);
    this._renderCards(nr, or, better);
    this._renderMonthly(nr, or, totalIncome);
    this._renderSlabs(nr, or);
    this._renderDeductionImpact(ded, or, totalIncome);
    this._renderBreakeven(totalIncome, nr, age);
  },

  /* ─── Verdict banner ─── */
  _renderVerdict(better, savings, nr, or) {
    const el    = document.getElementById('trVerdict');
    const color = better === 'new' ? '#6366f1' : '#0891b2';
    const icon  = better === 'new' ? '🆕' : '📋';
    const name  = better === 'new' ? 'New Regime' : 'Old Regime';
    const hint  = better === 'new'
      ? 'Simpler filing, no deduction paperwork.'
      : 'Your deductions reduce taxable income significantly.';
    el.style.cssText = `padding:18px 20px;border-radius:12px;margin-bottom:16px;font-size:14px;font-weight:600;background:${color}10;border:1.5px solid ${color}33;color:${color};`;
    el.innerHTML = `${icon} <strong>${name} is better for you</strong> — saves <strong>${this.inr(savings)}</strong> more this FY. <span style="font-weight:400;color:var(--text-muted);font-size:13px;">${hint}</span>`;
  },

  /* ─── Regime cards ─── */
  _renderCards(nr, or, better) {
    // Headers
    const newH = document.getElementById('trNewHeader');
    const oldH = document.getElementById('trOldHeader');
    newH.style.background = better === 'new' ? 'linear-gradient(135deg,#6366f1,#8b5cf6)' : 'linear-gradient(135deg,#6b7280,#9ca3af)';
    oldH.style.background = better === 'old' ? 'linear-gradient(135deg,#0891b2,#0e7490)'  : 'linear-gradient(135deg,#6b7280,#9ca3af)';
    document.getElementById('trNewBadge').style.display = better === 'new' ? '' : 'none';
    document.getElementById('trOldBadge').style.display = better === 'old' ? '' : 'none';

    const rows = (r, isOld) => {
      const items = [
        ['Gross Income',       this.inr(r.taxable + r.deductions.stdDed + (isOld ? (r.deductions.totalDed - r.deductions.stdDed) : 0))],
        ['Std. Deduction',     '− ' + this.inr(r.deductions.stdDed)],
      ];
      if (isOld) {
        const od = r.deductions;
        if (od.c80c)     items.push(['80C Deductions',   '− ' + this.inr(od.c80c)]);
        if (od.nps)      items.push(['NPS 80CCD(1B)',     '− ' + this.inr(od.nps)]);
        if (od.hra)      items.push(['HRA Exemption',     '− ' + this.inr(od.hra)]);
        if (od.hl)       items.push(['Home Loan Int.',    '− ' + this.inr(od.hl)]);
        if (od.d80d)     items.push(['80D Medical',       '− ' + this.inr(od.d80d)]);
        if (od.d80e)     items.push(['80E Education',     '− ' + this.inr(od.d80e)]);
        if (od.d80tta)   items.push(['80TTA/TTB',         '− ' + this.inr(od.d80tta)]);
        if (od.d80g)     items.push(['80G Donations',     '− ' + this.inr(od.d80g)]);
        if (od.other)    items.push(['Other Deductions',  '− ' + this.inr(od.other)]);
      }
      items.push(['__bold__Taxable Income', this.inr(r.taxable)]);
      items.push(['Income Tax (before rebate)', this.inr(r.taxBeforeRebate)]);
      if (r.rebate > 0) items.push(['Rebate u/s 87A', '− ' + this.inr(r.rebate)]);
      if (r.surcharge > 0) items.push(['Surcharge', this.inr(r.surcharge)]);
      items.push(['H&E Cess @ 4%', this.inr(r.cess)]);
      items.push(['__bold____big__Total Tax Payable', `<strong style="font-size:17px;">${this.inr(r.total)}</strong>`]);
      items.push(['Effective Tax Rate', `<span style="color:var(--accent);font-weight:700;">${this.pct(r.effectiveRate)}</span>`]);
      items.push(['__bold____green__Monthly Take-Home', `<strong style="color:#16a34a;font-size:17px;">${this.inr(r.takeHome / 12)}/mo</strong>`]);

      return items.map(([k, v]) => {
        const bold  = k.includes('__bold__');
        const big   = k.includes('__big__');
        const green = k.includes('__green__');
        const label = k.replace(/__bold__|__big__|__green__/g, '');
        return `<div class="tr-row" style="${bold?'font-weight:700;':''}${big?'':''}">
          <span style="color:var(--text-muted);">${label}</span>
          <span style="text-align:right;">${v}</span>
        </div>`;
      }).join('');
    };

    document.getElementById('trNewBody').innerHTML = rows(nr, false);
    document.getElementById('trOldBody').innerHTML = rows(or, true);
  },

  /* ─── Monthly take-home ─── */
  _renderMonthly(nr, or, income) {
    const nrMo = nr.takeHome / 12;
    const orMo = or.takeHome / 12;
    const diff = nrMo - orMo;
    const maxMo = Math.max(nrMo, orMo);

    document.getElementById('trMonthly').innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px;">
        <div style="text-align:center;padding:16px;background:rgba(99,102,241,.07);border-radius:10px;border:1px solid rgba(99,102,241,.2);">
          <div style="font-size:11px;font-weight:700;color:#6366f1;text-transform:uppercase;margin-bottom:4px;">🆕 New Regime</div>
          <div style="font-size:22px;font-weight:800;color:var(--text-primary);">${this.inr(nrMo)}<span style="font-size:13px;font-weight:400;color:var(--text-muted);">/month</span></div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Annual: ${this.inr(nr.takeHome)}</div>
        </div>
        <div style="text-align:center;padding:16px;background:rgba(8,145,178,.07);border-radius:10px;border:1px solid rgba(8,145,178,.2);">
          <div style="font-size:11px;font-weight:700;color:#0891b2;text-transform:uppercase;margin-bottom:4px;">📋 Old Regime</div>
          <div style="font-size:22px;font-weight:800;color:var(--text-primary);">${this.inr(orMo)}<span style="font-size:13px;font-weight:400;color:var(--text-muted);">/month</span></div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Annual: ${this.inr(or.takeHome)}</div>
        </div>
      </div>
      <div style="padding:10px 14px;border-radius:8px;background:var(--bg-secondary);font-size:13px;text-align:center;color:var(--text-muted);">
        Difference: <strong style="color:${diff>=0?'#6366f1':'#0891b2'}">${diff>=0?'+':''}${this.inr(Math.abs(diff))}/month</strong> in favour of <strong>${diff>=0?'New':'Old'} Regime</strong>
        &nbsp;|&nbsp; Annual: <strong>${this.inr(Math.abs(diff)*12)}</strong>
      </div>
    `;
  },

  /* ─── Slab breakdown ─── */
  _renderSlabs(nr, or) {
    const slabBlock = (result, color, label) => {
      const NEW_ALL = [
        { range:'₹0 – ₹3L',     rate:0  },
        { range:'₹3L – ₹7L',    rate:5  },
        { range:'₹7L – ₹10L',   rate:10 },
        { range:'₹10L – ₹12L',  rate:15 },
        { range:'₹12L – ₹15L',  rate:20 },
        { range:'Above ₹15L',   rate:30 },
      ];
      const OLD_ALL_GEN = [
        { range:'₹0 – ₹2.5L',   rate:0  },
        { range:'₹2.5L – ₹5L',  rate:5  },
        { range:'₹5L – ₹10L',   rate:20 },
        { range:'Above ₹10L',   rate:30 },
      ];
      const slabs = label.includes('New') ? NEW_ALL : OLD_ALL_GEN;
      const active = result.taxable;

      return `<div>
        <div style="font-size:12px;font-weight:700;color:${color};margin-bottom:10px;">${label}</div>
        ${slabs.map(s => {
          const pctW  = s.rate;
          const isActive = active > 0 && (
            (s.rate === 30 && active > (label.includes('New') ? 1500000 : 1000000)) ||
            (s.rate === 20 && active > (label.includes('New') ? 1200000 : 500000) && active <= (label.includes('New') ? 1500000 : 1000000)) ||
            (s.rate === 15 && active > 900000 && active <= 1200000) ||
            (s.rate === 10 && active > 700000 && active <= 900000) ||
            (s.rate === 5  && active > (label.includes('New') ? 300000 : 250000) && active <= (label.includes('New') ? 700000 : 500000))
          );
          const activeStyle = isActive ? `box-shadow:inset 0 0 0 2px ${color};` : '';
          return `<div style="display:flex;align-items:center;gap:8px;margin-bottom:7px;">
            <span style="font-size:11px;min-width:120px;color:var(--text-muted);">${s.range}</span>
            <div style="flex:1;height:18px;background:var(--border);border-radius:4px;overflow:hidden;${activeStyle}">
              <div style="height:100%;width:${pctW}%;background:${color};opacity:${pctW>0?.85:.2};border-radius:4px;min-width:${pctW>0?3:0}px;"></div>
            </div>
            <span style="font-size:12px;font-weight:700;width:30px;text-align:right;color:${pctW>0?color:'var(--text-muted)'};">${pctW}%</span>
          </div>`;
        }).join('')}
      </div>`;
    };
    document.getElementById('trSlabGrid').innerHTML =
      slabBlock(nr, '#6366f1', '🆕 New Regime Slabs (Budget 2024)') +
      slabBlock(or, '#0891b2', '📋 Old Regime Slabs');
  },

  /* ─── Deduction impact table ─── */
  _renderDeductionImpact(ded, or, income) {
    const card = document.getElementById('trDeductCard');
    const body = document.getElementById('trDeductBody');
    const dedMap = [
      { label:'Std. Deduction',  amount:50000 },
      { label:'80C / ELSS / PF', amount:Math.min(ded.c80c, 150000) },
      { label:'NPS 80CCD(1B)',   amount:Math.min(ded.nps, 50000) },
      { label:'HRA Exemption',   amount:ded.hra },
      { label:'Home Loan Int.',  amount:Math.min(ded.homeLoan, 200000) },
      { label:'80D Medical',     amount:ded.d80d },
      { label:'80E Education',   amount:ded.d80e },
      { label:'80TTA/TTB',       amount:Math.min(ded.d80tta, 10000) },
      { label:'80G Donations',   amount:ded.d80g },
      { label:'Other',           amount:ded.other },
    ].filter(d => d.amount > 0);

    if (!dedMap.length) { card.style.display = 'none'; return; }
    card.style.display = '';

    // Marginal rate for old regime (approximate from taxable income)
    const margRate = or.taxable > 1000000 ? 0.30 : or.taxable > 500000 ? 0.20 : or.taxable > 250000 ? 0.05 : 0;

    body.innerHTML = dedMap.map(d => {
      const taxSaved    = Math.round(d.amount * margRate * 1.04); // incl cess
      const effectiveR  = d.amount > 0 ? (taxSaved / d.amount * 100) : 0;
      return `<tr>
        <td>${d.label}</td>
        <td class="text-right">${this.inr(d.amount)}</td>
        <td class="text-right" style="color:#16a34a;font-weight:600;">${this.inr(taxSaved)}</td>
        <td class="text-right" style="color:var(--accent);">${effectiveR.toFixed(1)}%</td>
      </tr>`;
    }).join('') +
    `<tr style="font-weight:700;background:var(--bg-secondary);">
      <td>Total</td>
      <td class="text-right">${this.inr(or.deductions.totalDed)}</td>
      <td class="text-right" style="color:#16a34a;">${this.inr(or.taxBeforeRebate - (or.incomeTax||or.taxBeforeRebate) + or.rebate)}</td>
      <td class="text-right"></td>
    </tr>`;
  },

  /* ─── Breakeven ─── */
  _renderBreakeven(income, nr, age) {
    const wrap = document.getElementById('trBreakeven');
    // Binary search for breakeven deductions (excl. std ded) where old = new tax
    let lo = 0, hi = income, breakeven = null;
    for (let i = 0; i < 50; i++) {
      const mid = (lo + hi) / 2;
      const ded0 = { c80c:0, nps:0, hra:0, homeLoan:0, d80d:0, d80e:0, d80tta:0, d80g:0, other:mid };
      const or0  = this.calcOld(income, ded0, age);
      if (Math.abs(or0.total - nr.total) < 100) { breakeven = mid; break; }
      if (or0.total > nr.total) lo = mid; else hi = mid;
    }
    if (breakeven !== null && breakeven > 0) {
      wrap.style.display = '';
      wrap.innerHTML = `💡 <strong>Breakeven deductions: ~${this.inr(Math.round(breakeven))}</strong> — if your total old-regime deductions (excl. std. deduction) exceed this, Old Regime saves more tax.`;
    } else {
      wrap.style.display = 'none';
    }
  },
};

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('trIncome')?.focus();
});
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
