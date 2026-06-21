/**
 * WealthDash — t350: Font Size Preference — Accessibility
 * File: public/js/font_size.js
 *
 * Applies a root font-scale CSS variable based on saved preference.
 * Add <script src="<?= wd_js_url('font_size.js') ?>"></script> to layout.php
 * AFTER the window.WD config object is set.
 *
 * Settings page should call WDFontSize.set('large') etc.
 */
'use strict';

window.WDFontSize = (function () {

  const SCALES = {
    small:  '0.875',
    medium: '1',
    large:  '1.125',
    xlarge: '1.25',
  };

  function _injectStyle() {
    if (document.getElementById('wd-fontsize-style')) return;
    const style = document.createElement('style');
    style.id = 'wd-fontsize-style';
    style.textContent = `
      html { font-size: calc(16px * var(--wd-font-scale, 1)); }
      body, .page-content, .card, .data-table, .form-control { font-size: inherit; }
    `;
    document.head.appendChild(style);
  }

  function apply(size) {
    _injectStyle();
    const scale = SCALES[size] || SCALES.medium;
    document.documentElement.style.setProperty('--wd-font-scale', scale);
    document.documentElement.setAttribute('data-font-size', size);
  }

  function set(size) {
    apply(size);
    // Persist to server
    if (window.apiPost) {
      window.apiPost({ action: 'font_size_save', font_size: size }).then(r => {
        if (window.showToast) window.showToast(r.message, r.ok ? 'success' : 'error');
      });
    }
    // Persist locally for instant reapply before server round-trip
    try { localStorage.setItem('wd_font_size_hint', size); } catch (e) {}
  }

  function init() {
    // Quick local apply before server fetch (avoids flash of wrong size)
    try {
      const hint = localStorage.getItem('wd_font_size_hint');
      if (hint) apply(hint);
    } catch (e) {}

    if (window.apiPost) {
      window.apiPost({ action: 'font_size_get' }).then(r => {
        if (r.ok && r.data.font_size) {
          apply(r.data.font_size);
          try { localStorage.setItem('wd_font_size_hint', r.data.font_size); } catch (e) {}
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', init);

  return { set, apply, SCALES };

})();
