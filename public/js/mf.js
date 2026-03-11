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
  txnFilters: {}
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

  body.innerHTML = MF.filtered.map(h => {
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

    return `<tr data-fund-id="${fundId}" data-folio="${h.folio_number||''}">
      <td class="fund-name-cell">
        <div class="fund-title" title="${escHtml(h.scheme_name)}">${escHtml(h.scheme_name)}</div>
        <div class="fund-sub">${escHtml(h.fund_house_short||h.fund_house||'')} · ${escHtml(h.category||'')}${folioInfo ? ' · ' + h.folio_number : ''}</div>
      </td>
      <td class="text-center">${fmtInr(h.total_invested)}</td>
      <td class="text-center">${fmtInr(h.value_now)}</td>
      <td class="text-center ${gainClass}">${gainSign}${fmtInr(Math.abs(gain))}</td>
      <td class="text-center ${gainClass}">${gainSign}${gainPct}%</td>
      <td class="text-center ${h.cagr >= 0 ? 'positive' : 'negative'}">${cagr}</td>
      <td class="text-center">${Number(h.total_units).toFixed(4)}</td>
      <td class="text-center">${nav}${navDate}</td>
      <td class="text-center">${typeTag}</td>
      <td class="text-center">${ltcgDate}</td>
      <td>
        <div style="display:flex;gap:4px;">
          <button class="btn btn-ghost btn-xs" onclick="openTxnDrawer(${fundId},'${escAttr(h.scheme_name)}')" title="Transactions">📋</button>
          <button class="btn btn-ghost btn-xs" onclick="openAddTxnForFund(${fundId},'${escAttr(h.scheme_name)}')" title="Add">+</button>
        </div>
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
}

function updateSummaryCards(summary) {
  const inv  = summary.total_invested  || 0;
  const val  = summary.value_now       || 0;
  const gain = summary.gain_loss       || 0;
  const pct  = inv > 0 ? ((gain/inv)*100).toFixed(2) : '0.00';

  setEl('mfTotalInvested', fmtInr(inv));
  setEl('mfValueNow',      fmtInr(val));
  const gainEl = document.getElementById('mfGainLoss');
  if (gainEl) {
    gainEl.textContent = (gain>=0?'+':'') + fmtInr(Math.abs(gain)) + ' (' + (gain>=0?'+':'') + pct + '%)';
    gainEl.className   = 'stat-value ' + (gain>=0?'positive':'negative');
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
  body.innerHTML = `<tr><td colspan="10" class="text-center" style="padding:40px;"><div class="spinner"></div></td></tr>`;

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
    body.innerHTML = `<tr><td colspan="10" class="text-center text-danger">${err.message}</td></tr>`;
  }
}

function renderTxnTable(txns) {
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
  document.getElementById('txnDate').value     = new Date().toISOString().split('T')[0];
  document.getElementById('txnError').style.display = 'none';
  document.getElementById('txnValuePreview').style.display = 'none';
  MF.selectedFundId = null; MF.selectedFundNav = null;
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
  if (!confirm(`Delete transaction for "${fundName}"? This will recalculate holdings.`)) return;

  try {
    const csrf = document.getElementById('txnCsrf')?.value || await getCsrf();
    await API.post('/api/mutual_funds/mf_delete.php', { txn_id: txnId, csrf_token: csrf });
    showToast('Transaction deleted');
    reloadCurrentPage();
  } catch (err) {
    showToast('Delete failed: ' + err.message, 'error');
  }
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
  drawer.style.display  = 'block';

  try {
    const res = await API.get(`/api/mutual_funds/mf_list.php?view=transactions&fund_id=${fundId}&per_page=100`);
    const txns = res.data || [];

    if (!txns.length) {
      content.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:40px;">No transactions found</p>';
      return;
    }

    const typeColors = { BUY:'badge-success', SELL:'badge-danger', DIV_REINVEST:'badge-info',
                         SWITCH_IN:'badge-primary', SWITCH_OUT:'badge-warning' };

    content.innerHTML = `
      <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
        <button class="btn btn-primary btn-sm" onclick="openAddTxnForFund(${fundId},'${escAttr(fundName)}')">+ Add</button>
      </div>
      <table class="table table-hover" style="font-size:13px;">
        <thead><tr>
          <th>Date</th><th>Type</th><th>Units</th><th>NAV</th><th class="text-right">Amount</th><th>Folio</th><th></th>
        </tr></thead>
        <tbody>
          ${txns.map(t => `
            <tr>
              <td>${formatDateDisplay(t.txn_date)}</td>
              <td><span class="badge ${typeColors[t.transaction_type]||''}">${t.transaction_type}</span></td>
              <td>${Number(t.units).toFixed(4)}</td>
              <td>₹${Number(t.nav).toFixed(4)}</td>
              <td class="text-right">${fmtInr(t.value_at_cost)}</td>
              <td>${t.folio_number||'—'}</td>
              <td>
                <button class="btn btn-ghost btn-xs" onclick="editTransaction(${t.id})">✏️</button>
                <button class="btn btn-ghost btn-xs" onclick="deleteTransaction(${t.id},'${escAttr(t.scheme_name)}')">🗑</button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  } catch (err) {
    content.innerHTML = `<p style="color:var(--danger);">${err.message}</p>`;
  }
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
      resultEl.style.background = 'rgba(34,197,94,.1)';
      resultEl.style.color = 'var(--success)';
      resultEl.innerHTML = `<strong>✓ ${data.message}</strong><br>
        Imported: ${data.imported} | Skipped: ${data.skipped} | Format: ${data.format}
        ${data.errors?.length ? '<br><small>' + data.errors.join('<br>') + '</small>' : ''}`;
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


/* ═══════════════════════════════════════════════════════════════════════════
   EXCEL DOWNLOAD
═══════════════════════════════════════════════════════════════════════════ */
function downloadHoldingsExcel() {
  if (!MF.filtered || !MF.filtered.length) {
    showToast('No holdings to download', 'error'); return;
  }

  const headers = ['Fund Name','Fund House','Category','Folio','Invested (₹)','Current Value (₹)','Gain/Loss (₹)','Returns (%)','XIRR (%)','Units','NAV (₹)','Type','LTCG Date','First Purchase'];

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
    h.gain_type || '',
    h.ltcg_date ? formatDateDisplay(h.ltcg_date) : '',
    h.first_purchase_date ? formatDateDisplay(h.first_purchase_date) : ''
  ]);

  // Add totals row
  const totInv  = MF.filtered.reduce((s,h) => s + (Number(h.total_invested)||0), 0);
  const totVal  = MF.filtered.reduce((s,h) => s + (Number(h.value_now)||0), 0);
  const totGain = totVal - totInv;
  const totPct  = totInv > 0 ? ((totGain/totInv)*100).toFixed(2) : '0.00';
  rows.push(['TOTAL','','','', totInv.toFixed(2), totVal.toFixed(2), totGain.toFixed(2), totPct, '', '', '', '', '', '']);

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
  const abs = Math.abs(n);
  let s;
  if (abs >= 1e7)       s = '₹' + (abs/1e7).toFixed(2) + 'Cr';
  else if (abs >= 1e5)  s = '₹' + (abs/1e5).toFixed(2) + 'L';
  else                  s = '₹' + abs.toLocaleString('en-IN', {minimumFractionDigits:2,maximumFractionDigits:2});
  return n < 0 ? '-' + s : s;
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