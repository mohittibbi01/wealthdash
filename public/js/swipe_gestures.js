/**
 * WealthDash — t372: Swipe Gestures — Mobile Navigation
 * File: public/js/swipe_gestures.js
 *
 * Usage: Auto-initialized. No manual calls needed.
 * - Swipe right (from left edge) → open sidebar
 * - Swipe left → close sidebar
 * - Swipe up on cards → collapse
 * - Pull-to-refresh on page top
 *
 * Included in layout.php for mobile screens.
 */
'use strict';

(function WDSwipe() {

  const THRESHOLD   = 60;   // px min swipe distance
  const EDGE_WIDTH  = 30;   // px from left edge to trigger sidebar open
  const RESTRAINT   = 100;  // px max perpendicular drift
  const ALLOW_TIME  = 400;  // ms max swipe duration

  let startX, startY, startTime, isSwiping = false;

  // ── Touch start ───────────────────────────────────────────────
  document.addEventListener('touchstart', function(e) {
    const t = e.changedTouches[0];
    startX    = t.clientX;
    startY    = t.clientY;
    startTime = Date.now();
    isSwiping = true;
  }, { passive: true });

  // ── Touch end ─────────────────────────────────────────────────
  document.addEventListener('touchend', function(e) {
    if (!isSwiping) return;
    isSwiping = false;

    const t       = e.changedTouches[0];
    const distX   = t.clientX - startX;
    const distY   = t.clientY - startY;
    const elapsed = Date.now() - startTime;

    if (elapsed > ALLOW_TIME) return;
    if (Math.abs(distY) > RESTRAINT) return;
    if (Math.abs(distX) < THRESHOLD) return;

    const isSwipeRight = distX > 0;
    const isSwipeLeft  = distX < 0;

    // Sidebar: swipe right from left edge → open
    if (isSwipeRight && startX <= EDGE_WIDTH) {
      if (typeof window.openSidebar === 'function') window.openSidebar();
      else document.getElementById('sidebar')?.classList.add('open');
      e.preventDefault();
      return;
    }

    // Sidebar: swipe left → close
    if (isSwipeLeft) {
      if (typeof window.closeSidebar === 'function') window.closeSidebar();
      else document.getElementById('sidebar')?.classList.remove('open');
    }

    // Swipe left on a card → show quick actions (if card has data-swipe-actions)
    const card = e.target.closest('[data-swipe-actions]');
    if (card && isSwipeLeft) {
      _showCardSwipeActions(card);
    }

  }, { passive: false });

  // ── Pull to refresh ───────────────────────────────────────────
  let pullStartY  = 0;
  let pullEl      = null;
  const PTR_THRESHOLD = 80;

  document.addEventListener('touchstart', function(e) {
    if (window.scrollY === 0) {
      pullStartY = e.touches[0].clientY;
      pullEl = _getPullRefreshEl();
    }
  }, { passive: true });

  document.addEventListener('touchmove', function(e) {
    if (!pullStartY) return;
    const dist = e.touches[0].clientY - pullStartY;
    if (dist > 0 && window.scrollY === 0) {
      const pct = Math.min(dist / PTR_THRESHOLD, 1);
      if (pullEl) {
        pullEl.style.transform = `translateY(${Math.min(dist * 0.4, 40)}px)`;
        pullEl.style.opacity   = pct.toString();
      }
    }
  }, { passive: true });

  document.addEventListener('touchend', function(e) {
    if (!pullStartY) return;
    const dist = e.changedTouches[0].clientY - pullStartY;
    pullStartY = 0;
    if (pullEl) { pullEl.style.transform = ''; pullEl.style.opacity = '0'; }
    if (dist > PTR_THRESHOLD && window.scrollY === 0) {
      _doRefresh();
    }
  }, { passive: true });

  function _getPullRefreshEl() {
    let el = document.getElementById('wd-ptr-indicator');
    if (!el) {
      el = document.createElement('div');
      el.id = 'wd-ptr-indicator';
      el.style.cssText = 'position:fixed;top:0;left:50%;transform:translateX(-50%) translateY(-40px);z-index:9999;background:var(--accent);color:#fff;border-radius:0 0 20px 20px;padding:8px 20px;font-size:13px;font-weight:600;opacity:0;transition:opacity .2s;pointer-events:none;';
      el.textContent = '↓ Release to refresh';
      document.body.appendChild(el);
    }
    return el;
  }

  function _doRefresh() {
    const ptr = _getPullRefreshEl();
    if (ptr) { ptr.textContent = '⟳ Refreshing…'; ptr.style.opacity = '1'; ptr.style.transform = 'translateX(-50%) translateY(0)'; }
    setTimeout(() => { window.location.reload(); }, 300);
  }

  // ── Card swipe actions (horizontal) ──────────────────────────
  function _showCardSwipeActions(card) {
    // Remove any existing swipe panels
    document.querySelectorAll('.wd-swipe-panel').forEach(p => p.remove());

    const actionsAttr = card.dataset.swipeActions;
    if (!actionsAttr) return;

    let actions;
    try { actions = JSON.parse(actionsAttr); } catch { return; }

    const panel = document.createElement('div');
    panel.className = 'wd-swipe-panel';
    panel.style.cssText = 'position:absolute;right:0;top:0;bottom:0;display:flex;align-items:stretch;border-radius:0 8px 8px 0;overflow:hidden;z-index:5;';

    actions.forEach(a => {
      const btn = document.createElement('button');
      btn.style.cssText = `background:${a.color||'#6b7280'};color:#fff;border:none;padding:0 18px;font-size:13px;font-weight:600;cursor:pointer;`;
      btn.textContent = (a.icon || '') + ' ' + (a.label || '');
      if (a.onclick) btn.addEventListener('click', () => { eval(a.onclick); panel.remove(); });
      panel.appendChild(btn);
    });

    card.style.position = 'relative';
    card.appendChild(panel);

    // Auto-remove after 3s
    setTimeout(() => panel.remove(), 3000);
    document.addEventListener('touchstart', () => panel.remove(), { once: true, passive: true });
  }

  // ── Bottom tab bar swipe navigation ──────────────────────────
  const pages = ['dashboard','mf_holdings','report_fy','settings'];
  document.addEventListener('touchend', function(e) {
    const distX   = e.changedTouches[0].clientX - startX;
    const distY   = e.changedTouches[0].clientY - startY;
    const elapsed = Date.now() - startTime;

    if (elapsed > ALLOW_TIME || Math.abs(distY) > RESTRAINT || Math.abs(distX) < THRESHOLD * 1.5) return;

    const currentPage = document.body.dataset.page || '';
    const currentIdx  = pages.indexOf(currentPage);
    if (currentIdx === -1) return;

    if (distX < 0 && currentIdx < pages.length - 1) {
      // Swipe left → next page (subtle hint animation only, no hard nav)
      _pageSwipeHint('left');
    } else if (distX > 0 && currentIdx > 0) {
      _pageSwipeHint('right');
    }
  }, { passive: true });

  function _pageSwipeHint(dir) {
    const main = document.getElementById('mainWrapper');
    if (!main) return;
    main.style.transition = 'transform .15s ease';
    main.style.transform  = `translateX(${dir === 'left' ? -20 : 20}px)`;
    setTimeout(() => { main.style.transform = ''; }, 200);
  }

})();
