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

  // Custom file input display
  document.getElementById('importFile')?.addEventListener('change', function() {
    const label = document.getElementById('importFileLabel');
    const text  = document.getElementById('importFileText');
    if (this.files.length) {
      text.textContent  = this.files[0].name;
      text.style.color  = 'var(--text-primary)';
      text.style.fontWeight = '500';
      label.style.borderColor  = 'var(--accent)';
      label.style.background   = 'rgba(37,99,235,.04)';
    } else {
      text.textContent  = 'Choose CSV file…';
      text.style.color  = 'var(--text-muted)';
      text.style.fontWeight = '';
      label.style.borderColor  = 'var(--border)';
      label.style.background   = 'var(--card-bg)';
    }
  });
  document.getElementById('btnCloseImportModal')?.addEventListener('click', () => hideModal('modalImportCsv'));
  document.getElementById('btnCancelImport')?.addEventListener('click', () => hideModal('modalImportCsv'));
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

    // NAV
    const nav     = h.latest_nav ? `<div style="font-weight:600;">₹${Number(h.latest_nav).toFixed(4)}</div>` : '—';
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
        <div class="fund-sub">${escHtml(h.fund_house_short||h.fund_house||'')}${folioInfo ? ' · ' + h.folio_number : ''}</div>
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
          ${lockBadge}
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
          <!-- Line 1: SIP + SWP badges side by side (only if exist) -->
          ${(h.active_sip_count > 0 || (h.active_swp_count||0) > 0) ? `
          <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:center;">
            ${h.active_sip_count > 0 ? `<span
              onclick="openQuickSip(${fundId},'${escAttr(h.scheme_name)}',${h.latest_nav||0},'${escAttr(h.fund_house_short||'')}','${escAttr(h.category||'')}')"
              style="display:inline-block;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #86efac;cursor:pointer;transition:all .15s;"
              onmouseover="this.style.background='#bbf7d0'"
              onmouseout="this.style.background='#dcfce7'"
              title="SIP ₹${h.active_sip_amount ? Number(h.active_sip_amount).toLocaleString('en-IN') : '?'} / ${h.active_sip_frequency||'monthly'}">🔄 SIP</span>` : ''}
            ${(h.active_swp_count||0) > 0 ? `<span
              onclick="openQuickSip(${fundId},'${escAttr(h.scheme_name)}',${h.latest_nav||0},'${escAttr(h.fund_house_short||'')}','${escAttr(h.category||'')}')"
              style="display:inline-block;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;cursor:pointer;transition:all .15s;"
              onmouseover="this.style.background='#fecaca'"
              onmouseout="this.style.background='#fee2e2'"
              title="SWP ₹${h.active_swp_amount ? Number(h.active_swp_amount).toLocaleString('en-IN') : '?'} / month">💸 SWP</span>` : ''}
          </div>` : ''}
          <!-- Line 2: Transactions -->
          <button onclick="openTxnDrawer(${fundId},'${escAttr(h.scheme_name)}')" title="View Transactions"
            style="display:flex;align-items:center;gap:4px;padding:4px 8px;border-radius:6px;border:1px solid var(--border-color);background:var(--bg-secondary);cursor:pointer;font-size:11px;color:var(--text-muted);font-weight:500;transition:all .15s;"
            onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)';this.style.background='rgba(37,99,235,.06)'"
            onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)';this.style.background='var(--bg-secondary)'">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
            Txns
          </button>
          <!-- Line 3: Delete -->
          <button onclick="openDeleteFundModal(${fundId},'${escAttr(h.scheme_name)}',${h.total_invested||0})" title="Delete this fund"
            style="display:flex;align-items:center;gap:4px;padding:4px 8px;border-radius:6px;border:1px solid rgba(239,68,68,.35);background:rgba(239,68,68,.06);cursor:pointer;font-size:11px;color:#dc2626;font-weight:600;transition:all .15s;"
            onmouseover="this.style.background='rgba(239,68,68,.15)';this.style.borderColor='#dc2626'"
            onmouseout="this.style.background='rgba(239,68,68,.06)';this.style.borderColor='rgba(239,68,68,.35)'">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            Delete
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
  ['txnFilterType'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', loadTransactions);
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
  const from = document.getElementById('txnFilterFrom')?.value;
  const to   = document.getElementById('txnFilterTo')?.value;
  const q    = document.getElementById('txnSearch')?.value;
  params.set('portfolio_id', window.WD?.selectedPortfolio || 0);
  if (type) params.set('txn_type', type);
  if (from) params.set('from', from);
  if (to)   params.set('to', to);
  if (q)    params.set('q', q);

  try {
    const res = await API.get(`/api/mutual_funds/mf_list.php?${params}`);
    MF.totalTxns = res.total || 0;
    MF.page = page;
    renderTxnTable(res.data || []);
    renderPagination(res.total, res.page, res.per_page, res.pages);
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
    body.innerHTML = `<tr><td colspan="10" class="text-center" style="padding:40px;color:var(--text-muted);">No transactions found</td></tr>`;
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
  ['txnFilterType','txnFilterFrom','txnFilterTo','txnSearch'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  loadTransactions();
}

function exportTxnCsv() {
  const params = new URLSearchParams({ action: 'export_mf_csv' });
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
      const cgTab = document.getElementById('tabCapgains');
      if (cgTab) cgTab.style.display = which === 'capgains' ? '' : 'none';

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
  // Estimate commission drag: avg ~0.6% extra expense ratio in regular plans
  const annualDrag = totalRegInvested * 0.006;
  const tenYrDrag  = totalRegInvested * (Math.pow(1.006, 10) - 1);

  banner.innerHTML = `
    <div style="display:flex;align-items:flex-start;gap:14px;">
      <span style="font-size:24px;flex-shrink:0;">⚠️</span>
      <div style="flex:1;">
        <div style="font-weight:700;color:#c2410c;font-size:13px;margin-bottom:4px;">
          ${regularFunds.length} Regular Plan Fund${regularFunds.length>1?'s':''} Detected — You May Be Overpaying
        </div>
        <div style="font-size:12px;color:#9a3412;line-height:1.6;">
          Estimated extra commission: <strong>~₹${Math.round(annualDrag).toLocaleString('en-IN')}/year</strong> on ₹${fmtFull(totalRegInvested)} invested.
          Over 10 years: <strong style="color:#c2410c;">~₹${Math.round(tenYrDrag).toLocaleString('en-IN')} lost</strong> to distributor commission.
        </div>
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
          ${regularFunds.slice(0,3).map(h => `<span style="font-size:11px;background:rgba(251,146,60,.15);color:#c2410c;padding:2px 8px;border-radius:99px;font-weight:600;">${(h.scheme_name||'').slice(0,30)}</span>`).join('')}
          ${regularFunds.length > 3 ? `<span style="font-size:11px;color:#c2410c;">+${regularFunds.length-3} more</span>` : ''}
        </div>
        <div style="font-size:11px;color:#9a3412;margin-top:6px;">💡 Consider switching to Direct plans via MF Central or your AMC website.</div>
      </div>
    </div>`;
}

// Hook into renderMfAnalytics
const _origRenderMfAnalytics = renderMfAnalytics;
function renderMfAnalytics() {
  _origRenderMfAnalytics();
  renderDirectVsRegular();
  renderSipStreak();
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
  }, 500);
}