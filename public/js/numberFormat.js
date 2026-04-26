/**
 * WealthDash — Indian Number Formatting Utility
 * Task: t347 | Formats numbers using Indian numbering system (lakhs/crores)
 */

const WDFormat = (() => {
  function formatNumber(num, opts = {}) {
    const { decimals = 2, symbol = false, compact = false, signed = false } = opts;
    if (num === null || num === undefined || isNaN(num)) return '—';

    const absVal = Math.abs(num);
    const sign   = num < 0 ? '-' : (signed && num > 0 ? '+' : '');
    const prefix = symbol ? '₹' : '';

    if (compact) {
      if (absVal >= 1e7) return `${sign}${prefix}${(absVal / 1e7).toFixed(2)}Cr`;
      if (absVal >= 1e5) return `${sign}${prefix}${(absVal / 1e5).toFixed(2)}L`;
      if (absVal >= 1e3) return `${sign}${prefix}${(absVal / 1e3).toFixed(2)}K`;
    }

    const fixed = absVal.toFixed(decimals);
    const [intPart, decPart] = fixed.split('.');
    const len = intPart.length;

    let result = '';
    if (len <= 3) {
      result = intPart;
    } else {
      result = intPart.slice(-3);
      let remaining = intPart.slice(0, -3);
      while (remaining.length > 2) {
        result    = remaining.slice(-2) + ',' + result;
        remaining = remaining.slice(0, -2);
      }
      result = remaining + ',' + result;
    }

    const formatted = decPart !== undefined ? `${result}.${decPart}` : result;
    return `${sign}${prefix}${formatted}`;
  }

  function currency(num, decimals = 2) {
    return formatNumber(num, { symbol: true, decimals });
  }

  function compact(num) {
    return formatNumber(num, { symbol: true, compact: true });
  }

  function plain(num, decimals = 2) {
    return formatNumber(num, { decimals });
  }

  function percent(num, decimals = 2, signed = false) {
    if (num === null || num === undefined || isNaN(num)) return '—';
    const sign = signed && num > 0 ? '+' : '';
    return `${sign}${Number(num).toFixed(decimals)}%`;
  }

  function units(num) {
    return formatNumber(num, { decimals: 3 });
  }

  function parse(str) {
    if (!str) return 0;
    const s = String(str).replace(/₹|,|\s/g, '');
    if (s.endsWith('Cr')) return parseFloat(s) * 1e7;
    if (s.endsWith('L'))  return parseFloat(s) * 1e5;
    if (s.endsWith('K'))  return parseFloat(s) * 1e3;
    return parseFloat(s) || 0;
  }

  function applyToDOM(root = document) {
    root.querySelectorAll('[data-wd-format]').forEach(el => {
      const raw = el.dataset.wdRaw ?? el.textContent.trim();
      el.dataset.wdRaw = raw;
      const num    = parse(raw);
      const fmt    = el.dataset.wdFormat;
      const dec    = el.dataset.wdDecimals !== undefined ? parseInt(el.dataset.wdDecimals) : 2;
      const signed = el.dataset.wdSigned === 'true';

      let formatted;
      switch (fmt) {
        case 'currency': formatted = currency(num, dec);          break;
        case 'compact':  formatted = compact(num);                break;
        case 'plain':    formatted = plain(num, dec);             break;
        case 'percent':  formatted = percent(num, dec, signed);   break;
        case 'units':    formatted = units(num);                  break;
        default:         formatted = formatNumber(num, { decimals: dec });
      }

      el.textContent = formatted;

      if (signed) {
        el.classList.toggle('wd-gain',    num > 0);
        el.classList.toggle('wd-loss',    num < 0);
        el.classList.toggle('wd-neutral', num === 0);
      }
    });
  }

  return { formatNumber, currency, compact, plain, percent, units, parse, applyToDOM };
})();

window.WDFormat = WDFormat;

// -------------------------------------------------------
// t347 — Patch Number.prototype.toLocaleString globally
// Intercept all en-IN locale calls and route through WDFormat
// so legacy code (mf.js, stocks.js etc.) gets correct Indian
// formatting without any changes in those files.
// -------------------------------------------------------
(function patchToLocaleString() {
  const _orig = Number.prototype.toLocaleString;
  Number.prototype.toLocaleString = function(locale, opts) {
    if (locale === 'en-IN') {
      const num  = +this;
      const maxF = opts?.maximumFractionDigits ?? 2;
      const minF = opts?.minimumFractionDigits ?? 0;
      const dec  = Math.max(minF, maxF);
      return WDFormat.plain(num, dec);
    }
    return _orig.call(this, locale, opts);
  };
})();

// -------------------------------------------------------
// t347 — Window-level shims for any inline/legacy callers
// -------------------------------------------------------
window.formatINR     = (v, d = 2) => WDFormat.currency(v, d);
window.formatCompact = (v)        => WDFormat.compact(v);
window.formatPercent = (v, d = 2) => WDFormat.percent(v, d);
window.formatUnits   = (v)        => WDFormat.units(v);
window.parseINR      = (s)        => WDFormat.parse(s);

document.addEventListener('DOMContentLoaded', () => WDFormat.applyToDOM());
