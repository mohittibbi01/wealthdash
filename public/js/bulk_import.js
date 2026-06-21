/**
 * WealthDash — Bulk Import JS (t334)
 * Path: public/js/bulk_import.js
 */
'use strict';

(function () {
  const API = {
    post: (action, data, file) => {
      const fd = new FormData();
      fd.append('action', action);
      Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
      if (file) fd.append('import_file', file);
      return fetch('/api/router.php', { method: 'POST', body: fd }).then(r => r.json());
    },
    get: (action, params) => {
      const qs = new URLSearchParams({ action, ...(params || {}) }).toString();
      return fetch('/api/router.php?' + qs).then(r => r.json());
    },
  };

  let currentFile  = null;
  let currentSessionId = null;
  let fieldRefLoaded   = false;

  // ── Init ─────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    loadPortfolios();

    document.getElementById('btnDownloadTemplate')?.addEventListener('click', downloadTemplate);
    document.getElementById('btnBulkImport')?.addEventListener('click', runImport);
    document.getElementById('btnBulkReValidate')?.addEventListener('click', () => currentFile && validate(currentFile));
    document.getElementById('btnBulkHistory')?.addEventListener('click', toggleHistory);
  });

  // ── Portfolios ───────────────────────────────────────────────
  function loadPortfolios() {
    API.get('portfolio_list', {}).then(res => {
      const portfolios = res.data?.portfolios || [];
      const sel = document.getElementById('bulkPortfolioId');
      if (!sel) return;
      sel.innerHTML = portfolios.length
        ? '<option value="">— Select Portfolio —</option>' + portfolios.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')
        : '<option value="">No portfolios found</option>';
    }).catch(() => {});
  }

  // ── File Handling ────────────────────────────────────────────
  window.bulkHandleDrop = function (e) {
    e.preventDefault();
    document.getElementById('bulkDropZone').style.borderColor = 'var(--border-color)';
    const file = e.dataTransfer.files[0];
    if (file) bulkHandleFile(file);
  };

  window.bulkHandleFile = function (file) {
    if (!file) return;
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['xlsx', 'csv'].includes(ext)) {
      showToast('Only .xlsx and .csv files supported', 'error'); return;
    }
    currentFile = file;
    document.getElementById('bulkDropZone').innerHTML =
      `<div style="padding:12px;color:var(--success);">✅ ${esc(file.name)} (${(file.size / 1024).toFixed(1)} KB)</div>`;
    validate(file);
  };

  // ── Validate ─────────────────────────────────────────────────
  function validate(file) {
    const portfolioId = document.getElementById('bulkPortfolioId').value;
    if (!portfolioId) { showToast('Select a portfolio first', 'error'); return; }

    setText('bulkValidStatus', 'Validating…');
    document.getElementById('bulkValidPlaceholder').style.display = 'none';
    document.getElementById('bulkValidResult').style.display = 'block';
    document.getElementById('btnBulkImport').disabled = true;
    setText('bulkTotalRows', '…');
    setText('bulkValidRows', '…');
    setText('bulkErrorCount', '…');

    API.post('bulk_validate', { portfolio_id: portfolioId }, file).then(res => {
      if (!res.success) { showToast(res.message || 'Validation error', 'error'); return; }

      const d = res.data;
      currentSessionId = d.session_id;
      document.getElementById('bulkSessionId').value = d.session_id || '';

      setText('bulkTotalRows',  d.total_rows || 0);
      setText('bulkValidRows',  d.valid_rows || 0);
      setText('bulkErrorCount', d.error_count || 0);
      setText('bulkValidStatus', `Session #${d.session_id}`);

      // Error rows
      const errPanel = document.getElementById('bulkErrorPanel');
      if (d.error_count > 0 && d.error_rows?.length) {
        errPanel.innerHTML = d.error_rows.map(r =>
          `<div style="padding:8px 12px;border-bottom:1px solid var(--border-color);font-size:12px;">
            <strong>Row ${r.row}:</strong>
            <span style="color:var(--danger);">${(r.errors || []).map(esc).join(' · ')}</span>
            <span style="color:var(--text-muted);margin-left:8px;">${(r.data || []).slice(0,3).map(esc).join(', ')}</span>
          </div>`
        ).join('');
        errPanel.style.display = 'block';
      } else {
        errPanel.style.display = 'none';
      }

      // Enable import if any valid rows
      document.getElementById('btnBulkImport').disabled = (d.valid_rows || 0) === 0;

      if (d.valid_rows > 0) {
        showToast(`${d.valid_rows} rows ready to import`, 'success');
      } else {
        showToast('No valid rows found — fix errors and re-validate', 'error');
      }
    }).catch(() => showToast('Validation failed', 'error'));
  }

  // ── Import ───────────────────────────────────────────────────
  function runImport() {
    if (!currentFile) { showToast('Upload a file first', 'error'); return; }
    const portfolioId = document.getElementById('bulkPortfolioId').value;
    if (!portfolioId) { showToast('Select a portfolio', 'error'); return; }

    const skipErrors = document.getElementById('bulkSkipErrors').checked ? '1' : '0';
    const sessionId  = document.getElementById('bulkSessionId').value;
    const csrf       = document.getElementById('bulkCsrf').value;

    setBtnLoading('btnBulkImport', true);
    setBtnLoading('btnBulkReValidate', true);
    document.getElementById('bulkImportResult').style.display = 'none';
    document.getElementById('bulkImportProgress').style.display = 'block';
    setText('bulkProgressLabel', 'Importing…');
    animateProgress('bulkProgressBar', 90, 5000);

    API.post('bulk_import', {
      portfolio_id: portfolioId,
      session_id:   sessionId,
      skip_errors:  skipErrors,
      csrf_token:   csrf,
    }, currentFile).then(res => {
      document.getElementById('bulkImportProgress').style.display = 'none';

      if (res.success) {
        const d = res.data;
        document.getElementById('bulkImportResult').innerHTML = `
          <div style="padding:14px;background:rgba(16,185,129,.06);border-radius:10px;border:1px solid rgba(16,185,129,.2);">
            <div style="font-weight:700;color:var(--success);margin-bottom:8px;">✅ Import Complete</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:13px;text-align:center;">
              <div><strong>${d.imported}</strong><br><span style="font-size:11px;color:var(--text-muted);">Imported</span></div>
              <div><strong>${d.skipped}</strong><br><span style="font-size:11px;color:var(--text-muted);">Skipped</span></div>
              <div><strong style="color:${d.errors > 0 ? 'var(--danger)' : 'inherit'};">${d.errors}</strong><br><span style="font-size:11px;color:var(--text-muted);">Errors</span></div>
            </div>
            ${d.import_log?.filter(r => r.status === 'error').length ? `
              <details style="margin-top:8px;font-size:12px;">
                <summary style="cursor:pointer;color:var(--danger);">Show ${d.errors} error rows</summary>
                <div style="margin-top:4px;">
                  ${d.import_log.filter(r => r.status === 'error').map(r =>
                    `<div>Row ${r.row}: ${(r.errors || []).join(', ')}</div>`
                  ).join('')}
                </div>
              </details>` : ''}
          </div>`;
        document.getElementById('bulkImportResult').style.display = 'block';
        showToast(`${d.imported} transactions imported`, 'success');
      } else {
        document.getElementById('bulkImportResult').innerHTML =
          `<div style="padding:12px;background:rgba(239,68,68,.08);border-radius:8px;color:var(--danger);">❌ ${esc(res.message || 'Import failed')}</div>`;
        document.getElementById('bulkImportResult').style.display = 'block';
      }
    }).catch(() => {
      document.getElementById('bulkImportProgress').style.display = 'none';
      showToast('Network error during import', 'error');
    }).finally(() => {
      setBtnLoading('btnBulkImport', false);
      setBtnLoading('btnBulkReValidate', false);
    });
  }

  // ── Template Download ────────────────────────────────────────
  function downloadTemplate() {
    setBtnLoading('btnDownloadTemplate', true);
    const link = document.createElement('a');
    link.href  = '/api/router.php?action=bulk_template_download';
    link.click();
    setTimeout(() => setBtnLoading('btnDownloadTemplate', false), 2000);
  }

  // ── Field Reference ──────────────────────────────────────────
  window.toggleFieldRef = function () {
    const content = document.getElementById('fieldRefContent');
    const toggle  = document.getElementById('fieldRefToggle');
    const visible = content.style.display !== 'none';
    content.style.display = visible ? 'none' : 'block';
    toggle.textContent = visible ? '▼ Expand' : '▲ Collapse';
    if (!visible && !fieldRefLoaded) {
      fieldRefLoaded = true;
      loadFieldRef();
    }
  };

  function loadFieldRef() {
    API.get('bulk_template_fields', {}).then(res => {
      const fields = res.data?.fields || [];
      document.getElementById('fieldRefBody').innerHTML = fields.map(f => `
        <tr style="${f.required ? 'background:rgba(59,130,246,.03);' : ''}">
          <td style="font-size:12px;font-family:monospace;color:var(--primary);">${esc(f.col)}</td>
          <td style="font-size:12px;font-family:monospace;">${esc(f.field)}</td>
          <td style="font-size:12px;">${esc(f.label)}</td>
          <td style="font-size:12px;">${f.required ? '<span style="color:var(--danger);font-weight:700;">Required</span>' : '<span style="color:var(--text-muted);">Optional</span>'}</td>
          <td style="font-size:12px;">${esc(f.type)}</td>
          <td style="font-size:12px;color:var(--text-muted);">${esc(f.example || '')}</td>
        </tr>`).join('');
    }).catch(() => {
      document.getElementById('fieldRefBody').innerHTML = '<tr><td colspan="6" class="text-center" style="color:var(--danger);">Failed to load field definitions</td></tr>';
    });
  }

  // ── History ──────────────────────────────────────────────────
  function toggleHistory() {
    const panel = document.getElementById('bulkHistoryPanel');
    const visible = panel.style.display !== 'none';
    panel.style.display = visible ? 'none' : 'block';
    if (!visible) loadHistory();
  }

  function loadHistory() {
    API.get('bulk_session_list', {}).then(res => {
      const list  = res.data?.sessions || [];
      const tbody = document.getElementById('bulkHistoryBody');
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center" style="color:var(--text-muted);">No import sessions yet</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(s => `<tr>
        <td style="font-size:12px;">${s.created_at}</td>
        <td style="font-size:12px;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(s.filename || '—')}</td>
        <td class="text-right">${s.total_rows || 0}</td>
        <td class="text-right">${s.valid_rows || 0}</td>
        <td class="text-right">${s.imported || 0}</td>
        <td class="text-right" style="color:${s.error_count > 0 ? 'var(--danger)' : 'inherit'};">${s.error_count || 0}</td>
        <td><span style="font-size:11px;padding:2px 8px;border-radius:10px;background:${s.status==='done'?'rgba(16,185,129,.1)':s.status==='failed'?'rgba(239,68,68,.1)':'var(--bg-secondary)'};color:${s.status==='done'?'var(--success)':s.status==='failed'?'var(--danger)':'var(--text-muted)'};">${s.status}</span></td>
        <td><button class="btn btn-ghost btn-xs" onclick="viewSessionDetail(${s.id})">Detail</button></td>
      </tr>`).join('');
    });
  }

  window.viewSessionDetail = function (id) {
    API.get('bulk_session_detail', { id }).then(res => {
      const s = res.data?.session;
      if (!s) return;
      const errs = (s.validation_log || []).concat(s.import_log?.filter(r => r.status === 'error') || []);
      if (!errs.length) { showToast('No errors in this session', 'info'); return; }
      // Simple alert
      alert(`Session #${id} Errors:\n` + errs.slice(0,20).map(e => `Row ${e.row}: ${(e.errors || []).join(', ')}`).join('\n'));
    });
  };

  // ── Utils ─────────────────────────────────────────────────────
  function setText(id, val)     { const el = document.getElementById(id); if (el) el.textContent = val; }
  function setBtnLoading(id, l) { const b  = document.getElementById(id); if (b) b.disabled = l; }
  function esc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function animateProgress(id, targetPct, durationMs) {
    const el = document.getElementById(id); if (!el) return;
    let pct = 0; const step = targetPct / (durationMs / 50);
    const t = setInterval(() => { pct = Math.min(pct + step, targetPct); el.style.width = pct + '%'; if (pct >= targetPct) clearInterval(t); }, 50);
  }
  function showToast(msg, type = 'info') { if (window.showToast) window.showToast(msg, type); else console.log(`[${type}] ${msg}`); }
})();
