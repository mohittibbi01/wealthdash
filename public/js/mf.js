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
  perPage: 50,
  totalTxns: 0,
  txnFilters: {},
  holdingsPage: 1,
  holdingsPerPage: 25,
};

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

  // View toggle
  document.getElementById('viewCombined')?.addEventListener('click', () => {
    MF.view = 'holdings'; setViewActive('viewCombined'); loadHoldings();
  });
  document.getElementById('viewFolio')?.addEventListener('click', () => {
    MF.view = 'folio'; setViewActive('viewFolio'); loadHoldings();
  });

  // Filters
  ['filterPortfolio','filterCategory','filterGainType'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', applyHoldingsFilter);
  });
  document.getElementById('searchFund')?.addEventListener('input', e => {
    MF.search = e.target.value.toLowerCase(); applyHoldingsFilter();
  });

  // Sort headers
  document.querySelectorAll('#holdingsTable th.sortable').forEach(th => {
    th.addEventListener('click', () => {
      const col = th.dataset.col;
      if (MF.sortCol === col) MF.sortDir = MF.sortDir === 'asc' ? 'desc' : 'asc';
      else { MF.sortCol = col; MF.sortDir = 'desc'; }
      renderHoldings();
    });
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

  const portfolioId = document.getElementById('filterPortfolio')?.value || '';
  const params = new URLSearchParams({ view: MF.view });
  if (portfolioId) params.set('portfolio_id', portfolioId);

  try {
    const res  = await API.get(`/api/mutual_funds/mf_list.php?${params}`);
    MF.data = res.data || [];
    MF.filtered = [...MF.data];

    // Update summary from API if available
    if (res.summary) updateSummaryCards(res.summary);

    applyHoldingsFilter();
  } catch (err) {
    body.innerHTML = `<tr><td colspan="11" class="text-center text-danger" style="padding:32px;">${err.message}</td></tr>`;
  }
}

function applyHoldingsFilter() {
  MF.portfolioFilter = document.getElementById('filterPortfolio')?.value || '';
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

function renderHoldings() {
  const body    = document.getElementById('holdingsBody');
  const tfoot   = document.getElementById('holdingsTfoot');
  const countEl = document.getElementById('holdingsCount');

  // Sort
  MF.filtered.sort((a, b) => {
    let av = a[MF.sortCol] ?? 0, bv = b[MF.sortCol] ?? 0;
    if (typeof av === 'string') av = av.toLowerCase(), bv = bv?.toLowerCase?.() ?? '';
    return MF.sortDir === 'asc' ? (av > bv ? 1 : -1) : (av < bv ? 1 : -1);
  });

  if (!MF.filtered.length) {
    body.innerHTML = `<tr><td colspan="11" class="text-center" style="padding:40px;color:var(--text-muted);">No holdings found</td></tr>`;
    if (tfoot) tfoot.style.display = 'none';
    if (countEl) countEl.textContent = '0 funds';
    return;
  }

  if (countEl) countEl.textContent = `${MF.filtered.length} fund${MF.filtered.length !== 1 ? 's' : ''}`;

  // Totals for footer
  const totInv  = MF.filtered.reduce((s,h) => s + (h.total_invested||0), 0);
  const totVal  = MF.filtered.reduce((s,h) => s + (h.value_now||0), 0);
  const totGain = totVal - totInv;
  const totPct  = totInv > 0 ? ((totGain / totInv) * 100).toFixed(2) : '0.00';

  const isFolio = MF.view === 'folio';

  // --- Client-side pagination ---
  const total   = MF.filtered.length;
  const perPage = MF.holdingsPerPage;
  const pages   = Math.ceil(total / perPage);
  if (MF.holdingsPage > pages) MF.holdingsPage = 1;
  const pageStart = (MF.holdingsPage - 1) * perPage;
  const paged     = MF.filtered.slice(pageStart, pageStart + perPage);

  body.innerHTML = paged.map(h => {
    const gain      = h.gain_loss || 0;
    const gainPct   = h.gain_pct || 0;
    const gainClass = gain >= 0 ? 'positive' : 'negative';
    const gainSign  = gain >= 0 ? '+' : '';
    const ltcgDate  = h.ltcg_date ? formatDateDisplay(h.ltcg_date) : '—';
    const typeTag   = h.gain_type === 'LTCG'
      ? `<span class="badge badge-success">LTCG</span>`
      : `<span class="badge badge-warning">STCG</span>`;
    const nav       = h.latest_nav ? `₹${Number(h.latest_nav).toFixed(4)}` : '—';
    const navDate   = h.latest_nav_date ? `<br><small style="color:var(--text-muted);">${formatDateDisplay(h.latest_nav_date)}</small>` : '';
    const folioInfo = isFolio && h.folio_number ? `<br><small style="color:var(--text-muted);">${h.folio_number}</small>` : '';
    const cagr      = h.cagr ? `${h.cagr > 0 ? '+' : ''}${h.cagr}%` : '—';
    const fundId    = h.fund_id || h.id;

    // Peak NAV + Drawdown
    const peakNav = h.highest_nav
      ? `<div style="font-weight:600;">₹${Number(h.highest_nav).toFixed(4)}</div>${h.highest_nav_date ? `<small style="color:var(--text-muted);">${formatDateDisplay(h.highest_nav_date)}</small>` : ''}`
      : '—';
    let drawdownHtml = '—';
    if (h.drawdown_pct !== null && h.drawdown_pct !== undefined) {
      if (h.drawdown_pct <= 0) {
        drawdownHtml = `<span style="color:var(--gain,#16a34a);font-weight:600;">🏆 ATH</span>`;
      } else {
        const ddColor = h.drawdown_pct > 20 ? '#dc2626' : h.drawdown_pct > 10 ? '#d97706' : '#16a34a';
        drawdownHtml = `<span style="color:${ddColor};font-weight:600;">-${h.drawdown_pct}%</span>`;
      }
    }

    return `<tr data-fund-id="${fundId}" data-folio="${h.folio_number||''}">
      <td class="fund-name-cell">
        <div class="fund-title" title="${escHtml(h.scheme_name)}">${escHtml(h.scheme_name)}</div>
        <div class="fund-sub">${escHtml(h.fund_house_short||h.fund_house||'')} · ${escHtml(h.category||'')}${folioInfo ? ' · ' + h.folio_number : ''}</div>
        ${(h.active_sip_count > 0 || (h.active_swp_count||0) > 0) ? `<div style='margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;'>${h.active_sip_count > 0 ? `<span style='padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #86efac;' title='SIP ₹${h.active_sip_amount ? Number(h.active_sip_amount).toLocaleString("en-IN") : "?"} / ${h.active_sip_frequency||"monthly"}'>🔄 SIP</span>` : ''}${(h.active_swp_count||0) > 0 ? `<span style='padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;'>💸 SWP</span>` : ''}</div>` : ''}
      </td>
      <td class="text-center">${fmtInr(h.total_invested)}</td>
      <td class="text-center">${fmtInr(h.value_now)}</td>
      <td class="text-center ${gainClass}">${gainSign}${fmtInr(Math.abs(gain))}</td>
      <td class="text-center ${gainClass}">${gainSign}${gainPct}%</td>
      <td class="text-center ${h.cagr >= 0 ? 'positive' : 'negative'}">${cagr}</td>
      <td class="text-center">
        <div style="font-weight:600;">💪 ${Number(h.total_units).toFixed(4)}</div>
        ${h.ltcg_units > 0 ? `<div style="font-size:12px;margin-top:3px;color:#16a34a;font-weight:600;"><span style="font-size:14px;font-weight:900;">✓</span> L: ${Number(h.ltcg_units).toFixed(4)}</div>` : ''}
        ${h.stcg_units > 0 ? `<div style="font-size:12px;margin-top:2px;color:#ef4444;font-weight:500;">⏳ S: ${Number(h.stcg_units).toFixed(4)}</div>` : ''}
      </td>
      <td class="text-center">${nav}${navDate}</td>
      <td class="text-center">${peakNav}</td>
      <td class="text-center">${drawdownHtml}</td>
      <td style="white-space:nowrap;">
        <button class="btn btn-ghost btn-xs" onclick="openTxnDrawer(${fundId},'${escAttr(h.scheme_name)}')" title="View Transactions">📋</button>
      </td>
    </tr>`;
  }).join('');

  // Footer totals
  if (tfoot) {
    tfoot.style.display = '';
    document.getElementById('footInvested').textContent = fmtInr(totInv);
    document.getElementById('footValue').textContent    = fmtInr(totVal);
    const g = totGain;
    document.getElementById('footGain').textContent     = (g>=0?'+':'') + fmtInr(Math.abs(g));
    document.getElementById('footGain').className       = 'text-right ' + (g>=0?'positive':'negative');
    document.getElementById('footGainPct').textContent  = (g>=0?'+':'') + totPct + '%';
    document.getElementById('footGainPct').className    = 'text-right ' + (g>=0?'positive':'negative');
  }

  // --- Render pagination bar ---
  const wrap     = document.getElementById('holdingsPaginationWrap');
  const infoEl   = document.getElementById('holdingsPaginationInfo');
  const pgEl     = document.getElementById('holdingsPagination');

  if (wrap && infoEl && pgEl) {
    if (pages <= 1) {
      wrap.style.display = 'none';
    } else {
      wrap.style.display = 'flex';
      const from = pageStart + 1;
      const to   = Math.min(pageStart + perPage, total);
      infoEl.textContent = `${from}–${to} of ${total}`;

      let html = '';
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
}

function goHoldingsPage(p) {
  MF.holdingsPage = p;
  renderHoldings();
  document.getElementById('holdingsBody')?.closest('.card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
    per_page: 50
  });
  const pid = document.getElementById('txnFilterPortfolio')?.value;
  const type = document.getElementById('txnFilterType')?.value;
  const from = document.getElementById('txnFilterFrom')?.value;
  const to   = document.getElementById('txnFilterTo')?.value;
  const q    = document.getElementById('txnSearch')?.value;
  if (pid)  params.set('portfolio_id', pid);
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
      <td class="text-right">${fmtInr(t.value_at_cost)}</td>
      <td>${escHtml(t.platform||'—')}</td>
      <td>${escHtml(t.portfolio_name||'')}</td>
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
  ['txnFilterPortfolio','txnFilterType','txnFilterFrom','txnFilterTo','txnSearch'].forEach(id => {
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
    document.getElementById('txnPortfolio').value = txn.portfolio_id;
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

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function saveTransaction() {
  const editId     = document.getElementById('txnEditId').value;
  const portfolioId= document.getElementById('txnPortfolio').value;
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

  const portfolioId = document.getElementById('txnPortfolio')?.value;
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
    if (investedEl) investedEl.textContent = data.total_invested ? '₹' + parseFloat(data.total_invested).toLocaleString('en-IN', {maximumFractionDigits:2}) : '—';

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

    dropdown.innerHTML = funds.map(f => `
      <div class="autocomplete-item" data-fund-id="${f.id}" data-nav="${f.latest_nav||0}"
           style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);transition:background .15s;"
           onmousedown="selectFund(${f.id},'${escAttr(f.scheme_name)}',${f.latest_nav||0})"
           onmouseover="this.style.background='var(--bg-secondary)'"
           onmouseout="this.style.background=''">
        <div style="font-size:13px;font-weight:500;">${escHtml(f.scheme_name)}</div>
        <div style="font-size:11px;color:var(--text-muted);">${escHtml(f.fund_house_short||f.fund_house||'')} · ${escHtml(f.category||'')} · Code: ${f.scheme_code}</div>
        ${f.latest_nav ? `<div style="font-size:11px;color:var(--text-muted);">NAV: ₹${Number(f.latest_nav).toFixed(4)} (${f.latest_nav_date||''})</div>` : ''}
      </div>
    `).join('');

  } catch (err) {
    dropdown.innerHTML = `<div style="padding:12px;color:var(--danger);font-size:13px;">Search error: ${err.message}</div>`;
  }
}

function selectFund(id, name, nav) {
  document.getElementById('txnFundSearch').value = name;
  document.getElementById('txnFundId').value     = id;
  document.getElementById('fundSearchDropdown').style.display = 'none';
  MF.selectedFundId  = id;
  MF.selectedFundNav = nav;
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
      infoEl.innerHTML = `Latest NAV: <strong>₹${Number(res.latest_nav).toFixed(4)}</strong> as of ${res.latest_nav_date||''}`;
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
    return `<tr>
      <td>${formatDateDisplay(t.txn_date)}</td>
      <td><span class="badge ${typeColors[t.transaction_type]||''}">${t.transaction_type}</span></td>
      <td>${Number(t.units).toFixed(4)}</td>
      <td>₹${Number(t.nav).toFixed(4)}</td>
      <td class="text-right">${fmtInr(t.value_at_cost)}</td>
      <td>${t.folio_number||'—'}</td>
      ${ltcgCell}
      <td>
        <button class="btn btn-ghost btn-xs" onclick="editTransaction(${t.id})">✏️</button>
        <button class="btn btn-ghost btn-xs" onclick="deleteTransaction(${t.id},'${escAttr(t.scheme_name)}')">🗑</button>
      </td>
    </tr>`;
  }).join('');

  // Pagination buttons
  let paginationHtml = '';
  if (pages > 1) {
    const btnStyle = (active) => `style="min-width:32px;height:32px;border:1px solid var(--border);border-radius:6px;background:${active ? 'var(--accent)' : 'var(--bg-surface)'};color:${active ? '#fff' : 'var(--text-primary)'};font-size:13px;font-weight:${active ? '600' : '400'};cursor:${active ? 'default' : 'pointer'};padding:0 8px;"`;

    let btns = '';
    // Prev
    btns += `<button ${btnStyle(false)} ${page===1?'disabled style="opacity:.4;cursor:default;"':''} onclick="goDrawerPage(${page-1})">‹</button>`;
    // Page numbers
    for (let p = 1; p <= pages; p++) {
      if (p === 1 || p === pages || (p >= page-2 && p <= page+2)) {
        btns += `<button ${btnStyle(p===page)} onclick="goDrawerPage(${p})">${p}</button>`;
      } else if (p === page-3 || p === page+3) {
        btns += `<span style="padding:0 4px;color:var(--text-muted);">…</span>`;
      }
    }
    // Next
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
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
      <button class="btn btn-primary btn-sm" onclick="openAddTxnForFund(${fundId},'${escAttr(fundName)}')">+ Add</button>
    </div>
    <table class="table table-hover" style="font-size:13px;">
      <thead><tr>
        <th>Date</th><th>Type</th><th>Units</th><th>NAV</th><th class="text-right">Amount</th><th>Folio</th><th>LTCG Date</th><th></th>
      </tr></thead>
      <tbody>${drawerRows}</tbody>
    </table>
    ${paginationHtml}
  `;
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
  const portfolioId = document.getElementById('importPortfolio').value;
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

function setViewActive(activeId) {
  ['viewCombined','viewFolio'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('active', id === activeId);
  });
}

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
    if (abs >= 1e7)      s = '₹' + (abs / 1e7).toFixed(2) + ' Cr';
    else if (abs >= 1e5) s = '₹' + (abs / 1e5).toFixed(2) + ' L';
    else                 s = '₹' + ic(abs) + '.' + dec;
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
    });
  });

  // Realized gains filters
  ['rFilterPortfolio','rFilterFy','rFilterType'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => { RG.loaded = false; loadRealizedGains(); });
  });
  const rSearch = document.getElementById('rSearchFund');
  if (rSearch) rSearch.addEventListener('input', debounce(() => renderRealized(), 250));

  // Dividend filters
  ['dFilterPortfolio','dFilterFy'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => { DIV.loaded = false; loadDividends(); });
  });
  const dSearch = document.getElementById('dSearchFund');
  if (dSearch) dSearch.addEventListener('input', debounce(() => renderDividends(), 250));
});

/* ── Realized Gains ──────────────────────────────────────── */
const RG = { data: [], loaded: false };

async function loadRealizedGains() {
  const sel = document.getElementById('rFilterPortfolio');
  let portfolioId = sel?.value || '';
  if (!portfolioId && sel && sel.options.length > 1) {
    portfolioId = sel.options[1].value;
    sel.value = portfolioId;
  }
  if (!portfolioId) {
    document.getElementById('realizedBody').innerHTML =
      `<tr><td colspan="11" class="text-center" style="padding:32px;color:var(--text-muted);">Please select a portfolio above.</td></tr>`;
    return;
  }
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
      <td class="text-center">${fmtInr(r.proceeds)}</td>
      <td class="text-center">${fmtInr(r.cost)}</td>
      <td class="text-center ${gainCls}" style="font-weight:600;">${r.gain >= 0 ? '+' : ''}${fmtInr(r.gain)}</td>
      <td class="text-center">${r.days_held ?? '—'} days</td>
      <td class="text-center"><span class="badge ${typeCls}">${escHtml(r.gain_type||'')}</span></td>
      <td class="text-center">${r.tax_rate != null ? r.tax_rate + '%' : '—'}</td>
      <td class="text-center" style="font-size:12px;color:var(--text-muted);">${escHtml(r.fy||'')}</td>
    </tr>`;
  }).join('');

  document.getElementById('rFootProceeds').textContent = fmtInr(totProceeds);
  document.getElementById('rFootCost').textContent     = fmtInr(totCost);
  const gainEl = document.getElementById('rFootGain');
  gainEl.textContent  = (totGain >= 0 ? '+' : '') + fmtInr(totGain);
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
  const sel = document.getElementById('dFilterPortfolio');
  let portfolioId = sel?.value || '';
  if (!portfolioId && sel && sel.options.length > 1) {
    portfolioId = sel.options[1].value;
    sel.value = portfolioId;
  }
  if (!portfolioId) {
    document.getElementById('dividendsBody').innerHTML =
      `<tr><td colspan="7" class="text-center" style="padding:32px;color:var(--text-muted);">Please select a portfolio above.</td></tr>`;
    return;
  }
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
      <td class="text-center" style="font-weight:600;color:var(--gain);">${fmtInr(d.amount)}</td>
      <td class="text-center"><span class="badge badge-ltcg">Payout</span></td>
      <td class="text-center" style="font-size:12px;color:var(--text-muted);">${escHtml(d.fy||'')}</td>
    </tr>`;
  }).join('');

  document.getElementById('dFootTotal').textContent = fmtInr(total);
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
        csrf_token: csrf,
      }),
    });
    const json = await res.json();
    if (json.success) {
      const sipPageUrl = (window.WD?.appUrl || '') + '/templates/pages/report_sip.php';
      sucEl.innerHTML = `✓ ${_qsCurrentType} saved for <strong>${escHtml(fundName)}</strong>!
        &nbsp;<a href="${sipPageUrl}" style="color:#15803d;font-weight:700;text-decoration:underline;">
        View SIPs →</a>`;
      sucEl.style.display = 'block';
      btn.textContent = `✓ ${_qsCurrentType} Saved!`;
      btn.style.background = '#15803d';
      _sipHoldingsFunds = null;
      setTimeout(() => {
        document.getElementById('quickSipModal')?.remove();
        window.location.href = sipPageUrl;
      }, 1800);
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