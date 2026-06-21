<?php
/**
 * WealthDash - Market Indexes Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser = require_auth();
$pageTitle   = 'Market Indexes';
$activePage  = 'market_indexes';

ob_start();
?>
<style>
.idx-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
    gap: 10px;
    margin-bottom: 6px;
}
.idx-tile {
    border-radius: 10px;
    padding: 13px 13px 8px;
    display: flex;
    flex-direction: column;
    min-height: 115px;
    position: relative;
    overflow: hidden;
    cursor: default;
    transition: transform .15s, box-shadow .15s;
}
.idx-tile:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.22); }
.idx-tile.green { background: #16a34a; }
.idx-tile.red   { background: #dc2626; }
.idx-tile.neutral { background: var(--bg-surface-2); }
.idx-tile.green .t-name,
.idx-tile.green .t-price,
.idx-tile.green .t-change { color: #fff; }
.idx-tile.red   .t-name,
.idx-tile.red   .t-price,
.idx-tile.red   .t-change { color: #fff; }
.idx-tile.neutral .t-name   { color: var(--text-muted); }
.idx-tile.neutral .t-price  { color: var(--text-primary); }
.idx-tile.neutral .t-change { color: var(--text-muted); }
.t-name   { font-size: 11px; font-weight: 600; letter-spacing: .02em; opacity: .92; line-height: 1.2; }
.t-price  { font-size: 16px; font-weight: 700; margin-top: 5px; line-height: 1; }
.t-change { font-size: 11px; font-weight: 600; margin-top: 3px; opacity: .88; }
.t-spark  { margin-top: auto; padding-top: 6px; line-height: 0; }
.idx-sec-hdr {
    font-size: 13px; font-weight: 700;
    color: var(--text-primary);
    margin: 22px 0 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
}
.idx-summary-bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 6px; }
.idx-meta-bar    { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
.idx-meta-txt    { font-size: 12px; color: var(--text-muted); }
@media (max-width: 600px) {
    .idx-grid { grid-template-columns: repeat(2, 1fr); }
    .t-price  { font-size: 14px; }
}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Market Indexes</h1>
        <p class="page-subtitle">India &amp; Global markets &mdash; live data</p>
    </div>
    <div class="page-header-actions">
        <span id="nse-badge"></span>
        <button class="btn btn-ghost btn-sm" id="btnToggleAR">&#9646;&#9646; Auto-refresh</button>
        <button class="btn btn-primary btn-sm" id="btnRefresh">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="1 4 1 10 7 10"/>
                <path d="M3.51 15a9 9 0 1 0 .49-4.12"/>
            </svg>
            Refresh
        </button>
    </div>
</div>

<div class="idx-meta-bar">
    <div class="idx-summary-bar" id="idx-summary">
        <span class="badge badge-gray">Loading...</span>
    </div>
    <div class="idx-meta-txt">
        <span id="idx-updated"></span>
        &nbsp;&middot;&nbsp;
        <span id="idx-cd"></span>
    </div>
</div>

<div class="idx-sec-hdr">India &mdash; Broad Market</div>
<div class="idx-grid" id="g-india_broad">
    <div class="idx-tile neutral" style="grid-column:1/-1;opacity:.5"><div class="t-name">Loading...</div></div>
</div>

<div class="idx-sec-hdr">India &mdash; Sectoral</div>
<div class="idx-grid" id="g-india_sectoral">
    <div class="idx-tile neutral" style="grid-column:1/-1;opacity:.5"><div class="t-name">Loading...</div></div>
</div>

<div class="idx-sec-hdr">World Indices</div>
<div class="idx-grid" id="g-world">
    <div class="idx-tile neutral" style="grid-column:1/-1;opacity:.5"><div class="t-name">Loading...</div></div>
</div>

<div class="idx-sec-hdr">Commodities</div>
<div class="idx-grid" id="g-commodities">
    <div class="idx-tile neutral" style="grid-column:1/-1;opacity:.5"><div class="t-name">Loading...</div></div>
</div>

<p style="font-size:11px;color:var(--text-muted);margin-top:18px;">
    Data via Yahoo Finance. May be delayed 15-20 min. Not for trading decisions.
</p>

<?php
$pageContent  = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/indexes.js?v=' . filemtime(APP_ROOT . '/public/js/indexes.js') . '"></script>';
require_once APP_ROOT . '/templates/layout.php';
?>