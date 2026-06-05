/**
 * WealthDash — tp002: Lazy Loading JS Library
 * File: public/js/lazy_loader.js
 *
 * Usage:
 *   WDLazy.load('lazy_xirr', '#xirr-widget', renderXIRR);
 *   WDLazy.loadAll();
 *   <div data-lazy="lazy_portfolio_summary" data-lazy-target="#summary-widget"></div>
 */
'use strict';

window.WDLazy = (function () {

  const _queue   = [];
  const _cache   = {};
  let   _loading = {};

  // ── Core loader ──────────────────────────────────────────────────
  function load(action, targetSelector, renderFn, params) {
    params = params || {};

    // Show skeleton in target
    const target = typeof targetSelector === 'string'
      ? document.querySelector(targetSelector)
      : targetSelector;

    if (target && !target.dataset.lazyLoaded) {
      _showSkeleton(target);
    }

    // Deduplicate concurrent requests for same action+params
    const cacheKey = action + ':' + JSON.stringify(params);
    if (_loading[cacheKey]) {
      _loading[cacheKey].push({ target, renderFn });
      return;
    }
    _loading[cacheKey] = [{ target, renderFn }];

    // Use cached result if available (in-memory, not server cache)
    if (_cache[cacheKey]) {
      _dispatch(cacheKey, _cache[cacheKey]);
      return;
    }

    const body = Object.assign({ action }, params);
    fetch(window.WD.appUrl + '/api/router.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': window.WD.csrf || '',
      },
      body: JSON.stringify(body),
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        _cache[cacheKey] = data.data;
        _dispatch(cacheKey, data.data);
      } else {
        _dispatchError(cacheKey, data.message || 'Error loading');
      }
    })
    .catch(err => {
      _dispatchError(cacheKey, 'Network error');
      console.warn('[WDLazy] Error loading', action, err);
    });
  }

  function _dispatch(key, data) {
    const handlers = _loading[key] || [];
    handlers.forEach(({ target, renderFn }) => {
      if (target) target.dataset.lazyLoaded = '1';
      try { renderFn && renderFn(data, target); }
      catch (e) { console.error('[WDLazy] render error', e); }
    });
    delete _loading[key];
  }

  function _dispatchError(key, msg) {
    const handlers = _loading[key] || [];
    handlers.forEach(({ target }) => {
      if (target) target.innerHTML = `<div class="empty-state" style="padding:20px;"><div style="color:var(--text-muted);">⚠️ ${msg}</div></div>`;
    });
    delete _loading[key];
  }

  // ── Skeleton placeholder ─────────────────────────────────────────
  function _showSkeleton(el) {
    el.innerHTML = `
      <div class="wd-skeleton-wrap" style="padding:16px;">
        <div class="wd-skeleton" style="height:20px;width:60%;margin-bottom:10px;border-radius:6px;background:var(--bg-secondary);animation:wd-pulse 1.5s ease-in-out infinite;"></div>
        <div class="wd-skeleton" style="height:36px;width:40%;margin-bottom:8px;border-radius:6px;background:var(--bg-secondary);animation:wd-pulse 1.5s ease-in-out infinite .2s;"></div>
        <div class="wd-skeleton" style="height:14px;width:80%;border-radius:4px;background:var(--bg-secondary);animation:wd-pulse 1.5s ease-in-out infinite .4s;"></div>
      </div>`;
    if (!document.getElementById('wd-lazy-styles')) {
      const s = document.createElement('style');
      s.id = 'wd-lazy-styles';
      s.textContent = `@keyframes wd-pulse{0%,100%{opacity:.6}50%{opacity:1}}`;
      document.head.appendChild(s);
    }
  }

  // ── Auto-discover data-lazy elements ─────────────────────────────
  function loadAll() {
    const els = document.querySelectorAll('[data-lazy]:not([data-lazy-loaded])');
    els.forEach(el => {
      const action = el.dataset.lazy;
      const params = el.dataset.lazyParams ? JSON.parse(el.dataset.lazyParams) : {};
      load(action, el, _defaultRenderer(el), params);
    });
  }

  function _defaultRenderer(el) {
    return function(data) {
      // If element has a data-lazy-render function name, call it
      const fnName = el.dataset.lazyRender;
      if (fnName && typeof window[fnName] === 'function') {
        window[fnName](data, el);
        return;
      }
      // Fallback: show JSON
      el.innerHTML = `<pre style="font-size:11px;padding:12px;overflow:auto;">${JSON.stringify(data, null, 2)}</pre>`;
    };
  }

  // ── Intersection Observer: load when visible ──────────────────────
  function observe(selector, action, renderFn, params) {
    if (!window.IntersectionObserver) {
      // Fallback: load immediately
      document.querySelectorAll(selector).forEach(el => load(action, el, renderFn, params));
      return;
    }
    const obs = new IntersectionObserver((entries, observer) => {
      entries.forEach(entry => {
        if (entry.isIntersecting && !entry.target.dataset.lazyLoaded) {
          load(action, entry.target, renderFn, params);
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: '100px' });

    document.querySelectorAll(selector).forEach(el => obs.observe(el));
  }

  // ── Pre-built widget renderers ────────────────────────────────────
  const widgets = {

    xirr(data, el) {
      if (!el) return;
      const cls = data.xirr_pct >= 0 ? 'wd-gain' : 'wd-loss';
      el.innerHTML = `
        <div style="text-align:center;padding:12px 0;">
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Portfolio XIRR</div>
          <div class="wd-num-xl ${cls}" style="font-size:28px;font-weight:800;">
            ${data.xirr_pct >= 0 ? '+' : ''}${data.xirr_pct}%
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
            Current: ${formatINR(data.current_value)} · ${data.txn_count} transactions
            ${data._cached ? ' · <span style="color:#0891b2;">cached</span>' : ''}
          </div>
        </div>`;
    },

    portfolioSummary(data, el) {
      if (!el) return;
      const gc = data.gain >= 0 ? 'wd-gain' : 'wd-loss';
      el.innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:12px;">
          <div style="text-align:center;">
            <div style="font-size:11px;color:var(--text-muted);">Invested</div>
            <div style="font-weight:700;">${formatINR(data.invested)}</div>
          </div>
          <div style="text-align:center;">
            <div style="font-size:11px;color:var(--text-muted);">Current</div>
            <div style="font-weight:700;">${formatINR(data.current_value)}</div>
          </div>
          <div style="text-align:center;">
            <div style="font-size:11px;color:var(--text-muted);">Gain</div>
            <div style="font-weight:700;" class="${gc}">${data.gain>=0?'+':''}${formatINR(data.gain)} (${data.gain_pct}%)</div>
          </div>
        </div>`;
    },

    sipAnalysis(data, el) {
      if (!el) return;
      let html = `<div style="padding:12px;">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">Monthly SIP: <strong>${formatINR(data.monthly_total)}</strong> · ${data.sip_count} active SIPs</div>`;
      for (const s of data.next_dates) {
        html += `<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border);font-size:12px;">
          <span style="flex:2;">${esc(s.fund)}</span>
          <span style="color:var(--text-muted);">${esc(s.date)}</span>
          <span style="font-weight:600;min-width:80px;text-align:right;">${formatINR(s.amount)}</span>
        </div>`;
      }
      html += '</div>';
      el.innerHTML = html;
    },

    taxEstimate(data, el) {
      if (!el) return;
      el.innerHTML = `
        <div style="padding:12px;">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px;">
            <div style="text-align:center;"><div style="font-size:11px;color:var(--text-muted);">LTCG</div><div style="font-weight:700;">${formatINR(data.ltcg)}</div></div>
            <div style="text-align:center;"><div style="font-size:11px;color:var(--text-muted);">Tax</div><div style="font-weight:700;color:var(--loss);">${formatINR(data.total_tax)}</div></div>
            <div style="text-align:center;"><div style="font-size:11px;color:var(--text-muted);">FY</div><div style="font-weight:700;">${esc(data.fy)}</div></div>
          </div>
          <div style="font-size:10px;color:var(--text-muted);">${esc(data.disclaimer)}</div>
        </div>`;
    },
  };

  // ── Invalidate in-memory cache ────────────────────────────────────
  function invalidate(actionPrefix) {
    for (const key of Object.keys(_cache)) {
      if (key.startsWith(actionPrefix)) delete _cache[key];
    }
  }

  // ── Init on DOMContentLoaded ──────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    loadAll();
  });

  return { load, loadAll, observe, widgets, invalidate };

})();
