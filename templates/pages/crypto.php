<?php
/**
 * WealthDash — Crypto Holdings Page (t315)
 * Full portfolio tracking: holdings + live P&L + staking + watchlist + VDA tax
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser   = require_auth();
$pageTitle     = 'Crypto Holdings';
$activePage    = 'crypto';
$activeSection = 'crypto';

ob_start();
?>
<style>
/* ── t315 Crypto Page Styles ──────────────────────────────── */
.crypto-stat-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px}
.crypto-stat{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e5e7eb);border-radius:14px;padding:18px 20px;position:relative;overflow:hidden}
.crypto-stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent-color,#6366f1)}
.crypto-stat.green::before{background:#10b981}
.crypto-stat.red::before{background:#ef4444}
.crypto-stat.amber::before{background:#f59e0b}
.crypto-stat .label{font-size:11px;color:var(--text-muted,#6b7280);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.crypto-stat .value{font-size:22px;font-weight:700;color:var(--text-primary,#111827);line-height:1}
.crypto-stat .sub{font-size:12px;color:var(--text-muted,#6b7280);margin-top:4px}

.crypto-tabs{display:flex;gap:2px;background:var(--surface-2,#f3f4f6);border-radius:10px;padding:3px;margin-bottom:20px;flex-wrap:wrap}
.crypto-tab{padding:7px 16px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;color:var(--text-muted,#6b7280);border:none;background:transparent;transition:.15s}
.crypto-tab.active{background:var(--card-bg,#fff);color:var(--text-primary,#111827);box-shadow:0 1px 3px rgba(0,0,0,.08)}

.crypto-table-wrap{overflow-x:auto;border-radius:12px;border:1px solid var(--border-color,#e5e7eb)}
.crypto-table{width:100%;border-collapse:collapse;font-size:13px}
.crypto-table th{background:var(--surface-2,#f9fafb);padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--text-muted,#6b7280);text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.crypto-table td{padding:12px 14px;border-top:1px solid var(--border-color,#f3f4f6);vertical-align:middle}
.crypto-table tr:hover td{background:var(--surface-hover,#fafafa)}
.coin-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0}
.badge-cat{display:inline-block;padding:2px 7px;border-radius:99px;font-size:11px;font-weight:600;background:#ede9fe;color:#7c3aed}
.badge-cat.Bitcoin{background:#fef3c7;color:#d97706}
.badge-cat.Ethereum{background:#dbeafe;color:#2563eb}
.badge-cat.Stablecoin{background:#dcfce7;color:#16a34a}
.badge-cat.DeFi{background:#fce7f3;color:#be185d}
.pct-pill{display:inline-block;padding:2px 8px;border-radius:99px;font-size:12px;font-weight:600}
.pct-pill.pos{background:#dcfce7;color:#15803d}
.pct-pill.neg{background:#fee2e2;color:#dc2626}
.pct-pill.neu{background:#f3f4f6;color:#6b7280}

.alloc-bar-wrap{width:120px;background:var(--border-color,#e5e7eb);border-radius:99px;height:6px;overflow:hidden}
.alloc-bar{height:6px;border-radius:99px;background:linear-gradient(90deg,#6366f1,#8b5cf6)}

.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.section-header h3{font-size:15px;font-weight:700;color:var(--text-primary,#111827);margin:0}

.breakdown-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:22px}
.breakdown-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e5e7eb);border-radius:12px;padding:14px 16px}
.breakdown-card .bc-label{font-size:11px;color:var(--text-muted,#6b7280);font-weight:600;text-transform:uppercase;margin-bottom:6px}
.breakdown-card .bc-val{font-size:17px;font-weight:700;color:var(--text-primary,#111827)}
.breakdown-card .bc-sub{font-size:12px;color:var(--text-muted,#6b7280);margin-top:2px}

.tax-banner{background:linear-gradient(135deg,#fef3c7,#fffbeb);border:1.5px solid #fcd34d;border-radius:12px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;font-size:12px;color:#78350f;line-height:1.6}

.btn-sm{padding:5px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:.15s}
.btn-primary{background:#6366f1;color:#fff}
.btn-primary:hover{background:#4f46e5}
.btn-outline{background:transparent;border:1px solid var(--border-color,#d1d5db);color:var(--text-primary,#374151)}
.btn-outline:hover{background:var(--surface-2,#f9fafb)}
.btn-danger{background:transparent;border:1px solid #fecaca;color:#dc2626}
.btn-danger:hover{background:#fef2f2}

.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px}
.modal-box{background:var(--card-bg,#fff);border-radius:16px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.modal-head{padding:20px 24px 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h3{font-size:16px;font-weight:700;margin:0}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted,#6b7280);line-height:1;padding:0}
.modal-body{padding:20px 24px 24px}
.form-row{margin-bottom:14px}
.form-row label{display:block;font-size:12px;font-weight:600;color:var(--text-muted,#6b7280);margin-bottom:5px;text-transform:uppercase}
.form-row input,.form-row select,.form-row textarea{width:100%;padding:9px 12px;border:1px solid var(--border-color,#d1d5db);border-radius:8px;font-size:13px;color:var(--text-primary,#111827);background:var(--card-bg,#fff);box-sizing:border-box}
.form-row input:focus,.form-row select:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}

.empty-state{text-align:center;padding:48px 20px;color:var(--text-muted,#6b7280)}
.empty-state .icon{font-size:48px;margin-bottom:12px}
.empty-state p{font-size:14px;margin:0 0 16px}

.staking-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600}
.staking-badge.STAKING{background:#ede9fe;color:#7c3aed}
.staking-badge.YIELD{background:#dbeafe;color:#1d4ed8}
.staking-badge.AIRDROP{background:#fce7f3;color:#be185d}
.staking-badge.MINING{background:#fef3c7;color:#d97706}
.staking-badge.INTEREST{background:#dcfce7;color:#15803d}

.wl-alert-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;background:#fee2e2;color:#dc2626;animation:pulse .8s infinite alternate}
@keyframes pulse{from{opacity:.7}to{opacity:1}}

.live-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#10b981;margin-right:5px;animation:blink 1.5s ease-in-out infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}

#cryptoLoadSpinner{text-align:center;padding:60px;color:var(--text-muted,#6b7280);font-size:14px}
</style>

<!-- Tax notice -->
<div class="tax-banner">
  <span style="font-size:20px;flex-shrink:0">⚠️</span>
  <div>
    <strong>Indian Tax Rules (Budget 2022):</strong>
    Crypto gains par <strong>30% flat tax</strong> (Section 115BBH) — koi deduction allowed nahi.
    Sell par <strong>1% TDS</strong> katega (Section 194S).
    Losses kisi bhi income se offset NAHI ho sakte.
  </div>
</div>

<!-- Page header -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <div>
    <h1 style="margin:0;font-size:22px;font-weight:800">₿ Crypto Portfolio</h1>
    <p style="margin:4px 0 0;font-size:12px;color:var(--text-muted,#6b7280)">
      <span class="live-dot"></span>Live via CoinGecko · 30% VDA tax tracking · P&amp;L
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button class="btn-sm btn-outline" onclick="CryptoApp.refreshPrices()">🔄 Refresh Prices</button>
    <button class="btn-sm btn-primary" onclick="CryptoApp.openAddModal()">+ Add Holding</button>
  </div>
</div>

<!-- Stat cards -->
<div class="crypto-stat-cards" id="cryptoStatCards">
  <div class="crypto-stat"><div class="label">Total Invested</div><div class="value" id="cs-invested">—</div><div class="sub">Cost basis (INR)</div></div>
  <div class="crypto-stat"><div class="label">Current Value</div><div class="value" id="cs-current">—</div><div class="sub">Live prices</div></div>
  <div class="crypto-stat green"><div class="label">Total Gain/Loss</div><div class="value" id="cs-gain">—</div><div class="sub" id="cs-gain-pct">—</div></div>
  <div class="crypto-stat amber"><div class="label">Staking Income</div><div class="value" id="cs-staking">—</div><div class="sub">All time</div></div>
  <div class="crypto-stat"><div class="label">Coins Held</div><div class="value" id="cs-coins">—</div><div class="sub" id="cs-txns">— transactions</div></div>
</div>

<!-- Tabs -->
<div class="crypto-tabs">
  <button class="crypto-tab active" onclick="CryptoApp.setTab('holdings',this)">📊 Holdings</button>
  <button class="crypto-tab" onclick="CryptoApp.setTab('breakdown',this)">🗂 Breakdown</button>
  <button class="crypto-tab" onclick="CryptoApp.setTab('transactions',this)">📋 Transactions</button>
  <button class="crypto-tab" onclick="CryptoApp.setTab('staking',this)">💎 Staking / Yield</button>
  <button class="crypto-tab" onclick="CryptoApp.setTab('watchlist',this)">👁 Watchlist</button>
  <button class="crypto-tab" onclick="CryptoApp.setTab('tax',this)">📄 VDA Tax</button>
</div>

<!-- Tab content -->
<div id="cryptoTabContent">
  <div id="cryptoLoadSpinner">Loading portfolio… <span class="live-dot"></span></div>
</div>

<!-- Modals container -->
<div id="cryptoModals"></div>

<script>
/* ═══════════════════════════════════════════════════════════
   t315 — CryptoApp  (vanilla JS SPA)
   ═══════════════════════════════════════════════════════════ */
const CryptoApp = (() => {
  'use strict';
  const API = (p) => `/api/router.php?${p}`;
  const fmt = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', {maximumFractionDigits:2});
  const fmtN = (n, d=4) => Number(n || 0).toLocaleString('en-IN', {maximumFractionDigits:d});
  const fmtPct = (n) => (n >= 0 ? '+' : '') + Number(n).toFixed(2) + '%';
  const pillClass = (n) => n > 0 ? 'pos' : n < 0 ? 'neg' : 'neu';
  const esc = (s) => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

  let state = {
    tab:       'holdings',
    stats:     null,
    txns:      null,
    staking:   null,
    watchlist: null,
    tax:       null,
    loading:   false,
  };

  // ── Bootstrap ───────────────────────────────────────────────
  async function init() {
    await loadStats();
  }

  // ── API helpers ─────────────────────────────────────────────
  async function get(action, extra='') {
    const r = await fetch(API(`action=${action}${extra}`));
    return r.json();
  }
  async function post(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k,v] of Object.entries(data)) fd.append(k, v ?? '');
    const r = await fetch(API(''), {method:'POST', body:fd});
    return r.json();
  }

  // ── Load portfolio stats ─────────────────────────────────────
  async function loadStats() {
    setContent('<div id="cryptoLoadSpinner">Loading portfolio… <span class="live-dot"></span></div>');
    const res = await get('crypto_portfolio_stats');
    if (!res.success) { setContent(`<div class="empty-state"><div class="icon">⚠️</div><p>${esc(res.message)}</p></div>`); return; }

    state.stats = res;
    renderStatCards(res.summary);
    renderTab();
  }

  function renderStatCards(s) {
    const gainClass = s.total_gain >= 0 ? 'green' : 'red';
    document.getElementById('cs-invested').textContent = fmt(s.total_invested);
    document.getElementById('cs-current').textContent  = fmt(s.total_current);
    document.getElementById('cs-gain').textContent     = fmt(s.total_gain);
    document.getElementById('cs-gain-pct').textContent = fmtPct(s.total_gain_pct);
    document.getElementById('cs-staking').textContent  = fmt(s.staking_income);
    document.getElementById('cs-coins').textContent    = s.coin_count;
    document.getElementById('cs-txns').textContent     = s.txn_count + ' transactions';

    const card = document.querySelector('.crypto-stat.green, .crypto-stat.red');
    if (card) { card.classList.remove('green','red'); card.classList.add(gainClass); }
  }

  // ── Tab management ───────────────────────────────────────────
  function setTab(tab, btn) {
    state.tab = tab;
    document.querySelectorAll('.crypto-tab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    renderTab();
  }

  function renderTab() {
    switch (state.tab) {
      case 'holdings':     renderHoldings(); break;
      case 'breakdown':    renderBreakdown(); break;
      case 'transactions': loadTxns(); break;
      case 'staking':      loadStaking(); break;
      case 'watchlist':    loadWatchlist(); break;
      case 'tax':          loadTax(); break;
    }
  }

  // ── HOLDINGS tab ────────────────────────────────────────────
  function renderHoldings() {
    if (!state.stats) { loadStats(); return; }
    const holdings = state.stats.holdings || [];

    if (!holdings.length) {
      setContent(`<div class="empty-state">
        <div class="icon">₿</div>
        <p>No crypto holdings yet. Add your first coin!</p>
        <button class="btn-sm btn-primary" onclick="CryptoApp.openAddModal()">+ Add Holding</button>
      </div>`);
      return;
    }

    const rows = holdings.map(h => `
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <div class="coin-avatar">${esc(h.coin_symbol.substring(0,3))}</div>
            <div>
              <div style="font-weight:600;font-size:13px">${esc(h.coin_name)}</div>
              <div style="font-size:11px;color:var(--text-muted,#6b7280)">${esc(h.coin_symbol)}</div>
            </div>
          </div>
        </td>
        <td><span class="badge-cat ${esc(h.category)}">${esc(h.category)}</span></td>
        <td style="font-variant-numeric:tabular-nums">${fmtN(h.quantity, 6)}</td>
        <td>${h.price_inr > 0 ? fmt(h.price_inr) : '<span style="color:var(--text-muted)">—</span>'}</td>
        <td>${h.change_24h != null
          ? `<span class="pct-pill ${pillClass(h.change_24h)}">${fmtPct(h.change_24h)}</span>`
          : '—'}</td>
        <td style="font-weight:600">${fmt(h.current_value)}</td>
        <td>${fmt(h.total_invested)}</td>
        <td>
          <span class="pct-pill ${pillClass(h.gain_inr)}">${fmt(h.gain_inr)}</span>
          <div style="font-size:11px;color:var(--text-muted,#6b7280);margin-top:2px">${fmtPct(h.gain_pct)}</div>
        </td>
        <td>
          <div class="alloc-bar-wrap">
            <div class="alloc-bar" style="width:${Math.min(h.allocation_pct,100)}%"></div>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px">${h.allocation_pct}%</div>
        </td>
        <td>${esc(h.exchange || '—')}</td>
        <td>
          <button class="btn-sm btn-outline" style="margin-right:4px" onclick="CryptoApp.openEditModal(${JSON.stringify(h).replace(/"/g,'&quot;')})">Edit</button>
          <button class="btn-sm btn-outline" onclick="CryptoApp.openTxnModal('${esc(h.coin_id)}','${esc(h.coin_symbol)}')">+Txn</button>
        </td>
      </tr>`).join('');

    setContent(`
      <div class="section-header">
        <h3>Holdings (${holdings.length} coins)</h3>
        <button class="btn-sm btn-primary" onclick="CryptoApp.openAddModal()">+ Add Coin</button>
      </div>
      <div class="crypto-table-wrap">
        <table class="crypto-table">
          <thead>
            <tr>
              <th>Coin</th><th>Category</th><th>Qty</th><th>Price (INR)</th>
              <th>24h</th><th>Current Value</th><th>Invested</th>
              <th>Gain/Loss</th><th>Allocation</th><th>Exchange</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`);
  }

  // ── BREAKDOWN tab ────────────────────────────────────────────
  function renderBreakdown() {
    if (!state.stats) { loadStats(); return; }
    const byExch = state.stats.by_exchange || [];
    const byCat  = state.stats.by_category || [];

    const exchCards = byExch.length
      ? byExch.map(e => `
        <div class="breakdown-card">
          <div class="bc-label">🏦 ${esc(e.exchange)}</div>
          <div class="bc-val">${fmt(e.current)}</div>
          <div class="bc-sub">Invested: ${fmt(e.invested)} · ${e.coins} coin${e.coins>1?'s':''}</div>
          <div class="bc-sub" style="margin-top:4px">
            <span class="pct-pill ${pillClass(e.gain)}">${fmtPct(e.gain_pct)}</span>
            &nbsp;${e.alloc_pct}% of portfolio
          </div>
        </div>`).join('')
      : '<p style="color:var(--text-muted);font-size:13px">No exchange data</p>';

    const catCards = byCat.length
      ? byCat.map(c => `
        <div class="breakdown-card">
          <div class="bc-label">${esc(c.category)}</div>
          <div class="bc-val">${fmt(c.current)}</div>
          <div class="bc-sub">Invested: ${fmt(c.invested)} · ${c.coins} coin${c.coins>1?'s':''}</div>
          <div class="bc-sub" style="margin-top:4px">
            <span class="pct-pill ${pillClass(c.gain)}">${fmtPct(c.gain_pct)}</span>
            &nbsp;${c.alloc_pct}% of portfolio
          </div>
        </div>`).join('')
      : '<p style="color:var(--text-muted);font-size:13px">No category data</p>';

    setContent(`
      <div class="section-header"><h3>By Exchange</h3></div>
      <div class="breakdown-grid">${exchCards}</div>
      <div class="section-header"><h3>By Category</h3></div>
      <div class="breakdown-grid">${catCards}</div>`);
  }

  // ── TRANSACTIONS tab ─────────────────────────────────────────
  async function loadTxns(coinId='') {
    setContent('<div id="cryptoLoadSpinner">Loading transactions…</div>');
    const extra = coinId ? `&coin_id=${encodeURIComponent(coinId)}` : '';
    const res = await get('crypto_txns', extra);
    if (!res.success) { setContent(`<div class="empty-state"><p>${esc(res.message)}</p></div>`); return; }
    state.txns = res;

    const rows = res.map ? res.map(t => `
      <tr>
        <td>${esc(t.txn_date)}</td>
        <td><strong>${esc(t.coin_symbol)}</strong></td>
        <td><span class="staking-badge ${esc(t.txn_type)}" style="${t.txn_type==='SELL'?'background:#fee2e2;color:#dc2626':t.txn_type==='BUY'?'background:#dcfce7;color:#15803d':'background:#f3f4f6;color:#374151'}">${esc(t.txn_type)}</span></td>
        <td>${fmtN(t.quantity, 6)}</td>
        <td>${fmt(t.price_inr)}</td>
        <td>${fmt(t.amount_inr)}</td>
        <td>${t.tds_deducted > 0 ? fmt(t.tds_deducted) : '—'}</td>
        <td>${esc(t.exchange || '—')}</td>
        <td style="font-size:11px;color:var(--text-muted)">${esc(t.notes || '')}</td>
      </tr>`).join('') : '';

    const count = Array.isArray(res) ? res.length : 0;

    setContent(`
      <div class="section-header">
        <h3>Transaction History (${count})</h3>
        <button class="btn-sm btn-primary" onclick="CryptoApp.openTxnModal()">+ Add Transaction</button>
      </div>
      ${count === 0
        ? '<div class="empty-state"><div class="icon">📋</div><p>No transactions yet.</p></div>'
        : `<div class="crypto-table-wrap"><table class="crypto-table">
            <thead><tr><th>Date</th><th>Coin</th><th>Type</th><th>Qty</th><th>Price</th><th>Amount</th><th>TDS</th><th>Exchange</th><th>Notes</th></tr></thead>
            <tbody>${rows}</tbody>
          </table></div>`}`);
  }

  // ── STAKING tab ──────────────────────────────────────────────
  async function loadStaking() {
    setContent('<div id="cryptoLoadSpinner">Loading staking rewards…</div>');
    const res = await get('crypto_staking_list');
    if (!res.success) { setContent(`<div class="empty-state"><p>${esc(res.message)}</p></div>`); return; }
    state.staking = res;

    const rows = (res.rewards || []).map(r => `
      <tr>
        <td>${esc(r.reward_date)}</td>
        <td><strong>${esc(r.coin_symbol)}</strong></td>
        <td><span class="staking-badge ${esc(r.reward_type)}">${esc(r.reward_type)}</span></td>
        <td>${fmtN(r.quantity, 6)}</td>
        <td>${fmt(r.value_inr)}</td>
        <td>${fmt(r.current_value_inr)}</td>
        <td class="pct-pill ${pillClass(r.unrealised_gain)}" style="border:none">${r.unrealised_gain >= 0 ? '+' : ''}${fmt(r.unrealised_gain)}</td>
        <td>${esc(r.platform || '—')}</td>
        <td>
          <button class="btn-sm btn-danger" onclick="CryptoApp.deleteStaking(${r.id})">✕</button>
        </td>
      </tr>`).join('');

    const total = res.total_value_at_receipt || 0;
    const curTotal = res.total_current_value || 0;
    const byType  = res.by_type || [];

    setContent(`
      <div class="section-header">
        <h3>💎 Staking / Yield Rewards</h3>
        <button class="btn-sm btn-primary" onclick="CryptoApp.openStakingModal()">+ Add Reward</button>
      </div>
      <div class="crypto-stat-cards" style="margin-bottom:16px">
        <div class="crypto-stat"><div class="label">Total at Receipt</div><div class="value">${fmt(total)}</div></div>
        <div class="crypto-stat"><div class="label">Current Value</div><div class="value">${fmt(curTotal)}</div></div>
        ${byType.map(b => `<div class="crypto-stat"><div class="label">${esc(b.type)}</div><div class="value">${fmt(b.value_inr)}</div><div class="sub">${b.count} entries</div></div>`).join('')}
      </div>
      ${(res.rewards || []).length === 0
        ? '<div class="empty-state"><div class="icon">💎</div><p>No staking rewards recorded yet.</p></div>'
        : `<div class="crypto-table-wrap"><table class="crypto-table">
            <thead><tr><th>Date</th><th>Coin</th><th>Type</th><th>Qty</th><th>At Receipt</th><th>Current Value</th><th>Unrealised</th><th>Platform</th><th></th></tr></thead>
            <tbody>${rows}</tbody>
          </table></div>`}`);
  }

  // ── WATCHLIST tab ─────────────────────────────────────────────
  async function loadWatchlist() {
    setContent('<div id="cryptoLoadSpinner">Loading watchlist…</div>');
    const res = await get('crypto_wl_list');
    if (!res.success) { setContent(`<div class="empty-state"><p>${esc(res.message)}</p></div>`); return; }
    state.watchlist = res;
    const items = Array.isArray(res) ? res : [];

    const rows = items.map(w => `
      <tr>
        <td><strong>${esc(w.coin_symbol)}</strong> <span style="font-size:11px;color:var(--text-muted)">${esc(w.coin_name)}</span></td>
        <td>${w.price_inr > 0 ? fmt(w.price_inr) : '—'}</td>
        <td>${w.change_24h != null ? `<span class="pct-pill ${pillClass(w.change_24h)}">${fmtPct(w.change_24h)}</span>` : '—'}</td>
        <td>${w.alert_high ? fmt(w.alert_high) : '—'} ${w.alert_high_triggered ? '<span class="wl-alert-badge">🔔 HIT</span>' : ''}</td>
        <td>${w.alert_low  ? fmt(w.alert_low)  : '—'} ${w.alert_low_triggered  ? '<span class="wl-alert-badge">🔔 HIT</span>' : ''}</td>
        <td>${esc(w.notes || '')}</td>
        <td>
          <button class="btn-sm btn-danger" onclick="CryptoApp.deleteWatchlist(${w.id})">✕</button>
        </td>
      </tr>`).join('');

    setContent(`
      <div class="section-header">
        <h3>👁 Watchlist</h3>
        <button class="btn-sm btn-primary" onclick="CryptoApp.openWLModal()">+ Add to Watchlist</button>
      </div>
      ${items.length === 0
        ? '<div class="empty-state"><div class="icon">👁</div><p>No coins on watchlist yet.</p></div>'
        : `<div class="crypto-table-wrap"><table class="crypto-table">
            <thead><tr><th>Coin</th><th>Live Price</th><th>24h</th><th>Alert High</th><th>Alert Low</th><th>Notes</th><th></th></tr></thead>
            <tbody>${rows}</tbody>
          </table></div>`}`);
  }

  // ── VDA TAX tab ───────────────────────────────────────────────
  async function loadTax() {
    setContent('<div id="cryptoLoadSpinner">Loading VDA tax summary…</div>');
    const fy = new Date().getMonth() >= 3 ? new Date().getFullYear() : new Date().getFullYear() - 1;
    const res = await get('crypto_tax_year_summary', `&fy=${fy}`);
    if (!res.success) { setContent(`<div class="empty-state"><p>${esc(res.message)}</p></div>`); return; }
    state.tax = res;

    const breakdown = (res.breakdown || []).map(b => `
      <tr>
        <td>${esc(b.date)}</td>
        <td>${esc(b.coin)}</td>
        <td>${fmtN(b.qty, 6)}</td>
        <td>${fmt(b.sale_value)}</td>
        <td>${fmt(b.cost_basis)}</td>
        <td class="${b.gain >= 0 ? 'pct-pill pos' : 'pct-pill neg'}" style="border:none">${fmt(b.gain)}</td>
        <td style="font-weight:700;color:#ef4444">${fmt(b.tax_30pct)}</td>
        <td>${fmt(b.tds_deducted)}</td>
        <td style="font-weight:700">${fmt(b.net_tax_due)}</td>
      </tr>`).join('');

    setContent(`
      <div class="section-header"><h3>📄 VDA Tax — ${esc(res.fy)}</h3></div>
      <div class="crypto-stat-cards" style="margin-bottom:20px">
        <div class="crypto-stat"><div class="label">Total Sale Value</div><div class="value">${fmt(res.total_sale_value)}</div></div>
        <div class="crypto-stat"><div class="label">Total Cost Basis</div><div class="value">${fmt(res.total_cost_basis)}</div></div>
        <div class="crypto-stat ${res.total_gain>=0?'green':'red'}"><div class="label">Total Gain</div><div class="value">${fmt(res.total_gain)}</div></div>
        <div class="crypto-stat red"><div class="label">Tax @ 30%</div><div class="value">${fmt(res.total_tax_payable)}</div></div>
        <div class="crypto-stat"><div class="label">TDS Deducted</div><div class="value">${fmt(res.total_tds_deducted)}</div></div>
        <div class="crypto-stat amber"><div class="label">Net Tax Due</div><div class="value">${fmt(res.net_tax_due)}</div></div>
      </div>
      <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;font-size:12px;color:#78350f;margin-bottom:16px;line-height:1.6">
        <strong>Staking Income (${esc(res.fy)}):</strong> ${fmt(res.staking_income)} — ${esc(res.staking_tax_note)}<br>
        <strong>Note:</strong> ${esc(res.loss_offset_note)}
      </div>
      ${(res.breakdown || []).length === 0
        ? '<div class="empty-state"><div class="icon">✅</div><p>No sell transactions in this FY — no tax liability.</p></div>'
        : `<div class="crypto-table-wrap"><table class="crypto-table">
            <thead><tr><th>Date</th><th>Coin</th><th>Qty</th><th>Sale Value</th><th>Cost Basis</th><th>Gain</th><th>Tax 30%</th><th>TDS</th><th>Net Due</th></tr></thead>
            <tbody>${breakdown}</tbody>
          </table></div>`}`);
  }

  // ── MODAL: Add Holding ─────────────────────────────────────────
  function openAddModal() {
    showModal('add-holding', `
      <div class="modal-head">
        <h3>+ Add Crypto Holding</h3>
        <button class="modal-close" onclick="CryptoApp.closeModal()">✕</button>
      </div>
      <div class="modal-body">
        <div class="form-row"><label>CoinGecko ID *</label>
          <input id="mah-coinId" placeholder="bitcoin / ethereum / solana" list="coinSuggestions">
          <datalist id="coinSuggestions">
            <option value="bitcoin"><option value="ethereum"><option value="solana"><option value="binancecoin">
            <option value="ripple"><option value="cardano"><option value="polkadot"><option value="avalanche-2">
            <option value="matic-network"><option value="tether"><option value="usd-coin"><option value="dogecoin">
          </datalist>
        </div>
        <div class="form-row-2">
          <div class="form-row"><label>Symbol *</label><input id="mah-symbol" placeholder="BTC"></div>
          <div class="form-row"><label>Name</label><input id="mah-name" placeholder="Bitcoin"></div>
        </div>
        <div class="form-row-2">
          <div class="form-row"><label>Quantity *</label><input id="mah-qty" type="number" step="any" placeholder="0.5"></div>
          <div class="form-row"><label>Buy Price (₹) *</label><input id="mah-price" type="number" step="any" placeholder="4500000"></div>
        </div>
        <div class="form-row-2">
          <div class="form-row"><label>Exchange</label>
            <select id="mah-exchange">
              <option value="">Select…</option>
              <option>WazirX</option><option>CoinDCX</option><option>Binance</option>
              <option>Coinbase</option><option>Kraken</option><option>KuCoin</option>
              <option>Hardware Wallet</option><option>MetaMask</option><option>Other</option>
            </select>
          </div>
          <div class="form-row"><label>Buy Date</label><input id="mah-date" type="date" value="${new Date().toISOString().slice(0,10)}"></div>
        </div>
        <div class="form-row-2">
          <div class="form-row"><label>Category</label>
            <select id="mah-category">
              <option value="">Auto-detect</option>
              <option>Bitcoin</option><option>Ethereum</option><option>Large-cap</option>
              <option>Altcoin</option><option>DeFi</option><option>Stablecoin</option>
              <option>NFT</option><option>Other</option>
            </select>
          </div>
          <div class="form-row"><label>Wallet Tag</label><input id="mah-wallet-tag" placeholder="Hardware / DeFi…"></div>
        </div>
        <div class="form-row"><label>Notes</label><input id="mah-notes" placeholder="Optional"></div>
        <button class="btn-sm btn-primary" style="width:100%;padding:10px;font-size:14px" onclick="CryptoApp.submitAddHolding()">Add Holding</button>
      </div>`);
  }

  async function submitAddHolding() {
    const data = {
      coin_id: document.getElementById('mah-coinId').value.trim().toLowerCase(),
      coin_symbol: document.getElementById('mah-symbol').value.trim().toUpperCase(),
      coin_name: document.getElementById('mah-name').value.trim(),
      quantity: document.getElementById('mah-qty').value,
      price_inr: document.getElementById('mah-price').value,
      exchange: document.getElementById('mah-exchange').value,
      txn_date: document.getElementById('mah-date').value,
      notes: document.getElementById('mah-notes').value,
    };
    if (!data.coin_id || !data.coin_symbol || !data.quantity || !data.price_inr)
      return alert('Coin ID, symbol, quantity aur price required hain.');
    const res = await post('crypto_add', data);
    if (!res.success) return alert(res.message);
    closeModal();
    await loadStats();
    setTab('holdings');
  }

  // ── MODAL: Edit Holding ────────────────────────────────────────
  function openEditModal(h) {
    showModal('edit-holding', `
      <div class="modal-head">
        <h3>Edit ${esc(h.coin_name)}</h3>
        <button class="modal-close" onclick="CryptoApp.closeModal()">✕</button>
      </div>
      <div class="modal-body">
        <div class="form-row"><label>Exchange</label>
          <select id="meh-exchange">
            <option value="">— clear —</option>
            <option ${h.exchange==='WazirX'?'selected':''}>WazirX</option>
            <option ${h.exchange==='CoinDCX'?'selected':''}>CoinDCX</option>
            <option ${h.exchange==='Binance'?'selected':''}>Binance</option>
            <option ${h.exchange==='Hardware Wallet'?'selected':''}>Hardware Wallet</option>
            <option ${h.exchange==='MetaMask'?'selected':''}>MetaMask</option>
            <option ${h.exchange==='Other'?'selected':''}>Other</option>
          </select>
        </div>
        <div class="form-row"><label>Wallet Address</label><input id="meh-wallet" value="${esc(h.wallet_address||'')}"></div>
        <div class="form-row"><label>Wallet Tag</label><input id="meh-wallet-tag" value="${esc(h.wallet_tag||'')}"></div>
        <div class="form-row"><label>Category</label>
          <select id="meh-category">
            ${['Bitcoin','Ethereum','Large-cap','Altcoin','DeFi','Stablecoin','NFT','Other'].map(c =>
              `<option ${h.category===c?'selected':''}>${c}</option>`).join('')}
          </select>
        </div>
        <div class="form-row"><label>Notes</label><input id="meh-notes" value="${esc(h.notes||'')}"></div>
        <button class="btn-sm btn-primary" style="width:100%;padding:10px;font-size:14px"
          onclick="CryptoApp.submitEditHolding(${h.id||0})">Save Changes</button>
      </div>`);
  }

  async function submitEditHolding(id) {
    const res = await post('crypto_edit_holding', {
      id,
      exchange:       document.getElementById('meh-exchange').value,
      wallet_address: document.getElementById('meh-wallet').value,
      wallet_tag:     document.getElementById('meh-wallet-tag').value,
      category:       document.getElementById('meh-category').value,
      notes:          document.getElementById('meh-notes').value,
    });
    if (!res.success) return alert(res.message);
    closeModal();
    await loadStats();
  }

  // ── MODAL: Add Transaction ─────────────────────────────────────
  function openTxnModal(coinId='', coinSymbol='') {
    showModal('add-txn', `
      <div class="modal-head">
        <h3>+ Add Transaction</h3>
        <button class="modal-close" onclick="CryptoApp.closeModal()">✕</button>
      </div>
      <div class="modal-body">
        <div class="form-row-2">
          <div class="form-row"><label>Coin ID *</label><input id="mat-coinId" value="${esc(coinId)}" placeholder="bitcoin"></div>
          <div class="form-row"><label>Symbol *</label><input id="mat-symbol" value="${esc(coinSymbol)}" placeholder="BTC"></div>
        </div>
        <div class="form-row"><label>Type</label>
          <select id="mat-type">
            <option>BUY</option><option>SELL</option><option>TRANSFER_IN</option><option>TRANSFER_OUT</option>
          </select>
        </div>
        <div class="form-row-2">
          <div class="form-row"><label>Quantity *</label><input id="mat-qty" type="number" step="any" placeholder="0.1"></div>
          <div class="form-row"><label>Price (₹/coin) *</label><input id="mat-price" type="number" step="any" placeholder="4500000"></div>
        </div>
        <div class="form-row-2">
          <div class="form-row"><label>Date</label><input id="mat-date" type="date" value="${new Date().toISOString().slice(0,10)}"></div>
          <div class="form-row"><label>Exchange</label><input id="mat-exchange" placeholder="WazirX…"></div>
        </div>
        <div class="form-row"><label>Notes</label><input id="mat-notes" placeholder="Optional"></div>
        <p style="font-size:11px;color:var(--text-muted);margin-bottom:10px">
          SELL par 1% TDS auto-deducted hoga (Section 194S)
        </p>
        <button class="btn-sm btn-primary" style="width:100%;padding:10px;font-size:14px" onclick="CryptoApp.submitTxn()">Record Transaction</button>
      </div>`);
  }

  async function submitTxn() {
    const data = {
      coin_id:    document.getElementById('mat-coinId').value.trim().toLowerCase(),
      coin_symbol:document.getElementById('mat-symbol').value.trim().toUpperCase(),
      txn_type:   document.getElementById('mat-type').value,
      quantity:   document.getElementById('mat-qty').value,
      price_inr:  document.getElementById('mat-price').value,
      txn_date:   document.getElementById('mat-date').value,
      exchange:   document.getElementById('mat-exchange').value,
      notes:      document.getElementById('mat-notes').value,
    };
    if (!data.coin_id || !data.coin_symbol || !data.quantity)
      return alert('Coin ID, symbol aur quantity required hain.');
    const res = await post('crypto_txn_add', data);
    if (!res.success) return alert(res.message);
    alert(res.message);
    closeModal();
    await loadStats();
  }

  // ── MODAL: Add Staking Reward ──────────────────────────────────
  function openStakingModal() {
    showModal('add-staking', `
      <div class="modal-head">
        <h3>+ Add Staking / Yield Reward</h3>
        <button class="modal-close" onclick="CryptoApp.closeModal()">✕</button>
      </div>
      <div class="modal-body">
        <div class="form-row-2">
          <div class="form-row"><label>Coin ID *</label><input id="msr-coinId" placeholder="ethereum"></div>
          <div class="form-row"><label>Symbol *</label><input id="msr-symbol" placeholder="ETH"></div>
        </div>
        <div class="form-row"><label>Reward Type</label>
          <select id="msr-type"><option>STAKING</option><option>YIELD</option><option>AIRDROP</option><option>MINING</option><option>INTEREST</option></select>
        </div>
        <div class="form-row-2">
          <div class="form-row"><label>Qty Received *</label><input id="msr-qty" type="number" step="any" placeholder="0.05"></div>
          <div class="form-row"><label>Price (₹) at receipt</label><input id="msr-price" type="number" step="any" placeholder="leave blank for live"></div>
        </div>
        <div class="form-row-2">
          <div class="form-row"><label>Date</label><input id="msr-date" type="date" value="${new Date().toISOString().slice(0,10)}"></div>
          <div class="form-row"><label>Platform</label><input id="msr-platform" placeholder="Binance Earn…"></div>
        </div>
        <div class="form-row"><label>Notes</label><input id="msr-notes" placeholder="Optional"></div>
        <button class="btn-sm btn-primary" style="width:100%;padding:10px;font-size:14px" onclick="CryptoApp.submitStaking()">Add Reward</button>
      </div>`);
  }

  async function submitStaking() {
    const data = {
      coin_id:     document.getElementById('msr-coinId').value.trim().toLowerCase(),
      coin_symbol: document.getElementById('msr-symbol').value.trim().toUpperCase(),
      reward_type: document.getElementById('msr-type').value,
      quantity:    document.getElementById('msr-qty').value,
      price_inr:   document.getElementById('msr-price').value,
      reward_date: document.getElementById('msr-date').value,
      platform:    document.getElementById('msr-platform').value,
      notes:       document.getElementById('msr-notes').value,
    };
    if (!data.coin_id || !data.coin_symbol || !data.quantity) return alert('Required fields missing.');
    const res = await post('crypto_staking_add', data);
    if (!res.success) return alert(res.message);
    alert(res.message);
    closeModal();
    loadStaking();
  }

  async function deleteStaking(id) {
    if (!confirm('Delete this staking reward?')) return;
    const res = await post('crypto_staking_delete', {id});
    if (!res.success) return alert(res.message);
    loadStaking();
  }

  // ── MODAL: Watchlist ───────────────────────────────────────────
  function openWLModal() {
    showModal('add-wl', `
      <div class="modal-head">
        <h3>+ Add to Watchlist</h3>
        <button class="modal-close" onclick="CryptoApp.closeModal()">✕</button>
      </div>
      <div class="modal-body">
        <div class="form-row-2">
          <div class="form-row"><label>Coin ID *</label><input id="mwl-coinId" placeholder="bitcoin"></div>
          <div class="form-row"><label>Symbol *</label><input id="mwl-symbol" placeholder="BTC"></div>
        </div>
        <div class="form-row"><label>Name</label><input id="mwl-name" placeholder="Bitcoin"></div>
        <div class="form-row-2">
          <div class="form-row"><label>Alert High (₹)</label><input id="mwl-high" type="number" step="any" placeholder="Optional"></div>
          <div class="form-row"><label>Alert Low (₹)</label><input id="mwl-low" type="number" step="any" placeholder="Optional"></div>
        </div>
        <div class="form-row"><label>Notes</label><input id="mwl-notes" placeholder="Why watching…"></div>
        <button class="btn-sm btn-primary" style="width:100%;padding:10px;font-size:14px" onclick="CryptoApp.submitWL()">Add to Watchlist</button>
      </div>`);
  }

  async function submitWL() {
    const data = {
      coin_id:    document.getElementById('mwl-coinId').value.trim().toLowerCase(),
      coin_symbol:document.getElementById('mwl-symbol').value.trim().toUpperCase(),
      coin_name:  document.getElementById('mwl-name').value.trim(),
      alert_high: document.getElementById('mwl-high').value,
      alert_low:  document.getElementById('mwl-low').value,
      notes:      document.getElementById('mwl-notes').value,
    };
    if (!data.coin_id || !data.coin_symbol) return alert('Coin ID and symbol required.');
    const res = await post('crypto_wl_add', data);
    if (!res.success) return alert(res.message);
    closeModal();
    loadWatchlist();
  }

  async function deleteWatchlist(id) {
    if (!confirm('Remove from watchlist?')) return;
    const res = await post('crypto_wl_delete', {id});
    if (!res.success) return alert(res.message);
    loadWatchlist();
  }

  // ── Price refresh ─────────────────────────────────────────────
  async function refreshPrices() {
    const res = await get('crypto_prices');
    if (!res.success) return alert('Price refresh failed: ' + res.message);
    await loadStats();
  }

  // ── Modal helpers ─────────────────────────────────────────────
  function showModal(id, html) {
    document.getElementById('cryptoModals').innerHTML =
      `<div class="modal-bg" id="modalBg" onclick="if(event.target.id==='modalBg')CryptoApp.closeModal()">
         <div class="modal-box">${html}</div>
       </div>`;
  }
  function closeModal() {
    document.getElementById('cryptoModals').innerHTML = '';
  }
  function setContent(html) {
    document.getElementById('cryptoTabContent').innerHTML = html;
  }

  // ── Public API ────────────────────────────────────────────────
  return {
    init, setTab, loadStats, refreshPrices,
    openAddModal, submitAddHolding,
    openEditModal, submitEditHolding,
    openTxnModal, submitTxn,
    openStakingModal, submitStaking, deleteStaking,
    openWLModal, submitWL, deleteWatchlist,
    closeModal,
  };
})();

document.addEventListener('DOMContentLoaded', CryptoApp.init);
</script>

<?php
$content = ob_get_clean();
require APP_ROOT . '/templates/layout.php';
