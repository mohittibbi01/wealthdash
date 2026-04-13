<?php
/**
 * WealthDash — t46/t49: Govt Schemes & EPF Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser   = require_auth();
$pageTitle     = 'Govt Schemes';
$activePage    = 'govt_schemes';
$activeSection = 'govt';
$pageScript    = 'app.js';

ob_start();
?>
<div class="page-wrapper">
  <div class="page-header" style="padding:24px 0 20px;border-bottom:1px solid var(--border);margin-bottom:24px;">
    <h1 class="page-title" style="margin:0;font-size:24px;font-weight:700;">Govt Schemes</h1>
    <p class="page-subtitle" style="color:var(--text-secondary);margin:6px 0 0;font-size:14px;">
      ⚠️ Coming soon — implementation in progress
    </p>
  </div>
  <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:40px;text-align:center;color:var(--text-secondary);">
    <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="opacity:.3;margin-bottom:16px;">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <p style="font-size:16px;font-weight:500;margin:0 0 8px;">Module under development</p>
    <p style="font-size:13px;margin:0;">This module will be implemented in an upcoming session.</p>
  </div>
</div>
<?php
$content = ob_get_clean();
require APP_ROOT . '/templates/layout.php';
