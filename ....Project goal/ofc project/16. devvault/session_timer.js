/**
 * DevVault Pro v3.0 — Session Timeout Timer
 * Modern odometer-style countdown. Theme-aware (uses CSS vars).
 * Requires: window.DEVVAULT_CSRF and window.DEVVAULT_LOGOUT set before this script.
 */
(function () {
  'use strict';

  var TOTAL   = 300; // 5 min
  var WARN_AT = 60;  // show modal at 60s remaining
  var remaining = TOTAL;
  var warnShown = false;
  var ticker    = null;

  // ── CSS ──────────────────────────────────────────────────────────────────
  var css = document.createElement('style');
  css.textContent = `
  /* ── Timer chip ── */
  #dv-timer {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-family: 'Share Tech Mono', 'Courier New', monospace;
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 6px;
    border: 1px solid var(--border, #1e2d4a);
    background: var(--surface2, #111a2e);
    color: var(--muted, #5a7a9a);
    cursor: default;
    user-select: none;
    white-space: nowrap;
    transition: color .4s, border-color .4s, background .4s;
    position: relative;
    overflow: hidden;
  }
  #dv-timer .dv-icon { font-size: 11px; }

  /* Odometer digit wrapper */
  #dv-timer-digits {
    display: inline-flex;
    gap: 1px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 1px;
  }
  .dv-digit-col {
    display: inline-block;
    overflow: hidden;
    height: 16px;
    vertical-align: middle;
    position: relative;
  }
  .dv-digit-strip {
    display: flex;
    flex-direction: column;
    transition: transform .25s cubic-bezier(.4,0,.2,1);
    will-change: transform;
  }
  .dv-digit-strip span {
    height: 16px;
    line-height: 16px;
    display: block;
    text-align: center;
    min-width: 9px;
  }
  .dv-colon {
    font-size: 12px;
    line-height: 16px;
    display: inline-block;
    vertical-align: middle;
    opacity: .6;
    margin: 0 1px;
  }

  /* State colors — FF-03: color-coded per time remaining */
  #dv-timer.dv-ok    { color: #1e40af; border-color: #93c5fd; background: #E8F4FD; }
  #dv-timer.dv-warn  { color: #92400e; border-color: #fbbf24; background: #FFF4E5; }
  #dv-timer.dv-alert { color: #fff;    border-color: rgba(255,61,90,.6); background: #ff3d5a; }
  #dv-timer.dv-alert .dv-icon { animation: dvBlink .7s ease-in-out infinite; }
  /* Dark theme overrides */
  [data-theme="dark"] #dv-timer.dv-ok    { color: #90caf9; border-color: #1e3a6e; background: rgba(30,64,175,.15); }
  [data-theme="dark"] #dv-timer.dv-warn  { color: #ffd740; border-color: rgba(255,215,64,.35); background: rgba(146,64,14,.2); }
  [data-theme="dark"] #dv-timer.dv-alert { color: #fff;    border-color: rgba(255,61,90,.6);  background: #ff3d5a; }
  @keyframes dvBlink { 0%,100%{opacity:1} 50%{opacity:.3} }

  /* ── Modal overlay — always on top, uses CSS vars ── */
  #dv-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(6px);
    align-items: center;
    justify-content: center;
  }
  #dv-modal-overlay.dv-show { display: flex; }

  #dv-modal-box {
    background: var(--surface, #0d1422);
    border: 1px solid var(--border, #1e2d4a);
    border-radius: 16px;
    padding: 28px 28px 22px;
    max-width: 380px;
    width: 92%;
    text-align: center;
    box-shadow: 0 8px 48px rgba(0,0,0,.5);
    position: relative;
    overflow: hidden;
  }
  /* top accent line using accent color */
  #dv-modal-box::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--accent,#00d4ff), transparent);
  }
  #dv-modal-icon { font-size: 40px; display: block; margin-bottom: 10px; }
  #dv-modal-title {
    font-family: 'Rajdhani', sans-serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--text, #e8edf5);
    letter-spacing: .5px;
    margin-bottom: 6px;
  }
  #dv-modal-sub {
    font-family: 'Share Tech Mono', monospace;
    font-size: 12px;
    color: var(--muted, #5a7a9a);
    margin-bottom: 14px;
    line-height: 1.6;
  }

  /* Big countdown in modal — uses accent color */
  #dv-modal-big-count {
    font-family: 'Orbitron', 'Share Tech Mono', monospace;
    font-size: 48px;
    font-weight: 700;
    color: var(--accent, #00d4ff);
    letter-spacing: 4px;
    margin-bottom: 20px;
    text-shadow: 0 0 24px var(--accent, #00d4ff);
    transition: color .3s, text-shadow .3s;
  }
  #dv-modal-big-count.dv-critical {
    color: var(--danger, #ff3d5a);
    text-shadow: 0 0 24px rgba(255,61,90,.5);
    animation: dvPulse .6s ease-in-out infinite;
  }
  @keyframes dvPulse { 0%,100%{opacity:1} 50%{opacity:.5} }

  /* Countdown bar */
  #dv-progress-bar-wrap {
    width: 100%;
    height: 4px;
    background: var(--surface2, #111a2e);
    border-radius: 2px;
    margin-bottom: 18px;
    overflow: hidden;
  }
  #dv-progress-bar {
    height: 100%;
    border-radius: 2px;
    background: var(--accent, #00d4ff);
    transition: width .9s linear, background .5s;
  }

  /* Buttons — use existing btn classes if loaded, else define */
  #dv-btn-extend {
    width: 100%;
    background: var(--accent, #00d4ff);
    color: #000;
    border: none;
    border-radius: 8px;
    padding: 12px;
    font-size: 16px;
    font-weight: 700;
    font-family: 'Rajdhani', sans-serif;
    cursor: pointer;
    letter-spacing: .5px;
    margin-bottom: 8px;
    transition: opacity .2s, transform .15s;
  }
  #dv-btn-extend:hover  { opacity: .85; transform: translateY(-1px); }
  #dv-btn-extend:active { transform: translateY(0); }
  #dv-btn-logout {
    width: 100%;
    background: transparent;
    color: var(--muted, #5a7a9a);
    border: 1px solid var(--border, #1e2d4a);
    border-radius: 8px;
    padding: 9px;
    font-size: 13px;
    font-family: 'Rajdhani', sans-serif;
    cursor: pointer;
    transition: color .15s, border-color .15s;
  }
  #dv-btn-logout:hover { color: var(--danger,#ff3d5a); border-color: rgba(255,61,90,.4); }
  `;
  document.head.appendChild(css);

  // ── Build odometer digits for MM:SS ───────────────────────────────────────
  // Each digit is a scrolling strip of 0-9
  function makeDigitCol(id) {
    var col   = document.createElement('span');
    col.className = 'dv-digit-col';
    var strip = document.createElement('span');
    strip.className = 'dv-digit-strip';
    strip.id = 'dv-strip-' + id;
    // 0-9 repeated for smooth wrap
    for (var i = 0; i <= 9; i++) {
      var s = document.createElement('span');
      s.textContent = i;
      strip.appendChild(s);
    }
    col.appendChild(strip);
    return col;
  }

  function setDigit(id, val) {
    var strip = document.getElementById('dv-strip-' + id);
    if (!strip) return;
    var d = val % 10;
    strip.style.transform = 'translateY(-' + (d * 16) + 'px)';
  }

  // ── Build timer chip DOM ──────────────────────────────────────────────────
  function buildChip() {
    var el = document.getElementById('session-timer-display');
    if (!el) return;
    el.id = 'dv-timer';
    el.className = 'dv-ok';
    el.title = 'Session timeout timer. Any activity resets it.';

    var icon = document.createElement('span');
    icon.className = 'dv-icon';
    icon.textContent = '⏱';

    var digits = document.createElement('span');
    digits.id = 'dv-timer-digits';

    // M1 : M0 : S1 : S0
    digits.appendChild(makeDigitCol('m1'));
    digits.appendChild(makeDigitCol('m0'));
    var c = document.createElement('span');
    c.className = 'dv-colon';
    c.textContent = ':';
    digits.appendChild(c);
    digits.appendChild(makeDigitCol('s1'));
    digits.appendChild(makeDigitCol('s0'));

    el.innerHTML = '';
    el.appendChild(icon);
    el.appendChild(digits);
  }

  function updateChip() {
    var el = document.getElementById('dv-timer');
    if (!el) return;

    var m = Math.floor(remaining / 60);
    var s = remaining % 60;

    setDigit('m1', Math.floor(m / 10));
    setDigit('m0', m % 10);
    setDigit('s1', Math.floor(s / 10));
    setDigit('s0', s % 10);

    el.className = '';
    if (remaining > WARN_AT)      el.className = 'dv-ok';
    else if (remaining > 30)      el.className = 'dv-warn';
    else                          el.className = 'dv-alert';
  }

  // ── Build modal DOM ───────────────────────────────────────────────────────
  function buildModal() {
    var ov = document.createElement('div');
    ov.id = 'dv-modal-overlay';
    ov.innerHTML =
      '<div id="dv-modal-box">' +
        '<span id="dv-modal-icon">⏳</span>' +
        '<div id="dv-modal-title">Session Expire Ho Raha Hai</div>' +
        '<div id="dv-modal-sub">Aapki activity nahi mili.<br>Session neeche dikha samay mein expire ho jaayega.</div>' +
        '<div id="dv-modal-big-count">60</div>' +
        '<div id="dv-progress-bar-wrap"><div id="dv-progress-bar" style="width:100%"></div></div>' +
        '<button id="dv-btn-extend" onclick="dvExtend()">🔄 Session Extend Karo (+5 min)</button>' +
        '<button id="dv-btn-logout" onclick="dvLogout()">Abhi Logout Karo</button>' +
      '</div>';
    document.body.appendChild(ov);
  }

  function showModal() {
    if (warnShown) return;
    warnShown = true;
    document.getElementById('dv-modal-overlay').classList.add('dv-show');
    updateModal();
  }

  function hideModal() {
    warnShown = false;
    var ov = document.getElementById('dv-modal-overlay');
    if (ov) ov.classList.remove('dv-show');
  }

  function updateModal() {
    var big  = document.getElementById('dv-modal-big-count');
    var bar  = document.getElementById('dv-progress-bar');
    if (!big) return;
    var secs = remaining;
    big.textContent = secs + 's';
    big.className = secs <= 20 ? 'dv-critical' : '';
    if (bar) {
      var pct = Math.max(0, Math.min(100, (secs / WARN_AT) * 100));
      bar.style.width = pct + '%';
      // bar color: accent → amber → danger
      if (secs > 40)      bar.style.background = 'var(--accent,#00d4ff)';
      else if (secs > 20) bar.style.background = 'var(--amber,#ffd740)';
      else                bar.style.background = 'var(--danger,#ff3d5a)';
    }
  }

  // ── Extend via AJAX ───────────────────────────────────────────────────────
  window.dvExtend = function () {
    var csrf = window.DEVVAULT_CSRF || '';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      try {
        var res = JSON.parse(xhr.responseText);
        if (res.ok) {
          remaining = TOTAL;
          hideModal();
          updateChip();
        } else if (res.error === 'session_expired') {
          // Absolute 8-hour timeout hit on server side
          window.location.href = (window.DEVVAULT_LOGOUT || 'logout.php') + '?err=expired';
        } else {
          window.location.href = window.DEVVAULT_LOGOUT || 'logout.php';
        }
      } catch (e) {
        window.location.href = window.DEVVAULT_LOGOUT || 'logout.php';
      }
    };
    xhr.send('action=keepalive&csrf=' + encodeURIComponent(csrf));
  };

  window.dvLogout = function () {
    window.location.href = window.DEVVAULT_LOGOUT || 'logout.php';
  };

  // ── Activity reset ────────────────────────────────────────────────────────
  function onActivity() {
    if (!warnShown) {
      remaining = TOTAL;
      updateChip();
    }
  }
  ['mousemove','keydown','click','scroll','touchstart'].forEach(function(e){
    document.addEventListener(e, onActivity, { passive: true });
  });

  // ── Main tick ─────────────────────────────────────────────────────────────
  function tick() {
    remaining--;
    if (remaining <= 0) {
      clearInterval(ticker);
      window.location.href = window.DEVVAULT_LOGOUT || 'logout.php';
      return;
    }
    updateChip();
    if (remaining <= WARN_AT) {
      if (!warnShown) showModal();
      else updateModal();
    }
  }

  // ── Init ──────────────────────────────────────────────────────────────────
  function init() {
    buildChip();
    buildModal();
    updateChip();
    ticker = setInterval(tick, 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
