<?php defined('WEALTHDASH') or die(); ?>
<section class="wd-page" id="page-watchlist">
  <div class="page-header">
    <h1>Watchlist</h1>
    <div class="page-actions">
      <button class="wd-btn wd-btn--secondary" id="wl-refresh-btn">Refresh Prices</button>
      <button class="wd-btn wd-btn--primary" id="wl-add-btn">+ Add Stock</button>
    </div>
  </div>

  <!-- Alert Strip -->
  <div id="wl-alerts-strip" style="display:none">
    <div class="wd-alert-strip" id="wl-alerts-inner"></div>
  </div>

  <!-- Toolbar -->
  <div class="wd-toolbar">
    <input type="text" class="wd-input" id="wl-search" placeholder="Search symbol or name…">
    <input type="text" class="wd-input" id="wl-tag-filter" placeholder="Filter by tag…">
    <select class="wd-select" id="wl-sort">
      <option value="added_date">Date Added</option>
      <option value="stock_symbol">Symbol</option>
      <option value="buy_target">Buy Target</option>
      <option value="current_price">Current Price</option>
    </select>
    <select class="wd-select" id="wl-sort-dir">
      <option value="DESC">↓ Desc</option>
      <option value="ASC">↑ Asc</option>
    </select>
    <button class="wd-btn wd-btn--danger wd-btn--sm" id="wl-bulk-remove-btn" style="display:none">Remove Selected</button>
  </div>

  <!-- Watchlist Cards Grid -->
  <div class="wd-watchlist-grid" id="wl-grid">
    <div class="wd-empty">Loading…</div>
  </div>

  <!-- Watchlist Table (alternate view) -->
  <div class="wd-table-wrap" id="wl-table-wrap" style="display:none">
    <table class="wd-table" id="wl-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="wl-select-all"></th>
          <th>Symbol</th>
          <th>Name</th>
          <th>Sector</th>
          <th class="wd-num">Current</th>
          <th class="wd-num">Buy Target</th>
          <th class="wd-num">Buy Gap %</th>
          <th class="wd-num">Sell Target</th>
          <th class="wd-num">Stop Loss</th>
          <th>Status</th>
          <th>Tags</th>
          <th>Added</th>
          <th class="wd-actions">Actions</th>
        </tr>
      </thead>
      <tbody id="wl-table-body">
        <tr><td colspan="13" class="wd-empty">Loading…</td></tr>
      </tbody>
    </table>
  </div>

  <!-- View Toggle -->
  <div class="wd-view-toggle">
    <button class="wd-btn wd-btn--ghost wd-btn--sm active" id="wl-view-grid" title="Card view">⊞ Cards</button>
    <button class="wd-btn wd-btn--ghost wd-btn--sm" id="wl-view-table" title="Table view">☰ Table</button>
  </div>
</section>

<!-- Add / Edit Watchlist Modal -->
<div class="wd-modal" id="wl-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box">
    <div class="wd-modal__header">
      <h2 id="wl-modal-title">Add to Watchlist</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="wl-id">

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Symbol *</label>
          <input type="text" class="wd-input" id="wl-symbol" placeholder="INFY">
        </div>
        <div class="wd-field">
          <label>Name *</label>
          <input type="text" class="wd-input" id="wl-name" placeholder="Infosys Limited">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Exchange</label>
          <select class="wd-select" id="wl-exchange">
            <option value="NSE">NSE</option>
            <option value="BSE">BSE</option>
          </select>
        </div>
        <div class="wd-field">
          <label>Sector</label>
          <input type="text" class="wd-input" id="wl-sector" placeholder="IT">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Buy Target (₹)</label>
          <input type="number" class="wd-input" id="wl-buy-target" step="0.01" min="0">
        </div>
        <div class="wd-field">
          <label>Sell Target (₹)</label>
          <input type="number" class="wd-input" id="wl-sell-target" step="0.01" min="0">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Stop Loss (₹)</label>
          <input type="number" class="wd-input" id="wl-stop-loss" step="0.01" min="0">
        </div>
        <div class="wd-field">
          <label>Current Price (₹)</label>
          <input type="number" class="wd-input" id="wl-current-price" step="0.01" min="0">
        </div>
      </div>

      <div class="wd-field">
        <label>Tags <small>(comma-separated)</small></label>
        <input type="text" class="wd-input" id="wl-tags" placeholder="growth, dividend, momentum">
      </div>

      <div class="wd-field">
        <label>Rationale / Notes</label>
        <textarea class="wd-input" id="wl-rationale" rows="3" placeholder="Why are you watching this stock?"></textarea>
      </div>

      <div class="wd-form-row">
        <div class="wd-field wd-field--checkbox">
          <label><input type="checkbox" id="wl-alert-buy" checked> Alert on Buy Target</label>
        </div>
        <div class="wd-field wd-field--checkbox">
          <label><input type="checkbox" id="wl-alert-sell" checked> Alert on Sell Target</label>
        </div>
        <div class="wd-field wd-field--checkbox">
          <label><input type="checkbox" id="wl-alert-sl" checked> Alert on Stop Loss</label>
        </div>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="wl-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="wl-save">Save</button>
    </div>
  </div>
</div>

<!-- Bulk Price Refresh Modal -->
<div class="wd-modal" id="wl-refresh-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--sm">
    <div class="wd-modal__header">
      <h2>Bulk Price Update</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <p class="wd-text--muted">Paste prices as <code>SYMBOL:PRICE</code> (one per line)</p>
      <textarea class="wd-input wd-input--mono" id="wl-bulk-prices" rows="8" placeholder="TCS:3450.25&#10;INFY:1820.00&#10;HDFCBANK:1650.50"></textarea>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="wl-refresh-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="wl-refresh-save">Update Prices</button>
    </div>
  </div>
</div>
