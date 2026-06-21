/**
 * WealthDash — Groww Import JS (t302 + t392)
 * Path: public/js/groww_import.js
 */
'use strict';

(function () {
  const API = {
    post: (action, data, file) => {
      const fd = new FormData();
      fd.append('action', action);
      Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
      if (file) fd.append('csv_file', file);
      return fetch('/api/router.php', { method: 'POST', body: fd }).then(r => r.json());
    },
    get: (action, params) => {
      const qs = new URLSearchParams({ action, ...(params || {}) }).toString();
      return fetch('/api/router.php?' + qs).then(r => r.json());
    },
  };

  let currentFile = null;
  let currentDetect = null;
  let portfolios = [];

  // ── Init ────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    loadPortfolios();
    initTabs();

    document.getElementById('csvDropZone')?.addEventListener('click', () =>
      document.getElementById('csvFileInput').click()
    );
    document.getElementById('btnCsvImport')?.addEventListener('click', () => runCsvImport(false));
    document.getElementById('btnCsvPreviewOnly')?.addEventListener('click', () => runCsvImport(true));
    document.getElementById('btnApiConnect')?.addEventListener('click', connectGrowwApi);
    document.getElementById('btnSyncMf')?.addEventListener('click', () => syncGroww('mf'));
    document.getElementById('btnSyncStocks')?.addEventListener('click', () => syncGroww('stocks'));
    document.getElementById('btnGrowwHistory')?.addEventListener('click', toggleHistory);
    document.getElementById('btnRefreshMappings')?.addEventListener('click', loadMappings);
  });

  // ── Tabs ─────────────────────────────────────────────────────
  function initTabs() {
    document.querySelectorAll('.groww-tab').forEach(btn => {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.groww-tab').forEach(b => {
          b.style.borderBottomColor = 'transparent';
          b.style.color = 'var(--text-muted)';
          b.style.fontWeight = '400';
          b.classList.remove('active');
        });
        this.style.borderBottomColor = 'var(--primary)';
        this.style.color = 'var(--primary)';
        this.style.fontWeight = '600';
        this.classList.add('active');

        document.querySelectorAll('.groww-tab-content').forEach(c => c.style.display = 'none');
        const tab = this.dataset.tab;
        document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1)).style.display = 'block';

        if (tab === 'api')     { loadApiStatus(); }
        if (tab === 'mapping') { loadMappings(); }
      });
    });
  }

  // ── Portfolios ───────────────────────────────────────────────
  function loadPortfolios() {
    API.get('portfolio_list', {}).then(res => {
      portfolios = res.data?.portfolios || [];
      ['csvPortfolioId', 'apiMfPortfolioId', 'apiStockPortfolioId'].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        sel.innerHTML = portfolios.length
          ? portfolios.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')
          : '<option value="">No portfolios found</option>';
      });
    }).catch(() => {});
  }

  // ── CSV Drop / File ──────────────────────────────────────────
  window.growwHandleDrop = function (e) {
    e.preventDefault();
    document.getElementById('csvDropZone').style.borderColor = 'var(--border-color)';
    const file = e.dataTransfer.files[0];
    if (file) growwHandleFile(file);
  };

  window.growwHandleFile = function (file) {
    if (!file || !file.name.endsWith('.csv')) {
      showToast('Only .csv files supported', 'error'); return;
    }
    currentFile = file;
    detectCsv(file);
  };

  function detectCsv(file) {
    const zone = document.getElementById('csvDropZone');
    zone.innerHTML = '<div style="padding:16px;color:var(--text-muted);">🔍 Detecting format…</div>';

    API.post('groww_detect', {}, file).then(res => {
      zone.innerHTML = `<div style="padding:12px;color:var(--success);">✅ ${esc(file.name)}</div>`;

      if (!res.success) {
        showResult('csvImportResult', false, res.message || 'Detection failed'); return;
      }

      currentDetect = res.data;
      renderDetectResult(res.data);
    }).catch(err => {
      zone.innerHTML = '<div style="padding:12px;color:var(--danger);">❌ Upload failed</div>';
      showToast('Detection error', 'error');
    });
  }

  function renderDetectResult(d) {
    if (d.ambiguous) {
      showResult('csvImportResult', false, 'Could not detect CSV format. Try the Bulk Import page for generic CSVs.');
      return;
    }

    const typeColor = d.type === 'mf' ? 'var(--primary)' : 'var(--success)';
    setText('csvFormatLabel', 'Groww ' + (d.type_label || d.type));
    setText('csvTypeLabel', d.type_label || d.type);
    setText('csvRowCount', d.total_rows);

    const badge = document.getElementById('csvConfidenceBadge');
    badge.textContent = 'Auto-detected';
    badge.style.background = 'var(--success)';

    // Preview headers
    const headers = d.headers || [];
    document.getElementById('csvPreviewHeaders').innerHTML =
      headers.map(h => `<th>${esc(h)}</th>`).join('') + '<th>Status</th>';

    document.getElementById('csvPreviewBody').innerHTML =
      (d.preview || []).map(row => {
        const data = row.data || {};
        const ok   = row.valid;
        const vals = Object.values(data).filter(v => !String(v || '').startsWith('_')).slice(0, headers.length);
        return `<tr style="background:${ok ? '' : 'rgba(239,68,68,.04)'}">
          ${vals.map(v => `<td style="font-size:12px;">${esc(String(v ?? ''))}</td>`).join('')}
          <td>${ok ? '✅' : '<span style="color:var(--danger);">⚠️ ' + esc((row.errors || []).join(', ')) + '</span>'}</td>
        </tr>`;
      }).join('');

    document.getElementById('csvDetectPlaceholder').style.display = 'none';
    document.getElementById('csvDetectResult').style.display = 'block';
  }

  // ── CSV Import ───────────────────────────────────────────────
  function runCsvImport(previewOnly) {
    if (!currentFile)  { showToast('Upload a CSV first', 'error'); return; }
    const portfolioId = document.getElementById('csvPortfolioId').value;
    if (!portfolioId) { showToast('Select a portfolio', 'error'); return; }

    setBtnLoading('btnCsvImport', true);
    setBtnLoading('btnCsvPreviewOnly', true);
    document.getElementById('csvImportResult').style.display = 'none';
    document.getElementById('csvImportProgress').style.display = 'block';
    setText('csvProgressLabel', previewOnly ? 'Running preview…' : 'Importing…');
    animateProgress('csvProgressBar', 80, 3000);

    API.post('groww_import_csv', {
      portfolio_id: portfolioId,
      preview_only: previewOnly ? '1' : '0',
      csrf_token: getCsrf(),
    }, currentFile).then(res => {
      document.getElementById('csvImportProgress').style.display = 'none';
      document.getElementById('csvProgressBar').style.width = '100%';

      if (res.success) {
        const d = res.data;
        const html = `
          <div style="padding:14px;background:var(--bg-secondary);border-radius:10px;">
            <div style="font-weight:700;margin-bottom:8px;color:${previewOnly ? 'var(--primary)' : 'var(--success)'};">
              ${previewOnly ? '👁️ Preview Result' : '✅ Import Complete'}
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:13px;">
              <div><span style="color:var(--text-muted);">Imported</span><br><strong>${d.imported || 0}</strong></div>
              <div><span style="color:var(--text-muted);">Skipped</span><br><strong>${d.skipped || 0}</strong></div>
              <div><span style="color:var(--text-muted);">Errors</span><br><strong style="color:${d.errors > 0 ? 'var(--danger)' : 'inherit'};">${d.errors || 0}</strong></div>
            </div>
            ${d.error_log?.length ? `<div style="margin-top:8px;font-size:11px;color:var(--danger);">${d.error_log.slice(0,5).map(esc).join('<br>')}</div>` : ''}
          </div>`;
        document.getElementById('csvImportResult').innerHTML = html;
        document.getElementById('csvImportResult').style.display = 'block';

        // Show unmapped alert
        if ((d.errors || 0) > 0 && !previewOnly) {
          loadMappings(true);
        }
      } else {
        showResult('csvImportResult', false, res.message || 'Import failed');
      }
    }).catch(() => {
      document.getElementById('csvImportProgress').style.display = 'none';
      showResult('csvImportResult', false, 'Network error');
    }).finally(() => {
      setBtnLoading('btnCsvImport', false);
      setBtnLoading('btnCsvPreviewOnly', false);
    });
  }

  // ── API Status ───────────────────────────────────────────────
  function loadApiStatus() {
    API.get('groww_api_status', {}).then(res => {
      const d = res.data || {};

      if (!d.connected) {
        setText('apiStatusTitle', '🔴 Not Connected');
        setText('apiStatusSub', 'Link your Groww account to enable API sync');
        document.getElementById('apiStatusActions').innerHTML =
          '<button class="btn btn-primary btn-sm" onclick="showApiConnectForm()">Connect Groww</button>';
        document.getElementById('apiConnectForm').style.display = 'block';
        document.getElementById('apiSyncPanel').style.display = 'none';
      } else {
        const expired = d.token_expired;
        setText('apiStatusTitle', expired ? '🟡 Token Expired' : '🟢 Connected');
        setText('apiStatusSub', `${d.linked_email || ''} · Last sync: ${d.last_sync_at || 'Never'}`);
        document.getElementById('apiStatusActions').innerHTML = `
          <button class="btn btn-ghost btn-sm" onclick="disconnectGrowwApi()">Disconnect</button>
          ${expired ? '<button class="btn btn-primary btn-sm" onclick="showApiConnectForm()">Re-link</button>' : ''}
        `;
        document.getElementById('apiConnectForm').style.display = expired ? 'block' : 'none';
        document.getElementById('apiSyncPanel').style.display  = expired ? 'none'  : 'block';
        loadSyncLog();
      }
    }).catch(() => {
      setText('apiStatusTitle', '⚠️ Could not check status');
    });
  }

  window.showApiConnectForm = () => {
    document.getElementById('apiConnectForm').style.display = 'block';
  };

  function connectGrowwApi() {
    const token     = document.getElementById('apiAccessToken').value.trim();
    const email     = document.getElementById('apiEmail').value.trim();
    const scope     = document.getElementById('apiScope').value.trim();
    const expiresIn = document.getElementById('apiExpiresIn').value;

    if (!token && !email) { showToast('Access token or email required', 'error'); return; }

    setBtnLoading('btnApiConnect', true);
    API.post('groww_api_connect', { access_token: token, email, scope, expires_in: expiresIn })
      .then(res => {
        if (res.success) { showToast('Groww linked!', 'success'); loadApiStatus(); }
        else showToast(res.message || 'Error', 'error');
      }).finally(() => setBtnLoading('btnApiConnect', false));
  }

  window.disconnectGrowwApi = function () {
    if (!confirm('Disconnect Groww account? Existing synced data will be kept.')) return;
    API.post('groww_api_disconnect', {}).then(res => {
      if (res.success) { showToast('Disconnected', 'success'); loadApiStatus(); }
    });
  };

  function syncGroww(type) {
    const portfolioId = document.getElementById(type === 'mf' ? 'apiMfPortfolioId' : 'apiStockPortfolioId').value;
    if (!portfolioId) { showToast('Select a portfolio', 'error'); return; }

    const btnId = type === 'mf' ? 'btnSyncMf' : 'btnSyncStocks';
    setBtnLoading(btnId, true);

    const action = type === 'mf' ? 'groww_api_sync_mf' : 'groww_api_sync_stocks';
    API.post(action, { portfolio_id: portfolioId }).then(res => {
      if (res.success) {
        showToast(res.message || 'Sync complete', 'success');
        loadSyncLog();
        if (res.data?.unmapped_count > 0) {
          showToast(`${res.data.unmapped_count} funds need mapping — check Fund Mapping tab`, 'info');
        }
      } else {
        showToast(res.message || 'Sync failed', 'error');
      }
    }).catch(() => showToast('Network error', 'error'))
      .finally(() => setBtnLoading(btnId, false));
  }

  function loadSyncLog() {
    API.get('groww_api_sync_log', {}).then(res => {
      const list = res.data?.log || [];
      const tbody = document.getElementById('apiSyncLogBody');
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="color:var(--text-muted);">No syncs yet</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(s => `<tr>
        <td style="font-size:12px;">${s.started_at}</td>
        <td>${esc(s.sync_type)}</td>
        <td class="text-right">${s.mf_synced || '—'}</td>
        <td class="text-right">${s.stock_synced || '—'}</td>
        <td class="text-right" style="color:${s.errors > 0 ? 'var(--danger)' : 'inherit'};">${s.errors || 0}</td>
        <td><span style="font-size:11px;padding:2px 8px;border-radius:10px;background:${s.status === 'done' ? 'rgba(16,185,129,.1)' : s.status === 'failed' ? 'rgba(239,68,68,.1)' : 'var(--bg-secondary)'};color:${s.status === 'done' ? 'var(--success)' : s.status === 'failed' ? 'var(--danger)' : 'var(--text-muted)'};">${s.status}</span></td>
        <td>
          ${s.status === 'done' ? `<button class="btn btn-ghost btn-xs" onclick="pushSyncToPortfolio(${s.id}, '${s.sync_type}')">Push</button>` : ''}
        </td>
      </tr>`).join('');
    });
  }

  window.pushSyncToPortfolio = function (syncId, syncType) {
    const assetType  = syncType.includes('mf') ? 'mf' : 'stock';
    const portfolioId = document.getElementById(assetType === 'mf' ? 'apiMfPortfolioId' : 'apiStockPortfolioId').value;
    if (!portfolioId) { showToast('Select portfolio first', 'error'); return; }

    API.post('groww_api_push_to_portfolio', { sync_id: syncId, portfolio_id: portfolioId, asset_type: assetType })
      .then(res => {
        if (res.success) showToast(res.message, 'success');
        else showToast(res.message || 'Error', 'error');
      });
  };

  // ── Fund Mapping ─────────────────────────────────────────────
  function loadMappings(showAlert = false) {
    API.get('groww_fund_map_list', {}).then(res => {
      const list  = res.data?.unmapped || [];
      const tbody = document.getElementById('mappingBody');

      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center" style="padding:30px;color:var(--text-muted);">All funds are mapped ✅</td></tr>';
        return;
      }

      if (showAlert) {
        const alert = document.getElementById('unmappedFundsAlert');
        alert.style.display = 'block';
        document.getElementById('unmappedFundsList').innerHTML = list.slice(0,5).map(u => `• ${esc(u.groww_name)}`).join('<br>');
      }

      tbody.innerHTML = list.map(u => `<tr>
        <td style="font-size:13px;">${esc(u.groww_name)}</td>
        <td style="font-size:12px;color:var(--text-muted);">Search below →</td>
        <td>
          <input type="text" class="form-control" style="font-size:12px;padding:4px 8px;"
                 placeholder="Search fund name…"
                 id="mapSearch_${u.id}"
                 oninput="debounceMapSearch(${u.id}, this.value)">
          <div id="mapDropdown_${u.id}" style="display:none;background:var(--bg-card);border:1px solid var(--border-color);border-radius:6px;position:absolute;z-index:100;max-height:200px;overflow-y:auto;min-width:280px;box-shadow:0 4px 16px rgba(0,0,0,.1);"></div>
        </td>
        <td>
          <button class="btn btn-ghost btn-xs" id="mapSaveBtn_${u.id}" style="display:none;" onclick="saveMapping(${u.id})">Save</button>
        </td>
      </tr>`).join('');
    });
  }

  const mapSearchTimers = {};
  window.debounceMapSearch = function (rowId, q) {
    clearTimeout(mapSearchTimers[rowId]);
    mapSearchTimers[rowId] = setTimeout(() => searchFundForMap(rowId, q), 350);
  };

  function searchFundForMap(rowId, q) {
    if (q.length < 2) { document.getElementById('mapDropdown_' + rowId).style.display = 'none'; return; }
    API.get('mf_search', { q, limit: 10 }).then(res => {
      const funds = res.data?.funds || res.data || [];
      const dd = document.getElementById('mapDropdown_' + rowId);
      if (!funds.length) { dd.style.display = 'none'; return; }
      dd.innerHTML = funds.map(f => `
        <div style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border-color);"
             onmousedown="selectMapFund(${rowId}, ${f.id}, '${esc(f.fund_name)}')">
          ${esc(f.fund_name)}
        </div>`).join('');
      dd.style.display = 'block';
    });
  }

  const mapSelectedFunds = {};
  window.selectMapFund = function (rowId, fundId, fundName) {
    mapSelectedFunds[rowId] = fundId;
    document.getElementById('mapSearch_' + rowId).value = fundName;
    document.getElementById('mapDropdown_' + rowId).style.display = 'none';
    document.getElementById('mapSaveBtn_' + rowId).style.display = 'inline-block';
  };

  window.saveMapping = function (rowId) {
    const fundId = mapSelectedFunds[rowId];
    const growwName = document.querySelector(`#mapSearch_${rowId}`)?.closest('tr')?.querySelector('td:first-child')?.textContent?.trim();
    if (!fundId || !growwName) return;
    API.post('groww_fund_map_save', { groww_name: growwName, fund_id: fundId }).then(res => {
      if (res.success) { showToast('Mapping saved', 'success'); loadMappings(); }
      else showToast(res.message || 'Error', 'error');
    });
  };

  // ── Import History ───────────────────────────────────────────
  function toggleHistory() {
    const panel = document.getElementById('importHistoryPanel');
    const visible = panel.style.display !== 'none';
    panel.style.display = visible ? 'none' : 'block';
    if (!visible) loadHistory();
  }

  function loadHistory() {
    API.get('groww_sessions', {}).then(res => {
      const list  = res.data?.sessions || [];
      const tbody = document.getElementById('historyBody');
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="color:var(--text-muted);">No imports yet</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(s => `<tr>
        <td style="font-size:12px;">${s.created_at}</td>
        <td style="font-size:12px;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(s.filename || '—')}</td>
        <td>${esc(s.import_type)}</td>
        <td class="text-right">${s.imported || 0}</td>
        <td class="text-right">${s.skipped || 0}</td>
        <td class="text-right" style="color:${s.errors > 0 ? 'var(--danger)' : 'inherit'};">${s.errors || 0}</td>
        <td><span style="font-size:11px;padding:2px 8px;border-radius:10px;background:${s.status === 'done' ? 'rgba(16,185,129,.1)' : 'var(--bg-secondary)'};color:${s.status === 'done' ? 'var(--success)' : 'var(--text-muted)'};">${s.status}</span></td>
      </tr>`).join('');
    });
  }

  // ── Utils ─────────────────────────────────────────────────────
  function setText(id, val)     { const el = document.getElementById(id); if (el) el.textContent = val; }
  function setBtnLoading(id, l) { const b  = document.getElementById(id); if (b) b.disabled = l; }
  function esc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function getCsrf() { return document.querySelector('[name="csrf_token"]')?.value || document.querySelector('#txnCsrf')?.value || ''; }

  function showResult(id, ok, msg) {
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = `<div style="padding:12px;border-radius:8px;background:${ok ? 'rgba(16,185,129,.08)' : 'rgba(239,68,68,.08)'};color:${ok ? 'var(--success)' : 'var(--danger)'};">${ok ? '✅' : '❌'} ${esc(msg)}</div>`;
    el.style.display = 'block';
  }

  function animateProgress(id, targetPct, durationMs) {
    const el = document.getElementById(id);
    if (!el) return;
    let pct = 0;
    const step = targetPct / (durationMs / 50);
    const timer = setInterval(() => {
      pct = Math.min(pct + step, targetPct);
      el.style.width = pct + '%';
      if (pct >= targetPct) clearInterval(timer);
    }, 50);
  }

  function showToast(msg, type = 'info') {
    if (window.showToast) window.showToast(msg, type);
    else console.log(`[${type}] ${msg}`);
  }

})();
