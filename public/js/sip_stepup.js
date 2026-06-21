/**
 * WealthDash — SIP Step-Up JS
 * Task: t144
 * Path: public/js/sip_stepup.js
 */

'use strict';

(function () {
  const API = window.API || { post: (a, d) => fetch('/api/router.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action:a,...d}) }).then(r=>r.json()) };

  let configsCache   = [];
  let sipListCache   = [];
  let dueConfigs     = [];

  // ── Init ─────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    loadStepups();
    loadNudges();
    loadSipList();

    document.getElementById('btnAddStepup')?.addEventListener('click', () => openStepupModal());
    document.getElementById('btnSaveStepup')?.addEventListener('click', saveStepup);
    document.getElementById('btnViewHistory')?.addEventListener('click', toggleHistory);
    document.getElementById('btnSimulator')?.addEventListener('click', toggleSimulator);
    document.getElementById('btnRunSimulator')?.addEventListener('click', runSimulator);
    document.getElementById('btnSalaryHike')?.addEventListener('click', openSalaryHikeModal);
    document.getElementById('btnApplyAllStepups')?.addEventListener('click', applyAllStepups);
    document.getElementById('btnApplyDueStepups')?.addEventListener('click', applyAllDue);
    document.getElementById('btnDismissNudge')?.addEventListener('click', () => {
      document.getElementById('stepupNudgeBanner').style.display = 'none';
    });

    // Preview on change
    ['stepupSipId','stepupType','stepupValue'].forEach(id => {
      document.getElementById(id)?.addEventListener('change', updatePreview);
      document.getElementById(id)?.addEventListener('input', updatePreview);
    });

    document.getElementById('stepupSipId')?.addEventListener('change', function () {
      const sip = sipListCache.find(s => s.id == this.value);
      const info = document.getElementById('stepupSipInfo');
      if (sip && info) {
        info.textContent = `Current: ₹${fmt(sip.sip_amount, 0)} / ${sip.frequency}`;
      } else if (info) info.textContent = '';
      updatePreview();
    });
  });

  // ── Load step-up configs ─────────────────────────────────────
  function loadStepups() {
    API.post('stepup_list', {}).then(res => {
      configsCache = res.data?.configs || [];
      renderTable(configsCache);
      updateSummary(configsCache, res.data?.upcoming || []);
    }).catch(() => {
      document.getElementById('stepupBody').innerHTML = '<tr><td colspan="9" class="text-center text-danger">Load failed</td></tr>';
    });
  }

  function renderTable(list) {
    const tbody = document.getElementById('stepupBody');
    if (!list?.length) {
      tbody.innerHTML = `<tr><td colspan="9" class="text-center" style="padding:40px;color:var(--text-muted);">
        No step-up plans yet — click "Add Step-Up" to get started
      </td></tr>`;
      return;
    }

    tbody.innerHTML = list.map(c => {
      const stepupLabel = c.stepup_type === 'percentage'
        ? `${c.stepup_value}% / yr`
        : `₹${fmt(c.stepup_value, 0)} / yr`;

      const isOverdue   = c.next_stepup_date && c.next_stepup_date < date0();
      const isDueSoon   = c.next_stepup_date && c.next_stepup_date <= datePlus(30);
      const dateColor   = isOverdue ? 'var(--danger)' : isDueSoon ? 'var(--warning,#f59e0b)' : '';

      return `<tr>
        <td style="max-width:200px;">
          <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(c.fund_name)}</div>
          <div style="font-size:11px;color:var(--text-muted);">${esc(c.scheme_code || '')}</div>
        </td>
        <td class="text-right">₹${fmt(c.sip_amount, 0)}</td>
        <td>${esc(stepupLabel)}</td>
        <td>${esc(c.stepup_frequency)}</td>
        <td style="color:${dateColor};font-weight:${isOverdue||isDueSoon?'600':'400'};">
          ${c.next_stepup_date || '—'}
          ${isOverdue ? ' ⚠️' : isDueSoon ? ' 🔔' : ''}
        </td>
        <td style="color:var(--text-muted);font-size:12px;">
          ${c.last_stepup_date
            ? `${c.last_stepup_date}<br>₹${fmt(c.last_stepup_from,0)} → ₹${fmt(c.last_stepup_to,0)}`
            : '—'}
        </td>
        <td>${c.max_sip_amount ? '₹' + fmt(c.max_sip_amount, 0) : '—'}</td>
        <td>
          <span style="padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;
                       background:${c.is_active ? 'rgba(16,185,129,.1)' : 'var(--bg-secondary)'};
                       color:${c.is_active ? 'var(--success)' : 'var(--text-muted)'};">
            ${c.is_active ? 'Active' : 'Paused'}
          </span>
        </td>
        <td>
          <div style="display:flex;gap:4px;">
            ${c.is_active ? `<button class="btn btn-primary btn-xs" onclick="applyStepup(${c.id})" title="Apply Now">Apply</button>` : ''}
            <button class="btn btn-ghost btn-xs" onclick="editStepup(${c.id})" title="Edit">✏️</button>
            <button class="btn btn-ghost btn-xs" onclick="deleteStepup(${c.id})" title="Delete">🗑️</button>
          </div>
        </td>
      </tr>`;
    }).join('');
  }

  function updateSummary(configs, upcoming) {
    const active = configs.filter(c => c.is_active).length;
    const next   = configs.filter(c => c.next_stepup_date && c.is_active)
                         .sort((a,b) => a.next_stepup_date.localeCompare(b.next_stepup_date))[0];

    setText('statStepupCount',    active);
    setText('statNextStepup',     next ? next.next_stepup_date : '—');
    setText('statUpcomingCount',  upcoming.length);

    // Load total applied from history
    API.post('stepup_history', {}).then(res => {
      setText('statTotalApplied', (res.data?.history || []).length);
    });

    // Show banner if overdue/due
    dueConfigs = configs.filter(c => c.is_active && c.next_stepup_date <= datePlus(0));
    if (dueConfigs.length) {
      const banner = document.getElementById('stepupNudgeBanner');
      banner.style.display = 'block';
      document.getElementById('nudgeBannerText').textContent =
        `${dueConfigs.length} SIP step-up(s) are due — apply them now to grow your wealth!`;
    }
  }

  // ── SIP list for dropdown ────────────────────────────────────
  function loadSipList() {
    API.post('sip_list', {}).then(res => {
      sipListCache = res.data?.sips || res.data || [];
      const sel    = document.getElementById('stepupSipId');
      if (!sel || !sipListCache.length) return;

      // Keep existing options, add sips
      const currentConfigSipIds = configsCache.map(c => c.sip_id);
      const available = sipListCache.filter(s => !currentConfigSipIds.includes(s.id));

      sel.innerHTML = '<option value="">— Select SIP —</option>' +
        (available.length
          ? available.map(s => `<option value="${s.id}" data-amount="${s.sip_amount}" data-freq="${s.frequency}">${esc(s.fund_name)} — ₹${fmt(s.sip_amount,0)}/${s.frequency}</option>`).join('')
          : '<option disabled>All SIPs already have step-up plans</option>');
    }).catch(() => {});
  }

  // ── Add / Edit modal ────────────────────────────────────────
  function openStepupModal(config = null) {
    document.getElementById('stepupEditId').value    = config?.id || '';
    document.getElementById('stepupValue').value     = config?.stepup_value || '';
    document.getElementById('stepupType').value      = config?.stepup_type || 'percentage';
    document.getElementById('stepupFreq').value      = config?.stepup_frequency || 'yearly';
    document.getElementById('stepupMonth').value     = config?.stepup_month || '4';
    document.getElementById('customInterval').value  = config?.custom_interval_months || '';
    document.getElementById('stepupMaxAmount').value = config?.max_sip_amount || '';
    document.getElementById('stepupNotes').value     = config?.notes || '';
    document.getElementById('stepupIsActive').checked = config ? config.is_active == 1 : true;
    document.getElementById('stepupError').style.display = 'none';
    document.getElementById('modalStepupTitle').textContent = config ? 'Edit Step-Up Plan' : 'Add Step-Up Plan';

    // Set sip dropdown
    if (config?.sip_id) {
      // Add this sip to the dropdown if editing
      const sel = document.getElementById('stepupSipId');
      let opt = sel.querySelector(`option[value="${config.sip_id}"]`);
      if (!opt) {
        opt = document.createElement('option');
        opt.value = config.sip_id;
        opt.dataset.amount = config.sip_amount;
        opt.textContent = config.fund_name + ' — ₹' + fmt(config.sip_amount, 0) + '/' + config.frequency;
        sel.appendChild(opt);
      }
      sel.value = config.sip_id;
    } else {
      document.getElementById('stepupSipId').value = '';
    }

    toggleCustomInterval();
    updatePreview();
    document.getElementById('modalStepup').style.display = 'flex';
  }

  function saveStepup() {
    const editId = document.getElementById('stepupEditId').value;
    const sipId  = document.getElementById('stepupSipId').value;
    const value  = parseFloat(document.getElementById('stepupValue').value || 0);

    if (!sipId || value <= 0) {
      showError('stepupError', 'SIP select karo aur step-up value enter karo');
      return;
    }

    const payload = {
      sip_id:                   sipId,
      stepup_type:              document.getElementById('stepupType').value,
      stepup_value:             value,
      stepup_frequency:         document.getElementById('stepupFreq').value,
      stepup_month:             document.getElementById('stepupMonth').value,
      custom_interval_months:   document.getElementById('customInterval').value || 0,
      max_sip_amount:           parseFloat(document.getElementById('stepupMaxAmount').value || 0),
      is_active:                document.getElementById('stepupIsActive').checked ? 1 : 0,
      notes:                    document.getElementById('stepupNotes').value.trim(),
      _csrf:                    document.getElementById('stepupCsrf').value,
    };
    if (editId) payload.id = editId;

    setBtnLoading('btnSaveStepup', true);
    API.post('stepup_save', payload).then(res => {
      if (res.success) {
        closeStepupModal();
        loadStepups();
        loadSipList();
        showToast(res.message || 'Step-up saved', 'success');
      } else {
        showError('stepupError', res.message || 'Error');
      }
    }).finally(() => setBtnLoading('btnSaveStepup', false));
  }

  // ── Apply step-up ────────────────────────────────────────────
  window.applyStepup = function (configId) {
    const c = configsCache.find(x => x.id == configId);
    if (!c) return;

    const newAmt = calcNewAmount(parseFloat(c.sip_amount), c);
    if (!confirm(`Apply step-up?\n\n${c.fund_name}\n₹${fmt(c.sip_amount,0)} → ₹${fmt(newAmt,0)}`)) return;

    API.post('stepup_apply', { config_id: configId }).then(res => {
      if (res.success) {
        loadStepups();
        showToast(`✅ ${res.message}`, 'success');
      } else showToast(res.message || 'Error', 'error');
    });
  };

  function applyAllDue() {
    if (!dueConfigs.length) return;
    let done = 0;
    dueConfigs.forEach(c => {
      API.post('stepup_apply', { config_id: c.id }).then(() => {
        if (++done === dueConfigs.length) {
          document.getElementById('stepupNudgeBanner').style.display = 'none';
          loadStepups();
          showToast(`${done} step-up(s) applied!`, 'success');
        }
      });
    });
  }

  // ── Edit / Delete ────────────────────────────────────────────
  window.editStepup = function (id) {
    const c = configsCache.find(x => x.id == id);
    if (c) openStepupModal(c);
  };

  window.deleteStepup = function (id) {
    if (!confirm('Delete this step-up plan?')) return;
    API.post('stepup_delete', { id }).then(res => {
      if (res.success) { loadStepups(); loadSipList(); showToast('Deleted', 'success'); }
      else showToast(res.message || 'Error', 'error');
    });
  };

  // ── History ──────────────────────────────────────────────────
  function toggleHistory() {
    const panel = document.getElementById('historyPanel');
    const visible = panel.style.display !== 'none';
    panel.style.display = visible ? 'none' : 'block';
    if (!visible) loadHistory();
  }

  function loadHistory() {
    API.post('stepup_history', {}).then(res => {
      const list  = res.data?.history || [];
      const tbody = document.getElementById('historyBody');
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="color:var(--text-muted);">No step-ups applied yet</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(h => {
        const increase = parseFloat(h.new_amount) - parseFloat(h.old_amount);
        return `<tr>
          <td>${h.applied_date}</td>
          <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(h.fund_name)}</td>
          <td class="text-right">₹${fmt(h.old_amount, 0)}</td>
          <td class="text-right" style="color:var(--success);">₹${fmt(h.new_amount, 0)}</td>
          <td class="text-right" style="color:var(--success);">+₹${fmt(increase, 0)}</td>
          <td>${h.stepup_type === 'percentage' ? h.stepup_value + '%' : '₹' + fmt(h.stepup_value, 0)}</td>
          <td style="color:var(--text-muted);">${h.applied_by}</td>
        </tr>`;
      }).join('');
    });
  }

  // ── Simulator ────────────────────────────────────────────────
  function toggleSimulator() {
    const panel = document.getElementById('simulatorPanel');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
  }

  function runSimulator() {
    const payload = {
      sip_amount:    parseFloat(document.getElementById('simSipAmount').value || 5000),
      stepup_type:   document.getElementById('simStepupType').value,
      stepup_value:  parseFloat(document.getElementById('simStepupValue').value || 10),
      expected_xirr: parseFloat(document.getElementById('simXirr').value || 12),
      years:         parseInt(document.getElementById('simYears').value || 10),
      max_sip_amount:parseFloat(document.getElementById('simMaxCap').value || 0),
    };

    setBtnLoading('btnRunSimulator', true);
    API.post('stepup_simulate', payload).then(res => {
      const projections = res.data?.projections || [];
      if (!projections.length) return;

      const last       = projections[projections.length - 1];
      const totalInv   = last.cumulative_invested;
      const corpus     = last.corpus || 0;
      const wealthMult = totalInv > 0 ? (corpus / totalInv).toFixed(2) : '—';
      const finalSip   = last.new_amount;

      document.getElementById('simSummaryCards').innerHTML = `
        <div class="stat-card"><div class="stat-label">Final Monthly SIP</div><div class="stat-value" style="font-size:20px;">₹${fmt(finalSip,0)}</div></div>
        <div class="stat-card"><div class="stat-label">Total Invested</div><div class="stat-value" style="font-size:20px;">₹${fmt(totalInv,0)}</div></div>
        <div class="stat-card"><div class="stat-label">Projected Corpus</div><div class="stat-value" style="font-size:20px;color:var(--success);">₹${fmt(corpus,0)}</div></div>
        <div class="stat-card"><div class="stat-label">Wealth Multiple</div><div class="stat-value" style="font-size:20px;color:var(--success);">${wealthMult}x</div></div>
      `;

      document.getElementById('simResultsBody').innerHTML = projections.map(p => {
        const wm = p.cumulative_invested > 0 && p.corpus ? (p.corpus / p.cumulative_invested).toFixed(2) : '—';
        return `<tr>
          <td>Year ${p.year}</td>
          <td class="text-right">₹${fmt(p.sip_amount, 0)}</td>
          <td class="text-right" style="color:var(--success);">+₹${fmt(p.stepup_amount, 0)}</td>
          <td class="text-right">₹${fmt(p.cumulative_invested, 0)}</td>
          <td class="text-right" style="font-weight:600;">${p.corpus ? '₹' + fmt(p.corpus, 0) : '—'}</td>
          <td class="text-right">${wm}x</td>
        </tr>`;
      }).join('');

      document.getElementById('simResults').style.display = 'block';
    }).finally(() => setBtnLoading('btnRunSimulator', false));
  }

  // ── Salary Hike Modal ────────────────────────────────────────
  function openSalaryHikeModal() {
    const allActive = configsCache.filter(c => c.is_active);
    const listEl    = document.getElementById('hikeStepupList');

    if (!allActive.length) {
      listEl.innerHTML = '<p style="color:var(--text-muted);text-align:center;">No active step-up plans. Add one first!</p>';
    } else {
      listEl.innerHTML = allActive.map(c => {
        const newAmt = calcNewAmount(parseFloat(c.sip_amount), c);
        return `<div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:var(--bg-secondary);border-radius:8px;margin-bottom:8px;">
          <div>
            <div style="font-weight:600;font-size:13px;">${esc(c.fund_name)}</div>
            <div style="font-size:12px;color:var(--text-muted);">₹${fmt(c.sip_amount,0)} → ₹${fmt(newAmt,0)} (+${c.stepup_type==='percentage'?c.stepup_value+'%':'₹'+fmt(c.stepup_value,0)})</div>
          </div>
          <button class="btn btn-outline btn-xs" onclick="applyStepup(${c.id})">Apply</button>
        </div>`;
      }).join('');
    }

    document.getElementById('modalSalaryHike').style.display = 'flex';

    // Create nudge in DB
    API.post('stepup_nudge_salary_hike', {}).catch(() => {});
  }

  function applyAllStepups() {
    const allActive = configsCache.filter(c => c.is_active);
    if (!allActive.length) return;
    let done = 0;
    allActive.forEach(c => {
      API.post('stepup_apply', { config_id: c.id }).then(() => {
        if (++done === allActive.length) {
          document.getElementById('modalSalaryHike').style.display = 'none';
          loadStepups();
          showToast(`${done} step-up(s) applied! 🚀`, 'success');
        }
      });
    });
  }

  // ── Nudges ────────────────────────────────────────────────────
  function loadNudges() {
    API.post('stepup_nudges', {}).then(res => {
      const upcoming = res.data?.upcoming_stepups || [];
      if (upcoming.length) {
        document.getElementById('stepupNudgeBanner').style.display = 'block';
        document.getElementById('nudgeBannerText').textContent =
          `${upcoming.length} step-up(s) due in the next 30 days!`;
      }
    }).catch(() => {});
  }

  // ── Preview helper ────────────────────────────────────────────
  function updatePreview() {
    const sipId = document.getElementById('stepupSipId').value;
    const sip   = sipListCache.find(s => s.id == sipId);
    const val   = parseFloat(document.getElementById('stepupValue').value || 0);
    const type  = document.getElementById('stepupType').value;
    const box   = document.getElementById('stepupPreviewBox');

    if (!sip || val <= 0) { box.style.display = 'none'; return; }

    const cur = parseFloat(sip.sip_amount);
    const cfg = { stepup_type: type, stepup_value: val, max_sip_amount: parseFloat(document.getElementById('stepupMaxAmount')?.value || 0) || null };
    const newAmt = calcNewAmount(cur, cfg);

    document.getElementById('prevCurrent').textContent = '₹' + fmt(cur, 0);
    document.getElementById('prevNew').textContent     = '₹' + fmt(newAmt, 0);
    box.style.display = 'block';
  }

  function calcNewAmount(current, config) {
    const type  = config.stepup_type;
    const value = parseFloat(config.stepup_value);
    const max   = config.max_sip_amount ? parseFloat(config.max_sip_amount) : null;
    let newAmt  = type === 'percentage' ? current * (1 + value / 100) : current + value;
    newAmt      = Math.ceil(newAmt / 100) * 100;
    if (max && newAmt > max) newAmt = max;
    return newAmt;
  }

  // ── UI Helpers ────────────────────────────────────────────────
  window.toggleCustomInterval = function () {
    const freq = document.getElementById('stepupFreq').value;
    document.getElementById('customIntervalGroup').style.display  = freq === 'custom'  ? 'block' : 'none';
    document.getElementById('stepupMonthGroup').style.display     = freq !== 'custom'  ? 'block' : 'none';
  };

  window.closeStepupModal = function () {
    document.getElementById('modalStepup').style.display = 'none';
  };

  function setText(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }
  function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function fmt(n, dec = 2) { return parseFloat(n||0).toLocaleString('en-IN', { minimumFractionDigits: dec, maximumFractionDigits: dec }); }
  function setBtnLoading(id, l) { const b = document.getElementById(id); if (b) b.disabled = l; }
  function showError(id, msg) { const el = document.getElementById(id); if (el) { el.textContent = msg; el.style.display = 'block'; } }
  function date0()        { return new Date().toISOString().split('T')[0]; }
  function datePlus(days) { const d = new Date(); d.setDate(d.getDate() + days); return d.toISOString().split('T')[0]; }
  function showToast(msg, type = 'info') { if (window.showToast) window.showToast(msg, type); else console.log(`[${type}] ${msg}`); }

})();
