/**
 * WealthDash — t89: Keyboard Shortcuts — Full Cheatsheet
 * File: public/js/keyboard_shortcuts.js
 *
 * Global keyboard shortcut manager with cheatsheet modal (press "?").
 * Add <script src="<?= wd_js_url('keyboard_shortcuts.js') ?>"></script> to layout.php.
 */
'use strict';

window.WDShortcuts = (function () {

  const SHORTCUTS = [
    { keys: ['g', 'd'], action: () => _nav('dashboard'),         desc: 'Go to Dashboard',        group: 'Navigation' },
    { keys: ['g', 'h'], action: () => _nav('mf_holdings'),        desc: 'Go to Holdings',         group: 'Navigation' },
    { keys: ['g', 't'], action: () => _nav('mf_transactions'),    desc: 'Go to Transactions',     group: 'Navigation' },
    { keys: ['g', 'g'], action: () => _nav('goals_tracker'),      desc: 'Go to Goals',            group: 'Navigation' },
    { keys: ['g', 'r'], action: () => _nav('report_fy'),          desc: 'Go to Reports',          group: 'Navigation' },
    { keys: ['g', 's'], action: () => _nav('settings'),           desc: 'Go to Settings',         group: 'Navigation' },
    { keys: ['g', 'a'], action: () => _nav('ai_chatbot'),         desc: 'Go to AI Assistant',     group: 'Navigation' },
    { keys: ['n'],      action: () => _quickAdd(),                desc: 'New transaction (quick add)', group: 'Actions' },
    { keys: ['/'],      action: () => _focusSearch(),              desc: 'Focus search',           group: 'Actions' },
    { keys: ['t'],      action: () => _toggleTheme(),              desc: 'Toggle dark/light theme',group: 'Actions' },
    { keys: ['r'],      action: () => window.location.reload(),    desc: 'Refresh page',           group: 'Actions' },
    { keys: ['Escape'], action: () => _closeModals(),               desc: 'Close modal / dialog',   group: 'Actions' },
    { keys: ['?'],      action: () => toggleCheatsheet(),           desc: 'Show this cheatsheet',   group: 'Help' },
  ];

  let _pendingKey = null;
  let _pendingTimer = null;
  let _enabled = true;

  // ── Keydown listener ─────────────────────────────────────────────
  function _onKeyDown(e) {
    if (!_enabled) return;

    // Ignore when typing in inputs/textareas/contenteditable
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) {
      if (e.key === 'Escape') _closeModals();
      return;
    }

    // Ignore with modifier keys (except Escape and ?)
    if ((e.ctrlKey || e.metaKey || e.altKey) && e.key !== 'Escape') return;

    const key = e.key;

    // Two-key sequences (g then d, g then h, etc.)
    if (_pendingKey) {
      const combo = [_pendingKey, key];
      const match = SHORTCUTS.find(s => s.keys.length === 2 && s.keys[0] === combo[0] && s.keys[1] === combo[1]);
      _pendingKey = null;
      clearTimeout(_pendingTimer);
      if (match) {
        e.preventDefault();
        match.action();
        return;
      }
    }

    // Check single-key shortcuts
    const single = SHORTCUTS.find(s => s.keys.length === 1 && s.keys[0] === key);
    if (single) {
      e.preventDefault();
      single.action();
      return;
    }

    // Check if this key starts a sequence (e.g. 'g')
    const startsSequence = SHORTCUTS.some(s => s.keys.length === 2 && s.keys[0] === key);
    if (startsSequence) {
      _pendingKey = key;
      _pendingTimer = setTimeout(() => { _pendingKey = null; }, 1000);
    }
  }

  // ── Actions ────────────────────────────────────────────────────────
  function _nav(page) {
    if (window.WD && window.WD.appUrl) window.location.href = window.WD.appUrl + '?page=' + page;
  }

  function _quickAdd() {
    if (typeof window.QuickDrawer !== 'undefined' && window.QuickDrawer.open) {
      window.QuickDrawer.open();
    } else {
      showToast && showToast('Quick add not available on this page', 'info');
    }
  }

  function _focusSearch() {
    const search = document.querySelector('input[type="search"], input[placeholder*="earch" i], #globalSearch');
    if (search) { search.focus(); }
  }

  function _toggleTheme() {
    if (typeof window.toggleTheme === 'function') window.toggleTheme();
  }

  function _closeModals() {
    document.querySelectorAll('.modal-overlay').forEach(m => {
      if (m.style.display !== 'none') m.style.display = 'none';
    });
    const cheatsheet = document.getElementById('wd-shortcuts-modal');
    if (cheatsheet) cheatsheet.style.display = 'none';
  }

  // ── Cheatsheet modal ──────────────────────────────────────────────
  function _buildCheatsheet() {
    if (document.getElementById('wd-shortcuts-modal')) return;

    const groups = {};
    SHORTCUTS.forEach(s => {
      if (!groups[s.group]) groups[s.group] = [];
      groups[s.group].push(s);
    });

    let bodyHtml = '';
    for (const [group, items] of Object.entries(groups)) {
      bodyHtml += `<div style="margin-bottom:16px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">${group}</div>`;
      items.forEach(s => {
        const keysHtml = s.keys.map(k => `<kbd style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:4px;padding:2px 8px;font-family:monospace;font-size:12px;font-weight:600;">${k === ' ' ? 'Space' : k}</kbd>`).join(' <span style="color:var(--text-muted);">then</span> ');
        bodyHtml += `<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;">
          <span style="font-size:13px;">${s.desc}</span>
          <span>${keysHtml}</span>
        </div>`;
      });
      bodyHtml += '</div>';
    }

    const modal = document.createElement('div');
    modal.id = 'wd-shortcuts-modal';
    modal.className = 'modal-overlay';
    modal.style.cssText = 'display:none;';
    modal.onclick = (e) => { if (e.target === modal) modal.style.display = 'none'; };
    modal.innerHTML = `
      <div class="modal" style="max-width:420px;">
        <div class="modal-header">
          <span class="modal-title">⌨️ Keyboard Shortcuts</span>
          <button class="modal-close" onclick="document.getElementById('wd-shortcuts-modal').style.display='none'">×</button>
        </div>
        <div class="modal-body">${bodyHtml}</div>
      </div>`;
    document.body.appendChild(modal);
  }

  function toggleCheatsheet() {
    _buildCheatsheet();
    const modal = document.getElementById('wd-shortcuts-modal');
    modal.style.display = modal.style.display === 'none' ? '' : 'none';
  }

  function enable()  { _enabled = true; }
  function disable() { _enabled = false; }

  document.addEventListener('keydown', _onKeyDown);
  document.addEventListener('DOMContentLoaded', _buildCheatsheet);

  return { toggleCheatsheet, enable, disable, SHORTCUTS };

})();
