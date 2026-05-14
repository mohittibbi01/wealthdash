/**
 * WealthDash — REITs & InvITs JS
 * Task: t115
 * Path: public/js/reits.js
 */

'use strict';

(function () {
  const API = window.API || { post: (a, d) => fetch('/api/router.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action:a,...d}) }).then(r=>r.json()) };

  let activeType = '';
  let holdingsCache = [];

  // ── Init ────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    loadHoldings();

    document.getElementById('btnAddHolding')?.addEventListener('click', () => openHoldingModal());
    document.getElementById('btnSaveHolding')?.addEventListener('click', saveHolding);
    document.getElementById('btnRefreshPrices')?.addEventListener('click', promptRefreshPrice);
    document.getElementById('btnViewDistributions')?.addEventListener('click', toggleDistPanel);
    document.getElementById('btnViewTransactions')?.addEventListener('click', () => loadTransactionsPanel());
    document.getElementById('btnAddDist')?.addEventListener('click', () => openDistModal());
    document.getElementById('btnSaveDist')?.addEventListener('click', saveDist);

    document.querySelectorAll('.tab-filter-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.tab-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        activeType = this.dataset.type;
        renderHoldings(holdingsCache.filter(h => !activeType || h.trust_type === activeType));
      });
    });

    // Symbol search
    const symInput = document.getElementById('holdingSymbolSearch');
    if (symInput) {
      symInput.addEventListener('input', debounce(() => searchMaster(symInput.value), 300));
    }

    // Price preview
    ['holdingUnits','holdingAvgPrice'].forEach(id => {
      document.getElementById(id)?.addEventListener('input', updateHoldingPreview);
    });

    // Dist preview
    ['distPerUnit','distUnitsHeld','distTds'].forEach(id => {
      document.getElementById(id)?.addEventListener('input', updateDistPreview);
    });
  });

  // ── Load Holdings ────────────────────────────────────────────
  function loadHoldings() {
    API.post('reits_list', { trust_type: activeType }).then(res => {
      if (!res.success) return;
      holdingsCache = res.data?.holdings || [];
      renderHoldings(holdingsCache);
      updateSummary(res.data);
      populateDistHoldingSelect(holdingsCache);
    }).catch(() => {
      document.getElementById('reitsBody').innerHTML = '<tr><td colspan="11" class="text-center text-danger">Load failed</td></tr>';
    });
  }

  function renderHoldings(list) {
    const tbody = document.getElementById('reitsBody');
    if (!list?.length) {
      tbody.innerHTML = '<tr><td colspan="11" class="text-center" style="padding:40px;color:var(--text-muted);">No holdings yet — add your first REIT/InvIT above</td></tr>';
      return;
    }

    tbody.innerHTML = list.map(h => {
      const curVal   = parseFloat(h.current_value || h.value_now || 0);
      const invested = parseFloat(h.total_invested || 0);
      const gl       = curVal - invested;
      const glPct    = invested > 0 ? (gl / invested * 100).toFixed(2) : '0.00';
      const glColor  = gl >= 0 ? 'var(--success)' : 'var(--danger)';
      const cmp      = parseFloat(h.current_price || 0);
      const dist     = parseFloat(h.total_distributions || 0);

      return `<tr>
        <td><strong>${esc(h.symbol)}</strong></td>
        <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(h.name)}</td>
        <td><span class="badge badge-${h.trust_type === 'REIT' ? 'info' : 'warning'}">${esc(h.trust_type)}</span></td>
        <td class="text-right">${fmt(h.units, 4)}</td>
        <td class="text-right">₹${fmt(h.avg_buy_price, 2)}</td>
        <td class="text-right">₹${fmt(invested, 0)}</td>
        <td class="text-right">${cmp > 0 ? '₹' + fmt(cmp, 2) : '<span style="color:var(--text-muted)">—</span>'}</td>
        <td class="text-right">${curVal > 0 ? '₹' + fmt(curVal, 0) : '<span style="color:var(--text-muted)">—</span>'}</td>
        <td class="text-right" style="color:${glColor};">${curVal > 0 ? (gl >= 0 ? '+' : '') + '₹' + fmt(Math.abs(gl), 0) + ' (' + glPct + '%)' : '—'}</td>
        <td class="text-right">${dist > 0 ? '₹' + fmt(dist, 0) : '—'}</td>
        <td>
          <div style="display:flex;gap:4px;">
            <button class="btn btn-ghost btn-xs" onclick="ReITs.editHolding(${h.id})" title="Edit">✏️</button>
            <button class="btn btn-ghost btn-xs" onclick="ReITs.updatePrice(${h.id})" title="Update Price">📈</button>
            <button class="btn btn-ghost btn-xs" onclick="ReITs.deleteHolding(${h.id}, '${esc(h.symbol)}')" title="Delete">🗑️</button>
          </div>
        </td>
      </tr>`;
    }).join('');
  }

  function updateSummary(data) {
    if (!data) return;
    const list   = data.holdings || [];
    const invest = parseFloat(data.total_invested || 0);
    const val    = parseFloat(data.total_value || 0) || invest;
    const gl     = val - invest;

    setText('statHoldingsCount', list.length);
    setText('statTotalInvested', '₹' + fmt(invest, 0));
    setText('statCurrentValue', '₹' + fmt(val, 0));

    const glEl = document.getElementById('statGainLoss');
    if (glEl) {
      glEl.textContent = (gl >= 0 ? '+' : '') + '₹' + fmt(Math.abs(gl), 0);
      glEl.style.color = gl >= 0 ? 'var(--success)' : 'var(--danger)';
    }
    setText('statDistributions', '₹' + fmt(parseFloat(data.total_distributions || 0), 0));
  }

  // ── Add / Edit Holding ───────────────────────────────────────
  function openHoldingModal(holding = null) {
    document.getElementById('holdingEditId').value   = holding?.id || '';
    document.getElementById('holdingSymbolSearch').value = holding?.symbol || '';
    document.getElementById('holdingSymbol').value   = holding?.symbol || '';
    document.getElementById('holdingName').value     = holding?.name || '';
    document.getElementById('holdingType').value     = holding?.trust_type || 'REIT';
    document.getElementById('holdingExchange').value = holding?.exchange || 'NSE';
    document.getElementById('holdingIsin').value     = holding?.isin || '';
    document.getElementById('holdingUnits').value    = holding?.units || '';
    document.getElementById('holdingAvgPrice').value = holding?.avg_buy_price || '';
    document.getElementById('holdingCurPrice').value = holding?.current_price || '';
    document.getElementById('holdingNotes').value    = holding?.notes || '';
    document.getElementById('holdingError').style.display = 'none';
    document.getElementById('modalHoldingTitle').textContent = holding ? 'Edit Holding' : 'Add REIT / InvIT';
    showModal('modalAddHolding');
    updateHoldingPreview();
  }

  function saveHolding() {
    const editId    = document.getElementById('holdingEditId').value;
    const symbol    = (document.getElementById('holdingSymbol').value || document.getElementById('holdingSymbolSearch').value || '').toUpperCase().trim();
    const name      = document.getElementById('holdingName').value.trim();
    const trustType = document.getElementById('holdingType').value;
    const exchange  = document.getElementById('holdingExchange').value;
    const isin      = document.getElementById('holdingIsin').value.trim();
    const units     = parseFloat(document.getElementById('holdingUnits').value || 0);
    const avgPrice  = parseFloat(document.getElementById('holdingAvgPrice').value || 0);
    const curPrice  = parseFloat(document.getElementById('holdingCurPrice').value || 0);
    const notes     = document.getElementById('holdingNotes').value.trim();

    if (!symbol || !name || units <= 0 || avgPrice <= 0) {
      showHoldingError('Symbol, name, units aur avg price required hain');
      return;
    }

    const action = editId ? 'reits_edit' : 'reits_add';
    const payload = { symbol, name, trust_type: trustType, exchange, isin, units, avg_buy_price: avgPrice, current_price: curPrice, notes };
    if (editId) payload.id = editId;

    setBtnLoading('btnSaveHolding', true);
    API.post(action, payload).then(res => {
      if (res.success) {
        closeReitsModal('modalAddHolding');
        loadHoldings();
        showToast(res.message || 'Saved!', 'success');
      } else {
        showHoldingError(res.message || 'Error');
      }
    }).finally(() => setBtnLoading('btnSaveHolding', false));
  }

  // ── Delete ───────────────────────────────────────────────────
  window.ReITs = {
    editHolding(id) {
      const h = holdingsCache.find(x => x.id == id);
      if (h) openHoldingModal(h);
    },
    deleteHolding(id, symbol) {
      if (!confirm(`Delete ${symbol}? All transactions & distributions will also be removed.`)) return;
      API.post('reits_delete', { id }).then(res => {
        if (res.success) { loadHoldings(); showToast('Deleted', 'success'); }
        else showToast(res.message || 'Error', 'error');
      });
    },
    updatePrice(id) {
      const price = prompt('Enter current market price (₹):');
      if (!price || isNaN(parseFloat(price))) return;
      API.post('reits_price_refresh', { id, price: parseFloat(price) }).then(res => {
        if (res.success) { loadHoldings(); showToast('Price updated', 'success'); }
        else showToast(res.message || 'Error', 'error');
      });
    },
  };

  function promptRefreshPrice() {
    if (!holdingsCache.length) return;
    showToast('Use the 📈 button on each row to update individual prices', 'info');
  }

  // ── Distribution Panel ───────────────────────────────────────
  function toggleDistPanel() {
    const panel = document.getElementById('distPanel');
    const visible = panel.style.display !== 'none';
    panel.style.display = visible ? 'none' : 'block';
    if (!visible) loadDistributions();
  }

  function loadDistributions(holdingId = null) {
    const params = holdingId ? { holding_id: holdingId } : {};
    API.post('reits_dist_list', params).then(res => {
      if (!res.success) return;
      const list = res.data?.distributions || [];
      const tbody = document.getElementById('distBody');
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center" style="color:var(--text-muted);">No distributions recorded yet</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(d => `
        <tr>
          <td>${d.ex_date}</td>
          <td>${d.pay_date || '—'}</td>
          <td><strong>${esc(d.symbol)}</strong></td>
          <td>${esc(d.dist_type)}</td>
          <td class="text-right">₹${fmt(d.per_unit_amount, 4)}</td>
          <td class="text-right">${fmt(d.units_held, 4)}</td>
          <td class="text-right">₹${fmt(d.total_amount, 2)}</td>
          <td class="text-right">${parseFloat(d.tds_deducted) > 0 ? '₹' + fmt(d.tds_deducted, 2) : '—'}</td>
          <td class="text-right" style="color:var(--success);">₹${fmt(d.net_amount, 2)}</td>
          <td><button class="btn btn-ghost btn-xs" onclick="deleteDist(${d.id})">🗑️</button></td>
        </tr>
      `).join('');
    });
  }

  function openDistModal() {
    document.getElementById('distPerUnit').value  = '';
    document.getElementById('distUnitsHeld').value = '';
    document.getElementById('distTds').value = '0';
    document.getElementById('distPreview').style.display = 'none';
    showModal('modalAddDist');
  }

  function saveDist() {
    const payload = {
      holding_id:     document.getElementById('distHoldingId').value,
      dist_type:      document.getElementById('distType').value,
      ex_date:        document.getElementById('distExDate').value,
      pay_date:       document.getElementById('distPayDate').value,
      per_unit_amount: parseFloat(document.getElementById('distPerUnit').value || 0),
      units_held:     parseFloat(document.getElementById('distUnitsHeld').value || 0),
      tds_deducted:   parseFloat(document.getElementById('distTds').value || 0),
    };
    if (!payload.holding_id || !payload.ex_date || payload.per_unit_amount <= 0 || payload.units_held <= 0) {
      alert('Required fields fill karo'); return;
    }
    setBtnLoading('btnSaveDist', true);
    API.post('reits_dist_add', payload).then(res => {
      if (res.success) {
        closeReitsModal('modalAddDist');
        loadDistributions();
        loadHoldings();
        showToast('Distribution added', 'success');
      } else alert(res.message || 'Error');
    }).finally(() => setBtnLoading('btnSaveDist', false));
  }

  window.deleteDist = function (id) {
    if (!confirm('Delete this distribution?')) return;
    API.post('reits_dist_delete', { id }).then(res => {
      if (res.success) { loadDistributions(); showToast('Deleted', 'success'); }
    });
  };

  // ── Master Symbol Search ─────────────────────────────────────
  function searchMaster(q) {
    if (!q || q.length < 1) { hideDropdown(); return; }
    API.post('reits_master_search', { q, trust_type: document.getElementById('holdingType')?.value || '' }).then(res => {
      const results = res.data?.results || [];
      const dd = document.getElementById('symbolDropdown');
      if (!results.length) { dd.style.display = 'none'; return; }
      dd.innerHTML = results.map(r => `
        <div style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);"
             onmousedown="selectSymbol('${esc(r.symbol)}','${esc(r.name)}','${esc(r.trust_type)}','${esc(r.isin || '')}')">
          <strong>${esc(r.symbol)}</strong>
          <span style="font-size:12px;color:var(--text-muted);margin-left:8px;">${esc(r.name)}</span>
          <span class="badge badge-${r.trust_type === 'REIT' ? 'info' : 'warning'}" style="margin-left:8px;">${esc(r.trust_type)}</span>
        </div>
      `).join('');
      dd.style.display = 'block';
    });
  }

  window.selectSymbol = function (symbol, name, type, isin) {
    document.getElementById('holdingSymbolSearch').value = symbol;
    document.getElementById('holdingSymbol').value       = symbol;
    document.getElementById('holdingName').value         = name;
    document.getElementById('holdingType').value         = type;
    document.getElementById('holdingIsin').value         = isin || '';
    hideDropdown();
  };

  function hideDropdown() {
    document.getElementById('symbolDropdown').style.display = 'none';
  }

  document.addEventListener('click', e => {
    if (!e.target.closest('#holdingSymbolSearch') && !e.target.closest('#symbolDropdown')) hideDropdown();
  });

  // ── Helpers ──────────────────────────────────────────────────
  function updateHoldingPreview() {
    const units = parseFloat(document.getElementById('holdingUnits')?.value || 0);
    const price = parseFloat(document.getElementById('holdingAvgPrice')?.value || 0);
    const preview = document.getElementById('holdingValuePreview');
    if (units > 0 && price > 0) {
      document.getElementById('previewInvested').textContent = '₹' + fmt(units * price, 2);
      preview.style.display = 'block';
    } else {
      preview.style.display = 'none';
    }
  }

  function updateDistPreview() {
    const perUnit = parseFloat(document.getElementById('distPerUnit')?.value || 0);
    const units   = parseFloat(document.getElementById('distUnitsHeld')?.value || 0);
    const tds     = parseFloat(document.getElementById('distTds')?.value || 0);
    const preview = document.getElementById('distPreview');
    if (perUnit > 0 && units > 0) {
      const gross = perUnit * units;
      document.getElementById('distGross').textContent = '₹' + fmt(gross, 2);
      document.getElementById('distNet').textContent   = '₹' + fmt(gross - tds, 2);
      preview.style.display = 'block';
    } else {
      preview.style.display = 'none';
    }
  }

  function populateDistHoldingSelect(holdings) {
    const sel = document.getElementById('distHoldingId');
    if (!sel) return;
    sel.innerHTML = '<option value="">— Select Holding —</option>' +
      holdings.map(h => `<option value="${h.id}">${esc(h.symbol)} — ${esc(h.name)}</option>`).join('');
  }

  function loadTransactionsPanel() {
    showToast('View all transactions in the Transactions tab', 'info');
  }

  function showHoldingError(msg) {
    const el = document.getElementById('holdingError');
    el.textContent = msg;
    el.style.display = 'block';
  }

  function showModal(id)  { const m = document.getElementById(id); if (m) m.style.display = 'flex'; }
  window.closeReitsModal = function (id) { const m = document.getElementById(id); if (m) m.style.display = 'none'; };

  function setBtnLoading(id, loading) {
    const btn = document.getElementById(id);
    if (btn) btn.disabled = loading;
  }

  function setText(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }
  function esc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function fmt(n, dec = 2) { return parseFloat(n || 0).toLocaleString('en-IN', { minimumFractionDigits: dec, maximumFractionDigits: dec }); }
  function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

  function showToast(msg, type = 'info') {
    if (window.showToast) { window.showToast(msg, type); return; }
    console.log(`[${type}] ${msg}`);
  }

})();
