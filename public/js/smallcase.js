/**
 * WealthDash — Smallcase JS
 * Task: t120
 * Path: public/js/smallcase.js
 */

'use strict';

(function () {
  const API = window.API || { post: (a, d) => fetch('/api/router.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action:a,...d}) }).then(r=>r.json()) };

  let scCache = [];
  let activeScId = null;

  // ── Init ────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    loadSmallcases();

    document.getElementById('btnAddSmallcase')?.addEventListener('click', () => SC.openScModal());
    document.getElementById('btnSaveSc')?.addEventListener('click', saveSc);
    document.getElementById('btnSaveStock')?.addEventListener('click', saveStock);
    document.getElementById('btnSaveScTxn')?.addEventListener('click', saveScTxn);
    document.getElementById('btnScCalcXirr')?.addEventListener('click', calcAllXirr);
    document.getElementById('btnAddStockToSc')?.addEventListener('click', () => SC.openStockModal());
    document.getElementById('btnScAddTxn')?.addEventListener('click', () => showModal('modalScTxn'));
    document.getElementById('btnScBulkImport')?.addEventListener('click', () => showModal('modalBulkImport'));
    document.getElementById('btnRunBulkImport')?.addEventListener('click', runBulkImport);

    // Tab switching
    document.querySelectorAll('.sc-tab-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.sc-tab-btn').forEach(b => {
          b.style.borderBottomColor = 'transparent';
          b.style.color = 'var(--text-muted)';
          b.style.fontWeight = '400';
          b.classList.remove('active');
        });
        this.style.borderBottomColor = 'var(--primary)';
        this.style.color = 'var(--primary)';
        this.style.fontWeight = '600';
        this.classList.add('active');
        const tab = this.dataset.tab;
        document.querySelectorAll('.sc-tab-content').forEach(c => c.style.display = 'none');
        document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1)).style.display = 'block';
        if (activeScId) {
          if (tab === 'transactions') loadScTxns();
          if (tab === 'rebalances')   loadScRebalances();
        }
      });
    });

    // Bulk import preview
    document.getElementById('bulkImportData')?.addEventListener('input', previewBulkImport);
  });

  // ── Load Smallcases ──────────────────────────────────────────
  function loadSmallcases() {
    API.post('smallcase_list', {}).then(res => {
      scCache = res.data?.smallcases || [];
      renderScCards(scCache);
      updateSummary(res.data);
    }).catch(() => {
      document.getElementById('scCardsGrid').innerHTML = '<p style="color:var(--danger);text-align:center;">Load failed</p>';
    });
  }

  function renderScCards(list) {
    const grid = document.getElementById('scCardsGrid');
    if (!list?.length) {
      grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--text-muted);">
        <div style="font-size:48px;margin-bottom:12px;">📊</div>
        <p>No smallcases yet — add your first basket above</p>
      </div>`;
      return;
    }

    grid.innerHTML = list.map(sc => {
      const invested = parseFloat(sc.invested_amount || 0);
      const value    = parseFloat(sc.calc_value || sc.current_value || invested);
      const gl       = value - invested;
      const glPct    = invested > 0 ? (gl / invested * 100).toFixed(2) : '0.00';
      const glColor  = gl >= 0 ? 'var(--success)' : 'var(--danger)';
      const isActive = sc.is_active == 1;

      return `<div class="card" style="cursor:pointer;border:2px solid ${activeScId == sc.id ? 'var(--primary)' : 'var(--border-color)'};transition:border-color .2s;"
                   onclick="SC.openDetail(${sc.id})">
        <div class="card-body" style="padding:16px;">
          <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:10px;">
            <div>
              <h4 style="margin:0 0 2px;font-size:15px;">${esc(sc.name)}</h4>
              <div style="font-size:12px;color:var(--text-muted);">
                ${sc.manager ? esc(sc.manager) : ''}
                ${sc.strategy_type ? ' · ' + esc(sc.strategy_type) : ''}
              </div>
            </div>
            <div style="display:flex;gap:4px;">
              ${!isActive ? '<span style="font-size:10px;padding:2px 6px;background:var(--bg-secondary);border-radius:4px;color:var(--text-muted);">Inactive</span>' : ''}
              <button class="btn btn-ghost btn-xs" onclick="event.stopPropagation();SC.editSc(${sc.id})" title="Edit">✏️</button>
              <button class="btn btn-ghost btn-xs" onclick="event.stopPropagation();SC.deleteSc(${sc.id},'${esc(sc.name)}')" title="Delete">🗑️</button>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
            <div>
              <div style="color:var(--text-muted);font-size:11px;">Invested</div>
              <div style="font-weight:600;">₹${fmt(invested, 0)}</div>
            </div>
            <div>
              <div style="color:var(--text-muted);font-size:11px;">Current Value</div>
              <div style="font-weight:600;">${value > 0 ? '₹' + fmt(value, 0) : '—'}</div>
            </div>
            <div>
              <div style="color:var(--text-muted);font-size:11px;">Gain / Loss</div>
              <div style="font-weight:600;color:${glColor};">${value > 0 ? (gl >= 0 ? '+' : '') + '₹' + fmt(Math.abs(gl), 0) + ' (' + glPct + '%)' : '—'}</div>
            </div>
            <div>
              <div style="color:var(--text-muted);font-size:11px;">Stocks</div>
              <div style="font-weight:600;">${sc.stock_count || 0}</div>
            </div>
          </div>

          ${sc.xirr ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">XIRR: <strong style="color:${parseFloat(sc.xirr) >= 0 ? 'var(--success)':'var(--danger)'};">${parseFloat(sc.xirr).toFixed(2)}%</strong></div>` : ''}
          ${sc.last_rebalanced ? `<div style="margin-top:4px;font-size:11px;color:var(--text-muted);">Last rebalanced: ${sc.last_rebalanced}</div>` : ''}
        </div>
      </div>`;
    }).join('');
  }

  function updateSummary(data) {
    if (!data) return;
    const count    = (data.smallcases || []).length;
    const invested = parseFloat(data.total_invested || 0);
    const value    = parseFloat(data.total_value || 0) || invested;
    const gl       = value - invested;

    setText('statScCount',    count);
    setText('statScInvested', '₹' + fmt(invested, 0));
    setText('statScValue',    '₹' + fmt(value, 0));

    const glEl = document.getElementById('statScGain');
    if (glEl) {
      glEl.textContent = (gl >= 0 ? '+' : '') + '₹' + fmt(Math.abs(gl), 0);
      glEl.style.color = gl >= 0 ? 'var(--success)' : 'var(--danger)';
    }
  }

  // ── Detail Panel ─────────────────────────────────────────────
  window.SC = {
    openDetail(scId) {
      activeScId = scId;
      const sc = scCache.find(x => x.id == scId);
      document.getElementById('scDetailTitle').textContent = sc ? sc.name : 'Holdings';
      document.getElementById('scDetailPanel').style.display = 'block';
      document.getElementById('scDetailPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });

      // Activate holdings tab
      document.querySelectorAll('.sc-tab-btn').forEach((b, i) => {
        const isFirst = i === 0;
        b.style.borderBottomColor = isFirst ? 'var(--primary)' : 'transparent';
        b.style.color = isFirst ? 'var(--primary)' : 'var(--text-muted)';
        b.style.fontWeight = isFirst ? '600' : '400';
        b.classList.toggle('active', isFirst);
      });
      document.querySelectorAll('.sc-tab-content').forEach((c, i) => c.style.display = i === 0 ? 'block' : 'none');

      loadScHoldings();
      renderScCards(scCache); // re-render to highlight selected
    },

    openScModal(sc = null) {
      document.getElementById('scEditId').value    = sc?.id || '';
      document.getElementById('scName').value      = sc?.name || '';
      document.getElementById('scStrategy').value  = sc?.strategy_type || '';
      document.getElementById('scManager').value   = sc?.manager || '';
      document.getElementById('scInvested').value  = sc?.invested_amount || '';
      document.getElementById('scSubFee').value    = sc?.subscription_fee || '0';
      document.getElementById('scFeeFreq').value   = sc?.fee_frequency || '';
      document.getElementById('scDesc').value      = sc?.description || '';
      document.getElementById('scNotes').value     = sc?.notes || '';
      document.getElementById('modalScTitle').textContent = sc ? 'Edit Smallcase' : 'Add Smallcase';
      showModal('modalAddSc');
    },

    editSc(id)  { const sc = scCache.find(x => x.id == id); if (sc) SC.openScModal(sc); },
    deleteSc(id, name) {
      if (!confirm(`Delete "${name}"? All holdings & transactions will be removed.`)) return;
      API.post('smallcase_delete', { id }).then(res => {
        if (res.success) { if (activeScId == id) { activeScId = null; document.getElementById('scDetailPanel').style.display = 'none'; } loadSmallcases(); showToast('Deleted', 'success'); }
        else showToast(res.message || 'Error', 'error');
      });
    },

    openStockModal(holding = null) {
      document.getElementById('stockHoldingEditId').value = holding?.id || '';
      document.getElementById('stockSymbol').value        = holding?.symbol || '';
      document.getElementById('stockCompany').value       = holding?.company_name || '';
      document.getElementById('stockQty').value           = holding?.quantity || '';
      document.getElementById('stockAvgPrice').value      = holding?.avg_buy_price || '';
      document.getElementById('stockCurPrice').value      = holding?.current_price || '';
      document.getElementById('stockWeight').value        = holding?.weight_pct || '';
      document.getElementById('stockTargetWeight').value  = holding?.target_weight_pct || '';
      document.getElementById('stockSector').value        = holding?.sector || '';
      document.getElementById('modalStockTitle').textContent = holding ? 'Edit Stock' : 'Add Stock';
      showModal('modalAddStock');
    },

    closeModal(id) { const m = document.getElementById(id); if (m) m.style.display = 'none'; },
  };

  function loadScHoldings() {
    if (!activeScId) return;
    API.post('smallcase_holding_list', { smallcase_id: activeScId }).then(res => {
      const list = res.data?.holdings || [];
      const tbody = document.getElementById('scHoldingsBody');
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center" style="padding:20px;color:var(--text-muted);">No stocks yet — use "Add Stock" or "Bulk Import"</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(h => {
        const cur = parseFloat(h.current_value || 0);
        const inv = parseFloat(h.invested_amount || 0);
        const gl  = cur > 0 ? cur - inv : null;
        return `<tr>
          <td><strong>${esc(h.symbol)}</strong></td>
          <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(h.company_name)}</td>
          <td class="text-right">${fmt(h.quantity, 4)}</td>
          <td class="text-right">₹${fmt(h.avg_buy_price, 2)}</td>
          <td class="text-right">₹${fmt(inv, 0)}</td>
          <td class="text-right">${h.current_price ? '₹' + fmt(h.current_price, 2) : '—'}</td>
          <td class="text-right">${cur > 0 ? '₹' + fmt(cur, 0) : '—'}</td>
          <td class="text-right">${h.weight_pct ? h.weight_pct + '%' : '—'}</td>
          <td>
            <div style="display:flex;gap:4px;">
              <button class="btn btn-ghost btn-xs" onclick="SC.openStockModal(${JSON.stringify(h).replace(/"/g,'&quot;')})">✏️</button>
              <button class="btn btn-ghost btn-xs" onclick="deleteScHolding(${h.id})">🗑️</button>
            </div>
          </td>
        </tr>`;
      }).join('');
    });
  }

  function loadScTxns() {
    if (!activeScId) return;
    API.post('smallcase_txn_list', { smallcase_id: activeScId }).then(res => {
      const list = res.data?.transactions || [];
      const tbody = document.getElementById('scTxnBody');
      if (!list.length) { tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="color:var(--text-muted);">No transactions</td></tr>'; return; }
      tbody.innerHTML = list.map(t => `<tr>
        <td>${t.txn_date}</td>
        <td><span class="badge badge-${t.txn_type==='invest'?'success':t.txn_type==='redeem'?'danger':'info'}">${t.txn_type}</span></td>
        <td class="text-right">₹${fmt(t.amount, 2)}</td>
        <td style="color:var(--text-muted);">${esc(t.notes || '')}</td>
        <td><button class="btn btn-ghost btn-xs" onclick="deleteScTxn(${t.id})">🗑️</button></td>
      </tr>`).join('');
    });
  }

  function loadScRebalances() {
    if (!activeScId) return;
    API.post('smallcase_rebalance_list', { smallcase_id: activeScId }).then(res => {
      const list = res.data?.history || [];
      const tbody = document.getElementById('scRebBody');
      if (!list.length) { tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="color:var(--text-muted);">No rebalances recorded</td></tr>'; return; }
      tbody.innerHTML = list.map(r => `<tr>
        <td>${r.rebalance_date}</td>
        <td>${esc(r.reason || '—')}</td>
        <td style="font-size:12px;">${r.stocks_added ? JSON.parse(r.stocks_added).join(', ') : '—'}</td>
        <td style="font-size:12px;">${r.stocks_removed ? JSON.parse(r.stocks_removed).join(', ') : '—'}</td>
        <td>${r.portfolio_value ? '₹' + fmt(r.portfolio_value, 0) : '—'}</td>
      </tr>`).join('');
    });
  }

  // ── Save functions ───────────────────────────────────────────
  function saveSc() {
    const editId  = document.getElementById('scEditId').value;
    const payload = {
      name:              document.getElementById('scName').value.trim(),
      strategy_type:     document.getElementById('scStrategy').value.trim(),
      manager:           document.getElementById('scManager').value.trim(),
      invested_amount:   parseFloat(document.getElementById('scInvested').value || 0),
      subscription_fee:  parseFloat(document.getElementById('scSubFee').value || 0),
      fee_frequency:     document.getElementById('scFeeFreq').value,
      description:       document.getElementById('scDesc').value.trim(),
      notes:             document.getElementById('scNotes').value.trim(),
    };
    if (!payload.name) { alert('Name required'); return; }
    if (editId) payload.id = editId;
    const action = editId ? 'smallcase_edit' : 'smallcase_add';
    setBtnLoading('btnSaveSc', true);
    API.post(action, payload).then(res => {
      if (res.success) { SC.closeModal('modalAddSc'); loadSmallcases(); showToast(res.message || 'Saved', 'success'); }
      else alert(res.message || 'Error');
    }).finally(() => setBtnLoading('btnSaveSc', false));
  }

  function saveStock() {
    if (!activeScId) { alert('Select a smallcase first'); return; }
    const editId = document.getElementById('stockHoldingEditId').value;
    const payload = {
      smallcase_id:      activeScId,
      symbol:            document.getElementById('stockSymbol').value.toUpperCase().trim(),
      company_name:      document.getElementById('stockCompany').value.trim(),
      quantity:          parseFloat(document.getElementById('stockQty').value || 0),
      avg_buy_price:     parseFloat(document.getElementById('stockAvgPrice').value || 0),
      current_price:     parseFloat(document.getElementById('stockCurPrice').value || 0),
      weight_pct:        parseFloat(document.getElementById('stockWeight').value || 0),
      target_weight_pct: parseFloat(document.getElementById('stockTargetWeight').value || 0),
      sector:            document.getElementById('stockSector').value.trim(),
    };
    if (editId) payload.id = editId;
    setBtnLoading('btnSaveStock', true);
    API.post('smallcase_holding_save', payload).then(res => {
      if (res.success) { SC.closeModal('modalAddStock'); loadScHoldings(); loadSmallcases(); showToast('Stock saved', 'success'); }
      else alert(res.message || 'Error');
    }).finally(() => setBtnLoading('btnSaveStock', false));
  }

  function saveScTxn() {
    if (!activeScId) { alert('Open a smallcase first'); return; }
    const payload = {
      smallcase_id: activeScId,
      txn_type:     document.getElementById('scTxnType').value,
      txn_date:     document.getElementById('scTxnDate').value,
      amount:       parseFloat(document.getElementById('scTxnAmount').value || 0),
      notes:        document.getElementById('scTxnNotes').value.trim(),
    };
    if (payload.amount <= 0) { alert('Amount required'); return; }
    setBtnLoading('btnSaveScTxn', true);
    API.post('smallcase_txn_add', payload).then(res => {
      if (res.success) { SC.closeModal('modalScTxn'); loadScTxns(); loadSmallcases(); showToast('Transaction added', 'success'); }
      else alert(res.message || 'Error');
    }).finally(() => setBtnLoading('btnSaveScTxn', false));
  }

  window.deleteScHolding = function (id) {
    if (!confirm('Remove this stock?')) return;
    API.post('smallcase_holding_delete', { id, smallcase_id: activeScId }).then(res => {
      if (res.success) { loadScHoldings(); loadSmallcases(); showToast('Removed', 'success'); }
    });
  };

  window.deleteScTxn = function (id) {
    if (!confirm('Delete transaction?')) return;
    API.post('smallcase_txn_delete', { id }).then(res => {
      if (res.success) { loadScTxns(); showToast('Deleted', 'success'); }
    });
  };

  // ── Bulk Import ──────────────────────────────────────────────
  function previewBulkImport() {
    const text = document.getElementById('bulkImportData').value.trim();
    const rows = parseBulkCsv(text);
    document.getElementById('bulkParsePreview').textContent = rows.length
      ? `${rows.length} stocks parsed and ready to import`
      : 'Paste CSV data above';
  }

  function parseBulkCsv(text) {
    return text.split('\n').map(l => l.trim()).filter(Boolean).map(line => {
      const parts = line.split(',').map(p => p.trim());
      if (parts.length < 4) return null;
      const sym = parts[0].toUpperCase();
      if (!sym || isNaN(parseFloat(parts[2]))) return null;
      return {
        symbol:        sym,
        company_name:  parts[1] || sym,
        quantity:      parseFloat(parts[2]),
        avg_buy_price: parseFloat(parts[3]),
        sector:        parts[4] || '',
      };
    }).filter(Boolean);
  }

  function runBulkImport() {
    if (!activeScId) { alert('Open a smallcase first'); return; }
    const text = document.getElementById('bulkImportData').value.trim();
    const rows = parseBulkCsv(text);
    if (!rows.length) { alert('No valid rows found'); return; }
    setBtnLoading('btnRunBulkImport', true);
    API.post('smallcase_holding_bulk_import', { smallcase_id: activeScId, rows: JSON.stringify(rows) }).then(res => {
      if (res.success) {
        SC.closeModal('modalBulkImport');
        document.getElementById('bulkImportData').value = '';
        loadScHoldings(); loadSmallcases();
        showToast(res.message || 'Imported', 'success');
      } else alert(res.message || 'Error');
    }).finally(() => setBtnLoading('btnRunBulkImport', false));
  }

  // ── XIRR ─────────────────────────────────────────────────────
  function calcAllXirr() {
    if (!scCache.length) return;
    let done = 0;
    scCache.forEach(sc => {
      API.post('smallcase_calc_xirr', { smallcase_id: sc.id }).then(res => {
        if (++done === scCache.length) { loadSmallcases(); showToast('XIRR calculated', 'success'); }
      });
    });
  }

  // ── Utils ─────────────────────────────────────────────────────
  function showModal(id)       { const m = document.getElementById(id); if (m) m.style.display = 'flex'; }
  function setText(id, val)    { const el = document.getElementById(id); if (el) el.textContent = val; }
  function setBtnLoading(id, l){ const btn = document.getElementById(id); if (btn) btn.disabled = l; }
  function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function fmt(n, dec=2) { return parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:dec,maximumFractionDigits:dec}); }
  function showToast(msg, type='info') { if (window.showToast) { window.showToast(msg, type); } else console.log(`[${type}] ${msg}`); }
})();
