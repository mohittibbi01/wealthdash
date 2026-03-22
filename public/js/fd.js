/**
 * WealthDash — Fixed Deposits Frontend JS
 * Handles: FD list, add, delete, mature, filter, tab toggle
 */

'use strict';

const FD = (() => {

  // ── State ────────────────────────────────────────────────
  let currentStatus = 'active';
  let editingId     = null;

  // ── Helpers ──────────────────────────────────────────────
  const $ = id => document.getElementById(id);
  const fmtDate = str => {
    if (!str) return '—';
    const d = new Date(str);
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
  };
  const daysDiff = dateStr => Math.ceil((new Date(dateStr) - new Date()) / 86400000);

  // ── Load FD list ─────────────────────────────────────────
  async function loadFds() {
    const portfolioId = $('filterPortfolio')?.value || '';
    setBodyLoading();
    try {
      const res = await apiGet({
        action:       'fd_list',
        status:       currentStatus,
        portfolio_id: portfolioId,
      });
      if (res.success) {
        renderTable(res.data || []);
      } else {
        setBodyError(res.message || 'Failed to load FDs.');
      }
    } catch (e) {
      setBodyError('Network error. Please refresh.');
    }
  }

  // ── Render table rows ────────────────────────────────────
  // t20: Pagination state
  let fdPage = 1, fdPerPage = 10, _fdAllRows = [];

  function renderFdPagination(total, pages, startIdx) {
    const wrap = document.getElementById('fdPagWrap');
    if (!wrap) return;
    if (pages <= 1 && total <= 10) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = `<div style="display:flex;align-items:center;gap:8px;padding:10px 0;flex-wrap:wrap;">
      <select onchange="FD.setFdPerPage(+this.value)" style="padding:4px 8px;border-radius:6px;border:1px solid var(--border);background:var(--bg-secondary);color:var(--text-primary);font-size:12px;">
        ${[10,25,50,999].map(n=>`<option value="${n}" ${n===fdPerPage?'selected':''}>${n===999?'All':n}</option>`).join('')}
      </select>
      <span style="font-size:12px;color:var(--text-muted);">${Math.min(startIdx+1,total)}–${Math.min(startIdx+fdPerPage,total)} of ${total}</span>
      <div style="display:flex;gap:4px;margin-left:auto;">
        <button onclick="FD.goFdPage(${fdPage-1})" ${fdPage<=1?'disabled':''} class="btn btn-ghost btn-sm">‹</button>
        ${Array.from({length:Math.min(5,pages)},(_,i)=>{const p=Math.max(1,Math.min(fdPage-2,pages-4))+i;return `<button onclick="FD.goFdPage(${p})" class="btn btn-sm ${p===fdPage?'btn-primary':'btn-ghost'}">${p}</button>`;}).join('')}
        <button onclick="FD.goFdPage(${fdPage+1})" ${fdPage>=pages?'disabled':''} class="btn btn-ghost btn-sm">›</button>
      </div>
    </div>`;
  }

  function renderTable(rows) {
    const tbody = $('fdBody');
    if (!tbody) return;
    const search   = ($('searchBank')?.value || '').toLowerCase();
    const filtered = search ? rows.filter(r => (r.bank_name || '').toLowerCase().includes(search)) : rows;

    if (!filtered.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="12" class="text-center" style="padding:48px;color:var(--text-muted)">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" style="opacity:.3;display:block;margin:0 auto 12px">
              <rect x="3" y="8" width="18" height="13" rx="2"/>
              <path d="M8 8V6a4 4 0 0 1 8 0v2"/>
            </svg>
            No ${currentStatus || ''} Fixed Deposits found
          </td>
        </tr>`;
      return;
    }

    // paginate
    const total=filtered.length;
    const pages=fdPerPage>=999?1:Math.ceil(total/fdPerPage);
    if(fdPage>pages)fdPage=1;
    const startIdx=(fdPage-1)*(fdPerPage>=999?total:fdPerPage);
    const paged=fdPerPage>=999?filtered:filtered.slice(startIdx,startIdx+fdPerPage);
    renderFdPagination(total, pages, startIdx);
    tbody.innerHTML = paged.map(fd => {
      const days      = daysDiff(fd.maturity_date);
      const isMatured = fd.status === 'matured' || days < 0;
      const statusBadge = isMatured
        ? `<span class="badge badge-neutral">Matured</span>`
        : days <= 30
          ? `<span class="badge badge-danger">${days}d left</span>`
          : days <= 90
            ? `<span class="badge badge-warning">${days}d left</span>`
            : `<span class="badge badge-success">Active</span>`;

      const accrued = calcAccruedThisFY(fd);
      const fdJson  = encodeURIComponent(JSON.stringify(fd));

      return `
        <tr>
          <td><strong>${escapeHtml(fd.bank_name || '—')}</strong></td>
          <td style="font-family:monospace;font-size:12px;color:var(--text-muted)">
            ${fd.account_number ? '••••' + String(fd.account_number).slice(-4) : '—'}
          </td>
          <td>${escapeHtml(fd.portfolio_name || '—')}</td>
          <td class="text-right">${fmtINR(fd.principal)}</td>
          <td class="text-right">${parseFloat(fd.rate || 0).toFixed(2)}%</td>
          <td>${fmtDate(fd.start_date)}</td>
          <td>${fmtDate(fd.maturity_date)}</td>
          <td class="text-right text-success">${fmtINR(fd.maturity_amount)}</td>
          <td class="text-right text-success">${fmtINR(fd.interest_earned)}</td>
          <td>${statusBadge}</td>
          <td class="text-right">${fmtINR(accrued)}</td>
          <td class="text-center">
            <div style="display:flex;gap:6px;justify-content:center;align-items:center">
              ${!isMatured ? `<button class="btn btn-xs btn-ghost" onclick="FD.markMatured(${fd.id})" title="Mark Matured">✓</button>` : ''}
              <button class="btn btn-xs btn-ghost" onclick="FD.openEdit(decodeURIComponent('${fdJson}'))" title="Edit">✎</button>
              <button class="btn btn-xs" style="background:var(--loss-bg,#fee2e2);color:var(--loss,#dc2626);border:none;cursor:pointer;padding:4px 8px;border-radius:4px;font-size:12px" onclick="FD.deleteFd(${fd.id})" title="Delete">✕</button>
            </div>
          </td>
        </tr>`;
    }).join('');
  }

  // ── Accrued this FY ──────────────────────────────────────
  function calcAccruedThisFY(fd) {
    const now    = new Date();
    const fyStart = now.getMonth() >= 3
      ? new Date(now.getFullYear(), 3, 1)
      : new Date(now.getFullYear() - 1, 3, 1);
    const start    = new Date(fd.start_date);
    const maturity = new Date(fd.maturity_date);
    const from     = start > fyStart ? start : fyStart;
    const to       = maturity < now  ? maturity : now;
    if (from >= to) return 0;
    const principal = parseFloat(fd.principal) || 0;
    const rate      = parseFloat(fd.rate) || 0;
    const n         = parseInt(fd.compounding_frequency) || 4;
    const tYears    = (to - from) / (365.25 * 86400000);
    return Math.max(0, principal * Math.pow(1 + rate / 100 / n, n * tYears) - principal);
  }

  // ── Loading / error states ───────────────────────────────
  function setBodyLoading() {
    const tbody = $('fdBody');
    if (tbody) tbody.innerHTML = `
      <tr>
        <td colspan="12" class="text-center" style="padding:48px;color:var(--text-muted)">
          <div class="fd-spinner-wrap">
            <div class="fd-spinner-ring"></div>
            <span style="margin-left:10px;vertical-align:middle">Loading...</span>
          </div>
        </td>
      </tr>`;
  }

  function setBodyError(msg) {
    const tbody = $('fdBody');
    if (tbody) tbody.innerHTML = `
      <tr>
        <td colspan="12" class="text-center" style="padding:48px;color:var(--loss,#dc2626)">
          ⚠ ${escapeHtml(msg)}
        </td>
      </tr>`;
  }

  // ── Filter ───────────────────────────────────────────────
  function filterTable() { loadFds(); }

  // ── Mark Matured ─────────────────────────────────────────
  function markMatured(id) {
    showConfirm({
      title:     'Mark FD as Matured?',
      message:   'This FD will be moved to the Matured list.',
      okText:    'Mark Matured',
      onConfirm: async () => {
        const res = await apiPost({ action: 'fd_mature', id });
        if (res.success) { showToast('FD marked as matured.', 'success'); await loadFds(); }
        else showToast(res.message || 'Failed.', 'error');
      }
    });
  }

  // ── Delete ───────────────────────────────────────────────
  function deleteFd(id) {
    showConfirm({
      title:     'Delete Fixed Deposit?',
      message:   'This will permanently delete this FD. This cannot be undone.',
      okText:    'Delete',
      onConfirm: async () => {
        const res = await apiPost({ action: 'fd_delete', id });
        if (res.success) { showToast('FD deleted.', 'success'); await loadFds(); }
        else showToast(res.message || 'Failed to delete.', 'error');
      }
    });
  }

  // ── Open Add ─────────────────────────────────────────────
  function openAdd() {
    editingId = null;
    $('fdModalTitle').textContent = 'Add Fixed Deposit';
    $('saveFd').textContent       = 'Save FD';
    clearForm();
    $('fdStartDate').value = new Date().toISOString().slice(0, 10);
    $('modalAddFd').style.display = 'flex';
  }

  // ── Open Edit ────────────────────────────────────────────
  function openEdit(fdJsonOrStr) {
    const fd = typeof fdJsonOrStr === 'string' ? JSON.parse(fdJsonOrStr) : fdJsonOrStr;
    editingId = fd.id;
    $('fdModalTitle').textContent  = 'Edit Fixed Deposit';
    $('saveFd').textContent        = 'Update FD';
    $('fdPortfolio').value         = fd.portfolio_id          || '';
    $('fdBankName').value          = fd.bank_name             || '';
    $('fdAccountNumber').value     = fd.account_number        || '';
    $('fdPrincipal').value         = fd.principal             || '';
    $('fdRate').value              = fd.rate                  || '';
    $('fdCompounding').value       = fd.compounding_frequency || 4;
    $('fdStartDate').value         = fd.start_date            || '';
    $('fdMaturityDate').value      = fd.maturity_date         || '';
    $('fdSenior').value            = fd.is_senior_citizen     || 0;
    $('fdTds').value               = fd.tds_applicable !== undefined ? fd.tds_applicable : 1;
    $('fdNotes').value             = fd.notes                 || '';
    calcMaturity();
    $('modalAddFd').style.display = 'flex';
  }

  function clearForm() {
    ['fdBankName','fdAccountNumber','fdPrincipal','fdRate','fdStartDate','fdMaturityDate','fdNotes']
      .forEach(id => { const el = $(id); if (el) el.value = ''; });
    $('fdCompounding').value     = '4';
    $('fdSenior').value          = '0';
    $('fdTds').value             = '1';
    $('fdPreview').style.display = 'none';
    $('fdError').style.display   = 'none';
  }

  // ── Calc Maturity ─────────────────────────────────────────
  function calcMaturity() {
    const principal = parseFloat($('fdPrincipal')?.value) || 0;
    const rate      = parseFloat($('fdRate')?.value) || 0;
    const n         = parseInt($('fdCompounding')?.value) || 4;
    const startVal  = $('fdStartDate')?.value;
    const matVal    = $('fdMaturityDate')?.value;
    if (!principal || !rate || !startVal || !matVal) { $('fdPreview').style.display = 'none'; return; }
    const start    = new Date(startVal);
    const maturity = new Date(matVal);
    if (maturity <= start) { $('fdPreview').style.display = 'none'; return; }
    const years    = (maturity - start) / (365.25 * 86400000);
    const matAmt   = principal * Math.pow(1 + rate / 100 / n, n * years);
    const interest = matAmt - principal;
    const yldPct   = ((matAmt / principal - 1) / years * 100).toFixed(2);
    const totalDays = Math.round((maturity - start) / 86400000);
    $('prevTenure').textContent   = totalDays >= 365 ? `${(totalDays/365).toFixed(1)} yrs (${totalDays} days)` : `${totalDays} days`;
    $('prevMaturity').textContent = fmtINR(matAmt);
    $('prevInterest').textContent = fmtINR(interest);
    $('prevYield').textContent    = yldPct + '% p.a. effective';
    $('fdPreview').style.display  = 'block';
  }

  // ── Save ─────────────────────────────────────────────────
  async function saveFd() {
    const errEl = $('fdError');
    errEl.style.display = 'none';

    const payload = {
      action:                editingId ? 'fd_edit' : 'fd_add',
      portfolio_id:          $('fdPortfolio')?.value,
      bank_name:             $('fdBankName')?.value.trim(),
      account_number:        $('fdAccountNumber')?.value.trim(),
      principal:             $('fdPrincipal')?.value,
      rate:                  $('fdRate')?.value,
      compounding_frequency: $('fdCompounding')?.value,
      start_date:            $('fdStartDate')?.value,
      maturity_date:         $('fdMaturityDate')?.value,
      is_senior_citizen:     $('fdSenior')?.value,
      tds_applicable:        $('fdTds')?.value,
      notes:                 $('fdNotes')?.value.trim(),
    };
    if (editingId) payload.id = editingId;

    if (!payload.bank_name)     return showErr('Bank name is required.');
    if (!payload.principal)     return showErr('Principal amount is required.');
    if (!payload.rate)          return showErr('Interest rate is required.');
    if (!payload.start_date)    return showErr('Start date is required.');
    if (!payload.maturity_date) return showErr('Maturity date is required.');

    const btn = $('saveFd');
    btn.disabled = true; btn.textContent = 'Saving...';

    try {
      const res = await apiPost(payload);
      if (res.success) {
        showToast(editingId ? 'FD updated.' : 'FD added successfully!', 'success');
        closeFdModal();
        await loadFds();
      } else {
        showErr(res.message || 'Failed to save FD.');
      }
    } catch (e) {
      showErr('Network error. Please try again.');
    } finally {
      btn.disabled = false;
      btn.textContent = editingId ? 'Update FD' : 'Save FD';
    }
  }

  function showErr(msg) {
    const el = $('fdError');
    el.textContent   = msg;
    el.style.display = 'block';
  }

  function closeFdModal() {
    $('modalAddFd').style.display = 'none';
    clearForm();
    editingId = null;
  }

  // ── Tabs ─────────────────────────────────────────────────
  function initTabs() {
    document.querySelectorAll('.view-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentStatus = btn.dataset.status || '';
        loadFds();
      });
    });
  }

  // ── Init ─────────────────────────────────────────────────
  function init() {
    initTabs();
    $('btnAddFd')?.addEventListener('click', openAdd);
    $('closeFdModal')?.addEventListener('click', closeFdModal);
    $('cancelFd')?.addEventListener('click', closeFdModal);
    $('saveFd')?.addEventListener('click', saveFd);
    $('filterPortfolio')?.addEventListener('change', loadFds);
    $('searchBank')?.addEventListener('input', filterTable);
    $('modalAddFd')?.addEventListener('click', e => { if (e.target === $('modalAddFd')) closeFdModal(); });
    loadFds();
  }

  document.addEventListener('DOMContentLoaded', init);

  function goFdPage(p) { fdPage = p; renderTable(_fdAllRows); }
  function setFdPerPage(n) { fdPerPage = n; fdPage = 1; renderTable(_fdAllRows); }

  return { loadFds, filterTable, calcMaturity, markMatured, deleteFd, openEdit, goFdPage, setFdPerPage };
})();