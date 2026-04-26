/**
 * WealthDash — FD Rate Tracker Frontend (t421)
 * Handles: Rate Grid, Compare My FDs, Opportunities
 */

'use strict';

const RateTracker = (() => {

  // ── State ──────────────────────────────────────────────
  let _gridData        = null;   // cached rate grid
  let _compareData     = null;   // cached compare
  let _oppData         = null;   // cached opportunities
  let _activeSubTab    = 'grid';
  let _initialized     = false;

  const $  = id => document.getElementById(id);
  const inr = n => '₹' + Number(Math.round(n)).toLocaleString('en-IN');
  const pct = n => Number(n).toFixed(2) + '%';

  // ── Bank type labels + colors ─────────────────────────
  const TYPE_LABELS = {
    small_finance : { label: 'Small Finance', color: '#7c3aed', bg: '#ede9fe' },
    private       : { label: 'Private',       color: '#0369a1', bg: '#e0f2fe' },
    private_large : { label: 'Large Private', color: '#0f766e', bg: '#ccfbf1' },
    public        : { label: 'PSU',           color: '#b45309', bg: '#fef3c7' },
    government    : { label: 'Govt.',         color: '#15803d', bg: '#dcfce7' },
    cooperative   : { label: 'Co-op',         color: '#6b7280', bg: '#f3f4f6' },
  };

  function typeBadge(type) {
    const t = TYPE_LABELS[type] || { label: type, color: '#6b7280', bg: '#f3f4f6' };
    return `<span style="background:${t.bg};color:${t.color};border-radius:99px;font-size:10px;font-weight:700;padding:1px 6px">${t.label}</span>`;
  }

  // ── Init ──────────────────────────────────────────────
  async function init() {
    if (_initialized) return;
    _initialized = true;
    await loadGrid();
  }

  // ── Sub-tab switch ─────────────────────────────────────
  function subTab(name) {
    _activeSubTab = name;
    ['grid', 'compare', 'opp'].forEach(t => {
      const panel = $(`rtPanel${t.charAt(0).toUpperCase() + t.slice(1)}`);
      const btn   = $(`rtSub${t.charAt(0).toUpperCase() + t.slice(1)}`);
      if (panel) panel.style.display = (t === name || (t === 'opp' && name === 'opportunities')) ? '' : 'none';
      if (btn) {
        const isActive = t === name || (t === 'opp' && name === 'opportunities');
        btn.style.borderBottom = isActive ? '2px solid var(--primary)' : '2px solid transparent';
        btn.style.color        = isActive ? 'var(--primary)' : 'var(--text-muted)';
      }
    });
    // Fix: sub-tab IDs
    const panelMap = { grid: 'rtPanelGrid', compare: 'rtPanelCompare', opportunities: 'rtPanelOpp' };
    const btnMap   = { grid: 'rtSubGrid', compare: 'rtSubCompare', opportunities: 'rtSubOpp' };
    Object.keys(panelMap).forEach(t => {
      const p = $(panelMap[t]); const b = $(btnMap[t]);
      if (p) p.style.display = t === name ? '' : 'none';
      if (b) {
        b.style.borderBottom = t === name ? '2px solid var(--primary)' : '2px solid transparent';
        b.style.color        = t === name ? 'var(--primary)' : 'var(--text-muted)';
      }
    });
    if (name === 'compare'      && !_compareData) loadCompare();
    if (name === 'opportunities'&& !_oppData)     loadOpportunities();
  }

  // ═══════════════════════════════════════════════════
  // RATE GRID
  // ═══════════════════════════════════════════════════
  async function loadGrid() {
    try {
      const res = await fetch('/api/fd/fd_rates.php?action=rate_grid');
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Failed');
      _gridData = data;
      renderGrid(data);
      renderSummaryBar(data);
      if ($('rtLastUpdated')) $('rtLastUpdated').textContent = `Updated: ${data.last_updated}`;
    } catch (e) {
      if ($('rtGridBody')) $('rtGridBody').innerHTML =
        `<tr><td colspan="10" style="padding:24px;text-align:center;color:var(--danger)">Failed to load rates: ${e.message}</td></tr>`;
    }
  }

  function renderGrid(data) {
    const filterType = $('rtFilterType')?.value || '';
    const isSenior   = $('rtSeniorToggle')?.checked || false;
    const rateKey    = isSenior ? 'senior' : 'regular';

    const tenures = data.tenures || [3,6,9,12,18,24,36,60];
    const banks   = (data.banks || []).filter(b => !filterType || b.bank_type === filterType);
    const best    = data.best_per_tenure || {};

    // ── Header ──────────────────────────────────────────
    const thead = $('rtGridHead');
    if (thead) {
      thead.innerHTML = `<tr style="background:var(--bg-secondary)">
        <th style="padding:8px 12px;text-align:left;font-weight:700;position:sticky;left:0;background:var(--bg-secondary);z-index:2;white-space:nowrap">Bank</th>
        <th style="padding:8px 8px;text-align:left;font-weight:700;white-space:nowrap">Type</th>
        ${tenures.map(t => `<th style="padding:8px 12px;text-align:center;font-weight:700;white-space:nowrap">${tenureLabel(t)}</th>`).join('')}
      </tr>`;
    }

    // ── Body ────────────────────────────────────────────
    const tbody = $('rtGridBody');
    if (!tbody) return;

    if (!banks.length) {
      tbody.innerHTML = `<tr><td colspan="${tenures.length + 2}" style="padding:32px;text-align:center;color:var(--text-muted)">No banks found for this filter.</td></tr>`;
      return;
    }

    // Group by type
    const groups = {};
    banks.forEach(b => {
      const g = b.bank_type;
      if (!groups[g]) groups[g] = [];
      groups[g].push(b);
    });

    const typeOrder = ['government','public','private_large','private','small_finance','cooperative'];

    let html = '';
    typeOrder.forEach(type => {
      if (!groups[type]) return;
      const tLabel = TYPE_LABELS[type]?.label || type;
      html += `<tr style="background:var(--bg-secondary)">
        <td colspan="${tenures.length + 2}" style="padding:6px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;border-top:1px solid var(--border)">${tLabel}</td>
      </tr>`;

      groups[type].forEach(bank => {
        html += `<tr style="border-top:1px solid var(--border)">
          <td style="padding:8px 12px;font-weight:600;position:sticky;left:0;background:var(--surface,#fff);z-index:1;white-space:nowrap">${escHtml(bank.bank_name)}</td>
          <td style="padding:8px 8px">${typeBadge(bank.bank_type)}</td>
          ${tenures.map(t => {
            const r = bank.rates?.[t];
            if (!r) return `<td style="padding:8px 12px;text-align:center;color:var(--text-muted)">—</td>`;
            const rate    = r[rateKey] || r.regular;
            const isBest  = Math.abs(rate - (best[t] || 0)) < 0.01;
            const cellBg  = isBest ? 'background:#d1fae5;font-weight:700;' : '';
            const special = r.is_special ? '⭐' : '';
            return `<td style="padding:8px 12px;text-align:center;${cellBg}white-space:nowrap">
              ${isBest ? '🏆 ' : ''}${special}${rate.toFixed(2)}%
            </td>`;
          }).join('')}
        </tr>`;
      });
    });

    tbody.innerHTML = html;
  }

  function filterGrid() {
    if (_gridData) renderGrid(_gridData);
  }

  // ═══════════════════════════════════════════════════
  // SUMMARY BAR
  // ═══════════════════════════════════════════════════
  function renderSummaryBar(data) {
    const el = $('rateTrackerSummary');
    if (!el || !data.banks) return;

    let maxRate = 0, maxBank = '', maxTenure = '';
    data.banks.forEach(b => {
      Object.keys(b.rates || {}).forEach(t => {
        const r = b.rates[t].regular;
        if (r > maxRate) { maxRate = r; maxBank = b.bank_name; maxTenure = tenureLabel(+t); }
      });
    });

    const bankCount  = data.banks.length;
    const sfCount    = data.banks.filter(b => b.bank_type === 'small_finance').length;
    const avgRate12  = calcAvgRate(data, 12);

    el.innerHTML = `
      <div class="stat-card">
        <div class="stat-label">Banks Tracked</div>
        <div class="stat-value">${bankCount}</div>
        <div class="stat-sub" style="font-size:11px;color:var(--text-muted);margin-top:2px">${sfCount} Small Finance</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Highest Rate</div>
        <div class="stat-value text-success">${maxRate.toFixed(2)}%</div>
        <div class="stat-sub" style="font-size:11px;color:var(--text-muted);margin-top:2px">${escHtml(maxBank)} · ${maxTenure}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Avg. Rate (12M)</div>
        <div class="stat-value">${avgRate12.toFixed(2)}%</div>
        <div class="stat-sub" style="font-size:11px;color:var(--text-muted);margin-top:2px">All bank types</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Last Updated</div>
        <div class="stat-value" style="font-size:16px">${data.last_updated || '—'}</div>
        <div class="stat-sub" style="font-size:11px;color:var(--text-muted);margin-top:2px">Admin can update rates</div>
      </div>
    `;
  }

  function calcAvgRate(data, tenure) {
    let sum = 0, count = 0;
    (data.banks || []).forEach(b => {
      const r = b.rates?.[tenure]?.regular;
      if (r) { sum += r; count++; }
    });
    return count ? sum / count : 0;
  }

  // ═══════════════════════════════════════════════════
  // COMPARE MY FDs
  // ═══════════════════════════════════════════════════
  async function loadCompare() {
    const el = $('rtCompareBody');
    if (!el) return;
    el.innerHTML = `<div style="text-align:center;padding:32px;color:var(--text-muted)"><span class="spinner"></span> Comparing…</div>`;

    try {
      const res = await fetch('/api/fd/fd_rates.php?action=compare');
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Failed');
      _compareData = data;
      renderCompare(data);
    } catch (e) {
      el.innerHTML = `<p style="color:var(--danger);padding:16px">${e.message}</p>`;
    }
  }

  function renderCompare(data) {
    const el = $('rtCompareBody');
    if (!el) return;
    const list = data.comparisons || [];

    if (!list.length) {
      el.innerHTML = `<div style="text-align:center;padding:48px;color:var(--text-muted)">
        <div style="font-size:40px;margin-bottom:8px">💼</div>
        <p>Add active FDs to compare your rates with the market.</p>
      </div>`;
      return;
    }

    const { summary } = data;
    const oppCost = summary?.opportunity_cost_annual || 0;

    let html = '';

    // Summary chips
    if (oppCost > 0) {
      html += `<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:12px">
        <span style="font-size:24px">⚠️</span>
        <div>
          <strong style="color:#b91c1c">Annual Opportunity Cost: ${inr(oppCost)}</strong>
          <p style="margin:2px 0 0;font-size:12px;color:#7f1d1d">
            ${summary.suboptimal_fds} of ${summary.total_fds} FDs are below best available market rates.
            Over 5 years, you could earn ${inr(summary?.opportunity_cost_5yr || oppCost * 5)} more.
          </p>
        </div>
      </div>`;
    } else {
      html += `<div style="background:#f0fdf4;border:1px solid #6ee7b7;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:12px">
        <span style="font-size:24px">✅</span>
        <p style="margin:0;font-size:13px;color:#065f46"><strong>All your FDs are at competitive market rates!</strong></p>
      </div>`;
    }

    // Comparison rows
    html += `<div class="table-responsive">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
          <tr style="background:var(--bg-secondary)">
            <th style="padding:8px 12px;text-align:left;font-weight:700">Your FD</th>
            <th style="padding:8px 12px;text-align:center;font-weight:700">Tenure</th>
            <th style="padding:8px 12px;text-align:right;font-weight:700">Your Rate</th>
            <th style="padding:8px 12px;text-align:right;font-weight:700">Best Market</th>
            <th style="padding:8px 12px;text-align:right;font-weight:700">Gap</th>
            <th style="padding:8px 12px;text-align:right;font-weight:700">Annual Loss</th>
            <th style="padding:8px 12px;text-align:center;font-weight:700">Status</th>
          </tr>
        </thead>
        <tbody>`;

    list.forEach(c => {
      const statusBadge = {
        optimal:    `<span style="background:#d1fae5;color:#065f46;border-radius:99px;padding:2px 10px;font-size:11px;font-weight:700">✓ Optimal</span>`,
        acceptable: `<span style="background:#fef3c7;color:#92400e;border-radius:99px;padding:2px 10px;font-size:11px;font-weight:700">~ Acceptable</span>`,
        suboptimal: `<span style="background:#fee2e2;color:#b91c1c;border-radius:99px;padding:2px 10px;font-size:11px;font-weight:700">⚠ Suboptimal</span>`,
        poor:       `<span style="background:#dc2626;color:#fff;border-radius:99px;padding:2px 10px;font-size:11px;font-weight:700">🔴 Poor</span>`,
      }[c.status] || '';

      const gapColor = c.gap_pct > 0 ? '#dc2626' : '#059669';
      const gapSign  = c.gap_pct > 0 ? '+' : '';

      html += `<tr style="border-top:1px solid var(--border)">
        <td style="padding:8px 12px">
          <strong>${escHtml(c.bank_name)}</strong>
          <div style="font-size:11px;color:var(--text-muted)">${escHtml(c.principal > 0 ? inr(c.principal) : '')}</div>
        </td>
        <td style="padding:8px 12px;text-align:center;color:var(--text-muted)">${c.tenure_label}</td>
        <td style="padding:8px 12px;text-align:right;font-weight:700">${pct(c.your_rate)}</td>
        <td style="padding:8px 12px;text-align:right">
          <span style="font-weight:700;color:var(--primary)">${pct(c.best_rate)}</span>
          <div style="font-size:11px;color:var(--text-muted)">${escHtml(c.best_bank)}</div>
        </td>
        <td style="padding:8px 12px;text-align:right;font-weight:700;color:${gapColor}">${gapSign}${pct(c.gap_pct)}</td>
        <td style="padding:8px 12px;text-align:right;color:${c.annual_opportunity_cost > 0 ? '#dc2626' : 'var(--text-muted)'};font-weight:600">
          ${c.annual_opportunity_cost > 0 ? inr(c.annual_opportunity_cost) : '—'}
        </td>
        <td style="padding:8px 12px;text-align:center">${statusBadge}</td>
      </tr>`;
    });

    html += `</tbody></table></div>`;
    el.innerHTML = html;
  }

  // ═══════════════════════════════════════════════════
  // OPPORTUNITIES
  // ═══════════════════════════════════════════════════
  async function loadOpportunities() {
    const el = $('rtOppBody');
    if (!el) return;
    el.innerHTML = `<div style="text-align:center;padding:32px;color:var(--text-muted)"><span class="spinner"></span> Scanning for opportunities…</div>`;

    try {
      const res = await fetch('/api/fd/fd_rates.php?action=opportunities');
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Failed');
      _oppData = data;
      renderOpportunities(data);
      // Update badge
      const badge = $('rtOppBadge');
      if (badge) {
        const n = data.total_opportunity_count || 0;
        badge.textContent = n;
        badge.style.display = n > 0 ? 'inline' : 'none';
      }
    } catch (e) {
      el.innerHTML = `<p style="color:var(--danger);padding:16px">${e.message}</p>`;
    }
  }

  function renderOpportunities(data) {
    const el = $('rtOppBody');
    if (!el) return;
    const ops = data.opportunities || [];

    if (!ops.length) {
      el.innerHTML = `<div style="text-align:center;padding:48px;color:var(--text-muted)">
        <div style="font-size:48px;margin-bottom:12px">🎉</div>
        <h3 style="font-weight:700;color:var(--text-primary)">All Good!</h3>
        <p>Your FDs are at competitive market rates. No significant opportunities found.</p>
      </div>`;
      return;
    }

    const totalAnnual = data.total_opportunity_annual || 0;

    let html = `<div style="background:linear-gradient(135deg,#7c3aed10,#4f46e510);border:1px solid #7c3aed30;border-radius:12px;padding:16px 20px;margin-bottom:20px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div>
          <div style="font-weight:700;font-size:16px;color:var(--text-primary)">
            💡 ${data.total_opportunity_count} Renewal Opportunities Found
          </div>
          <div style="font-size:13px;color:var(--text-muted);margin-top:2px">
            You could earn <strong style="color:#7c3aed">${inr(totalAnnual)} more per year</strong> by switching at maturity
          </div>
        </div>
        <div style="font-size:12px;color:var(--text-muted)">Sorted by annual gain potential</div>
      </div>
    </div>`;

    html += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">`;

    ops.forEach(op => {
      const urgency = op.days_to_maturity <= 30
        ? { color: '#dc2626', bg: '#fee2e2', label: '🔴 Maturing Soon', border: '#fca5a5' }
        : op.days_to_maturity <= 90
          ? { color: '#d97706', bg: '#fef3c7', label: '🟡 Maturing in 90d', border: '#fcd34d' }
          : { color: '#4f46e5', bg: '#ede9fe', label: '🔵 At Next Maturity', border: '#a5b4fc' };

      html += `<div style="border:1px solid ${urgency.border};border-radius:12px;overflow:hidden;background:var(--surface,#fff)">
        <div style="background:${urgency.bg};padding:10px 14px;display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:11px;font-weight:700;color:${urgency.color}">${urgency.label}</span>
          <span style="font-size:11px;color:${urgency.color}">${op.days_to_maturity > 0 ? op.days_to_maturity + 'd' : 'Today!'}</span>
        </div>
        <div style="padding:14px">
          <div style="font-weight:700;font-size:15px;color:var(--text-primary);margin-bottom:8px">${escHtml(op.bank_name)}</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
            <div>
              <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;font-weight:700">Your Rate</div>
              <div style="font-size:18px;font-weight:700;color:var(--text-primary)">${pct(op.your_rate)}</div>
            </div>
            <div>
              <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;font-weight:700">Best Market</div>
              <div style="font-size:18px;font-weight:700;color:#7c3aed">${pct(op.best_rate)}</div>
              <div style="font-size:11px;color:var(--text-muted)">${escHtml(op.best_bank)}</div>
            </div>
          </div>
          <div style="background:var(--surface-hover,#f9fafb);border-radius:8px;padding:10px;margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
              <span>Gap:</span>
              <strong style="color:#dc2626">+${pct(op.gap_pct)} p.a.</strong>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px">
              <span>Annual gain:</span>
              <strong style="color:#059669">${inr(op.annual_opportunity_cost)}</strong>
            </div>
          </div>
          <div style="font-size:12px;color:var(--text-muted);line-height:1.5">${op.action_text}</div>
        </div>
      </div>`;
    });

    html += `</div>`;
    el.innerHTML = html;
  }

  // ═══════════════════════════════════════════════════
  // HELPERS
  // ═══════════════════════════════════════════════════
  function tenureLabel(m) {
    if (m < 12) return `${m}M`;
    if (m % 12 === 0) return `${m/12}Y`;
    return `${Math.floor(m/12)}Y${m%12}M`;
  }

  function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  return { init, subTab, filterGrid };
})();
