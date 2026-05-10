/**
 * WealthDash — t449: Loading Skeletons
 *
 * API:
 *   WdSkel.table(containerId, rows?, cols?)
 *     Inject a skeleton table (spinner rows) into a <tbody> or container.
 *
 *   WdSkel.cards(containerId, count?, height?)
 *     Inject skeleton card blocks.
 *
 *   WdSkel.stat(containerId)
 *     Inject a skeleton stat value (for summary cards).
 *
 *   WdSkel.chart(containerId)
 *     Inject a skeleton chart placeholder.
 *
 *   WdSkel.list(containerId, rows?)
 *     Inject skeleton text rows (for activity/alert lists).
 *
 *   WdSkel.clear(containerId)
 *     Fade-out and remove any skeletons inside the container.
 *
 *   WdSkel.clearAll()
 *     Clear every active skeleton on the page.
 *
 * Skeletons auto-clear when their container's content changes
 * if you call WdSkel.clear(id) after rendering real data.
 */

(function (global) {
  'use strict';

  const _active = new Set();  // track containers with active skeletons

  /* ── Helpers ─────────────────────────────────────────────────────────── */

  function _el(id) {
    return typeof id === 'string' ? document.getElementById(id) : id;
  }

  function _skel(cls, style = '') {
    return `<div class="skel ${cls}"${style ? ` style="${style}"` : ''}></div>`;
  }

  function _tag(id, html) {
    const el = _el(id);
    if (!el) return;
    el.innerHTML = html;
    el.dataset.wdSkel = '1';
    _active.add(id);
  }

  /* ── Public API ──────────────────────────────────────────────────────── */

  const WdSkel = {

    /**
     * Skeleton table rows — inject into a <tbody> or wrapper div.
     * @param {string|Element} id  — target element id or element
     * @param {number} rows       — number of skeleton rows (default 6)
     * @param {number} cols       — number of columns per row (default 5)
     */
    table(id, rows = 6, cols = 5) {
      const el = _el(id);
      if (!el) return;
      const tag = el.tagName.toLowerCase();
      const colWidth = Math.floor(100 / cols);
      const cells = Array(cols).fill(0).map((_, i) =>
        `<td style="padding:10px 8px;">${_skel('skel-text', `width:${i === 0 ? 70 : colWidth}%;margin:0;`)}</td>`
      ).join('');
      const rowHtml = `<tr>${cells}</tr>`;
      if (tag === 'tbody' || tag === 'table') {
        el.innerHTML = Array(rows).fill(rowHtml).join('');
      } else {
        // Non-table container: use div rows
        el.innerHTML = Array(rows).fill(_skel('skel-row')).join('');
      }
      el.dataset.wdSkel = '1';
      _active.add(typeof id === 'string' ? id : id.id);
    },

    /**
     * Skeleton stat value — for summary / hero numbers.
     */
    stat(id, wide = false) {
      _tag(id, `
        <div style="display:flex;flex-direction:column;gap:8px;">
          ${_skel('skel-value' + (wide ? '' : '-sm'))}
          ${_skel('skel-text-sm', 'width:55%;')}
        </div>`);
    },

    /**
     * Skeleton cards grid.
     */
    cards(id, count = 4, height = 110) {
      const cards = Array(count).fill(0).map(() =>
        _skel('skel-card', `height:${height}px;`)
      ).join('');
      const grid = Math.min(count, 4);
      _tag(id, `<div class="skel-grid skel-grid-${grid}" style="padding:4px 0;">${cards}</div>`);
    },

    /**
     * Skeleton chart area.
     */
    chart(id, height = 180) {
      _tag(id, `
        <div style="display:flex;flex-direction:column;gap:12px;padding:8px 0;">
          ${_skel('skel-text-sm', 'width:40%;')}
          ${_skel('skel-chart', `height:${height}px;`)}
        </div>`);
    },

    /**
     * Skeleton donut chart.
     */
    donut(id) {
      _tag(id, `<div style="display:flex;align-items:center;gap:20px;padding:8px;">
        ${_skel('skel-donut skel-circle')}
        <div style="flex:1;display:flex;flex-direction:column;gap:10px;">
          ${[0,1,2,3].map(() => _skel('skel-text', 'width:80%;margin:0;')).join('')}
        </div>
      </div>`);
    },

    /**
     * Skeleton activity/alert list.
     */
    list(id, rows = 5) {
      const items = Array(rows).fill(0).map(() => `
        <div style="display:flex;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color);">
          ${_skel('skel-circle skel-circle-sm skel-inline', 'flex-shrink:0;')}
          <div style="flex:1;display:flex;flex-direction:column;gap:6px;">
            ${_skel('skel-text', 'width:60%;margin:0;')}
            ${_skel('skel-text-sm', 'width:35%;margin:0;')}
          </div>
          ${_skel('skel-value-sm skel-inline', 'flex-shrink:0;width:70px;')}
        </div>`).join('');
      _tag(id, items);
    },

    /**
     * Skeleton summary cards row (for dashboard asset cards).
     */
    summaryCards(id, count = 4) {
      const cards = Array(count).fill(0).map(() => `
        <div class="card" style="padding:16px;display:flex;flex-direction:column;gap:10px;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            ${_skel('skel-text', 'width:45%;margin:0;')}
            ${_skel('skel-circle skel-circle-sm skel-inline')}
          </div>
          ${_skel('skel-value')}
          ${_skel('skel-text-sm', 'width:55%;margin:0;')}
        </div>`).join('');
      _tag(id, `<div class="skel-grid skel-grid-${Math.min(count, 4)}">${cards}</div>`);
    },

    /**
     * Clear skeletons from a container and show a fade-out.
     */
    clear(id) {
      const el = _el(id);
      if (!el || !el.dataset.wdSkel) return;
      // Mark skeletons for fade-out
      el.querySelectorAll('.skel').forEach(s => {
        s.classList.add('skel-fade-out');
        setTimeout(() => s.remove(), 260);
      });
      delete el.dataset.wdSkel;
      _active.delete(typeof id === 'string' ? id : id.id);
    },

    /**
     * Clear ALL active skeletons on the page.
     */
    clearAll() {
      for (const id of [..._active]) this.clear(id);
      // Also catch any orphaned skeletons
      document.querySelectorAll('[data-wd-skel]').forEach(el => {
        delete el.dataset.wdSkel;
        el.querySelectorAll('.skel').forEach(s => s.remove());
      });
    },
  };

  global.WdSkel = WdSkel;

})(window);
