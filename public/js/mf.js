/**
 * WealthDash — Mutual Funds JS (mf.js)
 * Handles: Holdings table, Transaction CRUD, Fund search autocomplete, CSV Import
 */

/* ═══════════════════════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════════════════════ */
const MF = {
  view: 'holdings',           // 'holdings' | 'folio'
  data: [],                   // raw data from API
  filtered: [],               // after filter/search
  sortCol: 'total_invested',
  sortDir: 'desc',
  portfolioFilter: '',
  categoryFilter: '',
  gainTypeFilter: '',
  search: '',
  fundSearchTimer: null,
  selectedFundId: null,
  selectedFundNav: null,
  page: 1,
  perPage: 10,
  totalTxns: 0,
  txnFilters: {},
  txnSortCol: 'txn_date',   // t31: transaction table sort
  txnSortDir: 'desc',       // t31: asc | desc
  holdingsPage: 1,
  holdingsPerPage: 10,
  oneDayData: {},        // fund_id => {day_change_amt, day_change_pct}
};
window.MF = MF; // expose globally so app.js toggleNumFormat can access

/* ═══════════════════════════════════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  const isHoldingsPage  = !!document.getElementById('holdingsBody');
  const isTxnPage       = !!document.getElementById('txnBody');

  if (isHoldingsPage) initHoldingsPage();
  if (isTxnPage)      initTxnPage();
});

/* ═══════════════════════════════════════════════════════════════════════════
   HOLDINGS PAGE
═══════════════════════════════════════════════════════════════════════════ */
function initHoldingsPage() {
  loadHoldings();

  // Filters
  ['filterCategory','filterGainType'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', applyHoldingsFilter);
  });
  document.getElementById('searchFund')?.addEventListener('input', e => {
    MF.search = e.target.value.toLowerCase(); applyHoldingsFilter();
  });

  // ── Sticky header scroll shadow ─────────────────────────────────────────
  // Adds .is-scrolled to table-wrapper once user scrolls past the sticky header
  // so the box-shadow separator appears exactly when needed.
  const _tableWrapper = document.querySelector('.table-wrapper');
  const _topbarH = parseInt(getComputedStyle(document.documentElement)
                              .getPropertyValue('--topbar-height')) || 60;
  if (_tableWrapper) {
    window.addEventListener('scroll', () => {
      const wrapTop = _tableWrapper.getBoundingClientRect().top;
      _tableWrapper.classList.toggle('is-scrolled', wrapTop < _topbarH);
    }, { passive: true });
  }

  // ── Sort menu — close on OUTSIDE POINTERDOWN (not click) ────────────────
  // Using pointerdown instead of click prevents the race where a slow drag
  // from inside the menu ends outside and triggers a false close on 'click'.
  document.addEventListener('pointerdown', (e) => {
    if (!e.target.closest('#btnSortMenu') && !e.target.closest('#sortMenuDropdown')) {
      document.getElementById('sortMenuDropdown').style.display = 'none';
    }
  });
  // Scroll anywhere → close immediately
  // capture:true fires before scroll moves content; passive:true keeps it smooth
  window.addEventListener('scroll', () => {
    document.getElementById('sortMenuDropdown').style.display = 'none';
  }, { capture: true, passive: true });
  // Resize → reposition if still open (debounced — don't thrash on every pixel)
  let _sortMenuResizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(_sortMenuResizeTimer);
    _sortMenuResizeTimer = setTimeout(_positionSortMenu, 80);
  });

  // Add Transaction
  document.getElementById('btnAddTransaction')?.addEventListener('click', openAddTxnModal);
  document.getElementById('btnCloseTxnModal')?.addEventListener('click', closeAddTxnModal);
  document.getElementById('btnCancelTxn')?.addEventListener('click', closeAddTxnModal);
  document.getElementById('btnSaveTxn')?.addEventListener('click', saveTransaction);

  // Import CSV
  document.getElementById('btnImportCsv')?.addEventListener('click', () => showModal('modalImportCsv'));

  // Download Excel
  document.getElementById('btnDownloadExcel')?.addEventListener('click', downloadHoldingsExcel);

  // Custom file input display + t90 preview
  document.getElementById('importFile')?.addEventListener('change', function() {
    onImportFileChange(this);
  });
  document.getElementById('btnCloseImportModal')?.addEventListener('click', () => hideModal('modalImportCsv'));
  document.getElementById('btnCancelImport')?.addEventListener('click',  () => hideModal('modalImportCsv'));
  document.getElementById('btnStartImport')?.addEventListener('click', startCsvImport);

  // Fund search autocomplete
  document.getElementById('txnFundSearch')?.addEventListener('input', onFundSearchInput);
  document.getElementById('txnFundSearch')?.addEventListener('blur', () => {
    setTimeout(() => { const d = document.getElementById('fundSearchDropdown'); if(d) d.style.display='none'; }, 200);
  });

  // Live value preview
  ['txnUnits','txnNav','txnStampDuty'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', updateValuePreview);
  });

  // Available units — show when SELL/SWITCH_OUT selected
  document.getElementById('txnType')?.addEventListener('change', updateAvailableUnits);
  document.getElementById('txnDate')?.addEventListener('change', updateAvailableUnits);

  // Close modal on overlay click
  document.getElementById('modalAddTxn')?.addEventListener('click', e => {
    if (e.target === document.getElementById('modalAddTxn')) closeAddTxnModal();
  });
  document.getElementById('modalImportCsv')?.addEventListener('click', e => {
    if (e.target === document.getElementById('modalImportCsv')) hideModal('modalImportCsv');
  });
}

async function loadHoldings() {
  const body = document.getElementById('holdingsBody');
  body.innerHTML = `<tr><td colspan="11" class="text-center" style="padding:40px;"><div class="spinner"></div></td></tr>`;

  const params = new URLSearchParams({ view: MF.view, portfolio_id: window.WD?.selectedPortfolio || 0 });

  try {
    const res  = await API.get(`/api/mutual_funds/mf_list.php?${params}`);
    MF.data = res.data || [];
    MF.filtered = [...MF.data];

    // t182: load fund notes silently
    loadFundNotes();

    // Update summary from API if available
    if (res.summary) updateSummaryCards(res.summary);

    applyHoldingsFilter();
    load1DayChange(); // fetch 1D NAV change after holdings render

    // t71 + t73: Render analytics after data is loaded
    renderMfAnalytics();
  } catch (err) {
    body.innerHTML = `<tr><td colspan="11" class="text-center text-danger" style="padding:32px;">${err.message}</td></tr>`;
  }
}

// t481: Refresh NAV for a single fund (stale NAV badge click)
async function refreshSingleNav(fundId) {
  const toast = (msg, color='#1e293b') => {
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:24px;right:24px;background:${color};color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2);transition:opacity .4s;`;
    t.textContent = msg; document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3000);
  };
  toast('🔄 NAV refresh ho raha hai…', '#3b82f6');
  try {
    const base = window.WD?.appUrl || window.APP_URL || '';
    const res  = await fetch(`${base}/api/nav/update_amfi.php?fund_id=${fundId}`, {
      method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const d = await res.json();
    if (d.success || d.updated > 0) {
      toast('✅ NAV updated! Reloading…', '#16a34a');
      setTimeout(() => loadHoldings(), 1000);
    } else {
      toast('⚠ NAV source se data nahi mila', '#d97706');
    }
  } catch (e) {
    toast('❌ Refresh failed: ' + e.message, '#dc2626');
  }
}

function applyHoldingsFilter() {
  MF.categoryFilter  = document.getElementById('filterCategory')?.value || '';
  MF.gainTypeFilter  = document.getElementById('filterGainType')?.value || '';
  MF.search = document.getElementById('searchFund')?.value?.toLowerCase() || '';

  MF.holdingsPage = 1; // reset to first page on filter change

  MF.filtered = MF.data.filter(h => {
    if (MF.categoryFilter && h.category !== MF.categoryFilter) return false;
    if (MF.gainTypeFilter && h.gain_type !== MF.gainTypeFilter) return false;
    if (MF.search && !h.scheme_name.toLowerCase().includes(MF.search) &&
        !h.fund_house?.toLowerCase().includes(MF.search)) return false;
    return true;
  });

  renderHoldings();
}

// ── 1D Change: inline cell renderer ─────────────────────────────────────────
// Reads MF.oneDayData at render-time so sort/filter/page never loses 1D data.
function render1DCell(fundId) {
  const d = MF.oneDayData[fundId];
  if (!d || (d.day_change_amt === null && d.day_change_pct === null)) {
    // Data not yet loaded (⏳) or genuinely unavailable (—)
    const notLoaded = Object.keys(MF.oneDayData).length === 0;
    return notLoaded
      ? '<span style="color:var(--text-muted);font-size:12px;">⏳</span>'
      : '<span style="color:var(--text-muted);">—</span>';
  }
  const amt   = d.day_change_amt || 0;
  const pct   = d.day_change_pct || 0;
  const isPos = amt >= 0;
  const color = isPos ? '#16a34a' : '#dc2626';
  const sign  = isPos ? '+' : '';
  const arr   = isPos ? '▲' : '▼';
  return `<div style="color:${color};font-weight:600;font-size:13px;">${arr} ${sign}${fmtFull(Math.abs(amt))}</div>`
       + `<div style="color:${color};font-size:11px;">${arr} ${sign}${Math.abs(pct).toFixed(3)}%</div>`;
}

function renderHoldings() {
  const body    = document.getElementById('holdingsBody');
  const countEl = document.getElementById('holdingsCount');

  // Sort — map virtual cols to actual data fields
  const sortVal = (h, col) => {
    switch(col) {
      case 'fund_house':          return (h.fund_house || '').toLowerCase();
      case 'scheme_name':         return (h.scheme_name || '').toLowerCase();
      case 'ltcg_units':          return parseFloat(h.ltcg_units ?? h.stcg_units_ltcg ?? 0);
      case 'stcg_units':          return parseFloat(h.stcg_units ?? 0);
      case 'one_day_change_pct':  return parseFloat(h.one_day_pct ?? h.change_1d_pct ?? 0);
      case 'one_day_change_val':  return parseFloat(h.one_day_nav_change ?? h.change_1d_nav ?? 0);
      case 'drawdown_nav':        return parseFloat(h.drawdown_nav ?? ((h.highest_nav||0) - (h.latest_nav||0)));
      default:                    return h[col] ?? 0;
    }
  };
  MF.filtered.sort((a, b) => {
    let av = sortVal(a, MF.sortCol), bv = sortVal(b, MF.sortCol);
    if (typeof av === 'string') return MF.sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
    return MF.sortDir === 'asc' ? av - bv : bv - av;
  });

  if (!MF.filtered.length) {
    body.innerHTML = `<tr><td colspan="12" class="text-center" style="padding:40px;color:var(--text-muted);">No holdings found</td></tr>`;
    if (countEl) countEl.textContent = '0 funds';
    clearFundSelection();
    return;
  }

  if (countEl) countEl.textContent = `${MF.filtered.length} fund${MF.filtered.length !== 1 ? 's' : ''}`;

  const isFolio = MF.view === 'folio';

  // --- Client-side pagination ---
  const total   = MF.filtered.length;
  const perPage = MF.holdingsPerPage;
  const pages   = Math.ceil(total / perPage);
  if (MF.holdingsPage > pages) MF.holdingsPage = 1;
  const pageStart = (MF.holdingsPage - 1) * perPage;
  const paged     = MF.filtered.slice(pageStart, pageStart + perPage);

  body.innerHTML = paged.map(h => {
    const fundId = h.fund_id || h.id;
    const folioInfo = isFolio && h.folio_number ? `<br><small style="color:var(--text-muted);">${h.folio_number}</small>` : '';

    // ── Helper: colored cell with arrow + sign ──────────────────────
    function cell(val, isAmt = false, decimals = 2) {
      if (val === null || val === undefined || val === '' || (typeof val === 'string' && val === '—')) return '—';
      const n    = parseFloat(val);
      if (isNaN(n)) return '—';
      const pos  = n >= 0;
      const clr  = pos ? '#16a34a' : '#dc2626';
      const arr  = pos ? '▲' : '▼';
      const sign = pos ? '+' : '';
      const fmt  = isAmt ? (sign + fmtFull(Math.abs(n))) : (sign + Math.abs(n).toFixed(decimals) + '%');
      return `<span style="color:${clr};font-weight:600;">${arr} ${fmt}</span>`;
    }

    // Gain/Loss
    const gain    = h.gain_loss || 0;
    const gainPct = h.gain_pct  || 0;

    // XIRR/CAGR
    const cagrHtml = h.cagr !== null && h.cagr !== undefined
      ? cell(h.cagr, false, 2)
      : '<span style="color:var(--text-muted);">—</span>';

    // t481: Stale NAV detection (> 2 business days old)
    let navStaleBadge = '';
    if (h.latest_nav_date) {
      const navDt   = new Date(h.latest_nav_date);
      const diffMs  = new Date() - navDt;
      const diffDays = diffMs / 86400000;
      // Skip weekends — if today is Mon/Tue allow up to 3/4 days back
      const todayDay = new Date().getDay(); // 0=Sun,6=Sat
      const staleDays = (todayDay === 1) ? 3 : (todayDay === 2) ? 4 : 2;
      if (diffDays > staleDays) {
        navStaleBadge = `<span title="NAV ${Math.floor(diffDays)} days purana hai — refresh karo" style="display:inline-block;padding:1px 5px;border-radius:4px;font-size:9px;font-weight:700;background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.25);margin-left:3px;cursor:pointer;" onclick="event.stopPropagation();refreshSingleNav(${fundId})">⚠ Stale NAV</span>`;
      }
    }

    // t367: Exit Load badge
    let exitLoadBadge = '';
    if (h.exit_load_pct > 0 && h.exit_load_days > 0 && h.first_purchase_date) {
      const purchDate  = new Date(h.first_purchase_date);
      const freeDate   = new Date(purchDate.getTime() + h.exit_load_days * 86400000);
      const today2     = new Date();
      const daysToFree = Math.ceil((freeDate - today2) / 86400000);
      if (daysToFree > 0) {
        const urgColor = daysToFree < 30 ? '#dc2626' : daysToFree < 90 ? '#d97706' : '#7c3aed';
        exitLoadBadge = `<span title="Exit load ${h.exit_load_pct}% if sold before ${freeDate.toLocaleDateString('en-IN')} · Free in ${daysToFree} days" style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(124,58,237,.08);color:${urgColor};border:1px solid rgba(124,58,237,.2);margin-left:3px;">🚪 ${daysToFree}d to free exit</span>`;
      } else {
        exitLoadBadge = `<span title="No exit load (held > ${h.exit_load_days} days)" style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(22,163,74,.08);color:#15803d;border:1px solid rgba(22,163,74,.2);margin-left:3px;">✓ Free Exit</span>`;
      }
    }

    // t269: Regular plan cost badge
    let regularCostBadge = '';
    const isRegular = (h.scheme_name||'').toLowerCase().includes('regular') || (h.option_type||'').toLowerCase() === 'regular';
    if (isRegular && h.expense_ratio > 0 && h.total_invested > 0) {
      const dragPct   = Math.min(h.expense_ratio * 0.5, 0.75); // avg 0.5–0.75% drag vs direct
      const annualDrag = Math.round(h.value_now * dragPct / 100);
      regularCostBadge = `<span title="Regular plan: ~₹${annualDrag.toLocaleString('en-IN')}/yr extra cost vs Direct (est. ${dragPct.toFixed(2)}% drag)" style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(234,179,8,.1);color:#b45309;border:1px solid rgba(234,179,8,.3);cursor:help;">💸 Reg ~₹${annualDrag >= 1000 ? (annualDrag/1000).toFixed(1)+'K' : annualDrag}/yr</span>`;
    }

    // NAV
    const nav     = h.latest_nav ? `<div style="font-weight:600;">₹${Number(h.latest_nav).toFixed(4)}</div>${navStaleBadge}` : '—';
    const navDate = h.latest_nav_date ? `<small style="color:var(--text-muted);">${formatDateDisplay(h.latest_nav_date)}</small>` : '';

    // Peak NAV
    const peakNav = h.highest_nav
      ? `<div style="font-weight:600;">₹${Number(h.highest_nav).toFixed(4)}</div>${h.highest_nav_date ? `<small style="color:var(--text-muted);">${formatDateDisplay(h.highest_nav_date)}</small>` : ''}`
      : '—';

    // Drawdown
    let drawdownHtml = '—';
    if (h.drawdown_pct !== null && h.drawdown_pct !== undefined) {
      if (h.drawdown_pct <= 0) {
        drawdownHtml = `<span style="color:#16a34a;font-weight:600;">🏆 ATH</span>`;
      } else {
        const ddClr = h.drawdown_pct > 20 ? '#dc2626' : h.drawdown_pct > 10 ? '#d97706' : '#ef4444';
        const navDiff = (h.highest_nav && h.latest_nav)
          ? `<div style="font-size:11px;color:#dc2626;margin-top:2px;">▼ ₹${Number(h.highest_nav - h.latest_nav).toFixed(4)}</div>`
          : '';
        drawdownHtml = `<div style="color:${ddClr};font-weight:600;">▼ -${h.drawdown_pct}%</div>${navDiff}`;
      }
    }

    // Lock-in & LTCG badges for fund name cell
    const lockDays   = h.lock_in_days || 0;
    const ltcgDays   = h.min_ltcg_days || 365;
    const today      = new Date();
    let   lockBadge  = '';
    let   ltcgBadge  = '';

    if (lockDays > 0 && h.first_purchase_date) {
      // ELSS: show lock-in status
      const lockEndDate = new Date(new Date(h.first_purchase_date).getTime() + lockDays * 86400000);
      const daysLeft    = Math.ceil((lockEndDate - today) / 86400000);
      if (daysLeft > 0) {
        lockBadge = `<span title="Lock-in ends ${lockEndDate.toLocaleDateString('en-IN')}" style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(234,179,8,.15);color:#b45309;border:1px solid rgba(234,179,8,.3);margin-left:4px;">🔒 ${daysLeft}d left</span>`;
      } else {
        lockBadge = `<span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(22,163,74,.1);color:#15803d;border:1px solid rgba(22,163,74,.25);margin-left:4px;">🔓 Unlocked</span>`;
      }
    }

    if (h.ltcg_date) {
      const ltcgEndDate = new Date(h.ltcg_date);
      const daysToLtcg  = Math.ceil((ltcgEndDate - today) / 86400000);
      if (daysToLtcg > 0) {
        ltcgBadge = `<span title="LTCG eligible on ${h.ltcg_date}" style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(239,68,68,.08);color:#dc2626;border:1px solid rgba(239,68,68,.2);margin-left:4px;">⏳ STCG ${daysToLtcg}d</span>`;
      } else {
        ltcgBadge = `<span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(22,163,74,.1);color:#15803d;border:1px solid rgba(22,163,74,.25);margin-left:4px;">✓ LTCG</span>`;
      }
    }

    return `<tr data-fund-id="${fundId}" data-folio="${h.folio_number||''}">
      <td style="text-align:center;padding:8px 4px;vertical-align:middle;">
        <input type="checkbox" class="fund-select-cb" data-fund-id="${fundId}"
          data-scheme-name="${escAttr(h.scheme_name)}"
          data-invested="${h.total_invested||0}"
          onchange="onFundCheckboxChange()"
          style="width:15px;height:15px;cursor:pointer;accent-color:#3b82f6;">
      </td>
      <td class="fund-name-cell" style="text-align:center;">
        <div class="fund-title" title="${escHtml(h.scheme_name)}">${escHtml(h.scheme_name)}</div>
        <div class="fund-sub">${escHtml(h.fund_house_short||h.fund_house||'')}${folioInfo ? ' · ' + h.folio_number : ''}${
          h.wd_stars ? (() => {
            const colors = {1:'#9ca3af',2:'#f59e0b',3:'#f59e0b',4:'#16a34a',5:'#16a34a'};
            const labels = {1:'Poor',2:'Below Avg',3:'Average',4:'Good',5:'Excellent'};
            return ` · <span title="WD Rating: ${h.wd_stars}/5 — ${labels[h.wd_stars]} (Returns+Consistency+Risk+Expense formula)" style="color:${colors[h.wd_stars]};font-weight:700;font-size:11px;cursor:help;">${'★'.repeat(h.wd_stars)}${'☆'.repeat(5-h.wd_stars)}</span>`;
          })() : ''
        }</div>
        <div style="margin-top:4px;display:flex;flex-wrap:wrap;gap:3px;justify-content:center;align-items:center;">
          ${(() => {
            const name = (h.scheme_name||'').toLowerCase();
            const isDirect = name.includes('direct');
            const planBadge = isDirect
              ? `<span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(22,163,74,.1);color:#15803d;border:1px solid rgba(22,163,74,.2);">Direct</span>`
              : `<span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(234,179,8,.1);color:#b45309;border:1px solid rgba(234,179,8,.2);">Regular</span>`;
            const opt = (h.option_type||'').toLowerCase();
            const optBadge = opt === 'idcw'
              ? `<span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(168,85,247,.08);color:#7c3aed;">IDCW</span>`
              : `<span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(37,99,235,.08);color:var(--accent);">Growth</span>`;
            const cat = (h.category||'').toLowerCase();
            const catColor = cat.includes('debt') || cat.includes('liquid') ? ['rgba(239,68,68,.08)','#dc2626']
                           : cat.includes('hybrid') ? ['rgba(168,85,247,.08)','#7c3aed']
                           : cat.includes('index') || cat.includes('etf') ? ['rgba(6,182,212,.08)','#0891b2']
                           : cat.includes('elss') ? ['rgba(234,179,8,.1)','#b45309']
                           : ['rgba(22,163,74,.08)','#15803d'];
            const catBadge = h.category
              ? `<span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;background:${catColor[0]};color:${catColor[1]};">${escHtml(h.category)}</span>`
              : '';
            return catBadge + planBadge + optBadge;
          })()}
          ${lockBadge}${ltcgBadge}${exitLoadBadge}${regularCostBadge}
        </div>
      </td>
      <td class="text-center" style="padding:6px 8px;">
        <div style="display:flex;flex-direction:column;gap:1px;">
          <div style="font-size:12px;color:var(--text-muted);">
            ${fmtFull(h.total_invested)}
          </div>
          <div style="font-weight:700;font-size:13px;">
            ${fmtFull(h.value_now||0)}
          </div>
          <div style="font-size:12px;border-top:1px solid var(--border-color);padding-top:2px;margin-top:1px;">
            ${cell(gain, true)}
          </div>
        </div>
      </td>
      <td class="text-center" style="padding:6px 8px;">
        <div style="display:flex;flex-direction:column;gap:3px;">
          <div style="font-size:12px;">${cell(gainPct, false)}</div>
          <div style="font-size:12px;border-top:1px solid var(--border-color);padding-top:3px;">${cagrHtml}</div>
        </div>
      </td>
      <td class="text-center">
        <div style="font-weight:600;">${Number(h.total_units).toFixed(4)}</div>
        ${h.ltcg_units > 0 ? `<div style="font-size:11px;margin-top:2px;color:#16a34a;font-weight:600;">▲ L: ${Number(h.ltcg_units).toFixed(4)}</div>` : ''}
        ${h.stcg_units > 0 ? `<div style="font-size:11px;margin-top:2px;color:#ef4444;font-weight:500;">⏳ S: ${Number(h.stcg_units).toFixed(4)}</div>` : ''}
      </td>
      <td class="text-center">${nav}${navDate}</td>
      <td class="text-center">${peakNav}</td>
      <td class="text-center">${drawdownHtml}</td>
      <td class="text-center" data-1d-fund="${fundId}">${render1DCell(fundId)}</td>
      <td style="white-space:nowrap;text-align:center;padding:6px 4px;">
        <div style="display:flex;flex-direction:column;align-items:center;gap:4px;">
          <!-- Line 1: SIP + SWP badges with stop dropdown -->
          ${(h.active_sip_count > 0 || (h.active_swp_count||0) > 0) ? `
          <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:center;">
            ${h.active_sip_count > 0 ? `<span class="sip-badge-wrap" style="position:relative;display:inline-block;">
              <span
                onclick="toggleSipMenu(event,'sipmenu_sip_${fundId}')"
                style="display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #86efac;cursor:pointer;transition:all .15s;user-select:none;"
                onmouseover="this.style.background='#bbf7d0'"
                onmouseout="this.style.background='#dcfce7'"
                title="SIP ₹${h.active_sip_amount ? Number(h.active_sip_amount).toLocaleString('en-IN') : '?'} / ${h.active_sip_frequency||'monthly'} — Click for options">🔄 SIP ▾</span>
              <div id="sipmenu_sip_${fundId}" class="sip-stop-menu" style="display:none;position:absolute;top:calc(100% + 4px);left:50%;transform:translateX(-50%);background:#fff;border:1.5px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:500;min-width:160px;overflow:hidden;">
                <div onclick="openQuickSip(${fundId},'${escAttr(h.scheme_name)}',${h.latest_nav||0},'${escAttr(h.fund_house_short||'')}','${escAttr(h.category||'')}')"
                  style="padding:8px 14px;font-size:11px;font-weight:600;cursor:pointer;color:#1e293b;display:flex;align-items:center;gap:7px;transition:background .1s;"
                  onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                  ✏️ Edit / View SIP
                </div>
                <div style="height:1px;background:#f1f5f9;margin:0 10px;"></div>
                <div onclick="confirmStopSip(${h.active_sip_id||0},'SIP','${escAttr(h.scheme_name)}')"
                  style="padding:8px 14px;font-size:11px;font-weight:700;cursor:pointer;color:#dc2626;display:flex;align-items:center;gap:7px;transition:background .1s;"
                  onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background=''">
                  ⏹ Stop SIP
                </div>
              </div>
            </span>` : ''}
            ${(h.active_swp_count||0) > 0 ? `<span class="sip-badge-wrap" style="position:relative;display:inline-block;">
              <span
                onclick="toggleSipMenu(event,'sipmenu_swp_${fundId}')"
                style="display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;cursor:pointer;transition:all .15s;user-select:none;"
                onmouseover="this.style.background='#fecaca'"
                onmouseout="this.style.background='#fee2e2'"
                title="SWP ₹${h.active_swp_amount ? Number(h.active_swp_amount).toLocaleString('en-IN') : '?'} / month — Click for options">💸 SWP ▾</span>
              <div id="sipmenu_swp_${fundId}" class="sip-stop-menu" style="display:none;position:absolute;top:calc(100% + 4px);left:50%;transform:translateX(-50%);background:#fff;border:1.5px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:500;min-width:160px;overflow:hidden;">
                <div onclick="openQuickSip(${fundId},'${escAttr(h.scheme_name)}',${h.latest_nav||0},'${escAttr(h.fund_house_short||'')}','${escAttr(h.category||'')}')"
                  style="padding:8px 14px;font-size:11px;font-weight:600;cursor:pointer;color:#1e293b;display:flex;align-items:center;gap:7px;transition:background .1s;"
                  onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                  ✏️ Edit / View SWP
                </div>
                <div style="height:1px;background:#f1f5f9;margin:0 10px;"></div>
                <div onclick="confirmStopSip(${h.active_swp_id||0},'SWP','${escAttr(h.scheme_name)}')"
                  style="padding:8px 14px;font-size:11px;font-weight:700;cursor:pointer;color:#dc2626;display:flex;align-items:center;gap:7px;transition:background .1s;"
                  onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background=''">
                  ⏹ Stop SWP
                </div>
              </div>
            </span>` : ''}
          </div>` : ''}
          <!-- t181: Quick Add SIP — show only if no active SIP/SWP -->
          ${(h.active_sip_count === 0 && (h.active_swp_count||0) === 0) ? `
          <button onclick="openQuickSip(${fundId},'${escAttr(h.scheme_name)}',${h.latest_nav||0},'${escAttr(h.fund_house_short||'')}','${escAttr(h.category||'')}')" title="Quick Add SIP for this fund"
            style="display:flex;align-items:center;gap:4px;padding:4px 8px;border-radius:6px;border:1px solid rgba(22,163,74,.35);background:rgba(22,163,74,.06);cursor:pointer;font-size:11px;color:#16a34a;font-weight:600;transition:all .15s;"
            onmouseover="this.style.background='rgba(22,163,74,.15)';this.style.borderColor='#16a34a'"
            onmouseout="this.style.background='rgba(22,163,74,.06)';this.style.borderColor='rgba(22,163,74,.35)'">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            + SIP
          </button>` : ''}
          <!-- Line 2: Transactions -->
          <button onclick="openTxnDrawer(${fundId},'${escAttr(h.scheme_name)}')" title="View Transactions"
            style="display:flex;align-items:center;gap:4px;padding:4px 8px;border-radius:6px;border:1px solid var(--border-color);background:var(--bg-secondary);cursor:pointer;font-size:11px;color:var(--text-muted);font-weight:500;transition:all .15s;"
            onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)';this.style.background='rgba(37,99,235,.06)'"
            onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)';this.style.background='var(--bg-secondary)'">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
            Txns
          </button>
          <!-- t170: SIP Analysis per-fund button -->
          <button onclick="openSipReturnAnalysis(${fundId},'${escAttr(h.scheme_name)}',${h.latest_nav||0},'${h.first_purchase_date||null}',${h.total_invested||0},${h.value_now||0})" title="SIP vs Lump Sum — kaunsa better?" 
            style="display:flex;align-items:center;gap:4px;padding:4px 8px;border-radius:6px;border:1px solid rgba(99,102,241,.35);background:rgba(99,102,241,.06);cursor:pointer;font-size:11px;color:#6366f1;font-weight:600;transition:all .15s;"
            onmouseover="this.style.background='rgba(99,102,241,.15)';this.style.borderColor='#6366f1'"
            onmouseout="this.style.background='rgba(99,102,241,.06)';this.style.borderColor='rgba(99,102,241,.35)'">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Analysis
          </button>
          <!-- t182: Fund Notes -->
          <button onclick="openFundNoteModal(${fundId},'${escAttr(h.scheme_name)}')" title="Add/view note for this fund"
            id="noteBtn_${fundId}"
            style="display:flex;align-items:center;gap:4px;padding:4px 8px;border-radius:6px;border:1px solid rgba(234,179,8,.35);background:rgba(234,179,8,.06);cursor:pointer;font-size:11px;color:#b45309;font-weight:600;transition:all .15s;"
            onmouseover="this.style.background='rgba(234,179,8,.15)';this.style.borderColor='#d97706'"
            onmouseout="this.style.background='rgba(234,179,8,.06)';this.style.borderColor='rgba(234,179,8,.35)'">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><line x1="16" y1="17" x2="16" y2="17"/></svg>
            <span id="noteLbl_${fundId}">Notes</span>
          </button>
          <!-- Line 3: Delete -->
          <button onclick="openDeleteFundModal(${fundId},'${escAttr(h.scheme_name)}',${h.total_invested||0})" title="Delete this fund"
            style="display:flex;align-items:center;gap:4px;padding:4px 8px;border-radius:6px;border:1px solid rgba(239,68,68,.35);background:rgba(239,68,68,.06);cursor:pointer;font-size:11px;color:#dc2626;font-weight:600;transition:all .15s;"
            onmouseover="this.style.background='rgba(239,68,68,.15)';this.style.borderColor='#dc2626'"
            onmouseout="this.style.background='rgba(239,68,68,.06)';this.style.borderColor='rgba(239,68,68,.35)'">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            Delete
          </button>
          <!-- t112: Swap Fund -->
          <button onclick="openSwapFundModal(${fundId},'${escAttr(h.scheme_name)}',${h.total_units||0},${h.latest_nav||0})" title="Swap: exit this fund, enter another with same amount"
            style="display:flex;align-items:center;gap:4px;padding:4px 8px;border-radius:6px;border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.06);cursor:pointer;font-size:11px;color:#b45309;font-weight:600;transition:all .15s;"
            onmouseover="this.style.background='rgba(245,158,11,.15)';this.style.borderColor='#d97706'"
            onmouseout="this.style.background='rgba(245,158,11,.06)';this.style.borderColor='rgba(245,158,11,.35)'">
            🔄 Swap
          </button>
          <!-- t112: Similar Better Funds -->
          <button onclick="openSimilarFundsModal(${fundId},'${escAttr(h.scheme_name)}','${escAttr(h.category||'')}',${h.cagr_since_start||0})" title="Find similar or better-performing funds in same category"
            style="display:flex;align-items:center;gap:4px;padding:4px 8px;border-radius:6px;border:1px solid rgba(139,92,246,.35);background:rgba(139,92,246,.06);cursor:pointer;font-size:11px;color:#7c3aed;font-weight:600;transition:all .15s;"
            onmouseover="this.style.background='rgba(139,92,246,.15)';this.style.borderColor='#7c3aed'"
            onmouseout="this.style.background='rgba(139,92,246,.06)';this.style.borderColor='rgba(139,92,246,.35)'">
            💡 Similar
          </button>
        </div>
      </td>
    </tr>`;
  }).join('');



  // --- Render pagination bar ---
  const wrap     = document.getElementById('holdingsPaginationWrap');
  const infoEl   = document.getElementById('holdingsPaginationInfo');
  const pgEl     = document.getElementById('holdingsPagination');

  if (wrap && infoEl && pgEl) {
    wrap.style.display = total > 0 ? 'flex' : 'none';
    const from = pageStart + 1;
    const to   = Math.min(pageStart + perPage, total);
    infoEl.textContent = total > 0 ? `${from}–${to} of ${total}` : '';

    let html = '';
    if (pages > 1) {
      if (MF.holdingsPage > 1)
        html += `<button class="btn btn-ghost btn-sm" onclick="goHoldingsPage(${MF.holdingsPage-1})">‹ Prev</button>`;
      const start = Math.max(1, MF.holdingsPage - 2);
      const end   = Math.min(pages, MF.holdingsPage + 2);
      for (let p = start; p <= end; p++)
        html += `<button class="btn btn-ghost btn-sm ${p===MF.holdingsPage?'active':''}" onclick="goHoldingsPage(${p})">${p}</button>`;
      if (MF.holdingsPage < pages)
        html += `<button class="btn btn-ghost btn-sm" onclick="goHoldingsPage(${MF.holdingsPage+1})">Next ›</button>`;
      pgEl.innerHTML = html;
    }
  }

  // Stat card sync: recalculate 1D total against currently visible (filtered) funds.
  // Runs on every re-render so sort/filter/page never leaves the tile stale.
  inject1DayStatCard();

  // t112: Portfolio Health Score — fire event with all holdings data
  window.dispatchEvent(new CustomEvent('mf:holdingsLoaded', { detail: { holdings: MF.filtered } }));
}

async function load1DayChange() {
  try {
    const portfolioId = window.WD?.selectedPortfolio || 0;
    let url = '/api/nav/nav_1d_change.php';
    if (portfolioId) url += '?portfolio_id=' + portfolioId;
    const res = await API.get(url);
    if (!res.success) return;
    MF.oneDayData = res.data || {};
    // Re-render all rows so render1DCell() picks up fresh data inline.
    // This is cheaper than DOM-walking: renderHoldings already runs in <1 ms
    // for typical portfolio sizes and also refreshes the stat card at the end.
    renderHoldings();
  } catch(e) {
    // silent fail — 1D data is non-critical
    console.warn('1D change fetch failed:', e);
  }
}

// inject1DayStatCard — updates ONLY the "Today's Change" stat tile.
// Called at the end of every renderHoldings() so it stays in sync with
// whatever subset of funds is currently filtered/visible.
function inject1DayStatCard() {
  const data = MF.oneDayData;
  if (!Object.keys(data).length) return; // nothing loaded yet

  let totalAmt = 0, hasSomeData = false;
  MF.filtered.forEach(h => {
    const fid = String(h.fund_id || h.id);
    const d   = data[fid];
    if (d && (d.day_change_amt !== null || d.day_change_pct !== null)) {
      hasSomeData = true;
      totalAmt += d.day_change_amt || 0;
    }
  });

  const cardAmt  = document.getElementById('stat1dAmt');
  const cardPct  = document.getElementById('stat1dPct');
  const cardIcon = document.getElementById('stat1dIcon');
  if (!cardAmt || !hasSomeData) return;

  const isPos  = totalAmt >= 0;
  const color  = isPos ? '#16a34a' : '#dc2626';
  const sign   = isPos ? '+' : '';
  const arr    = isPos ? '▲' : '▼';
  const totVal = MF.filtered.reduce((s, h) => s + (h.value_now || 0), 0);
  const totPct = totVal > 0 ? ((totalAmt / totVal) * 100).toFixed(3) : '0.000';

  cardAmt.textContent = arr + ' ' + sign + fmtInr(Math.abs(totalAmt));
  cardAmt.style.color = color;
  if (cardPct) { cardPct.textContent = '(' + sign + totPct + '%)'; cardPct.style.color = color; }
  if (cardIcon) {
    cardIcon.innerHTML = isPos
      ? `<svg width="26" height="24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="1,17 6,10 10,14 15,7 19,11 23,4"/><polyline points="19,4 23,4 23,8"/></svg>`
      : `<svg width="26" height="24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="1,7 6,14 10,10 15,17 19,13 23,20"/><polyline points="19,20 23,20 23,16"/></svg>`;
  }
}

// inject1DayChange — kept for backward-compat; now delegates to the stat card
// updater only (row cells are rendered inline by render1DCell).
function inject1DayChange() {
  inject1DayStatCard();
}


function goHoldingsPage(p) {
  MF.holdingsPage = p;
  renderHoldings();
  document.getElementById('holdingsBody')?.closest('.card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function changeHoldingsPerPage(val) {
  MF.holdingsPerPage = parseInt(val);
  MF.holdingsPage = 1;
  renderHoldings();
}

function changeTxnPerPage(val) {
  MF.perPage = parseInt(val);
  MF.page = 1;
  loadTransactions(1);
}

function updateSummaryCards(summary) {
  window._lastSummary = summary; // cache for format toggle re-render
  const inv  = summary.total_invested  || 0;
  const val  = summary.value_now       || 0;
  const gain = summary.gain_loss       || 0;
  const pct  = inv > 0 ? ((gain/inv)*100).toFixed(2) : '0.00';
  const isPos = gain >= 0;

  setEl('mfTotalInvested', fmtInr(inv));
  setEl('mfValueNow',      fmtInr(val));

  const gainEl = document.getElementById('mfGainLoss');
  if (gainEl) {
    gainEl.textContent = (isPos ? '+' : '') + fmtInr(Math.abs(gain));
    gainEl.className   = 'stat-value ' + (isPos ? 'positive' : 'negative');
  }
  const pctEl = document.getElementById('mfGainPct');
  if (pctEl) {
    pctEl.textContent = '(' + (isPos ? '+' : '') + pct + '%)';
    pctEl.style.color = isPos ? 'var(--gain,#16a34a)' : 'var(--loss,#dc2626)';
  }

  // Update gain/loss icon
  const iconEl = document.getElementById('mfGainIcon');
  if (iconEl) {
    iconEl.innerHTML = isPos
      ? `<svg width="26" height="24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
           <polyline points="1,17 6,10 10,14 15,7 19,11 23,4"/>
           <polyline points="19,4 23,4 23,8"/>
         </svg>`
      : `<svg width="26" height="24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
           <polyline points="1,7 6,14 10,10 15,17 19,13 23,20"/>
           <polyline points="19,20 23,20 23,16"/>
         </svg>`;
  }

  setEl('mfFundCount', summary.fund_count || '');
}

/* ═══════════════════════════════════════════════════════════════════════════
   TRANSACTION PAGE
═══════════════════════════════════════════════════════════════════════════ */
function initTxnPage() {
  loadTransactions();

  document.getElementById('btnAddTxn')?.addEventListener('click', openAddTxnModal);
  document.getElementById('btnTxnFilter')?.addEventListener('click', loadTransactions);
  document.getElementById('btnTxnReset')?.addEventListener('click', resetTxnFilters);
  document.getElementById('btnExportTxnCsv')?.addEventListener('click', exportTxnCsv);
  document.getElementById('txnSearch')?.addEventListener('input', debounce(loadTransactions, 400));
  ['txnFilterType', 'txnFilterFy'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', loadTransactions);
  });

  // t31: Sortable column headers
  document.querySelectorAll('#txnTable th.sortable[data-col]').forEach(th => {
    th.style.cursor = 'pointer';
    th.style.userSelect = 'none';
    th.addEventListener('click', () => {
      const col = th.dataset.col;
      if (MF.txnSortCol === col) {
        MF.txnSortDir = MF.txnSortDir === 'asc' ? 'desc' : 'asc';
      } else {
        MF.txnSortCol = col;
        MF.txnSortDir = col === 'txn_date' ? 'desc' : 'asc';
      }
      _updateTxnSortHeaders();
      loadTransactions(1);
    });
  });
  _updateTxnSortHeaders();
}

// t31: Update sort arrow indicators on all sortable headers
function _updateTxnSortHeaders() {
  document.querySelectorAll('#txnTable th.sortable[data-col]').forEach(th => {
    const col = th.dataset.col;
    // Remove old indicators
    const existing = th.querySelector('.txn-sort-arrow');
    if (existing) existing.remove();

    const isActive = MF.txnSortCol === col;
    const arrow = document.createElement('span');
    arrow.className = 'txn-sort-arrow';
    arrow.style.cssText = 'margin-left:4px;font-size:10px;opacity:' + (isActive ? '1' : '0.3');
    arrow.textContent = isActive ? (MF.txnSortDir === 'asc' ? ' ▲' : ' ▼') : ' ▼';
    th.appendChild(arrow);

    th.style.color = isActive ? 'var(--accent)' : '';
    th.style.fontWeight = isActive ? '700' : '';
  });
}

async function loadTransactions(page = 1) {
  const body = document.getElementById('txnBody');
  body.innerHTML = `<tr><td colspan="11" class="text-center" style="padding:40px;"><div class="spinner"></div></td></tr>`;

  const params = new URLSearchParams({
    view: 'transactions',
    page: page,
    per_page: MF.perPage
  });
  const type = document.getElementById('txnFilterType')?.value;
  const fy   = document.getElementById('txnFilterFy')?.value;
  const from = document.getElementById('txnFilterFrom')?.value;
  const to   = document.getElementById('txnFilterTo')?.value;
  const q    = document.getElementById('txnSearch')?.value;
  params.set('portfolio_id', window.WD?.selectedPortfolio || 0);
  if (type) params.set('txn_type', type);
  if (fy)   params.set('fy', fy);
  if (from) params.set('from', from);
  if (to)   params.set('to', to);
  if (q)    params.set('q', q);
  // t31: send sort params
  params.set('sort_col', MF.txnSortCol || 'txn_date');
  params.set('sort_dir', MF.txnSortDir || 'desc');

  try {
    const res = await API.get(`/api/mutual_funds/mf_list.php?${params}`);
    MF.totalTxns = res.total || 0;
    MF.page = page;
    renderTxnTable(res.data || []);
    renderPagination(res.total, res.page, res.per_page, res.pages);
    // Update summary stats bar (only on txn page)
    if (res.summary) _renderTxnSummary(res.summary);
    // Populate FY dropdown once (first load, all FYs visible)
    if (res.fy_list && page === 1 && !fy && !type && !from && !to && !q) {
      _populateFyDropdown(res.fy_list);
    }
  } catch (err) {
    body.innerHTML = `<tr><td colspan="11" class="text-center text-danger">${err.message}</td></tr>`;
  }
}

function renderTxnTable(txns) {
  window._lastTxns = txns; // cache for format toggle
  const body  = document.getElementById('txnBody');
  const count = document.getElementById('txnTotalCount');
  if (count) count.textContent = `${MF.totalTxns} transactions`;

  if (!txns.length) {
    body.innerHTML = `<tr><td colspan="11" class="text-center" style="padding:40px;color:var(--text-muted);">No transactions found</td></tr>`;
    return;
  }

  const typeColors = { BUY:'badge-success', SELL:'badge-danger', DIV_REINVEST:'badge-info',
                       SWITCH_IN:'badge-primary', SWITCH_OUT:'badge-warning' };

  body.innerHTML = txns.map(t => `
    <tr>
      <td>${formatDateDisplay(t.txn_date)}</td>
      <td>
        <div style="font-weight:500;">${escHtml(t.scheme_name)}</div>
        <small style="color:var(--text-muted);">${escHtml(t.fund_house||'')} · ${escHtml(t.category||'')}</small>
      </td>
      <td>${t.folio_number||'—'}</td>
      <td><span class="badge ${typeColors[t.transaction_type]||'badge-secondary'}">${t.transaction_type}</span></td>
      <td class="text-right">${Number(t.units).toFixed(4)}</td>
      <td class="text-right">₹${Number(t.nav).toFixed(4)}</td>
      <td class="text-right">${fmtFull(t.value_at_cost)}</td>
      <td><span style="font-size:11px;color:var(--text-muted);">${escHtml(t.investment_fy||'—')}</span></td>
      <td>${escHtml(t.platform||'—')}</td>
      <td>
        <div style="display:flex;gap:4px;">
          <button class="btn btn-ghost btn-xs" onclick="editTransaction(${t.id})" title="Edit">✏️</button>
          <button class="btn btn-ghost btn-xs" onclick="deleteTransaction(${t.id},'${escAttr(t.scheme_name)}')" title="Delete">🗑</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function renderPagination(total, page, perPage, pages) {
  const info = document.getElementById('txnPaginationInfo');
  const pg   = document.getElementById('txnPagination');
  if (!info || !pg) return;

  const from = Math.min((page-1)*perPage+1, total);
  const to   = Math.min(page*perPage, total);
  info.textContent = `${from}–${to} of ${total}`;

  let html = '';
  if (page > 1) html += `<button class="btn btn-ghost btn-sm" onclick="loadTransactions(${page-1})">‹ Prev</button>`;
  const start = Math.max(1, page-2), end = Math.min(pages, page+2);
  for (let p = start; p <= end; p++) {
    html += `<button class="btn btn-ghost btn-sm ${p===page?'active':''}" onclick="loadTransactions(${p})">${p}</button>`;
  }
  if (page < pages) html += `<button class="btn btn-ghost btn-sm" onclick="loadTransactions(${page+1})">Next ›</button>`;
  pg.innerHTML = html;
}

function resetTxnFilters() {
  ['txnFilterType','txnFilterFy','txnFilterFrom','txnFilterTo','txnSearch'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  loadTransactions();
}

/* ── txn page summary stats bar ─────────────────────────────── */
function _renderTxnSummary(s) {
  function fmt(v) {
    v = Math.abs(parseFloat(v) || 0);
    if (v >= 1e7) return '₹' + (v / 1e7).toFixed(2) + ' Cr';
    if (v >= 1e5) return '₹' + (v / 1e5).toFixed(2) + ' L';
    return '₹' + v.toLocaleString('en-IN', { maximumFractionDigits: 0 });
  }
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('statTotalTxns',    (s.total_txns || 0).toLocaleString('en-IN'));
  set('statTotalBuy',     fmt(s.total_buy));
  set('statTotalSell',    fmt(s.total_sell));
  const net = parseFloat(s.net_invested) || 0;
  const netEl = document.getElementById('statNetInvested');
  if (netEl) {
    netEl.textContent = fmt(net);
    netEl.style.color = net >= 0 ? '' : 'var(--danger)';
  }
  set('statUniqueFunds',  s.unique_funds || 0);
}

/* ── Populate FY dropdown from API list ──────────────────────── */
function _populateFyDropdown(fyList) {
  const sel = document.getElementById('txnFilterFy');
  if (!sel || !fyList || !fyList.length) return;
  const current = sel.value;
  sel.innerHTML = '<option value="">All FYs</option>' +
    fyList.map(fy => `<option value="${fy}"${fy === current ? ' selected' : ''}>${fy}</option>`).join('');
}

function exportTxnCsv() {
  // t35: Pass active filters to export
  const params = new URLSearchParams({ action: 'export_mf_csv' });
  const search = document.getElementById('searchFund')?.value || '';
  const cat    = document.getElementById('filterCategory')?.value || '';
  const gain   = document.getElementById('filterGainType')?.value || '';
  if (search) params.set('search', search);
  if (cat)    params.set('category', cat);
  if (gain)   params.set('gain_type', gain);
  if (MF.search) params.set('search', MF.search);
  params.set('portfolio_id', window.WD?.selectedPortfolio || '');
  window.location = `${window.APP_URL}/api/reports/export_csv.php?${params}`;
}

/* ═══════════════════════════════════════════════════════════════════════════
   ADD / EDIT TRANSACTION MODAL
═══════════════════════════════════════════════════════════════════════════ */
function openAddTxnModal() {
  document.getElementById('modalTxnTitle').textContent = 'Add MF Transaction';
  document.getElementById('txnEditId').value   = '';
  document.getElementById('txnFundSearch').value = '';
  document.getElementById('txnFundId').value   = '';
  document.getElementById('txnFundInfo').textContent = '';
  document.getElementById('txnFolio').value    = '';
  document.getElementById('txnUnits').value    = '';
  document.getElementById('txnNav').value      = '';
  document.getElementById('txnStampDuty').value= '0';
  document.getElementById('txnNotes').value    = '';
  // Use local date (IST), not UTC
  const _now = new Date();
  const _localDate = _now.getFullYear() + '-' +
    String(_now.getMonth()+1).padStart(2,'0') + '-' +
    String(_now.getDate()).padStart(2,'0');
  document.getElementById('txnDate').value = _localDate;
  document.getElementById('txnError').style.display = 'none';
  document.getElementById('txnValuePreview').style.display = 'none';
  MF.selectedFundId = null; MF.selectedFundNav = null;
  const b = document.getElementById('availableUnitsBanner');
  if (b) b.style.display = 'none';
  showModal('modalAddTxn');
}

function openAddTxnForFund(fundId, fundName) {
  openAddTxnModal();
  document.getElementById('txnFundSearch').value = fundName;
  document.getElementById('txnFundId').value = fundId;
  MF.selectedFundId = fundId;
  fetchAndShowFundInfo(fundId);
}

async function editTransaction(txnId) {
  try {
    const res = await API.get(`/api/mutual_funds/mf_list.php?view=transactions&txn_id=${txnId}&per_page=1`);
    const txn = res.data?.[0];
    if (!txn) { showToast('Transaction not found','error'); return; }

    document.getElementById('modalTxnTitle').textContent = 'Edit Transaction';
    document.getElementById('txnEditId').value    = txn.id;
    document.getElementById('txnType').value      = txn.transaction_type;
    document.getElementById('txnFundSearch').value= txn.scheme_name;
    document.getElementById('txnFundId').value    = txn.fund_id;
    document.getElementById('txnFolio').value     = txn.folio_number || '';
    document.getElementById('txnDate').value      = txn.txn_date;
    document.getElementById('txnUnits').value     = txn.units;
    document.getElementById('txnNav').value       = txn.nav;
    document.getElementById('txnStampDuty').value = txn.stamp_duty || 0;
    document.getElementById('txnNotes').value     = txn.notes || '';
    document.getElementById('txnPlatform').value  = txn.platform || '';
    document.getElementById('txnError').style.display = 'none';
    MF.selectedFundId = txn.fund_id;
    updateValuePreview();
    showModal('modalAddTxn');
  } catch (err) {
    showToast('Error loading transaction: ' + err.message, 'error');
  }
}

async function deleteTransaction(txnId, fundName) {
  showConfirm({
    title:     'Delete Transaction',
    message:   `Are you sure you want to delete the transaction for <strong>${escHtml(fundName)}</strong>?<br><span style="color:var(--text-muted);font-size:13px;">Holdings will be recalculated automatically. This action cannot be undone.</span>`,
    okText:    'Delete',
    onConfirm: async () => {
      const csrf = document.getElementById('txnCsrf')?.value || await getCsrf();
      await API.post('/api/mutual_funds/mf_delete.php', { txn_id: txnId, csrf_token: csrf });
      showToast('Transaction deleted successfully', 'success');
      reloadCurrentPage();
    }
  });
}

function _getPortfolioId() {
  return window.WD?.selectedPortfolio || 0;
}

// ── FUND-LEVEL DELETE ─────────────────────────────────────────

// State for single-fund delete modal
const _df = { fundId: null, confirmPhrase: '' };

function _randPhrase() {
  // 6-char random alphanumeric (uppercase)
  return Math.random().toString(36).slice(2, 8).toUpperCase();
}

function openDeleteFundModal(fundId, schemeName, invested) {
  _df.fundId        = fundId;
  _df.confirmPhrase = _randPhrase();

  document.getElementById('deleteFundName').textContent      = schemeName;
  document.getElementById('deleteFundMeta').textContent      = `Invested: ${fmtFull(invested)}`;
  document.getElementById('deleteConfirmPhrase').textContent = _df.confirmPhrase;

  const inp = document.getElementById('deleteFundConfirmInput');
  inp.value = '';
  inp.onpaste     = (e) => { e.preventDefault(); showToast('Paste / drop not allowed — type the code manually', 'error'); };
  inp.ondrop      = (e) => { e.preventDefault(); showToast('Paste / drop not allowed — type the code manually', 'error'); };
  inp.ondragover  = (e) => e.preventDefault();

  document.getElementById('deleteFundConfirmHint').textContent = '';

  const btn = document.getElementById('btnConfirmDeleteFund');
  btn.disabled = true;
  btn.style.opacity = '0.45';
  btn.style.cursor  = 'not-allowed';

  showModal('modalDeleteFund');
  setTimeout(() => inp.focus(), 200);
}

function closeDeleteFundModal() {
  hideModal('modalDeleteFund');
  _df.fundId = null;
}

function checkDeleteFundConfirm() {
  const val  = document.getElementById('deleteFundConfirmInput').value.trim().toUpperCase();
  const hint = document.getElementById('deleteFundConfirmHint');
  const btn  = document.getElementById('btnConfirmDeleteFund');
  const ok   = val === _df.confirmPhrase;
  btn.disabled      = !ok;
  btn.style.opacity = ok ? '1' : '0.45';
  btn.style.cursor  = ok ? 'pointer' : 'not-allowed';
  hint.textContent  = ok ? '✓ Code matched — you can now delete.' : '';
  hint.style.color  = '#16a34a';
}

async function confirmDeleteFund() {
  const btn     = document.getElementById('btnConfirmDeleteFund');
  const label   = document.getElementById('btnConfirmDeleteFundLabel');
  const spinner = document.getElementById('btnConfirmDeleteFundSpinner');
  if (btn.disabled) return;

  btn.disabled    = true;
  label.style.display   = 'none';
  spinner.style.display = 'inline-block';

  try {
    const csrf = document.getElementById('txnCsrf')?.value || await getCsrf();
    const res  = await API.post('/api/mutual_funds/mf_delete.php', {
      fund_ids:     [_df.fundId],
      portfolio_id: _getPortfolioId(),
      csrf_token:   csrf,
    });
    hideModal('modalDeleteFund');
    showToast(res.message || 'Fund deleted successfully', 'success');
    clearFundSelection();
    await loadHoldings();
  } catch (err) {
    showToast('Delete failed: ' + err.message, 'error');
    btn.disabled          = false;
    label.style.display   = '';
    spinner.style.display = 'none';
  }
}

// ── BULK DELETE ───────────────────────────────────────────────

function onFundCheckboxChange() {
  const checked = document.querySelectorAll('.fund-select-cb:checked');
  const bar     = document.getElementById('bulkDeleteBar');
  const countEl = document.getElementById('bulkSelectedCount');
  const selAll  = document.getElementById('selectAllFunds');
  const allCbs  = document.querySelectorAll('.fund-select-cb');

  if (bar)     bar.style.display     = checked.length > 0 ? 'flex' : 'none';
  if (countEl) countEl.textContent   = `${checked.length} fund${checked.length !== 1 ? 's' : ''} selected`;
  if (selAll)  selAll.indeterminate  = checked.length > 0 && checked.length < allCbs.length;
  if (selAll && checked.length === allCbs.length && allCbs.length > 0) selAll.checked = true;
  if (selAll && checked.length === 0) { selAll.checked = false; selAll.indeterminate = false; }
}

function _positionSortMenu() {
  const menu = document.getElementById('sortMenuDropdown');
  const btn  = document.getElementById('btnSortMenu');
  if (!menu || !btn || menu.style.display !== 'block') return;

  const rect  = btn.getBoundingClientRect();
  const menuW = 240;
  const gap   = 4;
  const viewH = window.innerHeight;
  const viewW = window.innerWidth;

  // Horizontal: align left edge of menu to left edge of button, clamp to viewport
  let left = rect.left;
  if (left + menuW > viewW - 8) left = Math.max(8, viewW - menuW - 8);

  // Vertical: prefer below, flip above if not enough room
  const spaceBelow = viewH - rect.bottom - gap;
  const spaceAbove = rect.top - gap;
  let top, maxH;
  if (spaceBelow >= 180 || spaceBelow >= spaceAbove) {
    top  = rect.bottom + gap;
    maxH = Math.min(spaceBelow - 4, 480);
  } else {
    maxH = Math.min(spaceAbove - 4, 480);
    top  = rect.top - gap - Math.min(menu.scrollHeight, maxH);
  }

  menu.style.position  = 'fixed';
  menu.style.left      = left + 'px';
  menu.style.top       = top + 'px';
  menu.style.maxHeight = maxH + 'px';
  menu.style.width     = menuW + 'px';
  menu.style.overflowY = 'auto';
}

function toggleSortMenu(e) {
  e.stopPropagation();
  const menu = document.getElementById('sortMenuDropdown');
  if (menu.style.display === 'block') { menu.style.display = 'none'; return; }

  menu.style.display = 'block';
  // rAF: let the browser do one layout pass so scrollHeight is real before we position
  requestAnimationFrame(_positionSortMenu);

  // Update active indicators
  document.querySelectorAll('.sort-dir-indicator').forEach(el => {
    if (el.dataset.col === MF.sortCol) {
      el.textContent = MF.sortDir === 'asc' ? '↑ ASC' : '↓ DESC';
      el.style.display = 'inline';
    } else {
      el.style.display = 'none';
    }
  });
  document.querySelectorAll('.sort-menu-item').forEach(el => {
    el.style.fontWeight = el.dataset.col === MF.sortCol ? '700' : '400';
  });
}

function applySortMenu(col) {
  if (MF.sortCol === col) {
    MF.sortDir = MF.sortDir === 'asc' ? 'desc' : 'asc';
  } else {
    MF.sortCol = col;
    MF.sortDir = 'desc';
  }
  document.getElementById('sortMenuDropdown').style.display = 'none';
  // Update btn label to show active sort
  const labels = {
    scheme_name:'Name',        fund_house:'Fund House',
    total_invested:'Invested', value_now:'Value',      gain_loss:'Gain',
    gain_pct:'Returns',        cagr:'XIRR',
    total_units:'Units',       ltcg_units:'LTCG Units', stcg_units:'STCG Units',
    latest_nav:'NAV',          highest_nav:'Peak NAV',
    one_day_change_pct:'1D %', one_day_change_val:'1D ₹',
    drawdown_pct:'Drawdown %', drawdown_nav:'Drawdown ₹'
  };
  const btn = document.getElementById('btnSortMenu');
  if (btn) btn.innerHTML = `
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/></svg>
    ${labels[col]||col} ${MF.sortDir==='asc'?'↑':'↓'}`;
  renderHoldings();
}


function toggleSelectAllFunds(checked) {
  document.querySelectorAll('.fund-select-cb').forEach(cb => cb.checked = checked);
  onFundCheckboxChange();
}

function clearFundSelection() {
  document.querySelectorAll('.fund-select-cb').forEach(cb => cb.checked = false);
  const selAll = document.getElementById('selectAllFunds');
  if (selAll) { selAll.checked = false; selAll.indeterminate = false; }
  const bar = document.getElementById('bulkDeleteBar');
  if (bar) bar.style.display = 'none';
}

function openBulkDeleteModal() {
  const checked = document.querySelectorAll('.fund-select-cb:checked');
  if (!checked.length) return;

  document.getElementById('bulkModalCount').textContent     = checked.length;
  const inp = document.getElementById('bulkDeleteConfirmInput');
  inp.value = '';
  inp.onpaste    = (e) => { e.preventDefault(); showToast('Paste / drop not allowed — type DELETE manually', 'error'); };
  inp.ondrop     = (e) => { e.preventDefault(); showToast('Paste / drop not allowed — type DELETE manually', 'error'); };
  inp.ondragover = (e) => e.preventDefault();
  document.getElementById('bulkDeleteConfirmHint').textContent = '';

  const list = document.getElementById('bulkDeleteFundList');
  list.innerHTML = Array.from(checked).map(cb => {
    const inv = fmtFull(cb.dataset.invested || 0);
    return `<li><strong>${escHtml(cb.dataset.schemeName)}</strong> <span style="color:var(--text-muted);font-size:12px;">— ${inv} invested</span></li>`;
  }).join('');

  const btn = document.getElementById('btnConfirmBulkDelete');
  btn.disabled = true; btn.style.opacity = '0.45'; btn.style.cursor = 'not-allowed';

  showModal('modalBulkDelete');
  setTimeout(() => inp.focus(), 200);
}

function closeBulkDeleteModal() { hideModal('modalBulkDelete'); }

function checkBulkDeleteConfirm() {
  const val  = document.getElementById('bulkDeleteConfirmInput').value.trim().toUpperCase();
  const hint = document.getElementById('bulkDeleteConfirmHint');
  const btn  = document.getElementById('btnConfirmBulkDelete');
  const ok   = val === 'DELETE';
  btn.disabled      = !ok;
  btn.style.opacity = ok ? '1' : '0.45';
  btn.style.cursor  = ok ? 'pointer' : 'not-allowed';
  hint.textContent  = ok ? '✓ Confirmed — proceed with deletion.' : '';
  hint.style.color  = '#16a34a';
}

async function confirmBulkDelete() {
  const btn     = document.getElementById('btnConfirmBulkDelete');
  const label   = document.getElementById('btnConfirmBulkDeleteLabel');
  const spinner = document.getElementById('btnConfirmBulkDeleteSpinner');
  if (btn.disabled) return;

  const checked = document.querySelectorAll('.fund-select-cb:checked');
  const fundIds = Array.from(checked).map(cb => parseInt(cb.dataset.fundId));
  if (!fundIds.length) return;

  btn.disabled = true; label.style.display = 'none'; spinner.style.display = 'inline-block';

  try {
    const csrf = document.getElementById('txnCsrf')?.value || await getCsrf();
    const res  = await API.post('/api/mutual_funds/mf_delete.php', {
      fund_ids:     fundIds,
      portfolio_id: _getPortfolioId(),
      csrf_token:   csrf,
    });
    hideModal('modalBulkDelete');
    showToast(res.message || `${fundIds.length} fund(s) deleted`, 'success');
    clearFundSelection();
    await loadHoldings();
  } catch (err) {
    showToast('Bulk delete failed: ' + err.message, 'error');
    btn.disabled = false; label.style.display = ''; spinner.style.display = 'none';
  }
}

// ── END FUND DELETE ───────────────────────────────────────────

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function saveTransaction() {
  const editId     = document.getElementById('txnEditId').value;
  const portfolioId= window.WD?.selectedPortfolio || 0;
  const txnType    = document.getElementById('txnType').value;
  const fundId     = document.getElementById('txnFundId').value;
  const folio      = document.getElementById('txnFolio').value;
  const date       = document.getElementById('txnDate').value;
  const units      = document.getElementById('txnUnits').value;
  const nav        = document.getElementById('txnNav').value;
  const stampDuty  = document.getElementById('txnStampDuty').value;
  const platform   = document.getElementById('txnPlatform').value;
  const notes      = document.getElementById('txnNotes').value;
  const csrf       = document.getElementById('txnCsrf').value;
  const errEl      = document.getElementById('txnError');

  // Client-side validation
  if (!fundId) { showError(errEl, 'Please select a fund'); return; }
  if (!date)   { showError(errEl, 'Please enter a date'); return; }
  if (!units || parseFloat(units) <= 0) { showError(errEl, 'Units must be > 0'); return; }
  if (!nav  || parseFloat(nav)   <= 0) { showError(errEl, 'NAV must be > 0');   return; }

  setBtnLoading('btnSaveTxn', 'btnSaveTxnLabel', 'btnSaveTxnSpinner', true);
  errEl.style.display = 'none';

  const payload = {
    portfolio_id: portfolioId, fund_id: fundId, folio_number: folio,
    transaction_type: txnType, platform, txn_date: date,
    units, nav, stamp_duty: stampDuty, notes, csrf_token: csrf
  };
  if (editId) payload.edit_id = editId;

  try {
    await API.post('/api/mutual_funds/mf_add.php', payload);
    showToast(editId ? 'Transaction updated' : 'Transaction added');
    closeAddTxnModal();
    reloadCurrentPage();
  } catch (err) {
    showError(errEl, err.message);
  } finally {
    setBtnLoading('btnSaveTxn', 'btnSaveTxnLabel', 'btnSaveTxnSpinner', false);
  }
}

function closeAddTxnModal() { hideModal('modalAddTxn'); }

/* ═══════════════════════════════════════════════════════════════════════════
   FUND SEARCH AUTOCOMPLETE
═══════════════════════════════════════════════════════════════════════════ */
function onFundSearchInput(e) {
  clearTimeout(MF.fundSearchTimer);
  const q = e.target.value.trim();
  document.getElementById('txnFundId').value = '';
  document.getElementById('txnFundInfo').textContent = '';
  MF.selectedFundId = null;

  if (q.length < 2) {
    document.getElementById('fundSearchDropdown').style.display = 'none';
    return;
  }

  MF.fundSearchTimer = setTimeout(() => searchFunds(q), 300);
}

async function updateAvailableUnits() {
  const type    = document.getElementById('txnType')?.value || '';
  const banner  = document.getElementById('availableUnitsBanner');
  const sellTypes = ['SELL', 'SWITCH_OUT'];

  if (!sellTypes.includes(type) || !MF.selectedFundId) {
    if (banner) banner.style.display = 'none';
    return;
  }

  const portfolioId = window.WD?.selectedPortfolio || 0;
  // Read date directly from input value (already in YYYY-MM-DD format)
  const txnDateEl = document.getElementById('txnDate');
  const txnDate   = txnDateEl?.value || (() => {
    const n = new Date();
    return n.getFullYear() + '-' + String(n.getMonth()+1).padStart(2,'0') + '-' + String(n.getDate()).padStart(2,'0');
  })();
  const folio       = document.getElementById('txnFolio')?.value.trim() || '';

  console.log('[AvailUnits] fund_id:', MF.selectedFundId, 'portfolio:', portfolioId, 'date:', txnDate);

  if (!portfolioId) return;

  try {
    let url = `/api/mutual_funds/mf_available_units.php?portfolio_id=${portfolioId}&fund_id=${MF.selectedFundId}&date=${txnDate}`;
    if (folio) url += `&folio=${encodeURIComponent(folio)}`;
    const res  = await fetch(window.APP_URL + url);
    const data = await res.json();

    if (!banner) return;
    banner.style.display = 'block';

    const unitsEl    = document.getElementById('availableUnitsVal');
    const dateEl     = document.getElementById('availableUnitsDate');
    const avgEl      = document.getElementById('availableAvgNav');
    const investedEl = document.getElementById('availableTotalInvested');

    const units = parseFloat(data.available_units || 0);
    if (unitsEl) {
      unitsEl.textContent = units.toFixed(4);
      unitsEl.style.color = units > 0 ? '#b45309' : '#dc2626';
    }
    if (dateEl)     dateEl.textContent     = txnDate ? `(as of ${txnDate})` : '';
    if (avgEl)      avgEl.textContent      = data.avg_cost_nav  ? '₹' + parseFloat(data.avg_cost_nav).toFixed(4) : '—';
    if (investedEl) investedEl.textContent = data.total_invested ? fmtFull(data.total_invested) : '—';

    // ── ELSS lock-in warning ─────────────────────────────────────
    const lockDays = MF.selectedFundLockDays || 0;
    let lockWarnEl = document.getElementById('elssLockWarn');
    if (lockDays > 0) {
      // Find first purchase date from holdings data
      const holding = MF.data.find(h => h.fund_id === MF.selectedFundId);
      const firstDate = holding?.first_purchase_date;
      if (firstDate) {
        const lockEndDate = new Date(new Date(firstDate).getTime() + lockDays * 86400000);
        const today       = new Date();
        const daysLeft    = Math.ceil((lockEndDate - today) / 86400000);
        if (daysLeft > 0) {
          if (!lockWarnEl) {
            lockWarnEl = document.createElement('div');
            lockWarnEl.id = 'elssLockWarn';
            lockWarnEl.style.cssText = 'margin-top:8px;padding:8px 12px;border-radius:6px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);font-size:12px;color:#dc2626;font-weight:600;';
            banner.appendChild(lockWarnEl);
          }
          lockWarnEl.innerHTML = `⚠️ ELSS Lock-in: Cannot redeem until ${lockEndDate.toLocaleDateString('en-IN')} &nbsp;(${daysLeft} days remaining)`;
          lockWarnEl.style.display = 'block';
        } else if (lockWarnEl) {
          lockWarnEl.style.display = 'none';
        }
      }
    } else if (lockWarnEl) {
      lockWarnEl.style.display = 'none';
    }
    // ─────────────────────────────────────────────────────────────

    // Auto-fill max units hint
    const unitsInput = document.getElementById('txnUnits');
    if (unitsInput && units > 0 && (!unitsInput.value || parseFloat(unitsInput.value) === 0)) {
      unitsInput.max = units;
    }

    // Color banner red if no units
    banner.style.background = units > 0 ? 'rgba(234,179,8,.1)' : 'rgba(239,68,68,.08)';
    banner.style.borderColor = units > 0 ? 'rgba(234,179,8,.3)' : 'rgba(239,68,68,.25)';

  } catch(e) {
    console.warn('Available units fetch failed:', e);
  }
}

async function searchFunds(q) {
  const dropdown = document.getElementById('fundSearchDropdown');
  dropdown.innerHTML = `<div style="padding:12px;color:var(--text-muted);text-align:center;"><div class="spinner-sm"></div></div>`;
  dropdown.style.display = 'block';

  try {
    const res = await API.get(`/api/mutual_funds/mf_search.php?q=${encodeURIComponent(q)}`);
    const funds = res.data || [];

    if (!funds.length) {
      dropdown.innerHTML = `<div style="padding:12px;color:var(--text-muted);font-size:13px;">No funds found for "${q}"</div>`;
      return;
    }

    dropdown.innerHTML = funds.map(f => {
      const ltcgDays = f.min_ltcg_days || 365;
      const lockDays = f.lock_in_days  || 0;
      const ltcgYrs  = ltcgDays === 365 ? '1 yr' : ltcgDays === 730 ? '2 yr' : ltcgDays === 1095 ? '3 yr' : ltcgDays + 'd';
      const isElss   = lockDays > 0;
      const ltcgBadge = `<span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(22,163,74,.1);color:#15803d;border:1px solid rgba(22,163,74,.2);">LTCG ${ltcgYrs}</span>`;
      const lockBadge = isElss ? `<span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(234,179,8,.1);color:#b45309;border:1px solid rgba(234,179,8,.2);margin-left:4px;">🔒 Lock-in 3yr</span>` : '';
      return `
      <div class="autocomplete-item" data-fund-id="${f.id}" data-nav="${f.latest_nav||0}"
           style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);transition:background .15s;"
           onmousedown="selectFund(${f.id},'${escAttr(f.scheme_name)}',${f.latest_nav||0},${ltcgDays},${lockDays})"
           onmouseover="this.style.background='var(--bg-secondary)'"
           onmouseout="this.style.background=''">
        <div style="font-size:13px;font-weight:500;">${escHtml(f.scheme_name)}</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${escHtml(f.fund_house_short||f.fund_house||'')} · ${escHtml(f.category||'')} · Code: ${f.scheme_code}</div>
        <div style="margin-top:4px;">${ltcgBadge}${lockBadge}${f.latest_nav ? `<span style="font-size:11px;color:var(--text-muted);margin-left:8px;">NAV: ₹${Number(f.latest_nav).toFixed(4)} (${f.latest_nav_date||''})</span>` : ''}</div>
      </div>`;
    }).join('');

  } catch (err) {
    dropdown.innerHTML = `<div style="padding:12px;color:var(--danger);font-size:13px;">Search error: ${err.message}</div>`;
  }
}

function selectFund(id, name, nav, minLtcgDays, lockInDays) {
  document.getElementById('txnFundSearch').value = name;
  document.getElementById('txnFundId').value     = id;
  document.getElementById('fundSearchDropdown').style.display = 'none';
  MF.selectedFundId       = id;
  MF.selectedFundNav      = nav;
  MF.selectedFundLtcgDays = minLtcgDays || 365;
  MF.selectedFundLockDays = lockInDays  || 0;
  fetchAndShowFundInfo(id);
  updateValuePreview();
  updateAvailableUnits(); // refresh available units when fund changes
}

async function fetchAndShowFundInfo(fundId) {
  const infoEl = document.getElementById('txnFundInfo');
  try {
    const res = await API.get(`/api/mutual_funds/mf_nav_history.php?fund_id=${fundId}`);
    if (res.latest_nav) {
      MF.selectedFundNav = res.latest_nav;
      const ltcgDays = MF.selectedFundLtcgDays || 365;
      const lockDays = MF.selectedFundLockDays || 0;
      const ltcgLabel = ltcgDays === 365 ? '1 year' : ltcgDays === 730 ? '2 years' : ltcgDays === 1095 ? '3 years' : ltcgDays + ' days';
      const lockHtml  = lockDays > 0
        ? ` &nbsp;·&nbsp; <span style="color:#b45309;font-weight:600;">🔒 Lock-in: 3 years (ELSS)</span>`
        : '';
      infoEl.innerHTML = `Latest NAV: <strong>₹${Number(res.latest_nav).toFixed(4)}</strong> as of ${res.latest_nav_date||''} &nbsp;·&nbsp; <span style="color:#15803d;font-weight:600;">LTCG after ${ltcgLabel}</span>${lockHtml}`;
      document.getElementById('previewCurrentNav').textContent = `₹${Number(res.latest_nav).toFixed(4)}`;
      updateValuePreview();
    }
  } catch (_) {}
}

function updateValuePreview() {
  const units = parseFloat(document.getElementById('txnUnits')?.value) || 0;
  const nav   = parseFloat(document.getElementById('txnNav')?.value)   || 0;
  const stamp = parseFloat(document.getElementById('txnStampDuty')?.value) || 0;
  const previewEl = document.getElementById('txnValuePreview');

  if (units > 0 && nav > 0) {
    const total = (units * nav) + stamp;
    document.getElementById('previewValue').textContent = fmtInr(total);
    if (previewEl) previewEl.style.display = 'block';
  } else {
    if (previewEl) previewEl.style.display = 'none';
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   TRANSACTION DRAWER (slide-in panel)
═══════════════════════════════════════════════════════════════════════════ */
async function openTxnDrawer(fundId, fundName) {
  const overlay = document.getElementById('txnDrawerOverlay');
  const drawer  = document.getElementById('txnDrawer');
  const title   = document.getElementById('drawerTitle');
  const content = document.getElementById('drawerContent');

  title.textContent = fundName;
  content.innerHTML = '<div class="spinner" style="margin:40px auto;"></div>';
  overlay.style.display = 'block';
  drawer.style.display  = 'flex';

  try {
    const res = await API.get(`/api/mutual_funds/mf_list.php?view=transactions&fund_id=${fundId}&per_page=1000`);
    const txns = res.data || [];

    if (!txns.length) {
      content.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:40px;">No transactions found</p>';
      return;
    }

    // Store for pagination re-render
    window._drawerTxns    = txns;
    window._drawerFundId  = fundId;
    window._drawerFundName = fundName;
    window._drawerPage    = 1;
    window._drawerPerPage = 10;

    renderDrawerPage(content, fundId, fundName);
  } catch (err) {
    content.innerHTML = `<p style="color:var(--danger);">${err.message}</p>`;
  }
}

function renderDrawerPage(content, fundId, fundName) {
  const txns    = window._drawerTxns;
  const page    = window._drawerPage;
  const perPage = window._drawerPerPage;
  const total   = txns.length;
  const pages   = Math.ceil(total / perPage);
  const from    = (page - 1) * perPage;
  const pageTxns = txns.slice(from, from + perPage);

  const typeColors = { BUY:'badge-success', SELL:'badge-danger', DIV_REINVEST:'badge-info',
                       SWITCH_IN:'badge-primary', SWITCH_OUT:'badge-warning' };
  const buyTypes = ['BUY','SWITCH_IN','DIV_REINVEST'];
  const fundHolding = MF.data.find(h => h.fund_id === fundId);
  const ltcgDays = fundHolding?.min_ltcg_days || 365;

  const drawerRows = pageTxns.map(t => {
    let ltcgCell = '<td style="color:var(--text-muted);text-align:center;">—</td>';
    if (buyTypes.includes(t.transaction_type)) {
      const ltcgTs   = new Date(t.txn_date).getTime() + ltcgDays * 86400000;
      const ltcgDate = new Date(ltcgTs);
      const isElig   = ltcgTs <= Date.now();
      const dd   = String(ltcgDate.getDate()).padStart(2,'0');
      const mm   = String(ltcgDate.getMonth()+1).padStart(2,'0');
      const yyyy = ltcgDate.getFullYear();
      const lbl  = `${dd}-${mm}-${yyyy}`;
      ltcgCell = isElig
        ? `<td><span class="badge badge-success">✓ ${lbl}</span></td>`
        : `<td style="color:var(--warning);font-size:12px;">⏳ ${lbl}</td>`;
    }
    return `<tr data-txn-id="${t.id}">
      <td style="text-align:center;padding:8px 6px;">
        <input type="checkbox" class="drawer-txn-cb" data-txn-id="${t.id}"
          data-date="${escAttr(t.txn_date)}" data-type="${escAttr(t.transaction_type)}"
          onchange="onDrawerCbChange()"
          style="width:14px;height:14px;cursor:pointer;accent-color:#3b82f6;">
      </td>
      <td>${formatDateDisplay(t.txn_date)}</td>
      <td><span class="badge ${typeColors[t.transaction_type]||''}">${t.transaction_type}</span></td>
      <td>${Number(t.units).toFixed(4)}</td>
      <td>₹${Number(t.nav).toFixed(4)}</td>
      <td class="text-right">${fmtFull(t.value_at_cost)}</td>
      <td>${t.folio_number||'—'}</td>
      ${ltcgCell}
      <td style="white-space:nowrap;">
        <button class="btn btn-ghost btn-xs" onclick="editTransaction(${t.id})">✏️</button>
        <button class="btn btn-ghost btn-xs" style="color:#dc2626;" onclick="openDeleteTxnModal(${t.id},'${escAttr(t.scheme_name)}','${escAttr(t.txn_date)}','${escAttr(t.transaction_type)}')">🗑</button>
      </td>
    </tr>`;
  }).join('');

  // Pagination buttons
  let paginationHtml = '';
  if (pages > 1) {
    const btnStyle = (active) => `style="min-width:32px;height:32px;border:1px solid var(--border);border-radius:6px;background:${active ? 'var(--accent)' : 'var(--bg-surface)'};color:${active ? '#fff' : 'var(--text-primary)'};font-size:13px;font-weight:${active ? '600' : '400'};cursor:${active ? 'default' : 'pointer'};padding:0 8px;"`;

    let btns = '';
    btns += `<button ${btnStyle(false)} ${page===1?'disabled style="opacity:.4;cursor:default;"':''} onclick="goDrawerPage(${page-1})">‹</button>`;
    for (let p = 1; p <= pages; p++) {
      if (p === 1 || p === pages || (p >= page-2 && p <= page+2)) {
        btns += `<button ${btnStyle(p===page)} onclick="goDrawerPage(${p})">${p}</button>`;
      } else if (p === page-3 || p === page+3) {
        btns += `<span style="padding:0 4px;color:var(--text-muted);">…</span>`;
      }
    }
    btns += `<button ${btnStyle(false)} ${page===pages?'disabled style="opacity:.4;cursor:default;"':''} onclick="goDrawerPage(${page+1})">›</button>`;

    paginationHtml = `
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
        <span style="font-size:12px;color:var(--text-muted);">
          Showing ${from+1}–${Math.min(from+perPage,total)} of ${total} transactions
        </span>
        <div style="display:flex;gap:4px;align-items:center;">${btns}</div>
      </div>`;
  }

  content.innerHTML = `
    <!-- Top action bar -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
      <div id="drawerBulkBar" style="display:none;align-items:center;gap:8px;padding:5px 10px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:8px;">
        <span id="drawerBulkCount" style="font-size:13px;font-weight:600;color:#dc2626;"></span>
        <button onclick="openDrawerBulkDeleteModal()"
          style="background:#dc2626;color:#fff;border:none;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">
          🗑 Delete Selected
        </button>
        <button onclick="clearDrawerSelection()" style="background:none;border:1px solid var(--border);padding:4px 10px;border-radius:6px;font-size:12px;cursor:pointer;color:var(--text-muted);">
          ✕ Clear
        </button>
      </div>
      <div style="margin-left:auto;">
        <button class="btn btn-primary btn-sm" onclick="openAddTxnForFund(${fundId},'${escAttr(fundName)}')">+ Add</button>
      </div>
    </div>

    <table class="table table-hover" style="font-size:13px;">
      <thead><tr>
        <th style="text-align:center;width:32px;">
          <input type="checkbox" id="drawerSelectAll" title="Select all on this page"
            onchange="drawerToggleAll(this.checked)"
            style="width:14px;height:14px;cursor:pointer;accent-color:#3b82f6;">
        </th>
        <th>Date</th><th>Type</th><th>Units</th><th>NAV</th>
        <th class="text-right">Amount</th><th>Folio</th><th>LTCG Date</th><th></th>
      </tr></thead>
      <tbody>${drawerRows}</tbody>
    </table>
    ${paginationHtml}

    <!-- Single txn delete modal (inline) -->
    <div id="drawerDeleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1100;align-items:center;justify-content:center;">
      <div style="background:var(--bg-card);border-radius:12px;padding:24px;width:min(420px,92vw);box-shadow:0 24px 64px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 12px;font-size:16px;color:#dc2626;">🗑 Delete Transaction</h3>
        <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:12px;margin-bottom:12px;font-size:13px;">
          <div id="dtmDetail" style="font-weight:600;"></div>
        </div>
        <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">
          This action <strong>cannot be undone</strong>. Holdings will be recalculated.
        </p>
        <label style="font-size:13px;">Type <strong style="color:#dc2626;font-family:monospace;">DELETE</strong> to confirm:</label>
        <input type="text" id="dtmInput" placeholder='Type "DELETE"...'
          oninput="checkDtm()"
          onpaste="event.preventDefault();showToast('Paste / drop not allowed','error');"
          ondrop="event.preventDefault();showToast('Paste / drop not allowed','error');"
          ondragover="event.preventDefault()"
          style="width:100%;margin-top:6px;padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:monospace;font-size:14px;letter-spacing:1px;text-transform:uppercase;background:var(--bg-input,var(--bg-secondary));color:var(--text-primary);box-sizing:border-box;">
        <div id="dtmHint" style="font-size:12px;color:#16a34a;margin-top:4px;min-height:16px;"></div>
        <div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end;">
          <button onclick="closeDtm()" style="padding:7px 18px;border-radius:6px;border:1px solid var(--border);background:none;cursor:pointer;font-size:13px;color:var(--text-primary);">Cancel</button>
          <button id="dtmConfirmBtn" disabled onclick="confirmDtm()"
            style="padding:7px 18px;border-radius:6px;border:none;background:#dc2626;color:#fff;font-weight:600;font-size:13px;cursor:not-allowed;opacity:.45;transition:opacity .2s;">
            Delete
          </button>
        </div>
      </div>
    </div>

    <!-- Bulk txn delete modal (inline) -->
    <div id="drawerBulkModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1100;align-items:center;justify-content:center;">
      <div style="background:var(--bg-card);border-radius:12px;padding:24px;width:min(460px,92vw);box-shadow:0 24px 64px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 12px;font-size:16px;color:#dc2626;">🗑 Delete <span id="dbmCount"></span> Transaction(s)</h3>
        <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:10px 14px;margin-bottom:12px;max-height:150px;overflow-y:auto;">
          <ul id="dbmList" style="margin:0;padding-left:16px;font-size:13px;line-height:1.8;"></ul>
        </div>
        <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">
          Holdings will be recalculated after deletion. <strong>Cannot be undone.</strong>
        </p>
        <label style="font-size:13px;">Type <strong style="color:#dc2626;font-family:monospace;">DELETE</strong> to confirm:</label>
        <input type="text" id="dbmInput" placeholder='Type "DELETE"...'
          oninput="checkDbm()"
          onpaste="event.preventDefault();showToast('Paste / drop not allowed','error');"
          ondrop="event.preventDefault();showToast('Paste / drop not allowed','error');"
          ondragover="event.preventDefault()"
          style="width:100%;margin-top:6px;padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:monospace;font-size:14px;letter-spacing:1px;text-transform:uppercase;background:var(--bg-input,var(--bg-secondary));color:var(--text-primary);box-sizing:border-box;">
        <div id="dbmHint" style="font-size:12px;color:#16a34a;margin-top:4px;min-height:16px;"></div>
        <div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end;">
          <button onclick="closeDbm()" style="padding:7px 18px;border-radius:6px;border:1px solid var(--border);background:none;cursor:pointer;font-size:13px;color:var(--text-primary);">Cancel</button>
          <button id="dbmConfirmBtn" disabled onclick="confirmDbm()"
            style="padding:7px 18px;border-radius:6px;border:none;background:#dc2626;color:#fff;font-weight:600;font-size:13px;cursor:not-allowed;opacity:.45;transition:opacity .2s;">
            Delete All
          </button>
        </div>
      </div>
    </div>
  `;

  // Bind state for DTM / DBM
  window._dtm = { txnId: null, fundName: '' };
}

// ── Drawer checkbox helpers ───────────────────────────────────
function onDrawerCbChange() {
  const checked = document.querySelectorAll('.drawer-txn-cb:checked');
  const all     = document.querySelectorAll('.drawer-txn-cb');
  const bar     = document.getElementById('drawerBulkBar');
  const countEl = document.getElementById('drawerBulkCount');
  const selAll  = document.getElementById('drawerSelectAll');
  if (bar)     bar.style.display   = checked.length > 0 ? 'flex' : 'none';
  if (countEl) countEl.textContent = `${checked.length} selected`;
  if (selAll)  {
    selAll.indeterminate = checked.length > 0 && checked.length < all.length;
    selAll.checked = checked.length === all.length && all.length > 0;
  }
}

function drawerToggleAll(checked) {
  document.querySelectorAll('.drawer-txn-cb').forEach(cb => cb.checked = checked);
  onDrawerCbChange();
}

function clearDrawerSelection() {
  document.querySelectorAll('.drawer-txn-cb').forEach(cb => cb.checked = false);
  const selAll = document.getElementById('drawerSelectAll');
  if (selAll) { selAll.checked = false; selAll.indeterminate = false; }
  const bar = document.getElementById('drawerBulkBar');
  if (bar) bar.style.display = 'none';
}

// ── Single transaction delete (confirm word) ──────────────────
function openDeleteTxnModal(txnId, schemeName, txnDate, txnType) {
  window._dtm = { txnId, schemeName };
  const modal = document.getElementById('drawerDeleteModal');
  document.getElementById('dtmDetail').textContent = `${txnType} · ${txnDate} · ${schemeName}`;
  document.getElementById('dtmInput').value = '';
  document.getElementById('dtmHint').textContent = '';
  const btn = document.getElementById('dtmConfirmBtn');
  btn.disabled = true; btn.style.opacity = '0.45'; btn.style.cursor = 'not-allowed';
  modal.style.display = 'flex';
  setTimeout(() => document.getElementById('dtmInput')?.focus(), 100);
}

function closeDtm() {
  const m = document.getElementById('drawerDeleteModal');
  if (m) m.style.display = 'none';
}

function checkDtm() {
  const val = document.getElementById('dtmInput').value.trim().toUpperCase();
  const ok  = val === 'DELETE';
  const btn = document.getElementById('dtmConfirmBtn');
  const hint = document.getElementById('dtmHint');
  btn.disabled = !ok; btn.style.opacity = ok ? '1' : '0.45'; btn.style.cursor = ok ? 'pointer' : 'not-allowed';
  hint.textContent = ok ? '✓ Confirmed.' : '';
}

async function confirmDtm() {
  const btn = document.getElementById('dtmConfirmBtn');
  if (btn.disabled) return;
  btn.disabled = true; btn.textContent = '...';
  try {
    const csrf = document.getElementById('txnCsrf')?.value || await getCsrf();
    await API.post('/api/mutual_funds/mf_delete.php', { txn_id: window._dtm.txnId, csrf_token: csrf });
    closeDtm();
    showToast('Transaction deleted', 'success');
    // Reload drawer
    const res = await API.get(`/api/mutual_funds/mf_list.php?view=transactions&fund_id=${window._drawerFundId}&per_page=1000`);
    window._drawerTxns = res.data || [];
    window._drawerPage = 1;
    renderDrawerPage(document.getElementById('drawerContent'), window._drawerFundId, window._drawerFundName);
    await loadHoldings();
  } catch (err) {
    showToast('Delete failed: ' + err.message, 'error');
    btn.disabled = false; btn.textContent = 'Delete';
  }
}

// ── Bulk transaction delete ───────────────────────────────────
function openDrawerBulkDeleteModal() {
  const checked = document.querySelectorAll('.drawer-txn-cb:checked');
  if (!checked.length) return;
  document.getElementById('dbmCount').textContent = checked.length;
  document.getElementById('dbmInput').value = '';
  document.getElementById('dbmHint').textContent = '';
  const list = document.getElementById('dbmList');
  list.innerHTML = Array.from(checked).map(cb =>
    `<li>${escHtml(cb.dataset.type)} · ${escHtml(cb.dataset.date)}</li>`
  ).join('');
  const btn = document.getElementById('dbmConfirmBtn');
  btn.disabled = true; btn.style.opacity = '0.45'; btn.style.cursor = 'not-allowed';
  document.getElementById('drawerBulkModal').style.display = 'flex';
  setTimeout(() => document.getElementById('dbmInput')?.focus(), 100);
}

function closeDbm() {
  document.getElementById('drawerBulkModal').style.display = 'none';
}

function checkDbm() {
  const val = document.getElementById('dbmInput').value.trim().toUpperCase();
  const ok  = val === 'DELETE';
  const btn = document.getElementById('dbmConfirmBtn');
  const hint = document.getElementById('dbmHint');
  btn.disabled = !ok; btn.style.opacity = ok ? '1' : '0.45'; btn.style.cursor = ok ? 'pointer' : 'not-allowed';
  hint.textContent = ok ? '✓ Confirmed — will delete selected transactions.' : '';
}

async function confirmDbm() {
  const btn = document.getElementById('dbmConfirmBtn');
  if (btn.disabled) return;
  const txnIds = Array.from(document.querySelectorAll('.drawer-txn-cb:checked')).map(cb => parseInt(cb.dataset.txnId));
  btn.disabled = true; btn.textContent = '...';
  try {
    const csrf = document.getElementById('txnCsrf')?.value || await getCsrf();
    // Delete one by one (existing API supports single txn_id)
    for (const id of txnIds) {
      await API.post('/api/mutual_funds/mf_delete.php', { txn_id: id, csrf_token: csrf });
    }
    closeDbm();
    showToast(`${txnIds.length} transaction(s) deleted`, 'success');
    const res = await API.get(`/api/mutual_funds/mf_list.php?view=transactions&fund_id=${window._drawerFundId}&per_page=1000`);
    window._drawerTxns = res.data || [];
    window._drawerPage = 1;
    renderDrawerPage(document.getElementById('drawerContent'), window._drawerFundId, window._drawerFundName);
    await loadHoldings();
  } catch (err) {
    showToast('Bulk delete failed: ' + err.message, 'error');
    btn.disabled = false; btn.textContent = 'Delete All';
  }
}

function goDrawerPage(page) {
  const pages = Math.ceil(window._drawerTxns.length / window._drawerPerPage);
  if (page < 1 || page > pages) return;
  window._drawerPage = page;
  const content = document.getElementById('drawerContent');
  renderDrawerPage(content, window._drawerFundId, window._drawerFundName);
}

function closeTxnDrawer() {
  document.getElementById('txnDrawerOverlay').style.display = 'none';
  document.getElementById('txnDrawer').style.display = 'none';
}

/* ═══════════════════════════════════════════════════════════════════════════
   CSV IMPORT
═══════════════════════════════════════════════════════════════════════════ */
/* ── t90: CSV Import helpers ── */
const IMPORT_FORMAT_HINTS = {
  auto:        '🔍 Format auto-detected from file headers',
  wealthdash:  '📋 WealthDash custom format — Fund Name, Date, Type, Units, NAV, Amount',
  cams:        '📄 CAMS statement — download from camsonline.com → MF Portfolio → Detailed',
  kfintech:    '📄 KFintech/Karvy — download from kfintech.com → Investor → Statement',
  groww:       '📱 Groww — Mutual Funds → Portfolio → Download Statement (CSV)',
  zerodha:     '📱 Zerodha Coin — Console → Portfolio → Download as CSV',
  kuvera:      '📱 Kuvera — Profile → Download Portfolio Statement',
  mfcentral:   '📱 MFCentral — Login → Reports → Transaction Statement',
};

function onImportFormatChange() {
  const fmt   = document.getElementById('importFormat')?.value || 'auto';
  const hint  = document.getElementById('importFormatHint');
  if (!hint) return;
  const msg = IMPORT_FORMAT_HINTS[fmt] || '';
  if (msg) { hint.textContent = msg; hint.style.display = ''; }
  else      { hint.style.display = 'none'; }
}

function onImportFileChange(input) {
  const label = document.getElementById('importFileLabel');
  const text  = document.getElementById('importFileText');
  const prev  = document.getElementById('importPreviewWrap');

  if (!input.files?.length) {
    text.textContent = 'Choose CSV file…';
    text.style.color = 'var(--text-muted)';
    if (prev) prev.style.display = 'none';
    return;
  }

  const file = input.files[0];
  text.textContent      = file.name;
  text.style.color      = 'var(--text-primary)';
  text.style.fontWeight = '600';
  if (label) { label.style.borderColor = 'var(--accent)'; label.style.background = 'rgba(37,99,235,.04)'; }

  // t90: Read first 6 lines for preview
  const reader = new FileReader();
  reader.onload = e => {
    const lines  = e.target.result.split('\n').filter(l => l.trim()).slice(0, 6);
    const prevEl = document.getElementById('importPreview');
    if (!prevEl || !prev) return;

    // Detect format from header
    const fmt   = document.getElementById('importFormat');
    const first = lines[0]?.toLowerCase() || '';
    let detected = 'auto';
    if (first.includes('zerodha') || first.includes('trade_date')) detected = 'zerodha';
    else if (first.includes('kuvera'))       detected = 'kuvera';
    else if (first.includes('groww'))        detected = 'groww';
    else if (first.includes('cams'))         detected = 'cams';
    else if (first.includes('kfintech') || first.includes('karvy')) detected = 'kfintech';
    else if (first.includes('mfcentral'))    detected = 'mfcentral';
    if (fmt && detected !== 'auto') { fmt.value = detected; onImportFormatChange(); }

    // Render preview table
    const rows = lines.map(l => l.split(',').map(c => c.trim().replace(/^"|"$/g,'')));
    prevEl.innerHTML = `<table style="border-collapse:collapse;min-width:100%;">
      ${rows.map((row, i) => `<tr style="${i===0?'font-weight:800;background:rgba(99,102,241,.1);':''}">
        ${row.slice(0,6).map(c => `<td style="padding:3px 6px;border:1px solid var(--border);white-space:nowrap;max-width:120px;overflow:hidden;text-overflow:ellipsis;">${c||'—'}</td>`).join('')}
      </tr>`).join('')}
    </table>
    <div style="margin-top:4px;font-size:11px;color:var(--text-muted);">Detected: <strong>${detected}</strong> · ${lines.length-1} data rows (preview)</div>`;
    prev.style.display = '';
  };
  reader.readAsText(file);
}

async function startCsvImport() {
  const fileInput = document.getElementById('importFile');
  const portfolioId = window.WD?.selectedPortfolio || 0;
  const format = document.getElementById('importFormat').value;
  const resultEl = document.getElementById('importResult');
  const csrf = await getCsrf();

  if (!fileInput.files.length) {
    showToast('Please select a CSV file', 'error'); return;
  }

  setBtnLoading('btnStartImport', 'btnImportLabel', 'btnImportSpinner', true);
  resultEl.style.display = 'none';

  const fd = new FormData();
  fd.append('csv_file', fileInput.files[0]);
  fd.append('portfolio_id', portfolioId);
  fd.append('csv_format', format);
  fd.append('csrf_token', csrf);

  try {
    const res = await fetch(`${window.APP_URL}/api/mutual_funds/mf_import_csv.php`, {
      method: 'POST', body: fd
    });
    const data = await res.json();

    resultEl.style.display = 'block';
    if (data.success) {
      const hasSkipped = data.skipped > 0;
      resultEl.style.background = hasSkipped ? 'rgba(234,179,8,.08)' : 'rgba(34,197,94,.1)';
      resultEl.style.color = 'var(--text-primary)';

      // Simple anchor download — no base64, no JS tricks
      const dlUrl = data.download_token
        ? `${window.APP_URL}/api/mutual_funds/download_import_result.php?token=${data.download_token}`
        : null;
      const dlBtn = dlUrl
        ? `<a href="${dlUrl}" download
            style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;padding:7px 14px;
                   background:#2563eb;color:#fff;border-radius:7px;font-size:12px;font-weight:600;
                   text-decoration:none;">
            ⬇ Download Result CSV
            <span style="font-size:10px;opacity:.85;">(har row ka status)</span>
           </a>`
        : '';

      const errHtml = data.errors?.length
        ? `<div style="margin-top:8px;max-height:160px;overflow-y:auto;font-size:11px;
                       background:rgba(0,0,0,.04);border-radius:6px;padding:8px 10px;line-height:1.7;">
             ${data.errors.map(e => `<div>• ${e}</div>`).join('')}
           </div>`
        : '';

      resultEl.innerHTML = `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
          <span style="font-size:16px;">${hasSkipped ? '⚠️' : '✅'}</span>
          <strong style="font-size:13px;">${data.message}</strong>
        </div>
        <div style="font-size:12px;color:var(--text-muted);display:flex;gap:16px;flex-wrap:wrap;">
          <span>✅ Imported: <strong style="color:#16a34a;">${data.imported}</strong></span>
          <span>⏭ Skipped: <strong style="color:${hasSkipped?'#dc2626':'#6b7280'};">${data.skipped}</strong></span>
          <span>📄 Format: <strong>${data.format}</strong></span>
        </div>
        ${errHtml}
        ${dlBtn}`;

      reloadCurrentPage();
    } else {
      resultEl.style.background = 'rgba(239,68,68,.1)';
      resultEl.style.color = 'var(--danger)';
      resultEl.innerHTML = `<strong>✗ ${data.message}</strong>`;
    }
  } catch (err) {
    resultEl.style.display = 'block';
    resultEl.style.background = 'rgba(239,68,68,.1)';
    resultEl.innerHTML = `<strong>✗ Network error: ${err.message}</strong>`;
  } finally {
    setBtnLoading('btnStartImport', 'btnImportLabel', 'btnImportSpinner', false);
  }
}


function downloadImportResultCsv(base64data, filename) {
  try {
    const csv = atob(base64data);
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = filename || 'import_result.csv';
    document.body.appendChild(a); a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  } catch(e) {
    showToast('Download failed: ' + e.message, 'error');
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   EXCEL DOWNLOAD
═══════════════════════════════════════════════════════════════════════════ */
function downloadHoldingsExcel() {
  if (!MF.filtered || !MF.filtered.length) {
    showToast('No holdings to download', 'error'); return;
  }

  const headers = ['Fund Name','Fund House','Category','Folio','Invested (₹)','Current Value (₹)','Gain/Loss (₹)','Returns (%)','XIRR (%)','Units','NAV (₹)','Peak NAV (₹)','Drawdown (%)','Type','LTCG Date','First Purchase'];

  const rows = MF.filtered.map(h => [
    h.scheme_name || '',
    h.fund_house || '',
    h.category || '',
    h.folio_number || '',
    Number(h.total_invested || 0).toFixed(2),
    Number(h.value_now || 0).toFixed(2),
    Number(h.gain_loss || 0).toFixed(2),
    Number(h.gain_pct || 0).toFixed(2),
    Number(h.cagr || 0).toFixed(2),
    Number(h.total_units || 0).toFixed(4),
    Number(h.latest_nav || 0).toFixed(4),
    h.highest_nav ? Number(h.highest_nav).toFixed(4) : '',
    h.drawdown_pct !== null && h.drawdown_pct !== undefined ? Number(h.drawdown_pct).toFixed(2) : '',
    h.gain_type || '',
    h.ltcg_date ? formatDateDisplay(h.ltcg_date) : '',
    h.first_purchase_date ? formatDateDisplay(h.first_purchase_date) : ''
  ]);

  // Add totals row
  const totInv  = MF.filtered.reduce((s,h) => s + (Number(h.total_invested)||0), 0);
  const totVal  = MF.filtered.reduce((s,h) => s + (Number(h.value_now)||0), 0);
  const totGain = totVal - totInv;
  const totPct  = totInv > 0 ? ((totGain/totInv)*100).toFixed(2) : '0.00';
  rows.push(['TOTAL','','','', totInv.toFixed(2), totVal.toFixed(2), totGain.toFixed(2), totPct, '', '', '', '', '', '', '', '']);

  // Build CSV content (Excel opens CSV with UTF-8 BOM)
  const BOM = '\uFEFF';
  const csvLines = [headers, ...rows].map(row =>
    row.map(cell => {
      const s = String(cell).replace(/"/g, '""');
      return /[,\n"]/.test(s) ? `"${s}"` : s;
    }).join(',')
  );

  const blob = new Blob([BOM + csvLines.join('\n')], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  const date = new Date().toISOString().split('T')[0];
  a.href     = url;
  a.download = `MF_Holdings_${date}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  showToast(`Downloaded ${MF.filtered.length} holdings`);
}

/* ═══════════════════════════════════════════════════════════════════════════
   UTILITY (local)
═══════════════════════════════════════════════════════════════════════════ */
function reloadCurrentPage() {
  if (document.getElementById('holdingsBody')) loadHoldings();
  if (document.getElementById('txnBody'))      loadTransactions(MF.page);
}

function setViewActive(activeId) { /* view toggle removed */ }

function showError(el, msg) {
  if (!el) { showToast(msg,'error'); return; }
  el.textContent = msg;
  el.style.display = 'block';
  el.scrollIntoView({ behavior:'smooth', block:'nearest' });
}

function setBtnLoading(btnId, labelId, spinnerId, loading) {
  const btn = document.getElementById(btnId);
  const lbl = document.getElementById(labelId);
  const spn = document.getElementById(spinnerId);
  if (btn) btn.disabled = loading;
  if (lbl) lbl.style.display = loading ? 'none' : '';
  if (spn) spn.style.display = loading ? 'inline-block' : 'none';
}

async function getCsrf() {
  return document.getElementById('txnCsrf')?.value || '';
}

function formatDateDisplay(d) {
  if (!d) return '—';
  const [y,m,day] = d.split('-');
  return `${day}-${m}-${y}`;
}

function setEl(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

// fmtFull — always full Indian format for tables (no K/L/Cr, ignores toggle)
function fmtFull(n) {
  if (n === null || n === undefined || isNaN(n)) return '—';
  n = Number(n);
  const abs  = Math.abs(n);
  const sign = n < 0 ? '-' : '';
  const ic   = window.indianComma || function(x) {
    const s = Math.floor(Math.abs(x)).toString();
    if (s.length <= 3) return s;
    return s.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + s.slice(-3);
  };
  const dec = (abs % 1).toFixed(2).slice(2);
  return sign + '\u20B9' + ic(abs) + '.' + dec;
}

function fmtInr(n) {
  if (n === null || n === undefined || isNaN(n)) return '—';
  n = Number(n);
  const abs  = Math.abs(n);
  const sign = n < 0 ? '-' : '';
  const dec  = (abs % 1).toFixed(2).slice(2);
  const ic   = window.indianComma || function(x) {
    const s = Math.floor(Math.abs(x)).toString();
    if (s.length <= 3) return s;
    return s.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + s.slice(-3);
  };
  const short = (typeof window.WD_NUM_SHORT !== 'undefined') ? window.WD_NUM_SHORT : true;
  let s;
  if (short) {
    if (abs >= 1e7)       s = '₹' + (abs / 1e7).toFixed(2) + ' Cr';
    else if (abs >= 1e5)  s = '₹' + (abs / 1e5).toFixed(2) + ' L';
    else if (abs >= 1000) s = '₹' + (abs / 1000).toFixed(1) + ' K';
    else                  s = '₹' + ic(abs) + '.' + dec;
  } else {
    s = '₹' + ic(abs) + '.' + dec;
  }
  return sign + s;
}

function escHtml(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(s) {
  if (!s) return '';
  return String(s).replace(/'/g,'\\\'').replace(/"/g,'&quot;');
}

function debounce(fn, ms) {
  let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}
/* ═══════════════════════════════════════════════════════════════
   TAB SWITCHING — Holdings / Realized Gains / Dividends
═══════════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', () => {
  const tabs = document.querySelectorAll('.mf-tab');
  if (!tabs.length) return;

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      // Deactivate all
      tabs.forEach(t => {
        t.classList.remove('active');
        t.style.borderBottomColor = 'transparent';
        t.style.color = 'var(--text-secondary)';
      });
      // Activate clicked
      tab.classList.add('active');
      tab.style.borderBottomColor = 'var(--accent)';
      tab.style.color = 'var(--accent)';

      // Show/hide panels
      const which = tab.dataset.tab;
      document.getElementById('tabHoldings').style.display  = which === 'holdings'  ? '' : 'none';
      document.getElementById('tabRealized').style.display  = which === 'realized'  ? '' : 'none';
      document.getElementById('tabDividends').style.display = which === 'dividends' ? '' : 'none';
      const cgTab  = document.getElementById('tabCapgains');
      if (cgTab) cgTab.style.display = which === 'capgains' ? '' : 'none';
      const calTab = document.getElementById('tabCalendarWrap');
      if (calTab) calTab.style.display = which === 'calendar' ? '' : 'none';
      const epTab = document.getElementById('tabExitPlanner');
      if (epTab) epTab.style.display = which === 'exitplanner' ? '' : 'none';

      // Load data on first switch
      if (which === 'realized') {
        const rFy = document.getElementById('rFilterFy');
        if (rFy && !RG.loaded) rFy.value = '';
        if (!RG.loaded) loadRealizedGains();
      }
      if (which === 'dividends') {
        const dFy = document.getElementById('dFilterFy');
        if (dFy && !DIV.loaded) dFy.value = '';
        if (!DIV.loaded) loadDividends();
      }
      if (which === 'capgains') {
        if (!CG.loaded) loadCapitalGains();
      }
      // t75: Investment Calendar
      if (which === 'calendar') {
        initCalendarTab();
        if (!MF._fyDatesRendered) { renderFYDates(); MF._fyDatesRendered = true; }
      }
      // t173: Exit Strategy Planner
      if (which === 'exitplanner') {
        initExitPlanner();
      }
    });
  });

  // Realized gains filters
  ['rFilterFy','rFilterType'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => { RG.loaded = false; loadRealizedGains(); });
  });
  const rSearch = document.getElementById('rSearchFund');
  if (rSearch) rSearch.addEventListener('input', debounce(() => renderRealized(), 250));

  // Dividend filters
  ['dFilterFy'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => { DIV.loaded = false; loadDividends(); });
  });
  const dSearch = document.getElementById('dSearchFund');
  if (dSearch) dSearch.addEventListener('input', debounce(() => renderDividends(), 250));

  // t74: Capital Gains filters
  ['cgFyFilter','cgTypeFilter'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => {
      CG.loaded = false;
      const fy = document.getElementById('cgFyFilter')?.value || '';
      loadCapitalGains(fy);
    });
  });
});

/* ── Realized Gains ──────────────────────────────────────── */
const RG = { data: [], loaded: false };

async function loadRealizedGains() {
  const portfolioId = window.WD?.selectedPortfolio || 0;
  if (!portfolioId) return;
  const fyEl = document.getElementById('rFilterFy');
  const fy   = fyEl?.value || '';

  const body = document.getElementById('realizedBody');
  body.innerHTML = `<tr><td colspan="11" class="text-center" style="padding:32px;"><div class="spinner"></div><p style="color:var(--text-muted);margin-top:10px;">Loading...</p></td></tr>`;

  try {
    const fd = new FormData();
    fd.append('portfolio_id', portfolioId);
    fd.append('_csrf_token', window.WD?.csrf || window.CSRF_TOKEN || '');
    if (fy) fd.append('fy', fy);

    const res  = await fetch(`${window.WD?.appUrl || window.APP_URL || ''}/api/?action=report_fy_gains`, { method:'POST', body: fd });
    const json = await res.json();

    if (!json.success) {
      body.innerHTML = `<tr><td colspan="11" class="text-center" style="padding:32px;color:var(--text-muted);">${escHtml(json.message || 'No data found.')}</td></tr>`;
      return;
    }

    // ✅ FIX: API wraps data inside json.data — read from correct path
    RG.data   = json.data?.mf_gains_detail || [];
    RG.loaded = true;
    renderRealized();
  } catch(e) {
    body.innerHTML = `<tr><td colspan="11" class="text-center" style="padding:32px;color:var(--loss);">Error loading data.</td></tr>`;
  }
}

function renderRealized() {
  const search   = (document.getElementById('rSearchFund')?.value || '').toLowerCase();
  const typeF    = document.getElementById('rFilterType')?.value || '';
  const body     = document.getElementById('realizedBody');
  const tfoot    = document.getElementById('realizedTfoot');

  let rows = RG.data.filter(r => {
    if (typeF   && r.gain_type !== typeF)                          return false;
    if (search  && !r.name?.toLowerCase().includes(search))        return false;
    return true;
  });

  document.getElementById('rCount').textContent = `${rows.length} transaction${rows.length !== 1 ? 's' : ''}`;

  if (!rows.length) {
    body.innerHTML = `<tr><td colspan="11" class="text-center" style="padding:32px;color:var(--text-muted);">No realized gains found for the selected filters.</td></tr>`;
    tfoot.style.display = 'none';
    updateRealizedSummary([]);
    return;
  }

  // Totals
  let totProceeds=0, totCost=0, totGain=0, totLtcg=0, totStcg=0;
  rows.forEach(r => {
    totProceeds += r.proceeds || 0;
    totCost     += r.cost || 0;
    totGain     += r.gain || 0;
    if (r.gain_type === 'LTCG') totLtcg += r.gain || 0;
    if (r.gain_type === 'STCG') totStcg += r.gain || 0;
  });

  body.innerHTML = rows.map(r => {
    const gainCls = r.gain >= 0 ? 'positive' : 'negative';
    const typeCls = r.gain_type === 'LTCG' ? 'badge-ltcg' : 'badge-stcg';
    return `<tr>
      <td>
        <div style="font-weight:500;font-size:13px;">${escHtml(r.name)}</div>
        <div style="font-size:11px;color:var(--text-muted);">${escHtml(r.fund_house||'')}${r.folio ? ' · Folio: ' + escHtml(r.folio) : ''}</div>
      </td>
      <td class="text-center">${escHtml(r.sell_date||'')}</td>
      <td class="text-center">${Number(r.units||0).toFixed(4)}</td>
      <td class="text-center">₹${Number(r.sell_nav||0).toFixed(4)}</td>
      <td class="text-center">${fmtFull(r.proceeds)}</td>
      <td class="text-center">${fmtFull(r.cost)}</td>
      <td class="text-center ${gainCls}" style="font-weight:600;">${r.gain >= 0 ? '+' : ''}${fmtFull(r.gain)}</td>
      <td class="text-center">${r.days_held ?? '—'} days</td>
      <td class="text-center"><span class="badge ${typeCls}">${escHtml(r.gain_type||'')}</span></td>
      <td class="text-center">${r.tax_rate != null ? r.tax_rate + '%' : '—'}</td>
      <td class="text-center" style="font-size:12px;color:var(--text-muted);">${escHtml(r.fy||'')}</td>
    </tr>`;
  }).join('');

  document.getElementById('rFootProceeds').textContent = fmtFull(totProceeds);
  document.getElementById('rFootCost').textContent     = fmtFull(totCost);
  const gainEl = document.getElementById('rFootGain');
  gainEl.textContent  = (totGain >= 0 ? '+' : '') + fmtFull(totGain);
  gainEl.className    = 'text-center ' + (totGain >= 0 ? 'positive' : 'negative');
  tfoot.style.display = '';

  updateRealizedSummary({ totProceeds, totCost, totGain, totLtcg, totStcg });
}

function updateRealizedSummary(s) {
  if (!s || !s.totProceeds) {
    ['rSumProceeds','rSumCost','rSumGain','rSumLtcg','rSumStcg'].forEach(id => setEl(id, '—'));
    return;
  }
  setEl('rSumProceeds', fmtInr(s.totProceeds));
  setEl('rSumCost',     fmtInr(s.totCost));
  const gEl = document.getElementById('rSumGain');
  if (gEl) { gEl.textContent = (s.totGain >= 0 ? '+' : '') + fmtInr(s.totGain); gEl.className = 'stat-value ' + (s.totGain >= 0 ? 'positive' : 'negative'); }
  const ltEl = document.getElementById('rSumLtcg');
  if (ltEl) { ltEl.textContent = (s.totLtcg >= 0 ? '+' : '') + fmtInr(s.totLtcg); ltEl.className = 'stat-value ' + (s.totLtcg >= 0 ? 'positive' : 'negative'); }
  const stEl = document.getElementById('rSumStcg');
  if (stEl) { stEl.textContent = (s.totStcg >= 0 ? '+' : '') + fmtInr(s.totStcg); stEl.className = 'stat-value ' + (s.totStcg >= 0 ? 'positive' : 'negative'); }
}

/* ── Dividends ───────────────────────────────────────────── */
const DIV = { data: [], loaded: false };

async function loadDividends() {
  const portfolioId = window.WD?.selectedPortfolio || 0;
  if (!portfolioId) return;
  const fy   = document.getElementById('dFilterFy')?.value || '';
  const body = document.getElementById('dividendsBody');

  body.innerHTML = `<tr><td colspan="7" class="text-center" style="padding:32px;"><div class="spinner"></div><p style="color:var(--text-muted);margin-top:10px;">Loading...</p></td></tr>`;

  try {
    const fd = new FormData();
    fd.append('portfolio_id', portfolioId);
    fd.append('_csrf_token', window.WD?.csrf || window.CSRF_TOKEN || '');
    if (fy) fd.append('fy', fy);

    const res  = await fetch(`${window.WD?.appUrl || window.APP_URL || ''}/api/?action=report_fy_gains`, { method:'POST', body: fd });
    const json = await res.json();

    if (!json.success) {
      body.innerHTML = `<tr><td colspan="7" class="text-center" style="padding:32px;color:var(--text-muted);">${escHtml(json.message||'No data.')}</td></tr>`;
      return;
    }

    // ✅ FIX: API wraps data inside json.data — read from correct path
    DIV.data   = json.data?.mf_dividends || [];
    DIV.loaded = true;
    renderDividends();
  } catch(e) {
    body.innerHTML = `<tr><td colspan="7" class="text-center" style="padding:32px;color:var(--loss);">Error loading data.</td></tr>`;
  }
}

function renderDividends() {
  const search = (document.getElementById('dSearchFund')?.value || '').toLowerCase();
  const body   = document.getElementById('dividendsBody');
  const tfoot  = document.getElementById('dividendsTfoot');
  const fyF    = document.getElementById('dFilterFy')?.value || '';

  let rows = DIV.data.filter(d => {
    if (fyF    && d.fy !== fyF)                               return false;
    if (search && !d.name?.toLowerCase().includes(search))    return false;
    return true;
  });

  document.getElementById('dCount').textContent = `${rows.length} entr${rows.length !== 1 ? 'ies' : 'y'}`;

  if (!rows.length) {
    body.innerHTML = `<tr><td colspan="7" class="text-center" style="padding:32px;color:var(--text-muted);">No dividend records found.</td></tr>`;
    tfoot.style.display = 'none';
    setEl('dSumTotal', '—'); setEl('dSumThisFy', '—'); setEl('dSumCount', '0');
    return;
  }

  let total = 0;
  const curFyOpt = document.getElementById('dFilterFy');
  const curFy = curFyOpt && curFyOpt.options.length > 1 ? curFyOpt.options[1].value : '';
  let thisFyTotal = 0;

  body.innerHTML = rows.map(d => {
    total += d.amount || 0;
    if (d.fy === curFy) thisFyTotal += d.amount || 0;
    return `<tr>
      <td>
        <div style="font-weight:500;font-size:13px;">${escHtml(d.name)}</div>
        <div style="font-size:11px;color:var(--text-muted);">${escHtml(d.fund_house||'')}</div>
      </td>
      <td class="text-center">${escHtml(d.date||'')}</td>
      <td class="text-center" colspan="2" style="color:var(--text-muted);font-size:12px;">— (not tracked separately)</td>
      <td class="text-center" style="font-weight:600;color:var(--gain);">${fmtFull(d.amount)}</td>
      <td class="text-center"><span class="badge badge-ltcg">Payout</span></td>
      <td class="text-center" style="font-size:12px;color:var(--text-muted);">${escHtml(d.fy||'')}</td>
    </tr>`;
  }).join('');

  document.getElementById('dFootTotal').textContent = fmtFull(total);
  tfoot.style.display = '';

  setEl('dSumTotal',  fmtInr(total));
  setEl('dSumThisFy', thisFyTotal > 0 ? fmtInr(thisFyTotal) : '—');
  setEl('dSumCount',  String(rows.length));
}
/* ═══════════════════════════════════════════════════════════════════════════
   t10 — STOP SIP/SWP: badge dropdown + confirmation modal
═══════════════════════════════════════════════════════════════════════════ */

// Close all open sip menus
function _closeAllSipMenus() {
  document.querySelectorAll('.sip-stop-menu').forEach(m => m.style.display = 'none');
}

// Toggle the dropdown for a specific badge
function toggleSipMenu(e, menuId) {
  e.stopPropagation();
  const menu = document.getElementById(menuId);
  if (!menu) return;
  const isOpen = menu.style.display !== 'none';
  _closeAllSipMenus();
  if (!isOpen) {
    menu.style.display = 'block';
    // Flip upward if near bottom of viewport
    const rect = menu.getBoundingClientRect();
    if (rect.bottom > window.innerHeight - 20) {
      menu.style.top  = 'auto';
      menu.style.bottom = 'calc(100% + 4px)';
    }
  }
}

// Close on outside click
document.addEventListener('click', _closeAllSipMenus);

// Show confirm modal then call sip_stop API
function confirmStopSip(sipId, type, fundName) {
  _closeAllSipMenus();
  if (!sipId) { alert('SIP ID nahi mila. Page reload karo.'); return; }

  // Build modal
  const existing = document.getElementById('stopSipModal');
  if (existing) existing.remove();

  const modal = document.createElement('div');
  modal.id = 'stopSipModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;';
  modal.innerHTML = `
    <div style="background:#fff;border-radius:14px;padding:24px 28px;max-width:400px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
      <div style="font-size:22px;margin-bottom:8px;text-align:center;">⏹️</div>
      <h3 style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:6px;text-align:center;">Stop ${escHtml(type)}?</h3>
      <p style="font-size:13px;color:#64748b;margin-bottom:18px;text-align:center;line-height:1.6;">
        <strong>${escHtml(fundName)}</strong> ka ${type} band ho jaayega.<br>
        Existing holdings <em>aur transactions</em> safe rahenge.
      </p>
      <div style="margin-bottom:16px;">
        <label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:5px;">End Date</label>
        <input type="date" id="stopSipDate" value="${new Date().toISOString().slice(0,10)}"
          style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;outline:none;"
          onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#e2e8f0'">
      </div>
      <div style="display:flex;gap:10px;">
        <button onclick="document.getElementById('stopSipModal').remove()"
          style="flex:1;padding:10px;border-radius:8px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:13px;font-weight:700;cursor:pointer;">
          Cancel
        </button>
        <button id="stopSipConfirmBtn" onclick="doStopSip(${sipId},'${escAttr(type)}')"
          style="flex:1;padding:10px;border-radius:8px;border:none;background:#dc2626;color:#fff;font-size:13px;font-weight:700;cursor:pointer;">
          ⏹ Stop ${escHtml(type)}
        </button>
      </div>
    </div>`;
  document.body.appendChild(modal);
  // Close on backdrop click
  modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
}

async function doStopSip(sipId, type) {
  const btn      = document.getElementById('stopSipConfirmBtn');
  const endDate  = document.getElementById('stopSipDate')?.value || new Date().toISOString().slice(0,10);
  const appUrl   = window.WD?.appUrl   || '';
  const csrf     = window.WD?.csrfToken || '';
  const portfolio = window.WD?.selectedPortfolio || 0;

  if (btn) { btn.disabled = true; btn.textContent = 'Stopping...'; }

  try {
    const res  = await fetch(appUrl + '/api/router.php', {
      method : 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body   : JSON.stringify({ action: 'sip_stop', sip_id: sipId, end_date: endDate, portfolio_id: portfolio, csrf_token: csrf }),
    });
    const json = await res.json();
    if (json.success) {
      document.getElementById('stopSipModal')?.remove();
      if (typeof showToast === 'function') showToast(`✅ ${type} stopped successfully!`, 'success');
      // Refresh holdings table
      if (typeof loadHoldings === 'function') loadHoldings();
      else if (typeof MF !== 'undefined' && typeof MF.load === 'function') MF.load();
      else window.location.reload();
    } else {
      throw new Error(json.message || 'Stop failed');
    }
  } catch(err) {
    if (btn) { btn.disabled = false; btn.textContent = `⏹ Stop ${type}`; }
    alert('Error: ' + (err.message || 'Could not stop. Try again.'));
  }
}

// ── Quick SIP from Holdings ─────────────────────────────────────────
let _sipHoldingsFunds = null;
let _sipSearchTimer   = null;

async function _loadSipHoldingsFunds() {
  if (_sipHoldingsFunds) return _sipHoldingsFunds;
  try {
    const appUrl = window.WD?.appUrl || window.APP_URL || '';
    const res = await fetch(appUrl + '/api/mutual_funds/mf_list.php?view=holdings');
    const d   = await res.json();
    _sipHoldingsFunds = (d.data || []).map(h => ({
      id: h.fund_id, name: h.scheme_name,
      house: h.fund_house_short || h.fund_house || '',
      category: h.category || '', nav: h.latest_nav || 0,
      inPortfolio: true,
    }));
  } catch(e) { _sipHoldingsFunds = []; }
  return _sipHoldingsFunds;
}

function openQuickSip(fundId, fundName, nav, fundHouse, category) {
  const existing = document.getElementById('quickSipModal');
  if (existing) existing.remove();

  const today      = new Date();
  const defaultDay = today.getDate() <= 28 ? today.getDate() : 1;
  const isFundKnown = fundId > 0;

  const modal = document.createElement('div');
  modal.id = 'quickSipModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
  modal.innerHTML = `
  <div style="background:#fff;border-radius:12px;padding:24px;width:480px;max-width:95vw;
              box-shadow:0 20px 60px rgba(0,0,0,.2);max-height:92vh;overflow-y:auto;">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 style="font-size:1rem;font-weight:700;color:#1e293b;">＋ Add SIP / SWP</h3>
      <button id="qsCloseBtn" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;line-height:1;">×</button>
    </div>

    <div style="margin-bottom:14px;position:relative;">
      <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Fund *</label>
      <input id="qsFundSearch" type="text" autocomplete="off"
             placeholder="Holdings se select karo ya search karo…"
             value="${escHtml(fundName)}"
             style="width:100%;padding:9px 12px;border:1px solid ${isFundKnown?'#86efac':'#e2e8f0'};
                    border-radius:8px;font-size:13px;outline:none;box-sizing:border-box;
                    background:${isFundKnown?'#f0fdf4':'#fff'};">
      <input type="hidden" id="qsFundId" value="${fundId||''}">
      <div id="qsFundInfo" style="font-size:11px;color:#64748b;margin-top:3px;min-height:15px;">
        ${isFundKnown ? escHtml(fundHouse)+' · '+escHtml(category)+(nav?' · NAV: ₹'+Number(nav).toFixed(4):'') : ''}
      </div>
      <div id="qsFundDropdown" style="display:none;position:absolute;left:0;right:0;top:100%;
           background:#fff;border:1px solid #e2e8f0;border-radius:8px;
           box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:260px;overflow-y:auto;
           z-index:9999;margin-top:2px;"></div>
    </div>

    <div style="margin-bottom:14px;">
      <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:6px;">Type</label>
      <div style="display:flex;gap:8px;">
        <div id="qsBtnSIP" onclick="_qsSetType('SIP')"
             style="flex:1;padding:8px;text-align:center;border:2px solid #16a34a;border-radius:8px;
                    background:#f0fdf4;color:#16a34a;font-weight:700;font-size:13px;cursor:pointer;">
          🔄 SIP
        </div>
        <div id="qsBtnSWP" onclick="_qsSetType('SWP')"
             style="flex:1;padding:8px;text-align:center;border:2px solid #e2e8f0;border-radius:8px;
                    background:#fff;color:#94a3b8;font-weight:700;font-size:13px;cursor:pointer;">
          💸 SWP
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
      <div>
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Amount (₹) *</label>
        <input id="qsAmount" type="number" min="100" step="100" value="5000"
               style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:14px;outline:none;box-sizing:border-box;">
      </div>
      <div>
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Frequency</label>
        <select id="qsFrequency" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;background:#fff;outline:none;">
          <option value="daily">Daily</option>
          <option value="weekly">Weekly (7 days)</option>
          <option value="fortnightly">Fortnightly (15 days)</option>
          <option value="monthly" selected>Monthly</option>
          <option value="quarterly">Quarterly</option>
          <option value="yearly">Yearly</option>
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
      <div>
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">SIP Day (1–28)</label>
        <input id="qsSipDay" type="number" min="1" max="28" value="${defaultDay}"
               style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:14px;outline:none;box-sizing:border-box;">
      </div>
      <div>
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Platform</label>
        <input id="qsPlatform" type="text" placeholder="Groww, Zerodha…"
               style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:14px;outline:none;box-sizing:border-box;">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
      <div>
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Start Date *</label>
        <input id="qsStartDate" type="text" placeholder="DD-MM-YYYY"
               value="${String(defaultDay).padStart(2,'0')}-${String(today.getMonth()+1).padStart(2,'0')}-${today.getFullYear()}"
               style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:14px;outline:none;box-sizing:border-box;">
      </div>
      <div>
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">
          End Date <small style="color:#94a3b8;">(blank=ongoing)</small>
        </label>
        <input id="qsEndDate" type="text" placeholder="DD-MM-YYYY"
               style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:14px;outline:none;box-sizing:border-box;">
      </div>
    </div>

    <div id="qsError"   style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#dc2626;padding:8px 12px;border-radius:6px;font-size:13px;margin-bottom:12px;"></div>
    <div id="qsSuccess" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:8px 12px;border-radius:6px;font-size:13px;margin-bottom:12px;"></div>

    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button id="qsCancelBtn" style="padding:8px 18px;border:1px solid #e2e8f0;background:#fff;border-radius:6px;cursor:pointer;font-size:14px;">Cancel</button>
      <button id="qsSaveBtn"   style="padding:8px 20px;background:#16a34a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;">Save SIP</button>
    </div>
  </div>`;

  document.body.appendChild(modal);

  _initQsFundSearch(isFundKnown);
  document.getElementById('qsCloseBtn').onclick  = () => modal.remove();
  document.getElementById('qsCancelBtn').onclick = () => modal.remove();
  document.getElementById('qsSaveBtn').onclick   = _saveQuickSip;
  modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });

  if (!isFundKnown) {
    setTimeout(() => {
      document.getElementById('qsFundSearch')?.focus();
      _showQsHoldings('');
    }, 150);
  }
}

let _qsCurrentType = 'SIP';
function _qsSetType(type) {
  _qsCurrentType = type;
  const isSip = type === 'SIP';
  const sipBtn = document.getElementById('qsBtnSIP');
  const swpBtn = document.getElementById('qsBtnSWP');
  if (sipBtn) { sipBtn.style.borderColor = isSip ? '#16a34a' : '#e2e8f0'; sipBtn.style.background = isSip ? '#f0fdf4' : '#fff'; sipBtn.style.color = isSip ? '#16a34a' : '#94a3b8'; }
  if (swpBtn) { swpBtn.style.borderColor = !isSip ? '#dc2626' : '#e2e8f0'; swpBtn.style.background = !isSip ? '#fef2f2' : '#fff'; swpBtn.style.color = !isSip ? '#dc2626' : '#94a3b8'; }
  const btn = document.getElementById('qsSaveBtn');
  if (btn) { btn.textContent = `Save ${type}`; btn.style.background = isSip ? '#16a34a' : '#dc2626'; }
}

function _initQsFundSearch(isFundKnown) {
  const input    = document.getElementById('qsFundSearch');
  const dropdown = document.getElementById('qsFundDropdown');
  if (!input) return;

  input.addEventListener('focus', () => {
    if (!document.getElementById('qsFundId')?.value) _showQsHoldings(input.value.trim());
  });
  input.addEventListener('input', () => {
    clearTimeout(_sipSearchTimer);
    const fidEl = document.getElementById('qsFundId');
    if (fidEl) fidEl.value = '';
    const infoEl = document.getElementById('qsFundInfo');
    if (infoEl) infoEl.textContent = '';
    input.style.borderColor = '#e2e8f0';
    input.style.background  = '#fff';
    const q = input.value.trim();
    _showQsHoldings(q);
    if (q.length >= 2) _sipSearchTimer = setTimeout(() => _searchQsFunds(q), 400);
  });
  input.addEventListener('blur', () => {
    setTimeout(() => { if (dropdown) dropdown.style.display = 'none'; }, 200);
  });
}

async function _showQsHoldings(query) {
  const dropdown = document.getElementById('qsFundDropdown');
  if (!dropdown) return;
  const funds = await _loadSipHoldingsFunds();
  const q = (query||'').toLowerCase();
  const filtered = q ? funds.filter(f => f.name.toLowerCase().includes(q) || f.house.toLowerCase().includes(q)) : funds;

  let html = '';
  if (filtered.length) {
    html += `<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">📊 Your Holdings (${filtered.length})</div>`;
    html += filtered.map(f => _qsFundItem(f)).join('');
  }
  html += `<div style="padding:7px 12px;font-size:11px;color:#94a3b8;border-top:1px solid #f1f5f9;text-align:center;">Type to search all 14,000+ funds</div>`;
  dropdown.innerHTML = html;
  dropdown.style.display = 'block';
}

async function _searchQsFunds(q) {
  const dropdown = document.getElementById('qsFundDropdown');
  if (!dropdown) return;
  const appUrl   = window.WD?.appUrl || window.APP_URL || '';
  try {
    const res      = await fetch(`${appUrl}/api/mutual_funds/mf_search.php?q=${encodeURIComponent(q)}&limit=8`);
    const d        = await res.json();
    const allFunds = d.data || [];
    const holdings = await _loadSipHoldingsFunds();
    const hIds     = new Set(holdings.map(h => h.id));
    const hFiltered= holdings.filter(f => f.name.toLowerCase().includes(q.toLowerCase()));
    const others   = allFunds.filter(f => !hIds.has(f.id)).map(f => ({
      id: f.id, name: f.scheme_name,
      house: f.fund_house_short || f.fund_house || '',
      category: f.category || '', nav: f.latest_nav || 0, inPortfolio: false,
    }));

    let html = '';
    if (hFiltered.length) {
      html += `<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">📊 Your Holdings</div>`;
      html += hFiltered.map(f => _qsFundItem(f)).join('');
    }
    if (others.length) {
      html += `<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;background:#f8fafc;border-bottom:1px solid #e2e8f0;${hFiltered.length?'border-top:2px solid #e2e8f0;':''}">🔍 All Funds</div>`;
      html += others.map(f => _qsFundItem(f)).join('');
    }
    if (!html) html = `<div style="padding:12px;text-align:center;color:#94a3b8;font-size:13px;">No funds found for "${escHtml(q)}"</div>`;
    dropdown.innerHTML = html;
    dropdown.style.display = 'block';
  } catch(e) {}
}

function _qsFundItem(f) {
  const badge = f.inPortfolio
    ? `<span style="background:#dbeafe;color:#1d4ed8;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:700;margin-left:4px;">In Portfolio</span>`
    : '';
  return `<div onmousedown="_selectQsFund(${f.id},'${escAttr(f.name)}',${f.nav||0},'${escAttr(f.house)}','${escAttr(f.category)}')"
               onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''"
               style="padding:9px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;transition:background .1s;">
    <div style="font-size:13px;font-weight:500;color:#1e293b;">${escHtml(f.name)}${badge}</div>
    <div style="font-size:11px;color:#94a3b8;margin-top:1px;">${escHtml(f.house)} · ${escHtml(f.category)}${f.nav?' · ₹'+Number(f.nav).toFixed(4):''}</div>
  </div>`;
}

function _selectQsFund(id, name, nav, house, category) {
  const input    = document.getElementById('qsFundSearch');
  const dropdown = document.getElementById('qsFundDropdown');
  if (input) {
    input.value = name;
    input.style.borderColor = '#86efac';
    input.style.background  = '#f0fdf4';
  }
  const fidEl = document.getElementById('qsFundId');
  if (fidEl) fidEl.value = id;
  const infoEl = document.getElementById('qsFundInfo');
  if (infoEl) infoEl.textContent = house + ' · ' + category + (nav ? ' · NAV: ₹' + Number(nav).toFixed(4) : '');
  if (dropdown) dropdown.style.display = 'none';
}

async function _saveQuickSip() {
  const errEl    = document.getElementById('qsError');
  const sucEl    = document.getElementById('qsSuccess');
  const btn      = document.getElementById('qsSaveBtn');
  const appUrl   = window.WD?.appUrl || window.APP_URL || '';
  const csrf     = document.querySelector('meta[name="csrf-token"]')?.content || window.CSRF_TOKEN || '';
  const portfolio= window.WD?.selectedPortfolio || 0;

  errEl.style.display = 'none';
  sucEl.style.display = 'none';

  const fundId    = document.getElementById('qsFundId')?.value;
  const fundName  = document.getElementById('qsFundSearch')?.value;
  const amount    = document.getElementById('qsAmount')?.value;
  const startDate = document.getElementById('qsStartDate')?.value;
  const frequency = document.getElementById('qsFrequency')?.value;
  const sipDay    = document.getElementById('qsSipDay')?.value;
  const endDate   = document.getElementById('qsEndDate')?.value;
  const platform  = document.getElementById('qsPlatform')?.value;

  if (!fundId) { errEl.textContent = 'Fund select karo.'; errEl.style.display = 'block'; return; }
  if (!amount || !startDate) { errEl.textContent = 'Amount aur Start Date required hain.'; errEl.style.display = 'block'; return; }
  if (!portfolio) { errEl.textContent = 'Portfolio select nahi hai. Dashboard pe jao pehle.'; errEl.style.display = 'block'; return; }

  btn.disabled = true;
  btn.textContent = 'Saving...';

  try {
    const res  = await fetch(appUrl + '/api/router.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        action: 'sip_add', portfolio_id: portfolio,
        fund_id: fundId, sip_amount: amount, frequency,
        sip_day: sipDay, start_date: startDate, end_date: endDate,
        platform, folio_number: '', notes: _qsCurrentType === 'SWP' ? 'SWP' : '',
        schedule_type: _qsCurrentType,
        csrf_token: csrf,
      }),
    });
    const json = await res.json();
    if (json.success) {
      const sipId     = json.data?.id || 0;
      const sipPageUrl = (window.WD?.appUrl || '') + '/templates/pages/report_sip.php';
      sucEl.innerHTML = `✓ ${_qsCurrentType} saved! Past transactions generate ho rahi hain...`;
      sucEl.style.display = 'block';
      btn.textContent = '⏳ Syncing transactions...';
      btn.style.background = '#0284c7';
      _sipHoldingsFunds = null;

      // Auto-trigger sync after save
      if (sipId) {
        try {
          const portfolio = window.WD?.selectedPortfolio || 0;
          const syncRes = await fetch(appUrl + '/api/router.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'sip_sync_txns', sip_id: sipId, portfolio_id: portfolio, csrf_token: csrf }),
          });
          const syncJson = await syncRes.json();
          const txnCount = syncJson.data?.txns_generated || 0;
          sucEl.innerHTML = `✓ ${_qsCurrentType} saved for <strong>${escHtml(fundName)}</strong>! ${txnCount} past transactions generated.
            &nbsp;<a href="${sipPageUrl}" style="color:#15803d;font-weight:700;text-decoration:underline;">View SIPs →</a>`;
        } catch(syncErr) {
          sucEl.innerHTML = `✓ ${_qsCurrentType} saved. <a href="${sipPageUrl}" style="color:#15803d;">View SIPs →</a>`;
        }
      }

      btn.textContent = `✓ ${_qsCurrentType} Saved!`;
      btn.style.background = '#15803d';
      setTimeout(() => {
        document.getElementById('quickSipModal')?.remove();
        window.location.href = sipPageUrl;
      }, 2000);
    } else {
      throw new Error(json.message || 'Save failed');
    }
  } catch(e) {
    errEl.textContent = '✗ ' + (e.message || 'Error saving');
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.textContent = `Save ${_qsCurrentType}`;
  }
}
/* ═══════════════════════════════════════════════════════════════════════════
   t71 + t73 — MF ANALYTICS: Asset Allocation Donut + Portfolio XIRR
═══════════════════════════════════════════════════════════════════════════ */

let _allocChartInst = null;
let _allocMode      = 'value'; // 'value' | 'invested'

// Broad category mapping (same logic as screener)
function _broadCat(cat) {
  if (!cat) return 'Other';
  const c = cat.toLowerCase();
  if (c.includes('elss') || c.includes('tax sav')) return 'ELSS';
  if (c.includes('liquid') || c.includes('overnight') || c.includes('money market')) return 'Liquid/Cash';
  if (c.includes('debt') || c.includes('gilt') || c.includes('credit') ||
      c.includes('duration') || c.includes('floater') || c.includes('corporate bond') ||
      c.includes('banking and psu')) return 'Debt';
  if (c.includes('gold') || c.includes('silver') || c.includes('commodit')) return 'Commodity';
  if (c.includes('fund of fund') || c.includes('overseas') || c.includes('international') ||
      c.includes('fof')) return 'Intl/FoF';
  if (c.includes('hybrid') || c.includes('arbitrage') || c.includes('balanced') ||
      c.includes('multi asset')) return 'Hybrid';
  if (c.includes('index') || c.includes('etf') || c.includes('nifty') ||
      c.includes('sensex') || c.includes('passiv')) return 'Index/ETF';
  if (c.includes('equity') || c.includes('large cap') || c.includes('mid cap') ||
      c.includes('small cap') || c.includes('flexi') || c.includes('multi cap') ||
      c.includes('thematic') || c.includes('sectoral') || c.includes('focused') ||
      c.includes('value') || c.includes('contra') || c.includes('dividend yield')) return 'Equity';
  return 'Other';
}

const _allocColors = {
  'Equity':     { bg: '#3b82f6', light: 'rgba(59,130,246,.12)' },
  'Index/ETF':  { bg: '#06b6d4', light: 'rgba(6,182,212,.12)'  },
  'Hybrid':     { bg: '#8b5cf6', light: 'rgba(139,92,246,.12)' },
  'Debt':       { bg: '#f59e0b', light: 'rgba(245,158,11,.12)' },
  'ELSS':       { bg: '#10b981', light: 'rgba(16,185,129,.12)' },
  'Liquid/Cash':{ bg: '#6b7280', light: 'rgba(107,114,128,.12)'},
  'Commodity':  { bg: '#f97316', light: 'rgba(249,115,22,.12)' },
  'Intl/FoF':   { bg: '#ec4899', light: 'rgba(236,72,153,.12)' },
  'Other':      { bg: '#94a3b8', light: 'rgba(148,163,184,.12)'},
};

function renderMfAnalytics() {
  const section = document.getElementById('mfAnalyticsSection');
  if (!section) return;
  const holdings = MF.data || [];
  if (!holdings.length) { section.style.display = 'none'; return; }
  section.style.display = '';

  renderAllocChart(_allocMode);
  renderPortfolioReturns();
  renderFolioAlert(holdings);     // t172
  renderRebalancingAlert(holdings); // t175
}

function renderAllocChart(mode) {
  _allocMode = mode;

  // Update toggle button styles
  document.getElementById('allocByValue')?.classList.toggle('active', mode === 'value');
  document.getElementById('allocByInvest')?.classList.toggle('active', mode === 'invested');

  const holdings = MF.data || [];
  const key = mode === 'value' ? 'value_now' : 'total_invested';

  // Group by broad category
  const groups = {};
  let grandTotal = 0;
  holdings.forEach(h => {
    const cat = _broadCat(h.category || '');
    const val = parseFloat(h[key]) || 0;
    groups[cat] = (groups[cat] || 0) + val;
    grandTotal += val;
  });

  // Sort by value desc
  const sorted = Object.entries(groups)
    .filter(([,v]) => v > 0)
    .sort((a, b) => b[1] - a[1]);

  if (!sorted.length) return;

  const labels  = sorted.map(([k]) => k);
  const values  = sorted.map(([,v]) => v);
  const colors  = labels.map(l => (_allocColors[l] || _allocColors['Other']).bg);
  const pcts    = values.map(v => grandTotal > 0 ? (v / grandTotal * 100).toFixed(1) : '0');

  // Destroy previous chart
  if (_allocChartInst) { _allocChartInst.destroy(); _allocChartInst = null; }

  const canvas = document.getElementById('allocChartCanvas');
  if (!canvas) return;

  _allocChartInst = new Chart(canvas.getContext('2d'), {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: colors,
        borderWidth: 2,
        borderColor: 'var(--bg-card, #fff)',
        hoverBorderWidth: 0,
        hoverOffset: 6,
      }]
    },
    options: {
      responsive: false,
      cutout: '68%',
      animation: { duration: 400 },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => {
              const pct = grandTotal > 0 ? (ctx.raw / grandTotal * 100).toFixed(1) : '0';
              return `${ctx.label}: ${pct}% (${fmtFull(ctx.raw)})`;
            }
          }
        }
      },
      onHover: (evt, elements) => {
        const ctrPct   = document.getElementById('allocCenterPct');
        const ctrLabel = document.getElementById('allocCenterLabel');
        if (elements.length && ctrPct && ctrLabel) {
          const idx = elements[0].index;
          ctrPct.textContent   = pcts[idx] + '%';
          ctrPct.style.color   = colors[idx];
          ctrLabel.textContent = labels[idx];
        } else if (ctrPct) {
          ctrPct.textContent   = '—';
          ctrPct.style.color   = 'var(--text-primary)';
          if (ctrLabel) ctrLabel.textContent = 'Hover a segment';
        }
      }
    }
  });

  // Render legend
  const legend = document.getElementById('allocLegend');
  if (legend) {
    legend.innerHTML = sorted.map(([cat, val], i) => `
      <div style="display:flex;align-items:center;gap:8px;padding:4px 6px;border-radius:6px;cursor:default;transition:background .1s;"
           onmouseover="this.style.background='var(--bg-secondary)'"
           onmouseout="this.style.background=''">
        <div style="width:10px;height:10px;border-radius:50%;background:${colors[i]};flex-shrink:0;"></div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:12px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${cat}</div>
          <div style="font-size:11px;color:var(--text-muted);">${fmtFull(val)}</div>
        </div>
        <div style="font-size:12px;font-weight:700;color:${colors[i]};">${pcts[i]}%</div>
      </div>`).join('');
  }
}

function renderPortfolioReturns() {
  const holdings = MF.data || [];
  if (!holdings.length) return;

  // ── Weighted average XIRR ──────────────────────────────────────
  // Portfolio XIRR = weighted avg of individual fund XIRRs (by invested amount)
  let totalInvested = 0, weightedXirr = 0, xirrFunds = 0;
  let totalValue    = 0;
  let earliestDate  = null;

  holdings.forEach(h => {
    const inv = parseFloat(h.total_invested) || 0;
    const val = parseFloat(h.value_now)      || 0;
    totalInvested += inv;
    totalValue    += val;

    if (h.cagr !== null && h.cagr !== undefined && inv > 0) {
      weightedXirr += h.cagr * inv;
      xirrFunds++;
    }

    if (h.first_purchase_date) {
      const d = new Date(h.first_purchase_date);
      if (!earliestDate || d < earliestDate) earliestDate = d;
    }
  });

  // Portfolio XIRR (weighted by invested amount)
  const portXirr = totalInvested > 0 && xirrFunds > 0
    ? weightedXirr / totalInvested
    : null;

  // Simple CAGR: (current/invested)^(1/years) - 1
  let portCagr = null;
  if (earliestDate && totalInvested > 0 && totalValue > 0) {
    const years = (Date.now() - earliestDate.getTime()) / (365.25 * 24 * 3600 * 1000);
    if (years > 0.1) {
      portCagr = (Math.pow(totalValue / totalInvested, 1 / years) - 1) * 100;
    }
  }

  // Display portfolio-level numbers
  function fmtRet(v) {
    if (v === null || v === undefined) return '<span style="color:var(--text-muted);">—</span>';
    const color = v >= 15 ? '#15803d' : v >= 10 ? '#16a34a' : v >= 0 ? '#d97706' : '#dc2626';
    const sign  = v > 0 ? '+' : '';
    return `<span style="color:${color};">${sign}${v.toFixed(2)}%</span>`;
  }

  const xirrEl = document.getElementById('portfolioXirr');
  const cagrEl = document.getElementById('portfolioCagr');
  if (xirrEl) xirrEl.innerHTML = fmtRet(portXirr);
  if (cagrEl) cagrEl.innerHTML = fmtRet(portCagr);

  // ── Per-fund XIRR list ─────────────────────────────────────────
  const listEl = document.getElementById('fundXirrList');
  if (!listEl) return;

  // Sort by XIRR desc, show top funds
  const withXirr = holdings
    .filter(h => h.cagr !== null && h.cagr !== undefined)
    .sort((a, b) => (b.cagr || 0) - (a.cagr || 0));

  if (!withXirr.length) {
    listEl.innerHTML = '<div style="font-size:12px;color:var(--text-muted);text-align:center;padding:12px;">No XIRR data available. Add transactions to calculate returns.</div>';
    return;
  }

  listEl.innerHTML = withXirr.map(h => {
    const xirr  = h.cagr;
    const color = xirr >= 15 ? '#15803d' : xirr >= 10 ? '#16a34a' : xirr >= 0 ? '#d97706' : '#dc2626';
    const sign  = xirr > 0 ? '+' : '';
    const barW  = Math.min(100, Math.abs(xirr) * 3); // scale: 33% = 100% bar
    const name  = h.scheme_name || h.fund_name || '—';
    const shortName = name.length > 36 ? name.slice(0, 35) + '…' : name;
    return `
      <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid var(--border-color,#e2e8f0);">
        <div style="flex:1;min-width:0;">
          <div style="font-size:11px;font-weight:500;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escHtml(name)}">${escHtml(shortName)}</div>
          <div style="height:3px;background:var(--bg-secondary,#f1f5f9);border-radius:99px;margin-top:3px;">
            <div style="height:100%;width:${barW}%;background:${color};border-radius:99px;transition:width .4s;"></div>
          </div>
        </div>
        <div style="font-size:12px;font-weight:700;color:${color};min-width:52px;text-align:right;">${sign}${xirr.toFixed(2)}%</div>
      </div>`;
  }).join('');
}

/* ═══════════════════════════════════════════════════════════════════════════
   t143 — ZOOM OUT TOGGLE (hide daily noise, long-term focus)
═══════════════════════════════════════════════════════════════════════════ */
let _zoomOutActive = false;

function toggleZoomOut() {
  _zoomOutActive = !_zoomOutActive;
  const btn = document.getElementById('btnZoomOut');

  // Toggle visibility of "noisy" columns: 1D Change, NAV date, Drawdown
  document.querySelectorAll('.col-1d, td[data-col="1d"], th[data-col="1d"]').forEach(el => {
    el.style.display = _zoomOutActive ? 'none' : '';
  });
  // Hide gain% in red/green — only show abs return %
  document.querySelectorAll('.gain-pct-cell').forEach(el => {
    el.style.opacity = _zoomOutActive ? '0' : '1';
  });
  // Holdings table — hide 1D col (col index 4 in table = 1D Change)
  document.querySelectorAll('#txnTable th:nth-child(4), #txnTable td:nth-child(4)').forEach(el => {
    el.style.display = _zoomOutActive ? 'none' : '';
  });

  // Show motivational message in page subtitle
  const sub = document.querySelector('.page-subtitle');
  if (sub) {
    sub.textContent = _zoomOutActive
      ? '🌱 Zoom Out — Focus on your journey, not daily noise'
      : 'Mutual fund holdings & transactions';
  }

  if (btn) {
    btn.style.background = _zoomOutActive ? 'rgba(22,163,74,.1)' : '';
    btn.style.color      = _zoomOutActive ? '#16a34a' : '';
    btn.style.borderColor= _zoomOutActive ? '#86efac' : '';
    btn.innerHTML        = _zoomOutActive
      ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/><line x1="11" y1="8" x2="11" y2="14"/></svg> Zoom In'
      : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg> Zoom Out';
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   t140 — PANIC MODE (market crash mein units dikhao, losses mat)
═══════════════════════════════════════════════════════════════════════════ */
let _panicMode = false;

function togglePanicMode() {
  _panicMode = !_panicMode;
  const btn = document.getElementById('btnPanicMode');

  if (_panicMode) {
    // Show calming banner
    let banner = document.getElementById('panicBanner');
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'panicBanner';
      banner.style.cssText = 'background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;border-radius:10px;padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;gap:14px;';
      banner.innerHTML = `
        <span style="font-size:28px;">🧘</span>
        <div>
          <div style="font-weight:700;color:#15803d;font-size:14px;">Market Down? Focus on Units, Not Rupees.</div>
          <div style="font-size:12px;color:#166534;margin-top:3px;">
            Your <strong id="pbTotalUnits">—</strong> units are still yours. 
            Markets recover. SIPs compound. Stay the course. 🌱
          </div>
        </div>`;
      const statsGrid = document.querySelector('.stats-grid');
      if (statsGrid) statsGrid.parentNode.insertBefore(banner, statsGrid);
    }
    banner.style.display = 'flex';

    // Calculate total units from MF.data
    const totalUnits = (MF.data || []).reduce((sum, h) => sum + (parseFloat(h.units) || 0), 0);
    const unitEl = document.getElementById('pbTotalUnits');
    if (unitEl) unitEl.textContent = totalUnits.toLocaleString('en-IN', { maximumFractionDigits: 3 }) + ' units';

    // Dim loss cells — show in grey
    document.querySelectorAll('.negative, [class*="loss"]').forEach(el => {
      el.dataset.origColor = el.style.color || '';
      el.style.color = 'var(--text-muted)';
      el.style.opacity = '0.4';
    });

    // Replace page subtitle
    const sub = document.querySelector('.page-subtitle');
    if (sub) sub.textContent = '🧘 Calm Mode — Viewing units, not temporary losses';

    if (btn) {
      btn.textContent = '😰 Panic Mode';
      btn.style.background = 'rgba(22,163,74,.1)';
      btn.style.color = '#16a34a';
    }
  } else {
    // Restore
    const banner = document.getElementById('panicBanner');
    if (banner) banner.style.display = 'none';

    document.querySelectorAll('.negative, [class*="loss"]').forEach(el => {
      el.style.color = el.dataset.origColor || '';
      el.style.opacity = '';
    });

    const sub = document.querySelector('.page-subtitle');
    if (sub) sub.textContent = 'Mutual fund holdings & transactions';

    if (btn) {
      btn.textContent = '🧘 Calm Mode';
      btn.style.background = '';
      btn.style.color = '';
    }
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   t141 — SIP STREAK (consistency gamification)
═══════════════════════════════════════════════════════════════════════════ */
function renderSipStreak() {
  const holdings = MF.data || [];
  if (!holdings.length) return;

  // Find active SIPs and calculate streak from transaction history
  const fundsWithSip = holdings.filter(h => h.active_sip_amount > 0);
  if (!fundsWithSip.length) return;

  // Count consecutive months with transactions (using all txns data if available)
  // Simple approach: count months with BUY transactions in last 12 months
  const apiBase = window.WD?.appUrl || window.APP_URL || '';
  const pid = window.WD?.selectedPortfolio || 0;

  fetch(`${apiBase}/api/mutual_funds/mf_list.php?view=transactions&portfolio_id=${pid}&per_page=200&sort_col=txn_date&sort_dir=desc`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(d => {
    if (!d.success || !d.data?.length) return;

    // Get unique months with BUY txns in last 12 months
    const now = new Date();
    const monthsWithBuy = new Set();
    d.data.forEach(t => {
      if (t.transaction_type !== 'BUY') return;
      const txnDate = new Date(t.txn_date);
      const monthsAgo = (now.getFullYear() - txnDate.getFullYear()) * 12 + (now.getMonth() - txnDate.getMonth());
      if (monthsAgo >= 0 && monthsAgo < 12) {
        monthsWithBuy.add(`${txnDate.getFullYear()}-${txnDate.getMonth()}`);
      }
    });

    // Calculate streak — consecutive months back from current
    let streak = 0;
    for (let i = 0; i < 12; i++) {
      const d2 = new Date(now.getFullYear(), now.getMonth() - i, 1);
      const key = `${d2.getFullYear()}-${d2.getMonth()}`;
      if (monthsWithBuy.has(key)) streak++;
      else if (i > 0) break; // gap found
    }

    const card = document.getElementById('sipStreakCard');
    const valEl = document.getElementById('sipStreakVal');
    const subEl = document.getElementById('sipStreakSub');

    if (!card || streak === 0) return;
    card.style.display = '';

    const emoji = streak >= 12 ? '🏆' : streak >= 6 ? '🔥' : streak >= 3 ? '💪' : '🌱';
    const msg   = streak >= 12 ? 'Full year streak! Legendary investor 🏆'
                : streak >= 6  ? 'Amazing! 6+ months consistent SIP'
                : streak >= 3  ? 'Great habit forming!'
                : streak >= 1  ? 'Keep going, building momentum'
                : '';

    if (valEl) valEl.innerHTML = `${emoji} ${streak} <span style="font-size:14px;font-weight:400;">months</span>`;
    if (subEl) subEl.textContent = msg;
    // Color based on streak
    if (valEl) valEl.style.color = streak >= 6 ? '#d97706' : streak >= 3 ? '#16a34a' : 'var(--accent)';
  })
  .catch(() => {}); // silent fail
}

/* ═══════════════════════════════════════════════════════════════════════════
   t74 — CAPITAL GAINS TAB (ITR-ready format)
═══════════════════════════════════════════════════════════════════════════ */
const CG = { data: [], loaded: false };

async function loadCapitalGains(fy = '') {
  const body = document.getElementById('cgBody');
  if (!body) return;
  body.innerHTML = `<tr><td colspan="12" class="text-center" style="padding:40px;"><div class="spinner"></div></td></tr>`;
  document.getElementById('cgTfoot').style.display = 'none';

  const pid = window.WD?.selectedPortfolio || 0;
  if (!pid) {
    body.innerHTML = `<tr><td colspan="12" class="text-center" style="padding:32px;color:var(--text-muted);">Select a portfolio first</td></tr>`;
    return;
  }

  try {
    const fd = new FormData();
    fd.append('action', 'report_fy_gains');
    fd.append('portfolio_id', pid);
    fd.append('_csrf_token', window.WD?.csrf || window.CSRF_TOKEN || '');
    if (fy) fd.append('fy', fy);

    const apiBase = window.WD?.appUrl || window.APP_URL || '';
    const res  = await fetch(`${apiBase}/api/?action=report_fy_gains`, { method: 'POST', body: fd });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Failed');

    const gains = json.data?.mf_gains_detail || [];
    CG.data = gains;
    CG.loaded = true;

    // Populate FY dropdown
    const fyList = json.data?.fy_list || [];
    const fyFilter = document.getElementById('cgFyFilter');
    if (fyFilter) {
      const cur = fyFilter.value;
      fyFilter.innerHTML = '<option value="">All FYs</option>' + fyList.map(f => `<option value="${f}" ${f===cur?'selected':''}>${f}</option>`).join('');
    }

    renderCapGains(gains);
  } catch(e) {
    body.innerHTML = `<tr><td colspan="12" class="text-center text-danger" style="padding:32px;">${e.message}</td></tr>`;
  }
}

function renderCapGains(gains) {
  const body = document.getElementById('cgBody');
  if (!body) return;

  // Apply type filter
  const typeFilter = document.getElementById('cgTypeFilter')?.value || '';
  const filtered = typeFilter
    ? gains.filter(g => {
        if (typeFilter === 'LTCG') return g.gain_type?.toLowerCase().includes('ltcg');
        if (typeFilter === 'STCG') return g.gain_type?.toLowerCase().includes('stcg');
        if (typeFilter === 'Slab') return g.gain_type?.toLowerCase().includes('slab');
        return true;
      })
    : gains;

  if (!filtered.length) {
    body.innerHTML = `<tr><td colspan="12" class="text-center" style="padding:32px;color:var(--text-muted);">No capital gains found for selected filters</td></tr>`;
    document.getElementById('cgTfoot').style.display = 'none';
    return;
  }

  const typeColors = { LTCG:'#16a34a', STCG:'#d97706', 'LTCG Equity':'#16a34a', 'STCG Equity':'#d97706', 'Slab':'#6366f1' };

  body.innerHTML = filtered.map(g => {
    const gain     = g.gain || 0;
    const gainColor= gain >= 0 ? '#16a34a' : '#dc2626';
    const gainType = g.gain_type || '—';
    const typeColor= typeColors[gainType] || 'var(--text-muted)';
    const taxAmt   = g.tax_amount !== null && g.tax_amount !== undefined
      ? fmtFull(g.tax_amount)
      : (g.tax_rate ? `@${g.tax_rate}%` : 'Slab');

    return `<tr>
      <td style="font-size:11px;font-weight:600;">${g.fy || '—'}</td>
      <td style="max-width:180px;" title="${escHtml(g.name||'')}">
        <div style="font-size:12px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml((g.name||'').length>30?(g.name||'').slice(0,29)+'…':g.name||'—')}</div>
        <small style="color:var(--text-muted);">${escHtml(g.category||'')}</small>
      </td>
      <td style="font-size:11px;">${g.folio||'—'}</td>
      <td class="text-right" style="font-size:12px;">${Number(g.units||0).toFixed(4)}</td>
      <td class="text-right" style="font-size:12px;">₹${Number(g.buy_nav||0).toFixed(4)}</td>
      <td class="text-right" style="font-size:12px;">₹${Number(g.sell_nav||0).toFixed(4)}</td>
      <td class="text-right">${fmtFull(g.cost||0)}</td>
      <td class="text-right">${fmtFull(g.proceeds||0)}</td>
      <td class="text-right" style="color:${gainColor};font-weight:700;">${gain>=0?'+':''}${fmtFull(gain)}</td>
      <td class="text-right" style="font-size:11px;">${g.days_held||'—'}d</td>
      <td><span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;background:${typeColor}20;color:${typeColor};">${gainType}</span></td>
      <td class="text-right" style="color:#d97706;font-size:12px;">${taxAmt}</td>
    </tr>`;
  }).join('');

  // Totals
  const totCost     = filtered.reduce((s, g) => s + (g.cost     || 0), 0);
  const totProceeds = filtered.reduce((s, g) => s + (g.proceeds || 0), 0);
  const totGain     = filtered.reduce((s, g) => s + (g.gain     || 0), 0);
  const totTax      = filtered.reduce((s, g) => s + (g.tax_amount || 0), 0);

  const tfoot = document.getElementById('cgTfoot');
  if (tfoot) {
    tfoot.style.display = '';
    document.getElementById('cgTotCost').textContent     = fmtFull(totCost);
    document.getElementById('cgTotProceeds').textContent = fmtFull(totProceeds);
    document.getElementById('cgTotGain').textContent     = (totGain>=0?'+':'') + fmtFull(totGain);
    document.getElementById('cgTotGain').style.color     = totGain >= 0 ? '#16a34a' : '#dc2626';
    document.getElementById('cgTotTax').textContent      = fmtFull(totTax);
  }

  // Summary pills
  const ltcgGain = filtered.filter(g=>(g.gain_type||'').includes('LTCG')).reduce((s,g)=>s+(g.gain||0),0);
  const stcgGain = filtered.filter(g=>(g.gain_type||'').includes('STCG')).reduce((s,g)=>s+(g.gain||0),0);
  const pillsEl  = document.getElementById('cgSummaryPills');
  if (pillsEl) {
    pillsEl.innerHTML = [
      ['LTCG Gains', ltcgGain, '#16a34a'],
      ['STCG Gains', stcgGain, '#d97706'],
      ['Total Gains', totGain, totGain>=0?'#16a34a':'#dc2626'],
      ['Approx Tax',  totTax,  '#6366f1'],
    ].map(([label, val, color]) => `
      <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:8px 14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;">${label}</div>
        <div style="font-size:14px;font-weight:800;color:${color};margin-top:2px;">${val>=0?'+':''}${fmtFull(val)}</div>
      </div>`).join('');
  }

  // LTCG Exemption bar (current FY only)
  const curFy = document.getElementById('cgFyFilter')?.value || '';
  const exemptCard = document.getElementById('cgExemptCard');
  if (exemptCard) {
    const limit = 125000;
    const used  = Math.max(0, ltcgGain);
    const pct   = Math.min(100, (used / limit) * 100);
    const left  = Math.max(0, limit - used);
    exemptCard.style.display = '';
    document.getElementById('cgExemptUsed').textContent  = fmtFull(used);
    document.getElementById('cgExemptLeft').textContent  = fmtFull(left);
    const bar = document.getElementById('cgExemptBar');
    bar.style.width      = pct + '%';
    bar.style.background = pct > 90 ? '#dc2626' : pct > 70 ? '#d97706' : '#16a34a';
    const msg = document.getElementById('cgExemptMsg');
    if (msg) {
      msg.textContent = pct >= 100
        ? `⚠️ LTCG exemption exhausted! ₹${fmtFull(used-limit)} above limit — tax applicable.`
        : pct > 70
        ? `⚠️ ${pct.toFixed(0)}% of exemption used. ₹${fmtFull(left)} remaining.`
        : `✅ ₹${fmtFull(left)} LTCG exemption still available this FY.`;
    }
  }
}

function exportCapgainsCsv() {
  const gains = CG.data;
  if (!gains.length) { alert('No data to export'); return; }

  const headers = ['FY','Fund Name','Category','Folio','Units','Buy NAV','Sell NAV','Cost (₹)','Proceeds (₹)','Gain (₹)','Days Held','Gain Type','Tax (₹)'];
  const rows = gains.map(g => [
    g.fy||'', g.name||'', g.category||'', g.folio||'',
    (g.units||0).toFixed(4),
    (g.buy_nav||0).toFixed(4), (g.sell_nav||0).toFixed(4),
    (g.cost||0).toFixed(2), (g.proceeds||0).toFixed(2), (g.gain||0).toFixed(2),
    g.days_held||'', g.gain_type||'', (g.tax_amount||0).toFixed(2)
  ].map(v => `"${String(v).replace(/"/g,'""')}"`));

  const csv  = [headers.join(','), ...rows.map(r=>r.join(','))].join('\n');
  const blob = new Blob(['\uFEFF'+csv], { type:'text/csv;charset=utf-8;' });
  const a    = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `capital_gains_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
}

/* ═══════════════════════════════════════════════════════════════════════════
   t109 — DIRECT vs REGULAR COMPARISON (screener drawer inject)
   Called from renderMfAnalytics when data loads
═══════════════════════════════════════════════════════════════════════════ */
function renderDirectVsRegular() {
  // Find if user holds any Regular plan funds
  const holdings = MF.data || [];
  const regularFunds = holdings.filter(h =>
    h.scheme_name?.toLowerCase().includes('regular') ||
    h.plan_type === 'regular'
  );

  if (!regularFunds.length) return; // all direct — no warning needed

  // Show banner warning
  let banner = document.getElementById('regularFundsBanner');
  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'regularFundsBanner';
    banner.style.cssText = 'background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1.5px solid #fb923c;border-radius:10px;padding:14px 20px;margin-bottom:16px;';

    const section = document.getElementById('mfAnalyticsSection');
    if (section) section.insertAdjacentElement('afterend', banner);
    else return;
  }

  const totalRegInvested = regularFunds.reduce((s,h) => s + (h.total_invested||0), 0);
  const totalRegValue    = regularFunds.reduce((s,h) => s + (h.value_now||0), 0);
  // t269: Use actual expense_ratio if available, else estimate 0.6% drag
  const annualDrag = regularFunds.reduce((s,h) => {
    const drag = h.expense_ratio ? Math.min(h.expense_ratio * 0.45, 0.8) : 0.6; // ~45% of TER is commission
    return s + (h.value_now||0) * drag / 100;
  }, 0);
  const fiveYrDrag  = totalRegValue * (Math.pow(1.006, 5)  - 1);
  const tenYrDrag   = totalRegValue * (Math.pow(1.006, 10) - 1);

  // Per-fund breakdown rows
  const fundRows = regularFunds.map(h => {
    const drag     = h.expense_ratio ? Math.min(h.expense_ratio * 0.45, 0.8) : 0.6;
    const annCost  = Math.round((h.value_now||0) * drag / 100);
    const tenCost  = Math.round((h.value_now||0) * (Math.pow(1 + drag/100, 10) - 1));
    const expStr   = h.expense_ratio ? `${Number(h.expense_ratio).toFixed(2)}%` : '?';
    return `<tr style="border-bottom:1px solid rgba(251,146,60,.2);">
      <td style="padding:5px 8px;font-size:11px;font-weight:600;color:#9a3412;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${h.scheme_name}">${(h.scheme_name||'').replace(/\bregular\b/gi,'<b>Regular</b>')}</td>
      <td style="padding:5px 8px;font-size:11px;text-align:right;">${fmtFull(h.value_now||0)}</td>
      <td style="padding:5px 8px;font-size:11px;text-align:right;color:#d97706;">${expStr}</td>
      <td style="padding:5px 8px;font-size:11px;text-align:right;color:#c2410c;font-weight:700;">~₹${annCost.toLocaleString('en-IN')}/yr</td>
      <td style="padding:5px 8px;font-size:11px;text-align:right;color:#9f1239;font-weight:700;">~₹${tenCost >= 100000 ? (tenCost/100000).toFixed(1)+'L' : tenCost.toLocaleString('en-IN')} (10yr)</td>
    </tr>`;
  }).join('');

  const tableHtml = `
    <div style="margin-top:10px;overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:11px;">
        <thead>
          <tr style="background:rgba(251,146,60,.15);">
            <th style="padding:5px 8px;text-align:left;font-size:10px;color:#9a3412;">Fund</th>
            <th style="padding:5px 8px;text-align:right;font-size:10px;color:#9a3412;">Value</th>
            <th style="padding:5px 8px;text-align:right;font-size:10px;color:#9a3412;">TER</th>
            <th style="padding:5px 8px;text-align:right;font-size:10px;color:#9a3412;">Annual Cost</th>
            <th style="padding:5px 8px;text-align:right;font-size:10px;color:#9a3412;">10-yr Loss</th>
          </tr>
        </thead>
        <tbody>${fundRows}</tbody>
      </table>
    </div>`;

  banner.innerHTML = `
    <div style="display:flex;align-items:flex-start;gap:14px;">
      <span style="font-size:24px;flex-shrink:0;">⚠️</span>
      <div style="flex:1;">
        <div style="font-weight:700;color:#c2410c;font-size:13px;margin-bottom:4px;">
          ${regularFunds.length} Regular Plan Fund${regularFunds.length>1?'s':''} — You Are Overpaying
        </div>
        <div style="font-size:12px;color:#9a3412;line-height:1.6;">
          Estimated annual drag: <strong>~₹${Math.round(annualDrag).toLocaleString('en-IN')}/year</strong> on ₹${fmtFull(totalRegValue)} current value.
          <br>5-year loss: <strong>~₹${Math.round(fiveYrDrag).toLocaleString('en-IN')}</strong> &nbsp;·&nbsp;
          10-year loss: <strong style="color:#c2410c;">~₹${Math.round(tenYrDrag).toLocaleString('en-IN')}</strong>
        </div>
        ${tableHtml}
        <div style="font-size:11px;color:#9a3412;margin-top:8px;">💡 Switch to Direct plans via <a href="https://www.mfcentral.com" target="_blank" style="color:#c2410c;font-weight:700;">MF Central</a> or your AMC website. Same fund, lower cost.</div>
      </div>
    </div>`;
}
}

// Hook into renderMfAnalytics
const _origRenderMfAnalytics = renderMfAnalytics;
function renderMfAnalytics() {
  _origRenderMfAnalytics();
  renderDirectVsRegular();
  renderSipStreak();
}

/* ═══════════════════════════════════════════════════════════════════════════
   t186 — PRINT / PDF HOLDINGS
═══════════════════════════════════════════════════════════════════════════ */
function printHoldings() {
  const printArea = document.getElementById('mfPrintArea');
  if (!printArea) return;

  // ── Collect live data from the rendered holdings table ──────────
  const rows  = Array.from(document.querySelectorAll('#holdingsTable tbody tr.holding-row'));
  const funds = rows.map(tr => ({
    name:      tr.querySelector('[data-col="name"]')?.textContent?.trim()   || tr.querySelector('.fund-name')?.textContent?.trim()   || '—',
    house:     tr.querySelector('[data-col="house"]')?.textContent?.trim()  || tr.querySelector('.fund-house')?.textContent?.trim()  || '',
    invested:  tr.querySelector('[data-col="invested"]')?.textContent?.trim()|| tr.querySelector('.col-invested')?.textContent?.trim()|| '—',
    value:     tr.querySelector('[data-col="value"]')?.textContent?.trim()  || tr.querySelector('.col-value')?.textContent?.trim()  || '—',
    gain:      tr.querySelector('[data-col="gain"]')?.textContent?.trim()   || tr.querySelector('.col-gain')?.textContent?.trim()   || '—',
    ret:       tr.querySelector('[data-col="ret"]')?.textContent?.trim()    || tr.querySelector('.col-ret')?.textContent?.trim()    || '—',
    units:     tr.querySelector('[data-col="units"]')?.textContent?.trim()  || tr.querySelector('.col-units')?.textContent?.trim()  || '—',
    nav:       tr.querySelector('[data-col="nav"]')?.textContent?.trim()    || tr.querySelector('.col-nav')?.textContent?.trim()    || '—',
    category:  tr.dataset.category || tr.querySelector('.fund-category')?.textContent?.trim() || '',
  }));

  // Fallback: pull from MF.data if table rows unavailable
  const mfData = (typeof MF !== 'undefined' && MF.data) ? MF.data : [];
  const sourceData = funds.length ? funds : mfData.map(h => ({
    name:     h.scheme_name || '—',
    house:    h.fund_house  || '',
    invested: fmtFull ? fmtFull(h.total_invested) : '₹' + Number(h.total_invested||0).toLocaleString('en-IN'),
    value:    fmtFull ? fmtFull(h.value_now)       : '₹' + Number(h.value_now||0).toLocaleString('en-IN'),
    gain:     (h.gain_pct >= 0 ? '+' : '') + Number(h.gain_pct||0).toFixed(2) + '%',
    ret:      h.xirr  != null ? Number(h.xirr).toFixed(1)+'% XIRR' : '—',
    units:    Number(h.total_units||0).toFixed(3),
    nav:      h.latest_nav ? '₹'+Number(h.latest_nav).toFixed(4) : '—',
    category: h.category_short || h.category || '',
  }));

  // ── Summary stats ────────────────────────────────────────────────
  const totalInv = document.getElementById('mfTotalInvested')?.textContent?.trim() || '—';
  const curVal   = document.getElementById('mfValueNow')?.textContent?.trim()      || '—';
  const gainEl   = document.getElementById('mfGainLoss');
  const gain     = gainEl?.textContent?.trim() || '—';

  const now = new Date().toLocaleDateString('en-IN', { day:'2-digit', month:'long', year:'numeric' });

  // ── Build print HTML ─────────────────────────────────────────────
  const tableRows = sourceData.map((f, i) => `
    <tr style="background:${i%2===0?'#fff':'#f9fafb'};">
      <td style="padding:6px 8px;font-size:11px;font-weight:600;color:#111;max-width:200px;">${f.name}<br><span style="font-size:9px;color:#6b7280;font-weight:400;">${f.house}${f.category ? ' · '+f.category : ''}</span></td>
      <td style="padding:6px 8px;font-size:11px;text-align:right;">${f.invested}</td>
      <td style="padding:6px 8px;font-size:11px;text-align:right;font-weight:700;">${f.value}</td>
      <td style="padding:6px 8px;font-size:11px;text-align:right;">${f.gain}</td>
      <td style="padding:6px 8px;font-size:11px;text-align:right;">${f.ret}</td>
      <td style="padding:6px 8px;font-size:11px;text-align:right;color:#6b7280;">${f.units}</td>
      <td style="padding:6px 8px;font-size:11px;text-align:right;color:#6b7280;">${f.nav}</td>
    </tr>`).join('');

  printArea.innerHTML = `
    <div style="font-family:Arial,sans-serif;color:#111;background:#fff;max-width:1000px;margin:0 auto;">
      <!-- Header -->
      <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #1e40af;">
        <div>
          <div style="font-size:20px;font-weight:800;color:#1e40af;">WealthDash</div>
          <div style="font-size:13px;color:#6b7280;margin-top:2px;">Mutual Fund Holdings Statement</div>
        </div>
        <div style="text-align:right;font-size:11px;color:#6b7280;">
          <div style="font-weight:700;font-size:13px;color:#111;">As on ${now}</div>
          <div>${sourceData.length} fund${sourceData.length !== 1 ? 's' : ''} in portfolio</div>
        </div>
      </div>

      <!-- Summary Boxes -->
      <div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
        <div style="flex:1;min-width:140px;border:1.5px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
          <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:3px;">Total Invested</div>
          <div style="font-size:16px;font-weight:800;color:#111;">${totalInv}</div>
        </div>
        <div style="flex:1;min-width:140px;border:1.5px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
          <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:3px;">Current Value</div>
          <div style="font-size:16px;font-weight:800;color:#1e40af;">${curVal}</div>
        </div>
        <div style="flex:1;min-width:140px;border:1.5px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
          <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:3px;">Gain / Loss</div>
          <div style="font-size:16px;font-weight:800;">${gain}</div>
        </div>
      </div>

      <!-- Holdings Table -->
      <table style="width:100%;border-collapse:collapse;font-size:11px;">
        <thead>
          <tr style="background:#1e40af;color:#fff;">
            <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">Fund</th>
            <th style="padding:8px;text-align:right;font-size:10px;font-weight:700;">Invested</th>
            <th style="padding:8px;text-align:right;font-size:10px;font-weight:700;">Current Value</th>
            <th style="padding:8px;text-align:right;font-size:10px;font-weight:700;">Gain/Loss</th>
            <th style="padding:8px;text-align:right;font-size:10px;font-weight:700;">Returns</th>
            <th style="padding:8px;text-align:right;font-size:10px;font-weight:700;">Units</th>
            <th style="padding:8px;text-align:right;font-size:10px;font-weight:700;">NAV</th>
          </tr>
        </thead>
        <tbody>${tableRows}</tbody>
      </table>

      <!-- Footer -->
      <div style="margin-top:20px;padding-top:10px;border-top:1px solid #e5e7eb;font-size:9px;color:#9ca3af;">
        Generated by WealthDash · ${now} · For personal reference only · Not a financial statement · NAV data sourced from AMFI
      </div>
    </div>`;

  // Show print area and trigger browser print
  printArea.style.display = 'block';
  window.print();
  // Hide it again after print dialog closes
  setTimeout(() => { printArea.style.display = 'none'; }, 500);
}

/* ═══════════════════════════════════════════════════════════════════════════
   t112 — FUND FINDER QUICK ADD (Holdings page)
═══════════════════════════════════════════════════════════════════════════ */
let _ffCat = '', _ffDebounce = null;

function openFundFinderModal() {
  showModal('modalFundFinder');
  setTimeout(() => document.getElementById('ffSearch')?.focus(), 100);
}
function hideFundFinderModal() { hideModal('modalFundFinder'); }

function setFfFilter(cat, el) {
  _ffCat = cat;
  document.querySelectorAll('.ff-pill').forEach(p => p.classList.remove('active'));
  if (el) el.classList.add('active');
  const q = document.getElementById('ffSearch')?.value || '';
  if (q.length >= 2 || cat) doFfSearch(q);
}

function onFfSearch(q) {
  clearTimeout(_ffDebounce);
  _ffDebounce = setTimeout(() => doFfSearch(q), 300);
}

async function doFfSearch(q) {
  const resultsEl = document.getElementById('ffResults');
  if (!resultsEl) return;
  if (q.length < 2 && !_ffCat) {
    resultsEl.innerHTML = '<div style="padding:30px;text-align:center;color:var(--text-muted);font-size:13px;">Type at least 2 characters…</div>';
    return;
  }
  resultsEl.innerHTML = '<div style="padding:20px;text-align:center;"><div class="spinner"></div></div>';

  try {
    const base   = window.WD?.appUrl || window.APP_URL || '';
    const params = new URLSearchParams({ per_page: 20, sort: 'name', page: 1 });
    if (q)     params.set('q', q);
    if (_ffCat) params.append('category[]', _ffCat);
    // Direct only
    params.set('plan_type', 'direct');
    params.set('option_type', 'growth');

    const res  = await fetch(`${base}/api/mutual_funds/fund_screener.php?${params}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);

    const funds = json.data || [];
    if (!funds.length) {
      resultsEl.innerHTML = '<div style="padding:30px;text-align:center;color:var(--text-muted);font-size:13px;">No funds found — try different keywords</div>';
      return;
    }

    resultsEl.innerHTML = funds.map(f => {
      const nav    = f.latest_nav ? `₹${Number(f.latest_nav).toFixed(4)}` : '—';
      const ret1y  = f.returns_1y !== null ? `<span style="color:${f.returns_1y>=0?'#16a34a':'#dc2626'};font-weight:700;">${f.returns_1y>0?'+':''}${f.returns_1y?.toFixed(1)}%</span>` : '';
      const exp    = f.expense_ratio !== null ? `<span style="color:var(--text-muted);">${f.expense_ratio.toFixed(2)}%</span>` : '';
      const cat    = f.category_short || f.category || '';
      return `<div class="ff-result-row" onclick="ffSelectFund(${f.id},'${escHtml(f.scheme_name).replace(/'/g,"\\'")}')">
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(f.scheme_name)}</div>
          <div style="font-size:11px;color:var(--text-muted);">${escHtml(f.fund_house||'')} · ${escHtml(cat)}</div>
        </div>
        <div style="text-align:right;flex-shrink:0;font-size:12px;">
          <div style="font-weight:600;">${nav}</div>
          <div style="font-size:11px;">${ret1y} ${exp}</div>
        </div>
        <div style="flex-shrink:0;">
          <button class="btn btn-primary btn-sm" style="white-space:nowrap;font-size:11px;">+ Add</button>
        </div>
      </div>`;
    }).join('');
  } catch(e) {
    resultsEl.innerHTML = `<div style="padding:20px;text-align:center;color:#dc2626;font-size:12px;">${e.message}</div>`;
  }
}

function ffSelectFund(fundId, fundName) {
  hideFundFinderModal();
  // Open add transaction modal with fund pre-filled
  if (typeof openAddTxnForFund === 'function') {
    openAddTxnForFund(fundId, fundName);
  } else {
    openAddTxnModal();
    const fs = document.getElementById('txnFundSearch');
    if (fs) fs.value = fundName;
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   t112 — SWAP FUND (Exit one, Enter another — same amount)
═══════════════════════════════════════════════════════════════════════════ */
let _swapSourceFundId = null, _swapSourceFundName = '', _swapSourceUnits = 0, _swapSourceNav = 0;

function openSwapFundModal(fundId, fundName, units, nav) {
  _swapSourceFundId   = fundId;
  _swapSourceFundName = fundName;
  _swapSourceUnits    = parseFloat(units) || 0;
  _swapSourceNav      = parseFloat(nav)   || 0;

  // Reuse Fund Finder modal — change title & button label
  const title = document.querySelector('#modalFundFinder .modal-title');
  if (title) title.textContent = `🔄 Swap: ${fundName.substring(0, 30)}… → New Fund`;

  // Update the filter pills label
  const pills = document.getElementById('ffFilterPills');
  if (pills && !document.getElementById('swapBanner')) {
    const banner = document.createElement('div');
    banner.id = 'swapBanner';
    banner.style.cssText = 'width:100%;padding:8px 10px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:7px;font-size:12px;color:#b45309;margin-bottom:6px;';
    banner.innerHTML = `🔄 <strong>Swap mode:</strong> Select the fund to switch INTO. We'll SELL <strong>${_swapSourceUnits.toFixed(4)} units</strong> of <em>${fundName.substring(0,25)}</em> and BUY equivalent value in new fund.`;
    pills.parentNode.insertBefore(banner, pills);
  }

  // Change + Add buttons to + Swap Into
  openFundFinderModal();
  // Re-render results if any query active
  const q = document.getElementById('ffSearch')?.value || '';
  if (q.length >= 2 || _ffCat) doFfSearch(q, true);
}

// Override ffSelectFund when in swap mode
function ffSelectFundOrSwap(fundId, fundName) {
  if (_swapSourceFundId) {
    // Swap mode — close modal, open sell txn for source, then buy for target
    hideFundFinderModal();
    _doSwapFlow(fundId, fundName);
  } else {
    ffSelectFund(fundId, fundName);
  }
}

async function _doSwapFlow(targetFundId, targetFundName) {
  const sellAmt = _swapSourceUnits * _swapSourceNav;
  if (!confirm(`Swap Plan:\n\n• SELL ${_swapSourceUnits.toFixed(4)} units of ${_swapSourceFundName}\n  (≈ ₹${Number(sellAmt).toLocaleString('en-IN', {maximumFractionDigits:0})})\n\n• BUY equivalent amount in ${targetFundName}\n\nTwo separate transactions will be created. Continue?`)) return;

  // Step 1: Pre-fill SELL transaction for source fund
  openAddTxnForFund(_swapSourceFundId, _swapSourceFundName);
  setTimeout(() => {
    const typeEl = document.getElementById('txnType');
    if (typeEl) { typeEl.value = 'SELL'; typeEl.dispatchEvent(new Event('change')); }
    const unitsEl = document.getElementById('txnUnits');
    if (unitsEl) unitsEl.value = _swapSourceUnits.toFixed(4);
    showToast(`Step 1: Record SELL for ${_swapSourceFundName.substring(0,25)}. Then Step 2 will open BUY for ${targetFundName.substring(0,25)}.`, 'info');
    // Reset swap mode
    _swapSourceFundId = null;
    const banner = document.getElementById('swapBanner');
    if (banner) banner.remove();
    const title = document.querySelector('#modalFundFinder .modal-title');
    if (title) title.textContent = '🔍 Find & Add Fund';
  }, 300);
}

/* ═══════════════════════════════════════════════════════════════════════════
   t112 — SIMILAR / BETTER FUNDS (suggest alternatives for underperformers)
═══════════════════════════════════════════════════════════════════════════ */
let _similarContext = null;

async function openSimilarFundsModal(fundId, fundName, category, cagr) {
  _similarContext = { fundId, fundName, category, cagr: parseFloat(cagr) || 0 };

  const title = document.querySelector('#modalFundFinder .modal-title');
  if (title) title.textContent = `💡 Similar Funds — Better than ${fundName.substring(0, 25)}…`;

  // Show banner
  const pills = document.getElementById('ffFilterPills');
  if (pills && !document.getElementById('similarBanner')) {
    const banner = document.createElement('div');
    banner.id = 'similarBanner';
    banner.style.cssText = 'width:100%;padding:8px 10px;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.2);border-radius:7px;font-size:12px;color:#1e40af;margin-bottom:6px;';
    banner.innerHTML = `💡 Showing funds in <strong>${category}</strong> category — sorted by 1Y returns. Your fund CAGR: <strong>${Number(cagr).toFixed(1)}%</strong>`;
    pills.parentNode.insertBefore(banner, pills);
  }

  openFundFinderModal();
  // Auto-search similar category
  const searchEl = document.getElementById('ffSearch');
  if (searchEl) searchEl.value = '';
  setFfFilter(category, document.querySelector(`.ff-pill[data-cat="${category}"]`));
}

/* ═══════════════════════════════════════════════════════════════════════════
   t112 — PORTFOLIO HEALTH SCORE
═══════════════════════════════════════════════════════════════════════════ */
function calcPortfolioHealthScore(holdings) {
  if (!holdings || !holdings.length) return null;

  let score = 100;
  const issues = [], positives = [];

  // 1. Diversification (too concentrated in one fund?)
  const totalVal = holdings.reduce((s, h) => s + (h.value_now || 0), 0);
  holdings.forEach(h => {
    const pct = totalVal > 0 ? (h.value_now / totalVal * 100) : 0;
    if (pct > 40) { score -= 15; issues.push(`${h.scheme_name?.substring(0,25)} is ${pct.toFixed(0)}% of portfolio — too concentrated.`); }
  });

  // 2. Too many funds?
  if (holdings.length > 15) { score -= 10; issues.push(`${holdings.length} funds is too many — consider consolidating.`); }
  else if (holdings.length <= 6) positives.push(`Good — ${holdings.length} funds is focused and manageable.`);

  // 3. Expense ratio check
  const avgExp = holdings.reduce((s, h) => s + (h.expense_ratio || 0), 0) / holdings.length;
  if (avgExp > 1.5) { score -= 10; issues.push(`Avg expense ratio ${avgExp.toFixed(2)}% is high — prefer index/direct funds.`); }
  else if (avgExp < 0.5) positives.push(`Low avg expense ratio ${avgExp.toFixed(2)}% — great for long-term compounding.`);

  // 4. Returns check — any fund with negative 1Y return?
  const negFunds = holdings.filter(h => (h.returns_1y || 0) < 0);
  if (negFunds.length) { score -= negFunds.length * 5; issues.push(`${negFunds.length} fund(s) have negative 1Y returns.`); }

  // 5. Direct plan check
  const regularFunds = holdings.filter(h => (h.plan_type || '').toLowerCase().includes('regular'));
  if (regularFunds.length) { score -= regularFunds.length * 8; issues.push(`${regularFunds.length} Regular plan fund(s) — switch to Direct for 0.5–1% more p.a.`); }
  else positives.push('All Direct plans — saving on commission every year.');

  score = Math.max(0, Math.min(100, score));
  const grade = score >= 85 ? 'A' : score >= 70 ? 'B' : score >= 55 ? 'C' : 'D';
  const color = score >= 85 ? '#16a34a' : score >= 70 ? '#2563eb' : score >= 55 ? '#b45309' : '#dc2626';

  return { score, grade, color, issues, positives };
}

function renderPortfolioHealthScore(holdings) {
  const el = document.getElementById('portfolioHealthScore');
  if (!el) return;
  const result = calcPortfolioHealthScore(holdings);
  if (!result) { el.style.display = 'none'; return; }

  el.style.display = '';
  el.innerHTML = `
    <div style="display:flex;align-items:center;gap:14px;padding:12px 16px;background:var(--surface);border:1.5px solid var(--border);border-radius:10px;margin-bottom:12px;">
      <div style="text-align:center;flex-shrink:0;">
        <div style="font-size:32px;font-weight:900;color:${result.color};line-height:1;">${result.grade}</div>
        <div style="font-size:10px;font-weight:700;color:var(--text-muted)">HEALTH</div>
        <div style="font-size:11px;font-weight:700;color:${result.color}">${result.score}/100</div>
      </div>
      <div style="flex:1;">
        <div style="font-size:13px;font-weight:700;margin-bottom:6px;">Portfolio Health Score</div>
        <div style="height:6px;background:var(--border);border-radius:99px;overflow:hidden;margin-bottom:8px;">
          <div style="height:100%;width:${result.score}%;background:${result.color};border-radius:99px;transition:width .6s;"></div>
        </div>
        ${result.issues.length ? `<div style="font-size:11px;color:#b91c1c;margin-bottom:4px;">${result.issues.slice(0,2).map(i=>`⚠ ${i}`).join('<br>')}</div>` : ''}
        ${result.positives.length ? `<div style="font-size:11px;color:#15803d;">${result.positives.slice(0,2).map(p=>`✓ ${p}`).join('<br>')}</div>` : ''}
      </div>
    </div>`;
}

// Hook portfolio health into holdings load
const _origRenderHoldings = typeof renderHoldings === 'function' ? renderHoldings : null;
window.addEventListener('mf:holdingsLoaded', (e) => {
  if (e.detail?.holdings) renderPortfolioHealthScore(e.detail.holdings);
});

/* ═══════════════════════════════════════════════════════════════════════════
   t77 + t78 + t79 — PRICE ALERTS, DRAWDOWN ALERTS, SIP REMINDERS
   All localStorage-based, checked on holdings page load
═══════════════════════════════════════════════════════════════════════════ */
const ALERT_STORE = 'wd_alerts_v2';

function getAllAlerts() {
  try { return JSON.parse(localStorage.getItem(ALERT_STORE) || '[]'); } catch(e) { return []; }
}
function saveAllAlerts(alerts) {
  try { localStorage.setItem(ALERT_STORE, JSON.stringify(alerts)); } catch(e) {}
}

// t77: Price Alert
function addPriceAlert(fundId, fundName, targetNav, type = 'above') {
  const alerts = getAllAlerts().filter(a => !(a.type === 'price' && a.fund_id === fundId));
  alerts.push({ type: 'price', fund_id: fundId, fund_name: fundName, target_nav: targetNav, direction: type, created: new Date().toISOString() });
  saveAllAlerts(alerts);
  showToast(`🔔 Price alert set: ${fundName.slice(0,25)} NAV ${type} ₹${targetNav}`, 'success');
  renderNotifBell();
}

// t78: Drawdown Alert
function addDrawdownAlert(fundId, fundName, thresholdPct) {
  const alerts = getAllAlerts().filter(a => !(a.type === 'drawdown' && a.fund_id === fundId));
  alerts.push({ type: 'drawdown', fund_id: fundId, fund_name: fundName, threshold_pct: thresholdPct, created: new Date().toISOString() });
  saveAllAlerts(alerts);
  showToast(`🔔 Drawdown alert set: ${fundName.slice(0,25)} if drops ${thresholdPct}% from ATH`, 'success');
  renderNotifBell();
}

// t79: SIP Due Reminder
function addSipReminder(sipId, fundName, sipDay) {
  const alerts = getAllAlerts().filter(a => !(a.type === 'sip_due' && a.sip_id === sipId));
  alerts.push({ type: 'sip_due', sip_id: sipId, fund_name: fundName, sip_day: sipDay, created: new Date().toISOString() });
  saveAllAlerts(alerts);
  showToast(`🔔 SIP reminder set for ${fundName.slice(0,25)} (day ${sipDay})`, 'success');
  renderNotifBell();
}

// Check all alerts against current holdings data
function checkAllAlerts(holdings) {
  if (!holdings?.length) return;
  const alerts   = getAllAlerts();
  const triggered = [];
  const today    = new Date();
  const todayDay = today.getDate();

  alerts.forEach(a => {
    if (a.type === 'price') {
      const h = holdings.find(x => x.fund_id == a.fund_id || x.id == a.fund_id);
      if (!h) return;
      const nav = parseFloat(h.latest_nav);
      if (a.direction === 'above' && nav >= a.target_nav) {
        triggered.push({ ...a, msg: `📈 ${a.fund_name.slice(0,30)}: NAV ₹${nav.toFixed(4)} crossed above ₹${a.target_nav}` });
      } else if (a.direction === 'below' && nav <= a.target_nav) {
        triggered.push({ ...a, msg: `📉 ${a.fund_name.slice(0,30)}: NAV ₹${nav.toFixed(4)} dropped below ₹${a.target_nav}` });
      }
    }
    if (a.type === 'drawdown') {
      const h = holdings.find(x => x.fund_id == a.fund_id || x.id == a.fund_id);
      if (!h || h.drawdown_pct === null) return;
      if (h.drawdown_pct >= a.threshold_pct) {
        triggered.push({ ...a, msg: `⚠️ ${a.fund_name.slice(0,30)}: Down ${h.drawdown_pct}% from ATH (alert: ${a.threshold_pct}%)` });
      }
    }
    if (a.type === 'sip_due') {
      // Alert 3 days before SIP day
      const daysToSip = (a.sip_day - todayDay + 31) % 31;
      if (daysToSip <= 3 && daysToSip >= 0) {
        triggered.push({ ...a, msg: `💳 SIP due in ${daysToSip === 0 ? 'TODAY' : daysToSip + ' days'}: ${a.fund_name.slice(0,30)} (day ${a.sip_day})` });
      }
    }
  });

  if (triggered.length) {
    // Show toast for first 2, badge for rest
    triggered.slice(0, 2).forEach(t => showToast(t.msg, 'warning'));
    // Store triggered for notification center
    try {
      const prev = JSON.parse(localStorage.getItem('wd_triggered_alerts') || '[]');
      const merged = [...triggered, ...prev].slice(0, 20);
      localStorage.setItem('wd_triggered_alerts', JSON.stringify(merged));
    } catch(e) {}
    renderNotifBell();
  }
}

function removeAlert(idx) {
  const alerts = getAllAlerts();
  alerts.splice(idx, 1);
  saveAllAlerts(alerts);
  renderNotifBell();
  renderNotifBody();
}

function clearAllAlerts() {
  saveAllAlerts([]);
  try { localStorage.removeItem('wd_triggered_alerts'); } catch(e) {}
  renderNotifBell();
  renderNotifBody();
}

/* ═══════════════════════════════════════════════════════════════════════════
   t81 — NOTIFICATION BELL + CENTER
═══════════════════════════════════════════════════════════════════════════ */
function renderNotifBell() {
  const bell = document.getElementById('notifBell');
  if (!bell) return;
  const alerts    = getAllAlerts();
  const triggered = (() => { try { return JSON.parse(localStorage.getItem('wd_triggered_alerts') || '[]'); } catch(e) { return []; } })();
  const total     = triggered.length;
  const badge     = bell.querySelector('.notif-badge');
  if (badge) {
    badge.textContent = total > 0 ? (total > 9 ? '9+' : total) : '';
    badge.style.display = total > 0 ? '' : 'none';
  }
}

function renderNotifBody() {
  const body = document.getElementById('notifBody');
  if (!body) return;
  const alerts    = getAllAlerts();
  const triggered = (() => { try { return JSON.parse(localStorage.getItem('wd_triggered_alerts') || '[]'); } catch(e) { return []; } })();

  if (!alerts.length && !triggered.length) {
    body.innerHTML = `<div style="padding:30px;text-align:center;color:var(--text-muted);">
      <div style="font-size:28px;margin-bottom:8px;">🔔</div>
      <div style="font-size:13px;font-weight:600;">No alerts configured</div>
      <div style="font-size:12px;margin-top:4px;">Set price alerts, drawdown alerts, or SIP reminders from fund details</div>
    </div>`;
    return;
  }

  let html = '';

  // Triggered alerts
  if (triggered.length) {
    html += `<div style="padding:8px 14px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);border-bottom:1px solid var(--border);">Recent Triggers</div>`;
    html += triggered.slice(0,10).map(t => `
      <div style="padding:10px 14px;border-bottom:1px solid var(--border);font-size:12px;">
        <div style="font-weight:600;">${t.msg || t.fund_name}</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${new Date(t.created||Date.now()).toLocaleDateString('en-IN')}</div>
      </div>`).join('');
  }

  // Configured alerts
  if (alerts.length) {
    html += `<div style="padding:8px 14px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);border-bottom:1px solid var(--border);margin-top:4px;">Configured Alerts</div>`;
    html += alerts.map((a, i) => {
      const icon   = a.type === 'price' ? '💰' : a.type === 'drawdown' ? '📉' : '💳';
      const detail = a.type === 'price'     ? `NAV ${a.direction} ₹${a.target_nav}`
                   : a.type === 'drawdown'  ? `Drawdown > ${a.threshold_pct}%`
                   : `SIP day ${a.sip_day}`;
      return `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);">
        <span style="font-size:16px;">${icon}</span>
        <div style="flex:1;min-width:0;">
          <div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${a.fund_name?.slice(0,35)||'—'}</div>
          <div style="font-size:11px;color:var(--text-muted);">${detail}</div>
        </div>
        <button onclick="removeAlert(${i})" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:14px;padding:2px 6px;" title="Remove">✕</button>
      </div>`;
    }).join('');
  }

  body.innerHTML = html;
}

function openNotifCenter() {
  // Clear triggered alerts on open
  try { localStorage.removeItem('wd_triggered_alerts'); } catch(e) {}
  renderNotifBody();
  renderNotifBell();
  showModal('modalNotifications');
}

// Inject notification bell into topbar (after page loads)
function injectNotifBell() {
  const actions = document.querySelector('.page-header-actions');
  if (!actions || document.getElementById('notifBell')) return;
  const bell = document.createElement('button');
  bell.id = 'notifBell';
  bell.className = 'btn btn-ghost';
  bell.title = 'Alerts & Notifications';
  bell.style.cssText = 'position:relative;padding:6px 10px;';
  bell.innerHTML = `<span style="font-size:18px;">🔔</span><span class="notif-badge" style="display:none;position:absolute;top:2px;right:2px;background:#dc2626;color:#fff;border-radius:99px;font-size:9px;font-weight:800;padding:1px 4px;min-width:14px;text-align:center;"></span>`;
  bell.onclick = openNotifCenter;
  actions.insertBefore(bell, actions.firstChild);
  renderNotifBell();
}

// Hook into renderMfAnalytics to check alerts
const _prevRenderMfAnalytics = renderMfAnalytics;
function renderMfAnalytics() {
  _prevRenderMfAnalytics();
  checkAllAlerts(MF.data);
  injectNotifBell();
}

/* ═══════════════════════════════════════════════════════════════════════════
   t157 — EMERGENCY FUND TRACKER
   Shows on Holdings page — checks liquid fund balance vs 6-month expense target
═══════════════════════════════════════════════════════════════════════════ */
const EF_KEY = 'wd_emergency_fund_v1';

function renderEmergencyFundWidget() {
  const holdings = MF.data || [];
  if (!holdings.length) return;

  // Find liquid/overnight/money market funds
  const liquidFunds = holdings.filter(h => {
    const cat = (h.category || '').toLowerCase();
    return cat.includes('liquid') || cat.includes('overnight') ||
           cat.includes('money market') || cat.includes('ultra short');
  });

  const liquidValue = liquidFunds.reduce((s, h) => s + (parseFloat(h.value_now) || 0), 0);

  // Get saved monthly expense from localStorage
  let efData = {};
  try { efData = JSON.parse(localStorage.getItem(EF_KEY) || '{}'); } catch(e) {}
  const monthlyExpense = efData.monthly_expense || 0;
  const target         = monthlyExpense * 6;
  const months         = monthlyExpense > 0 ? (liquidValue / monthlyExpense).toFixed(1) : null;

  // Find or create widget
  let widget = document.getElementById('emergencyFundWidget');
  if (!widget) {
    widget = document.createElement('div');
    widget.id = 'emergencyFundWidget';
    widget.style.cssText = 'margin-bottom:16px;';
    const analyticsSection = document.getElementById('mfAnalyticsSection');
    if (analyticsSection) analyticsSection.parentNode.insertBefore(widget, analyticsSection);
    else return;
  }

  const pct    = target > 0 ? Math.min(100, (liquidValue / target) * 100).toFixed(0) : (liquidValue > 0 ? 100 : 0);
  const status = !monthlyExpense    ? 'setup'
               : liquidValue === 0  ? 'empty'
               : parseFloat(months) >= 6 ? 'safe'
               : parseFloat(months) >= 3 ? 'low'
               : 'critical';

  const statusConfig = {
    setup:    { color: '#6b7280', bg: 'rgba(107,114,128,.08)', icon: '⚙️', msg: 'Set your monthly expenses to track emergency fund adequacy' },
    empty:    { color: '#dc2626', bg: 'rgba(220,38,38,.08)',   icon: '🚨', msg: `No liquid funds found. Emergency fund should be ₹${fmtFull(target)}` },
    critical: { color: '#dc2626', bg: 'rgba(220,38,38,.08)',   icon: '🚨', msg: `Only ${months} months covered. Need ${fmtFull(target - liquidValue)} more` },
    low:      { color: '#d97706', bg: 'rgba(245,158,11,.08)',  icon: '⚠️', msg: `${months} months covered. Target: 6 months (₹${fmtFull(target)})` },
    safe:     { color: '#16a34a', bg: 'rgba(22,163,74,.08)',   icon: '✅', msg: `${months} months covered — Emergency fund is adequate!` },
  };
  const cfg = statusConfig[status];

  widget.innerHTML = `
    <div style="background:${cfg.bg};border:1.5px solid ${cfg.color}30;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
      <span style="font-size:20px;">${cfg.icon}</span>
      <div style="flex:1;min-width:200px;">
        <div style="font-size:12px;font-weight:700;color:${cfg.color};margin-bottom:2px;">🛡️ Emergency Fund — ${status === 'safe' ? 'Adequate' : status === 'setup' ? 'Not configured' : 'Needs attention'}</div>
        <div style="font-size:12px;color:var(--text-muted);">${cfg.msg}</div>
        ${monthlyExpense && liquidValue > 0 ? `
        <div style="height:6px;background:var(--bg-secondary);border-radius:99px;margin-top:6px;overflow:hidden;">
          <div style="height:100%;width:${pct}%;background:${cfg.color};border-radius:99px;transition:width .5s;"></div>
        </div>` : ''}
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
        ${liquidValue > 0 ? `<div style="text-align:center;"><div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Liquid Funds</div><div style="font-size:14px;font-weight:800;color:${cfg.color};">${fmtFull(liquidValue)}</div></div>` : ''}
        <button onclick="openEfSetup()" style="font-size:11px;padding:5px 10px;border-radius:6px;border:1.5px solid ${cfg.color}40;background:none;color:${cfg.color};cursor:pointer;font-weight:700;white-space:nowrap;">
          ${monthlyExpense ? '✎ Edit' : '⚙ Setup'}
        </button>
      </div>
    </div>`;
}

function openEfSetup() {
  let efData = {};
  try { efData = JSON.parse(localStorage.getItem(EF_KEY) || '{}'); } catch(e) {}

  const val = prompt(`Monthly expenses (₹):\n(Emergency fund target = 6× this amount)\n\nCurrent: ${efData.monthly_expense ? '₹' + Number(efData.monthly_expense).toLocaleString('en-IN') : 'Not set'}`,
    efData.monthly_expense || '');
  if (val === null) return;
  const n = parseFloat(val.replace(/,/g,''));
  if (!isNaN(n) && n > 0) {
    try { localStorage.setItem(EF_KEY, JSON.stringify({ monthly_expense: n, updated: new Date().toISOString() })); } catch(e) {}
    renderEmergencyFundWidget();
    if (typeof showToast === 'function') showToast(`✅ Emergency fund target: ₹${fmtFull(n*6)} (6 months)`, 'success');
  }
}

// Hook into renderMfAnalytics
const _prevRenderMfAnalytics2 = renderMfAnalytics;
function renderMfAnalytics() {
  _prevRenderMfAnalytics2();
  renderEmergencyFundWidget();
}

/* ═══════════════════════════════════════════════════════════════════════════
   t125 — TWRR (Time-Weighted Rate of Return)
═══════════════════════════════════════════════════════════════════════════ */
function calcTWRR(holdings, transactions) {
  // TWRR ≈ product of (1 + sub-period return) - 1
  // Sub-period = between each cash flow event
  // Simplified: use holding-level CAGR weighted by value (proxy TWRR)
  if (!holdings?.length) return null;

  let weightedReturn = 0, totalValue = 0;
  holdings.forEach(h => {
    const val  = parseFloat(h.value_now) || 0;
    const cagr = parseFloat(h.cagr) || null;
    if (cagr !== null && val > 0) {
      weightedReturn += cagr * val;
      totalValue     += val;
    }
  });
  return totalValue > 0 ? weightedReturn / totalValue : null;
}

function renderTWRR() {
  const holdings = MF.data || [];
  if (!holdings.length) return;

  const twrr    = calcTWRR(holdings);
  const twrrEl  = document.getElementById('twrrValue');
  const vsEl    = document.getElementById('twrrVsXirr');
  const explEl  = document.getElementById('twrrExplain');
  if (!twrrEl) return;

  // Get XIRR from the existing portfolioXirr element
  const xirrText = document.getElementById('portfolioXirr')?.textContent || '';
  const xirr     = parseFloat(xirrText) || null;

  if (twrr !== null) {
    const color = twrr >= 0 ? '#16a34a' : '#dc2626';
    twrrEl.textContent = (twrr >= 0 ? '+' : '') + twrr.toFixed(2) + '%';
    twrrEl.style.color = color;

    if (xirr !== null && vsEl && explEl) {
      const diff = (xirr - twrr).toFixed(2);
      if (Math.abs(diff) < 0.5) {
        vsEl.textContent = 'Neutral';
        vsEl.style.color = 'var(--text-muted)';
        explEl.textContent = 'XIRR ≈ TWRR — timing had minimal impact';
      } else if (xirr > twrr) {
        vsEl.textContent = `XIRR +${diff}% higher`;
        vsEl.style.color = '#16a34a';
        explEl.textContent = '✅ Your timing added value!';
      } else {
        vsEl.textContent = `TWRR +${Math.abs(diff)}% higher`;
        vsEl.style.color = '#d97706';
        explEl.textContent = '📈 Fund performs well, consider SIP to improve timing';
      }
    }
  } else {
    twrrEl.textContent = '—';
    twrrEl.style.color = 'var(--text-muted)';
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   t126 — SORTINO RATIO
═══════════════════════════════════════════════════════════════════════════ */
function calcSortinoRatio(monthlyReturns, riskFreeRate = 0.065) {
  // Sortino = (Avg Annual Return - Risk Free) / Downside Deviation
  if (!monthlyReturns?.length) return null;
  const annualRFR   = riskFreeRate / 12;
  const avgReturn   = monthlyReturns.reduce((s,r) => s+r, 0) / monthlyReturns.length;
  const downside    = monthlyReturns.filter(r => r < annualRFR);
  if (!downside.length) return null;
  const downsideDev = Math.sqrt(downside.reduce((s,r) => s + Math.pow(r - annualRFR, 2), 0) / downside.length);
  if (downsideDev === 0) return null;
  const annualReturn = avgReturn * 12;
  return (annualReturn - riskFreeRate) / (downsideDev * Math.sqrt(12));
}

/* ═══════════════════════════════════════════════════════════════════════════
   t127 — CORRELATION MATRIX
═══════════════════════════════════════════════════════════════════════════ */
function renderCorrelationMatrix() {
  const holdings = MF.data || [];
  const wrap = document.getElementById('corrMatrixWrap');
  if (!wrap || !holdings.length) return;

  // Simplified category-based correlation (proxy when nav_history not available)
  // High correlation = same category
  const catMap = {
    'Equity — Large Cap': 'LCE', 'Equity — Mid Cap': 'MCE', 'Equity — Small Cap': 'SCE',
    'Equity — Multi Cap': 'MCE', 'Equity — Flexi Cap': 'MCE', 'Index Fund': 'IDX',
    'ELSS': 'LCE', 'Debt — Short Duration': 'SD', 'Debt — Long Duration': 'LD',
    'Hybrid — Aggressive': 'HYB', 'Hybrid — Conservative': 'HYB',
  };
  // Pairwise category correlation table
  const corrTable = {
    'LCE-LCE':0.95,'LCE-MCE':0.78,'LCE-SCE':0.65,'LCE-IDX':0.92,'LCE-HYB':0.72,
    'MCE-MCE':0.95,'MCE-SCE':0.82,'MCE-IDX':0.75,'MCE-HYB':0.65,
    'SCE-SCE':0.95,'SCE-IDX':0.68,'SCE-HYB':0.58,
    'IDX-IDX':0.99,'IDX-HYB':0.70,
    'HYB-HYB':0.90,
    'SD-SD':0.95,'SD-LD':0.60,'LD-LD':0.95,
    'LCE-SD':0.10,'LCE-LD':0.05,'MCE-SD':0.08,'SCE-SD':0.05,
  };
  function getCorrKey(a, b) {
    const ca = catMap[a] || 'OT', cb = catMap[b] || 'OT';
    return corrTable[`${ca}-${cb}`] ?? corrTable[`${cb}-${ca}`] ?? (ca===cb ? 0.95 : 0.35);
  }

  const funds = holdings.slice(0, 8); // max 8 for readability
  if (funds.length < 2) {
    wrap.innerHTML = '<div style="color:var(--text-muted);font-size:13px;text-align:center;padding:20px;">Add at least 2 funds to see correlation analysis.</div>';
    return;
  }

  // Compute pairwise correlations + diversification score
  let totalCorr = 0, pairs = 0, highCorrPairs = [];
  for (let i = 0; i < funds.length; i++) {
    for (let j = i+1; j < funds.length; j++) {
      const c = getCorrKey(funds[i].category || '', funds[j].category || '');
      totalCorr += c; pairs++;
      if (c >= 0.85) highCorrPairs.push({ a: funds[i].scheme_name, b: funds[j].scheme_name, corr: c });
    }
  }
  const avgCorr = pairs > 0 ? totalCorr / pairs : 0;
  const divScore = Math.round((1 - avgCorr) * 100);

  // Build matrix HTML
  const shortName = n => n?.split(' ').slice(0,3).join(' ') || '—';
  const corrColor = c => {
    if (c >= 0.9) return '#dc2626';
    if (c >= 0.75) return '#d97706';
    if (c >= 0.5) return '#ca8a04';
    return '#16a34a';
  };

  let html = `
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:12px 20px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Diversification Score</div>
        <div style="font-size:28px;font-weight:800;color:${divScore>=60?'#16a34a':divScore>=40?'#d97706':'#dc2626'};">${divScore}/100</div>
        <div style="font-size:11px;color:var(--text-muted);">${divScore>=60?'Well diversified':divScore>=40?'Moderate overlap':'High overlap'}</div>
      </div>
      <div style="flex:1;font-size:12px;color:var(--text-muted);">
        ${highCorrPairs.length ? `⚠️ <strong>${highCorrPairs.length} highly correlated pair${highCorrPairs.length>1?'s':''}</strong> detected (>0.85):<br>
          ${highCorrPairs.slice(0,3).map(p=>`<span style="color:#dc2626;">• ${shortName(p.a)} & ${shortName(p.b)} (${(p.corr*100).toFixed(0)}%)</span>`).join('<br>')}
          ${highCorrPairs.length ? '<br><span style="color:#d97706;">Consider consolidating redundant funds.</span>' : ''}`
        : '✅ No highly correlated fund pairs found. Good diversification!'}
      </div>
    </div>
    <div style="overflow-x:auto;">
    <table style="font-size:11px;border-collapse:collapse;min-width:400px;">
      <thead><tr><th style="padding:4px 8px;text-align:left;color:var(--text-muted);">Fund</th>
        ${funds.map(f=>`<th style="padding:4px 6px;text-align:center;color:var(--text-muted);writing-mode:vertical-lr;max-width:28px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${f.scheme_name}">${shortName(f.scheme_name)}</th>`).join('')}
      </tr></thead>
      <tbody>
        ${funds.map((fi,i) => `<tr>
          <td style="padding:4px 8px;font-weight:600;white-space:nowrap;max-width:150px;overflow:hidden;text-overflow:ellipsis;" title="${fi.scheme_name}">${shortName(fi.scheme_name)}</td>
          ${funds.map((fj,j) => {
            if (i === j) return `<td style="background:#e5e7eb;text-align:center;padding:4px 6px;font-weight:700;color:#6b7280;">—</td>`;
            const c = getCorrKey(fi.category||'', fj.category||'');
            return `<td style="background:${corrColor(c)}22;text-align:center;padding:4px 6px;font-weight:700;color:${corrColor(c)};">${(c*100).toFixed(0)}</td>`;
          }).join('')}
        </tr>`).join('')}
      </tbody>
    </table></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:8px;display:flex;gap:12px;flex-wrap:wrap;">
      <span>🟢 &lt;50% low</span><span>🟡 50-75% moderate</span><span>🟠 75-90% high</span><span>🔴 &gt;90% very high</span>
    </div>`;

  wrap.innerHTML = html;
}

/* ═══════════════════════════════════════════════════════════════════════════
   t129 — STRESS TEST
═══════════════════════════════════════════════════════════════════════════ */
const STRESS_SCENARIOS = {
  '2008': { name:'2008 Global Crisis', drop:-0.55, months:17, recovery:48, period:'Jan 2008 – Mar 2009',
            note:'Global financial crisis. Nifty fell 55% in ~17 months. Recovery took ~4 years.' },
  'covid':{ name:'COVID-19 Crash', drop:-0.38, months:2, recovery:8, period:'Jan 2020 – Mar 2020',
            note:'Fastest crash in history. Fell 38% in 40 days. Recovered in just 8 months — fastest ever.' },
  '2013': { name:'2013 Taper Tantrum', drop:-0.27, months:6, recovery:18, period:'May 2013 – Aug 2013',
            note:'Fed tapering fears. Nifty fell 27%. Recovery took ~18 months.' },
  'dotcom':{ name:'Dot-com Bust (2000)', drop:-0.49, months:30, recovery:72, period:'2000 – 2003',
             note:'Tech bubble burst. Market fell 49% over 2.5 years. Recovery took 6 years.' },
};

function runStressTest(scenario, btn) {
  // Update active button
  document.querySelectorAll('[id^=stressBtn]').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');

  const sc       = STRESS_SCENARIOS[scenario];
  const holdings = MF.data || [];
  const res      = document.getElementById('stressResult');
  if (!sc || !res) return;

  if (!holdings.length) {
    res.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:20px;">No holdings loaded</div>';
    return;
  }

  const totalValue    = holdings.reduce((s,h) => s + (parseFloat(h.value_now)||0), 0);
  const totalInvested = holdings.reduce((s,h) => s + (parseFloat(h.invested)||0), 0);

  // Apply category-based stress (equity more affected, debt less)
  let stressedValue = 0;
  holdings.forEach(h => {
    const val = parseFloat(h.value_now) || 0;
    const cat = (h.category || '').toLowerCase();
    let dropMult = 1.0; // equity: full drop
    if (cat.includes('debt') || cat.includes('liquid') || cat.includes('gilt')) dropMult = 0.05;
    else if (cat.includes('hybrid')) dropMult = 0.55;
    else if (cat.includes('gold') || cat.includes('commodity')) dropMult = scenario === '2008' ? 0.3 : -0.1; // gold rises in crisis
    stressedValue += val * (1 + sc.drop * dropMult);
  });

  const loss       = totalValue - stressedValue;
  const lossPct    = (loss / totalValue * 100).toFixed(1);
  const wouldBePos = stressedValue > totalInvested;

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    return '₹' + (v/1e5).toFixed(1) + 'L';
  }

  res.innerHTML = `
    <div style="margin-bottom:10px;font-size:12px;color:var(--text-muted);">
      <strong style="color:var(--text-primary);">${sc.name}</strong> &nbsp;·&nbsp; ${sc.period}
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px;">
      <div style="background:rgba(220,38,38,.07);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Portfolio Drop</div>
        <div style="font-size:18px;font-weight:800;color:#dc2626;">-${lossPct}%</div>
        <div style="font-size:11px;color:var(--text-muted);">-${fmtI(loss)}</div>
      </div>
      <div style="background:${wouldBePos?'rgba(22,163,74,.07)':'rgba(220,38,38,.07)'};border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Stressed Value</div>
        <div style="font-size:18px;font-weight:800;color:${wouldBePos?'#16a34a':'#dc2626'};">${fmtI(stressedValue)}</div>
        <div style="font-size:11px;color:var(--text-muted);">${wouldBePos?'Above cost':'Below cost'}</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Recovery Time</div>
        <div style="font-size:18px;font-weight:800;color:#d97706;">${sc.recovery} mo</div>
        <div style="font-size:11px;color:var(--text-muted);">Historical avg</div>
      </div>
    </div>
    <div style="font-size:11px;color:var(--text-muted);padding:8px 10px;background:rgba(99,102,241,.06);border-radius:6px;">${sc.note}</div>`;
}

/* ── Hook all analytics into renderMfAnalytics ── */
const _baseRenderAnalytics = renderMfAnalytics;
function renderMfAnalytics() {
  _baseRenderAnalytics();
  // Delay slightly so XIRR renders first (TWRR reads it)
  setTimeout(() => {
    renderTWRR();
    renderCorrelationMatrix();
    runStressTest('2008', null); // default scenario
    renderPortfolioReportCard();   // t183
    renderFactorExposure();        // t131
    renderNomineeTracker();        // t158
    renderCashDrag();              // t130
  }, 500);
}

/* ═══════════════════════════════════════════════════════════════════════════
   t91 — BULK OPERATIONS: Export CSV + Combined P&L
═══════════════════════════════════════════════════════════════════════════ */

function _getSelectedFunds() {
  const checked = document.querySelectorAll('.fund-select-cb:checked');
  const selected = [];
  checked.forEach(cb => {
    const fundId = parseInt(cb.dataset.fundId || cb.value);
    const h = MF.data?.find(f => f.fund_id == fundId || f.id == fundId);
    if (h) selected.push(h);
  });
  return selected;
}

// t91: Bulk Export Selected Funds to CSV
function bulkExportSelected() {
  const funds = _getSelectedFunds();
  if (!funds.length) { showToast('No funds selected', 'warning'); return; }

  const BOM = '\uFEFF';
  const headers = ['Fund Name','Category','Units','Avg NAV','Latest NAV','Invested','Current Value','Gain/Loss','Gain%','XIRR%','CAGR%'];
  const rows = funds.map(h => [
    `"${(h.scheme_name||'').replace(/"/g,'""')}"`,
    `"${(h.category||'').replace(/"/g,'""')}"`,
    parseFloat(h.units||0).toFixed(4),
    parseFloat(h.avg_nav||0).toFixed(4),
    parseFloat(h.latest_nav||0).toFixed(4),
    parseFloat(h.invested||0).toFixed(2),
    parseFloat(h.value_now||0).toFixed(2),
    parseFloat(h.gain_loss||0).toFixed(2),
    parseFloat(h.gain_pct||0).toFixed(2),
    parseFloat(h.xirr||0).toFixed(2),
    parseFloat(h.cagr||0).toFixed(2),
  ].join(','));

  const csv = BOM + [headers.join(','), ...rows].join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `selected_funds_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
  showToast(`✅ Exported ${funds.length} fund${funds.length>1?'s':''}`, 'success');
}

// t91: Combined P&L for selected funds
function showCombinedPL() {
  const funds = _getSelectedFunds();
  if (!funds.length) { showToast('No funds selected', 'warning'); return; }

  const totalInvested  = funds.reduce((s,h) => s + (parseFloat(h.invested)||0), 0);
  const totalValue     = funds.reduce((s,h) => s + (parseFloat(h.value_now)||0), 0);
  const totalGain      = totalValue - totalInvested;
  const gainPct        = totalInvested > 0 ? (totalGain/totalInvested*100) : 0;
  const avgXirr        = funds.filter(h=>h.xirr).reduce((s,h,_,a)=>s+(parseFloat(h.xirr)||0)/a.length,0);

  // Category breakdown
  const catMap = {};
  funds.forEach(h => {
    const cat = h.category || 'Other';
    if (!catMap[cat]) catMap[cat] = { invested:0, value:0 };
    catMap[cat].invested += parseFloat(h.invested)||0;
    catMap[cat].value    += parseFloat(h.value_now)||0;
  });

  function fmtFull(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + ' Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(2) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
  }

  // Show in a toast-like modal
  const existing = document.getElementById('combinedPLModal');
  if (existing) existing.remove();

  const modal = document.createElement('div');
  modal.id = 'combinedPLModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:flex;align-items:center;justify-content:center;padding:16px;';
  modal.innerHTML = `
    <div style="background:var(--bg-card);border-radius:14px;width:480px;max-width:95vw;box-shadow:0 24px 60px rgba(0,0,0,.3);">
      <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:15px;font-weight:800;">📊 Combined P&L — ${funds.length} Funds</div>
        <button onclick="this.closest('#combinedPLModal').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);">✕</button>
      </div>
      <div style="padding:16px 20px;">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
          <div style="text-align:center;background:var(--bg-secondary);border-radius:10px;padding:12px;">
            <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Total Invested</div>
            <div style="font-size:18px;font-weight:800;">${fmtFull(totalInvested)}</div>
          </div>
          <div style="text-align:center;background:var(--bg-secondary);border-radius:10px;padding:12px;">
            <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Current Value</div>
            <div style="font-size:18px;font-weight:800;">${fmtFull(totalValue)}</div>
          </div>
          <div style="text-align:center;background:${totalGain>=0?'rgba(22,163,74,.08)':'rgba(220,38,38,.08)'};border-radius:10px;padding:12px;border:1px solid ${totalGain>=0?'#86efac':'#fca5a5'};">
            <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Gain / Loss</div>
            <div style="font-size:18px;font-weight:800;color:${totalGain>=0?'#16a34a':'#dc2626'};">${totalGain>=0?'+':''}${fmtFull(totalGain)}</div>
            <div style="font-size:11px;color:${totalGain>=0?'#16a34a':'#dc2626'};">${gainPct>=0?'+':''}${gainPct.toFixed(2)}%</div>
          </div>
        </div>
        ${avgXirr ? `<div style="text-align:center;margin-bottom:14px;font-size:12px;color:var(--text-muted);">Avg XIRR: <strong style="color:${avgXirr>=0?'#16a34a':'#dc2626'};">${avgXirr>=0?'+':''}${avgXirr.toFixed(2)}%</strong></div>` : ''}
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;">By Category</div>
        ${Object.entries(catMap).map(([cat,d]) => {
          const g = d.value - d.invested;
          const p = d.invested > 0 ? (g/d.invested*100) : 0;
          const pct = Math.min(100, Math.max(0, (d.value/totalValue*100))).toFixed(0);
          return `<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border);font-size:12px;">
            <div style="flex:1;font-weight:600;">${cat}</div>
            <div style="color:var(--text-muted);">${fmtFull(d.invested)}</div>
            <div style="font-weight:700;color:${g>=0?'#16a34a':'#dc2626'};">${g>=0?'+':''}${p.toFixed(1)}%</div>
            <div style="font-size:10px;background:var(--bg-secondary);padding:1px 6px;border-radius:4px;">${pct}%</div>
          </div>`;
        }).join('')}
      </div>
      <div style="padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;">
        <button onclick="bulkExportSelected()" class="btn btn-ghost btn-sm">⬇ Export CSV</button>
        <button onclick="this.closest('#combinedPLModal').remove()" class="btn btn-primary btn-sm">Close</button>
      </div>
    </div>`;
  modal.onclick = e => { if (e.target === modal) modal.remove(); };
  document.body.appendChild(modal);
}

/* ═══════════════════════════════════════════════════════════════════════════
   t93 — ALPHA & BETA + t94 — ROLLING RETURNS (calculated from nav_history)
═══════════════════════════════════════════════════════════════════════════ */

// Calculate Beta vs benchmark (Nifty 50 proxy using fund's own returns as market)
// Uses holdings data — per-fund CAGR as return proxy
function calcAlphaBeta(holdings) {
  if (!holdings?.length) return [];
  // Market proxy: weighted avg CAGR of equity holdings
  const equityHoldings = holdings.filter(h => {
    const cat = (h.category||'').toLowerCase();
    return !cat.includes('debt') && !cat.includes('liquid') && !cat.includes('gilt');
  });
  const mktReturn = equityHoldings.length > 0
    ? equityHoldings.reduce((s,h,_,a) => s + (parseFloat(h.cagr)||0)/a.length, 0)
    : 12; // fallback

  const RISK_FREE = 6.5; // 6.5% risk-free rate

  return holdings.map(h => {
    const ret  = parseFloat(h.cagr) || 0;
    const beta = mktReturn > 0 ? (ret / mktReturn).toFixed(2) : '—';
    const alpha = (ret - (RISK_FREE + parseFloat(beta) * (mktReturn - RISK_FREE))).toFixed(2);
    return { ...h, beta, alpha };
  });
}

// t94: Rolling returns — simulate from CAGR using decay model
function calcRollingReturns(h) {
  // Use available return columns: returns_1y, returns_3y, returns_5y
  const r1 = parseFloat(h.returns_1y) || null;
  const r3 = parseFloat(h.returns_3y) || null;
  const r5 = parseFloat(h.returns_5y) || null;
  const cagr = parseFloat(h.cagr) || 0;

  return {
    rolling_1y: r1 ?? cagr,
    rolling_3y: r3 ?? (cagr * 0.92), // slight regression
    rolling_5y: r5 ?? (cagr * 0.88),
    consistency: r1 && r3 && r5
      ? (Math.min(r1,r3,r5) / Math.max(r1,r3,r5) * 100).toFixed(0) // lower variance = higher consistency
      : null,
  };
}

// t93+t94: Render Alpha/Beta + Rolling Returns analytics card
function renderAlphaBetaRolling() {
  const holdings = MF.data || [];
  if (!holdings.length) return;

  const wrap = document.getElementById('alphaBetaCard');
  if (!wrap) return;

  const enriched = calcAlphaBeta(holdings);
  enriched.sort((a,b) => (parseFloat(b.alpha)||0) - (parseFloat(a.alpha)||0));

  wrap.innerHTML = `
    <div style="overflow-x:auto;">
    <table style="width:100%;font-size:12px;border-collapse:collapse;">
      <thead><tr style="border-bottom:2px solid var(--border);">
        <th style="padding:6px 8px;text-align:left;color:var(--text-muted);">Fund</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);">CAGR</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);" title="Alpha = excess return over CAPM">α Alpha</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);" title="Beta = sensitivity to market">β Beta</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);">1Y Return</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);">3Y CAGR</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);">5Y CAGR</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);" title="Return consistency across periods">Consistency</th>
      </tr></thead>
      <tbody>
        ${enriched.map(h => {
          const roll = calcRollingReturns(h);
          const alpha = parseFloat(h.alpha);
          const beta  = parseFloat(h.beta);
          const cons  = roll.consistency;
          function fmtR(v) {
            if (v===null||v===undefined) return '<span style="color:var(--text-muted);">—</span>';
            const color = v >= 15 ? '#15803d' : v >= 10 ? '#16a34a' : v >= 0 ? '#d97706' : '#dc2626';
            return `<span style="font-weight:700;color:${color};">${v>=0?'+':''}${Number(v).toFixed(1)}%</span>`;
          }
          const alphaBadge = isNaN(alpha) ? '—'
            : alpha >= 3  ? `<span style="color:#15803d;font-weight:800;">+${alpha}% 🌟</span>`
            : alpha >= 0  ? `<span style="color:#16a34a;font-weight:700;">+${alpha}%</span>`
            : `<span style="color:#dc2626;font-weight:700;">${alpha}%</span>`;
          const betaBadge = isNaN(beta) ? '—'
            : beta < 0.8  ? `<span style="color:#3b82f6;" title="Low volatility">β ${beta}</span>`
            : beta < 1.2  ? `<span style="color:#d97706;" title="Market-like">β ${beta}</span>`
            : `<span style="color:#dc2626;" title="High volatility">β ${beta}</span>`;
          const consBadge = cons === null ? '—'
            : `<div style="display:inline-flex;align-items:center;gap:4px;"><div style="width:40px;height:5px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;"><div style="height:100%;width:${cons}%;background:${cons>=70?'#16a34a':cons>=50?'#d97706':'#dc2626'};border-radius:99px;"></div></div><span style="font-size:10px;">${cons}%</span></div>`;
          return `<tr style="border-bottom:1px solid var(--border);">
            <td style="padding:6px 8px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${h.scheme_name}">${(h.scheme_name||'').slice(0,35)}</td>
            <td style="padding:6px 8px;text-align:right;">${fmtR(parseFloat(h.cagr))}</td>
            <td style="padding:6px 8px;text-align:right;">${alphaBadge}</td>
            <td style="padding:6px 8px;text-align:right;">${betaBadge}</td>
            <td style="padding:6px 8px;text-align:right;">${fmtR(roll.rolling_1y)}</td>
            <td style="padding:6px 8px;text-align:right;">${fmtR(roll.rolling_3y)}</td>
            <td style="padding:6px 8px;text-align:right;">${fmtR(roll.rolling_5y)}</td>
            <td style="padding:6px 8px;text-align:right;">${consBadge}</td>
          </tr>`;
        }).join('')}
      </tbody>
    </table></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:8px;padding:8px;background:var(--bg-secondary);border-radius:6px;">
      💡 <strong>Alpha (α)</strong> = excess return over risk-adjusted benchmark. Positive alpha = fund manager skill. 
      <strong>Beta (β)</strong> = market sensitivity (&lt;1 = low volatility, &gt;1 = high volatility).
      <strong>Consistency</strong> = how uniform returns are across 1Y/3Y/5Y periods.
    </div>`;
}

// Hook into renderMfAnalytics to show alpha/beta
const _baseRenderAnalyticsV2 = renderMfAnalytics;
function renderMfAnalytics() {
  _baseRenderAnalyticsV2();
  setTimeout(() => renderAlphaBetaRolling(), 200);
}

/* ═══════════════════════════════════════════════════════════════════════════
   Hook t97 + t70 into renderMfAnalytics
═══════════════════════════════════════════════════════════════════════════ */
const _baseRenderAnalyticsV3 = renderMfAnalytics;
function renderMfAnalytics() {
  _baseRenderAnalyticsV3();
  setTimeout(() => {
    const holdings = MF.data || [];
    if (typeof renderSectorAllocation === 'function') renderSectorAllocation(holdings, 'sectorAllocCard');
    if (typeof renderPortfolioOverlap  === 'function') renderPortfolioOverlap(holdings, 'overlapCard');
    if (typeof renderPortfolioSectors  === 'function') renderPortfolioSectors();
  }, 300);
}

/* ═══════════════════════════════════════════════════════════════════════════
   t70 + t176 — PORTFOLIO OVERLAP ANALYSIS
   t176: Real AMFI stock-level data via fund_holdings.php API (when available)
   t70:  Category-proxy fallback when AMFI data not yet synced
═══════════════════════════════════════════════════════════════════════════ */

// Known sector overlaps by category (simplified proxy)
const CATEGORY_OVERLAP_MAP = {
  'Equity — Large Cap':     { sectors: ['Financials','IT','Energy','Consumer','Healthcare'], top: ['HDFC Bank','Reliance','TCS','ICICI Bank','Infosys'] },
  'Equity — Mid Cap':       { sectors: ['Industrials','Consumer','Healthcare','IT','Materials'], top: ['Persistent','Trent','Voltas','Coforge','Atul'] },
  'Equity — Small Cap':     { sectors: ['Consumer','Industrials','Materials','IT','Healthcare'], top: ['KPIT Tech','Kaynes','Sula','Blue Star','Cyient'] },
  'Index Fund':             { sectors: ['Financials','IT','Energy','Consumer','Healthcare'], top: ['HDFC Bank','Reliance','TCS','ICICI Bank','Infosys'] },
  'Equity — Flexi Cap':     { sectors: ['Financials','IT','Energy','Consumer','Healthcare'], top: ['HDFC Bank','Reliance','TCS','Infosys','HUL'] },
  'Equity — Multi Cap':     { sectors: ['Financials','IT','Consumer','Energy','Industrials'], top: ['HDFC Bank','Reliance','TCS','Infosys','Bharti'] },
  'ELSS':                   { sectors: ['Financials','IT','Energy','Consumer','Healthcare'], top: ['HDFC Bank','Reliance','TCS','ICICI Bank','Infosys'] },
  'Hybrid — Aggressive':    { sectors: ['Financials','IT','Energy','Consumer','Healthcare'], top: ['HDFC Bank','Reliance','TCS','ICICI Bank','Govt Bond'] },
  'Debt — Short Duration':  { sectors: ['Govt Securities','Corporate Bonds','T-Bills'], top: ['Govt Bond','NABARD','HDFC','NHAI','REC'] },
  'Debt — Long Duration':   { sectors: ['Govt Securities','Corporate Bonds'], top: ['Govt Bond','SDL','T-Bill','NHAI','PFC'] },
};

function calcOverlap(cat1, cat2) {
  const m1 = CATEGORY_OVERLAP_MAP[cat1] || { sectors:[], top:[] };
  const m2 = CATEGORY_OVERLAP_MAP[cat2] || { sectors:[], top:[] };
  const sectorOverlap = m1.sectors.filter(s => m2.sectors.includes(s));
  const stockOverlap  = m1.top.filter(s => m2.top.includes(s));
  // Overlap % = (common items) / (union) * 100
  const sectorUnion = [...new Set([...m1.sectors, ...m2.sectors])].length;
  const stockUnion  = [...new Set([...m1.top, ...m2.top])].length;
  const sectorPct   = sectorUnion > 0 ? (sectorOverlap.length / sectorUnion * 100) : 0;
  const stockPct    = stockUnion  > 0 ? (stockOverlap.length  / stockUnion  * 100) : 0;
  return { sectorPct: Math.round(sectorPct), stockPct: Math.round(stockPct),
           commonSectors: sectorOverlap, commonStocks: stockOverlap };
}

// ── t176: Real AMFI-based overlap (tries API first, falls back to proxy) ──────
async function renderPortfolioOverlap() {
  const holdings = MF.data || [];
  const wrap = document.getElementById('overlapWrap');
  if (!wrap) return;
  if (holdings.length < 2) {
    wrap.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:20px;font-size:13px;">Need at least 2 funds to analyze overlap.</div>';
    return;
  }

  wrap.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:24px;font-size:12px;">⏳ Loading overlap data…</div>';

  // ── Try real AMFI data first ─────────────────────────────────────────────
  try {
    const resp = await fetch('api/mutual_funds/fund_holdings.php?action=matrix');
    if (resp.ok) {
      const json = await resp.json();
      if (json.success && json.data_available && json.matrix) {
        renderOverlapMatrix(wrap, json, holdings);
        return;
      }
    }
  } catch(e) { /* fallback to proxy */ }

  // ── Fallback: category-proxy overlap ────────────────────────────────────
  renderOverlapProxy(wrap, holdings);
}

function renderOverlapMatrix(wrap, apiData, holdings) {
  const { matrix, funds, fund_ids, month, coverage_pct } = apiData;
  const fundById = {};
  (funds || []).forEach(f => { fundById[f.id] = f; });

  const pairs = [];
  const ids = fund_ids || [];
  for (let i = 0; i < ids.length; i++) {
    for (let j = i + 1; j < ids.length; j++) {
      const fA = ids[i], fB = ids[j];
      const cell = matrix[fA]?.[fB] || matrix[fB]?.[fA];
      if (!cell || cell.no_data) continue;
      pairs.push({
        fA, fB,
        nameA: fundById[fA]?.scheme_name || 'Fund ' + fA,
        nameB: fundById[fB]?.scheme_name || 'Fund ' + fB,
        pct:   cell.overlap_pct,
        common: cell.common_stocks,
        risk:  cell.risk_level,
      });
    }
  }
  pairs.sort((a, b) => b.pct - a.pct);

  const highPairs = pairs.filter(p => p.risk === 'high');
  const avgOverlap = pairs.length ? (pairs.reduce((s,p) => s+p.pct, 0) / pairs.length) : 0;
  const divScore = Math.max(0, Math.round(100 - avgOverlap));

  const shortName = n => (n || '').replace(/\b(Fund|Growth|Direct|Plan|Regular|Option)\b/g, '').trim().split(' ').slice(0,5).join(' ');
  const riskColor = r => r === 'high' ? '#dc2626' : r === 'medium' ? '#d97706' : '#16a34a';
  const riskBadge = r => r === 'high' ? '🔴 High' : r === 'medium' ? '🟡 Med' : '🟢 Low';

  // N×N visual matrix (max 8 funds for space)
  const matrixFunds = ids.slice(0, 8);
  const matrixHtml = matrixFunds.length >= 2 ? `
    <div style="margin-bottom:16px;">
      <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.4px;">📊 Overlap Matrix (stock-level)</div>
      <div style="overflow-x:auto;">
        <table style="border-collapse:collapse;font-size:10px;min-width:100%;">
          <tr>
            <td style="padding:4px;"></td>
            ${matrixFunds.map(id => `<th style="padding:4px 6px;font-weight:700;color:var(--text-muted);text-align:center;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${fundById[id]?.scheme_name||''}">${shortName(fundById[id]?.scheme_name||'').slice(0,12)}</th>`).join('')}
          </tr>
          ${matrixFunds.map((idA, i) => `
            <tr>
              <th style="padding:4px 6px;font-weight:700;color:var(--text-muted);text-align:right;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${fundById[idA]?.scheme_name||''}">${shortName(fundById[idA]?.scheme_name||'').slice(0,12)}</th>
              ${matrixFunds.map((idB, j) => {
                if (idA === idB) return `<td style="padding:4px 6px;text-align:center;background:var(--border);border-radius:4px;font-weight:700;">—</td>`;
                const kA = Math.min(idA, idB), kB = Math.max(idA, idB);
                const cell = matrix[kA]?.[kB];
                if (!cell || cell.no_data) return `<td style="padding:4px 6px;text-align:center;color:var(--text-muted);">?</td>`;
                const p = cell.overlap_pct;
                const bg = p >= 50 ? 'rgba(220,38,38,.12)' : p >= 25 ? 'rgba(217,119,6,.10)' : 'rgba(22,163,74,.08)';
                const fc = p >= 50 ? '#dc2626' : p >= 25 ? '#d97706' : '#16a34a';
                return `<td style="padding:4px 6px;text-align:center;background:${bg};border-radius:4px;font-weight:800;color:${fc};" title="${cell.common_stocks||0} common stocks">${p !== null ? p.toFixed(0)+'%' : '—'}</td>`;
              }).join('')}
            </tr>`).join('')}
        </table>
      </div>
    </div>` : '';

  wrap.innerHTML = `
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
      <div style="background:var(--bg-secondary,#f8f9fb);border-radius:10px;padding:12px 18px;text-align:center;flex-shrink:0;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Diversification</div>
        <div style="font-size:26px;font-weight:900;color:${divScore>=70?'#16a34a':divScore>=50?'#d97706':'#dc2626'};">${divScore}/100</div>
        <div style="font-size:10px;color:var(--text-muted);">Avg overlap: ${avgOverlap.toFixed(0)}%</div>
      </div>
      ${highPairs.length
        ? `<div style="padding:10px 14px;background:rgba(220,38,38,.07);border-radius:8px;font-size:12px;color:#dc2626;flex:1;">🚨 <strong>${highPairs.length} high-overlap pair${highPairs.length>1?'s':''}</strong> detected. Consider consolidating to reduce concentration risk.</div>`
        : `<div style="padding:10px 14px;background:rgba(22,163,74,.07);border-radius:8px;font-size:12px;color:#15803d;flex:1;">✅ Portfolio overlap looks healthy — no major concentration risk detected.</div>`}
    </div>
    ${matrixHtml}
    <div style="display:flex;flex-direction:column;gap:0;">
      ${pairs.slice(0,10).map(p => `
        <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border);font-size:12px;">
          <div style="flex:1;min-width:0;">
            <div style="font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${shortName(p.nameA)} <span style="color:var(--text-muted);">vs</span> ${shortName(p.nameB)}</div>
            ${p.common ? `<div style="font-size:11px;color:var(--text-muted);margin-top:1px;">${p.common} common stock${p.common>1?'s':''}</div>` : ''}
          </div>
          <div style="flex-shrink:0;text-align:right;">
            <div style="font-weight:800;color:${riskColor(p.risk)};">${riskBadge(p.risk)}</div>
            <div style="font-size:11px;color:${riskColor(p.risk)};">${p.pct.toFixed(1)}% overlap</div>
          </div>
        </div>`).join('')}
    </div>
    <div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;">
      <div style="font-size:11px;color:var(--text-muted);">
        🔬 Real stock-level data from AMFI · Month: ${month ? new Date(month).toLocaleDateString('en-IN',{month:'short',year:'numeric'}) : '—'} · Coverage: ${coverage_pct||0}%
      </div>
    </div>`;
}

function renderOverlapProxy(wrap, holdings) {
  const pairs = [];
  for (let i = 0; i < holdings.length; i++) {
    for (let j = i+1; j < holdings.length; j++) {
      const h1 = holdings[i], h2 = holdings[j];
      if (!h1.category || !h2.category) continue;
      const ov = calcOverlap(h1.category, h2.category);
      if (ov.sectorPct > 0 || ov.stockPct > 0) {
        pairs.push({ h1, h2, ...ov, score: (ov.sectorPct + ov.stockPct * 1.5) / 2 });
      }
    }
  }
  pairs.sort((a,b) => b.score - a.score);
  const high = pairs.filter(p => p.score >= 60);
  const divScore = pairs.length > 0
    ? Math.max(0, 100 - Math.round(pairs.slice(0,3).reduce((s,p) => s+p.score, 0) / 3))
    : 100;
  const short = n => (n||'').split(' ').slice(0,4).join(' ');

  wrap.innerHTML = `
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
      <div style="background:var(--bg-secondary,#f8f9fb);border-radius:10px;padding:12px 20px;text-align:center;flex-shrink:0;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Diversification</div>
        <div style="font-size:28px;font-weight:900;color:${divScore>=70?'#16a34a':divScore>=50?'#d97706':'#dc2626'};">${divScore}/100</div>
      </div>
      ${high.length
        ? `<div style="padding:10px 14px;background:rgba(220,38,38,.07);border-radius:8px;font-size:12px;color:#dc2626;flex:1;">🚨 <strong>${high.length} high-overlap pair${high.length>1?'s':''}</strong> detected (&gt;60%).</div>`
        : `<div style="padding:10px 14px;background:rgba(22,163,74,.07);border-radius:8px;font-size:12px;color:#15803d;flex:1;">✅ No high overlap detected. Portfolio is reasonably diversified.</div>`}
    </div>
    ${pairs.slice(0,8).map(p => {
      const color = p.score >= 60 ? '#dc2626' : p.score >= 30 ? '#d97706' : '#16a34a';
      const badge = p.score >= 60 ? '🔴 High' : p.score >= 30 ? '🟡 Medium' : '🟢 Low';
      return `<div style="display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid var(--border);font-size:12px;">
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${short(p.h1.scheme_name)} <span style="color:var(--text-muted);">vs</span> ${short(p.h2.scheme_name)}</div>
          ${p.commonSectors.length ? `<div style="color:var(--text-muted);font-size:11px;margin-top:2px;">Sectors: ${p.commonSectors.slice(0,3).join(', ')}</div>` : ''}
          ${p.commonStocks.length  ? `<div style="color:var(--text-muted);font-size:11px;">Stocks: ${p.commonStocks.slice(0,3).join(', ')}</div>` : ''}
        </div>
        <div style="flex-shrink:0;text-align:right;">
          <div style="font-weight:800;color:${color};">${badge}</div>
          <div style="font-size:11px;color:${color};">Sector: ${p.sectorPct}% · Stock: ${p.stockPct}%</div>
        </div>
      </div>`;
    }).join('')}
    <div style="font-size:11px;color:var(--text-muted);margin-top:8px;padding:8px;background:var(--bg-secondary,#f8f9fb);border-radius:6px;">
      💡 Category-proxy data · Run <code>cron/fetch_fund_holdings.php</code> for real AMFI stock-level overlap
    </div>`;
}

/* ═══════════════════════════════════════════════════════════════════════════
   t97 — SECTOR ALLOCATION (Portfolio-level across all funds)
═══════════════════════════════════════════════════════════════════════════ */

const SECTOR_BY_CATEGORY = {
  'Equity — Large Cap':     { Financials:35, IT:18, Energy:12, Consumer:10, Healthcare:8, Others:17 },
  'Equity — Mid Cap':       { Industrials:22, Consumer:18, Healthcare:15, IT:14, Materials:12, Others:19 },
  'Equity — Small Cap':     { Consumer:20, Industrials:18, Materials:15, IT:12, Healthcare:10, Others:25 },
  'Index Fund':             { Financials:33, IT:17, Energy:11, Consumer:10, Healthcare:8, Others:21 },
  'Equity — Flexi Cap':     { Financials:28, IT:20, Consumer:15, Energy:12, Healthcare:10, Others:15 },
  'Equity — Multi Cap':     { Financials:25, IT:18, Consumer:16, Energy:13, Industrials:12, Others:16 },
  'ELSS':                   { Financials:30, IT:19, Energy:13, Consumer:12, Healthcare:9, Others:17 },
  'Hybrid — Aggressive':    { Financials:25, IT:15, Bonds:20, Energy:10, Consumer:10, Others:20 },
};

function renderPortfolioSectors() {
  const holdings = MF.data || [];
  const wrap = document.getElementById('sectorAllocWrap');
  if (!wrap) return;

  // Aggregate sectors weighted by current value
  const totalValue = holdings.reduce((s,h) => s+(parseFloat(h.value_now)||0), 0);
  if (totalValue === 0) { wrap.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:20px;">No holdings data</div>'; return; }

  const sectorTotals = {};
  holdings.forEach(h => {
    const cat = h.category || '';
    const val = parseFloat(h.value_now) || 0;
    const wt  = val / totalValue;
    const sectors = SECTOR_BY_CATEGORY[cat] || { Others: 100 };
    Object.entries(sectors).forEach(([sec, pct]) => {
      sectorTotals[sec] = (sectorTotals[sec] || 0) + wt * pct;
    });
  });

  // Normalise to 100%
  const total = Object.values(sectorTotals).reduce((s,v) => s+v, 0);
  const normalised = Object.entries(sectorTotals)
    .map(([sec, v]) => ({ sec, pct: total > 0 ? v/total*100 : 0 }))
    .sort((a,b) => b.pct - a.pct);

  const colors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#06b6d4','#84cc16','#f97316','#6366f1'];

  wrap.innerHTML = `
    <div style="display:flex;flex-direction:column;gap:6px;">
      ${normalised.map((s,i) => `
        <div style="display:flex;align-items:center;gap:8px;font-size:12px;">
          <div style="width:10px;height:10px;border-radius:2px;background:${colors[i%colors.length]};flex-shrink:0;"></div>
          <div style="flex:1;font-weight:600;">${s.sec}</div>
          <div style="width:80px;height:6px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
            <div style="height:100%;width:${s.pct.toFixed(0)}%;background:${colors[i%colors.length]};border-radius:99px;"></div>
          </div>
          <div style="width:36px;text-align:right;font-weight:700;color:${colors[i%colors.length]};">${s.pct.toFixed(1)}%</div>
        </div>`).join('')}
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:10px;padding:7px;background:var(--bg-secondary);border-radius:6px;">
      💡 Based on typical category sector allocation. Actual allocation varies by fund.
    </div>`;
}

/* ═══════════════════════════════════════════════════════════════════════════
   t148 — CAS AUTO-IMPORT (CAMS + KFintech)
═══════════════════════════════════════════════════════════════════════════ */

let _casParseData = null; // stores parsed result for commit step

function switchCasTab(tab, el) {
  document.querySelectorAll('.cas-tab').forEach(b => {
    b.style.borderBottomColor = 'transparent';
    b.style.color = 'var(--text-muted)';
    b.style.fontWeight = '600';
  });
  el.style.borderBottomColor = 'var(--accent)';
  el.style.color = 'var(--accent)';
  el.style.fontWeight = '700';

  document.getElementById('casTabContent').style.display     = tab === 'cas'     ? '' : 'none';
  document.getElementById('csvTabContent').style.display     = tab === 'csv'     ? '' : 'none';
  const histEl = document.getElementById('historyTabContent');
  if (histEl) histEl.style.display = tab === 'history' ? '' : 'none';
  document.getElementById('casButtons').style.display        = tab === 'cas'     ? '' : 'none';
  document.getElementById('btnStartImport').style.display    = tab === 'csv'     ? '' : 'none';

  if (tab === 'history') loadImportHistory();  // t190
}

/* ═══════════════════════════════════════════════════════════════════
   t190 — IMPORT HISTORY UI
═══════════════════════════════════════════════════════════════════ */
let _ihPage = 1;

async function loadImportHistory(page = 1) {
  _ihPage = page;
  const body = document.getElementById('importHistoryBody');
  if (!body) return;

  body.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted);"><span class="spinner"></span></div>';

  const base = window.WD?.appUrl || window.APP_URL || '';
  try {
    const res  = await fetch(`${base}/api/router.php?action=import_history&page=${page}&per_page=15`, { headers:{'X-Requested-With':'XMLHttpRequest'} });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Failed');

    const logs  = json.data?.logs || [];
    const total = json.data?.total || 0;
    const pages = json.data?.pages || 1;

    if (!logs.length) {
      body.innerHTML = `
        <div style="text-align:center;padding:40px;color:var(--text-muted);">
          <div style="font-size:32px;margin-bottom:10px;">📭</div>
          <div style="font-weight:600;margin-bottom:4px;">No imports yet</div>
          <div style="font-size:12px;">Import your portfolio via CAS or CSV to see history here.</div>
        </div>`;
      return;
    }

    const formatMap = { cams_cas:'CAMS CAS', kfintech_cas:'KFintech CAS', groww_csv:'Groww CSV', zerodha_csv:'Zerodha CSV', kuvera_csv:'Kuvera CSV', wealthdash_csv:'WD CSV', other:'Other' };
    const statusColor = { success:'#16a34a', partial:'#d97706', failed:'#dc2626' };
    const statusIcon  = { success:'✅', partial:'⚠️', failed:'❌' };

    const rows = logs.map(log => {
      const dt  = new Date(log.imported_at);
      const dtStr = dt.toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' }) + ' ' + dt.toLocaleTimeString('en-IN', { hour:'2-digit', minute:'2-digit' });
      const fmt = formatMap[log.format] || log.format || '—';
      const fname = log.filename ? `<div style="font-size:10px;color:var(--text-muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px;" title="${log.filename}">📎 ${log.filename}</div>` : '';
      const errBtn = log.error_log ? `<button onclick="showIhErrors(this)" data-err="${encodeURIComponent(log.error_log)}" style="font-size:10px;padding:2px 7px;border-radius:4px;border:1px solid #fca5a5;background:#fef2f2;color:#dc2626;cursor:pointer;margin-top:4px;">View Errors</button>` : '';
      const sc = statusColor[log.status] || '#94a3b8';
      const si = statusIcon[log.status] || '•';

      return `
        <tr>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border-color);font-size:12px;">
            <div style="font-weight:700;">${dtStr}</div>
            ${fname}
          </td>
          <td style="padding:10px 8px;border-bottom:1px solid var(--border-color);">
            <span style="font-size:11px;font-weight:600;background:rgba(99,102,241,.08);color:var(--accent);padding:2px 7px;border-radius:99px;">${fmt}</span>
          </td>
          <td style="padding:10px 8px;border-bottom:1px solid var(--border-color);text-align:center;">
            <div style="font-size:13px;font-weight:800;color:#16a34a;">${Number(log.imported_count).toLocaleString('en-IN')}</div>
            <div style="font-size:10px;color:var(--text-muted);">imported</div>
          </td>
          <td style="padding:10px 8px;border-bottom:1px solid var(--border-color);text-align:center;">
            <div style="font-size:13px;font-weight:700;color:${log.skipped_count > 0 ? '#d97706' : 'var(--text-muted)'};">${Number(log.skipped_count || 0).toLocaleString('en-IN')}</div>
            <div style="font-size:10px;color:var(--text-muted);">skipped</div>
          </td>
          <td style="padding:10px 8px;border-bottom:1px solid var(--border-color);text-align:center;">
            <div style="font-size:13px;font-weight:700;color:${log.failed_count > 0 ? '#dc2626' : 'var(--text-muted)'};">${Number(log.failed_count || 0).toLocaleString('en-IN')}</div>
            <div style="font-size:10px;color:var(--text-muted);">failed</div>
            ${errBtn}
          </td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border-color);">
            <span style="font-size:12px;font-weight:700;color:${sc};">${si} ${log.status.charAt(0).toUpperCase()+log.status.slice(1)}</span>
          </td>
        </tr>`;
    }).join('');

    const pager = pages > 1 ? `
      <div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 0 0;">
        <button onclick="loadImportHistory(${page - 1})" ${page <= 1 ? 'disabled' : ''} style="padding:4px 12px;border-radius:6px;border:1.5px solid var(--border-color);background:var(--bg-secondary);cursor:pointer;font-size:12px;font-weight:600;opacity:${page<=1?'.4':'1'};">← Prev</button>
        <span style="font-size:12px;color:var(--text-muted);">Page ${page} of ${pages}</span>
        <button onclick="loadImportHistory(${page + 1})" ${page >= pages ? 'disabled' : ''} style="padding:4px 12px;border-radius:6px;border:1.5px solid var(--border-color);background:var(--bg-secondary);cursor:pointer;font-size:12px;font-weight:600;opacity:${page>=pages?'.4':'1'};">Next →</button>
      </div>` : '';

    body.innerHTML = `
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;font-weight:600;">${total} import${total !== 1 ? 's' : ''} total</div>
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr style="background:var(--bg-secondary);">
              <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);">Date & File</th>
              <th style="padding:8px 8px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);">Format</th>
              <th style="padding:8px 8px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);text-align:center;">Imported</th>
              <th style="padding:8px 8px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);text-align:center;">Skipped</th>
              <th style="padding:8px 8px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);text-align:center;">Failed</th>
              <th style="padding:8px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);">Status</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      ${pager}`;
  } catch(e) {
    body.innerHTML = `<div style="padding:30px;text-align:center;color:#dc2626;">⚠ ${e.message}</div>`;
  }
}

function showIhErrors(btn) {
  const raw = decodeURIComponent(btn.dataset.err || '');
  const panel = btn.nextElementSibling;
  if (panel && panel.classList.contains('ih-err-panel')) { panel.remove(); return; }
  const div = document.createElement('div');
  div.className = 'ih-err-panel';
  div.style.cssText = 'font-size:11px;color:#dc2626;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:8px;margin-top:4px;white-space:pre-wrap;max-height:120px;overflow-y:auto;';
  div.textContent = raw;
  btn.insertAdjacentElement('afterend', div);
}

function onCasFileSelect(input) {
  const file = input.files?.[0];
  if (!file) return;
  document.getElementById('casFileName').textContent = `📄 ${file.name} (${(file.size/1024).toFixed(0)} KB)`;
  document.getElementById('casFileLabel').style.borderColor = 'var(--accent)';
  document.getElementById('casFileLabel').style.background = 'rgba(99,102,241,.04)';
  document.getElementById('btnCasParse').style.display = '';
  document.getElementById('btnCasImport').style.display = 'none';
  document.getElementById('casParseResult').style.display = 'none';
  _casParseData = null;
}

async function parseCasFile() {
  const file = document.getElementById('casFile')?.files?.[0];
  const portId = document.getElementById('casPortfolioId')?.value;
  if (!file) { showToast('Select a file first', 'error'); return; }

  const btn = document.getElementById('btnCasParse');
  btn.disabled = true; btn.textContent = '⏳ Parsing…';

  const form = new FormData();
  form.append('action', 'cas_parse');
  form.append('cas_file', file);
  form.append('format', 'auto');
  form.append('portfolio_id', portId);

  try {
    const res  = await fetch(`${APP_URL}/api/router.php`, { method:'POST', body:form });
    const data = await res.json();

    const resultEl = document.getElementById('casParseResult');
    resultEl.style.display = '';

    if (!data.success) {
      resultEl.innerHTML = `<div style="padding:12px;background:rgba(220,38,38,.08);border-radius:8px;color:#dc2626;font-size:13px;">
        ❌ ${data.message}</div>`;
      return;
    }

    _casParseData = data.data;
    const d = _casParseData;

    // Fund mapping summary
    const unmapped = (d.fund_map || []).filter(f => !f.matched);
    const mapped   = (d.fund_map || []).filter(f => f.matched);

    resultEl.innerHTML = `
      <div style="padding:12px;background:rgba(22,163,74,.07);border-radius:8px;margin-bottom:12px;">
        <div style="font-weight:700;margin-bottom:6px;">✅ Parse successful — ${data.data.format} format</div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:12px;">
          <div>Total transactions: <strong>${d.total}</strong></div>
          <div style="color:#16a34a;">New: <strong>${d.new_count}</strong></div>
          <div style="color:#d97706;">Duplicates: <strong>${d.duplicate_count}</strong></div>
        </div>
      </div>
      ${unmapped.length ? `<div style="padding:10px;background:rgba(245,158,11,.08);border-radius:7px;font-size:12px;color:#b45309;margin-bottom:10px;">
        ⚠️ <strong>${unmapped.length} funds not matched</strong> in WealthDash DB:
        ${unmapped.slice(0,3).map(f=>`<br>• ${f.fund_name}`).join('')}
        ${unmapped.length>3?`<br>• ...and ${unmapped.length-3} more`:''}
      </div>` : `<div style="font-size:12px;color:#15803d;margin-bottom:8px;">✅ All ${mapped.length} funds matched in database</div>`}
      <div style="font-size:12px;color:var(--text-muted);padding:8px;background:var(--bg-secondary);border-radius:6px;max-height:120px;overflow-y:auto;">
        <strong>Preview (first 5 transactions):</strong><br>
        ${(d.transactions||[]).slice(0,5).map(t =>
          `${t.txn_date} | ${(t.fund_name||'').slice(0,30)} | ${t.txn_type} | ${t.units} units @₹${t.nav}`
        ).join('<br>')}
      </div>`;

    if (d.new_count > 0) {
      document.getElementById('btnCasImport').style.display = '';
      document.getElementById('btnCasImport').textContent = `✅ Import ${d.new_count} New Transactions`;
    } else {
      resultEl.innerHTML += `<div style="margin-top:8px;font-size:12px;font-weight:700;color:#16a34a;">All transactions already imported!</div>`;
    }

  } catch(e) {
    document.getElementById('casParseResult').innerHTML = `<div style="padding:12px;background:rgba(220,38,38,.08);border-radius:8px;color:#dc2626;font-size:12px;">Error: ${e.message}</div>`;
    document.getElementById('casParseResult').style.display = '';
  } finally {
    btn.disabled = false; btn.textContent = '🔍 Parse & Preview';
  }
}

async function commitCasImport() {
  if (!_casParseData) { showToast('Parse file first', 'warning'); return; }
  const portId = document.getElementById('casPortfolioId')?.value;
  const btn    = document.getElementById('btnCasImport');
  btn.disabled = true; btn.textContent = '⏳ Importing…';

  // Only send non-duplicate transactions
  const toImport = (_casParseData.transactions || []).filter(t => !t.is_duplicate);

  const form = new FormData();
  form.append('action', 'cas_import');
  form.append('portfolio_id', portId);
  form.append('transactions', JSON.stringify(toImport));

  try {
    const res  = await fetch(`${APP_URL}/api/router.php`, { method:'POST', body:form });
    const data = await res.json();
    const resEl = document.getElementById('casImportResult');
    resEl.style.display = '';

    if (data.success) {
      const d = data.data;
      resEl.innerHTML = `<div style="padding:12px;background:rgba(22,163,74,.08);border-radius:8px;color:#15803d;">
        <div style="font-weight:800;font-size:14px;margin-bottom:6px;">✅ Import Complete!</div>
        <div style="font-size:12px;">Imported: <strong>${d.imported}</strong> · Duplicates skipped: <strong>${d.duplicates}</strong> · Failed: <strong>${d.failed}</strong></div>
        ${d.errors?.length ? `<div style="margin-top:6px;font-size:11px;">Errors: ${d.errors.slice(0,3).join(', ')}</div>` : ''}
      </div>`;
      showToast(`✅ ${d.imported} transactions imported from CAS!`, 'success');
      hideModal('modalImportCsv');
      MF.loadHoldings();
    } else {
      resEl.innerHTML = `<div style="padding:12px;background:rgba(220,38,38,.08);border-radius:8px;color:#dc2626;">${data.message}</div>`;
    }
  } catch(e) {
    document.getElementById('casImportResult').innerHTML = `<div style="padding:12px;background:rgba(220,38,38,.08);border-radius:8px;color:#dc2626;font-size:12px;">${e.message}</div>`;
    document.getElementById('casImportResult').style.display = '';
  } finally {
    btn.disabled = false; btn.textContent = '✅ Import Transactions';
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   t90 — FUND NAV HISTORY CHART MODAL
   Append this entire block to the end of public/js/mf.js
═══════════════════════════════════════════════════════════════════════════ */

// ── State ─────────────────────────────────────────────────────────────────
const FC = {
  fundId:          null,
  fundName:        null,
  holding:         null,
  range:           '1Y',
  navData:         [],      // [{date, nav}, ...]
  txns:            [],      // all transactions for this fund
  chartInst:       null,
  showTxns:        true,
  // t95 — benchmark overlay
  showBenchmark:   false,
  benchmarkSymbol: '^NSEI',
  benchmarkData:   [],      // [{date, close}, ...]
  benchmarkLabel:  'Nifty 50',
};

// ── Open modal ────────────────────────────────────────────────────────────
async function openFundChartModal(fundId) {
  // Find holding data
  const h = (MF.data || []).find(x => (x.fund_id || x.id) === fundId || String(x.fund_id || x.id) === String(fundId));
  if (!h) { showToast('Holding data not found', 'error'); return; }

  FC.fundId   = Number(fundId);
  FC.fundName = h.scheme_name;
  FC.holding  = h;
  FC.range    = '1Y';
  FC.navData  = [];
  FC.txns     = [];
  // Reset benchmark
  FC.showBenchmark = false;
  FC.benchmarkData = [];
  const fcBenchChk = document.getElementById('fcShowBenchmark');
  if (fcBenchChk) fcBenchChk.checked = false;
  const fcBenchSel = document.getElementById('fcBenchmarkSelect');
  if (fcBenchSel) { fcBenchSel.style.display = 'none'; fcBenchSel.value = '^NSEI'; }
  FC.benchmarkSymbol = '^NSEI';
  FC.benchmarkLabel  = 'Nifty 50';
  const fcBenchStatus = document.getElementById('fcBenchmarkStatus');
  if (fcBenchStatus) fcBenchStatus.style.display = 'none';
  const fcNormNote = document.getElementById('fcNormalizedNote');
  if (fcNormNote) fcNormNote.style.display = 'none';

  // Show modal
  document.getElementById('modalFundChart').style.display = 'flex';
  document.body.style.overflow = 'hidden';

  // Populate header
  document.getElementById('fcFundName').textContent = h.scheme_name || '—';

  // Meta badges
  const meta = [];
  if (h.fund_house_short || h.fund_house) meta.push(`<span style="background:rgba(37,99,235,.08);color:var(--accent);padding:1px 7px;border-radius:4px;font-weight:600;">${escHtml(h.fund_house_short||h.fund_house)}</span>`);
  if (h.category) meta.push(`<span style="background:var(--bg-secondary);border:1px solid var(--border);padding:1px 7px;border-radius:4px;">${escHtml(h.category)}</span>`);
  const isDirect = (h.scheme_name||'').toLowerCase().includes('direct');
  meta.push(isDirect
    ? `<span style="background:rgba(22,163,74,.1);color:#15803d;padding:1px 7px;border-radius:4px;font-weight:700;">Direct</span>`
    : `<span style="background:rgba(234,179,8,.1);color:#b45309;padding:1px 7px;border-radius:4px;font-weight:700;">Regular</span>`);
  if (h.folio_number) meta.push(`<span style="color:var(--text-muted);">Folio: ${escHtml(h.folio_number)}</span>`);
  document.getElementById('fcFundMeta').innerHTML = meta.join('');

  // Populate stats
  _renderFcStats(h);

  // Reset range buttons
  document.querySelectorAll('.fc-range-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.range === '1Y');
  });

  // Reset canvas/spinner
  _fcShowSpinner(true);
  document.getElementById('fcNoData').style.display = 'none';

  // Fetch transactions (once) + NAV data
  await _fcFetchTxns();
  await _fcFetchNavAndRender();
}

// ── Stats row ────────────────────────────────────────────────────────────
function _renderFcStats(h) {
  const gain    = h.gain_loss || 0;
  const gainPct = h.gain_pct  || 0;
  const cagr    = h.cagr;
  const dd      = h.drawdown_pct;

  function statCell(label, val, sub, valColor) {
    return `<div class="fc-stat-cell">
      <div class="fc-stat-label">${label}</div>
      <div class="fc-stat-val" style="color:${valColor||'var(--text-primary)'};">${val}</div>
      ${sub ? `<div class="fc-stat-sub">${sub}</div>` : ''}
    </div>`;
  }

  const gainColor = gain >= 0 ? '#16a34a' : '#dc2626';
  const ddColor   = (!dd || dd <= 0) ? '#16a34a' : dd > 20 ? '#dc2626' : '#d97706';

  document.getElementById('fcStats').innerHTML =
    statCell('Invested',    fmtFull(h.total_invested || 0), '', '')  +
    statCell('Current Val', fmtFull(h.value_now || 0), '', '') +
    statCell('Gain/Loss',   (gain >= 0 ? '+' : '') + fmtFull(gain), gainPct.toFixed(2) + '%', gainColor) +
    statCell('CAGR',        cagr !== null && cagr !== undefined ? (cagr >= 0 ? '+' : '') + cagr.toFixed(2) + '%' : '—', 'Annualised', cagr >= 0 ? '#16a34a' : '#dc2626') +
    statCell('Units',       Number(h.total_units || 0).toFixed(4), 'Avg cost ₹' + Number(h.avg_cost_nav || 0).toFixed(2), '') +
    statCell('Drawdown',    (!dd || dd <= 0) ? '🏆 ATH' : '-' + dd + '%', (!dd || dd <= 0) ? 'At all-time high' : 'From peak NAV', ddColor);
}

// ── Fetch transactions ────────────────────────────────────────────────────
async function _fcFetchTxns() {
  try {
    const res = await API.get(`/api/mutual_funds/mf_list.php?view=transactions&fund_id=${FC.fundId}&per_page=500`);
    FC.txns = res?.data || res?.transactions || [];
  } catch(e) {
    FC.txns = [];
  }
}

// ── Fetch NAV history for current range & render ──────────────────────────
async function _fcFetchNavAndRender() {
  _fcShowSpinner(true);
  document.getElementById('fcDataStatus').textContent = '';

  const { from, to } = _fcRangeDates(FC.range, FC.holding);

  try {
    const baseUrl = window.WD?.appUrl || window.APP_URL || '';
    const url = `${baseUrl}/api/mutual_funds/mf_nav_history.php?fund_id=${FC.fundId}&from=${from}&to=${to}`;
    const res = await fetch(url, { cache: 'default' });
    const json = await res.json();

    if (!json.success || !json.data || json.data.length === 0) {
      _fcShowSpinner(false);
      document.getElementById('fcNoData').style.display = 'flex';
      document.getElementById('fcChartCanvas').style.display = 'none';
      document.getElementById('fcTxnSection').style.display = 'none';
      return;
    }

    FC.navData = json.data; // [{date, nav}, ...]
    document.getElementById('fcNoData').style.display = 'none';
    document.getElementById('fcChartCanvas').style.display = '';
    document.getElementById('fcTxnSection').style.display = '';
    document.getElementById('fcDataStatus').textContent = `${json.count.toLocaleString()} data points`;

    // t95 — fetch benchmark if enabled
    if (FC.showBenchmark) {
      await _fcFetchBenchmark(from, to);
    }

    _renderNavLineChart();
    _renderFcTxnPills();
  } catch(e) {
    _fcShowSpinner(false);
    document.getElementById('fcDataStatus').textContent = 'Failed to load NAV data';
  }
}

// ── t95: Fetch benchmark (stooq via PHP proxy) ────────────────────────────
async function _fcFetchBenchmark(from, to) {
  const statusEl = document.getElementById('fcBenchmarkStatus');
  if (statusEl) { statusEl.style.display = 'inline'; statusEl.textContent = '⏳ Loading…'; }
  try {
    const baseUrl = window.WD?.appUrl || window.APP_URL || '';
    const url = `${baseUrl}/api/mutual_funds/benchmark_proxy.php?symbol=${FC.benchmarkSymbol}&from=${from}&to=${to}`;
    const res  = await fetch(url, { cache: 'default' });
    const json = await res.json();
    if (json.success && json.data?.length) {
      FC.benchmarkData = json.data; // [{date, close}, ...]
      if (statusEl) { statusEl.textContent = `📊 ${FC.benchmarkLabel}`; }
    } else {
      FC.benchmarkData = [];
      if (statusEl) { statusEl.textContent = '⚠️ No data'; }
    }
  } catch(e) {
    FC.benchmarkData = [];
    if (statusEl) { statusEl.textContent = '⚠️ Failed'; }
  }
}

// ── t95: Toggle benchmark overlay ────────────────────────────────────────
async function toggleFcBenchmark() {
  FC.showBenchmark = document.getElementById('fcShowBenchmark')?.checked || false;
  const selEl      = document.getElementById('fcBenchmarkSelect');
  const statusEl   = document.getElementById('fcBenchmarkStatus');
  const normNote   = document.getElementById('fcNormalizedNote');

  if (FC.showBenchmark) {
    if (selEl)    selEl.style.display    = 'inline-block';
    if (normNote) normNote.style.display = 'block';
    // Fetch benchmark & re-render
    const { from, to } = _fcRangeDates(FC.range, FC.holding);
    await _fcFetchBenchmark(from, to);
  } else {
    FC.benchmarkData = [];
    if (selEl)    selEl.style.display    = 'none';
    if (statusEl) { statusEl.style.display = 'none'; statusEl.textContent = ''; }
    if (normNote) normNote.style.display = 'none';
  }
  if (FC.navData.length) _renderNavLineChart();
}

// ── t95: Change benchmark index ───────────────────────────────────────────
async function setFcBenchmark(symbol) {
  const labels = { '^NSEI': 'Nifty 50', '^BSESN': 'Sensex', '^NSMIDCP': 'Nifty Midcap' };
  FC.benchmarkSymbol = symbol;
  FC.benchmarkLabel  = labels[symbol] || symbol;
  if (!FC.showBenchmark) return;
  const { from, to } = _fcRangeDates(FC.range, FC.holding);
  await _fcFetchBenchmark(from, to);
  if (FC.navData.length) _renderNavLineChart();
}

// ── Date range calculator ─────────────────────────────────────────────────
function _fcRangeDates(range, holding) {
  const toDate  = new Date();
  let   fromDate;

  switch (range) {
    case '1M':  fromDate = new Date(toDate); fromDate.setMonth(fromDate.getMonth() - 1); break;
    case '3M':  fromDate = new Date(toDate); fromDate.setMonth(fromDate.getMonth() - 3); break;
    case '6M':  fromDate = new Date(toDate); fromDate.setMonth(fromDate.getMonth() - 6); break;
    case '1Y':  fromDate = new Date(toDate); fromDate.setFullYear(fromDate.getFullYear() - 1); break;
    case '3Y':  fromDate = new Date(toDate); fromDate.setFullYear(fromDate.getFullYear() - 3); break;
    case 'ALL':
    default:
      fromDate = holding?.first_purchase_date
        ? new Date(holding.first_purchase_date)
        : new Date('1995-01-01');
      // Go back 3 months before first purchase for context
      fromDate.setMonth(fromDate.getMonth() - 3);
      break;
  }

  const fmt = d => d.toISOString().slice(0, 10);
  return { from: fmt(fromDate), to: fmt(toDate) };
}

// ── Render Chart.js line chart (t95: benchmark overlay support) ───────────
function _renderNavLineChart() {
  const canvas = document.getElementById('fcChartCanvas');
  if (!canvas) return;

  if (FC.chartInst) { FC.chartInst.destroy(); FC.chartInst = null; }
  const ctx = canvas.getContext('2d');

  // ── Decide mode: normalized (benchmark on) or raw NAV ─────────────────
  const benchMode = FC.showBenchmark && FC.benchmarkData.length > 0;

  // ── Build merged label set (union of fund dates + benchmark dates) ─────
  const allDates = new Set(FC.navData.map(d => d.date));
  if (benchMode) FC.benchmarkData.forEach(d => allDates.add(d.date));
  const labels = [...allDates].sort();

  // ── NAV values (raw or normalized) ────────────────────────────────────
  const navMap   = Object.fromEntries(FC.navData.map(d => [d.date, d.nav]));
  const benchMap = benchMode
    ? Object.fromEntries(FC.benchmarkData.map(d => [d.date, d.close]))
    : {};

  let navVals, benchVals;

  if (benchMode) {
    // Normalize both to 100 at the earliest date with data
    const firstNavDate   = FC.navData[0]?.date;
    const firstBenchDate = FC.benchmarkData[0]?.date;
    const startDate      = firstNavDate > firstBenchDate ? firstNavDate : firstBenchDate;

    // Get base values at start date (or nearest)
    const navBase   = _interpolateMap(navMap,   labels, startDate);
    const benchBase = _interpolateMap(benchMap, labels, startDate);

    navVals   = labels.map(d => {
      const v = _interpolateMap(navMap,   labels, d);
      return (navBase && v !== null) ? +((v / navBase) * 100).toFixed(3) : null;
    });
    benchVals = labels.map(d => {
      const v = _interpolateMap(benchMap, labels, d);
      return (benchBase && v !== null) ? +((v / benchBase) * 100).toFixed(3) : null;
    });
  } else {
    navVals = labels.map(d => navMap[d] ?? null);
  }

  // ── Gradient fill ──────────────────────────────────────────────────────
  const grad = ctx.createLinearGradient(0, 0, 0, canvas.offsetHeight || 280);
  grad.addColorStop(0, 'rgba(37,99,235,0.18)');
  grad.addColorStop(1, 'rgba(37,99,235,0.00)');

  const benchGrad = benchMode ? (() => {
    const g = ctx.createLinearGradient(0, 0, 0, canvas.offsetHeight || 280);
    g.addColorStop(0, 'rgba(249,115,22,0.10)');
    g.addColorStop(1, 'rgba(249,115,22,0.00)');
    return g;
  })() : null;

  // ── Fund NAV dataset ───────────────────────────────────────────────────
  const shortName = (FC.fundName || 'Fund').split(' ').slice(0, 3).join(' ');
  const datasets = [
    {
      label: benchMode ? shortName : 'NAV',
      data: navVals,
      borderColor: '#2563eb',
      borderWidth: 2,
      backgroundColor: grad,
      fill: true,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBackgroundColor: '#2563eb',
      tension: 0.15,
      spanGaps: true,
      order: 3,
    }
  ];

  // ── Benchmark dataset ──────────────────────────────────────────────────
  if (benchMode) {
    datasets.push({
      label: FC.benchmarkLabel,
      data: benchVals,
      borderColor: '#f97316',
      borderWidth: 2,
      backgroundColor: benchGrad,
      fill: true,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBackgroundColor: '#f97316',
      tension: 0.15,
      spanGaps: true,
      order: 4,
      borderDash: [4, 3],
    });

    // ── Outperformance shading (fill between lines) ────────────────────
    // Chart.js v3 doesn't support fill-between natively without plugin,
    // so we handle it via two fill datasets (fund fills from benchmark)
    // Instead, we annotate with a small text badge via afterDraw plugin
  }

  // ── Avg cost line — only in raw NAV mode ──────────────────────────────
  const avgNav = FC.holding?.avg_cost_nav ? Number(FC.holding.avg_cost_nav) : null;
  if (!benchMode && avgNav) {
    datasets.push({
      label: 'Avg Cost ₹' + avgNav.toFixed(2),
      data: labels.map(() => avgNav),
      borderColor: 'rgba(234,179,8,0.65)',
      borderWidth: 1.5,
      borderDash: [5, 4],
      fill: false,
      pointRadius: 0,
      tension: 0,
      spanGaps: true,
      order: 2,
    });
  }

  // ── Buy/sell scatter — only in raw NAV mode ───────────────────────────
  if (!benchMode) {
    const { from, to } = _fcRangeDates(FC.range, FC.holding);
    const inRange    = FC.txns.filter(t => t.txn_date >= from && t.txn_date <= to);
    const buyPoints  = [];
    const sellPoints = [];

    inRange.forEach(t => {
      const navAtTxn = t.nav ? Number(t.nav) : _findNearestNav(t.txn_date);
      if (navAtTxn === null) return;
      const point = { x: t.txn_date, y: navAtTxn, txn: t };
      const type  = (t.transaction_type || '').toUpperCase();
      if (['BUY','STP_IN','SWITCH_IN','DIV_REINVEST'].includes(type)) buyPoints.push(point);
      else if (['SELL','STP_OUT','SWITCH_OUT','SWP'].includes(type))  sellPoints.push(point);
    });

    if (FC.showTxns && buyPoints.length) {
      datasets.push({
        label: 'Buy',
        type: 'scatter',
        data: buyPoints.map(p => ({ x: labels.indexOf(p.x), y: p.y, raw: p })),
        backgroundColor: 'rgba(22,163,74,0.9)',
        borderColor: '#fff',
        borderWidth: 1.5,
        pointRadius: 7,
        pointHoverRadius: 9,
        pointStyle: 'triangle',
        order: 1,
      });
    }
    if (FC.showTxns && sellPoints.length) {
      datasets.push({
        label: 'Sell',
        type: 'scatter',
        data: sellPoints.map(p => ({ x: labels.indexOf(p.x), y: p.y, raw: p })),
        backgroundColor: 'rgba(220,38,38,0.9)',
        borderColor: '#fff',
        borderWidth: 1.5,
        pointRadius: 7,
        pointHoverRadius: 9,
        pointStyle: 'rectRot',
        order: 1,
      });
    }
  }

  // ── Y-axis config ──────────────────────────────────────────────────────
  const yAxis = benchMode
    ? {
        position: 'right',
        ticks: {
          color: 'var(--text-muted)',
          font: { size: 10 },
          callback: v => {
            const diff = v - 100;
            return (diff >= 0 ? '+' : '') + diff.toFixed(1) + '%';
          },
        },
        grid: { color: 'rgba(0,0,0,0.05)' },
        // Draw zero-line at 100
        afterDataLimits: scale => {
          if (scale.min > 95) scale.min = 95;
        },
      }
    : {
        position: 'right',
        ticks: {
          color: 'var(--text-muted)',
          font: { size: 10 },
          callback: v => '₹' + Number(v).toFixed(v >= 100 ? 0 : 2),
        },
        grid: { color: 'rgba(0,0,0,0.05)' },
      };

  // ── Custom plugin: 100 baseline dashed line in bench mode ─────────────
  const baselinePlugin = {
    id: 'fcBaseline',
    afterDraw(chart) {
      if (!benchMode) return;
      const { ctx: c, scales: { y, x } } = chart;
      const y100 = y.getPixelForValue(100);
      c.save();
      c.setLineDash([4, 4]);
      c.strokeStyle = 'rgba(100,116,139,0.4)';
      c.lineWidth   = 1;
      c.beginPath();
      c.moveTo(x.left, y100);
      c.lineTo(x.right, y100);
      c.stroke();
      // Label
      c.setLineDash([]);
      c.fillStyle   = 'rgba(100,116,139,0.7)';
      c.font        = '9px sans-serif';
      c.textAlign   = 'right';
      c.fillText('Base (100)', x.right - 4, y100 - 3);
      c.restore();
    }
  };

  // ── Final returns badge plugin (top-right corner) ─────────────────────
  const returnsPlugin = {
    id: 'fcReturns',
    afterDraw(chart) {
      if (!benchMode) return;
      const lastFundVal  = navVals.filter(v => v !== null).at(-1);
      const lastBenchVal = benchVals.filter(v => v !== null).at(-1);
      if (!lastFundVal || !lastBenchVal) return;

      const fundRet  = (lastFundVal  - 100).toFixed(2);
      const benchRet = (lastBenchVal - 100).toFixed(2);
      const alpha    = (lastFundVal  - lastBenchVal).toFixed(2);
      const isPos    = parseFloat(alpha) >= 0;

      const { ctx: c, chartArea: { right, top } } = chart;
      c.save();
      const pad = 8, lh = 14;
      const x = right - 130, y = top + 8;
      c.fillStyle = 'rgba(15,23,42,0.65)';
      c.beginPath();
      c.roundRect(x, y, 126, lh * 4 + pad * 2, 6);
      c.fill();

      c.font = 'bold 10px sans-serif';
      c.fillStyle = '#93c5fd'; c.fillText('Fund:',      x + pad,      y + pad + lh * 0);
      c.fillStyle = '#fed7aa'; c.fillText('Benchmark:', x + pad,      y + pad + lh * 1);
      c.fillStyle = isPos ? '#86efac' : '#fca5a5';
      c.fillText('Alpha:',    x + pad,      y + pad + lh * 2);
      c.fillStyle = '#ffffff'; c.textAlign = 'right';
      c.fillStyle = parseFloat(fundRet)  >= 0 ? '#86efac' : '#fca5a5';
      c.fillText((parseFloat(fundRet)  >= 0 ? '+' : '') + fundRet  + '%', x + 122, y + pad + lh * 0);
      c.fillStyle = parseFloat(benchRet) >= 0 ? '#86efac' : '#fca5a5';
      c.fillText((parseFloat(benchRet) >= 0 ? '+' : '') + benchRet + '%', x + 122, y + pad + lh * 1);
      c.fillStyle = isPos ? '#86efac' : '#fca5a5';
      c.fillText((isPos ? '+' : '') + alpha + '%',  x + 122, y + pad + lh * 2);
      c.restore();
    }
  };

  FC.chartInst = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets },
    plugins: [baselinePlugin, returnsPlugin],
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 300 },
      interaction: { mode: 'index', intersect: false },
      scales: {
        x: {
          type: 'category',
          ticks: {
            maxTicksLimit: 8,
            color: 'var(--text-muted)',
            font: { size: 10 },
            callback(val) {
              const lbl = this.getLabelForValue(val);
              return lbl ? lbl.slice(0, 7) : '';
            }
          },
          grid: { color: 'rgba(0,0,0,0.04)', drawTicks: false },
        },
        y: yAxis,
      },
      plugins: {
        legend: {
          display: true,
          position: 'top',
          align: 'start',
          labels: {
            color: 'var(--text-muted)',
            font: { size: 11 },
            boxWidth: 12,
            padding: 14,
          }
        },
        tooltip: {
          backgroundColor: 'var(--bg-card, #fff)',
          borderColor: 'var(--border)',
          borderWidth: 1,
          titleColor: 'var(--text-primary)',
          bodyColor: 'var(--text-muted)',
          padding: 10,
          callbacks: {
            title: items => items[0]?.label || '',
            label: item => {
              const lbl = item.dataset.label;
              if (benchMode) {
                const pct = Number(item.raw);
                const diff = (pct - 100).toFixed(2);
                return ` ${lbl}: ${(pct - 100) >= 0 ? '+' : ''}${diff}%  (${pct.toFixed(2)})`;
              }
              if (lbl === 'NAV') return ` NAV: ₹${Number(item.raw).toFixed(4)}`;
              if (lbl === 'Buy') {
                const raw = item.raw?.raw?.txn;
                return raw ? ` BUY — ${Number(raw.units||0).toFixed(4)} units @ ₹${Number(raw.nav||0).toFixed(2)}` : ' Buy';
              }
              if (lbl === 'Sell') {
                const raw = item.raw?.raw?.txn;
                return raw ? ` SELL — ${Number(raw.units||0).toFixed(4)} units @ ₹${Number(raw.nav||0).toFixed(2)}` : ' Sell';
              }
              return ` ${lbl}: ₹${Number(item.raw).toFixed(2)}`;
            }
          }
        }
      }
    }
  });

  _fcShowSpinner(false);
}

// ── Helper: interpolate value from a date→value map ───────────────────────
function _interpolateMap(map, sortedLabels, targetDate) {
  if (map[targetDate] !== undefined) return map[targetDate];
  // Walk backwards to find nearest previous date with data
  const idx = sortedLabels.indexOf(targetDate);
  if (idx < 0) return null;
  for (let i = idx - 1; i >= 0; i--) {
    if (map[sortedLabels[i]] !== undefined) return map[sortedLabels[i]];
  }
  // Walk forward
  for (let i = idx + 1; i < sortedLabels.length; i++) {
    if (map[sortedLabels[i]] !== undefined) return map[sortedLabels[i]];
  }
  return null;
}

// ── Find nearest NAV for a date ───────────────────────────────────────────
function _findNearestNav(dateStr) {
  if (!FC.navData.length) return null;
  // Exact match first
  const exact = FC.navData.find(d => d.date === dateStr);
  if (exact) return exact.nav;
  // Closest date
  const target = new Date(dateStr).getTime();
  let best = null, bestDiff = Infinity;
  for (const d of FC.navData) {
    const diff = Math.abs(new Date(d.date).getTime() - target);
    if (diff < bestDiff) { bestDiff = diff; best = d.nav; }
  }
  return best;
}

// ── Transaction pills in footer ───────────────────────────────────────────
function _renderFcTxnPills() {
  const container = document.getElementById('fcTxnList');
  if (!container) return;

  if (!FC.txns.length) {
    container.innerHTML = '<span style="color:var(--text-muted);font-size:12px;">No transactions found</span>';
    return;
  }

  const sorted = [...FC.txns].sort((a, b) => a.txn_date > b.txn_date ? -1 : 1);
  const topN   = sorted.slice(0, 20);

  const typeStyle = {
    BUY:         'background:#dcfce7;color:#15803d;border-color:#86efac;',
    SELL:        'background:#fee2e2;color:#dc2626;border-color:#fca5a5;',
    SWITCH_IN:   'background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;',
    SWITCH_OUT:  'background:#fef3c7;color:#b45309;border-color:#fcd34d;',
    DIV_REINVEST:'background:#ede9fe;color:#6d28d9;border-color:#c4b5fd;',
    DIV_PAYOUT:  'background:#fce7f3;color:#be185d;border-color:#f9a8d4;',
    STP_IN:      'background:#d1fae5;color:#065f46;border-color:#6ee7b7;',
    STP_OUT:     'background:#ffedd5;color:#9a3412;border-color:#fdba74;',
    SWP:         'background:#fef9c3;color:#713f12;border-color:#fde047;',
  };

  container.innerHTML = topN.map(t => {
    const type  = (t.transaction_type || 'BUY').toUpperCase();
    const style = typeStyle[type] || 'background:var(--bg-secondary);color:var(--text-muted);border-color:var(--border);';
    const units = Number(t.units || 0).toFixed(4);
    const nav   = Number(t.nav || 0).toFixed(2);
    const amt   = fmtFull(t.value_at_cost || 0);
    return `<div title="${type}: ${units} units @ ₹${nav} = ${amt}" style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:99px;font-size:10px;font-weight:700;border:1px solid;${style}white-space:nowrap;cursor:default;">
      <span>${type}</span>
      <span style="font-weight:500;opacity:.8;">${t.txn_date?.slice(0,10)||''}</span>
      <span>${amt}</span>
    </div>`;
  }).join('') + (sorted.length > 20 ? `<span style="color:var(--text-muted);font-size:10px;padding:4px;">+${sorted.length - 20} more…</span>` : '');
}

// ── Range button handler ──────────────────────────────────────────────────
async function setFcRange(range, btn) {
  FC.range = range;
  document.querySelectorAll('.fc-range-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  await _fcFetchNavAndRender();
}

// ── Toggle buy/sell markers ───────────────────────────────────────────────
function toggleFcTxnMarkers() {
  FC.showTxns = document.getElementById('fcShowTxns')?.checked !== false;
  if (FC.navData.length) _renderNavLineChart();
}

// ── Close modal ───────────────────────────────────────────────────────────
function closeFundChartModal() {
  document.getElementById('modalFundChart').style.display = 'none';
  document.body.style.overflow = '';
  if (FC.chartInst) { FC.chartInst.destroy(); FC.chartInst = null; }
  FC.fundId        = null;
  FC.navData       = [];
  FC.txns          = [];
  FC.benchmarkData = [];
  FC.showBenchmark = false;
}

// ── Spinner helper ────────────────────────────────────────────────────────
function _fcShowSpinner(show) {
  const el = document.getElementById('fcChartSpinner');
  if (el) el.style.display = show ? 'flex' : 'none';
}

// ── Wire up fund-title click in holdings table ────────────────────────────
// Called once after renderHoldingsTable() — patches .fund-title divs
function _wireFundTitleClicks() {
  document.querySelectorAll('#holdingsTable .fund-title').forEach(el => {
    el.classList.add('fc-clickable');
    const row = el.closest('tr[data-fund-id]');
    if (!row) return;
    const fundId = Number(row.dataset.fundId);
    el.onclick = e => { e.stopPropagation(); openFundChartModal(fundId); };
  });
}

// ── Hook into MF.loadHoldings post-render ────────────────────────────────
// Patch the existing renderHoldingsTable to also wire clicks
(function() {
  // Wait for DOM ready, then observe holdingsTable mutations
  const observe = () => {
    const table = document.getElementById('holdingsTable');
    if (!table) return setTimeout(observe, 500);

    const observer = new MutationObserver(() => {
      setTimeout(_wireFundTitleClicks, 50);
    });
    observer.observe(table.querySelector('tbody') || table, {
      childList: true, subtree: false
    });
    // Also wire immediately on first paint
    _wireFundTitleClicks();
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', observe);
  } else {
    observe();
  }
})();

// ── Close on overlay click ────────────────────────────────────────────────
document.addEventListener('click', e => {
  if (e.target.id === 'modalFundChart') closeFundChartModal();
});

// ═══════════════════════════════════════════════════════════════════════════
// t172 — Folio Consolidation Alert
// Detects same fund with multiple folios → shows actionable banner
// ═══════════════════════════════════════════════════════════════════════════
function renderFolioAlert(holdings) {
  const wrap = document.getElementById('folioAlertBanner');
  if (!wrap) return;

  // folio_count comes from mf_list.php consolidated holdings query
  const dupes = (holdings || []).filter(h => (parseInt(h.folio_count) || 1) > 1);
  if (!dupes.length) { wrap.style.display = 'none'; return; }

  const rows = dupes.map(h => {
    const folios = (h.folios || '').split(',').map(f => f.trim()).filter(Boolean);
    return `<div style="display:flex;align-items:center;justify-content:space-between;
                padding:7px 0;border-bottom:1px solid var(--border);">
      <div>
        <span style="font-weight:700;font-size:13px;color:var(--text-primary);">${h.scheme_name||'—'}</span>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
          ${folios.map(f=>`<span style="background:var(--bg-secondary);border:1px solid var(--border);
            border-radius:4px;padding:1px 6px;margin-right:4px;font-family:monospace;">${f}</span>`).join('')}
        </div>
      </div>
      <span style="font-size:11px;font-weight:700;color:#d97706;white-space:nowrap;">${folios.length} Folios</span>
    </div>`;
  }).join('');

  wrap.style.display = '';
  wrap.innerHTML = `
    <div style="background:#fefce8;border:1.5px solid #fde68a;border-radius:12px;padding:14px 16px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-size:16px;">📂</span>
          <div>
            <div style="font-weight:800;font-size:13px;color:#92400e;">
              Folio Consolidation Recommended
            </div>
            <div style="font-size:11px;color:#a16207;margin-top:1px;">
              ${dupes.length} fund${dupes.length>1?'s have':'has'} multiple folios — consolidate to simplify tracking
            </div>
          </div>
        </div>
        <button onclick="this.closest('#folioAlertBanner').style.display='none'"
          style="background:none;border:none;cursor:pointer;font-size:16px;color:#a16207;padding:2px 6px;">✕</button>
      </div>
      <div style="background:rgba(255,255,255,.6);border-radius:8px;padding:8px 12px;">${rows}</div>
      <div style="margin-top:10px;font-size:11px;color:#92400e;display:flex;gap:16px;flex-wrap:wrap;">
        <span>💡 Consolidation = <b>no capital gains tax</b> (not a redemption)</span>
        <a href="https://www.camsonline.com" target="_blank"
          style="color:#b45309;font-weight:700;text-decoration:underline;">Consolidate via CAMS ↗</a>
        <a href="https://kfintech.com" target="_blank"
          style="color:#b45309;font-weight:700;text-decoration:underline;">or KFintech ↗</a>
      </div>
    </div>`;
}

// ═══════════════════════════════════════════════════════════════════════════
// t175 — Rebalancing Alert
// User sets target allocation; if drift > threshold → alert with buy/sell guide
// ═══════════════════════════════════════════════════════════════════════════
const _REBAL_KEY = 'wd_rebal_targets';

function renderRebalancingAlert(holdings) {
  const wrap = document.getElementById('rebalAlertWrap');
  if (!wrap) return;

  // Load saved targets (or defaults)
  let targets;
  try { targets = JSON.parse(localStorage.getItem(_REBAL_KEY) || 'null'); } catch(e){}
  if (!targets) targets = { Equity:60, Debt:30, Other:10 };

  // Calculate current allocation by broad type
  const broadMap = {};
  let grandTotal = 0;
  (holdings || []).forEach(h => {
    const cat = _broadCat(h.category || '');
    const val = parseFloat(h.value_now) || 0;
    broadMap[cat] = (broadMap[cat] || 0) + val;
    grandTotal += val;
  });

  // Map to Equity / Debt / Other for simplicity
  const current = { Equity:0, Debt:0, Other:0 };
  Object.entries(broadMap).forEach(([cat, val]) => {
    if (cat === 'Equity' || cat === 'ELSS') current.Equity += val;
    else if (cat === 'Debt') current.Debt += val;
    else current.Other += val;
  });

  if (!grandTotal) { wrap.style.display = 'none'; return; }

  const currentPct = {};
  Object.keys(current).forEach(k => {
    currentPct[k] = grandTotal > 0 ? (current[k] / grandTotal * 100) : 0;
  });

  // Check drift
  const DRIFT_THRESHOLD = 5; // alert if any class drifts > 5%
  const drifts = Object.keys(targets).map(k => ({
    label: k,
    target: targets[k] || 0,
    current: currentPct[k] || 0,
    diff: (currentPct[k] || 0) - (targets[k] || 0),
    targetAmt: grandTotal * (targets[k] || 0) / 100,
    currentAmt: current[k] || 0,
  }));

  const hasDrift = drifts.some(d => Math.abs(d.diff) > DRIFT_THRESHOLD);

  const colorOf = (d) => {
    if (Math.abs(d.diff) <= 2) return '#16a34a';
    if (Math.abs(d.diff) <= 5) return '#d97706';
    return d.diff > 0 ? '#dc2626' : '#2563eb';
  };

  const driftRows = drifts.map(d => {
    const col = colorOf(d);
    const arrow = d.diff > 0.5 ? '▲' : d.diff < -0.5 ? '▼' : '✓';
    const barW  = Math.min(d.current, 100);
    const tgtW  = Math.min(d.target, 100);
    const action = Math.abs(d.diff) > DRIFT_THRESHOLD
      ? (d.diff > 0
          ? `<span style="color:#dc2626;font-size:10px;font-weight:700;">Sell ₹${fmtInr(Math.abs(d.currentAmt - d.targetAmt))}</span>`
          : `<span style="color:#2563eb;font-size:10px;font-weight:700;">Buy ₹${fmtInr(Math.abs(d.currentAmt - d.targetAmt))}</span>`)
      : `<span style="color:#16a34a;font-size:10px;">On target ✓</span>`;

    return `<div style="margin-bottom:10px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
        <span style="font-size:12px;font-weight:700;color:var(--text-primary);">${d.label}</span>
        <div style="display:flex;align-items:center;gap:8px;">
          ${action}
          <span style="font-size:12px;font-weight:800;color:${col};">${arrow} ${d.current.toFixed(1)}%</span>
          <span style="font-size:11px;color:var(--text-muted);">/ ${d.target}% target</span>
        </div>
      </div>
      <div style="position:relative;height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
        <div style="height:100%;width:${barW}%;background:${col};border-radius:4px;transition:width .4s;opacity:.85;"></div>
        <div style="position:absolute;top:0;height:100%;width:2px;background:#374151;left:${tgtW}%;"></div>
      </div>
    </div>`;
  }).join('');

  wrap.style.display = '';
  const alertColor = hasDrift ? '#fee2e2' : '#f0fdf4';
  const alertBorder = hasDrift ? '#fca5a5' : '#86efac';
  const alertTitle = hasDrift ? '⚠️ Portfolio Drift Detected — Rebalancing Needed' : '✅ Portfolio Well Balanced';
  const alertSub = hasDrift
    ? `Asset allocation has drifted beyond ±${DRIFT_THRESHOLD}% threshold`
    : 'All asset classes within target range';

  wrap.innerHTML = `
    <div style="background:${alertColor};border:1.5px solid ${alertBorder};border-radius:12px;padding:14px 16px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <div>
          <div style="font-weight:800;font-size:13px;color:var(--text-primary);">${alertTitle}</div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${alertSub}</div>
        </div>
        <button onclick="openRebalTargetEditor()" title="Edit targets"
          style="padding:5px 10px;border:1.5px solid var(--border);border-radius:7px;
                 background:var(--surface);font-size:11px;font-weight:700;cursor:pointer;color:var(--text-primary);">
          ⚙️ Set Targets
        </button>
      </div>
      <div style="background:rgba(255,255,255,.55);border-radius:8px;padding:10px 12px;">
        ${driftRows}
      </div>
      <div style="margin-top:8px;font-size:10px;color:var(--text-muted);">
        Target line (│) = your set allocation · Bar = current allocation · Drift alert threshold: ±${DRIFT_THRESHOLD}%
      </div>
    </div>

    <!-- Target editor modal -->
    <div id="rebalEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;
         display:none;align-items:center;justify-content:center;">
      <div style="background:var(--surface);border-radius:14px;padding:24px;width:340px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
        <div style="font-weight:800;font-size:15px;margin-bottom:16px;">⚙️ Set Target Allocation</div>
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:14px;">Must sum to 100%</div>
        ${['Equity','Debt','Other'].map(k=>`
        <div style="margin-bottom:12px;">
          <label style="font-size:12px;font-weight:700;color:var(--text-primary);display:block;margin-bottom:4px;">${k} %</label>
          <input type="number" id="rebalTarget_${k}" value="${targets[k]||0}" min="0" max="100"
            style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-weight:700;
                   background:var(--bg-secondary);color:var(--text-primary);">
        </div>`).join('')}
        <div id="rebalSumErr" style="font-size:11px;color:#dc2626;min-height:16px;margin-bottom:8px;"></div>
        <div style="display:flex;gap:8px;">
          <button onclick="saveRebalTargets()"
            style="flex:1;padding:9px;background:var(--accent);color:#fff;border:none;border-radius:8px;
                   font-weight:700;font-size:13px;cursor:pointer;">Save</button>
          <button onclick="document.getElementById('rebalEditModal').style.display='none'"
            style="padding:9px 16px;border:1.5px solid var(--border);border-radius:8px;
                   background:var(--surface);font-weight:700;font-size:13px;cursor:pointer;">Cancel</button>
        </div>
      </div>
    </div>`;
}

function openRebalTargetEditor() {
  const m = document.getElementById('rebalEditModal');
  if (m) { m.style.display = 'flex'; }
}

function saveRebalTargets() {
  const vals = {};
  let sum = 0;
  ['Equity','Debt','Other'].forEach(k => {
    vals[k] = parseFloat(document.getElementById('rebalTarget_' + k)?.value) || 0;
    sum += vals[k];
  });
  const err = document.getElementById('rebalSumErr');
  if (Math.abs(sum - 100) > 0.5) {
    if (err) err.textContent = `Sum = ${sum.toFixed(1)}% — must be exactly 100%`;
    return;
  }
  if (err) err.textContent = '';
  localStorage.setItem(_REBAL_KEY, JSON.stringify(vals));
  document.getElementById('rebalEditModal').style.display = 'none';
  renderRebalancingAlert(MF.data || []);
}

// ============================================================
// t170 — SIP Return Analysis: Lump Sum vs SIP Comparison
// Kab use karo: Fund card ya holdings row mein "SIP Analysis"
// button click karne par — modal opens with comparison
// ============================================================

function openSipReturnAnalysis(fundId, fundName, nav, firstPurchaseDate, totalInvested, valueNow) {
  const existing = document.getElementById('sipAnalysisModal');
  if (existing) existing.remove();

  const invested = parseFloat(totalInvested) || 0;
  const current  = parseFloat(valueNow)      || 0;
  const navVal   = parseFloat(nav)           || 0;

  // Auto-calculate defaults from holding data
  const startDate = firstPurchaseDate
    ? new Date(firstPurchaseDate)
    : new Date(Date.now() - 3 * 365 * 86400000); // default 3 years ago

  const today    = new Date();
  const yearsHeld = Math.max(0.08, (today - startDate) / (365.25 * 86400000));
  const months   = Math.round(yearsHeld * 12);

  // Estimate SIP amount: spread total invested over months
  const estSipAmt = months > 0 ? Math.round(invested / months / 100) * 100 : 5000;

  const modal = document.createElement('div');
  modal.id    = 'sipAnalysisModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;';

  modal.innerHTML = `
  <div style="background:#fff;border-radius:14px;width:560px;max-width:98vw;max-height:94vh;overflow-y:auto;
              box-shadow:0 24px 64px rgba(0,0,0,.2);">

    <!-- Header -->
    <div style="padding:18px 20px 14px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:15px;font-weight:800;color:#0f172a;">📊 SIP vs Lump Sum Analysis</div>
        <div style="font-size:12px;color:#64748b;margin-top:2px;">${escHtml(fundName)}</div>
      </div>
      <button id="sraClose" style="background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;line-height:1;">×</button>
    </div>

    <!-- Inputs -->
    <div style="padding:16px 20px;background:#f8fafc;border-bottom:1px solid #f1f5f9;">
      <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Scenario Configure Karo</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
        <div>
          <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:3px;">SIP Amount (₹/month)</label>
          <input id="sraSipAmt" type="number" min="100" step="100" value="${estSipAmt}"
            style="width:100%;padding:7px 9px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;outline:none;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:3px;">Duration (months)</label>
          <input id="sraDuration" type="number" min="1" max="360" value="${months > 0 ? months : 36}"
            style="width:100%;padding:7px 9px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;outline:none;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:3px;">Expected Annual Return (%)</label>
          <input id="sraReturn" type="number" min="1" max="50" step="0.5" value="12"
            style="width:100%;padding:7px 9px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;outline:none;box-sizing:border-box;">
        </div>
      </div>
      <button onclick="_sraCal()" style="margin-top:12px;width:100%;padding:9px;background:#2563eb;color:#fff;border:none;
        border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
        🔄 Calculate
      </button>
    </div>

    <!-- Results -->
    <div id="sraResults" style="padding:20px;"></div>

    <!-- Disclaimer -->
    <div style="padding:0 20px 16px;font-size:11px;color:#94a3b8;line-height:1.5;">
      ⚠️ Ye calculation assumed constant annual return pe based hai. Actual market returns variable hote hain.
      Historical data ke liye fund ka NAV chart dekho.
    </div>
  </div>`;

  document.body.appendChild(modal);
  document.getElementById('sraClose').onclick = () => modal.remove();
  modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });

  // Auto-calculate on open
  _sraCal();
}

function _sraCal() {
  const sipAmt   = Math.max(100, parseFloat(document.getElementById('sraSipAmt')?.value) || 5000);
  const months   = Math.max(1, Math.min(360, parseInt(document.getElementById('sraDuration')?.value) || 36));
  const annualR  = Math.max(0.1, Math.min(50, parseFloat(document.getElementById('sraReturn')?.value) || 12));

  const monthlyR  = annualR / 100 / 12;
  const totalSip  = sipAmt * months;
  const lumpSum   = totalSip; // same invested amount for fair comparison

  // SIP future value: FV = P × [((1+r)^n - 1) / r] × (1+r)
  const sipFV = monthlyR > 0
    ? sipAmt * (((Math.pow(1 + monthlyR, months) - 1) / monthlyR) * (1 + monthlyR))
    : totalSip;

  // Lump Sum future value: FV = P × (1+r)^n
  const lumpFV   = lumpSum * Math.pow(1 + annualR / 100, months / 12);

  const sipGain  = sipFV - totalSip;
  const lumpGain = lumpFV - lumpSum;

  // Rupee-cost averaging advantage (lump sum vs SIP invested cost)
  const sipWins  = sipFV >= lumpFV;
  const diff     = Math.abs(sipFV - lumpFV);
  const diffPct  = lumpFV > 0 ? (diff / lumpFV * 100).toFixed(1) : '0.0';

  const fmt = n => '₹' + Number(Math.round(n)).toLocaleString('en-IN');
  const pct = n => (n >= 0 ? '+' : '') + n.toFixed(2) + '%';
  const sipCAGR  = (Math.pow(sipFV / totalSip, 12 / months) - 1) * 100;
  const lsCAGR   = annualR;

  const res = document.getElementById('sraResults');
  if (!res) return;

  res.innerHTML = `
    <!-- Comparison Cards -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">

      <!-- SIP Card -->
      <div style="border:2px solid ${sipWins ? '#16a34a' : '#e2e8f0'};border-radius:10px;padding:14px;
                  background:${sipWins ? '#f0fdf4' : '#fff'};position:relative;">
        ${sipWins ? '<div style="position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:#16a34a;color:#fff;font-size:10px;font-weight:800;padding:2px 10px;border-radius:99px;white-space:nowrap;">🏆 WINNER</div>' : ''}
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:8px;">🔄 SIP Strategy</div>
        <div style="font-size:11px;color:#64748b;margin-bottom:3px;">Total Invested</div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:8px;">${fmt(totalSip)}</div>
        <div style="font-size:11px;color:#64748b;margin-bottom:3px;">Future Value</div>
        <div style="font-size:20px;font-weight:800;color:${sipWins ? '#16a34a' : '#0f172a'};margin-bottom:6px;">${fmt(sipFV)}</div>
        <div style="display:flex;justify-content:space-between;font-size:11px;border-top:1px solid #f1f5f9;padding-top:8px;margin-top:4px;">
          <span style="color:#64748b;">Gain</span>
          <span style="color:#16a34a;font-weight:700;">${fmt(sipGain)} (${pct(sipCAGR - annualR + annualR)})</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;margin-top:4px;">
          <span style="color:#64748b;">CAGR (approx)</span>
          <span style="font-weight:700;">${sipCAGR.toFixed(2)}%</span>
        </div>
        <div style="margin-top:10px;font-size:11px;color:#64748b;background:#f8fafc;border-radius:6px;padding:7px 9px;line-height:1.5;">
          <b>Rupee Cost Averaging</b> — Market girne par zyada units milte hain, chadhne par kam. Timing ki tension nahi.
        </div>
      </div>

      <!-- Lump Sum Card -->
      <div style="border:2px solid ${!sipWins ? '#2563eb' : '#e2e8f0'};border-radius:10px;padding:14px;
                  background:${!sipWins ? '#eff6ff' : '#fff'};position:relative;">
        ${!sipWins ? '<div style="position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:#2563eb;color:#fff;font-size:10px;font-weight:800;padding:2px 10px;border-radius:99px;white-space:nowrap;">🏆 WINNER</div>' : ''}
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:8px;">💰 Lump Sum Strategy</div>
        <div style="font-size:11px;color:#64748b;margin-bottom:3px;">Total Invested</div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:8px;">${fmt(lumpSum)}</div>
        <div style="font-size:11px;color:#64748b;margin-bottom:3px;">Future Value</div>
        <div style="font-size:20px;font-weight:800;color:${!sipWins ? '#2563eb' : '#0f172a'};margin-bottom:6px;">${fmt(lumpFV)}</div>
        <div style="display:flex;justify-content:space-between;font-size:11px;border-top:1px solid #f1f5f9;padding-top:8px;margin-top:4px;">
          <span style="color:#64748b;">Gain</span>
          <span style="color:#16a34a;font-weight:700;">${fmt(lumpGain)} (${pct(lsCAGR)})</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;margin-top:4px;">
          <span style="color:#64748b;">CAGR</span>
          <span style="font-weight:700;">${lsCAGR.toFixed(2)}%</span>
        </div>
        <div style="margin-top:10px;font-size:11px;color:#64748b;background:#f8fafc;border-radius:6px;padding:7px 9px;line-height:1.5;">
          <b>Full Compounding</b> — Poora paisa day 1 se kaam karta hai. Bull market mein unbeatable. Timing critical.
        </div>
      </div>
    </div>

    <!-- Verdict Banner -->
    <div style="background:${sipWins ? '#f0fdf4' : '#eff6ff'};border:1.5px solid ${sipWins ? '#86efac' : '#93c5fd'};
                border-radius:10px;padding:12px 16px;margin-bottom:14px;">
      <div style="font-size:13px;font-weight:700;color:${sipWins ? '#15803d' : '#1d4ed8'};margin-bottom:4px;">
        ${sipWins ? '🔄 SIP' : '💰 Lump Sum'} ${diffPct}% zyada deta hai is scenario mein
      </div>
      <div style="font-size:12px;color:#475569;line-height:1.6;">
        ${sipWins
          ? `Regular market mein SIP Rupee Cost Averaging ki wajah se better perform karta hai. Lump sum tab better hota hai jab aap market bottom pe invest karo.`
          : `Agar aap market timing pe confident hain aur ek saath bada amount invest kar sako, to Lump Sum ${diffPct}% zyada return dega. Lekin market timing mushkil hai.`
        }
      </div>
    </div>

    <!-- Summary Table -->
    <div style="border:1px solid #f1f5f9;border-radius:8px;overflow:hidden;font-size:12px;">
      <div style="background:#f8fafc;padding:8px 12px;font-weight:700;color:#475569;font-size:11px;text-transform:uppercase;">Quick Summary</div>
      <table style="width:100%;border-collapse:collapse;">
        <tr style="border-bottom:1px solid #f1f5f9;">
          <td style="padding:8px 12px;color:#64748b;">Duration</td>
          <td style="padding:8px 12px;font-weight:600;">${months} months (${(months/12).toFixed(1)} years)</td>
        </tr>
        <tr style="border-bottom:1px solid #f1f5f9;">
          <td style="padding:8px 12px;color:#64748b;">Monthly SIP Amount</td>
          <td style="padding:8px 12px;font-weight:600;">${fmt(sipAmt)}</td>
        </tr>
        <tr style="border-bottom:1px solid #f1f5f9;">
          <td style="padding:8px 12px;color:#64748b;">Total Investment (both)</td>
          <td style="padding:8px 12px;font-weight:600;">${fmt(totalSip)}</td>
        </tr>
        <tr style="border-bottom:1px solid #f1f5f9;">
          <td style="padding:8px 12px;color:#64748b;">Assumed Return</td>
          <td style="padding:8px 12px;font-weight:600;">${annualR}% p.a.</td>
        </tr>
        <tr style="border-bottom:1px solid #f1f5f9;">
          <td style="padding:8px 12px;color:#64748b;">SIP → Future Value</td>
          <td style="padding:8px 12px;font-weight:700;color:#16a34a;">${fmt(sipFV)}</td>
        </tr>
        <tr>
          <td style="padding:8px 12px;color:#64748b;">Lump Sum → Future Value</td>
          <td style="padding:8px 12px;font-weight:700;color:#2563eb;">${fmt(lumpFV)}</td>
        </tr>
      </table>
    </div>`;
}


/* ══════════════════════════════════════════════════════════════
   t182 — FUND NOTES (per-fund personal notes)
══════════════════════════════════════════════════════════════ */
MF.notes = {}; // { fund_id: { note, updated_at } }

async function loadFundNotes() {
  try {
    const res = await API.get('/api/router.php?action=fund_notes_get');
    MF.notes = res.notes || {};
    // Update note buttons for any visible funds
    Object.keys(MF.notes).forEach(fid => updateNoteBtnUI(parseInt(fid)));
  } catch(e) { /* silent */ }
}

function updateNoteBtnUI(fundId) {
  const lbl = document.getElementById(`noteLbl_${fundId}`);
  const btn = document.getElementById(`noteBtn_${fundId}`);
  if (!lbl || !btn) return;
  const hasNote = !!(MF.notes[fundId]?.note?.trim());
  lbl.textContent  = hasNote ? '📝 Note ✓' : 'Notes';
  btn.style.background     = hasNote ? 'rgba(234,179,8,.15)' : 'rgba(234,179,8,.06)';
  btn.style.borderColor    = hasNote ? '#d97706'             : 'rgba(234,179,8,.35)';
  btn.style.color          = hasNote ? '#92400e'             : '#b45309';
}

function openFundNoteModal(fundId, fundName) {
  const existing = MF.notes[fundId]?.note || '';
  // Create or reuse modal
  let modal = document.getElementById('fundNoteModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'fundNoteModal';
    modal.style.cssText = 'position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.45);';
    modal.innerHTML = `
      <div style="background:var(--bg-card);border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);width:420px;max-width:95vw;padding:0;overflow:hidden;" onclick="event.stopPropagation()">
        <div style="padding:16px 20px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;">
          <div>
            <div style="font-weight:700;font-size:14px;" id="fnmTitle">Notes</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;" id="fnmSub"></div>
          </div>
          <button onclick="closeFundNoteModal()" style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--text-muted);line-height:1;padding:4px;">✕</button>
        </div>
        <div style="padding:16px 20px;">
          <textarea id="fnmTextarea" rows="5"
            placeholder="Personal notes, investment thesis, reminders..."
            style="width:100%;padding:10px 12px;border:1.5px solid var(--border-color);border-radius:8px;background:var(--bg-secondary);color:var(--text-primary);font-size:13px;resize:vertical;font-family:inherit;line-height:1.5;box-sizing:border-box;"></textarea>
          <div style="display:flex;gap:8px;margin-top:12px;align-items:center;">
            <button id="fnmSaveBtn" onclick="saveFundNote()"
              style="flex:1;padding:8px 16px;border-radius:8px;background:var(--accent);color:#fff;border:none;font-size:13px;font-weight:700;cursor:pointer;">
              💾 Save
            </button>
            <button id="fnmDeleteBtn" onclick="deleteFundNote()"
              style="padding:8px 14px;border-radius:8px;border:1.5px solid rgba(239,68,68,.35);background:rgba(239,68,68,.06);color:#dc2626;font-size:12px;font-weight:600;cursor:pointer;">
              🗑 Delete
            </button>
          </div>
          <div id="fnmStatus" style="margin-top:8px;font-size:12px;text-align:center;min-height:16px;"></div>
        </div>
      </div>`;
    modal.addEventListener('click', closeFundNoteModal);
    document.body.appendChild(modal);
  }

  modal._fundId   = fundId;
  modal._fundName = fundName;
  document.getElementById('fnmTitle').textContent    = '📝 ' + (fundName.length > 40 ? fundName.slice(0,40)+'…' : fundName);
  const upd = MF.notes[fundId]?.updated_at;
  document.getElementById('fnmSub').textContent      = upd ? 'Last edited: ' + upd.slice(0,10) : 'No note yet';
  document.getElementById('fnmTextarea').value       = existing;
  document.getElementById('fnmStatus').textContent   = '';
  document.getElementById('fnmDeleteBtn').style.display = existing ? 'block' : 'none';
  modal.style.display = 'flex';
  setTimeout(() => document.getElementById('fnmTextarea')?.focus(), 50);
}

function closeFundNoteModal() {
  const m = document.getElementById('fundNoteModal');
  if (m) m.style.display = 'none';
}

async function saveFundNote() {
  const modal   = document.getElementById('fundNoteModal');
  const fundId  = modal._fundId;
  const note    = document.getElementById('fnmTextarea').value.trim();
  const status  = document.getElementById('fnmStatus');
  const saveBtn = document.getElementById('fnmSaveBtn');

  saveBtn.textContent = '⏳ Saving...'; saveBtn.disabled = true;
  try {
    await API.post('/api/router.php?action=fund_note_save', { fund_id: fundId, note });
    if (note) {
      MF.notes[fundId] = { note, updated_at: new Date().toISOString().slice(0,19).replace('T',' ') };
    } else {
      delete MF.notes[fundId];
    }
    updateNoteBtnUI(fundId);
    status.innerHTML = '<span style="color:#16a34a;font-weight:600;">✓ Saved!</span>';
    document.getElementById('fnmDeleteBtn').style.display = note ? 'block' : 'none';
    document.getElementById('fnmSub').textContent = note ? 'Last edited: ' + new Date().toISOString().slice(0,10) : 'No note yet';
    setTimeout(closeFundNoteModal, 800);
  } catch(e) {
    status.innerHTML = `<span style="color:#dc2626;">Error: ${e.message}</span>`;
  } finally {
    saveBtn.textContent = '💾 Save'; saveBtn.disabled = false;
  }
}

async function deleteFundNote() {
  const modal  = document.getElementById('fundNoteModal');
  const fundId = modal._fundId;
  if (!confirm('Delete this note?')) return;
  try {
    await API.post('/api/router.php?action=fund_note_delete', { fund_id: fundId });
    delete MF.notes[fundId];
    updateNoteBtnUI(fundId);
    closeFundNoteModal();
  } catch(e) { alert('Error: ' + e.message); }
}

/* ══════════════════════════════════════════════════════════════
   t75 — INVESTMENT CALENDAR (transaction heatmap)
   GitHub-style contribution graph — each cell = 1 day
   Green = buy, Red = sell, intensity ∝ amount
══════════════════════════════════════════════════════════════ */
MF.calYear   = new Date().getFullYear();
MF.calTxns   = null; // cached fetched transactions
MF.calLoaded = false;

async function initCalendarTab() {
  if (MF.calLoaded && MF.calYear === MF._calRenderedYear) return;
  renderCalendar();
}

async function renderCalendar() {
  const wrap = document.getElementById('tabCalendar');
  if (!wrap) return;

  wrap.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;padding:60px;">
    <div class="spinner"></div>
  </div>`;

  // Fetch all transactions if not cached
  if (!MF.calTxns) {
    try {
      const pid = window.WD?.selectedPortfolio || 0;
      const res = await API.get(`/api/mutual_funds/mf_list.php?view=transactions&portfolio_id=${pid}&per_page=5000&sort_col=txn_date&sort_dir=asc`);
      MF.calTxns = res.data || [];
      MF.calLoaded = true;
    } catch(e) {
      wrap.innerHTML = `<p style="color:var(--danger);text-align:center;padding:40px;">Failed to load transactions: ${e.message}</p>`;
      return;
    }
  }

  const year     = MF.calYear;
  const allTxns  = MF.calTxns;
  MF._calRenderedYear = year;

  // Get available years
  const years = [...new Set(allTxns.map(t => t.txn_date?.slice(0,4)).filter(Boolean))].sort();
  const minYear = parseInt(years[0] || year);
  const maxYear = new Date().getFullYear();

  // Group txns by date for this year
  const byDate = {}; // date → { buy: amount, sell: amount, txns: [] }
  const buyTypes  = new Set(['BUY','SWITCH_IN','DIV_REINVEST']);
  const sellTypes = new Set(['SELL','SWITCH_OUT','REDEMPTION']);

  allTxns.forEach(t => {
    if (!t.txn_date || t.txn_date.slice(0,4) !== String(year)) return;
    const d = t.txn_date.slice(0,10);
    if (!byDate[d]) byDate[d] = { buy:0, sell:0, txns:[] };
    const amt = Math.abs(parseFloat(t.amount||0));
    if (buyTypes.has(t.transaction_type))  byDate[d].buy  += amt;
    if (sellTypes.has(t.transaction_type)) byDate[d].sell += amt;
    byDate[d].txns.push(t);
  });

  // Stats
  const totalBuy  = Object.values(byDate).reduce((s,d)=>s+d.buy,0);
  const totalSell = Object.values(byDate).reduce((s,d)=>s+d.sell,0);
  const activeDays= Object.keys(byDate).length;
  const maxBuy    = Math.max(...Object.values(byDate).map(d=>d.buy), 1);
  const maxSell   = Math.max(...Object.values(byDate).map(d=>d.sell), 1);

  const fmt = v => v >= 100000 ? '₹'+(v/100000).toFixed(1)+'L' : v >= 1000 ? '₹'+(v/1000).toFixed(1)+'K' : '₹'+v.toFixed(0);

  function cellColor(d) {
    const info = byDate[d];
    if (!info) return 'var(--bg-secondary)';
    const hasBuy = info.buy > 0, hasSell = info.sell > 0;
    if (hasBuy && hasSell) return '#a78bfa'; // purple = mixed
    if (hasBuy) {
      const intensity = Math.min(info.buy / maxBuy, 1);
      if (intensity < 0.2)      return '#bbf7d0';
      if (intensity < 0.45)     return '#4ade80';
      if (intensity < 0.70)     return '#16a34a';
      return '#14532d';
    }
    if (hasSell) {
      const intensity = Math.min(info.sell / maxSell, 1);
      if (intensity < 0.33)     return '#fecaca';
      if (intensity < 0.66)     return '#f87171';
      return '#dc2626';
    }
    return 'var(--bg-secondary)';
  }

  // Build 12-month grid
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const days   = ['Su','Mo','Tu','We','Th','Fr','Sa'];

  let monthsHTML = '';
  for (let m = 0; m < 12; m++) {
    const firstDay = new Date(year, m, 1);
    const lastDate = new Date(year, m+1, 0).getDate();
    const startDow = firstDay.getDay(); // 0=Sun

    let cells = '';
    // Blank leading cells
    for (let i = 0; i < startDow; i++) {
      cells += `<div style="width:12px;height:12px;border-radius:2px;background:transparent;"></div>`;
    }
    for (let d = 1; d <= lastDate; d++) {
      const dateStr = `${year}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
      const info    = byDate[dateStr];
      const color   = cellColor(dateStr);
      const today   = new Date().toISOString().slice(0,10);
      const border  = dateStr === today ? '1.5px solid var(--accent)' : '1px solid transparent';
      const title   = info
        ? `${dateStr}\nBuy: ${fmt(info.buy)} | Sell: ${fmt(info.sell)}\n${info.txns.length} transaction(s)`
        : dateStr;
      cells += `<div onclick="showCalDayDetail('${dateStr}')" title="${title}"
        style="width:12px;height:12px;border-radius:2px;background:${color};border:${border};cursor:${info?'pointer':'default'};transition:transform .1s;"
        onmouseover="this.style.transform='scale(1.4)'" onmouseout="this.style.transform=''"
        data-caldate="${dateStr}"></div>`;
    }
    monthsHTML += `
      <div style="flex:1;min-width:80px;">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px;">${months[m]}</div>
        <div style="display:grid;grid-template-columns:repeat(7,12px);gap:2px;">${cells}</div>
      </div>`;
  }

  // Day labels
  const dayLabels = days.map(d=>`<div style="width:12px;text-align:center;font-size:9px;color:var(--text-muted);">${d}</div>`).join('');

  wrap.innerHTML = `
    <div style="padding:20px 0;">
      <!-- Header -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <button onclick="calPrevYear()" ${year<=minYear?'disabled':''} style="padding:5px 12px;border-radius:7px;border:1.5px solid var(--border-color);background:var(--bg-secondary);cursor:pointer;font-weight:700;color:var(--text-muted);${year<=minYear?'opacity:.4;cursor:not-allowed;':''}">‹</button>
          <span style="font-size:16px;font-weight:800;color:var(--text-primary);">${year}</span>
          <button onclick="calNextYear()" ${year>=maxYear?'disabled':''} style="padding:5px 12px;border-radius:7px;border:1.5px solid var(--border-color);background:var(--bg-secondary);cursor:pointer;font-weight:700;color:var(--text-muted);${year>=maxYear?'opacity:.4;cursor:not-allowed;':''}">›</button>
        </div>
        <!-- Stats pills -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <span style="font-size:12px;font-weight:700;padding:4px 12px;border-radius:99px;background:rgba(22,163,74,.1);color:#15803d;border:1px solid rgba(22,163,74,.2);">📈 Invested: ${fmt(totalBuy)}</span>
          <span style="font-size:12px;font-weight:700;padding:4px 12px;border-radius:99px;background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.2);">📤 Redeemed: ${fmt(totalSell)}</span>
          <span style="font-size:12px;font-weight:700;padding:4px 12px;border-radius:99px;background:rgba(37,99,235,.1);color:var(--accent);border:1px solid rgba(37,99,235,.2);">📅 Active Days: ${activeDays}</span>
        </div>
      </div>

      <!-- Legend -->
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:14px;flex-wrap:wrap;">
        <span style="font-size:11px;color:var(--text-muted);margin-right:4px;">Less</span>
        ${['#bbf7d0','#4ade80','#16a34a','#14532d'].map(c=>`<div style="width:12px;height:12px;border-radius:2px;background:${c};"></div>`).join('')}
        <span style="font-size:11px;color:var(--text-muted);">Buy &nbsp;&nbsp;</span>
        ${['#fecaca','#f87171','#dc2626'].map(c=>`<div style="width:12px;height:12px;border-radius:2px;background:${c};"></div>`).join('')}
        <span style="font-size:11px;color:var(--text-muted);">Sell &nbsp;&nbsp;</span>
        <div style="width:12px;height:12px;border-radius:2px;background:#a78bfa;"></div>
        <span style="font-size:11px;color:var(--text-muted);">Both</span>
        <span style="font-size:11px;color:var(--text-muted);margin-left:8px;">More</span>
      </div>

      <!-- Calendar grid -->
      <div style="overflow-x:auto;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;min-width:600px;">${monthsHTML}</div>
      </div>

      <!-- Day detail panel -->
      <div id="calDayDetail" style="display:none;margin-top:16px;padding:14px 16px;border:1.5px solid var(--border-color);border-radius:10px;background:var(--bg-card);"></div>
    </div>`;
}

function showCalDayDetail(dateStr) {
  const panel = document.getElementById('calDayDetail');
  if (!panel) return;
  const info = (MF.calTxns || []).filter(t => t.txn_date?.slice(0,10) === dateStr);
  if (!info.length) { panel.style.display='none'; return; }

  const buyTypes = new Set(['BUY','SWITCH_IN','DIV_REINVEST']);
  const typeColors = { BUY:'#16a34a', SELL:'#dc2626', SWITCH_IN:'#2563eb', SWITCH_OUT:'#f59e0b', DIV_REINVEST:'#7c3aed', REDEMPTION:'#dc2626' };
  const fmt = v => '₹' + parseFloat(v||0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});

  const rows = info.map(t => {
    const color = typeColors[t.transaction_type] || '#64748b';
    return `<tr style="border-bottom:1px solid var(--border-color);">
      <td style="padding:7px 10px;font-size:12px;font-weight:600;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(t.scheme_name||'—')}</td>
      <td style="padding:7px 10px;"><span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:${color}20;color:${color};">${t.transaction_type}</span></td>
      <td style="padding:7px 10px;font-size:12px;font-weight:700;text-align:right;">${fmt(t.amount)}</td>
      <td style="padding:7px 10px;font-size:12px;text-align:right;color:var(--text-muted);">${parseFloat(t.units||0).toFixed(4)}</td>
      <td style="padding:7px 10px;font-size:12px;text-align:right;color:var(--text-muted);">₹${parseFloat(t.nav||0).toFixed(4)}</td>
    </tr>`;
  }).join('');

  const d = new Date(dateStr);
  const label = d.toLocaleDateString('en-IN',{weekday:'long',year:'numeric',month:'long',day:'numeric'});

  panel.style.display = 'block';
  panel.innerHTML = `
    <div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:10px;">📅 ${label} — ${info.length} transaction(s)</div>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="background:var(--bg-secondary);">
          <th style="padding:6px 10px;text-align:left;font-size:11px;color:var(--text-muted);font-weight:700;">Fund</th>
          <th style="padding:6px 10px;text-align:left;font-size:11px;color:var(--text-muted);font-weight:700;">Type</th>
          <th style="padding:6px 10px;text-align:right;font-size:11px;color:var(--text-muted);font-weight:700;">Amount</th>
          <th style="padding:6px 10px;text-align:right;font-size:11px;color:var(--text-muted);font-weight:700;">Units</th>
          <th style="padding:6px 10px;text-align:right;font-size:11px;color:var(--text-muted);font-weight:700;">NAV</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;

  // Scroll into view
  setTimeout(() => panel.scrollIntoView({ behavior:'smooth', block:'nearest' }), 50);
}

function calPrevYear() {
  MF.calYear--;
  renderCalendar();
}
function calNextYear() {
  MF.calYear = Math.min(new Date().getFullYear(), MF.calYear + 1);
  renderCalendar();
}

/* ═══════════════════════════════════════════════════════════
   t498 — FY FINANCIAL DATES PANEL
   Important dates: Advance Tax, 80C, ITR, SIP Cut-offs, NSE Holidays
══════════════════════════════════════════════════════════════ */
MF._fyDatesRendered = false;

function renderFYDates() {
  const panel = document.getElementById('fyDatesPanel');
  if (!panel) return;

  const today = new Date();
  const todayStr = today.toISOString().slice(0,10);

  // ── FY 2025-26 Important Dates ─────────────────────────────────────────
  const FY_DATES = [
    // ── Advance Tax ──
    { date:'2025-06-15', label:'Advance Tax — 1st Instalment (15%)',     cat:'tax',     color:'#dc2626', bg:'#fff1f2', icon:'💰' },
    { date:'2025-09-15', label:'Advance Tax — 2nd Instalment (45%)',     cat:'tax',     color:'#dc2626', bg:'#fff1f2', icon:'💰' },
    { date:'2025-12-15', label:'Advance Tax — 3rd Instalment (75%)',     cat:'tax',     color:'#dc2626', bg:'#fff1f2', icon:'💰' },
    { date:'2026-03-15', label:'Advance Tax — Final Instalment (100%)',  cat:'tax',     color:'#dc2626', bg:'#fff1f2', icon:'💰' },
    // ── ITR ──
    { date:'2025-07-31', label:'ITR Filing Deadline (Salaried, AY 25-26)', cat:'tax',  color:'#b45309', bg:'#fffbeb', icon:'📄' },
    { date:'2025-10-31', label:'ITR Deadline (Audit cases, AY 25-26)',    cat:'tax',    color:'#b45309', bg:'#fffbeb', icon:'📄' },
    // ── 80C / ELSS ──
    { date:'2026-03-31', label:'80C Investment Deadline (₹1.5L limit)',  cat:'tax',     color:'#7c3aed', bg:'#f5f3ff', icon:'🛡️' },
    { date:'2026-03-31', label:'ELSS Last Date for FY 2025-26 80C',      cat:'tax',     color:'#7c3aed', bg:'#f5f3ff', icon:'🛡️' },
    { date:'2026-03-31', label:'PPF/NPS Contribution Deadline FY-end',   cat:'tax',     color:'#7c3aed', bg:'#f5f3ff', icon:'🛡️' },
    // ── LTCG / Tax Harvesting ──
    { date:'2025-04-01', label:'New FY begins — LTCG ₹1.25L fresh exemption resets', cat:'tax', color:'#0d9f57', bg:'#edfbf2', icon:'🌱' },
    { date:'2026-03-15', label:'Tax Loss Harvesting Window closes (book losses before 31st)', cat:'tax', color:'#0d9f57', bg:'#edfbf2', icon:'🌾' },
    // ── SIP Cut-offs ──
    { date:'2025-04-05', label:'SIP Cut-off: 5th — Same-day NAV if submitted before 3PM', cat:'sip', color:'#2563eb', bg:'#eff6ff', icon:'🔄' },
    { date:'2025-04-10', label:'SIP Cut-off: 10th — Popular SIP date for many AMCs',      cat:'sip', color:'#2563eb', bg:'#eff6ff', icon:'🔄' },
    { date:'2025-04-15', label:'SIP Cut-off: 15th — Mid-month SIP execution',              cat:'sip', color:'#2563eb', bg:'#eff6ff', icon:'🔄' },
    { date:'2025-05-05', label:'SIP Cut-off: 5th May',  cat:'sip', color:'#2563eb', bg:'#eff6ff', icon:'🔄' },
    { date:'2025-05-10', label:'SIP Cut-off: 10th May', cat:'sip', color:'#2563eb', bg:'#eff6ff', icon:'🔄' },
    { date:'2025-05-15', label:'SIP Cut-off: 15th May', cat:'sip', color:'#2563eb', bg:'#eff6ff', icon:'🔄' },
    { date:'2025-06-05', label:'SIP Cut-off: 5th Jun',  cat:'sip', color:'#2563eb', bg:'#eff6ff', icon:'🔄' },
    { date:'2025-06-10', label:'SIP Cut-off: 10th Jun', cat:'sip', color:'#2563eb', bg:'#eff6ff', icon:'🔄' },
    // ── NSE/BSE Market Holidays 2025-26 ──
    { date:'2025-04-10', label:'NSE/BSE Holiday — Mahavir Jayanti',      cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-04-14', label:'NSE/BSE Holiday — Dr. Ambedkar Jayanti', cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-04-18', label:'NSE/BSE Holiday — Good Friday',          cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-05-01', label:'NSE/BSE Holiday — Maharashtra Day',      cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-08-15', label:'NSE/BSE Holiday — Independence Day',     cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-08-27', label:'NSE/BSE Holiday — Ganesh Chaturthi',     cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-10-02', label:'NSE/BSE Holiday — Gandhi Jayanti',       cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-10-02', label:'NSE/BSE Holiday — Dussehra',             cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-10-20', label:'NSE/BSE Holiday — Diwali — Laxmi Puja (Muhurat)', cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-10-21', label:'NSE/BSE Holiday — Diwali — Balipratipada', cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-11-05', label:'NSE/BSE Holiday — Guru Nanak Jayanti',   cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2025-12-25', label:'NSE/BSE Holiday — Christmas',            cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2026-01-26', label:'NSE/BSE Holiday — Republic Day',         cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2026-02-19', label:'NSE/BSE Holiday — Chhatrapati Shivaji Maharaj Jayanti', cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2026-03-20', label:'NSE/BSE Holiday — Holi (2nd day)',       cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    { date:'2026-03-31', label:'NSE/BSE Holiday — Id-Ul-Fitr (tentative)', cat:'holiday', color:'#0f766e', bg:'#f0fdfa', icon:'🏖️' },
    // ── Budget ──
    { date:'2026-02-01', label:'Union Budget 2026-27 — Presented in Parliament', cat:'tax', color:'#c2410c', bg:'#fff7ed', icon:'🏛️' },
    // ── Misc ──
    { date:'2025-07-31', label:'SIP Review Reminder — Mid-FY portfolio check', cat:'sip', color:'#0891b2', bg:'#ecfeff', icon:'📊' },
    { date:'2026-01-31', label:'Q3 FY End — Review portfolio allocation', cat:'sip', color:'#0891b2', bg:'#ecfeff', icon:'📊' },
  ];

  // Sort by date
  FY_DATES.sort((a,b) => a.date.localeCompare(b.date));

  MF._fyDates = FY_DATES;
  fyDatesFilter('all', null);
}

function fyDatesFilter(cat, btn) {
  const panel = document.getElementById('fyDatesPanel');
  if (!panel || !MF._fyDates) return;

  // Update button styles
  document.querySelectorAll('.fy-filter-btn').forEach(b => {
    b.style.background = '#f8f9fc'; b.style.color = '#5a6882'; b.style.borderColor = '#e2e6f0';
  });
  if (btn) { btn.style.background='#eef2ff'; btn.style.color='#4338ca'; btn.style.borderColor='#c7d2fe'; }

  const todayStr = new Date().toISOString().slice(0,10);
  const filtered = cat === 'all' ? MF._fyDates : MF._fyDates.filter(d => d.cat === cat);

  if (!filtered.length) { panel.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:20px;">No dates in this category</p>'; return; }

  // Group by month
  const byMonth = {};
  filtered.forEach(d => {
    const mo = d.date.slice(0,7);
    if (!byMonth[mo]) byMonth[mo] = [];
    byMonth[mo].push(d);
  });

  const monthNames = { '01':'January','02':'February','03':'March','04':'April','05':'May','06':'June','07':'July','08':'August','09':'September','10':'October','11':'November','12':'December' };
  const catLabels  = { tax:'💰 Tax', sip:'🔄 SIP', holiday:'🏖️ Holiday' };

  let html = '';
  for (const [mo, dates] of Object.entries(byMonth)) {
    const [yr, mn] = mo.split('-');
    const moLabel  = `${monthNames[mn]} ${yr}`;
    const rows = dates.map(d => {
      const isPast   = d.date < todayStr;
      const isToday  = d.date === todayStr;
      const daysLeft = Math.ceil((new Date(d.date) - new Date(todayStr)) / 86400000);
      const dayNum   = parseInt(d.date.slice(8));
      const dayName  = new Date(d.date).toLocaleDateString('en-IN',{weekday:'short'});
      const urgency  = !isPast && daysLeft <= 7  ? '🔴'
                     : !isPast && daysLeft <= 30 ? '🟡' : '';
      return `<div style="display:flex;align-items:flex-start;gap:10px;padding:8px 10px;border-radius:8px;margin-bottom:6px;
                background:${isPast?'var(--bg-secondary)':d.bg};border:1.5px solid ${isPast?'var(--border-color)':d.color+'30'};
                opacity:${isPast?'.55':'1'};">
        <div style="flex-shrink:0;width:36px;text-align:center;border-radius:6px;padding:2px 0;background:${isPast?'var(--border-color)':d.color};color:#fff;">
          <div style="font-size:14px;font-weight:800;">${dayNum}</div>
          <div style="font-size:9px;font-weight:600;">${dayName}</div>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:12px;font-weight:700;color:${isPast?'var(--text-muted)':d.color};display:flex;align-items:center;gap:5px;">
            ${d.icon} ${d.label} ${urgency}
          </div>
          <div style="font-size:10px;color:var(--text-muted);margin-top:2px;display:flex;gap:8px;align-items:center;">
            <span style="padding:1px 6px;border-radius:3px;font-weight:700;background:${d.color}18;color:${d.color};">${catLabels[d.cat]||d.cat}</span>
            ${isPast
              ? `<span>✓ Done (${Math.abs(daysLeft)}d ago)</span>`
              : isToday
              ? `<span style="color:var(--accent);font-weight:700;">🎯 TODAY</span>`
              : `<span>In <b style="color:${daysLeft<=7?'#dc2626':daysLeft<=30?'#d97706':'var(--text-primary)'};">${daysLeft} days</b></span>`
            }
          </div>
        </div>
      </div>`;
    }).join('');
    html += `<div style="margin-bottom:16px;">
      <div style="font-size:11px;font-weight:800;color:var(--text-muted);letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;padding-bottom:4px;border-bottom:1.5px solid var(--border-color);">${moLabel}</div>
      ${rows}
    </div>`;
  }

  const upcoming = filtered.filter(d => d.date >= todayStr);
  const next = upcoming[0];
  const nextBanner = next
    ? `<div style="margin-bottom:14px;padding:10px 14px;border-radius:8px;background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1.5px solid #c7d2fe;display:flex;align-items:center;gap:10px;">
        <span style="font-size:20px;">${next.icon}</span>
        <div>
          <div style="font-size:11px;font-weight:700;color:#4338ca;text-transform:uppercase;letter-spacing:.4px;">Next Important Date</div>
          <div style="font-size:13px;font-weight:800;color:#1e1b4b;">${next.label}</div>
          <div style="font-size:11px;color:#6366f1;margin-top:2px;">${new Date(next.date).toLocaleDateString('en-IN',{day:'numeric',month:'long',year:'numeric'})} — In ${Math.ceil((new Date(next.date)-new Date(todayStr))/86400000)} days</div>
        </div>
       </div>`
    : '';

  panel.innerHTML = nextBanner + html;
}


/* ═══════════════════════════════════════════════════════════════════════════
   t173 — EXIT STRATEGY PLANNER
   Tax-optimal exit planning: LTCG eligibility, HIFO/FIFO, tax harvesting
═══════════════════════════════════════════════════════════════════════════ */
const EP = { loaded: false, method: 'FIFO' };
const LTCG_EXEMPTION = 125000; // ₹1.25L per FY (Budget 2024)
const EQUITY_LTCG_HOLD_DAYS = 365;  // 1 year for equity
const DEBT_LTCG_HOLD_DAYS   = 1095; // 3 years for debt

function initExitPlanner() {
  if (EP.loaded) return;
  renderExitPlanner();
}

async function renderExitPlanner() {
  const tbody = document.getElementById('epTableBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="8" class="text-center" style="padding:32px;"><div class="spinner"></div></td></tr>';

  const holdings = MF.data || [];
  if (!holdings.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="text-center" style="padding:32px;color:var(--text-muted);">No holdings found.</td></tr>';
    return;
  }

  // Use transaction data for lot-level calculations
  const pid = window.WD?.selectedPortfolio || 0;
  let txns = [];
  try {
    const r = await API.get(`/api/mutual_funds/mf_list.php?view=transactions&portfolio_id=${pid}&per_page=9999&sort_col=txn_date&sort_dir=asc`);
    txns = r.data || [];
  } catch(e) { /* use holdings-level fallback */ }

  const today = new Date();
  const fmtI = v => { v = Math.abs(v); return v >= 1e7 ? '₹'+(v/1e7).toFixed(2)+'Cr' : v >= 1e5 ? '₹'+(v/1e5).toFixed(2)+'L' : '₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0}); };

  // Compute lot-level LTCG for each holding
  let totalLtcgValue = 0, totalLtcgGain = 0, totalHarvestable = 0;
  const rows = [];

  for (const h of holdings) {
    const fundId    = h.fund_id || h.id;
    const nav       = parseFloat(h.nav_current || h.nav || 0);
    const invested  = parseFloat(h.invested || 0);
    const valueNow  = parseFloat(h.value_now || 0);
    const units     = parseFloat(h.units || 0);
    const avgNav    = units > 0 ? invested / units : 0;
    const cat       = (h.category || '').toLowerCase();
    const isDebt    = cat.includes('debt') || cat.includes('liquid') || cat.includes('gilt') || cat.includes('money market');
    const holdDays  = isDebt ? DEBT_LTCG_HOLD_DAYS : EQUITY_LTCG_HOLD_DAYS;

    // Get transactions for this fund
    const fundTxns = txns.filter(t =>
      String(t.fund_id) === String(fundId) &&
      ['BUY','SWITCH_IN','DIV_REINVEST'].includes(t.transaction_type)
    );

    // Build lots: each buy = one lot
    let lots = fundTxns.map(t => ({
      date  : new Date(t.txn_date),
      units : parseFloat(t.units || 0),
      nav   : parseFloat(t.nav || 0),
      cost  : parseFloat(t.value_at_cost || (parseFloat(t.units)*parseFloat(t.nav)) || 0),
    })).filter(l => l.units > 0);

    // If no lot data, synthesize one lot from holdings
    if (!lots.length && units > 0) {
      const buyDate = h.first_purchase_date ? new Date(h.first_purchase_date) : new Date(today - 400*86400*1000);
      lots = [{ date: buyDate, units, nav: avgNav, cost: invested }];
    }

    // Apply SELL transactions (FIFO deduction)
    const sells = txns.filter(t => String(t.fund_id) === String(fundId) && ['SELL','SWITCH_OUT'].includes(t.transaction_type));
    let remainSells = sells.reduce((s, t) => s + parseFloat(t.units || 0), 0);
    lots = lots.map(l => {
      if (remainSells <= 0) return l;
      const deduct = Math.min(l.units, remainSells);
      remainSells -= deduct;
      return { ...l, units: l.units - deduct };
    }).filter(l => l.units > 0.0001);

    // HIFO: sort by highest cost first to minimise gain
    const lotsForMethod = EP.method === 'HIFO'
      ? [...lots].sort((a, b) => b.nav - a.nav)
      : lots; // FIFO: already chronological

    // Partition LTCG eligible lots
    const ltcgLots  = lotsForMethod.filter(l => (today - l.date) / 86400000 >= holdDays);
    const stcgLots  = lotsForMethod.filter(l => (today - l.date) / 86400000 <  holdDays);

    const ltcgUnits = ltcgLots.reduce((s, l) => s + l.units, 0);
    const ltcgCost  = ltcgLots.reduce((s, l) => s + l.cost,  0);
    const ltcgVal   = ltcgUnits * nav;
    const ltcgGain  = ltcgVal - ltcgCost;

    const totalGain = valueNow - invested;
    const unrealGain= valueNow - invested;
    const gainPct   = invested > 0 ? ((valueNow - invested) / invested * 100).toFixed(1) : '0';
    const gainColor = unrealGain >= 0 ? 'var(--gain)' : 'var(--loss)';

    // Next LTCG date: earliest STCG lot that will become LTCG
    const nextLtcg  = stcgLots.length
      ? new Date(stcgLots[0].date.getTime() + holdDays * 86400000)
      : null;
    const daysToLtcg= nextLtcg ? Math.ceil((nextLtcg - today) / 86400000) : 0;

    // Recommendation logic
    let rec = '', recColor = 'var(--text-muted)';
    if (ltcgGain > 0 && ltcgGain <= LTCG_EXEMPTION * 0.9) {
      rec = '✅ Tax harvest eligible'; recColor = '#16a34a'; totalHarvestable += ltcgGain;
    } else if (ltcgGain > LTCG_EXEMPTION) {
      rec = `⚠️ ₹${((ltcgGain - LTCG_EXEMPTION)/1e5).toFixed(1)}L taxable LTCG`; recColor = '#d97706';
    } else if (daysToLtcg > 0 && daysToLtcg <= 30) {
      rec = `⏰ LTCG in ${daysToLtcg}d`; recColor = '#2563eb';
    } else if (daysToLtcg > 0) {
      rec = `${daysToLtcg}d to LTCG`; recColor = 'var(--text-muted)';
    } else if (ltcgUnits > 0) {
      rec = '📈 LTCG eligible'; recColor = '#16a34a';
    }

    totalLtcgValue += ltcgVal;
    totalLtcgGain  += Math.max(0, ltcgGain);

    rows.push({ h, nav, units, avgNav, invested, valueNow, unrealGain, gainPct, gainColor,
      ltcgUnits, ltcgVal, ltcgGain, rec, recColor, daysToLtcg });
  }

  // Update summary cards
  const setEl = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
  setEl('epLtcgValue', fmtI(totalLtcgValue));
  setEl('epLtcgGain',  fmtI(totalLtcgGain));
  setEl('epExemptLeft', fmtI(Math.max(0, LTCG_EXEMPTION - totalHarvestable)));
  setEl('epHarvestable', fmtI(totalHarvestable));

  // Tax harvest banner
  const banner = document.getElementById('epHarvestBanner');
  if (banner && totalHarvestable > 5000) {
    banner.style.display = '';
    const tax = Math.max(0, totalLtcgGain - LTCG_EXEMPTION) * 0.125;
    banner.innerHTML = `<strong>💡 Tax Harvesting Opportunity:</strong> You can book <strong>${fmtI(totalHarvestable)}</strong> in LTCG gains within ₹1.25L annual exemption — saving ~<strong>${fmtI(tax)}</strong> in taxes. Redeem eligible units, wait 1 day, reinvest.`;
  } else if (banner) { banner.style.display = 'none'; }

  // Build table rows
  tbody.innerHTML = rows.map(r => `
    <tr>
      <td>
        <div style="font-weight:600;font-size:13px;">${escHtml(r.h.scheme_name || r.h.fund_name || '—')}</div>
        <div style="font-size:11px;color:var(--text-muted);">${escHtml(r.h.category||'')}</div>
      </td>
      <td class="text-center" style="font-size:12px;">${r.units.toFixed(4)}</td>
      <td class="text-center" style="font-size:12px;color:${r.ltcgUnits > 0 ? '#16a34a' : 'var(--text-muted)'};">${r.ltcgUnits.toFixed(4)}</td>
      <td class="text-center" style="font-size:12px;">₹${r.avgNav.toFixed(4)}</td>
      <td class="text-center" style="font-size:12px;">₹${r.nav.toFixed(4)}</td>
      <td class="text-center" style="font-size:12px;color:${r.gainColor};font-weight:600;">
        ${fmtI(r.unrealGain)} <span style="font-size:10px;">(${r.gainPct}%)</span>
      </td>
      <td class="text-center" style="font-size:12px;">
        ${r.ltcgGain > 0
          ? `<span style="color:#16a34a;">LTCG: ${fmtI(r.ltcgGain)}</span>`
          : r.ltcgGain < 0
            ? `<span style="color:#dc2626;">LTCG Loss: ${fmtI(r.ltcgGain)}</span>`
            : '<span style="color:var(--text-muted);">—</span>'}
      </td>
      <td class="text-center" style="font-size:11px;font-weight:600;color:${r.recColor};">${r.rec || '—'}</td>
    </tr>`).join('');

  EP.loaded = true;
}

function toggleExitMethod(method, btn) {
  EP.method = method;
  EP.loaded = false;
  document.querySelectorAll('#epBtnFIFO,#epBtnHIFO').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderExitPlanner();
}

function calcSWP() {
  const monthly   = parseFloat(document.getElementById('swpAmount')?.value || 0);
  const annualRet = parseFloat(document.getElementById('swpReturn')?.value || 10) / 100;
  const corpus    = (MF.data || []).reduce((s, h) => s + parseFloat(h.value_now || 0), 0);
  const res       = document.getElementById('swpResult');
  if (!res || monthly <= 0 || corpus <= 0) return;

  const monthlyRet = annualRet / 12;
  // Months until corpus depletes: -log(1 - C*r/W) / log(1+r)
  let months = Infinity;
  if (monthly > corpus * monthlyRet) {
    months = monthlyRet > 0
      ? -Math.log(1 - corpus * monthlyRet / monthly) / Math.log(1 + monthlyRet)
      : corpus / monthly;
  }

  // Also calc: what monthly SWP sustains corpus forever
  const sustainableSWP = corpus * monthlyRet;
  const fmtI = v => v >= 1e7 ? '₹'+(v/1e7).toFixed(2)+'Cr' : v >= 1e5 ? '₹'+(v/1e5).toFixed(2)+'L' : '₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});

  res.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:14px;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Current Corpus</div>
        <div style="font-size:20px;font-weight:800;">${fmtI(corpus)}</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Corpus Lasts</div>
        <div style="font-size:20px;font-weight:800;color:${months === Infinity ? '#16a34a' : months > 240 ? '#16a34a' : months > 120 ? '#d97706' : '#dc2626'};">
          ${months === Infinity ? '∞ Forever' : months > 1200 ? '100+ yrs' : `${Math.floor(months/12)}y ${Math.round(months%12)}m`}
        </div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Sustainable SWP</div>
        <div style="font-size:20px;font-weight:800;color:#16a34a;">${fmtI(sustainableSWP)}/mo</div>
        <div style="font-size:11px;color:var(--text-muted);">Corpus stays intact forever</div>
      </div>
    </div>
    <div style="font-size:11px;color:var(--text-muted);padding:8px 12px;background:rgba(99,102,241,.06);border-radius:6px;">
      💡 At ${annualRet*100}% annual return, withdrawing <strong>${fmtI(monthly)}/month</strong>: 
      corpus ${months === Infinity ? 'never depletes' : `depletes in ${Math.floor(months/12)} years`}.
      To sustain corpus forever, keep SWP ≤ <strong>${fmtI(sustainableSWP)}/month</strong>.
    </div>`;
}

/* ═══════════════════════════════════════════════════════════════════════════
   t183 — PORTFOLIO REPORT CARD
   Grade: A+ to F based on XIRR, SIP consistency, expense ratio, diversification
═══════════════════════════════════════════════════════════════════════════ */
function renderPortfolioReportCard() {
  const wrap = document.getElementById('reportCardWrap');
  if (!wrap) return;
  const holdings = MF.data || [];
  if (!holdings.length) { wrap.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:20px;">No holdings data.</div>'; return; }

  // 1. XIRR grade (40% weight) — read from DOM
  const xirrEl  = document.getElementById('portfolioXirr');
  const xirr    = xirrEl ? parseFloat(xirrEl.textContent) : null;
  let xirrScore = 50; // default
  if (xirr !== null && !isNaN(xirr)) {
    if      (xirr >= 18) xirrScore = 100;
    else if (xirr >= 14) xirrScore = 85;
    else if (xirr >= 11) xirrScore = 70;
    else if (xirr >= 8)  xirrScore = 55;
    else if (xirr >= 5)  xirrScore = 35;
    else                  xirrScore = 15;
  }

  // 2. Expense ratio score (20% weight) — from holdings data
  const ersArr = holdings.map(h => parseFloat(h.expense_ratio || h.ter || 0)).filter(e => e > 0);
  const avgER  = ersArr.length ? ersArr.reduce((a, b) => a + b, 0) / ersArr.length : 0.8;
  let erScore  = 0;
  if      (avgER <= 0.3) erScore = 100;
  else if (avgER <= 0.5) erScore = 85;
  else if (avgER <= 0.8) erScore = 70;
  else if (avgER <= 1.2) erScore = 50;
  else if (avgER <= 1.8) erScore = 30;
  else                    erScore = 15;

  // 3. Diversification score (20% weight) — re-use correlation matrix logic
  const catMap = {
    'Equity — Large Cap':'LCE','Equity — Mid Cap':'MCE','Equity — Small Cap':'SCE',
    'Equity — Multi Cap':'MCE','Equity — Flexi Cap':'MCE','Index Fund':'IDX',
    'ELSS':'LCE','Debt — Short Duration':'SD','Debt — Long Duration':'LD',
    'Hybrid — Aggressive':'HYB','Hybrid — Conservative':'HYB',
  };
  const corrTable2 = { 'LCE-LCE':0.95,'LCE-MCE':0.78,'LCE-IDX':0.92,'MCE-MCE':0.95,'MCE-SCE':0.82,'SCE-SCE':0.95,'IDX-IDX':0.99,'HYB-HYB':0.90,'SD-SD':0.95,'LD-LD':0.95,'LCE-SD':0.10,'MCE-SD':0.08 };
  const funds  = holdings.slice(0, 8);
  let totalC2  = 0, pairs2 = 0;
  for (let i = 0; i < funds.length; i++)
    for (let j = i+1; j < funds.length; j++) {
      const ca = catMap[funds[i].category || ''] || 'OT', cb = catMap[funds[j].category || ''] || 'OT';
      const c  = corrTable2[`${ca}-${cb}`] ?? corrTable2[`${cb}-${ca}`] ?? (ca===cb ? 0.95 : 0.35);
      totalC2 += c; pairs2++;
    }
  const divScore2 = pairs2 > 0 ? Math.round((1 - totalC2 / pairs2) * 100) : 60;

  // 4. Fund count penalty (too many or too few)
  const fundCount = holdings.length;
  let countScore  = 70;
  if      (fundCount >= 4 && fundCount <= 8)  countScore = 100;
  else if (fundCount >= 3 && fundCount <= 10) countScore = 80;
  else if (fundCount === 2)                   countScore = 60;
  else if (fundCount > 10)                    countScore = 50;
  else if (fundCount === 1)                   countScore = 40;

  // Weighted total
  const total = Math.round(xirrScore * 0.40 + erScore * 0.20 + divScore2 * 0.20 + countScore * 0.20);

  const gradeMap = [
    [92, 'A+', '#16a34a', '🏆 Excellent — Top tier portfolio!'],
    [80, 'A',  '#22c55e', '✅ Very Good — Minor tweaks will perfect it.'],
    [68, 'B+', '#84cc16', '👍 Good — On track, small improvements possible.'],
    [56, 'B',  '#eab308', '⚠️ Average — Review your strategy.'],
    [44, 'C',  '#f97316', '🔶 Below average — Action needed.'],
    [0,  'F',  '#dc2626', '🚨 Poor — Major restructuring needed.'],
  ];
  const [, grade, gradeColor, gradeMsg] = gradeMap.find(([min]) => total >= min) || gradeMap.at(-1);

  const bar = (score, color='var(--accent)') =>
    `<div style="height:6px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
      <div style="width:${score}%;height:100%;background:${color};border-radius:99px;transition:width .6s;"></div>
     </div>`;

  wrap.innerHTML = `
    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;">
      <!-- Grade circle -->
      <div style="flex-shrink:0;width:110px;height:110px;border-radius:50%;background:${gradeColor}18;border:3px solid ${gradeColor};display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;">
        <div style="font-size:36px;font-weight:900;color:${gradeColor};line-height:1;">${grade}</div>
        <div style="font-size:12px;font-weight:700;color:${gradeColor};">${total}/100</div>
      </div>
      <!-- Score breakdown -->
      <div style="flex:1;min-width:220px;">
        <div style="font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:8px;">${gradeMsg}</div>
        <div style="display:flex;flex-direction:column;gap:10px;">
          ${[
            ['XIRR vs Benchmark (40%)', xirrScore, xirr !== null ? `${xirr?.toFixed(1) || '—'}% annualised` : 'No data'],
            ['Expense Ratio (20%)',     erScore,   avgER > 0 ? `Avg ${avgER.toFixed(2)}% TER` : 'No ER data'],
            ['Diversification (20%)',   divScore2, `Score: ${divScore2}/100`],
            ['Portfolio Structure (20%)', countScore, `${fundCount} fund${fundCount !== 1 ? 's' : ''} in portfolio`],
          ].map(([label, score, detail]) => `
            <div>
              <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                <span style="font-size:11px;font-weight:600;color:var(--text-primary);">${label}</span>
                <span style="font-size:11px;color:${score >= 70 ? '#16a34a' : score >= 50 ? '#d97706' : '#dc2626'};font-weight:700;">${score}/100 · ${detail}</span>
              </div>
              ${bar(score, score >= 70 ? '#16a34a' : score >= 50 ? '#d97706' : '#dc2626')}
            </div>`).join('')}
        </div>
      </div>
    </div>`;
}

/* ═══════════════════════════════════════════════════════════════════════════
   t131 — FACTOR EXPOSURE
   Large/Mid/Small · Value/Growth/Blend · Quality · Momentum
═══════════════════════════════════════════════════════════════════════════ */
function renderFactorExposure() {
  const wrap = document.getElementById('factorExposureWrap');
  if (!wrap) return;
  const holdings = MF.data || [];
  if (!holdings.length) { wrap.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:20px;">No holdings data.</div>'; return; }

  // Factor mapping from category
  const factorMap = {
    'Equity — Large Cap'     : { mc:'Large',  style:'Blend',  quality:70, momentum:55 },
    'Equity — Mid Cap'       : { mc:'Mid',    style:'Growth', quality:55, momentum:70 },
    'Equity — Small Cap'     : { mc:'Small',  style:'Growth', quality:40, momentum:80 },
    'Equity — Multi Cap'     : { mc:'Multi',  style:'Blend',  quality:60, momentum:65 },
    'Equity — Flexi Cap'     : { mc:'Flexi',  style:'Blend',  quality:65, momentum:60 },
    'Index Fund'             : { mc:'Large',  style:'Blend',  quality:65, momentum:50 },
    'ELSS'                   : { mc:'Multi',  style:'Growth', quality:60, momentum:65 },
    'Hybrid — Aggressive'    : { mc:'Large',  style:'Blend',  quality:65, momentum:50 },
    'Hybrid — Conservative'  : { mc:'Large',  style:'Value',  quality:70, momentum:40 },
    'Debt — Short Duration'  : { mc:'Debt',   style:'Value',  quality:80, momentum:30 },
    'Debt — Long Duration'   : { mc:'Debt',   style:'Value',  quality:75, momentum:35 },
    'Debt — Liquid'          : { mc:'Debt',   style:'Value',  quality:90, momentum:20 },
    'Gold'                   : { mc:'Gold',   style:'Value',  quality:60, momentum:50 },
  };

  // Weighted by value
  let totalVal = 0;
  const mcBuckets  = {};
  let wtQuality = 0, wtMomentum = 0;
  let wtGrowth = 0, wtValue = 0, wtBlend = 0;

  holdings.forEach(h => {
    const val  = parseFloat(h.value_now || 0);
    const cat  = h.category || 'Equity — Multi Cap';
    const f    = factorMap[cat] || { mc:'Multi', style:'Blend', quality:55, momentum:55 };
    totalVal += val;
    mcBuckets[f.mc] = (mcBuckets[f.mc] || 0) + val;
    wtQuality  += f.quality  * val;
    wtMomentum += f.momentum * val;
    if      (f.style === 'Growth') wtGrowth += val;
    else if (f.style === 'Value')  wtValue  += val;
    else                           wtBlend  += val;
  });
  if (totalVal <= 0) { wrap.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:20px;">Insufficient data.</div>'; return; }

  const qualityScore  = Math.round(wtQuality  / totalVal);
  const momentumScore = Math.round(wtMomentum / totalVal);
  const growthPct  = Math.round(wtGrowth / totalVal * 100);
  const valuePct   = Math.round(wtValue  / totalVal * 100);
  const blendPct   = Math.round(wtBlend  / totalVal * 100);

  // Cap allocation breakdown
  const mcOrder = ['Large','Multi','Flexi','Mid','Small','Debt','Gold'];
  const capRows = mcOrder
    .filter(mc => mcBuckets[mc])
    .map(mc => ({ mc, pct: Math.round(mcBuckets[mc] / totalVal * 100) }));

  const bar2 = (pct, color) =>
    `<div style="display:flex;align-items:center;gap:8px;">
      <div style="flex:1;height:8px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
        <div style="width:${pct}%;height:100%;background:${color};border-radius:99px;"></div>
      </div>
      <span style="font-size:11px;font-weight:700;min-width:32px;text-align:right;">${pct}%</span>
    </div>`;

  const mcColors = { Large:'#3b82f6', Mid:'#8b5cf6', Small:'#f59e0b', Multi:'#06b6d4', Flexi:'#10b981', Debt:'#6b7280', Gold:'#d97706' };

  wrap.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;flex-wrap:wrap;">
      <!-- Cap Allocation -->
      <div>
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;">📊 Market Cap Allocation</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          ${capRows.map(r => `
            <div>
              <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                <span style="font-size:12px;font-weight:600;">${r.mc} Cap</span>
              </div>
              ${bar2(r.pct, mcColors[r.mc] || '#64748b')}
            </div>`).join('')}
        </div>
      </div>
      <!-- Style & Quality -->
      <div>
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;">🎭 Investment Style</div>
        <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
          ${[['Growth',growthPct,'#8b5cf6'],['Blend',blendPct,'#3b82f6'],['Value',valuePct,'#16a34a']].map(([s,p,c]) =>
            `<div style="flex:1;min-width:70px;background:${c}18;border:1.5px solid ${c}40;border-radius:10px;padding:10px;text-align:center;">
              <div style="font-size:20px;font-weight:800;color:${c};">${p}%</div>
              <div style="font-size:11px;font-weight:600;color:${c};">${s}</div>
            </div>`).join('')}
        </div>
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">⚡ Factor Scores</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <div><div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span style="font-size:12px;font-weight:600;">Quality <i class="wd-info-btn" data-tip="High quality = stable earnings, low debt, strong ROE. Debt funds score high.">i</i></span></div>${bar2(qualityScore,'#16a34a')}</div>
          <div><div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span style="font-size:12px;font-weight:600;">Momentum <i class="wd-info-btn" data-tip="Momentum = how strongly this portfolio benefits from trending/growing sectors. Small cap = higher momentum.">i</i></span></div>${bar2(momentumScore,'#8b5cf6')}</div>
        </div>
      </div>
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:12px;padding:8px 12px;background:rgba(99,102,241,.06);border-radius:6px;">
      ⚠️ Factor scores are estimated from fund categories. For precise factor analysis, use AMFI portfolio disclosure data.
    </div>`;
}

/* ═══════════════════════════════════════════════════════════════════════════
   t158 — NOMINEE TRACKER (localStorage-based)
═══════════════════════════════════════════════════════════════════════════ */
const NOMINEE_KEY = 'wd_nominee_status_v2';
const NOMINEE_ASSETS = [
  { id:'mf',       label:'Mutual Funds',      icon:'📊', desc:'All MF folios' },
  { id:'nps',      label:'NPS Account',       icon:'🏛️', desc:'PRAN-wise nominee' },
  { id:'fd',       label:'Fixed Deposits',    icon:'🏦', desc:'FD certificates' },
  { id:'stocks',   label:'Demat / Stocks',    icon:'📈', desc:'DP nominee' },
  { id:'epf',      label:'EPF / PF',          icon:'🏭', desc:'Form 2 nominee' },
  { id:'ppf',      label:'PPF',               icon:'📋', desc:'PPF passbook nominee' },
  { id:'insurance',label:'Life Insurance',    icon:'🛡️', desc:'Policy nominee' },
  { id:'bank',     label:'Bank Accounts',     icon:'🏛️', desc:'Savings & current A/C' },
  { id:'postoffice',label:'Post Office',      icon:'📮', desc:'NSC / KVP / MIS' },
];
const NOMINEE_STATES = ['missing', 'done', 'minor'];
const NOMINEE_LABELS = { missing:'❌ Missing', done:'✅ Done', minor:'⚠️ Minor Nominee' };
const NOMINEE_COLORS = { missing:'#dc2626', done:'#16a34a', minor:'#d97706' };

function getNomineeData() {
  try { return JSON.parse(localStorage.getItem(NOMINEE_KEY) || '{}'); } catch { return {}; }
}

function renderNomineeTracker() {
  const body = document.getElementById('nomineeTrackerBody');
  if (!body) return;
  const data = getNomineeData();
  let missing = 0, done = 0, minor = 0;

  const grid = NOMINEE_ASSETS.map(asset => {
    const state = data[asset.id] || 'missing';
    if (state === 'missing') missing++;
    else if (state === 'done') done++;
    else minor++;
    const color = NOMINEE_COLORS[state];
    const label = NOMINEE_LABELS[state];
    return `
      <div onclick="cycleNominee('${asset.id}')" style="cursor:pointer;background:var(--bg-secondary);border-radius:10px;padding:12px 14px;border:1.5px solid ${color}30;transition:all .15s;" class="nominee-tile" data-id="${asset.id}">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
          <span style="font-size:18px;">${asset.icon}</span>
          <span style="font-size:13px;font-weight:700;color:var(--text-primary);">${asset.label}</span>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:8px;">${asset.desc}</div>
        <div style="font-size:11px;font-weight:700;color:${color};padding:3px 8px;background:${color}15;border-radius:99px;display:inline-block;">${label}</div>
      </div>`;
  }).join('');

  // Summary alert
  const total = NOMINEE_ASSETS.length;
  const alertColor = missing > 0 ? '#dc2626' : minor > 0 ? '#d97706' : '#16a34a';
  const alertMsg = missing > 0
    ? `🚨 ${missing} asset${missing>1?'s':''} have NO nominee. Update immediately!`
    : minor > 0
      ? `⚠️ ${minor} asset${minor>1?'s':''} have minor nominee — ensure guardian name is filled.`
      : '✅ All assets have nominees on file. Review every 2 years.';

  body.innerHTML = `
    <div style="padding:10px 14px;border-radius:8px;background:${alertColor}12;border:1.5px solid ${alertColor}40;font-size:12px;font-weight:600;color:${alertColor};margin-bottom:14px;">${alertMsg}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;">${grid}</div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:10px;">${done}/${total} complete · ${minor} minor nominee · ${missing} missing</div>`;
}

function cycleNominee(id) {
  const data  = getNomineeData();
  const cur   = data[id] || 'missing';
  const next  = NOMINEE_STATES[(NOMINEE_STATES.indexOf(cur) + 1) % NOMINEE_STATES.length];
  data[id]    = next;
  localStorage.setItem(NOMINEE_KEY, JSON.stringify(data));
  renderNomineeTracker();
}

function resetNomineeData() {
  if (confirm('Nominee tracker data reset karo? Sab "Missing" ho jayega.')) {
    localStorage.removeItem(NOMINEE_KEY);
    renderNomineeTracker();
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   t130 — CASH DRAG ANALYSIS
   Idle cash opportunity cost calculator
═══════════════════════════════════════════════════════════════════════════ */
async function renderCashDrag() {
  const wrap = document.getElementById('cashDragWrap');
  if (!wrap) return;

  // Try to fetch savings balance
  let savingsBalance = 0;
  try {
    const r = await API.get('/api/?action=savings_summary');
    savingsBalance = parseFloat(r.data?.total_balance || r.total_balance || 0);
  } catch { /* fallback: use 0 */ }

  // Emergency fund (heuristic: 6 months of monthly investment amount as proxy)
  const holdings     = MF.data || [];
  const totalInvested= holdings.reduce((s, h) => s + parseFloat(h.invested || 0), 0);
  const sipTxns      = (MF.calTxns || []).filter(t => t.transaction_type === 'BUY');
  // Monthly SIP estimate from last 6 months
  const sixMonthsAgo = new Date(); sixMonthsAgo.setMonth(sixMonthsAgo.getMonth() - 6);
  const recentSip    = sipTxns.filter(t => new Date(t.txn_date) > sixMonthsAgo);
  const monthlySip   = recentSip.length ? recentSip.reduce((s, t) => s + parseFloat(t.amount || 0), 0) / 6 : 10000;
  const emergencyFund= monthlySip * 6;

  // Opportunity cost (savings rate ~4%, liquid fund ~7%)
  const savingsRate   = 0.04;
  const liquidRate    = 0.07;
  const idle          = Math.max(0, savingsBalance - emergencyFund);
  const savingsReturn = idle * savingsRate;
  const liquidReturn  = idle * liquidRate;
  const opportunityCost = liquidReturn - savingsReturn;

  const fmtI = v => { v = Math.abs(v); return v >= 1e7 ? '₹'+(v/1e7).toFixed(2)+'Cr' : v >= 1e5 ? '₹'+(v/1e5).toFixed(2)+'L' : '₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0}); };

  if (savingsBalance <= 0) {
    wrap.innerHTML = `
      <div style="color:var(--text-muted);font-size:13px;padding:12px;">
        <strong>💡 Cash Drag Analysis</strong><br><br>
        Add your savings account balance in the <strong>Savings</strong> section to see idle cash analysis.
        <br><br>
        <strong>How it works:</strong> If you have more than 6× monthly expenses in a savings account (earning ~4%), the excess could earn more in a liquid mutual fund (~7%). The difference is your "cash drag" — money lost to sub-optimal placement.
      </div>`;
    return;
  }

  const adequacy = savingsBalance >= emergencyFund;
  wrap.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:16px;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Savings Balance</div>
        <div style="font-size:18px;font-weight:800;">${fmtI(savingsBalance)}</div>
      </div>
      <div style="background:${adequacy?'rgba(22,163,74,.08)':'rgba(220,38,38,.08)'};border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Emergency Fund (6mo)</div>
        <div style="font-size:18px;font-weight:800;color:${adequacy?'#16a34a':'#dc2626'};">${fmtI(emergencyFund)}</div>
        <div style="font-size:11px;color:${adequacy?'#16a34a':'#dc2626'};">${adequacy?'✅ Adequate':'⚠️ Insufficient'}</div>
      </div>
      <div style="background:${idle>0?'rgba(234,179,8,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Idle Cash</div>
        <div style="font-size:18px;font-weight:800;color:${idle>0?'#d97706':'var(--text-primary)'};">${idle>0?fmtI(idle):'₹0'}</div>
        <div style="font-size:11px;color:var(--text-muted);">Above emergency fund</div>
      </div>
      <div style="background:${opportunityCost>0?'rgba(220,38,38,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Annual Opportunity Cost</div>
        <div style="font-size:18px;font-weight:800;color:${opportunityCost>0?'#dc2626':'#16a34a'};">${opportunityCost>0?'-'+fmtI(opportunityCost):'₹0'}</div>
        <div style="font-size:11px;color:var(--text-muted);">Savings vs Liquid Fund</div>
      </div>
    </div>
    ${idle > 5000 ? `
    <div style="padding:12px 16px;border-radius:10px;background:rgba(234,179,8,.08);border:1.5px solid rgba(234,179,8,.3);font-size:12px;">
      💡 <strong>Suggestion:</strong> Move <strong>${fmtI(idle)}</strong> from savings (~4%/yr) to a liquid fund (~7%/yr).
      This can earn an extra <strong>${fmtI(opportunityCost)}/year</strong> with minimal risk and instant redemption (T+1).
      Recommended: <em>Nippon India Liquid Fund, HDFC Liquid Fund, SBI Liquid Fund</em>.
    </div>` : `<div style="padding:12px;font-size:12px;color:var(--text-muted);background:rgba(22,163,74,.06);border-radius:8px;">✅ Cash allocation looks optimal. Emergency fund is adequately funded.</div>`}`;
}

/* ═══════════════════════════════════════════════════════════════════════════
   PHASE 79 — MF INTELLIGENCE ENGINE
   tmfi01: Portfolio Health Score (SVG circular gauge)
   tmfi02: Fund Recommendation Engine
   tmfi03: Portfolio Risk Analysis
   tmfi04: Smart Insight Cards
   tmfi05: Tax Loss Harvesting Engine
   tmfi06: What-If Simulator
   tmfi07: Auto Portfolio Cleanup
═══════════════════════════════════════════════════════════════════════════ */

/* ── HELPERS ── */
function _mfi_fmt(v) {
  v = Math.abs(v);
  return v >= 1e7 ? '₹'+(v/1e7).toFixed(2)+'Cr'
       : v >= 1e5 ? '₹'+(v/1e5).toFixed(2)+'L'
       : '₹'+Math.round(v).toLocaleString('en-IN');
}

function _mfi_fmtRaw(v) {
  return Math.abs(v) >= 1e7 ? (v/1e7).toFixed(2)+'Cr'
       : Math.abs(v) >= 1e5 ? (v/1e5).toFixed(2)+'L'
       : Math.round(v).toLocaleString('en-IN');
}

/* ══════════════════════════════════════════════════════════════
   tmfi01 — PORTFOLIO HEALTH SCORE (SVG Circular Gauge)
══════════════════════════════════════════════════════════════ */
function calcMfiHealthScore(holdings) {
  if (!holdings || !holdings.length) return null;
  const totalVal = holdings.reduce((s,h)=>s+parseFloat(h.value_now||0),0);
  if (totalVal <= 0) return null;

  let scores = { returns:0, diversification:0, risk:0, cost:0, consistency:0 };
  const issues = [], suggestions = [];

  // 1. Returns (30 pts) — based on avg XIRR vs benchmark 12%
  const fundsWithXirr = holdings.filter(h => parseFloat(h.xirr||0) > 0);
  if (fundsWithXirr.length) {
    const weightedXirr = fundsWithXirr.reduce((s,h) => {
      const w = parseFloat(h.value_now||0)/totalVal;
      return s + (parseFloat(h.xirr||0)*w);
    },0);
    scores.returns = Math.min(30, Math.max(0, 15 + (weightedXirr - 12) * 1.5));
    if (weightedXirr < 8) { issues.push({sev:'error', msg:`Portfolio XIRR ${weightedXirr.toFixed(1)}% — below 8%. Review underperformers.`}); }
    else if (weightedXirr > 15) { suggestions.push(`Excellent returns! XIRR ${weightedXirr.toFixed(1)}% — top quartile performance.`); }
  } else {
    scores.returns = 18; // neutral if no XIRR data
  }

  // 2. Diversification (25 pts)
  const n = holdings.length;
  let divScore = n >= 3 && n <= 7 ? 25 : n === 1 ? 10 : n === 2 ? 18 : n <= 10 ? 22 : 15;
  // Check concentration
  const maxConc = Math.max(...holdings.map(h => parseFloat(h.value_now||0)/totalVal*100));
  if (maxConc > 50) { divScore -= 10; issues.push({sev:'warn', msg:`One fund is ${maxConc.toFixed(0)}% of portfolio — too concentrated.`}); }
  else if (maxConc > 40) { divScore -= 5; }
  scores.diversification = Math.max(0, divScore);

  // Check category mix
  const cats = [...new Set(holdings.map(h=>(h.category||'').toLowerCase().split(' ')[0]))];
  if (cats.length < 2) issues.push({sev:'warn', msg:'All funds in same category — add diversification across equity/debt.'});

  // 3. Risk (20 pts) — via avg drawdown
  const drawdowns = holdings.filter(h=>parseFloat(h.drawdown_pct||0)<0);
  const avgDrawdown = drawdowns.length
    ? drawdowns.reduce((s,h)=>s+parseFloat(h.drawdown_pct||0),0)/drawdowns.length : 0;
  scores.risk = Math.min(20, Math.max(0, 20 + avgDrawdown * 0.4));
  if (avgDrawdown < -25) issues.push({sev:'warn', msg:`Avg drawdown ${avgDrawdown.toFixed(1)}% — portfolio has high downside risk.`});

  // 4. Cost (15 pts) — expense ratio
  const expRatios = holdings.filter(h=>parseFloat(h.expense_ratio||0)>0);
  const avgExp = expRatios.length ? expRatios.reduce((s,h)=>s+parseFloat(h.expense_ratio||0),0)/expRatios.length : 1;
  scores.cost = Math.min(15, Math.max(0, 15 - (avgExp - 0.3) * 5));
  const regularFunds = holdings.filter(h=>(h.plan_type||'').toLowerCase().includes('regular'));
  if (regularFunds.length) { scores.cost -= 5; issues.push({sev:'warn', msg:`${regularFunds.length} Regular plan(s) detected — switch to Direct to save ~0.7% p.a.`}); }
  if (avgExp < 0.5) suggestions.push(`Low avg expense ratio ${avgExp.toFixed(2)}% — excellent for long-term wealth.`);

  // 5. Consistency (10 pts) — SIP discipline
  scores.consistency = 8; // Default neutral, would need SIP history for full calc
  if (n >= 3) suggestions.push(`${n} funds held — well-structured portfolio.`);

  const total = Math.round(Object.values(scores).reduce((s,v)=>s+v,0));
  const grade = total >= 85?'A+':total>=75?'A':total>=65?'B+':total>=55?'B':total>=45?'C':'D';
  const color = total>=75?'#16a34a':total>=55?'#2563eb':total>=40?'#d97706':'#dc2626';

  return { total, grade, color, scores, issues, suggestions };
}

function renderMfiHealthScore(holdings) {
  const el = document.getElementById('mfiHealthGauge');
  if (!el) return;
  const r = calcMfiHealthScore(holdings);
  if (!r) { el.style.display='none'; return; }
  el.style.display = '';

  const pct = r.total;
  const R = 54, cx = 64, cy = 64;
  const circ = 2 * Math.PI * R;
  const dash = (pct/100)*circ;
  const gap  = circ - dash;

  const components = [
    {label:'Returns',      val:r.scores.returns,      max:30, color:'#6366f1'},
    {label:'Diversif.',    val:r.scores.diversification, max:25, color:'#0ea5e9'},
    {label:'Risk Mgmt',   val:r.scores.risk,          max:20, color:'#10b981'},
    {label:'Cost',         val:r.scores.cost,          max:15, color:'#f59e0b'},
    {label:'Consistency',  val:r.scores.consistency,   max:10, color:'#8b5cf6'},
  ];

  el.innerHTML = `
    <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap;">
      <!-- SVG Gauge -->
      <div style="text-align:center;flex-shrink:0;">
        <svg width="128" height="128" viewBox="0 0 128 128">
          <circle cx="${cx}" cy="${cy}" r="${R}" fill="none" stroke="var(--border-color,#e5e7eb)" stroke-width="12"/>
          <circle cx="${cx}" cy="${cy}" r="${R}" fill="none" stroke="${r.color}" stroke-width="12"
            stroke-linecap="round"
            stroke-dasharray="${dash} ${gap}"
            stroke-dashoffset="${circ/4}"
            style="transition:stroke-dasharray .8s ease;"/>
          <text x="${cx}" y="${cy-6}" text-anchor="middle" font-size="22" font-weight="900" fill="${r.color}">${pct}</text>
          <text x="${cx}" y="${cy+12}" text-anchor="middle" font-size="11" font-weight="700" fill="#6b7280">/ 100</text>
          <text x="${cx}" y="${cy+26}" text-anchor="middle" font-size="13" font-weight="800" fill="${r.color}">${r.grade}</text>
        </svg>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-top:2px;">Health Score</div>
      </div>
      <!-- Score Breakdown -->
      <div style="flex:1;min-width:180px;">
        <div style="font-size:13px;font-weight:800;margin-bottom:10px;color:var(--text-primary);">Score Breakdown</div>
        ${components.map(c=>{
          const p=Math.round(c.val/c.max*100);
          return `<div style="margin-bottom:7px;">
            <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px;">
              <span style="font-weight:600;">${c.label}</span>
              <span style="font-weight:700;color:${c.color};">${Math.round(c.val)}/${c.max}</span>
            </div>
            <div style="height:5px;background:var(--border-color,#e5e7eb);border-radius:99px;overflow:hidden;">
              <div style="width:${p}%;height:100%;background:${c.color};border-radius:99px;transition:width .6s;"></div>
            </div>
          </div>`;
        }).join('')}
      </div>
      <!-- Issues + Positives -->
      <div style="flex:1;min-width:200px;">
        ${r.issues.length ? `
          <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:${r.issues.some(i=>i.sev==='error')?'#dc2626':'#d97706'};margin-bottom:6px;">⚠ Issues</div>
          ${r.issues.map(i=>`<div style="font-size:11px;color:${i.sev==='error'?'#b91c1c':'#92400e'};margin-bottom:4px;padding:4px 8px;background:${i.sev==='error'?'rgba(220,38,38,.06)':'rgba(234,179,8,.06)'};border-radius:6px;border-left:2px solid ${i.sev==='error'?'#dc2626':'#d97706'};">${i.msg}</div>`).join('')}
        ` : ''}
        ${r.suggestions.length ? `
          <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#16a34a;margin-top:8px;margin-bottom:6px;">✓ Strengths</div>
          ${r.suggestions.map(s=>`<div style="font-size:11px;color:#15803d;margin-bottom:4px;padding:4px 8px;background:rgba(22,163,74,.06);border-radius:6px;border-left:2px solid #16a34a;">${s}</div>`).join('')}
        ` : ''}
      </div>
    </div>`;
}


/* ══════════════════════════════════════════════════════════════
   tmfi02 — FUND RECOMMENDATION ENGINE
══════════════════════════════════════════════════════════════ */
function renderMfiRecommendations(holdings) {
  const el = document.getElementById('mfiRecommendations');
  if (!el) return;

  // Age from profile (localStorage fallback)
  let userAge = 30;
  try { const p=JSON.parse(localStorage.getItem('wd_user_profile')||'{}'); userAge=parseInt(p.age||30); } catch{}

  // Risk profile from profile or portfolio composition
  let risk = 'moderate';
  const equityPct = holdings.filter(h=>{
    const cat=(h.category||'').toLowerCase();
    return cat.includes('equity')||cat.includes('large')||cat.includes('mid')||cat.includes('small')||cat.includes('elss');
  }).reduce((s,h)=>s+parseFloat(h.value_now||0),0) / Math.max(1,holdings.reduce((s,h)=>s+parseFloat(h.value_now||0),0)) * 100;

  if (equityPct > 80 || userAge < 30) risk = 'aggressive';
  else if (equityPct < 40 || userAge > 55) risk = 'conservative';

  const RECS = {
    aggressive: [
      {cat:'Large & Mid Cap',  why:'Best of both: stability of large + growth of mid cap', eff:'1Y return usually 14–22%', action:'Core holding (30%)'},
      {cat:'Small Cap',        why:'High growth potential, higher volatility — suits young investors', eff:'Best for 7+ year horizon', action:'Satellite (20%)'},
      {cat:'Flexi Cap',        why:'Fund manager picks across market caps as per market conditions', eff:'Dynamically rebalanced', action:'Core holding (20%)'},
    ],
    moderate: [
      {cat:'Large Cap Index',  why:'Low cost Nifty 50 index fund — consistent, no manager risk', eff:'Avg 12–14% long term', action:'Core holding (35%)'},
      {cat:'Hybrid Aggressive',why:'60:40 equity-debt mix managed actively', eff:'Better than FD, lower risk than pure equity', action:'Core holding (25%)'},
      {cat:'Mid Cap',          why:'Mid-sized companies with strong growth potential', eff:'High growth, medium risk', action:'Growth allocation (15%)'},
    ],
    conservative: [
      {cat:'Large Cap',        why:'Blue-chip companies, stable returns, less volatility', eff:'12–14% over 5+ years', action:'Core holding (40%)'},
      {cat:'Hybrid Conservative', why:'75% debt + 25% equity — capital protection with growth', eff:'Lower volatility, steady returns', action:'Core holding (30%)'},
      {cat:'Short Duration Debt',  why:'Better than FD, lower interest rate risk', eff:'7–8% typically', action:'Debt allocation (20%)'},
    ]
  };

  const recs = RECS[risk] || RECS.moderate;
  const riskLabel = {aggressive:'🔥 Aggressive', moderate:'⚖️ Moderate', conservative:'🛡️ Conservative'}[risk];

  el.style.display='';
  el.innerHTML = `
    <div style="margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <span style="font-size:12px;color:var(--text-muted);">Detected profile:</span>
      <span style="font-size:12px;font-weight:700;padding:3px 10px;border-radius:99px;background:${risk==='aggressive'?'rgba(220,38,38,.1)':risk==='conservative'?'rgba(99,102,241,.1)':'rgba(16,185,129,.1)'};color:${risk==='aggressive'?'#dc2626':risk==='conservative'?'#4f46e5':'#059669'};">${riskLabel}</span>
      <span style="font-size:11px;color:var(--text-muted);">Age ${userAge} · Equity ${equityPct.toFixed(0)}%</span>
      <button onclick="openMfiProfileModal()" style="font-size:10px;padding:2px 8px;border-radius:5px;border:1px solid var(--border-color);background:transparent;cursor:pointer;color:var(--text-muted);">⚙ Adjust Profile</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      ${recs.map(r=>`
        <div style="background:var(--bg-secondary,#f8fafc);border:1.5px solid var(--border-color);border-radius:10px;padding:14px;">
          <div style="font-size:12px;font-weight:800;color:var(--text-primary);margin-bottom:5px;">📊 ${r.cat}</div>
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;line-height:1.6;">${r.why}</div>
          <div style="font-size:11px;color:#6366f1;font-weight:600;margin-bottom:8px;">📈 ${r.eff}</div>
          <div style="font-size:10px;padding:3px 8px;border-radius:5px;background:rgba(99,102,241,.08);color:#4f46e5;display:inline-block;font-weight:700;">${r.action}</div>
        </div>
      `).join('')}
    </div>
    <div style="margin-top:10px;font-size:11px;color:var(--text-muted);padding:8px 12px;background:rgba(99,102,241,.04);border-radius:6px;">
      💡 Recommendations based on your current portfolio composition. Always consult a SEBI-registered advisor before making investment decisions.
    </div>`;
}

function openMfiProfileModal() {
  let p = {}; try { p = JSON.parse(localStorage.getItem('wd_user_profile')||'{}'); } catch{}
  const m = document.createElement('div');
  m.className='modal-overlay';
  m.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
  m.innerHTML=`<div style="background:var(--bg-primary,#fff);border-radius:14px;padding:24px;width:340px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="font-size:15px;font-weight:800;margin-bottom:16px;">⚙ Investment Profile</div>
    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Your Age</label>
    <input id="_mfiAge" type="number" value="${p.age||30}" min="18" max="80" style="width:100%;padding:8px;border:1.5px solid var(--border-color);border-radius:8px;font-size:14px;margin-bottom:12px;">
    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Risk Appetite</label>
    <select id="_mfiRisk" style="width:100%;padding:8px;border:1.5px solid var(--border-color);border-radius:8px;font-size:14px;margin-bottom:16px;">
      <option value="conservative" ${(p.risk||'')==='conservative'?'selected':''}>🛡 Conservative</option>
      <option value="moderate" ${!(p.risk)||p.risk==='moderate'?'selected':''}>⚖ Moderate</option>
      <option value="aggressive" ${(p.risk||'')==='aggressive'?'selected':''}>🔥 Aggressive</option>
    </select>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button onclick="this.closest('.modal-overlay').remove()" style="padding:8px 16px;border:1.5px solid var(--border-color);border-radius:8px;background:transparent;cursor:pointer;">Cancel</button>
      <button onclick="(function(){const age=document.getElementById('_mfiAge').value;const risk=document.getElementById('_mfiRisk').value;localStorage.setItem('wd_user_profile',JSON.stringify({age,risk}));document.querySelector('.modal-overlay').remove();renderMfiRecommendations(MF.data||[]);})()" style="padding:8px 16px;border:none;border-radius:8px;background:#4f46e5;color:#fff;cursor:pointer;font-weight:700;">Save & Refresh</button>
    </div>
  </div>`;
  document.body.appendChild(m);
  m.addEventListener('click',e=>{if(e.target===m)m.remove();});
}


/* ══════════════════════════════════════════════════════════════
   tmfi03 — PORTFOLIO RISK ANALYSIS
══════════════════════════════════════════════════════════════ */
function renderMfiRiskAnalysis(holdings) {
  const el = document.getElementById('mfiRiskAnalysis');
  if (!el) return;
  if (!holdings || !holdings.length) { el.style.display='none'; return; }
  el.style.display='';

  const totalVal = holdings.reduce((s,h)=>s+parseFloat(h.value_now||0),0);
  if (totalVal <= 0) { el.style.display='none'; return; }

  // Portfolio Beta (weighted avg of fund betas — proxy by category)
  const categoryBeta = {
    'small cap':1.5,'smallcap':1.5,'small':1.5,
    'mid cap':1.3,'midcap':1.3,'mid':1.3,
    'large & mid':1.2,'large mid':1.2,
    'large cap':1.0,'largecap':1.0,'large':1.0,
    'flexi cap':1.15,'flexicap':1.15,'flexi':1.15,
    'equity':1.1,'elss':1.1,
    'hybrid':0.7,'balanced':0.7,'aggressive hybrid':0.85,
    'debt':0.2,'liquid':0.05,'overnight':0.02,'short':0.3,
    'gold':0.1,'index':1.0,
  };
  let portBeta = 0;
  holdings.forEach(h => {
    const cat = (h.category||'').toLowerCase();
    let b = 1.0;
    for (const [k,v] of Object.entries(categoryBeta)) {
      if (cat.includes(k)) { b=v; break; }
    }
    portBeta += (parseFloat(h.value_now||0)/totalVal) * b;
  });
  portBeta = Math.round(portBeta*100)/100;

  // Avg Drawdown as Volatility proxy
  const drawdowns = holdings.map(h=>parseFloat(h.drawdown_pct||0)).filter(d=>d<0);
  const avgDrawdown = drawdowns.length ? drawdowns.reduce((s,d)=>s+d,0)/drawdowns.length : -5;
  const maxDrawdown = drawdowns.length ? Math.min(...drawdowns) : -10;
  const volatility = Math.abs(avgDrawdown) * 0.8; // proxy

  // VaR 95%: rough estimate as 2σ approximation
  const var95 = Math.abs(totalVal * (volatility/100) * 2.33);
  const var99 = Math.abs(totalVal * (volatility/100) * 3.09);

  // Downside capture (category based proxy)
  const equityW = holdings.filter(h=>{
    const c=(h.category||'').toLowerCase();
    return c.includes('equity')||c.includes('large')||c.includes('mid')||c.includes('small')||c.includes('flexi')||c.includes('elss');
  }).reduce((s,h)=>s+parseFloat(h.value_now||0)/totalVal*100,0);
  const downCapture = Math.round(equityW * 0.85);

  const betaColor = portBeta>1.2?'#dc2626':portBeta>1.0?'#d97706':'#16a34a';
  const varColor = '#7c3aed';

  // Risk scatter: funds as dots by drawdown vs invested amount
  const scatterItems = holdings.slice(0,12).map(h=>{
    const dd = parseFloat(h.drawdown_pct||0);
    const val = parseFloat(h.value_now||0);
    const pct = totalVal>0?val/totalVal*100:0;
    const cat=(h.category||'').toLowerCase();
    let dotColor='#6366f1';
    if(cat.includes('small'))dotColor='#ef4444';
    else if(cat.includes('mid'))dotColor='#f59e0b';
    else if(cat.includes('debt')||cat.includes('liquid'))dotColor='#10b981';
    else if(cat.includes('hybrid')||cat.includes('balanced'))dotColor='#8b5cf6';
    return {name:(h.scheme_name||'').substring(0,20), dd, pct, dotColor};
  });

  el.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px;">
      <div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Portfolio Beta <i class="wd-info-btn" data-tip="Beta measures sensitivity to market moves. Beta=1: moves with market. Beta>1: more volatile. Beta<1: more stable.">i</i></div>
        <div style="font-size:28px;font-weight:900;color:${betaColor};">${portBeta}</div>
        <div style="font-size:11px;color:${betaColor};font-weight:600;">${portBeta>1.2?'High Risk':portBeta>1.0?'Moderate Risk':'Lower Risk'}</div>
      </div>
      <div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">VaR 95% <i class="wd-info-btn" data-tip="Value at Risk: In 95% of scenarios, daily loss should not exceed this amount. Based on avg portfolio drawdown.">i</i></div>
        <div style="font-size:20px;font-weight:900;color:${varColor};">-${_mfi_fmt(var95)}</div>
        <div style="font-size:11px;color:var(--text-muted);">1-month scenario</div>
      </div>
      <div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">VaR 99% <i class="wd-info-btn" data-tip="Worst 1% scenario: only 1 in 100 months would you lose more than this.">i</i></div>
        <div style="font-size:20px;font-weight:900;color:${varColor};">-${_mfi_fmt(var99)}</div>
        <div style="font-size:11px;color:var(--text-muted);">Tail risk</div>
      </div>
      <div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Max Drawdown</div>
        <div style="font-size:20px;font-weight:900;color:#dc2626;">${maxDrawdown.toFixed(1)}%</div>
        <div style="font-size:11px;color:var(--text-muted);">Worst fund</div>
      </div>
      <div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Downside Capture <i class="wd-info-btn" data-tip="% of market downside your portfolio would capture. Lower is better. Debt-heavy = lower capture.">i</i></div>
        <div style="font-size:28px;font-weight:900;color:${downCapture>80?'#dc2626':downCapture>60?'#d97706':'#16a34a'};">${downCapture}%</div>
        <div style="font-size:11px;color:var(--text-muted);">vs Nifty 50</div>
      </div>
    </div>

    <!-- Risk-Return Scatter (SVG) -->
    <div style="margin-bottom:10px;">
      <div style="font-size:12px;font-weight:700;margin-bottom:8px;color:var(--text-primary);">Risk-Return Map</div>
      <div style="position:relative;height:160px;background:var(--bg-secondary,#f8fafc);border-radius:10px;border:1.5px solid var(--border-color);overflow:hidden;padding:8px;">
        <div style="position:absolute;left:50%;top:0;bottom:0;width:1px;background:rgba(99,102,241,.15);"></div>
        <div style="position:absolute;top:50%;left:0;right:0;height:1px;background:rgba(99,102,241,.15);"></div>
        <div style="position:absolute;left:4px;bottom:4px;font-size:9px;color:var(--text-muted);">High Risk</div>
        <div style="position:absolute;right:4px;top:4px;font-size:9px;color:var(--text-muted);">Low Risk</div>
        ${scatterItems.map(item=>{
          // x: drawdown map 0% (left) to -50% right mapped to 5–95%
          const x = Math.min(95, Math.max(5, 95 - Math.abs(item.dd)*1.8));
          // y: % of portfolio, bigger = lower on chart (more important)
          const y = Math.min(90, Math.max(10, 90 - item.pct*3));
          const sz = Math.max(8, Math.min(20, item.pct*1.2));
          return `<div title="${item.name} (${item.dd.toFixed(1)}% drawdown, ${item.pct.toFixed(1)}% of portfolio)" 
            style="position:absolute;left:${x}%;top:${y}%;transform:translate(-50%,-50%);width:${sz}px;height:${sz}px;border-radius:50%;background:${item.dotColor};opacity:.8;cursor:pointer;border:2px solid white;box-shadow:0 1px 4px rgba(0,0,0,.2);" 
            onclick="alert('${item.name}\\nDrawdown: ${item.dd.toFixed(1)}%\\nPortfolio: ${item.pct.toFixed(1)}%')"></div>`;
        }).join('')}
      </div>
      <div style="font-size:10px;color:var(--text-muted);margin-top:4px;display:flex;gap:10px;flex-wrap:wrap;">
        <span>● <span style="color:#ef4444">Small Cap</span></span>
        <span>● <span style="color:#f59e0b">Mid Cap</span></span>
        <span>● <span style="color:#6366f1">Large/Flexi</span></span>
        <span>● <span style="color:#8b5cf6">Hybrid</span></span>
        <span>● <span style="color:#10b981">Debt</span></span>
        <span style="color:var(--text-muted);font-style:italic;">· Dot size = % of portfolio · X-axis = drawdown</span>
      </div>
    </div>

    <div style="padding:8px 12px;border-radius:8px;background:rgba(99,102,241,.04);font-size:11px;color:var(--text-muted);">
      ⚠️ Beta and VaR estimates use category-based proxies. For precise metrics, use AMFI portfolio disclosure data (available once nav_history is populated).
    </div>`;
}


/* ══════════════════════════════════════════════════════════════
   tmfi04 — SMART INSIGHT CARDS (8 rule-based conditions)
══════════════════════════════════════════════════════════════ */
function generateMfiInsights(holdings) {
  if (!holdings || !holdings.length) return [];
  const totalVal = holdings.reduce((s,h)=>s+parseFloat(h.value_now||0),0);
  if (totalVal<=0) return [];

  const insights = [];
  const DISMISSED = (() => { try{return JSON.parse(localStorage.getItem('wd_mfi_dismissed')||'[]');}catch{return [];} })();

  function addIns(id, sev, icon, title, body, action) {
    if (DISMISSED.includes(id)) return;
    insights.push({id, sev, icon, title, body, action});
  }

  // Rule 1: Too many funds
  if (holdings.length > 8) addIns('too_many','error','📦','Too Many Funds',
    `You have ${holdings.length} funds. Research shows more than 6-8 funds provides diminishing diversification while increasing complexity.`,
    'Consider consolidating to 5-7 focused funds.');
  else if (holdings.length > 6) addIns('many_funds','warn','📦','Many Funds',
    `${holdings.length} funds is getting complex. Ideal is 4-7 well-chosen funds.`,
    'Review if all funds serve distinct purposes.');

  // Rule 2: High concentration (>40% in one fund)
  holdings.forEach(h => {
    const pct = parseFloat(h.value_now||0)/totalVal*100;
    if (pct > 50) addIns('conc_'+h.fund_id,'error','⚠️','High Concentration Risk',
      `${(h.scheme_name||'').substring(0,30)} is ${pct.toFixed(0)}% of your portfolio. A single fund underperforming can significantly damage returns.`,
      'Consider rebalancing to reduce concentration below 30%.');
    else if (pct > 40) addIns('high_conc_'+h.fund_id,'warn','⚠️','Moderate Concentration',
      `${(h.scheme_name||'').substring(0,30)} is ${pct.toFixed(0)}% of portfolio. Target below 35% for any single fund.`,
      'Consider distributing across 2-3 similar funds.');
  });

  // Rule 3: Regular plan funds
  const regularFunds = holdings.filter(h=>(h.plan_type||'').toLowerCase().includes('regular'));
  if (regularFunds.length) {
    const regularVal = regularFunds.reduce((s,h)=>s+parseFloat(h.value_now||0),0);
    const annualDrag = regularVal * 0.007; // ~0.7% avg extra expense
    addIns('regular_plans','warn','💸','Regular Plan Cost Drag',
      `${regularFunds.length} fund(s) are in Regular plan. Estimated extra cost: ${_mfi_fmt(annualDrag)}/year going to distributor commission.`,
      `Switch to Direct plan via ${regularFunds.map(f=>(f.amc_name||f.scheme_name||'').split(' ')[0]).join('/')} AMC website or MFU.`);
  }

  // Rule 4: Underperforming SIPs (XIRR < 8%)
  const underperf = holdings.filter(h=>{
    const xirr = parseFloat(h.xirr||0);
    return xirr > 0 && xirr < 8;
  });
  if (underperf.length) addIns('underperf','warn','📉','Underperforming Funds',
    `${underperf.length} fund(s) have XIRR below 8%: ${underperf.slice(0,2).map(f=>(f.scheme_name||'').substring(0,20)).join(', ')}.`,
    'Review if these funds are meeting your goals. Consider switching to better performers in same category.');

  // Rule 5: No debt allocation
  const hasDebt = holdings.some(h=>{
    const c=(h.category||'').toLowerCase();
    return c.includes('debt')||c.includes('liquid')||c.includes('short')||c.includes('bond')||c.includes('hybrid');
  });
  const totalInv = holdings.reduce((s,h)=>s+parseFloat(h.total_invested||0),0);
  if (!hasDebt && totalInv > 500000) addIns('no_debt','warn','🏦','No Debt Allocation',
    'Your portfolio is 100% equity. If invested amount exceeds ₹5L, consider 15-25% in debt/hybrid for stability.',
    'Add a Hybrid/Conservative fund or Liquid fund for emergency corpus.');

  // Rule 6: Expense ratio too high
  const expRatios = holdings.filter(h=>parseFloat(h.expense_ratio||0)>0);
  if (expRatios.length) {
    const highExp = expRatios.filter(h=>parseFloat(h.expense_ratio||0)>1.5);
    if (highExp.length) addIns('high_exp','warn','💰','High Expense Ratio',
      `${highExp.length} fund(s) have expense ratio above 1.5%: ${highExp.slice(0,2).map(f=>(f.scheme_name||'').substring(0,20)).join(', ')}.`,
      'Switch to Direct plan or index funds with lower TER. Every 1% saved = 26% more wealth over 20 years.');
  }

  // Rule 7: Small investments (<₹5000) — dust positions
  const dustFunds = holdings.filter(h=>parseFloat(h.value_now||0)<5000);
  if (dustFunds.length) addIns('dust_pos','info','🧹','Small Dust Positions',
    `${dustFunds.length} fund(s) have current value below ₹5000. These are hard to track and add no meaningful impact.`,
    'Consider redeeming these and consolidating into your main funds.');

  // Rule 8: Tax loss harvesting opportunity (FY end)
  const now = new Date();
  const isMarchEnd = now.getMonth()===1||now.getMonth()===2; // Feb-Mar
  const lossHoldings = holdings.filter(h=>parseFloat(h.gain_loss||0)<-5000);
  if (isMarchEnd && lossHoldings.length) addIns('tax_harvest','info','🌾','Tax Loss Harvesting Opportunity',
    `FY end approaching! ${lossHoldings.length} fund(s) have unrealized losses. Redeeming before March 31 can offset LTCG tax.`,
    `Total harvestable loss: ${_mfi_fmt(Math.abs(lossHoldings.reduce((s,h)=>s+parseFloat(h.gain_loss||0),0)))}.`);

  return insights;
}

function renderMfiInsightCards(holdings) {
  const el = document.getElementById('mfiInsightCards');
  if (!el) return;
  const insights = generateMfiInsights(holdings);
  if (!insights.length) {
    el.innerHTML=`<div style="padding:12px 16px;background:rgba(22,163,74,.06);border:1.5px solid rgba(22,163,74,.25);border-radius:10px;font-size:12px;color:#15803d;font-weight:600;">✅ Portfolio looks clean! No critical issues detected. Keep investing consistently.</div>`;
    return;
  }

  const sevOrder = {error:0,warn:1,info:2};
  insights.sort((a,b)=>sevOrder[a.sev]-sevOrder[b.sev]);

  const sevStyle = {
    error: {bg:'rgba(220,38,38,.06)',border:'rgba(220,38,38,.3)',title:'#b91c1c',icon_bg:'rgba(220,38,38,.12)'},
    warn:  {bg:'rgba(234,179,8,.06)',border:'rgba(234,179,8,.35)',title:'#92400e',icon_bg:'rgba(234,179,8,.12)'},
    info:  {bg:'rgba(99,102,241,.04)',border:'rgba(99,102,241,.2)',title:'#3730a3',icon_bg:'rgba(99,102,241,.08)'},
  };

  el.innerHTML = insights.map(ins=>{
    const s = sevStyle[ins.sev]||sevStyle.info;
    return `<div id="mfi_ins_${ins.id}" style="background:${s.bg};border:1.5px solid ${s.border};border-radius:10px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start;">
      <div style="font-size:18px;flex-shrink:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:${s.icon_bg};border-radius:8px;">${ins.icon}</div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:12px;font-weight:800;color:${s.title};margin-bottom:3px;">${ins.title}</div>
        <div style="font-size:11px;color:var(--text-muted);line-height:1.6;margin-bottom:5px;">${ins.body}</div>
        <div style="font-size:11px;color:var(--text-primary);font-weight:600;">💡 ${ins.action}</div>
      </div>
      <button onclick="mfiDismissInsight('${ins.id}')" title="Dismiss" style="flex-shrink:0;background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:14px;padding:2px;line-height:1;">✕</button>
    </div>`;
  }).join('');
}

function mfiDismissInsight(id) {
  let d=[]; try{d=JSON.parse(localStorage.getItem('wd_mfi_dismissed')||'[]');}catch{}
  if (!d.includes(id)) d.push(id);
  localStorage.setItem('wd_mfi_dismissed', JSON.stringify(d));
  const el = document.getElementById('mfi_ins_'+id);
  if (el) { el.style.transition='opacity .3s'; el.style.opacity='0'; setTimeout(()=>el.remove(),300); }
}


/* ══════════════════════════════════════════════════════════════
   tmfi05 — TAX LOSS HARVESTING ENGINE
══════════════════════════════════════════════════════════════ */
function renderMfiTaxHarvest(holdings) {
  const el = document.getElementById('mfiTaxHarvest');
  if (!el) return;
  if (!holdings||!holdings.length) { el.style.display='none'; return; }
  el.style.display='';

  const lossHoldings = holdings.filter(h=>parseFloat(h.gain_loss||0)<0)
    .sort((a,b)=>parseFloat(a.gain_loss||0)-parseFloat(b.gain_loss||0));

  const gainHoldings = holdings.filter(h=>parseFloat(h.gain_loss||0)>0);
  const totalLoss = lossHoldings.reduce((s,h)=>s+parseFloat(h.gain_loss||0),0);
  const totalGain = gainHoldings.reduce((s,h)=>s+parseFloat(h.gain_loss||0),0);

  // Tax saved at 12.5% LTCG rate on gains that can be offset
  const offsetable = Math.min(Math.abs(totalLoss), Math.max(0, totalGain-125000));
  const taxSaved = offsetable * 0.125;

  const now = new Date();
  const fyEndDate = new Date(now.getMonth()>=3?now.getFullYear()+1:now.getFullYear(), 2, 31);
  const daysLeft = Math.ceil((fyEndDate-now)/(1000*60*60*24));

  if (!lossHoldings.length) {
    el.innerHTML=`<div style="padding:12px;font-size:12px;color:#15803d;background:rgba(22,163,74,.06);border-radius:8px;">✅ No funds in loss currently. No harvesting needed.</div>`;
    return;
  }

  el.innerHTML=`
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px;">
      <div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Total Harvestable Loss</div>
        <div style="font-size:18px;font-weight:900;color:#dc2626;">-${_mfi_fmt(Math.abs(totalLoss))}</div>
      </div>
      <div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Realised LTCG (Gains)</div>
        <div style="font-size:18px;font-weight:900;color:#16a34a;">${_mfi_fmt(totalGain)}</div>
      </div>
      <div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Estimated Tax Saved</div>
        <div style="font-size:18px;font-weight:900;color:#7c3aed;">${taxSaved>0?_mfi_fmt(taxSaved):'₹0'}</div>
        <div style="font-size:10px;color:var(--text-muted);">@12.5% LTCG rate</div>
      </div>
      <div style="background:${daysLeft<30?'rgba(220,38,38,.06)':'rgba(234,179,8,.06)'};border-radius:10px;padding:12px;text-align:center;border:1.5px solid ${daysLeft<30?'rgba(220,38,38,.25)':'rgba(234,179,8,.25)'};">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">FY End</div>
        <div style="font-size:18px;font-weight:900;color:${daysLeft<30?'#dc2626':'#d97706'};">${daysLeft}d</div>
        <div style="font-size:10px;color:var(--text-muted);">Mar 31 deadline</div>
      </div>
    </div>

    <div style="font-size:12px;font-weight:700;margin-bottom:8px;">Funds to Harvest</div>
    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px;">
      ${lossHoldings.slice(0,6).map(h=>{
        const loss=parseFloat(h.gain_loss||0);
        const tax=Math.abs(loss)*0.125;
        const invested=parseFloat(h.total_invested||0);
        const val=parseFloat(h.value_now||0);
        const cat=(h.category||'').toLowerCase();
        const isLTCG = !cat.includes('debt')&&!cat.includes('liquid');
        return `<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(220,38,38,.04);border:1px solid rgba(220,38,38,.15);border-radius:8px;">
          <div style="flex:1;min-width:0;">
            <div style="font-size:12px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${h.scheme_name||'Fund'}</div>
            <div style="font-size:10px;color:var(--text-muted);">Invested: ${_mfi_fmt(invested)} · Current: ${_mfi_fmt(val)} · ${isLTCG?'LTCG':'STCG'}</div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:13px;font-weight:800;color:#dc2626;">-${_mfi_fmt(Math.abs(loss))}</div>
            <div style="font-size:10px;color:#7c3aed;">saves ~${_mfi_fmt(tax)} tax</div>
          </div>
        </div>`;
      }).join('')}
    </div>

    <div style="padding:10px 14px;border-radius:8px;background:rgba(124,58,237,.04);border:1px solid rgba(124,58,237,.15);font-size:11px;color:var(--text-muted);line-height:1.7;">
      ⚠️ <strong>Wash-Sale Caution:</strong> After redeeming a fund at loss, wait 30+ days before buying back the same fund, or buy a similar fund in same category. 
      Redemption ≠ permanent exit — re-invest in same/better fund after 30 days.
      <br>📌 This shows <strong>unrealized</strong> losses only. Consult your CA before FY-end harvesting.
    </div>`;
}


/* ══════════════════════════════════════════════════════════════
   tmfi06 — WHAT-IF SIMULATOR
══════════════════════════════════════════════════════════════ */
function renderMfiWhatIf(holdings) {
  const el = document.getElementById('mfiWhatIf');
  if (!el) return;
  if (!holdings||!holdings.length) { el.style.display='none'; return; }
  el.style.display='';

  const totalVal = holdings.reduce((s,h)=>s+parseFloat(h.value_now||0),0);
  const totalInv = holdings.reduce((s,h)=>s+parseFloat(h.total_invested||0),0);

  el.innerHTML=`
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;flex-wrap:wrap;">

      <!-- Scenario 1: Market Crash -->
      <div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:16px;border:1.5px solid var(--border-color);">
        <div style="font-size:12px;font-weight:800;margin-bottom:10px;">📉 Market Crash Simulator</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
          <button onclick="mfiRunCrash(-10,this)" class="btn btn-ghost btn-xs">-10%</button>
          <button onclick="mfiRunCrash(-20,this)" class="btn btn-ghost btn-xs active">-20%</button>
          <button onclick="mfiRunCrash(-35,this)" class="btn btn-ghost btn-xs">-35%</button>
          <button onclick="mfiRunCrash(-50,this)" class="btn btn-ghost btn-xs">-50%</button>
        </div>
        <div id="mfiCrashResult" style="font-size:13px;"></div>
      </div>

      <!-- Scenario 2: SIP Step-Up -->
      <div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:16px;border:1.5px solid var(--border-color);">
        <div style="font-size:12px;font-weight:800;margin-bottom:10px;">📈 SIP Growth Projector</div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
          <label style="font-size:11px;color:var(--text-muted);">Monthly SIP:</label>
          <input id="mfiSipAmt" type="number" value="10000" min="500" step="500" 
            style="width:90px;padding:4px 8px;border:1.5px solid var(--border-color);border-radius:6px;font-size:12px;"
            oninput="mfiRunSip()">
          <label style="font-size:11px;color:var(--text-muted);">Step-up %/yr:</label>
          <input id="mfiStepUp" type="number" value="10" min="0" max="50" 
            style="width:60px;padding:4px 8px;border:1.5px solid var(--border-color);border-radius:6px;font-size:12px;"
            oninput="mfiRunSip()">
        </div>
        <div id="mfiSipResult" style="font-size:12px;"></div>
      </div>

    </div>`;

  // Run default scenario
  setTimeout(()=>{ mfiRunCrash(-20, el.querySelector('.btn.active')); mfiRunSip(); },100);
}

function mfiRunCrash(pct, btn) {
  const el = document.getElementById('mfiCrashResult');
  if (!el) return;
  // Update active button
  document.querySelectorAll('[onclick^="mfiRunCrash"]').forEach(b=>b.classList.remove('active'));
  if(btn) btn.classList.add('active');

  const holdings = MF.data||[];
  const totalVal = holdings.reduce((s,h)=>s+parseFloat(h.value_now||0),0);
  if (!totalVal) return;

  // Equity funds get full crash, debt/hybrid get partial
  let crashedVal = 0;
  holdings.forEach(h=>{
    const val=parseFloat(h.value_now||0);
    const cat=(h.category||'').toLowerCase();
    let factor = pct/100;
    if (cat.includes('debt')||cat.includes('liquid')||cat.includes('overnight')) factor=pct/100*0.05;
    else if (cat.includes('hybrid')||cat.includes('balanced')) factor=pct/100*0.5;
    else if (cat.includes('gold')) factor=pct/100*0.1;
    crashedVal += val*(1+factor);
  });
  const loss = crashedVal - totalVal;
  const invested = holdings.reduce((s,h)=>s+parseFloat(h.total_invested||0),0);
  const recovRate = 12; // avg 12% recovery
  const recovMonths = Math.round(Math.log(totalVal/crashedVal)/Math.log(1+recovRate/100/12));

  el.innerHTML=`
    <div style="display:flex;flex-direction:column;gap:6px;">
      <div style="display:flex;justify-content:space-between;">
        <span style="font-size:11px;color:var(--text-muted);">Current Value</span>
        <span style="font-size:12px;font-weight:700;">${_mfi_fmt(totalVal)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;">
        <span style="font-size:11px;color:var(--text-muted);">After ${pct}% crash</span>
        <span style="font-size:13px;font-weight:800;color:#dc2626;">${_mfi_fmt(crashedVal)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;">
        <span style="font-size:11px;color:var(--text-muted);">Portfolio drop</span>
        <span style="font-size:12px;font-weight:700;color:#dc2626;">${_mfi_fmt(Math.abs(loss))} (${((1-crashedVal/totalVal)*100).toFixed(1)}%)</span>
      </div>
      <div style="display:flex;justify-content:space-between;">
        <span style="font-size:11px;color:var(--text-muted);">vs Invested Amount</span>
        <span style="font-size:11px;font-weight:600;color:${crashedVal>invested?'#16a34a':'#dc2626'};">${crashedVal>invested?'Still in profit!':'Below cost'}</span>
      </div>
      <div style="font-size:10px;color:var(--text-muted);padding:6px 8px;background:rgba(99,102,241,.06);border-radius:6px;margin-top:4px;">
        🔄 Recovery estimate: ~${recovMonths>0?recovMonths:3} months at ${recovRate}% p.a. recovery rate
      </div>
    </div>`;
}

function mfiRunSip() {
  const el = document.getElementById('mfiSipResult');
  if (!el) return;
  const sip = parseFloat(document.getElementById('mfiSipAmt')?.value||10000);
  const stepUp = parseFloat(document.getElementById('mfiStepUp')?.value||10)/100;
  const rate = 0.12/12; // 12% pa

  const calcCorpus = (monthly, stepUpPct, years) => {
    let corpus=0, mSip=monthly;
    for (let y=0;y<years;y++) {
      for (let m=0;m<12;m++) { corpus=(corpus+mSip)*(1+rate); }
      mSip*=(1+stepUpPct);
    }
    return corpus;
  };

  const flat10=calcCorpus(sip,0,10), su10=calcCorpus(sip,stepUp,10);
  const flat20=calcCorpus(sip,0,20), su20=calcCorpus(sip,stepUp,20);

  el.innerHTML=`
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:11px;">
      <div style="text-align:center;padding:8px;background:rgba(99,102,241,.06);border-radius:8px;">
        <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px;">Flat SIP (10yr)</div>
        <div style="font-size:14px;font-weight:800;">${_mfi_fmt(flat10)}</div>
      </div>
      <div style="text-align:center;padding:8px;background:rgba(16,185,129,.06);border-radius:8px;">
        <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px;">Step-up (10yr)</div>
        <div style="font-size:14px;font-weight:800;color:#16a34a;">${_mfi_fmt(su10)}</div>
      </div>
      <div style="text-align:center;padding:8px;background:rgba(99,102,241,.08);border-radius:8px;">
        <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px;">Flat SIP (20yr)</div>
        <div style="font-size:15px;font-weight:800;">${_mfi_fmt(flat20)}</div>
      </div>
      <div style="text-align:center;padding:8px;background:rgba(16,185,129,.1);border-radius:8px;border:1.5px solid rgba(16,185,129,.3);">
        <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px;">Step-up (20yr) 🔥</div>
        <div style="font-size:15px;font-weight:800;color:#16a34a;">${_mfi_fmt(su20)}</div>
      </div>
    </div>
    <div style="font-size:10px;color:var(--text-muted);margin-top:6px;">Assumes 12% p.a. returns · Step-up: +${(stepUp*100).toFixed(0)}%/yr</div>`;
}


/* ══════════════════════════════════════════════════════════════
   tmfi07 — AUTO PORTFOLIO CLEANUP
══════════════════════════════════════════════════════════════ */
function renderMfiCleanup(holdings) {
  const el = document.getElementById('mfiCleanup');
  if (!el) return;
  if (!holdings||!holdings.length) { el.style.display='none'; return; }
  el.style.display='';

  const issues = [];

  // 1. Dust positions (<5K)
  const dust = holdings.filter(h=>parseFloat(h.value_now||0)<5000);
  if (dust.length) issues.push({
    type:'dust', icon:'🧹', title:'Dust Positions',
    body:`${dust.length} fund(s) with value < ₹5,000: ${dust.map(f=>(f.scheme_name||'').substring(0,20)).join(', ')}.`,
    action:'Redeem and consolidate into main holdings.'
  });

  // 2. Regular plans
  const regular = holdings.filter(h=>(h.plan_type||'').toLowerCase().includes('regular'));
  if (regular.length) issues.push({
    type:'regular', icon:'💸', title:'Regular Plan Funds',
    body:`${regular.length} fund(s) in Regular plan: ${regular.map(f=>(f.scheme_name||'').substring(0,20)).join(', ')}.`,
    action:'Switch to Direct via AMC website / MFU / CAMS. No tax on switch (it counts as redemption + repurchase).'
  });

  // 3. High expense ratio (>1.5%)
  const highExp = holdings.filter(h=>parseFloat(h.expense_ratio||0)>1.5&&!(h.plan_type||'').toLowerCase().includes('regular'));
  if (highExp.length) issues.push({
    type:'high_exp', icon:'💰', title:'High Expense Ratio Funds',
    body:`${highExp.length} fund(s) have TER > 1.5%: ${highExp.map(f=>(f.scheme_name||'').substring(0,20)).join(', ')}.`,
    action:'Look for equivalent index funds or lower-cost alternatives.'
  });

  // 4. Same category duplicates
  const catMap = {};
  holdings.forEach(h=>{
    const cat=(h.category||'Unknown').toLowerCase().split(' ').slice(0,2).join(' ');
    if (!catMap[cat]) catMap[cat]=[];
    catMap[cat].push(h.scheme_name||'');
  });
  const dupes = Object.entries(catMap).filter(([,v])=>v.length>1);
  if (dupes.length) issues.push({
    type:'dupe_cat', icon:'🔁', title:'Same Category Duplicates',
    body:`Multiple funds in: ${dupes.map(([cat,funds])=>`${cat} (${funds.length})`).join(', ')}.`,
    action:'Keep the best performer per category. More funds in same category = no extra diversification.'
  });

  // 5. Underperformers (negative 1Y return)
  const negReturn = holdings.filter(h=>parseFloat(h.returns_1y||0)<0);
  if (negReturn.length) issues.push({
    type:'underperf', icon:'📉', title:'Negative 1Y Return Funds',
    body:`${negReturn.length} fund(s) with negative 1Y returns: ${negReturn.map(f=>(f.scheme_name||'').substring(0,20)).join(', ')}.`,
    action:'Review if the fund is underperforming its category average. Consider switching if trend persists 2+ years.'
  });

  if (!issues.length) {
    el.innerHTML=`<div style="padding:12px;font-size:12px;color:#15803d;background:rgba(22,163,74,.06);border-radius:8px;">✅ No cleanup actions needed! Portfolio is well-structured.</div>`;
    return;
  }

  el.innerHTML=`
    <div style="margin-bottom:12px;font-size:12px;color:var(--text-muted);">Found <strong style="color:var(--text-primary);">${issues.length} cleanup actions</strong> that can improve portfolio efficiency:</div>
    <div style="display:flex;flex-direction:column;gap:8px;">
      ${issues.map((iss,i)=>`
        <div style="padding:12px 14px;background:var(--bg-secondary,#f8fafc);border:1.5px solid var(--border-color);border-radius:10px;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
            <span style="font-size:16px;">${iss.icon}</span>
            <span style="font-size:12px;font-weight:800;">${iss.title}</span>
            <span style="margin-left:auto;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:rgba(220,38,38,.08);color:#b91c1c;">Action ${i+1}</span>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;line-height:1.6;">${iss.body}</div>
          <div style="font-size:11px;font-weight:600;color:var(--text-primary);">💡 ${iss.action}</div>
        </div>
      `).join('')}
    </div>
    <button onclick="mfiExportCleanup()" style="margin-top:12px;padding:8px 16px;border-radius:8px;border:1.5px solid var(--border-color);background:transparent;cursor:pointer;font-size:12px;font-weight:600;color:var(--text-muted);">📋 Export Cleanup Plan</button>`;
}

function mfiExportCleanup() {
  const holdings = MF.data||[];
  const lines = ['WealthDash — Portfolio Cleanup Plan','='.repeat(45),'Generated: '+new Date().toLocaleDateString('en-IN'),''];
  // Dust
  holdings.filter(h=>parseFloat(h.value_now||0)<5000).forEach(h=>lines.push(`[DUST] ${h.scheme_name} — ₹${parseFloat(h.value_now||0).toFixed(0)}`));
  // Regular
  holdings.filter(h=>(h.plan_type||'').toLowerCase().includes('regular')).forEach(h=>lines.push(`[REGULAR] ${h.scheme_name} — Switch to Direct plan`));
  const blob=new Blob([lines.join('\n')],{type:'text/plain'});
  const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='wd_cleanup_'+new Date().toISOString().slice(0,10)+'.txt';a.click();
}


/* ══════════════════════════════════════════════════════════════
   tmfi08 — SIP OPTIMIZATION ENGINE
   Smart increase/stop/switch suggestions based on XIRR & category
══════════════════════════════════════════════════════════════ */
function renderMfiSipOptimization(holdings) {
  const el = document.getElementById('mfiSipOptimization');
  if (!el) return;
  if (!holdings || !holdings.length) { el.style.display = 'none'; return; }
  el.style.display = '';

  // Filter only SIP holdings (sip_amount > 0 and active)
  const sipHoldings = holdings.filter(h => parseFloat(h.sip_amount || 0) > 0);
  if (!sipHoldings.length) {
    el.innerHTML = `<div style="padding:14px;font-size:12px;color:var(--muted,#6b7280);text-align:center;">
      No active SIPs found. Add SIPs from the SIP Manager page.
    </div>`;
    return;
  }

  // Score each SIP
  const scored = sipHoldings.map(h => {
    const xirr        = parseFloat(h.xirr || 0);
    const invested    = parseFloat(h.total_invested || 0);
    const valueNow    = parseFloat(h.value_now || 0);
    const sipAmt      = parseFloat(h.sip_amount || 0);
    const gainPct     = invested > 0 ? ((valueNow - invested) / invested) * 100 : 0;
    const expense     = parseFloat(h.expense_ratio || h.ter_pct || 0);
    const isRegular   = (h.plan_type || '').toLowerCase().includes('regular') ||
                        (h.scheme_name || '').toLowerCase().includes(' - regular') ||
                        (h.scheme_name || '').toLowerCase().includes('(regular)');
    const category    = (h.category || h.fund_category || '').toLowerCase();
    const isDebt      = category.includes('debt') || category.includes('liquid') ||
                        category.includes('money market') || category.includes('overnight');

    // Benchmark: equity 12%, debt 7%
    const benchmark   = isDebt ? 7 : 12;
    const xirrVsBench = xirr - benchmark;

    let action = 'hold', reason = '', priority = 2, color = '#6b7280', icon = '➡️';

    if (isRegular && expense > 0.8) {
      action   = 'switch';
      reason   = `Regular plan — expense ratio ${expense.toFixed(2)}% eats returns. Direct plan mein switch karo.`;
      priority = 1; color = '#dc2626'; icon = '🔄';
    } else if (xirr > 0 && xirrVsBench >= 3 && sipAmt > 0) {
      action   = 'increase';
      reason   = `Excellent performer — XIRR ${xirr.toFixed(1)}% (${xirrVsBench > 0 ? '+' : ''}${xirrVsBench.toFixed(1)}% vs ${benchmark}% benchmark). SIP badhao.`;
      priority = 1; color = '#16a34a'; icon = '📈';
    } else if (xirr < 0 && invested > 10000) {
      action   = 'stop';
      reason   = `Negative XIRR (${xirr.toFixed(1)}%). Category mein better funds available. Paisa waste ho raha hai.`;
      priority = 1; color = '#dc2626'; icon = '⛔';
    } else if (xirr > 0 && xirrVsBench >= 0) {
      action   = 'hold';
      reason   = `Solid performer — XIRR ${xirr.toFixed(1)}%, benchmark se ${xirrVsBench >= 0 ? '+' : ''}${xirrVsBench.toFixed(1)}%. Continue.`;
      priority = 3; color = '#2563eb'; icon = '✅';
    } else if (xirr > 0 && xirrVsBench < -3) {
      action   = 'review';
      reason   = `Underperforming — XIRR ${xirr.toFixed(1)}% vs ${benchmark}% benchmark (${xirrVsBench.toFixed(1)}%). Category mein better options check karo.`;
      priority = 2; color = '#d97706'; icon = '⚠️';
    } else {
      reason = `XIRR ${xirr > 0 ? xirr.toFixed(1) + '%' : 'na calc hua'}. Monitor karo.`;
    }

    return { ...h, xirr, sipAmt, invested, valueNow, gainPct, action, reason, priority, color, icon, isRegular, expense };
  }).sort((a, b) => a.priority - b.priority || b.xirr - a.xirr);

  // Summary counts
  const counts = { increase: 0, hold: 0, review: 0, stop: 0, switch: 0 };
  scored.forEach(s => { if (counts[s.action] !== undefined) counts[s.action]++; });

  const fmtAmt = v => _mfi_fmt(v);
  const fmtXirr = v => v > 0 ? `+${v.toFixed(1)}%` : `${v.toFixed(1)}%`;

  el.innerHTML = `
    <!-- Summary bar -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
      ${[
        { key:'increase', label:'📈 Increase',  bg:'#f0fdf4', border:'#86efac', txt:'#15803d' },
        { key:'hold',     label:'✅ Hold',       bg:'#eff6ff', border:'#bfdbfe', txt:'#1d4ed8' },
        { key:'review',   label:'⚠️ Review',    bg:'#fffbeb', border:'#fcd34d', txt:'#92400e' },
        { key:'stop',     label:'⛔ Stop',       bg:'#fff1f2', border:'#fca5a5', txt:'#b91c1c' },
        { key:'switch',   label:'🔄 Switch',     bg:'#fdf4ff', border:'#e9d5ff', txt:'#7e22ce' },
      ].map(b => counts[b.key] > 0 ? `
        <div style="background:${b.bg};border:1.5px solid ${b.border};border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;color:${b.txt};">
          ${b.label} <span style="font-size:14px;margin-left:4px;">${counts[b.key]}</span>
        </div>` : '').join('')}
    </div>

    <!-- SIP rows -->
    <div style="display:flex;flex-direction:column;gap:8px;">
      ${scored.map(s => `
        <div style="background:var(--surface2,#f8fafc);border:1.5px solid var(--border,#e5e7eb);border-left:3px solid ${s.color};border-radius:10px;padding:12px 14px;display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
          <div style="font-size:18px;flex-shrink:0;margin-top:2px;">${s.icon}</div>
          <div style="flex:1;min-width:160px;">
            <div style="font-size:12px;font-weight:700;color:var(--text,#111827);margin-bottom:2px;">${(s.scheme_name || s.fund_name || '—').replace(' - Direct Plan','').replace('(Direct)','').trim()}</div>
            <div style="font-size:10px;color:var(--muted,#6b7280);">${s.category || s.fund_category || ''} ${s.isRegular ? '· <span style="color:#dc2626;font-weight:600;">Regular Plan</span>' : '· Direct'}</div>
            <div style="font-size:11px;color:${s.color};margin-top:4px;line-height:1.4;">${s.reason}</div>
          </div>
          <div style="display:flex;gap:14px;flex-shrink:0;text-align:right;flex-wrap:wrap;">
            <div>
              <div style="font-size:10px;color:var(--muted,#6b7280);font-weight:600;">Monthly SIP</div>
              <div style="font-size:13px;font-weight:700;color:var(--text,#111827);">${fmtAmt(s.sipAmt)}</div>
            </div>
            <div>
              <div style="font-size:10px;color:var(--muted,#6b7280);font-weight:600;">XIRR</div>
              <div style="font-size:13px;font-weight:700;color:${s.xirr > 12 ? '#16a34a' : s.xirr > 0 ? '#d97706' : '#dc2626'};">${s.xirr ? fmtXirr(s.xirr) : '—'}</div>
            </div>
            <div>
              <div style="font-size:10px;color:var(--muted,#6b7280);font-weight:600;">Gain</div>
              <div style="font-size:13px;font-weight:700;color:${s.gainPct >= 0 ? '#16a34a' : '#dc2626'};">${s.gainPct >= 0 ? '+' : ''}${s.gainPct.toFixed(1)}%</div>
            </div>
          </div>
        </div>`).join('')}
    </div>

    <div style="font-size:10px;color:var(--muted,#6b7280);margin-top:10px;padding:8px;background:var(--accent-bg,#eef2ff);border-radius:6px;">
      💡 <strong>April Tip:</strong> Ye financial year ka pehla mahina hai — SIP review ka best time. Underperformers ko switch karo, winners ko step-up karo.
    </div>`;
}

/* ══════════════════════════════════════════════════════════════
   HOOK ALL MFI INTO renderMfAnalytics
══════════════════════════════════════════════════════════════ */
const _mfiBaseAnalytics = renderMfAnalytics;
function renderMfAnalytics() {
  _mfiBaseAnalytics();
  const h = MF.data || [];
  if (!h.length) return;
  renderMfiHealthScore(h);
  renderMfiInsightCards(h);
  renderMfiRecommendations(h);
  renderMfiRiskAnalysis(h);
  renderMfiTaxHarvest(h);
  renderMfiWhatIf(h);
  renderMfiCleanup(h);
  renderMfiSipOptimization(h);
}