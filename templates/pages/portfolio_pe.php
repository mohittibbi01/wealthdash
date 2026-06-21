<?php defined('WEALTHDASH') or die(); ?>
<section class="wd-page" id="page-portfolio-pe">
  <div class="page-header">
    <h1>Portfolio P/E vs Market P/E</h1>
    <div class="page-actions">
      <button class="wd-btn wd-btn--secondary" id="pe-refresh-btn">Refresh Fundamentals</button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="wd-cards wd-cards--4">
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Portfolio Weighted P/E</span>
      <span class="stat-value" id="pe-portfolio-pe">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Nifty 50 P/E</span>
      <span class="stat-value" id="pe-market-pe">—</span>
      <span class="stat-sub" id="pe-market-date">as of —</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Portfolio Weighted P/B</span>
      <span class="stat-value" id="pe-portfolio-pb">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Nifty 50 P/B</span>
      <span class="stat-value" id="pe-market-pb">—</span>
    </div>
  </div>

  <!-- Valuation Comment -->
  <div class="wd-info-box wd-info-box--lg" id="pe-valuation-comment">
    Run refresh to load valuation analysis.
  </div>

  <!-- Tabs -->
  <div class="wd-tabs">
    <button class="wd-tab active" data-tab="holdings">Holdings Breakdown</button>
    <button class="wd-tab" data-tab="sectors">By Sector</button>
    <button class="wd-tab" data-tab="market-history">Market P/E History</button>
  </div>

  <!-- Holdings Panel -->
  <div class="wd-tab-panel active" data-panel="holdings">
    <div class="wd-table-wrap">
      <table class="wd-table" id="pe-holdings-table">
        <thead>
          <tr>
            <th>Symbol</th>
            <th>Name</th>
            <th>Sector</th>
            <th>Cap</th>
            <th class="wd-num">Qty</th>
            <th class="wd-num">Current Price (₹)</th>
            <th class="wd-num">Value (₹)</th>
            <th class="wd-num">Weight %</th>
            <th class="wd-num">P/E</th>
            <th class="wd-num">P/B</th>
          </tr>
        </thead>
        <tbody id="pe-holdings-body">
          <tr><td colspan="10" class="wd-empty">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- By Sector Panel -->
  <div class="wd-tab-panel" data-panel="sectors">
    <div class="wd-table-wrap">
      <table class="wd-table" id="pe-sector-table">
        <thead>
          <tr>
            <th>Sector</th>
            <th class="wd-num">Value (₹)</th>
            <th class="wd-num">Weight %</th>
            <th class="wd-num">Avg P/E</th>
          </tr>
        </thead>
        <tbody id="pe-sector-body">
          <tr><td colspan="4" class="wd-empty">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Market P/E History Panel -->
  <div class="wd-tab-panel" data-panel="market-history">
    <div class="wd-toolbar">
      <select class="wd-select" id="pe-history-index">
        <option value="NIFTY50">Nifty 50</option>
        <option value="NIFTY500">Nifty 500</option>
        <option value="SENSEX">Sensex</option>
      </select>
      <select class="wd-select" id="pe-history-range">
        <option value="365">1 Year</option>
        <option value="730">2 Years</option>
        <option value="1825">5 Years</option>
      </select>
    </div>
    <div class="wd-chart-wrap" id="pe-history-chart-wrap">
      <canvas id="pe-history-chart" height="280"></canvas>
    </div>
    <div class="wd-table-wrap">
      <table class="wd-table wd-table--sm" id="pe-history-table">
        <thead>
          <tr>
            <th>Date</th>
            <th class="wd-num">P/E</th>
            <th class="wd-num">P/B</th>
            <th class="wd-num">Div Yield %</th>
          </tr>
        </thead>
        <tbody id="pe-history-body">
          <tr><td colspan="4" class="wd-empty">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>
