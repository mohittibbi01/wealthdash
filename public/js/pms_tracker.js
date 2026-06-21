/**
 * WealthDash — PMS / AIF Tracker JS
 * Task: t119
 * File: public/js/pms_tracker.js
 */

const PmsTracker = {
  _data:        [],
  _filter:      '',
  _navChart:    null,
  _activeHid:   null,

  BADGE_CLASS: {
    PMS:      'pms-badge-pms',
    AIF_CAT1: 'pms-badge-aif1',
    AIF_CAT2: 'pms-badge-aif2',
    AIF_CAT3: 'pms-badge-aif3',
  },
  BADGE_LABEL: {
    PMS:      'PMS',
    AIF_CAT1: 'AIF I',
    AIF_CAT2: 'AIF II',
    AIF_CAT3: 'AIF III',
  },

  /* ──────────────────────────────────── init ── */
  init() {
    document.getElementById('btnAddPms')?.addEventListener('click',      () => this.openModal());
    document.getElementById('btnAddPmsEmpty')?.addEventListener('click', () => this.openModal());
    document.getElementById('btnSavePms')?.addEventListener('click',     () => this.save());
    document.getElementById('btnSavePmsTxn')?.addEventListener('click',  () => this.saveTxn());

    document.querySelectorAll('.pms-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.pms-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        this._filter = btn.dataset.filter ?? '';
        this._render();
      });
    });

    this.load();
  },

  /* ───────────────────────────────────── API ── */
  async load() {
    const base = window._PMS_BASE || '';
    try {
      const [listRes, sumRes] = await Promise.all([
        fetch(`${base}/api/pms_aif/pms_tracker.php?action=pms_list`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }),
        fetch(`${base}/api/pms_aif/pms_tracker.php?action=pms_summary`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }),
      ]);
      const list = await listRes.json();
      const sum  = await sumRes.json();

      this._data = list.data ?? [];
      if (sum.success) this._renderSummary(sum.data);
      this._render();
    } catch (e) {
      document.getElementById('pmsGrid').innerHTML =
        `<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--danger);">Failed to load: ${e.message}</div>`;
    }
  },

  /* ──────────────────────────────── summary ── */
  _renderSummary(s) {
    const inr = v => v != null ? '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '—';
    const gl  = (float) => {
      if (s.total_gain_loss == null) return '—';
      const v   = Number(s.total_gain_loss);
      const pct = s.gain_pct != null ? ` (${s.gain_pct > 0 ? '+' : ''}${s.gain_pct}%)` : '';
      const col = v >= 0 ? '#16a34a' : '#dc2626';
      return `<span style="color:${col};">${v >= 0 ? '+' : ''}${inr(v)}${pct}</span>`;
    };
    document.getElementById('statPmsInvested').textContent = inr(s.total_invested);
    document.getElementById('statPmsCurrent').textContent  = inr(s.total_current);
    document.getElementById('statPmsGain').innerHTML       = gl();
    document.getElementById('statPmsXirr').textContent     = s.avg_xirr != null ? Number(s.avg_xirr).toFixed(2) + '%' : '—';
    document.getElementById('statPmsCount').textContent    = s.active_count ?? 0;
  },

  /* ──────────────────────────────────── grid ── */
  _render() {
    const grid  = document.getElementById('pmsGrid');
    const empty = document.getElementById('pmsEmpty');

    let list = this._data;
    if (this._filter) list = list.filter(h => h.asset_class === this._filter);

    if (!list.length) {
      grid.style.display  = 'none';
      empty.style.display = 'block';
      return;
    }
    grid.style.display  = 'grid';
    empty.style.display = 'none';

    grid.innerHTML = list.map(h => this._cardHtml(h)).join('');
  },

  _cardHtml(h) {
    const inr  = v => v != null ? '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '—';
    const gl   = Number(h.gain_loss ?? 0);
    const glp  = Number(h.gain_loss_pct ?? 0);
    const gcls = gl >= 0 ? 'pms-gain-pos' : 'pms-gain-neg';
    const glSign = gl >= 0 ? '+' : '';

    const badgeCls   = this.BADGE_CLASS[h.asset_class] ?? 'pms-badge-pms';
    const badgeLbl   = this.BADGE_LABEL[h.asset_class] ?? h.asset_class;
    const lockTag    = h.locked ? `<span class="pms-locked-tag">🔒 ${h.days_to_unlock}d locked</span>` : '';
    const xirr       = h.xirr != null ? `<span style="font-size:11px;color:#3b82f6;margin-left:6px;font-weight:700;">XIRR: ${Number(h.xirr).toFixed(2)}%</span>` : '';

    return `
      <div class="pms-card" onclick="PmsTracker.openDrawer(${h.id})" style="cursor:pointer;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;">
          <span class="pms-badge ${badgeCls}">${badgeLbl}</span>
          <div style="display:flex;gap:6px;">
            <button class="btn btn-ghost btn-sm" style="padding:3px 8px;font-size:11px;"
              onclick="event.stopPropagation();PmsTracker.openTxnModal(${h.id},'${(h.pms_name ?? '').replace(/'/g,"\\'")}')">+ Txn</button>
            <button class="btn btn-ghost btn-sm" style="padding:3px 8px;font-size:11px;"
              onclick="event.stopPropagation();PmsTracker.openUpdateModal(${h.id})">↑ Value</button>
          </div>
        </div>
        <div class="pms-card-name">${h.pms_name ?? ''}${lockTag}</div>
        <div class="pms-card-sub">
          ${h.manager_name ? h.manager_name + ' · ' : ''}${h.strategy_name ?? ''}
          ${h.platform ? ' · ' + h.platform : ''}
          ${xirr}
        </div>
        <div class="pms-metrics">
          <div class="pms-metric">
            <div class="pms-metric-label">Invested</div>
            <div class="pms-metric-value">${inr(h.invested_amount)}</div>
          </div>
          <div class="pms-metric">
            <div class="pms-metric-label">Current</div>
            <div class="pms-metric-value">${inr(h.current_value)}</div>
          </div>
          <div class="pms-metric">
            <div class="pms-metric-label">Gain / Loss</div>
            <div class="pms-metric-value ${gcls}">
              ${h.current_value != null ? glSign + inr(gl) : '—'}
              ${h.gain_loss_pct != null ? `<br><span style="font-size:10px;">(${glSign}${Number(h.gain_loss_pct).toFixed(2)}%)</span>` : ''}
            </div>
          </div>
        </div>
        ${h.management_fee_pct != null ? `
          <div style="margin-top:10px;font-size:11px;color:var(--text-muted);border-top:1px solid var(--border-color);padding-top:8px;">
            Mgmt ${h.management_fee_pct}%${h.performance_fee_pct ? ' · Perf ' + h.performance_fee_pct + '%' : ''}
            ${h.hurdle_rate_pct ? ' · Hurdle ' + h.hurdle_rate_pct + '%' : ''}
            ${h.benchmark ? ' · ' + h.benchmark : ''}
          </div>` : ''}
      </div>`;
  },

  /* ──────────────────────────────── drawer ── */
  async openDrawer(hid) {
    this._activeHid = hid;
    document.getElementById('pmsDrawerOv').classList.add('open');
    document.getElementById('pmsDrawer').classList.add('open');
    document.getElementById('drawerContent').innerHTML = `<div style="text-align:center;padding:40px;"><div class="spinner"></div></div>`;

    const base = window._PMS_BASE || '';
    try {
      const res  = await fetch(`${base}/api/pms_aif/pms_tracker.php?action=pms_detail&id=${hid}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.msg);
      this._renderDrawer(json.data);
    } catch (e) {
      document.getElementById('drawerContent').innerHTML =
        `<div style="color:var(--danger);">Failed: ${e.message}</div>`;
    }
  },

  _renderDrawer(h) {
    const inr  = v => v != null ? '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '—';
    const gl   = Number(h.gain_loss ?? 0);
    const gcls = gl >= 0 ? '#16a34a' : '#dc2626';
    const glSign = gl >= 0 ? '+' : '';

    document.getElementById('drawerTitle').textContent = h.pms_name ?? 'PMS Detail';

    const txnRows = (h.transactions ?? []).slice(0, 20).map(t => `
      <tr>
        <td style="padding:7px 10px;font-size:12px;">${t.txn_date}</td>
        <td style="padding:7px 10px;font-size:12px;text-transform:capitalize;">${(t.txn_type ?? '').replace(/_/g,' ')}</td>
        <td style="padding:7px 10px;font-size:12px;text-align:right;font-weight:600;">${inr(t.amount)}</td>
        <td style="padding:7px 10px;font-size:11px;color:var(--text-muted);">${t.notes ?? ''}</td>
      </tr>`).join('') || `<tr><td colspan="4" style="text-align:center;padding:20px;color:var(--text-muted);">No transactions yet</td></tr>`;

    document.getElementById('drawerContent').innerHTML = `
      <div style="margin-bottom:16px;">
        <span class="pms-badge ${this.BADGE_CLASS[h.asset_class] ?? 'pms-badge-pms'}">${this.BADGE_LABEL[h.asset_class] ?? h.asset_class}</span>
        ${h.locked ? `<span class="pms-locked-tag">🔒 ${h.days_to_unlock} days locked</span>` : ''}
        ${h.platform ? `<span style="font-size:11px;color:var(--text-muted);margin-left:6px;">${h.platform}</span>` : ''}
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;">
        ${[
          ['Invested',  inr(h.invested_amount)],
          ['Current',   inr(h.current_value)],
          ['Gain/Loss', `<span style="color:${gcls};font-weight:700;">${h.current_value != null ? glSign + inr(gl) : '—'}</span>`],
          ['Gain %',    h.gain_loss_pct != null ? `<span style="color:${gcls};font-weight:700;">${glSign}${Number(h.gain_loss_pct).toFixed(2)}%</span>` : '—'],
          ['XIRR',      h.xirr != null ? `<span style="color:#3b82f6;font-weight:700;">${Number(h.xirr).toFixed(2)}%</span>` : '—'],
          ['NAV',       h.nav_current ? `₹${Number(h.nav_current).toFixed(4)} (${h.nav_date ?? ''})` : '—'],
          ['Units',     h.units ? Number(h.units).toFixed(4) : '—'],
          ['Mgmt Fee',  h.management_fee_pct ? h.management_fee_pct + '%/yr' : '—'],
          ['Perf Fee',  h.performance_fee_pct ? h.performance_fee_pct + '%' : '—'],
          ['Benchmark', h.benchmark ?? '—'],
          ['Lock-in',   h.lock_in_end_date ?? (h.lock_in_months ? h.lock_in_months + ' months' : 'None')],
          ['Invested On', h.investment_date ?? '—'],
        ].map(([k, v]) => `
          <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 12px;">
            <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em;margin-bottom:3px;">${k}</div>
            <div style="font-size:13px;font-weight:600;">${v}</div>
          </div>`).join('')}
      </div>

      ${h.nav_history?.length ? `
      <div style="margin-bottom:16px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">NAV History</div>
        <canvas id="pmsNavChart" style="max-height:180px;"></canvas>
      </div>` : ''}

      <div style="margin-bottom:8px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">
          Transactions (${h.transactions?.length ?? 0})
        </div>
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;">
            <thead>
              <tr style="background:var(--bg-secondary);">
                <th style="padding:7px 10px;font-size:11px;text-align:left;">Date</th>
                <th style="padding:7px 10px;font-size:11px;text-align:left;">Type</th>
                <th style="padding:7px 10px;font-size:11px;text-align:right;">Amount</th>
                <th style="padding:7px 10px;font-size:11px;">Notes</th>
              </tr>
            </thead>
            <tbody>${txnRows}</tbody>
          </table>
        </div>
      </div>

      <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">
        <button class="btn btn-outline btn-sm" onclick="PmsTracker.openTxnModal(${h.id},'${(h.pms_name ?? '').replace(/'/g,"\\'")}')">+ Add Transaction</button>
        <button class="btn btn-outline btn-sm" onclick="PmsTracker.openEditModal(${h.id})">✎ Edit</button>
        <button class="btn btn-outline btn-sm" onclick="PmsTracker.openUpdateModal(${h.id})">↑ Update Value</button>
        <button class="btn btn-ghost btn-sm" style="color:var(--danger);" onclick="PmsTracker.deleteHolding(${h.id})">Delete</button>
      </div>`;

    // Render NAV chart if data available
    if (h.nav_history?.length) {
      setTimeout(() => this._renderNavChart(h.nav_history), 50);
    }
  },

  _renderNavChart(history) {
    const ctx = document.getElementById('pmsNavChart');
    if (!ctx) return;
    if (this._navChart) { this._navChart.destroy(); this._navChart = null; }

    this._navChart = new Chart(ctx, {
      type: 'line',
      data: {
        datasets: [{
          label: 'NAV',
          data:  history.map(p => ({ x: p.nav_date, y: parseFloat(p.nav) })),
          borderColor: '#3b82f6',
          backgroundColor: 'rgba(59,130,246,.08)',
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 4,
          fill: true,
          tension: 0.3,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: c => ` NAV: ₹${c.parsed.y.toFixed(4)}` } }
        },
        scales: {
          x: {
            type: 'time',
            time: { tooltipFormat: 'dd MMM yyyy' },
            grid: { display: false },
            ticks: { font: { size: 10 }, color: '#9ca3af', maxTicksLimit: 6 },
          },
          y: {
            grid: { color: 'rgba(0,0,0,.05)' },
            ticks: { font: { size: 10 }, color: '#9ca3af', callback: v => '₹' + v.toFixed(2) },
          }
        }
      }
    });
  },

  closeDrawer() {
    document.getElementById('pmsDrawerOv').classList.remove('open');
    document.getElementById('pmsDrawer').classList.remove('open');
    if (this._navChart) { this._navChart.destroy(); this._navChart = null; }
    this._activeHid = null;
  },

  /* ──────────────────────────────── modal ── */
  openModal(hid = null) {
    document.getElementById('pmsEditId').value      = hid ?? '';
    document.getElementById('pmsModalTitle').textContent = hid ? 'Edit PMS / AIF' : 'Add PMS / AIF Holding';
    document.getElementById('pmsError').style.display   = 'none';

    if (!hid) {
      ['pmsFundName','pmsManagerName','pmsStrategyName','pmsFolioNo',
       'pmsInvested','pmsCurValue','pmsXirr','pmsNav','pmsNavDate',
       'pmsUnits','pmsLockMonths','pmsMgmtFee','pmsPerfFee','pmsHurdle','pmsBenchmark','pmsNotes'
      ].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
      document.getElementById('pmsAssetClass').value  = 'PMS';
      document.getElementById('pmsInvestDate').value  = new Date().toISOString().split('T')[0];
    }
    document.getElementById('modalPmsAdd').style.display = 'flex';
    setTimeout(() => document.getElementById('pmsFundName').focus(), 100);
  },

  async openEditModal(hid) {
    this.closeDrawer();
    const base = window._PMS_BASE || '';
    try {
      const res  = await fetch(`${base}/api/pms_aif/pms_tracker.php?action=pms_detail&id=${hid}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.msg);
      const h = json.data;
      this.openModal(hid);
      document.getElementById('pmsAssetClass').value   = h.asset_class   ?? 'PMS';
      document.getElementById('pmsFundName').value     = h.pms_name      ?? '';
      document.getElementById('pmsManagerName').value  = h.manager_name  ?? '';
      document.getElementById('pmsStrategyName').value = h.strategy_name ?? '';
      document.getElementById('pmsFolioNo').value      = h.folio_number  ?? '';
      document.getElementById('pmsInvestDate').value   = h.investment_date ?? '';
      document.getElementById('pmsInvested').value     = h.invested_amount ?? '';
      document.getElementById('pmsCurValue').value     = h.current_value   ?? '';
      document.getElementById('pmsXirr').value         = h.xirr            ?? '';
      document.getElementById('pmsNav').value          = h.nav_current     ?? '';
      document.getElementById('pmsNavDate').value      = h.nav_date        ?? '';
      document.getElementById('pmsUnits').value        = h.units           ?? '';
      document.getElementById('pmsLockMonths').value   = h.lock_in_months  ?? '';
      document.getElementById('pmsMgmtFee').value      = h.management_fee_pct   ?? '';
      document.getElementById('pmsPerfFee').value      = h.performance_fee_pct  ?? '';
      document.getElementById('pmsHurdle').value       = h.hurdle_rate_pct      ?? '';
      document.getElementById('pmsBenchmark').value    = h.benchmark   ?? '';
      document.getElementById('pmsPlatform').value     = h.platform    ?? '';
      document.getElementById('pmsNotes').value        = h.notes       ?? '';
    } catch (e) {
      if (typeof showToast === 'function') showToast('Load failed: ' + e.message, 'error');
    }
  },

  closeModal() {
    document.getElementById('modalPmsAdd').style.display = 'none';
  },

  async save() {
    const btn     = document.getElementById('btnSavePms');
    const label   = document.getElementById('btnSavePmsLabel');
    const spinner = document.getElementById('btnSavePmsSpinner');
    const errEl   = document.getElementById('pmsError');
    const hid     = document.getElementById('pmsEditId').value;
    const isEdit  = !!hid;

    const name     = document.getElementById('pmsFundName').value.trim();
    const invested = parseFloat(document.getElementById('pmsInvested').value);
    if (!name || !invested) {
      errEl.textContent = 'Fund name and invested amount are required';
      errEl.style.display = 'block'; return;
    }

    btn.disabled  = true; spinner.style.display = 'inline-block'; label.textContent = 'Saving…'; errEl.style.display = 'none';

    const payload = {
      action:             isEdit ? 'pms_edit' : 'pms_add',
      _csrf:              document.getElementById('pmsCsrf').value,
      asset_class:        document.getElementById('pmsAssetClass').value,
      pms_name:           name,
      manager_name:       document.getElementById('pmsManagerName').value,
      strategy_name:      document.getElementById('pmsStrategyName').value,
      folio_number:       document.getElementById('pmsFolioNo').value,
      investment_date:    document.getElementById('pmsInvestDate').value,
      invested_amount:    invested,
      current_value:      document.getElementById('pmsCurValue').value      || '',
      xirr:               document.getElementById('pmsXirr').value          || '',
      nav_current:        document.getElementById('pmsNav').value            || '',
      nav_date:           document.getElementById('pmsNavDate').value        || '',
      units:              document.getElementById('pmsUnits').value          || '',
      lock_in_months:     document.getElementById('pmsLockMonths').value     || '',
      management_fee_pct: document.getElementById('pmsMgmtFee').value        || '',
      performance_fee_pct:document.getElementById('pmsPerfFee').value        || '',
      hurdle_rate_pct:    document.getElementById('pmsHurdle').value         || '',
      benchmark:          document.getElementById('pmsBenchmark').value      || '',
      platform:           document.getElementById('pmsPlatform').value       || '',
      notes:              document.getElementById('pmsNotes').value          || '',
    };
    if (isEdit) payload.id = hid;

    const base = window._PMS_BASE || '';
    try {
      const res  = await fetch(`${base}/api/pms_aif/pms_tracker.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(payload),
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.msg || 'Save failed');
      this.closeModal();
      if (typeof showToast === 'function') showToast(isEdit ? 'Updated successfully' : 'Holding added', 'success');
      await this.load();
    } catch (e) {
      errEl.textContent = e.message; errEl.style.display = 'block';
    } finally {
      btn.disabled  = false; spinner.style.display = 'none'; label.textContent = 'Save';
    }
  },

  /* ────────────────────────────── txn modal ── */
  openTxnModal(hid, name = '') {
    document.getElementById('txnHoldingId').value = hid;
    document.getElementById('pmsTxnType').value   = 'investment';
    document.getElementById('pmsTxnDate').value   = new Date().toISOString().split('T')[0];
    ['pmsTxnAmount','pmsTxnNav','pmsTxnUnits','pmsTxnNotes'].forEach(id => {
      const el = document.getElementById(id); if (el) el.value = '';
    });
    document.getElementById('pmsTxnError').style.display = 'none';
    document.getElementById('modalPmsTxn').style.display = 'flex';
  },

  closeTxnModal() { document.getElementById('modalPmsTxn').style.display = 'none'; },

  async saveTxn() {
    const btn     = document.getElementById('btnSavePmsTxn');
    const label   = document.getElementById('btnSavePmsTxnLabel');
    const spinner = document.getElementById('btnSavePmsTxnSpinner');
    const errEl   = document.getElementById('pmsTxnError');
    const hid     = document.getElementById('txnHoldingId').value;
    const amount  = parseFloat(document.getElementById('pmsTxnAmount').value);

    if (!hid || !amount) {
      errEl.textContent = 'Amount is required'; errEl.style.display = 'block'; return;
    }
    btn.disabled = true; spinner.style.display = 'inline-block'; label.textContent = 'Saving…'; errEl.style.display = 'none';

    const base = window._PMS_BASE || '';
    try {
      const res  = await fetch(`${base}/api/pms_aif/pms_tracker.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({
          action:     'pms_txn_add',
          holding_id: hid,
          txn_type:   document.getElementById('pmsTxnType').value,
          txn_date:   document.getElementById('pmsTxnDate').value,
          amount,
          nav:        document.getElementById('pmsTxnNav').value   || '',
          units:      document.getElementById('pmsTxnUnits').value || '',
          notes:      document.getElementById('pmsTxnNotes').value || '',
        })
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.msg || 'Failed');
      this.closeTxnModal();
      if (typeof showToast === 'function') showToast('Transaction added', 'success');
      await this.load();
    } catch (e) {
      errEl.textContent = e.message; errEl.style.display = 'block';
    } finally {
      btn.disabled = false; spinner.style.display = 'none'; label.textContent = 'Save Transaction';
    }
  },

  /* ───────────────────────── quick value update ── */
  openUpdateModal(hid) {
    const h = this._data.find(x => x.id === hid);
    if (!h) return;
    const val = prompt(`Update current value for ${h.pms_name}:\n(Current: ₹${Number(h.current_value ?? 0).toLocaleString('en-IN')})`, h.current_value ?? '');
    if (val === null || val === '') return;
    const newVal = parseFloat(val.replace(/,/g, ''));
    if (isNaN(newVal) || newVal < 0) { alert('Invalid value'); return; }
    this._quickUpdateValue(hid, newVal);
  },

  async _quickUpdateValue(hid, val) {
    const base = window._PMS_BASE || '';
    try {
      const res  = await fetch(`${base}/api/pms_aif/pms_tracker.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'pms_update_value', id: hid, current_value: val })
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.msg);
      if (typeof showToast === 'function') showToast('Value updated', 'success');
      await this.load();
    } catch (e) {
      if (typeof showToast === 'function') showToast('Update failed: ' + e.message, 'error');
    }
  },

  /* ─────────────────────────────── delete ── */
  async deleteHolding(hid) {
    if (!confirm('Delete this PMS/AIF holding? (Soft delete — data preserved)')) return;
    const base = window._PMS_BASE || '';
    try {
      const res  = await fetch(`${base}/api/pms_aif/pms_tracker.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'pms_delete', id: hid })
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.msg);
      this.closeDrawer();
      if (typeof showToast === 'function') showToast('Holding removed', 'success');
      await this.load();
    } catch (e) {
      if (typeof showToast === 'function') showToast('Delete failed: ' + e.message, 'error');
    }
  },

  /* ─────────────────────────────── export ── */
  exportCsv() {
    if (!this._data.length) return;
    const headers = ['PMS/AIF Name','Type','Manager','Strategy','Invested (₹)','Current Value (₹)',
                     'Gain/Loss (₹)','Gain %','XIRR %','NAV','NAV Date','Lock-in Months',
                     'Mgmt Fee %','Perf Fee %','Hurdle %','Benchmark','Platform','Investment Date','Folio'];
    const rows = this._data.map(h => [
      h.pms_name ?? '', h.asset_class ?? '', h.manager_name ?? '', h.strategy_name ?? '',
      h.invested_amount ?? '', h.current_value ?? '',
      h.gain_loss ?? '', h.gain_loss_pct ?? '', h.xirr ?? '',
      h.nav_current ?? '', h.nav_date ?? '', h.lock_in_months ?? '',
      h.management_fee_pct ?? '', h.performance_fee_pct ?? '', h.hurdle_rate_pct ?? '',
      h.benchmark ?? '', h.platform ?? '', h.investment_date ?? '', h.folio_number ?? '',
    ].map(v => `"${String(v).replace(/"/g,'""')}"`));
    const csv  = [headers, ...rows].map(r => r.join(',')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = `WealthDash_PMS_AIF_${new Date().toISOString().slice(0,10)}.csv`;
    a.click(); URL.revokeObjectURL(a.href);
  },
};

window.PmsTracker = PmsTracker;
