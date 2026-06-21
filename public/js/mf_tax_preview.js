/**
 * WealthDash — Capital Gains Tax Preview (tv09)
 * File: public/js/mf_tax_preview.js
 *
 * Live LTCG/STCG calculation on Holdings page.
 * "Agar aaj sab redeem karo to ₹X tax lagega"
 *
 * Integration:
 *   1. Include this file in mf_holdings.php
 *   2. Call MF_TAX_PREVIEW.init() after holdings table renders
 *   3. Inject tax preview toggle button via injectTaxPreviewToggle()
 *
 * Tax Rules (India FY 2024-25):
 *   Equity MF LTCG (>12m): 10% above ₹1,25,000
 *   Equity MF STCG (≤12m): 20% flat
 *   Debt MF (post Apr 2023): slab rate
 *   Hybrid/Index/ELSS: treated as equity if equity > 65%
 */

const MF_TAX_PREVIEW = (() => {

  // ── State ──────────────────────────────────────────
  let _active     = false;        // toggle state
  let _holdings   = [];           // injected from MF.holdings
  let _ltcgExempt = 125000;       // Section 112A limit
  let _computed   = null;         // cached computation

  // ── Constants ──────────────────────────────────────
  const EQUITY_CATS     = ['Equity', 'ELSS', 'Index', 'Hybrid Equity', 'Large Cap', 'Mid Cap',
                           'Small Cap', 'Multi Cap', 'Flexi Cap', 'Thematic'];
  const LTCG_MONTHS     = 12;     // equity LTCG threshold
  const LTCG_RATE       = 0.10;
  const STCG_RATE       = 0.20;

  // ── Helpers ────────────────────────────────────────
  function _isEquity(fund) {
    return EQUITY_CATS.some(c => (fund.category || '').toLowerCase().includes(c.toLowerCase()));
  }

  function _monthsDiff(dateStr) {
    const then = new Date(dateStr);
    const now  = new Date();
    return (now.getFullYear() - then.getFullYear()) * 12 +
           (now.getMonth()   - then.getMonth());
  }

  function _fmtInr(v) {
    const abs = Math.abs(v);
    let s;
    if (abs >= 1e7) s = '₹' + (abs/1e7).toFixed(2) + 'Cr';
    else if (abs >= 1e5) s = '₹' + (abs/1e5).toFixed(2) + 'L';
    else s = '₹' + abs.toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0});
    return (v < 0 ? '-' : '') + s;
  }

  function _fmtInrFull(v) {
    return '₹' + Math.abs(v).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
  }

  // ── Core Tax Computation ───────────────────────────
  function compute(holdings) {
    if (!holdings || !holdings.length) return null;

    let totalLTCG    = 0, totalSTCG = 0, totalDebt = 0;
    let ltcgFunds = [], stcgFunds = [], debtFunds = [];

    holdings.forEach(h => {
      const gain      = (h.current_value || 0) - (h.invested || 0);
      if (gain <= 0) return; // losses — separate handling

      const equity    = _isEquity(h);
      const months    = _monthsDiff(h.first_purchase_date || h.purchase_date || '2020-01-01');
      const isPostApr23 = new Date(h.first_purchase_date || h.purchase_date) >= new Date('2023-04-01');

      let type = 'DEBT';
      let rate = null;

      if (equity) {
        if (months >= LTCG_MONTHS) { type = 'LTCG'; totalLTCG += gain; }
        else                        { type = 'STCG'; totalSTCG += gain; }
      } else {
        type = isPostApr23 ? 'DEBT_SLAB' : (months >= 36 ? 'DEBT_LTCG' : 'DEBT_STCG');
        totalDebt += gain;
      }

      const entry = {
        fund_name    : h.fund_name || h.name,
        category     : h.category,
        invested     : h.invested,
        current_value: h.current_value,
        gain,
        months,
        type,
      };
      if      (type === 'LTCG')  ltcgFunds.push(entry);
      else if (type === 'STCG')  stcgFunds.push(entry);
      else                       debtFunds.push(entry);
    });

    // LTCG exemption (₹1,25,000 per FY — already-booked gains can further reduce headroom)
    // We compute "worst case" here (no prior bookings this FY assumed)
    const ltcgExemptUsed   = Math.min(Math.max(totalLTCG, 0), _ltcgExempt);
    const ltcgTaxableAmt   = Math.max(totalLTCG - ltcgExemptUsed, 0);
    const ltcgTaxEstimate  = ltcgTaxableAmt * LTCG_RATE;
    const stcgTaxEstimate  = Math.max(totalSTCG, 0) * STCG_RATE;
    const totalTaxEstimate = ltcgTaxEstimate + stcgTaxEstimate;

    return {
      totalLTCG, totalSTCG, totalDebt,
      ltcgExemptUsed, ltcgTaxableAmt,
      ltcgTaxEstimate, stcgTaxEstimate, totalTaxEstimate,
      ltcgFunds, stcgFunds, debtFunds
    };
  }

  // ── Inject Toggle Button ───────────────────────────
  function injectTaxPreviewToggle() {
    if (document.getElementById('taxPreviewToggle')) return;

    const wrap = document.querySelector('.mf-holdings-actions') ||
                 document.querySelector('.holdings-action-bar') ||
                 document.querySelector('.mf-top-actions');
    if (!wrap) {
      // Fallback: inject after holdings stat strip
      const statStrip = document.querySelector('.holdings-stat-strip, .mf-stat-strip, #holdingsStats');
      if (!statStrip) return;
      const div = document.createElement('div');
      div.style.cssText = 'margin:8px 0;';
      div.innerHTML = _toggleBtnHTML();
      statStrip.parentNode.insertBefore(div, statStrip.nextSibling);
      return;
    }

    const btn = document.createElement('button');
    btn.id = 'taxPreviewToggle';
    btn.className = 'wd-btn wd-btn-outline tax-toggle-btn';
    btn.innerHTML = '🧮 Tax Preview';
    btn.title = 'Agar aaj sab redeem karo to kitna tax lagega';
    btn.onclick = toggle;
    btn.style.cssText = 'padding:5px 12px;border-radius:7px;border:1.5px solid var(--border);' +
      'font-size:11px;font-weight:700;cursor:pointer;background:var(--bg);color:var(--muted);' +
      'font-family:inherit;transition:all .15s;';
    wrap.appendChild(btn);
  }

  // ── Summary Card HTML ──────────────────────────────
  function _summaryCardHTML(c) {
    const headroom = _ltcgExempt - c.ltcgExemptUsed;

    return `
<div id="taxPreviewCard" style="
  background:var(--surface);border:1.5px solid var(--accent-border);border-radius:14px;
  box-shadow:0 4px 16px rgba(79,70,229,.10);padding:16px 18px;margin:12px 0;
  animation:tp-slide-in .25s ease;
">
  <style>
    @keyframes tp-slide-in { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
    .tp-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px}
    .tp-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:10px 12px;text-align:center}
    .tp-num{font-size:17px;font-weight:800;line-height:1.1;letter-spacing:-.4px}
    .tp-lbl{font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-top:3px}
    .tp-sub{font-size:9px;color:var(--muted2);margin-top:1px}
    .tp-breakdown{border-top:1px solid var(--border);padding-top:12px}
    .tp-row{display:flex;justify-content:space-between;padding:5px 0;font-size:11px;border-bottom:1px dashed var(--border)}
    .tp-row:last-child{border-bottom:none;font-weight:800;font-size:12px;padding-top:8px}
    .tp-exempt-row{background:var(--green-bg);border-radius:6px;padding:3px 8px;margin:3px 0;font-size:11px;display:flex;justify-content:space-between}
    .tp-fund-list{max-height:180px;overflow-y:auto;margin-top:8px;font-size:11px}
    .tp-fund-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid var(--border)}
    .tp-fund-row:last-child{border-bottom:none}
    .tp-fund-name{color:var(--text);font-weight:600;flex:1;margin-right:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .tp-fund-type{font-size:9px;padding:1px 5px;border-radius:4px;font-weight:700;flex-shrink:0;margin-right:6px}
    .tp-type-lt{background:var(--green-bg);color:var(--green)}
    .tp-type-st{background:var(--red-bg);color:var(--red)}
    .tp-type-dbt{background:var(--yellow-bg);color:var(--yellow)}
    .tp-gain{font-weight:700;flex-shrink:0;font-size:11px}
    .tp-headroom-bar{height:5px;border-radius:99px;background:var(--border);overflow:hidden;margin-top:6px}
    .tp-headroom-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#0d9f57,#10b981);transition:width .6s}
    .tp-note{font-size:10px;color:var(--muted);line-height:1.5;margin-top:10px;padding:8px 10px;background:var(--bg);border-radius:7px;border:1px solid var(--border)}
    @media(max-width:600px){.tp-grid{grid-template-columns:repeat(2,1fr)}}
  </style>

  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
    <div>
      <div style="font-size:14px;font-weight:800;display:flex;align-items:center;gap:6px">
        🧮 Capital Gains Tax Preview
        <span style="font-size:9px;padding:2px 7px;border-radius:99px;background:var(--accent-bg);color:var(--accent);border:1px solid var(--accent-border);font-weight:700">LIVE</span>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px">Agar aaj sab holdings redeem karo — estimated tax liability (FY 2024-25)</div>
    </div>
    <button onclick="MF_TAX_PREVIEW.toggle()" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);cursor:pointer;color:var(--muted);font-size:11px;font-weight:700;font-family:inherit">✕ Close</button>
  </div>

  <!-- 4-card summary -->
  <div class="tp-grid">
    <div class="tp-card">
      <div class="tp-num" style="color:var(--green)">${_fmtInr(c.totalLTCG)}</div>
      <div class="tp-lbl">LTCG Gains</div>
      <div class="tp-sub">Held >12 months</div>
    </div>
    <div class="tp-card">
      <div class="tp-num" style="color:var(--red)">${_fmtInr(c.totalSTCG)}</div>
      <div class="tp-lbl">STCG Gains</div>
      <div class="tp-sub">Held ≤12 months</div>
    </div>
    <div class="tp-card">
      <div class="tp-num" style="color:var(--yellow)">${_fmtInr(c.totalDebt)}</div>
      <div class="tp-lbl">Debt Gains</div>
      <div class="tp-sub">Slab rate</div>
    </div>
    <div class="tp-card" style="border-color:var(--red-border);background:var(--red-bg)">
      <div class="tp-num" style="color:var(--red)">${_fmtInr(c.totalTaxEstimate)}</div>
      <div class="tp-lbl">Est. Tax Due</div>
      <div class="tp-sub">LTCG + STCG</div>
    </div>
  </div>

  <!-- Breakdown calculation -->
  <div class="tp-breakdown">
    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Tax Calculation Breakdown</div>

    <div class="tp-row"><span style="color:var(--muted)">Total LTCG (Equity, >12m)</span><span style="font-weight:700">${_fmtInrFull(c.totalLTCG)}</span></div>
    <div class="tp-exempt-row">
      <span style="color:var(--green);font-weight:600">(-) Section 112A Exemption</span>
      <span style="color:var(--green);font-weight:700">${_fmtInrFull(c.ltcgExemptUsed)}</span>
    </div>
    <div class="tp-row"><span style="color:var(--muted)">Taxable LTCG</span><span style="font-weight:700">${_fmtInrFull(c.ltcgTaxableAmt)}</span></div>
    <div class="tp-row"><span style="color:var(--muted)">LTCG Tax @ 10%</span><span style="color:var(--red);font-weight:700">${_fmtInrFull(c.ltcgTaxEstimate)}</span></div>
    <div class="tp-row"><span style="color:var(--muted)">Total STCG (Equity, ≤12m)</span><span style="font-weight:700">${_fmtInrFull(c.totalSTCG)}</span></div>
    <div class="tp-row"><span style="color:var(--muted)">STCG Tax @ 20%</span><span style="color:var(--red);font-weight:700">${_fmtInrFull(c.stcgTaxEstimate)}</span></div>
    <div class="tp-row"><span>Total Estimated Capital Gains Tax</span><span style="color:var(--red)">${_fmtInrFull(c.totalTaxEstimate)}</span></div>
  </div>

  <!-- LTCG Headroom bar -->
  ${c.totalLTCG > 0 ? `
  <div style="margin-top:12px;padding:10px 12px;background:var(--green-bg);border-radius:8px;border:1px solid var(--green-border)">
    <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:700;margin-bottom:4px">
      <span style="color:var(--green)">📊 LTCG Exemption Headroom (₹1,25,000/FY)</span>
      <span style="color:var(--green)">${_fmtInr(Math.max(headroom,0))} remaining</span>
    </div>
    <div class="tp-headroom-bar">
      <div class="tp-headroom-fill" style="width:${Math.min((c.ltcgExemptUsed/_ltcgExempt)*100,100)}%"></div>
    </div>
    <div style="font-size:10px;color:var(--muted);margin-top:4px">
      ${headroom > 0
        ? `You can book ₹${_fmtInr(headroom)} more LTCG this FY tax-free (partial redemption strategy).`
        : `₹1,25,000 exemption fully used. All remaining LTCG taxable at 10%.`}
    </div>
  </div>` : ''}

  <!-- Fund-level breakdown -->
  ${(c.ltcgFunds.length || c.stcgFunds.length) ? `
  <div style="margin-top:12px">
    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Fund-wise Breakdown</div>
    <div class="tp-fund-list">
      ${[...c.ltcgFunds.map(f=>({...f, cls:'tp-type-lt', label:'LTCG'})),
          ...c.stcgFunds.map(f=>({...f, cls:'tp-type-st', label:'STCG'})),
          ...c.debtFunds.map(f=>({...f, cls:'tp-type-dbt', label:'SLAB'}))]
        .sort((a,b) => b.gain - a.gain)
        .map(f => `
        <div class="tp-fund-row">
          <span class="tp-fund-name" title="${f.fund_name}">${f.fund_name}</span>
          <span class="tp-fund-type ${f.cls}">${f.label}</span>
          <span class="tp-gain" style="color:${f.gain>=0?'var(--green)':'var(--red)'}">
            ${_fmtInr(f.gain)}
          </span>
        </div>`).join('')}
    </div>
  </div>` : ''}

  <!-- Disclaimer note -->
  <div class="tp-note">
    ⚠️ <strong>Note:</strong> This is an estimate assuming <em>complete</em> redemption today.
    Actual tax depends on actual redemption date, FY, prior bookings, indexation (debt pre-Apr'23), and
    grandfathering (equity purchased before Feb 1 2018). Debt MF gains are taxed at your income tax slab rate — add to your income separately.
    Consult a CA before filing ITR.
    <br><br>
    💡 <strong>Smart redemption tip:</strong> Consider booking LTCG up to ₹1,25,000 each FY tax-free.
    Sell and repurchase (wait 1 day) to step up cost basis.
  </div>
</div>`;
  }

  // ── Inject per-row tax badge in holdings table ─────
  function _injectRowBadges(holdings) {
    holdings.forEach(h => {
      const row = document.querySelector(`[data-fund-id="${h.fund_id}"], [data-id="${h.id}"]`);
      if (!row) return;

      // Remove old badge
      const old = row.querySelector('.tp-row-badge');
      if (old) old.remove();

      const gain   = (h.current_value || 0) - (h.invested || 0);
      const equity = _isEquity(h);
      const months = _monthsDiff(h.first_purchase_date || h.purchase_date || '2020-01-01');

      let label, color, bg, tax = 0;

      if (!equity) {
        label = 'Slab';
        color = 'var(--yellow)'; bg = 'var(--yellow-bg)';
      } else if (months >= LTCG_MONTHS) {
        const taxable = Math.max(gain - _ltcgExempt, 0);
        tax   = taxable * LTCG_RATE;
        label = gain > 0 ? `LTCG ~${_fmtInr(tax)}` : 'LTCG ₹0 tax';
        color = 'var(--green)'; bg = 'var(--green-bg)';
      } else {
        tax   = Math.max(gain, 0) * STCG_RATE;
        label = gain > 0 ? `STCG ~${_fmtInr(tax)}` : 'STCG ₹0 tax';
        color = 'var(--red)'; bg = 'var(--red-bg)';
      }

      const badge = document.createElement('span');
      badge.className = 'tp-row-badge';
      badge.style.cssText = `
        display:inline-block;font-size:9px;padding:2px 6px;border-radius:4px;
        background:${bg};color:${color};font-weight:700;margin-left:6px;
        border:1px solid ${color};cursor:help;
      `;
      badge.textContent = '🧮 ' + label;
      badge.title = `Estimated tax if redeemed today\nGain: ${_fmtInr(gain)}\nHeld: ${months} months`;

      // Try to append to the gain cell or fund name cell
      const gainCell = row.querySelector('.gain-cell, .td-gain, [data-col="gain"]') ||
                       row.querySelector('td:nth-child(6), td:nth-child(5)');
      if (gainCell) gainCell.appendChild(badge);
    });
  }

  // ── Toggle ─────────────────────────────────────────
  function toggle() {
    _active = !_active;
    const btn = document.getElementById('taxPreviewToggle');

    if (_active) {
      // Compute from MF.holdings or passed holdings
      const holdings = _holdings.length ? _holdings :
        (window.MF && MF.holdings ? MF.holdings : []);

      _computed = compute(holdings);
      if (!_computed) {
        alert('Holdings data not available. Please refresh the page.');
        _active = false;
        return;
      }

      // Render summary card
      const existing = document.getElementById('taxPreviewCard');
      if (existing) existing.remove();

      const anchor = document.querySelector('#mfAnalyticsSection, #holdingsTableWrap, .holdings-table-wrap, .mf-holdings-main') ||
                     document.querySelector('.mf-holdings-content') ||
                     document.body;

      const div = document.createElement('div');
      div.innerHTML = _summaryCardHTML(_computed);
      anchor.insertBefore(div.firstElementChild, anchor.firstChild);

      // Inject per-row badges
      _injectRowBadges(holdings);

      if (btn) {
        btn.textContent = '✕ Hide Tax Preview';
        btn.style.background = 'var(--red-bg)';
        btn.style.color = 'var(--red)';
        btn.style.borderColor = 'var(--red-border)';
      }
    } else {
      // Remove card and row badges
      const card = document.getElementById('taxPreviewCard');
      if (card) card.remove();
      document.querySelectorAll('.tp-row-badge').forEach(b => b.remove());

      if (btn) {
        btn.textContent = '🧮 Tax Preview';
        btn.style.background = 'var(--bg)';
        btn.style.color = 'var(--muted)';
        btn.style.borderColor = 'var(--border)';
      }
    }
  }

  // ── Public API ─────────────────────────────────────
  function init(holdings) {
    if (holdings) _holdings = holdings;
    injectTaxPreviewToggle();
  }

  function setHoldings(holdings) {
    _holdings = holdings;
    if (_active) {
      // Recompute and re-render
      _active = false;
      toggle();
    }
  }

  function setAlreadyBookedLTCG(amount) {
    // If user has already booked some LTCG this FY, adjust exemption headroom
    _ltcgExempt = Math.max(125000 - amount, 0);
  }

  return { init, toggle, setHoldings, setAlreadyBookedLTCG, compute };

})();

// ──────────────────────────────────────────────
// AUTO-INIT: Hook into MF module after render
// ──────────────────────────────────────────────
(function() {
  // Wait for MF.holdings to be available
  const _origRender = window.MF && MF.renderHoldings;
  if (_origRender) {
    const _patched = MF.renderHoldings;
    MF.renderHoldings = function() {
      _patched.apply(this, arguments);
      // After render, init tax preview with current holdings
      setTimeout(() => {
        if (MF.holdings && MF.holdings.length) {
          MF_TAX_PREVIEW.setHoldings(MF.holdings);
        }
        MF_TAX_PREVIEW.injectTaxPreviewToggle && MF_TAX_PREVIEW.init();
      }, 100);
    };
  } else {
    // DOM ready fallback
    document.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => {
        const holdings = window.MF && MF.holdings ? MF.holdings : [];
        MF_TAX_PREVIEW.init(holdings);
      }, 500);
    });
  }
})();
