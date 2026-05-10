<?php
/**
 * WealthDash — t394: Sovereign Gold Bonds (SGB) Page
 * Full UI: Holdings table, live price widget, add/edit modal, interest tracker
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser   = require_auth();
$pageTitle     = 'Sovereign Gold Bonds';
$activePage    = 'sgb';
$activeSection = 'alt_investments';
$pageScript    = 'app.js';

ob_start();
?>
<style>
.sgb-gold-bar { background: linear-gradient(135deg, #d4a017 0%, #f5c842 40%, #d4a017 100%); }
.sgb-chip { display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.sgb-chip.active { background:rgba(34,197,94,.15);color:#16a34a; }
.sgb-chip.matured { background:rgba(148,163,184,.15);color:#64748b; }
.sgb-chip.maturing-soon { background:rgba(245,158,11,.15);color:#d97706; }
.sgb-stat-card { background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:20px;text-align:center; }
.sgb-stat-label { font-size:11px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px; }
.sgb-stat-value { font-size:22px;font-weight:700;color:var(--text-primary); }
.sgb-stat-sub { font-size:12px;color:var(--text-secondary);margin-top:2px; }
.sgb-price-banner { display:flex;align-items:center;gap:16px;background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:16px 20px;margin-bottom:24px; }
.sgb-price-gram { font-size:28px;font-weight:800;color:#d4a017; }
.sgb-table-wrap { overflow-x:auto; }
.sgb-table { width:100%;border-collapse:collapse;font-size:13px; }
.sgb-table th { background:var(--table-header-bg, rgba(0,0,0,.04));padding:10px 14px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-secondary);white-space:nowrap;border-bottom:1px solid var(--border); }
.sgb-table td { padding:12px 14px;border-bottom:1px solid var(--border);vertical-align:middle; }
.sgb-table tr:hover td { background:var(--hover-bg, rgba(0,0,0,.02)); }
.sgb-table .series-name { font-weight:600;color:var(--text-primary);max-width:200px; }
.sgb-table .num { text-align:right; }
.gain-pos { color:#16a34a;font-weight:600; }
.gain-neg { color:#dc2626;font-weight:600; }
.btn-icon { background:none;border:1px solid var(--border);border-radius:6px;padding:5px 8px;cursor:pointer;color:var(--text-secondary);font-size:13px;transition:.15s; }
.btn-icon:hover { background:var(--hover-bg);color:var(--text-primary); }
#sgbModal .modal-dialog { max-width:560px; }
.interest-badge { background:rgba(234,179,8,.15);color:#a16207;border-radius:8px;padding:2px 8px;font-size:11px;font-weight:600; }
</style>

<div class="page-wrapper">
  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;padding:24px 0 20px;border-bottom:1px solid var(--border);margin-bottom:24px;">
    <div>
      <h1 style="margin:0;font-size:24px;font-weight:700;">🏅 Sovereign Gold Bonds</h1>
      <p style="color:var(--text-secondary);margin:4px 0 0;font-size:13px;">RBI-issued SGBs — live gold price + 2.5% annual interest</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
      <button class="btn btn-outline-secondary btn-sm" onclick="sgbRefreshNav()" id="btnRefreshNav">
        <span id="refreshIcon">🔄</span> Refresh NAV
      </button>
      <button class="btn btn-primary btn-sm" onclick="sgbOpenAddModal()">
        ＋ Add SGB
      </button>
    </div>
  </div>

  <!-- Live Gold Price Banner -->
  <div class="sgb-price-banner" id="sgbPriceBanner">
    <div style="font-size:24px;">🥇</div>
    <div>
      <div class="sgb-stat-label">Live Gold Price (24K)</div>
      <div class="sgb-price-gram" id="liveGoldPrice">Loading…</div>
    </div>
    <div style="margin-left:auto;text-align:right;">
      <div class="sgb-stat-label">Source</div>
      <div id="priceSource" style="font-size:12px;color:var(--text-secondary);">—</div>
      <div id="priceTime" style="font-size:11px;color:var(--text-muted,#94a3b8);">—</div>
    </div>
  </div>

  <!-- Summary Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;" id="sgbSummaryCards">
    <div class="sgb-stat-card">
      <div class="sgb-stat-label">Holdings</div>
      <div class="sgb-stat-value" id="sumCount">—</div>
      <div class="sgb-stat-sub">SGB bonds</div>
    </div>
    <div class="sgb-stat-card">
      <div class="sgb-stat-label">Total Units</div>
      <div class="sgb-stat-value" id="sumUnits">—</div>
      <div class="sgb-stat-sub">grams</div>
    </div>
    <div class="sgb-stat-card">
      <div class="sgb-stat-label">Invested</div>
      <div class="sgb-stat-value" id="sumInvested">—</div>
    </div>
    <div class="sgb-stat-card">
      <div class="sgb-stat-label">Current Value</div>
      <div class="sgb-stat-value" id="sumValue">—</div>
    </div>
    <div class="sgb-stat-card">
      <div class="sgb-stat-label">Capital Gain</div>
      <div class="sgb-stat-value" id="sumGain">—</div>
      <div class="sgb-stat-sub">Tax-free on maturity</div>
    </div>
    <div class="sgb-stat-card">
      <div class="sgb-stat-label">Interest Earned</div>
      <div class="sgb-stat-value" id="sumInterest">—</div>
      <div class="sgb-stat-sub">2.5% p.a. (taxable)</div>
    </div>
  </div>

  <!-- Holdings Table -->
  <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:15px;font-weight:600;">Your SGB Holdings</h3>
      <span id="holdingCount" style="font-size:12px;color:var(--text-secondary);"></span>
    </div>
    <div class="sgb-table-wrap">
      <table class="sgb-table" id="sgbTable">
        <thead>
          <tr>
            <th>Series</th>
            <th class="num">Units (g)</th>
            <th class="num">Issue Price</th>
            <th class="num">Invested</th>
            <th class="num">Current NAV</th>
            <th class="num">Current Value</th>
            <th class="num">Gain/Loss</th>
            <th class="num">CAGR</th>
            <th class="num">Interest</th>
            <th>Maturity</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="sgbTbody">
          <tr><td colspan="12" style="text-align:center;padding:40px;color:var(--text-secondary);">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Tax Info Box -->
  <div style="margin-top:20px;background:rgba(234,179,8,.07);border:1px solid rgba(234,179,8,.3);border-radius:10px;padding:16px 20px;">
    <strong style="color:#a16207;">📋 SGB Tax Rules</strong>
    <ul style="margin:8px 0 0;padding-left:20px;font-size:13px;color:var(--text-secondary);">
      <li><strong>Capital Gains on Maturity:</strong> Completely <strong>tax-free</strong> (held 8 years to maturity)</li>
      <li><strong>Early Exit (after 5 years):</strong> Long-term capital gains with indexation benefit</li>
      <li><strong>Interest Income:</strong> 2.5% p.a. — taxable as per your income slab (no TDS)</li>
      <li><strong>Listing:</strong> SGBs are listed on NSE/BSE — can be sold before 5 years (STCG/LTCG applies)</li>
    </ul>
  </div>
</div>

<!-- ══ ADD / EDIT MODAL ══════════════════════════════════════════════════ -->
<div class="modal fade" id="sgbModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sgbModalTitle">Add SGB Holding</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="sgbEditId">

        <!-- Quick-fill from known series -->
        <div class="mb-3">
          <label class="form-label">Quick-fill from RBI Series</label>
          <select class="form-select form-select-sm" id="sgbSeriesSelect" onchange="sgbFillFromSeries(this.value)">
            <option value="">— Select series to auto-fill —</option>
          </select>
        </div>

        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Series Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-sm" id="sgbSeriesName" placeholder="e.g. SGB 2022-23 Series I">
          </div>
          <div class="col-6">
            <label class="form-label">Tranche Code</label>
            <input type="text" class="form-control form-control-sm" id="sgbTrancheCode" placeholder="SGB2022-23S1">
          </div>
          <div class="col-6">
            <label class="form-label">NSE Symbol</label>
            <input type="text" class="form-control form-control-sm" id="sgbNseSymbol" placeholder="e.g. SGBFEB32">
          </div>
          <div class="col-6">
            <label class="form-label">Issue Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control form-control-sm" id="sgbIssueDate">
          </div>
          <div class="col-6">
            <label class="form-label">Maturity Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control form-control-sm" id="sgbMaturityDate">
          </div>
          <div class="col-6">
            <label class="form-label">Units / Grams <span class="text-danger">*</span></label>
            <input type="number" class="form-control form-control-sm" id="sgbUnits" placeholder="e.g. 10" min="1" step="1">
          </div>
          <div class="col-6">
            <label class="form-label">Issue Price (₹/gram) <span class="text-danger">*</span></label>
            <input type="number" class="form-control form-control-sm" id="sgbIssuePrice" placeholder="e.g. 5091" step="0.01">
          </div>
          <div class="col-6">
            <label class="form-label">Coupon Rate (%)</label>
            <input type="number" class="form-control form-control-sm" id="sgbCouponRate" value="2.50" step="0.01">
          </div>
          <div class="col-6">
            <label class="form-label">Interest Payout</label>
            <select class="form-select form-select-sm" id="sgbInterestPayout">
              <option value="semi-annual">Semi-Annual</option>
              <option value="annual">Annual</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea class="form-control form-control-sm" id="sgbNotes" rows="2" placeholder="e.g. via Zerodha, Demat: ..."></textarea>
          </div>
        </div>

        <!-- Estimated investment preview -->
        <div id="sgbPreview" style="margin-top:12px;padding:10px 14px;background:rgba(212,160,23,.1);border-radius:8px;font-size:13px;display:none;">
          Total Invested: <strong id="previewInvested">—</strong> &nbsp;|&nbsp;
          Est. Annual Interest: <strong id="previewInterest">—</strong>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="sgbSave()" id="btnSgbSave">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ INTEREST LOG MODAL ══════════════════════════════════════════════ -->
<div class="modal fade" id="sgbInterestModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Interest Payout Log</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="interestSgbId">
        <div style="margin-bottom:16px;">
          <h6 id="interestSgbName" style="margin:0 0 4px;font-size:14px;font-weight:600;"></h6>
        </div>
        <!-- Add payout form -->
        <div style="background:var(--surface-secondary,rgba(0,0,0,.03));border-radius:8px;padding:12px;margin-bottom:16px;">
          <strong style="font-size:12px;">Log New Payout</strong>
          <div class="row g-2 mt-1">
            <div class="col-5">
              <input type="date" class="form-control form-control-sm" id="interestDate" placeholder="Payout Date">
            </div>
            <div class="col-4">
              <input type="number" class="form-control form-control-sm" id="interestAmount" placeholder="Amount ₹" step="0.01">
            </div>
            <div class="col-3">
              <button class="btn btn-primary btn-sm w-100" onclick="sgbLogInterest()">Add</button>
            </div>
          </div>
        </div>
        <!-- History table -->
        <div id="interestHistoryWrap">
          <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead>
              <tr style="border-bottom:1px solid var(--border);">
                <th style="padding:6px;font-weight:600;color:var(--text-secondary);font-size:11px;">Date</th>
                <th style="padding:6px;text-align:right;font-weight:600;color:var(--text-secondary);font-size:11px;">Amount</th>
                <th style="padding:6px;text-align:right;font-weight:600;color:var(--text-secondary);font-size:11px;">Rate</th>
              </tr>
            </thead>
            <tbody id="interestTbody">
              <tr><td colspan="3" style="text-align:center;padding:20px;color:var(--text-secondary);">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  const API = '<?= APP_URL ?>/api/index.php';
  let sgbData = [];
  let seriesList = [];
  let liveNav = null;

  // ── Init ──────────────────────────────────────────────────────────────
  async function init() {
    await Promise.all([loadSeriesList(), loadLivePrice()]);
    await loadHoldings();
  }

  // ── Live Gold Price ───────────────────────────────────────────────────
  async function loadLivePrice() {
    try {
      const r = await fetch(`${API}?action=sgb_live_price`);
      const d = await r.json();
      if (d.success) {
        liveNav = d.price_24k_gram;
        document.getElementById('liveGoldPrice').textContent = '₹' + fmt(liveNav) + '/g';
        document.getElementById('priceSource').textContent = d.source;
        document.getElementById('priceTime').textContent = d.fetched_at ? new Date(d.fetched_at).toLocaleTimeString('en-IN') : '';
      }
    } catch(e) {
      document.getElementById('liveGoldPrice').textContent = 'Unavailable';
    }
  }

  // ── Series List ───────────────────────────────────────────────────────
  async function loadSeriesList() {
    try {
      const r = await fetch(`${API}?action=sgb_series_list`);
      const d = await r.json();
      if (d.success) {
        seriesList = d.series || [];
        const sel = document.getElementById('sgbSeriesSelect');
        // Group by FY
        const grouped = {};
        seriesList.forEach(s => {
          const fy = s.code.match(/SGB(\d{4}-\d{2,4})/)?.[1] || 'Other';
          if (!grouped[fy]) grouped[fy] = [];
          grouped[fy].push(s);
        });
        Object.keys(grouped).sort().reverse().forEach(fy => {
          const og = document.createElement('optgroup');
          og.label = 'FY ' + fy;
          grouped[fy].forEach(s => {
            const o = document.createElement('option');
            o.value = s.code;
            o.textContent = s.name + ' — ₹' + s.issue_price;
            og.appendChild(o);
          });
          sel.appendChild(og);
        });
      }
    } catch(e) {}
  }

  // ── Load Holdings ─────────────────────────────────────────────────────
  async function loadHoldings() {
    const tbody = document.getElementById('sgbTbody');
    tbody.innerHTML = '<tr><td colspan="12" style="text-align:center;padding:30px;color:var(--text-secondary);">Loading…</td></tr>';
    try {
      const r = await fetch(`${API}?action=sgb_list`);
      const d = await r.json();
      if (!d.success) { tbody.innerHTML = `<tr><td colspan="12" style="text-align:center;padding:30px;color:#dc2626;">${d.message}</td></tr>`; return; }
      sgbData = d.holdings || [];
      renderSummary(d.summary);
      renderTable(sgbData);
    } catch(e) {
      tbody.innerHTML = '<tr><td colspan="12" style="text-align:center;padding:30px;color:#dc2626;">Failed to load. Please try again.</td></tr>';
    }
  }

  function renderSummary(s) {
    document.getElementById('sumCount').textContent = s.count || 0;
    document.getElementById('sumUnits').textContent = fmtD(s.total_units, 2);
    document.getElementById('sumInvested').textContent = '₹' + fmt(s.total_invested);
    document.getElementById('sumValue').textContent = '₹' + fmt(s.total_value);
    const g = s.total_gain;
    const gainEl = document.getElementById('sumGain');
    gainEl.textContent = (g >= 0 ? '+' : '') + '₹' + fmt(Math.abs(g));
    gainEl.style.color = g >= 0 ? '#16a34a' : '#dc2626';
    document.getElementById('sumInterest').textContent = '₹' + fmt(s.total_interest);
  }

  function renderTable(rows) {
    const tbody = document.getElementById('sgbTbody');
    document.getElementById('holdingCount').textContent = rows.length + ' holding' + (rows.length !== 1 ? 's' : '');
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="12" style="text-align:center;padding:40px;color:var(--text-secondary);">
        No SGB holdings yet. <a href="#" onclick="sgbOpenAddModal();return false;">Add your first SGB →</a></td></tr>`;
      return;
    }
    const today = new Date();
    tbody.innerHTML = rows.map(r => {
      const matDate = new Date(r.maturity_date);
      const daysLeft = r.days_to_maturity;
      let statusChip = '';
      if (r.is_matured) {
        statusChip = '<span class="sgb-chip matured">Matured</span>';
      } else if (daysLeft <= 365) {
        statusChip = `<span class="sgb-chip maturing-soon">Matures in ${daysLeft}d</span>`;
      } else {
        statusChip = '<span class="sgb-chip active">Active</span>';
      }

      const gainCls = (r.gain_loss ?? 0) >= 0 ? 'gain-pos' : 'gain-neg';
      const gainSign = (r.gain_loss ?? 0) >= 0 ? '+' : '';

      return `<tr>
        <td>
          <div class="series-name">${esc(r.series_name)}</div>
          ${r.tranche_code ? `<div style="font-size:10px;color:var(--text-secondary);">${esc(r.tranche_code)}</div>` : ''}
          ${r.next_interest_date ? `<div style="font-size:10px;color:#a16207;margin-top:2px;">Next interest: ${fmtDate(r.next_interest_date)}</div>` : ''}
        </td>
        <td class="num">${fmtD(r.units, 2)}</td>
        <td class="num">₹${fmt(r.issue_price)}</td>
        <td class="num">₹${fmt(r.total_invested)}</td>
        <td class="num">${r.current_nav ? '₹' + fmt(r.current_nav) : '—'}</td>
        <td class="num">${r.current_value ? '₹' + fmt(r.current_value) : '—'}</td>
        <td class="num ${gainCls}">${r.gain_loss !== null ? gainSign + '₹' + fmt(Math.abs(r.gain_loss)) : '—'}
          ${r.gain_pct !== null ? `<div style="font-size:10px;">${r.gain_pct >= 0 ? '+' : ''}${r.gain_pct}%</div>` : ''}
        </td>
        <td class="num">${r.cagr_pct !== null ? (r.cagr_pct >= 0 ? '+' : '') + r.cagr_pct + '%' : '—'}</td>
        <td class="num">
          <span class="interest-badge">₹${fmt(r.total_interest_received)}</span>
          <div style="font-size:10px;color:var(--text-secondary);">₹${fmt(r.annual_interest_amount)}/yr</div>
        </td>
        <td style="white-space:nowrap;">
          <div>${fmtDate(r.maturity_date)}</div>
          ${!r.is_matured ? `<div style="font-size:10px;color:var(--text-secondary);">${daysLeft} days</div>` : ''}
        </td>
        <td>${statusChip}</td>
        <td style="white-space:nowrap;">
          <button class="btn-icon" title="Log Interest" onclick="sgbOpenInterestModal(${r.id},'${esc(r.series_name)}')">💰</button>
          <button class="btn-icon" title="Edit" onclick="sgbEdit(${r.id})">✏️</button>
          <button class="btn-icon" title="Delete" onclick="sgbDelete(${r.id},'${esc(r.series_name)}')">🗑️</button>
        </td>
      </tr>`;
    }).join('');
  }

  // ── Refresh NAV ───────────────────────────────────────────────────────
  window.sgbRefreshNav = async function() {
    const btn = document.getElementById('btnRefreshNav');
    const icon = document.getElementById('refreshIcon');
    btn.disabled = true;
    icon.textContent = '⏳';
    try {
      const r = await fetch(`${API}?action=sgb_refresh_nav`);
      const d = await r.json();
      if (d.success) {
        liveNav = d.current_nav;
        document.getElementById('liveGoldPrice').textContent = '₹' + fmt(liveNav) + '/g';
        document.getElementById('priceSource').textContent = d.price_source;
        document.getElementById('priceTime').textContent = new Date(d.fetched_at).toLocaleTimeString('en-IN');
        await loadHoldings();
        icon.textContent = '✅';
        setTimeout(() => { icon.textContent = '🔄'; btn.disabled = false; }, 2000);
      } else {
        alert('Refresh failed: ' + d.message);
        icon.textContent = '🔄'; btn.disabled = false;
      }
    } catch(e) {
      icon.textContent = '🔄'; btn.disabled = false;
    }
  };

  // ── Add Modal ─────────────────────────────────────────────────────────
  window.sgbOpenAddModal = function() {
    document.getElementById('sgbEditId').value = '';
    document.getElementById('sgbModalTitle').textContent = 'Add SGB Holding';
    document.getElementById('sgbSeriesSelect').value = '';
    ['sgbSeriesName','sgbTrancheCode','sgbNseSymbol','sgbNotes'].forEach(id => document.getElementById(id).value = '');
    ['sgbIssueDate','sgbMaturityDate'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('sgbUnits').value = '';
    document.getElementById('sgbIssuePrice').value = '';
    document.getElementById('sgbCouponRate').value = '2.50';
    document.getElementById('sgbInterestPayout').value = 'semi-annual';
    document.getElementById('sgbPreview').style.display = 'none';
    new bootstrap.Modal(document.getElementById('sgbModal')).show();
  };

  window.sgbFillFromSeries = function(code) {
    if (!code) return;
    const s = seriesList.find(x => x.code === code);
    if (!s) return;
    document.getElementById('sgbSeriesName').value   = s.name;
    document.getElementById('sgbTrancheCode').value  = s.code;
    document.getElementById('sgbIssueDate').value    = s.issue_date;
    document.getElementById('sgbMaturityDate').value = s.maturity_date;
    document.getElementById('sgbIssuePrice').value   = s.issue_price;
    updatePreview();
  };

  function updatePreview() {
    const units  = parseFloat(document.getElementById('sgbUnits').value) || 0;
    const price  = parseFloat(document.getElementById('sgbIssuePrice').value) || 0;
    const coupon = parseFloat(document.getElementById('sgbCouponRate').value) || 2.5;
    if (units > 0 && price > 0) {
      const invested = units * price;
      const interest = invested * coupon / 100;
      document.getElementById('previewInvested').textContent = '₹' + fmt(invested);
      document.getElementById('previewInterest').textContent = '₹' + fmt(interest) + '/year';
      document.getElementById('sgbPreview').style.display = '';
    } else {
      document.getElementById('sgbPreview').style.display = 'none';
    }
  }

  // Listen for changes
  ['sgbUnits','sgbIssuePrice','sgbCouponRate'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', updatePreview);
  });

  // ── Edit ──────────────────────────────────────────────────────────────
  window.sgbEdit = function(id) {
    const r = sgbData.find(x => x.id === id);
    if (!r) return;
    document.getElementById('sgbEditId').value     = id;
    document.getElementById('sgbModalTitle').textContent = 'Edit SGB Holding';
    document.getElementById('sgbSeriesName').value  = r.series_name;
    document.getElementById('sgbTrancheCode').value = r.tranche_code || '';
    document.getElementById('sgbNseSymbol').value   = r.nse_symbol || '';
    document.getElementById('sgbIssueDate').value   = r.issue_date;
    document.getElementById('sgbMaturityDate').value= r.maturity_date;
    document.getElementById('sgbUnits').value       = r.units;
    document.getElementById('sgbIssuePrice').value  = r.issue_price;
    document.getElementById('sgbCouponRate').value  = r.coupon_rate;
    document.getElementById('sgbInterestPayout').value = r.interest_payout;
    document.getElementById('sgbNotes').value       = r.notes || '';
    updatePreview();
    new bootstrap.Modal(document.getElementById('sgbModal')).show();
  };

  // ── Save ──────────────────────────────────────────────────────────────
  window.sgbSave = async function() {
    const id = document.getElementById('sgbEditId').value;
    const body = new URLSearchParams({
      action:           id ? 'sgb_update' : 'sgb_add',
      series_name:      document.getElementById('sgbSeriesName').value.trim(),
      tranche_code:     document.getElementById('sgbTrancheCode').value.trim(),
      nse_symbol:       document.getElementById('sgbNseSymbol').value.trim(),
      issue_date:       document.getElementById('sgbIssueDate').value,
      maturity_date:    document.getElementById('sgbMaturityDate').value,
      units:            document.getElementById('sgbUnits').value,
      issue_price:      document.getElementById('sgbIssuePrice').value,
      coupon_rate:      document.getElementById('sgbCouponRate').value,
      interest_payout:  document.getElementById('sgbInterestPayout').value,
      notes:            document.getElementById('sgbNotes').value.trim(),
    });
    if (id) body.set('id', id);

    const btn = document.getElementById('btnSgbSave');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      const r = await fetch(API, { method:'POST', body });
      const d = await r.json();
      if (d.success) {
        bootstrap.Modal.getInstance(document.getElementById('sgbModal'))?.hide();
        loadHoldings();
      } else {
        alert('Error: ' + d.message);
      }
    } finally {
      btn.disabled = false; btn.textContent = 'Save';
    }
  };

  // ── Delete ────────────────────────────────────────────────────────────
  window.sgbDelete = async function(id, name) {
    if (!confirm(`Remove "${name}"?`)) return;
    const r = await fetch(API, { method:'POST', body: new URLSearchParams({ action:'sgb_delete', id }) });
    const d = await r.json();
    if (d.success) loadHoldings();
    else alert('Error: ' + d.message);
  };

  // ── Interest Modal ────────────────────────────────────────────────────
  window.sgbOpenInterestModal = async function(sgbId, name) {
    document.getElementById('interestSgbId').value = sgbId;
    document.getElementById('interestSgbName').textContent = name;
    document.getElementById('interestDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('interestAmount').value = '';
    new bootstrap.Modal(document.getElementById('sgbInterestModal')).show();
    await loadInterestHistory(sgbId);
  };

  async function loadInterestHistory(sgbId) {
    const tbody = document.getElementById('interestTbody');
    tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:16px;color:var(--text-secondary);">Loading…</td></tr>';
    try {
      const r = await fetch(`${API}?action=sgb_interest_list&sgb_id=${sgbId}`);
      const d = await r.json();
      if (d.success && d.payouts.length) {
        tbody.innerHTML = d.payouts.map(p => `
          <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:8px 6px;">${fmtDate(p.payout_date)}</td>
            <td style="padding:8px 6px;text-align:right;font-weight:600;color:#16a34a;">₹${fmt(p.amount)}</td>
            <td style="padding:8px 6px;text-align:right;color:var(--text-secondary);">${p.rate_pct}%</td>
          </tr>`).join('');
      } else {
        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:16px;color:var(--text-secondary);">No payouts logged yet.</td></tr>';
      }
    } catch(e) {
      tbody.innerHTML = '<tr><td colspan="3" style="padding:16px;">Error loading history.</td></tr>';
    }
  }

  window.sgbLogInterest = async function() {
    const sgbId  = document.getElementById('interestSgbId').value;
    const date   = document.getElementById('interestDate').value;
    const amount = document.getElementById('interestAmount').value;
    if (!date || !amount || parseFloat(amount) <= 0) { alert('Enter date and amount.'); return; }
    const body = new URLSearchParams({ action:'sgb_interest_add', sgb_id:sgbId, payout_date:date, amount });
    const r = await fetch(API, { method:'POST', body });
    const d = await r.json();
    if (d.success) {
      document.getElementById('interestAmount').value = '';
      await loadInterestHistory(sgbId);
      loadHoldings();
    } else { alert('Error: ' + d.message); }
  };

  // ── Utils ─────────────────────────────────────────────────────────────
  function fmt(n) {
    n = parseFloat(n) || 0;
    if (n >= 1e7) return (n/1e7).toFixed(2) + 'Cr';
    if (n >= 1e5) return (n/1e5).toFixed(2) + 'L';
    return n.toLocaleString('en-IN', {maximumFractionDigits:0});
  }
  function fmtD(n, d=2) { return parseFloat(n).toFixed(d); }
  function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s);
    return d.toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'});
  }
  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  init();
})();
</script>
<?php
$content = ob_get_clean();
require APP_ROOT . '/templates/layout.php';
