/**
 * WealthDash — t449: Loading Skeletons — Smooth Loading States
 * File: public/js/skeletons.js
 *
 * Already referenced in layout.php: <script src="<?= wd_js_url('skeletons.js') ?>"></script>
 *
 * Usage:
 *   WDSkeleton.show('#myCard', 'card');       // shows skeleton
 *   WDSkeleton.show('#myTable', 'table', 5);  // 5 rows
 *   WDSkeleton.hide('#myCard');               // removes skeleton, restores content
 *   WDSkeleton.wrap('#myCard', 'text', fetchPromise, renderFn); // auto show/hide
 *
 * Types: 'card', 'table', 'text', 'chart', 'list', 'stat'
 */
'use strict';

window.WDSkeleton = (function () {

  const _stored = new WeakMap(); // element -> original innerHTML

  // ── CSS injection (once) ──────────────────────────────────────
  function _injectStyles() {
    if (document.getElementById('wd-skeleton-styles')) return;
    const style = document.createElement('style');
    style.id = 'wd-skeleton-styles';
    style.textContent = `
      .wd-skel { background: linear-gradient(90deg, var(--bg-secondary) 25%, var(--border) 50%, var(--bg-secondary) 75%); background-size: 200% 100%; animation: wd-skel-shimmer 1.5s ease-in-out infinite; border-radius: 6px; }
      @keyframes wd-skel-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
      .wd-skel-line { height: 14px; margin-bottom: 8px; }
      .wd-skel-title { height: 20px; width: 50%; margin-bottom: 14px; }
      .wd-skel-circle { border-radius: 50%; }
      .wd-skel-card { padding: 16px; }
      .wd-skel-row { display: flex; gap: 12px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); }
      .wd-skel-stat-val { height: 28px; width: 70%; margin: 8px 0; }
    `;
    document.head.appendChild(style);
  }

  // ── Templates per type ────────────────────────────────────────
  function _template(type, opts = {}) {
    _injectStyles();
    const rows = opts.rows || 4;

    switch (type) {
      case 'card':
        return `<div class="wd-skel-card">
          <div class="wd-skel wd-skel-title"></div>
          <div class="wd-skel wd-skel-line" style="width:90%"></div>
          <div class="wd-skel wd-skel-line" style="width:75%"></div>
          <div class="wd-skel wd-skel-line" style="width:60%"></div>
        </div>`;

      case 'stat':
        return `<div class="wd-skel-card">
          <div class="wd-skel wd-skel-line" style="width:50%;height:11px;"></div>
          <div class="wd-skel wd-skel-stat-val"></div>
          <div class="wd-skel wd-skel-line" style="width:40%;height:11px;"></div>
        </div>`;

      case 'table': {
        let html = '<div style="padding:8px 16px;">';
        for (let i = 0; i < rows; i++) {
          html += `<div class="wd-skel-row">
            <div class="wd-skel wd-skel-circle" style="width:32px;height:32px;flex-shrink:0;"></div>
            <div style="flex:1;"><div class="wd-skel wd-skel-line" style="width:60%"></div><div class="wd-skel wd-skel-line" style="width:40%;height:10px;"></div></div>
            <div class="wd-skel" style="width:70px;height:14px;"></div>
          </div>`;
        }
        return html + '</div>';
      }

      case 'list': {
        let html = '';
        for (let i = 0; i < rows; i++) {
          html += `<div class="wd-skel-row"><div class="wd-skel" style="flex:1;height:14px;"></div></div>`;
        }
        return html;
      }

      case 'chart':
        return `<div style="padding:16px;display:flex;align-items:flex-end;gap:8px;height:${opts.height || 200}px;">
          ${Array.from({length: 8}).map(() => `<div class="wd-skel" style="flex:1;height:${Math.floor(Math.random()*60+30)}%;"></div>`).join('')}
        </div>`;

      case 'text':
      default: {
        let html = '';
        for (let i = 0; i < rows; i++) {
          html += `<div class="wd-skel wd-skel-line" style="width:${100 - i*8}%;"></div>`;
        }
        return html;
      }
    }
  }

  // ── Show skeleton in element (saves original content) ─────────
  function show(selector, type = 'card', opts = {}) {
    const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!el) return;
    if (typeof opts === 'number') opts = { rows: opts };
    if (!_stored.has(el)) _stored.set(el, el.innerHTML);
    el.innerHTML = _template(type, opts);
    el.classList.add('wd-skeleton-active');
  }

  // ── Hide skeleton, restore original content (or set new) ───────
  function hide(selector, newHtml = null) {
    const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!el) return;
    if (newHtml !== null) {
      el.innerHTML = newHtml;
    } else if (_stored.has(el)) {
      el.innerHTML = _stored.get(el);
    }
    el.classList.remove('wd-skeleton-active');
    _stored.delete(el);
  }

  // ── Wrap an async operation: show skeleton, await promise, render result ──
  function wrap(selector, type, promise, renderFn, opts = {}) {
    show(selector, type, opts);
    return promise
      .then(data => {
        const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!el) return data;
        if (renderFn) {
          el.innerHTML = renderFn(data);
        } else {
          hide(selector);
        }
        el.classList.remove('wd-skeleton-active');
        _stored.delete(el);
        return data;
      })
      .catch(err => {
        const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) {
          el.innerHTML = `<div class="empty-state" style="padding:20px;"><div style="color:var(--text-muted);">⚠️ Failed to load</div></div>`;
          el.classList.remove('wd-skeleton-active');
        }
        throw err;
      });
  }

  // ── Auto-skeleton: apply to all [data-skeleton] elements on load ────
  function autoInit() {
    document.querySelectorAll('[data-skeleton]').forEach(el => {
      const type = el.dataset.skeleton || 'card';
      const rows = parseInt(el.dataset.skeletonRows || '4', 10);
      show(el, type, { rows });
    });
  }

  document.addEventListener('DOMContentLoaded', autoInit);

  return { show, hide, wrap, autoInit };

})();
