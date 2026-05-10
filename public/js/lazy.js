/**
 * WealthDash — tp002: Lazy Loader
 *
 * Defers expensive render functions until their container scrolls into view.
 * Uses IntersectionObserver (supported by all modern browsers).
 *
 * API:
 *   WdLazy.observe(elementId, renderFn, options?)
 *     — Call renderFn once when #elementId enters viewport.
 *
 *   WdLazy.observeGroup(groupId, elementIds, renderFn, options?)
 *     — Call renderFn once when ANY element in the group enters viewport.
 *       Used for tab-based content where multiple elements share one loader.
 *
 *   WdLazy.markReady(elementId)
 *     — Mark an element as "data is now available". Triggers pending observers
 *       that were waiting for both visibility AND data readiness.
 *
 *   WdLazy.trigger(elementId)
 *     — Programmatically fire the observer callback (e.g. on tab click).
 *
 *   WdLazy.triggerAll()
 *     — Fire all pending observers immediately (e.g. on print).
 *
 *   WdLazy.reset(elementId)
 *     — Allow the render fn to fire again on next observation.
 *
 * Options:
 *   rootMargin  — IntersectionObserver rootMargin (default '200px')
 *   threshold   — IntersectionObserver threshold (default 0)
 *   waitForData — if true, waits for WdLazy.markReady(id) before rendering
 *   onlyOnce    — if false, re-triggers on every intersection (default true)
 */

(function (global) {
  'use strict';

  const _observers = new Map();  // elementId → { observer, fn, fired, dataReady, opts }
  const _groups    = new Map();  // groupId   → { elementIds, fn, fired }

  const DEFAULT_OPTS = {
    rootMargin:  '200px',   // start loading 200px before element enters viewport
    threshold:   0,
    waitForData: false,
    onlyOnce:    true,
  };

  function _buildObserver(elementId, fn, opts) {
    const el = document.getElementById(elementId);
    if (!el) return null;

    const merged = Object.assign({}, DEFAULT_OPTS, opts);
    const entry  = { fn, fired: false, dataReady: !merged.waitForData, opts: merged, observer: null };

    const io = new IntersectionObserver((entries) => {
      for (const e of entries) {
        if (!e.isIntersecting) continue;
        const stored = _observers.get(elementId);
        if (!stored) continue;
        if (stored.fired && stored.opts.onlyOnce) continue;
        if (!stored.dataReady) continue;

        stored.fired = true;
        try { stored.fn(); } catch (err) { console.warn('[WdLazy] Error in', elementId, err); }
        if (stored.opts.onlyOnce) {
          stored.observer.disconnect();
        }
      }
    }, { rootMargin: merged.rootMargin, threshold: merged.threshold });

    entry.observer = io;
    io.observe(el);
    return entry;
  }

  const WdLazy = {

    /**
     * Observe a single element. renderFn fires once it enters viewport.
     */
    observe(elementId, renderFn, options = {}) {
      if (_observers.has(elementId)) {
        // Update the render fn (allows re-registration with new data)
        _observers.get(elementId).fn = renderFn;
        return;
      }
      const entry = _buildObserver(elementId, renderFn, options);
      if (entry) _observers.set(elementId, entry);
    },

    /**
     * Observe a group — renderFn fires once ANY element in the group is visible.
     */
    observeGroup(groupId, elementIds, renderFn, options = {}) {
      if (_groups.has(groupId)) return;
      const group = { elementIds, fn: renderFn, fired: false };
      _groups.set(groupId, group);

      for (const id of elementIds) {
        this.observe(id, () => {
          if (group.fired) return;
          group.fired = true;
          try { renderFn(); } catch (err) { console.warn('[WdLazy] Group error', groupId, err); }
        }, options);
      }
    },

    /**
     * Mark data as ready; if element is already visible, fires immediately.
     */
    markReady(elementId) {
      const stored = _observers.get(elementId);
      if (!stored) return;
      stored.dataReady = true;
      if (!stored.fired) {
        const el = document.getElementById(elementId);
        if (el) {
          const rect = el.getBoundingClientRect();
          const inView = rect.top < window.innerHeight + 200 && rect.bottom >= -200;
          if (inView) {
            stored.fired = true;
            try { stored.fn(); } catch (e) { console.warn('[WdLazy] markReady trigger error', e); }
          }
        }
      }
    },

    /**
     * Force-trigger an observer by ID (e.g. tab click).
     */
    trigger(elementId) {
      const stored = _observers.get(elementId);
      if (!stored) return;
      if (stored.fired && stored.opts.onlyOnce) return;
      stored.fired = true;
      try { stored.fn(); } catch (e) { console.warn('[WdLazy] trigger error', elementId, e); }
    },

    /**
     * Trigger all pending observers (e.g. before print).
     */
    triggerAll() {
      for (const [id, stored] of _observers.entries()) {
        if (!stored.fired) {
          stored.fired = true;
          try { stored.fn(); } catch (e) { console.warn('[WdLazy] triggerAll error', id, e); }
        }
      }
    },

    /**
     * Reset so it can re-fire (e.g. after data refresh).
     */
    reset(elementId) {
      const stored = _observers.get(elementId);
      if (stored) {
        stored.fired = false;
        stored.dataReady = !stored.opts.waitForData;
        // Re-observe
        const el = document.getElementById(elementId);
        if (el && stored.observer) stored.observer.observe(el);
      }
    },

    /**
     * Reset all observers (call after full data refresh / MF.data reload).
     */
    resetAll() {
      for (const [id] of _observers.entries()) this.reset(id);
      for (const [, g] of _groups.entries()) g.fired = false;
    },
  };

  global.WdLazy = WdLazy;

})(window);
