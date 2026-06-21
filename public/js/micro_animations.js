/**
 * WealthDash — t451: Micro-animations — JS Helpers
 * File: public/js/micro_animations.js
 *
 * Provides count-up number animation and flash-on-change effects.
 * Pair with micro_animations.css.
 *
 * Usage:
 *   WDAnim.countUp('#myValue', 0, 150000, 800);  // animates 0 → 1,50,000 over 800ms
 *   WDAnim.flashOnChange('#sipValue', newValue, oldValue); // green/red flash
 *   WDAnim.observeCountUp('.wd-count-up'); // auto count-up when scrolled into view
 */
'use strict';

window.WDAnim = (function () {

  // ── Count-up animation for numbers ─────────────────────────────────
  function countUp(selector, from, to, duration = 800, formatter = null) {
    const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!el) return;

    const startTime = performance.now();
    const fmt = formatter || (v => Math.round(v).toLocaleString('en-IN'));

    function tick(now) {
      const elapsed = now - startTime;
      const progress = Math.min(1, elapsed / duration);
      // Ease-out cubic
      const eased = 1 - Math.pow(1 - progress, 3);
      const current = from + (to - from) * eased;
      el.textContent = fmt(current);
      if (progress < 1) requestAnimationFrame(tick);
      else el.textContent = fmt(to);
    }
    requestAnimationFrame(tick);
  }

  // ── Count-up formatted as INR currency ───────────────────────────
  function countUpINR(selector, from, to, duration = 800) {
    countUp(selector, from, to, duration, v => {
      if (window.formatINR) return window.formatINR(v, 0);
      return '₹' + Math.round(v).toLocaleString('en-IN');
    });
  }

  // ── Flash background when a value changes (gain=green, loss=red) ──
  function flashOnChange(selector, newVal, oldVal) {
    const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!el || oldVal === undefined || newVal === oldVal) return;
    el.classList.remove('wd-num-flash-gain', 'wd-num-flash-loss');
    void el.offsetWidth; // force reflow to restart animation
    el.classList.add(newVal >= oldVal ? 'wd-num-flash-gain' : 'wd-num-flash-loss');
    setTimeout(() => el.classList.remove('wd-num-flash-gain', 'wd-num-flash-loss'), 700);
  }

  // ── Auto count-up when element scrolls into view ───────────────────
  function observeCountUp(selector) {
    if (!window.IntersectionObserver) return;
    const els = document.querySelectorAll(selector);
    const obs = new IntersectionObserver((entries, observer) => {
      entries.forEach(entry => {
        if (entry.isIntersecting && !entry.target.dataset.counted) {
          const target = parseFloat(entry.target.dataset.countTarget || entry.target.textContent.replace(/[^0-9.-]/g, '')) || 0;
          const isCurrency = entry.target.dataset.countCurrency === 'true';
          entry.target.dataset.counted = '1';
          if (isCurrency) countUpINR(entry.target, 0, target, 900);
          else countUp(entry.target, 0, target, 900);
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.3 });
    els.forEach(el => obs.observe(el));
  }

  // ── Badge pop-in animation (for gamification t242) ──────────────────
  function popBadge(selector) {
    const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!el) return;
    el.classList.add('wd-badge-new');
    setTimeout(() => el.classList.remove('wd-badge-new'), 500);
  }

  // ── Success checkmark animation ──────────────────────────────────
  function checkAnim(selector) {
    const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!el) return;
    el.classList.add('wd-check-anim');
    setTimeout(() => el.classList.remove('wd-check-anim'), 500);
  }

  // ── Button loading state with spinner ────────────────────────────
  function setButtonLoading(selector, loading = true, loadingText = 'Loading…') {
    const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!el) return;
    if (loading) {
      el.dataset.originalText = el.innerHTML;
      el.innerHTML = `<span class="wd-spin">⟳</span> ${loadingText}`;
      el.disabled = true;
    } else {
      el.innerHTML = el.dataset.originalText || el.innerHTML;
      el.disabled = false;
    }
  }

  // ── Auto-init: observe elements with data-count-target on load ────
  document.addEventListener('DOMContentLoaded', () => {
    observeCountUp('[data-count-target]');
  });

  return { countUp, countUpINR, flashOnChange, observeCountUp, popBadge, checkAnim, setButtonLoading };

})();
