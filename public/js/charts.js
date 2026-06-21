/**
 * WealthDash — Chart.js Wrappers
 * Consistent chart styles for dark/light themes
 */

'use strict';

// ---- Load Chart.js from CDN (lazy) ----
function loadChartJs(cb) {
  if (window.Chart) { cb(); return; }
  const s = document.createElement('script');
  s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
  s.onload = cb;
  document.head.appendChild(s);
}

// ---- Get theme colors ----
function getThemeColors() {
  const style = getComputedStyle(document.documentElement);
  return {
    text:    style.getPropertyValue('--text-primary').trim(),
    muted:   style.getPropertyValue('--text-muted').trim(),
    border:  style.getPropertyValue('--border').trim(),
    accent:  style.getPropertyValue('--accent').trim(),
    gain:    style.getPropertyValue('--gain').trim(),
    loss:    style.getPropertyValue('--loss').trim(),
    surface: style.getPropertyValue('--bg-surface').trim(),
  };
}

// ---- Palette for multi-series ----
const CHART_COLORS = [
  '#2563EB','#7C3AED','#059669','#DC2626','#D97706',
  '#0891B2','#BE185D','#1D4ED8','#065F46','#92400E',
  '#4338CA','#047857','#B45309','#0E7490','#9D174D',
];

// ============================================================
// LINE CHART — Portfolio value over time
// ============================================================
function createLineChart(canvasId, labels, datasets, options = {}) {
  loadChartJs(() => {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    const tc = getThemeColors();

    // Destroy existing
    if (ctx._chart) ctx._chart.destroy();

    const ds = datasets.map((d, i) => ({
      label:           d.label,
      data:            d.data,
      borderColor:     d.color || CHART_COLORS[i % CHART_COLORS.length],
      backgroundColor: hexToRgba(d.color || CHART_COLORS[i % CHART_COLORS.length], .1),
      borderWidth:     2,
      pointRadius:     3,
      pointHoverRadius: 5,
      fill:            d.fill ?? false,
      tension:         0.4,
    }));

    ctx._chart = new Chart(ctx, {
      type: 'line',
      data: { labels, datasets: ds },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            labels: { color: tc.text, font: { size: 12 }, boxWidth: 14 }
          },
          tooltip: {
            backgroundColor: tc.surface,
            titleColor: tc.text,
            bodyColor: tc.muted,
            borderColor: tc.border,
            borderWidth: 1,
            callbacks: {
              label: (ctx) => ` ${ctx.dataset.label}: ${formatINR(ctx.raw)}`
            }
          },
        },
        scales: {
          x: {
            grid: { color: tc.border },
            ticks: { color: tc.muted, font: { size: 11 } },
          },
          y: {
            grid: { color: tc.border },
            ticks: {
              color: tc.muted, font: { size: 11 },
              callback: (val) => formatINR(val, 0)
            },
          },
        },
        ...options,
      }
    });
  });
}

// ============================================================
// DOUGHNUT CHART — Asset allocation
// ============================================================
function createDoughnutChart(canvasId, labels, data, options = {}) {
  loadChartJs(() => {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    const tc = getThemeColors();

    if (ctx._chart) ctx._chart.destroy();

    ctx._chart = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{
          data,
          backgroundColor: CHART_COLORS.slice(0, data.length),
          borderColor: tc.surface,
          borderWidth: 2,
          hoverOffset: 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
          legend: {
            position: 'right',
            labels: { color: tc.text, font: { size: 12 }, boxWidth: 12, padding: 12 }
          },
          tooltip: {
            backgroundColor: tc.surface,
            titleColor: tc.text,
            bodyColor: tc.muted,
            borderColor: tc.border,
            borderWidth: 1,
            callbacks: {
              label: (ctx) => ` ${ctx.label}: ${formatINR(ctx.raw)} (${ctx.formattedValue}%)`
            }
          },
        },
        ...options,
      }
    });
  });
}

// ============================================================
// BAR CHART — FY gains comparison
// ============================================================
function createBarChart(canvasId, labels, datasets, options = {}) {
  loadChartJs(() => {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    const tc = getThemeColors();

    if (ctx._chart) ctx._chart.destroy();

    const ds = datasets.map((d, i) => ({
      label:           d.label,
      data:            d.data,
      backgroundColor: d.color || CHART_COLORS[i % CHART_COLORS.length],
      borderRadius:    4,
      barPercentage:   0.7,
    }));

    ctx._chart = new Chart(ctx, {
      type: 'bar',
      data: { labels, datasets: ds },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            labels: { color: tc.text, font: { size: 12 }, boxWidth: 14 }
          },
          tooltip: {
            backgroundColor: tc.surface,
            titleColor: tc.text,
            bodyColor: tc.muted,
            borderColor: tc.border,
            borderWidth: 1,
            callbacks: {
              label: (ctx) => ` ${ctx.dataset.label}: ${formatINR(ctx.raw)}`
            }
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: tc.muted } },
          y: {
            grid: { color: tc.border },
            ticks: {
              color: tc.muted,
              callback: (val) => formatINR(val, 0)
            }
          },
        },
        ...options,
      }
    });
  });
}

// ============================================================
// HELPER
// ============================================================
function hexToRgba(hex, alpha) {
  const r = parseInt(hex.slice(1,3), 16);
  const g = parseInt(hex.slice(3,5), 16);
  const b = parseInt(hex.slice(5,7), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}

// Refresh charts on theme toggle
const _origToggleTheme = window.toggleTheme;
window.toggleTheme = function() {
  _origToggleTheme && _origToggleTheme();
  setTimeout(() => {
    document.querySelectorAll('canvas').forEach(c => {
      if (c._chart) {
        c._chart.destroy();
        c._chart = null;
      }
    });
    if (typeof window.initPageCharts === 'function') window.initPageCharts();
  }, 100);
};
