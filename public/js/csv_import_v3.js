/**
 * WealthDash — CSV Importer v3 JS (t490)
 * Path: public/js/csv_import_v3.js
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

  let currentFile    = null;
  let currentDetect  = null;
  let currentColMap  = null;
  let presetsCache   = [];

  // ── Init ─────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    loadFormats();
    loadPortfolios();
    loadPresets();

    document.getElementById('btnV3History')?.addEventListener('click', toggleHistory);
    document.getElementById('btnV3Presets')?.addEventListener('click', togglePresets);
    document.getElementById('btnV3Import')?.addEventListener('click', () => runImport(false));
    document.getElementById('btnV3Preview')?.addEventListener('click', () => runImport(true));
    document.getElementById('btnSavePreset')?.addEventListener('click', () => {
      document.getElementById('modalSavePreset').style.display = 'flex';
    });
    document.getElementById('btnConfirmSavePreset')?.addEventListener('click', savePreset);
    document.getElementById('btnV3SaveSession')?.addEventListener('click', saveSession);
  });

  // ── Supported Formats bar ────────────────────────────────────
  function loadFormats() {
    API.get('csv_v3_formats', {}).then(res => {
      const formats = res.data?.formats || [];
      const bar = document.getElementById('formatsBar');
      if (!bar) return;
      bar.innerHTML = formats.map(f =>
        `<span style="padding:4px 10px;background:var(--bg-secondary);border-radius:20px;font-size:12px;color:var(--text-muted);">
          ${f.broker ? '🏦' : '📄'} ${esc(f.label)}
        </span>`
      ).join('');
    }).catch(() => {});
  }

  // ── Portfolios ───────────────────────────────────────────────
  function loadPortfolios() {
    API.get('portfolio_list', {}).then(res => {
      const portfolios = res.data?.portfolios || [];
      const sel = document.getElementById('v3PortfolioId');
      if (!sel) return;
      sel.innerHTML = '<option value="">— Select Portfolio —</option>' +
        portfolios.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
    }).catch(() => {});
  }

  // ── File Handling ────────────────────────────────────────────
  window.v3HandleDrop = function (e) {
    e.preventDefault();
    document.getElementById('v3DropZone').style.borderColor = 'var(--border-color)';
    const file = e.dataTransfer.files[0];
    if (file) v3HandleFile(file);
  };

  window.v3HandleFile = function (file) {
    if (!file || !file.name.endsWith('.csv')) {
      showToast('Only .csv files supported for v3 auto-detect', 'error'); return;
    }
    currentFile = file;
    document.getElementById('v3DropIcon').textContent = '✅';
    document.getElementById('v3DropLabel').textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
    document.getElementById('btnV3Refresh').style.display = 'inline-block';
    v3Detect();
  };

  // ── Detect ───────────────────────────────────────────────────
  window.v3Detect = function () {
    if (!currentFile) return;
    document.getElementById('v3DetectBox').style.display = 'none';
    document.getElementById('v3PreviewPlaceholder').textContent = 'Detecting format…';

    API.post('mf_detect_csv', { test: '1' }, currentFile).then(res => {
      if (!res.success) {
        document.getElementById('v3PreviewPlaceholder').textContent = res.message || 'Detection failed. Check file format.';
        return;
      }

      currentDetect = res.data;
      renderDetect(res.data);
      renderPreview(res.data);
      renderColMap(res.data);
    }).catch(() => {
      document.getElementById('v3PreviewPlaceholder').textContent = 'Detection error — check network';
    });
  };

  function renderDetect(d) {
    setText('v3FormatName', d.format_label || d.format || 'Unknown');
    setText('v3RowCount',   d.total_rows   || 0);
    setText('v3HeaderRow',  (d.header_row_index ?? 0) + 1);

    const pill = document.getElementById('v3ConfidencePill');
    const conf = d.confidence || 0;
    pill.textContent = conf + '% confident';
    pill.style.background = conf >= 80 ? 'var(--success)' : conf >= 50 ? 'var(--warning,#f59e0b)' : 'var(--danger)';

    const warn = document.getElementById('v3DetectWarning');
    if (conf < 60) {
      warn.style.display = 'block';
      warn.textContent   = '⚠️ Low confidence — please verify column mapping below';
    } else {
      warn.style.display = 'none';
    }

    document.getElementById('v3DetectBox').style.display = 'block';
  }

  function renderPreview(d) {
    document.getElementById('v3PreviewPlaceholder').style.display = 'none';
    document.getElementById('v3PreviewWrap').style.display = 'block';

    const preview  = d.preview || [];
    const headers  = d.headers || [];
    const headerEl = document.getElementById('v3PreviewHeader');
    const bodyEl   = document.getElementById('v3PreviewBody');

    headerEl.innerHTML = headers.map(h => `<th style="font-size:11px;">${esc(h)}</th>`).join('') + '<th>OK?</th>';
    bodyEl.innerHTML   = preview.map(row => {
      const vals = Array.isArray(row.data) ? row.data : Object.values(row.data || {});
      const ok   = row.valid !== false;
      return `<tr style="${ok ? '' : 'background:rgba(239,68,68,.04);'}">
        ${vals.slice(0, headers.length).map(v => `<td style="font-size:11px;max-width:100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(String(v ?? ''))}</td>`).join('')}
        <td style="font-size:11px;">${ok ? '✅' : '⚠️'}</td>
      </tr>`;
    }).join('');
  }

  function renderColMap(d) {
    currentColMap = d.col_map || d.column_mapping || {};
    const grid    = document.getElementById('v3ColMapGrid');
    const fields  = ['fund_name','txn_date','transaction_type','units','nav','amount','folio_number','scheme_code'];
    const headers = d.headers || [];

    grid.innerHTML = fields.map(field => {
      const currentIdx = currentColMap[field] ?? '';
      const label = field.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
      return `<div style="font-size:12px;">
        <label style="color:var(--text-muted);font-size:11px;">${esc(label)}</label>
        <select class="form-control" style="padding:4px 6px;font-size:11px;" data-field="${field}" onchange="updateColMap('${field}', this.value)">
          <option value="">— not mapped —</option>
          ${headers.map((h, i) => `<option value="${i}" ${currentIdx == i ? 'selected' : ''}>${esc(h)}</option>`).join('')}
        </select>
      </div>`;
    }).join('');

    document.getElementById('v3ColMapPanel').style.display = 'block';
  }

  window.updateColMap = function (field, idx) {
    if (currentColMap) currentColMap[field] = idx !== '' ? parseInt(idx) : null;
  };

  // ── Import / Preview ─────────────────────────────────────────
  function runImport(previewOnly) {
    if (!currentFile) { showToast('Upload a CSV first', 'error'); return; }
    const portfolioId = document.getElementById('v3PortfolioId').value;
    if (!portfolioId) { showToast('Select a portfolio', 'error'); return; }

    setBtnLoading('btnV3Import', true);
    setBtnLoading('btnV3Preview', true);
    document.getElementById('v3ImportResult').style.display = 'none';
    document.getElementById('v3ImportProgress').style.display = 'block';
    setText('v3ProgressLabel', previewOnly ? 'Previewing…' : 'Importing…');
    animateProgress('v3ProgressBar', 85, 4000);

    const action = previewOnly ? 'mf_preview_csv' : 'mf_import_csv_v3';
    const payload = {
      portfolio_id:  portfolioId,
      col_map:       JSON.stringify(currentColMap || {}),
      detected_format: currentDetect?.format || 'generic',
      preview_only:  previewOnly ? '1' : '0',
      csrf_token:    document.getElementById('v3Csrf')?.value || '',
    };

    API.post(action, payload, currentFile).then(res => {
      document.getElementById('v3ImportProgress').style.display = 'none';
      const d = res.data || {};

      if (res.success) {
        const color = previewOnly ? 'var(--primary)' : 'var(--success)';
        document.getElementById('v3ImportResult').innerHTML = `
          <div style="padding:12px;border-radius:10px;background:var(--bg-secondary);">
            <div style="font-weight:700;color:${color};margin-bottom:6px;">${previewOnly ? '👁️ Preview' : '✅ Imported'}</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;font-size:12px;text-align:center;">
              <div><strong>${d.imported || 0}</strong><br><span style="color:var(--text-muted);">${previewOnly ? 'Would Import' : 'Imported'}</span></div>
              <div><strong>${d.skipped  || 0}</strong><br><span style="color:var(--text-muted);">Skipped</span></div>
              <div><strong style="color:${(d.errors||0)>0?'var(--danger)':'inherit'};">${d.errors || 0}</strong><br><span style="color:var(--text-muted);">Errors</span></div>
            </div>
          </div>`;
        document.getElementById('v3ImportResult').style.display = 'block';

        // Show error rows
        const errEl = document.getElementById('v3ErrorSummary');
        if ((d.error_rows || []).length) {
          errEl.innerHTML = d.error_rows.slice(0, 20).map(r =>
            `<div>Row ${r.row}: ${(r.errors || []).join(' · ')}</div>`
          ).join('');
          errEl.style.display = 'block';
        } else {
          errEl.style.display = 'none';
        }

        if (!previewOnly) showToast(`${d.imported} transactions imported`, 'success');
      } else {
        document.getElementById('v3ImportResult').innerHTML =
          `<div style="padding:10px;background:rgba(239,68,68,.08);border-radius:8px;color:var(--danger);">❌ ${esc(res.message || 'Import failed')}</div>`;
        document.getElementById('v3ImportResult').style.display = 'block';
      }
    }).catch(() => {
      document.getElementById('v3ImportProgress').style.display = 'none';
      showToast('Network error', 'error');
    }).finally(() => {
      setBtnLoading('btnV3Import', false);
      setBtnLoading('btnV3Preview', false);
    });
  }

  // ── Save Session ─────────────────────────────────────────────
  function saveSession() {
    if (!currentDetect) return;
    const portfolioId = document.getElementById('v3PortfolioId').value;
    API.post('csv_v3_save_session', {
      portfolio_id:    portfolioId,
      filename:        currentFile?.name || '',
      file_size:       currentFile?.size || 0,
      detected_format: currentDetect.format || 'generic',
      format_label:    currentDetect.format_label || '',
      confidence:      currentDetect.confidence || 0,
      col_mapping_json: JSON.stringify(currentColMap || {}),
      header_row_index: currentDetect.header_row_index || 0,
      total_data_rows:  currentDetect.total_rows || 0,
      session_action:  'detect',
      status:          'detected',
    }).then(res => {
      if (res.success) showToast(`Session #${res.data?.session_id} saved`, 'success');
    });
  }

  // ── Presets ──────────────────────────────────────────────────
  function loadPresets() {
    API.get('csv_v3_preset_list', {}).then(res => {
      presetsCache = res.data?.presets || [];
      const sel = document.getElementById('v3PresetSelect');
      if (sel) {
        sel.innerHTML = '<option value="">Load preset…</option>' +
          presetsCache.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
      }
      renderPresetsList();
    }).catch(() => {});
  }

  function renderPresetsList() {
    const tbody = document.getElementById('v3PresetsList');
    if (!tbody) return;
    if (!presetsCache.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="color:var(--text-muted);">No presets saved yet</td></tr>';
      return;
    }
    tbody.innerHTML = presetsCache.map(p => `<tr>
      <td>${esc(p.name)}</td>
      <td style="color:var(--text-muted);">${esc(p.format_hint || '—')}</td>
      <td class="text-right">${p.use_count || 0}</td>
      <td style="font-size:12px;color:var(--text-muted);">${p.last_used || '—'}</td>
      <td>
        <button class="btn btn-ghost btn-xs" onclick="applyPreset(${p.id})">Apply</button>
        <button class="btn btn-ghost btn-xs" onclick="deletePreset(${p.id})">🗑️</button>
      </td>
    </tr>`).join('');
  }

  function savePreset() {
    const name   = document.getElementById('presetName').value.trim();
    const hint   = document.getElementById('presetFormatHint').value.trim();
    if (!name) { showToast('Name required', 'error'); return; }
    if (!currentColMap) { showToast('Detect a CSV first to get column mapping', 'error'); return; }

    API.post('csv_v3_preset_save', {
      name, format_hint: hint, mapping_json: JSON.stringify(currentColMap)
    }).then(res => {
      if (res.success) {
        document.getElementById('modalSavePreset').style.display = 'none';
        document.getElementById('presetName').value = '';
        showToast('Preset saved', 'success');
        loadPresets();
      } else showToast(res.message || 'Error', 'error');
    });
  }

  window.applyPreset = function (id) {
    if (!id) return;
    API.get('csv_v3_preset_apply', { id }).then(res => {
      if (res.success) {
        currentColMap = res.data?.preset?.mapping || {};
        if (currentDetect) renderColMap({ ...currentDetect, col_map: currentColMap });
        showToast('Preset applied', 'success');
      }
    });
  };

  window.deletePreset = function (id) {
    if (!confirm('Delete this preset?')) return;
    API.post('csv_v3_preset_delete', { id }).then(res => {
      if (res.success) { showToast('Deleted', 'success'); loadPresets(); }
    });
  };

  // ── History ──────────────────────────────────────────────────
  function toggleHistory() {
    const panel = document.getElementById('v3HistoryPanel');
    const visible = panel.style.display !== 'none';
    panel.style.display = visible ? 'none' : 'block';
    if (!visible) loadHistory();
  }

  function loadHistory() {
    API.get('csv_v3_sessions', { limit: 20 }).then(res => {
      const list  = res.data?.sessions || [];
      const tbody = document.getElementById('v3HistoryBody');
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center" style="color:var(--text-muted);">No sessions yet</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(s => `<tr>
        <td style="font-size:12px;">${s.created_at}</td>
        <td style="font-size:12px;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${esc(s.filename)}">${esc(s.filename || '—')}</td>
        <td style="font-size:12px;"><span style="padding:2px 6px;background:var(--bg-secondary);border-radius:4px;">${esc(s.detected_format || '—')}</span></td>
        <td class="text-right">${s.total_data_rows || 0}</td>
        <td class="text-right">${s.imported || 0}</td>
        <td class="text-right" style="color:${s.errors > 0 ? 'var(--danger)' : 'inherit'};">${s.errors || 0}</td>
        <td><span style="font-size:11px;padding:2px 8px;border-radius:10px;background:${s.status==='imported'?'rgba(16,185,129,.1)':s.status==='failed'?'rgba(239,68,68,.1)':'var(--bg-secondary)'};color:${s.status==='imported'?'var(--success)':s.status==='failed'?'var(--danger)':'var(--text-muted)'};">${s.status}</span></td>
        <td>${s.errors > 0 ? `<button class="btn btn-ghost btn-xs" onclick="retrySession(${s.id})">Retry</button>` : ''}</td>
      </tr>`).join('');

      // Stats footer
      const stats = document.getElementById('v3HistoryStats');
      if (stats) {
        stats.textContent = `Total: ${res.data.total_files || 0} sessions · ${res.data.total_imported || 0} transactions imported`;
      }
    });
  }

  window.retrySession = function (id) {
    API.post('csv_v3_retry', { session_id: id }).then(res => {
      if (res.success) {
        showToast(`${res.data?.count || 0} error rows — upload file and fix them`, 'info');
      }
    });
  };

  function togglePresets() {
    const panel = document.getElementById('v3PresetsPanel');
    panel.style.display = panel.style.display !== 'none' ? 'none' : 'block';
  }

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
