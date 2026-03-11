<?php
/**
 * WealthDash — Stocks & ETF Holdings Page
 * Phase 4 — Complete: Holdings + Transactions + Live Price + Corporate Actions
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Stocks & ETF';
$activePage  = 'stocks';

$db = DB::conn();

$summaryStmt = $db->prepare("
    SELECT COUNT(DISTINCT h.stock_id) AS stock_count,
           SUM(h.total_invested)      AS total_invested,
           SUM(h.current_value)       AS current_value,
           SUM(h.gain_loss)           AS gain_loss
    FROM stock_holdings h
    JOIN portfolios p ON p.id = h.portfolio_id
    WHERE p.user_id = ? AND h.quantity > 0
");
$summaryStmt->execute([$currentUser['id']]);
$summary       = $summaryStmt->fetch();
$totalInvested = (float)($summary['total_invested'] ?? 0);
$currentValue  = (float)($summary['current_value'] ?? 0);
$gainLoss      = (float)($summary['gain_loss'] ?? 0);
$gainPct       = $totalInvested > 0 ? round(($gainLoss / $totalInvested) * 100, 2) : 0;
$stockCount    = (int)($summary['stock_count'] ?? 0);

$pStmt = $db->prepare("SELECT id, name, color FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Stocks &amp; ETF</h1>
    <p class="page-subtitle">Equity holdings — delivery only, NSE/BSE</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost" id="btnRefreshPrices">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.12"/></svg>
      Refresh Prices
    </button>
    <button class="btn btn-primary" id="btnAddStock">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Transaction
    </button>
  </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label">Stocks / ETFs</div>
    <div class="stat-value"><?= $stockCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Invested</div>
    <div class="stat-value"><?= inr($totalInvested) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Current Value</div>
    <div class="stat-value"><?= inr($currentValue) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Gain / Loss</div>
    <div class="stat-value <?= $gainLoss >= 0 ? 'text-success' : 'text-danger' ?>">
      <?= inr($gainLoss) ?>
      <span class="stat-sub"><?= ($gainLoss >= 0 ? '+' : '') . $gainPct ?>%</span>
    </div>
  </div>
</div>

<!-- Holdings -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div class="view-toggle">
      <button class="view-btn active" data-gain="">All</button>
      <button class="view-btn" data-gain="LTCG">LTCG</button>
      <button class="view-btn" data-gain="STCG">STCG</button>
    </div>
    <div style="display:flex;gap:8px">
      <input type="text" class="form-input" id="searchStock" placeholder="Search symbol..." style="width:180px">
      <select class="form-select" id="filterPortfolio" style="width:160px">
        <option value="">All Portfolios</option>
        <?php foreach ($portfolios as $p): ?>
        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Symbol / Company</th>
          <th>Exchange</th>
          <th class="text-right">Qty</th>
          <th class="text-right">Avg Buy (₹)</th>
          <th class="text-right">CMP (₹)</th>
          <th class="text-right">Invested</th>
          <th class="text-right">Current Value</th>
          <th class="text-right">Gain / Loss</th>
          <th class="text-right">CAGR</th>
          <th>Gain Type</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="stocksBody">
        <tr><td colspan="11" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span> Loading...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Transaction History -->
<div class="card" style="margin-top:24px">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <h3 class="card-title">Transaction History</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <input type="text" class="form-input" id="txnSearchSymbol" placeholder="Symbol..." style="width:140px">
      <select class="form-select" id="txnFilterType" style="width:130px">
        <option value="">All Types</option>
        <option value="BUY">BUY</option>
        <option value="SELL">SELL</option>
        <option value="DIV">DIV</option>
        <option value="BONUS">BONUS</option>
        <option value="SPLIT">SPLIT</option>
      </select>
      <select class="form-select" id="txnFilterFy" style="width:120px">
        <option value="">All FY</option>
        <?php
        $fyYear = date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
        for ($i = 0; $i < 10; $i++) {
          $fy = ($fyYear - $i) . '-' . substr((string)($fyYear - $i + 1), 2);
          echo "<option value=\"{$fy}\">{$fy}</option>";
        }
        ?>
      </select>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Symbol</th>
          <th>Type</th>
          <th class="text-right">Qty</th>
          <th class="text-right">Price</th>
          <th class="text-right">Brokerage</th>
          <th class="text-right">STT</th>
          <th class="text-right">Total Cost</th>
          <th>FY</th>
          <th class="text-center">Del</th>
        </tr>
      </thead>
      <tbody id="stocksTxnBody">
        <tr><td colspan="10" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Stock Transaction Modal -->
<div class="modal-overlay" id="modalAddStock" style="display:none">
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <h3 class="modal-title" id="stockModalTitle">Add Stock Transaction</h3>
      <button class="modal-close" id="closeStockModal">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Portfolio *</label>
          <select class="form-select" id="stockPortfolio">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Transaction Type *</label>
          <select class="form-select" id="stockTxnType" onchange="STOCKS.onTxnTypeChange()">
            <option value="BUY">BUY</option>
            <option value="SELL">SELL</option>
            <option value="DIV">DIVIDEND</option>
            <option value="BONUS">BONUS</option>
            <option value="SPLIT">SPLIT</option>
          </select>
        </div>
      </div>

      <!-- Stock Search -->
      <div class="form-group" style="position:relative">
        <label class="form-label">Stock Symbol *</label>
        <input type="text" class="form-input" id="stockSearch" placeholder="Type symbol (e.g. RELIANCE, INFY)..." autocomplete="off" oninput="STOCKS.searchStocks()">
        <div id="stockDropdown" class="autocomplete-dropdown" style="display:none"></div>
        <input type="hidden" id="stockId">
        <div id="selectedStockInfo" style="margin-top:6px;font-size:12px;color:var(--text-muted)"></div>
        <!-- New stock inline form -->
        <div id="newStockForm" style="display:none;margin-top:10px;padding:12px;background:var(--surface-hover);border-radius:8px">
          <div style="font-size:12px;font-weight:600;margin-bottom:8px;color:var(--text-muted)">Add new stock to master:</div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Symbol *</label><input type="text" class="form-input" id="newSymbol" placeholder="RELIANCE"></div>
            <div class="form-group"><label class="form-label">Exchange *</label><select class="form-select" id="newExchange"><option value="NSE">NSE</option><option value="BSE">BSE</option></select></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Company Name</label><input type="text" class="form-input" id="newCompany" placeholder="Reliance Industries Ltd"></div>
            <div class="form-group"><label class="form-label">Sector</label><input type="text" class="form-input" id="newSector" placeholder="Energy"></div>
          </div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Transaction Date *</label>
          <input type="date" class="form-input" id="stockTxnDate" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><span id="priceLabel">Price / Share (₹) *</span></label>
          <input type="number" class="form-input" id="stockPrice" step="0.01" min="0" placeholder="0.00" oninput="STOCKS.calcTotal()">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" id="qtyGroup">
          <label class="form-label">Quantity *</label>
          <input type="number" class="form-input" id="stockQty" step="1" min="1" placeholder="0" oninput="STOCKS.calcTotal()">
        </div>
        <div class="form-group" id="chargesGroup">
          <label class="form-label">Total Value (₹)</label>
          <input type="number" class="form-input" id="stockTotal" readonly style="background:var(--surface-hover)">
        </div>
      </div>
      <div class="form-row" id="chargesRow">
        <div class="form-group">
          <label class="form-label">Brokerage (₹)</label>
          <input type="number" class="form-input" id="stockBrokerage" step="0.01" min="0" value="0" oninput="STOCKS.calcTotal()">
        </div>
        <div class="form-group">
          <label class="form-label">STT (₹)</label>
          <input type="number" class="form-input" id="stockStt" step="0.01" min="0" value="0" oninput="STOCKS.calcTotal()">
        </div>
        <div class="form-group">
          <label class="form-label">Exchange Charges (₹)</label>
          <input type="number" class="form-input" id="stockExch" step="0.01" min="0" value="0" oninput="STOCKS.calcTotal()">
        </div>
      </div>
      <!-- DIV-specific -->
      <div class="form-group" id="divTotalGroup" style="display:none">
        <label class="form-label">Total Dividend Amount (₹)</label>
        <input type="number" class="form-input" id="stockDivTotal" step="0.01" min="0" placeholder="Total dividend received">
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" class="form-input" id="stockNotes" placeholder="Optional">
      </div>
      <div id="stockError" class="form-error" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelStock">Cancel</button>
      <button class="btn btn-primary" id="saveStock">Save Transaction</button>
    </div>
  </div>
</div>

<!-- Delete Confirm -->
<div class="modal-overlay" id="modalDelStock" style="display:none">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3 class="modal-title">Delete Transaction?</h3><button class="modal-close" id="closeDelStock">✕</button></div>
    <div class="modal-body"><p>This will permanently delete this stock transaction and recalculate holdings.</p></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelDelStock">Cancel</button>
      <button class="btn btn-danger" id="confirmDelStock">Delete</button>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/public/js/stocks.js?v=<?= ASSET_VERSION ?>"></script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';

