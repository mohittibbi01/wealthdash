<?php
/**
 * WealthDash — FY Gains Report Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser   = require_auth();
$pageTitle     = 'FY Gains Report';
$activePage    = 'report_fy';
$activeSection = 'reports';

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-title">FY Gains Report</h1>
        <p class="page-subtitle">Financial Year-wise LTCG · STCG · Dividends</p>
    </div>
    <div class="page-actions" style="display:flex;gap:10px;align-items:center;">

        <!-- Custom FY Dropdown — uses shared .cfd-* classes -->
        <div class="cfd-wrapper" id="fyDropdownWrap">
            <button class="cfd-trigger" id="fyDropdownBtn" type="button" style="min-width:180px;justify-content:space-between;">
                <span class="cfd-label" id="fyDropdownLabel">All Financial Years</span>
                <svg class="cfd-chevron" id="fyChevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>

            <!-- Hidden real select for JS compatibility -->
            <select id="fyFilter" style="display:none;">
                <option value="">All Financial Years</option>
            </select>

            <!-- Dropdown menu -->
            <div class="cfd-menu" id="fyDropdownMenu" style="right:0;left:auto;min-width:200px;">
                <div id="fyDropdownList"></div>
            </div>
        </div>

        <button class="btn btn-outline" id="exportTaxBtn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            Export Tax CSV
        </button>
    </div>

<script>
(function() {
    const btn     = document.getElementById('fyDropdownBtn');
    const menu    = document.getElementById('fyDropdownMenu');
    const list    = document.getElementById('fyDropdownList');
    const label   = document.getElementById('fyDropdownLabel');
    const wrapper = document.getElementById('fyDropdownWrap');
    const select  = document.getElementById('fyFilter');

    function buildItems() {
        list.innerHTML = '';
        Array.from(select.options).forEach(opt => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'cfd-item' + (opt.value === select.value ? ' active' : '');
            item.textContent = opt.text;
            item.addEventListener('click', () => {
                select.value = opt.value;
                label.textContent = opt.text;
                closeMenu();
                select.dispatchEvent(new Event('change'));
                list.querySelectorAll('.cfd-item').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
            });
            list.appendChild(item);
        });
    }

    function openMenu() {
        buildItems();
        menu.classList.add('open');
        wrapper.classList.add('open');
    }
    function closeMenu() {
        menu.classList.remove('open');
        wrapper.classList.remove('open');
    }

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        menu.classList.contains('open') ? closeMenu() : openMenu();
    });
    document.addEventListener('click', (e) => {
        if (!wrapper.contains(e.target)) closeMenu();
    });

    const observer = new MutationObserver(buildItems);
    observer.observe(select, { childList: true });
})();
</script>
</div>

<!-- Summary Cards -->
<div id="fySummaryCards" class="cards-grid cards-grid-4 mb-4"></div>

<!-- LTCG Exemption Bar — IDs match reports.js exactly -->
<div class="card mb-4" id="ltcgExemptionCard" style="display:none">
    <div class="card-header">
        <h3 class="card-title">LTCG Exemption Usage — Current FY</h3>
        <span class="badge badge-info" id="ltcgExemptionFy"></span>
    </div>
    <div class="card-body">
        <div class="flex-between mb-2">
            <span class="text-sm">Realised LTCG: <strong id="ltcgUsed" class="text-danger">₹0</strong></span>
            <span class="text-sm">Exemption: <strong id="ltcgLimit">₹1,25,000</strong></span>
            <span class="text-sm">Remaining: <strong id="ltcgRemaining" class="text-success">—</strong></span>
        </div>
        <div class="progress-bar-wrap">
            <div class="progress-bar progress-success" id="ltcgProgressBar" style="width:0%"></div>
        </div>
    </div>
</div>

<!-- FY-wise Summary Table -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title">Year-wise Summary</h3>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>FY</th>
                    <th class="text-right">LTCG Equity</th>
                    <th class="text-right">LTCG Debt</th>
                    <th class="text-right">STCG Equity</th>
                    <th class="text-right">STCG Debt</th>
                    <th class="text-right">Slab Gains</th>
                    <th class="text-right">MF Div</th>
                    <th class="text-right">Stock Div</th>
                    <th class="text-right">Total Gains</th>
                    <th class="text-right">Tax (Approx)</th>
                </tr>
            </thead>
            <tbody id="fySummaryBody">
                <tr><td colspan="10" class="text-center text-secondary">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Gain/Loss Details Tabs -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title">Gain/Loss Details</h3>
        <div class="tab-bar">
            <button class="tab-btn active" data-tab="mfGainsTab">MF Gains</button>
            <button class="tab-btn" data-tab="stockGainsTab">Stock Gains</button>
            <button class="tab-btn" data-tab="mfDivTab">MF Dividends</button>
            <button class="tab-btn" data-tab="stDivTab">Stock Dividends</button>
        </div>
    </div>

    <!-- MF Gains Tab -->
    <div id="mfGainsTab" class="tab-panel active">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>FY</th>
                        <th>Fund Name</th>
                        <th>Category</th>
                        <th>Folio</th>
                        <th class="text-right">Units Sold</th>
                        <th class="text-right">Sell NAV</th>
                        <th class="text-right">Proceeds (₹)</th>
                        <th class="text-right">Cost (₹)</th>
                        <th class="text-right">Gain (₹)</th>
                        <th>Days</th>
                        <th>Type</th>
                        <th class="text-right">Tax (₹)</th>
                    </tr>
                </thead>
                <tbody id="mfGainsBody">
                    <tr><td colspan="12" class="text-center text-secondary">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--border);">
          <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:12px;color:var(--text-muted);">Show</label>
            <select id="mfGainsPerPage" class="form-select" style="width:75px;padding:4px 8px;font-size:12px;" onchange="fyPagination.changePerPage('mfGains',this.value)">
              <option value="10" selected>10</option><option value="25">25</option><option value="50">50</option><option value="9999">All</option>
            </select>
            <span style="font-size:12px;color:var(--text-muted);">per page</span>
          </div>
          <div id="mfGainsPagInfo" style="font-size:12px;color:var(--text-muted);"></div>
          <div id="mfGainsPag" style="display:flex;gap:4px;"></div>
        </div>
    </div>

    <!-- Stock Gains Tab -->
    <div id="stockGainsTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>FY</th>
                        <th>Symbol</th>
                        <th>Company</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Sell Price</th>
                        <th class="text-right">Proceeds (₹)</th>
                        <th class="text-right">Cost (₹)</th>
                        <th class="text-right">Gain (₹)</th>
                        <th>Days</th>
                        <th>Type</th>
                        <th class="text-right">Tax (₹)</th>
                    </tr>
                </thead>
                <tbody id="stockGainsBody">
                    <tr><td colspan="11" class="text-center text-secondary">No data</td></tr>
                </tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--border);">
          <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:12px;color:var(--text-muted);">Show</label>
            <select id="stGainsPerPage" class="form-select" style="width:75px;padding:4px 8px;font-size:12px;" onchange="fyPagination.changePerPage('stGains',this.value)">
              <option value="10" selected>10</option><option value="25">25</option><option value="50">50</option><option value="9999">All</option>
            </select>
            <span style="font-size:12px;color:var(--text-muted);">per page</span>
          </div>
          <div id="stGainsPagInfo" style="font-size:12px;color:var(--text-muted);"></div>
          <div id="stGainsPag" style="display:flex;gap:4px;"></div>
        </div>
    </div>

    <!-- MF Dividends Tab -->
    <div id="mfDivTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>FY</th>
                        <th>Fund Name</th>
                        <th>Fund House</th>
                        <th>Date</th>
                        <th class="text-right">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody id="mfDivBody">
                    <tr><td colspan="5" class="text-center text-secondary">No data</td></tr>
                </tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--border);">
          <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:12px;color:var(--text-muted);">Show</label>
            <select id="mfDivPerPage" class="form-select" style="width:75px;padding:4px 8px;font-size:12px;" onchange="fyPagination.changePerPage('mfDiv',this.value)">
              <option value="10" selected>10</option><option value="25">25</option><option value="50">50</option><option value="9999">All</option>
            </select>
            <span style="font-size:12px;color:var(--text-muted);">per page</span>
          </div>
          <div id="mfDivPagInfo" style="font-size:12px;color:var(--text-muted);"></div>
          <div id="mfDivPag" style="display:flex;gap:4px;"></div>
        </div>
    </div>

    <!-- Stock Dividends Tab -->
    <div id="stDivTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>FY</th>
                        <th>Symbol</th>
                        <th>Company</th>
                        <th>Date</th>
                        <th class="text-right">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody id="stDivBody">
                    <tr><td colspan="5" class="text-center text-secondary">No data</td></tr>
                </tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--border);">
          <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:12px;color:var(--text-muted);">Show</label>
            <select id="stDivPerPage" class="form-select" style="width:75px;padding:4px 8px;font-size:12px;" onchange="fyPagination.changePerPage('stDiv',this.value)">
              <option value="10" selected>10</option><option value="25">25</option><option value="50">50</option><option value="9999">All</option>
            </select>
            <span style="font-size:12px;color:var(--text-muted);">per page</span>
          </div>
          <div id="stDivPagInfo" style="font-size:12px;color:var(--text-muted);"></div>
          <div id="stDivPag" style="display:flex;gap:4px;"></div>
        </div>
    </div>
</div>

<script>
// Tab switching handled by reports.js via data-tab attributes
</script>
<script src="<?= APP_URL ?>/public/js/reports.js?v=4"></script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';