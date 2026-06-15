/**
 * WealthDash — t452: Empty States — Zero-State Screens
 * File: public/js/empty-states.js
 *
 * Already referenced in layout.php: <script src="<?= wd_js_url('empty-states.js') ?>"></script>
 *
 * Usage:
 *   WDEmpty.render('#myTable', 'mf_holdings');
 *   WDEmpty.render('#myTable', 'goals', { actionUrl: '...' });
 *   WDEmpty.renderCustom('#el', '🎯', 'No goals yet', 'Add Goal', '?page=goals_tracker');
 *
 * Pre-built presets for common WealthDash empty states.
 */
'use strict';

window.WDEmpty = (function () {

  // ── Preset configs per context ───────────────────────────────
  const PRESETS = {
    mf_holdings: {
      icon: '📈',
      title: 'No investments yet',
      desc: 'Add your first mutual fund holding to start tracking your portfolio.',
      cta: '+ Add Holding',
      action: '?page=mf_holdings&action=add',
    },
    mf_transactions: {
      icon: '📜',
      title: 'No transactions recorded',
      desc: 'Your investment transactions will appear here once added.',
      cta: '+ Add Transaction',
      action: '?page=mf_transactions&action=add',
    },
    mf_sips: {
      icon: '🔁',
      title: 'No active SIPs',
      desc: 'Start a SIP to build wealth consistently through disciplined investing.',
      cta: '+ Start a SIP',
      action: '?page=mf_holdings&action=sip',
    },
    goals: {
      icon: '🎯',
      title: 'No financial goals set',
      desc: 'Set a goal — retirement, home, education — and track your progress.',
      cta: '+ Add Goal',
      action: '?page=goals_tracker&action=add',
    },
    insurance: {
      icon: '🛡',
      title: 'No insurance policies',
      desc: 'Add your term, health, or ULIP policies to track coverage and premiums.',
      cta: '+ Add Policy',
      action: '?page=insurance&action=add',
    },
    loans: {
      icon: '🏦',
      title: 'No loans tracked',
      desc: 'Add a loan to monitor EMIs, interest, and repayment progress.',
      cta: '+ Add Loan',
      action: '?page=loans&action=add',
    },
    life_events: {
      icon: '🗓',
      title: 'No life events recorded',
      desc: 'Track financial milestones — marriage, home purchase, career changes.',
      cta: '+ Add Event',
      action: '?page=life_events&action=add',
    },
    notifications: {
      icon: '🔔',
      title: "You're all caught up!",
      desc: 'No new notifications right now.',
      cta: null,
      action: null,
    },
    chat_history: {
      icon: '💬',
      title: 'Start a conversation',
      desc: 'Ask the AI assistant anything about your investments.',
      cta: null,
      action: null,
    },
    search_results: {
      icon: '🔍',
      title: 'No results found',
      desc: 'Try a different search term or check your spelling.',
      cta: null,
      action: null,
    },
    anomalies: {
      icon: '✅',
      title: 'All clear!',
      desc: 'No anomalies detected in your recent transactions.',
      cta: null,
      action: null,
    },
    alerts: {
      icon: '🔔',
      title: 'No alerts',
      desc: 'Click "Check Now" to scan for SIP due dates, EMI reminders, and more.',
      cta: null,
      action: null,
    },
    generic: {
      icon: '📭',
      title: 'Nothing here yet',
      desc: 'Data will appear here once available.',
      cta: null,
      action: null,
    },
    error: {
      icon: '⚠️',
      title: 'Something went wrong',
      desc: 'Please try again, or contact support if the issue persists.',
      cta: '🔄 Retry',
      action: null, // handled via onRetry callback
    },
    offline: {
      icon: '📡',
      title: 'No connection',
      desc: 'Check your internet connection and try again.',
      cta: '🔄 Retry',
      action: null,
    },
  };

  function _injectStyles() {
    if (document.getElementById('wd-empty-styles')) return;
    const style = document.createElement('style');
    style.id = 'wd-empty-styles';
    style.textContent = `
      .wd-empty { text-align: center; padding: 48px 20px; }
      .wd-empty-icon { font-size: 3rem; margin-bottom: 12px; opacity: .8; }
      .wd-empty-title { font-size: 16px; font-weight: 700; margin-bottom: 6px; color: var(--text); }
      .wd-empty-desc { font-size: 13px; color: var(--text-muted); max-width: 340px; margin: 0 auto 16px; line-height: 1.6; }
    `;
    document.head.appendChild(style);
  }

  // ── Render a preset empty state into an element ────────────────
  function render(selector, presetKey, opts = {}) {
    _injectStyles();
    const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!el) return;

    const preset = PRESETS[presetKey] || PRESETS.generic;
    const cta    = opts.ctaLabel || preset.cta;
    const action = opts.actionUrl || preset.action;

    let html = `<div class="wd-empty">
      <div class="wd-empty-icon">${opts.icon || preset.icon}</div>
      <div class="wd-empty-title">${_esc(opts.title || preset.title)}</div>
      <div class="wd-empty-desc">${_esc(opts.desc || preset.desc)}</div>`;

    if (cta) {
      if (opts.onRetry) {
        html += `<button class="btn btn-primary btn-sm" id="wd-empty-retry">${_esc(cta)}</button>`;
      } else if (action) {
        const fullUrl = action.startsWith('?') ? (window.WD?.appUrl || '') + action : action;
        html += `<a href="${fullUrl}" class="btn btn-primary btn-sm">${_esc(cta)}</a>`;
      }
    }
    html += '</div>';

    el.innerHTML = html;

    if (opts.onRetry) {
      const btn = el.querySelector('#wd-empty-retry');
      if (btn) btn.addEventListener('click', opts.onRetry);
    }
  }

  // ── Fully custom empty state ────────────────────────────────────
  function renderCustom(selector, icon, title, ctaLabel = null, actionUrl = null, desc = '') {
    render(selector, 'generic', { icon, title, desc, ctaLabel, actionUrl });
  }

  // ── Render only if data array is empty; returns true if rendered ──
  function renderIfEmpty(selector, data, presetKey, opts = {}) {
    if (Array.isArray(data) ? data.length === 0 : !data) {
      render(selector, presetKey, opts);
      return true;
    }
    return false;
  }

  function _esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  return { render, renderCustom, renderIfEmpty, PRESETS };

})();
