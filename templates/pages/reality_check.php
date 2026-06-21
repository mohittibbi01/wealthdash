<?php defined('WEALTHDASH') or die(); ?>
<section class="wd-page" id="page-reality-check">
  <div class="page-header">
    <h1>Stock Picker Reality Check</h1>
    <div class="page-actions">
      <button class="wd-btn wd-btn--secondary" id="rc-snapshot-btn">Save Snapshot</button>
      <button class="wd-btn wd-btn--primary" id="rc-run-btn">Run Analysis</button>
    </div>
  </div>

  <!-- Date Range -->
  <div class="wd-toolbar">
    <div class="wd-field wd-field--inline">
      <label>From</label>
      <input type="date" class="wd-input" id="rc-from">
    </div>
    <div class="wd-field wd-field--inline">
      <label>To</label>
      <input type="date" class="wd-input" id="rc-to">
    </div>
    <select class="wd-select" id="rc-preset">
      <option value="">Custom Range</option>
      <option value="1y">Last 1 Year</option>
      <option value="3y">Last 3 Years</option>
      <option value="5y">Last 5 Years</option>
      <option value="fy">Current FY</option>
    </select>
  </div>

  <!-- Verdict Banner -->
  <div class="wd-verdict-banner" id="rc-verdict" style="display:none">
    <span class="verdict-icon" id="rc-verdict-icon">—</span>
    <span class="verdict-text" id="rc-verdict-text">Run analysis to see verdict</span>
  </div>

  <!-- Main Metric Cards -->
  <div class="wd-cards wd-cards--4">
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Your Portfolio XIRR</span>
      <span class="stat-value" id="rc-xirr">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Nifty 50 CAGR (same period)</span>
      <span class="stat-value" id="rc-nifty-cagr">—</span>
    </div>
    <div class="wd-card wd-card--stat wd-card--highlight">
      <span class="stat-label">Alpha Generated</span>
      <span class="stat-value" id="rc-alpha">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Winners / Losers vs Nifty</span>
      <span class="stat-value" id="rc-win-lose">—</span>
    </div>
  </div>

  <!-- Tabs -->
  <div class="wd-tabs" id="rc-tabs">
    <button class="wd-tab active" data-tab="per-stock">Per Stock Breakdown</button>
    <button class="wd-tab" data-tab="history">Snapshot History</button>
  </div>

  <!-- Per-Stock Panel -->
  <div class="wd-tab-panel active" data-panel="per-stock">
    <div class="wd-table-wrap">
      <table class="wd-table" id="rc-stock-table">
        <thead>
          <tr>
            <th>Symbol</th>
            <th>Name</th>
            <th class="wd-num">Invested (₹)</th>
            <th class="wd-num">Realised (₹)</th>
            <th class="wd-num">Returns %</th>
            <th>vs Nifty</th>
          </tr>
        </thead>
        <tbody id="rc-stock-body">
          <tr><td colspan="6" class="wd-empty">Run analysis to load data.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Snapshot History Panel -->
  <div class="wd-tab-panel" data-panel="history">
    <div class="wd-table-wrap">
      <table class="wd-table" id="rc-history-table">
        <thead>
          <tr>
            <th>Date</th>
            <th class="wd-num">Portfolio Value (₹)</th>
            <th class="wd-num">Invested (₹)</th>
            <th class="wd-num">XIRR %</th>
            <th class="wd-num">Nifty 50 Returns %</th>
            <th class="wd-num">Alpha %</th>
          </tr>
        </thead>
        <tbody id="rc-history-body">
          <tr><td colspan="6" class="wd-empty">No snapshots yet.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>
