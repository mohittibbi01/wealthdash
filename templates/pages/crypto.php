<?php
/**
 * WealthDash — Crypto Holdings Page (t24)
 * Full implementation: holdings + live prices + P&L + VDA Tax
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser   = require_auth();
$pageTitle     = 'Crypto Holdings';
$activePage    = 'crypto';
$activeSection = 'crypto';
$pageScript    = 'crypto.js';

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">₿ Crypto Holdings</h1>
    <p class="page-subtitle">Live prices via CoinGecko · 30% VDA tax tracker · P&amp;L</p>
  </div>
</div>

<!-- Notice about tax -->
<div style="background:linear-gradient(135deg,#fef3c7,#fffbeb);border:1.5px solid #fcd34d;border-radius:12px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px">
  <span style="font-size:20px;flex-shrink:0">⚠️</span>
  <div style="font-size:12px;color:#78350f;line-height:1.6">
    <strong>Indian Tax Rules (Budget 2022):</strong>
    Crypto gains par <strong>30% flat tax</strong> lagta hai (Section 115BBH) — koi deduction allowed nahi.
    Sell karte waqt <strong>1% TDS</strong> bhi katega (Section 194S).
    Losses CANNOT be offset against other income ya future gains.
  </div>
</div>

<!-- Main App Container (JS fills this) -->
<div id="cryptoApp"></div>

<?php
$content = ob_get_clean();
require APP_ROOT . '/templates/layout.php';
