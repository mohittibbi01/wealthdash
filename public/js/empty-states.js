/**
 * WealthDash — t452: Empty States
 *
 * Returns ready-to-inject HTML for empty state blocks.
 * Each asset type has a contextual illustration + action button.
 *
 * API:
 *   WdEmpty.html(type, opts?)  → HTML string (inject with innerHTML)
 *   WdEmpty.table(tbodyId, type, colspan?, opts?)  → inject as table row
 *   WdEmpty.div(containerId, type, opts?)           → inject into div
 *
 * Types:
 *   'mf'       — Mutual Fund holdings empty
 *   'stocks'   — Stocks empty
 *   'fd'       — Fixed Deposits empty
 *   'crypto'   — Crypto empty
 *   'nps'      — NPS empty
 *   'realestate'— Real estate empty
 *   'goals'    — Goals empty
 *   'watchlist'— Watchlist empty
 *   'activity' — No recent activity
 *   'alerts'   — No alerts
 *   'search'   — No search results
 *   'generic'  — Fallback
 *
 * opts:
 *   { title, message, actionLabel, actionFn, actionUrl, icon }
 *   All optional — defaults come from the type.
 */

(function (global) {
  'use strict';

  const DEFAULTS = {
    mf: {
      icon: '📈',
      title: 'No mutual fund holdings yet',
      message: 'Start your SIP journey — add your first fund to track NAV, XIRR, and returns.',
      actionLabel: '+ Add Fund',
      actionAttr: 'onclick="if(typeof openAddModal===\'function\')openAddModal();"',
    },
    stocks: {
      icon: '🏦',
      title: 'No stocks in portfolio',
      message: 'Track your equity investments — add a buy transaction to get started.',
      actionLabel: '+ Add Stock',
      actionAttr: 'onclick="if(typeof STOCKS?.openAddModal===\'function\')STOCKS.openAddModal();"',
    },
    fd: {
      icon: '🏛️',
      title: 'No fixed deposits tracked',
      message: 'Add your FDs to monitor maturity dates, interest earned, and TDS.',
      actionLabel: '+ Add FD',
      actionAttr: 'onclick="if(typeof openAddFDModal===\'function\')openAddFDModal();"',
    },
    crypto: {
      icon: '₿',
      title: 'No crypto holdings',
      message: 'Track Bitcoin, Ethereum and other VDAs with live CoinGecko prices and VDA tax (30%).',
      actionLabel: '+ Add Crypto',
      actionAttr: 'onclick="if(typeof openCryptoAddModal===\'function\')openCryptoAddModal();"',
    },
    nps: {
      icon: '🏛️',
      title: 'No NPS account linked',
      message: 'Link your National Pension System account to track tier-wise corpus and returns.',
      actionLabel: '+ Add NPS Account',
      actionAttr: '',
    },
    realestate: {
      icon: '🏠',
      title: 'No real estate tracked',
      message: 'Add properties to monitor current value, rental yield, and LTCG impact.',
      actionLabel: '+ Add Property',
      actionAttr: '',
    },
    goals: {
      icon: '🎯',
      title: 'No financial goals set',
      message: 'Define your goals — retirement, home, education — and track progress automatically.',
      actionLabel: '+ Add Goal',
      actionAttr: 'onclick="if(typeof openGoalModal===\'function\')openGoalModal();"',
    },
    watchlist: {
      icon: '👀',
      title: 'Watchlist is empty',
      message: 'Add funds or stocks to your watchlist to get price alerts and quick access.',
      actionLabel: '+ Add to Watchlist',
      actionAttr: '',
    },
    activity: {
      icon: '📋',
      title: 'No recent activity',
      message: 'Your transactions and portfolio changes will appear here.',
      actionLabel: null,
    },
    alerts: {
      icon: '🔔',
      title: 'All clear — no alerts',
      message: 'WealthDash will notify you about NAV drops, goal progress, and tax opportunities.',
      actionLabel: null,
    },
    search: {
      icon: '🔍',
      title: 'No results found',
      message: 'Try a different search term or adjust the filters.',
      actionLabel: null,
    },
    generic: {
      icon: '📂',
      title: 'Nothing here yet',
      message: 'Add data to get started.',
      actionLabel: null,
    },
  };

  function _buildHtml(type, opts = {}) {
    const def  = DEFAULTS[type] || DEFAULTS.generic;
    const icon = opts.icon        || def.icon;
    const title= opts.title       || def.title;
    const msg  = opts.message     || def.message;
    const lbl  = opts.actionLabel !== undefined ? opts.actionLabel : def.actionLabel;
    const attr = opts.actionAttr  || def.actionAttr || '';
    const url  = opts.actionUrl   || '';

    const btn = lbl
      ? (url
        ? `<a href="${url}" class="btn btn-primary btn-sm" style="margin-top:12px;">${lbl}</a>`
        : `<button class="btn btn-primary btn-sm" style="margin-top:12px;" ${attr}>${lbl}</button>`)
      : '';

    return `
<div class="wd-empty-state">
  <div class="wd-empty-icon">${icon}</div>
  <div class="wd-empty-title">${title}</div>
  <div class="wd-empty-msg">${msg}</div>
  ${btn}
</div>`.trim();
  }

  const WdEmpty = {

    html(type, opts = {}) {
      return _buildHtml(type, opts);
    },

    /** Inject as a single <tr> spanning all columns in a <tbody>. */
    table(tbodyId, type, colspan = 10, opts = {}) {
      const el = typeof tbodyId === 'string'
        ? document.getElementById(tbodyId) : tbodyId;
      if (!el) return;
      el.innerHTML = `<tr><td colspan="${colspan}" style="padding:0;border:none;">
        ${_buildHtml(type, opts)}
      </td></tr>`;
    },

    /** Inject into any container div. */
    div(containerId, type, opts = {}) {
      const el = typeof containerId === 'string'
        ? document.getElementById(containerId) : containerId;
      if (!el) return;
      el.innerHTML = _buildHtml(type, opts);
    },
  };

  global.WdEmpty = WdEmpty;

})(window);
