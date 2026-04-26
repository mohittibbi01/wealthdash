<?php
/**
 * WealthDash — Main Layout Shell
 * Include this in every protected page.
 * Set $pageTitle, $activePage, $activeSection before including.
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$pageTitle     = $pageTitle     ?? APP_NAME;
$activePage    = $activePage    ?? '';
$activeSection = $activeSection ?? '';
$currentUser   = $currentUser   ?? [];
$flashMsgs     = flash_get();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($currentUser['theme'] ?? 'light') ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="app-url" content="<?= e(APP_URL) ?>">
  <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;500;700&display=swap">
  <link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css?v=<?= filemtime(APP_ROOT.'/public/css/app.css') ?>">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/public/img/logo.svg">
</head>
<body class="app-body" id="app-body">

<!-- Mobile overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ===========================
     SIDEBAR
     =========================== -->
<aside class="sidebar" id="sidebar">
  <?php include APP_ROOT . '/templates/sidebar.php'; ?>
</aside>

<!-- ===========================
     MAIN CONTENT AREA
     =========================== -->
<div class="main-wrapper" id="mainWrapper">

  <!-- TOPBAR -->
  <header class="topbar">
    <?php include APP_ROOT . '/templates/topbar.php'; ?>
  </header>

  <!-- PAGE CONTENT -->
  <main class="page-content">

    <!-- Flash messages -->
    <?php foreach ($flashMsgs as $type => $msgs): ?>
      <?php foreach ($msgs as $msg): ?>
        <div class="alert alert-<?= e($type) ?> alert-dismissible" role="alert">
          <?= e($msg) ?>
          <button class="alert-close" onclick="this.parentElement.remove()">×</button>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <!-- Page body injected here -->
    <?php if (isset($pageContent)): ?>
      <?= $pageContent ?>
    <?php endif; ?>

  </main>

</div>

<!-- ===========================
     GLOBAL MODALS
     =========================== -->
<?php include APP_ROOT . '/templates/modals.php'; ?>

<!-- ═══════════════════════════════════════════════════════
     t370: MOBILE BOTTOM NAVIGATION BAR
     Only visible on screens < 768px
════════════════════════════════════════════════════════ -->
<nav class="mobile-bottom-nav" id="mobileBottomNav" aria-label="Mobile navigation">
  <a class="mbn-item <?= $activePage==='dashboard'?'active':'' ?>" href="<?= APP_URL ?>?page=dashboard">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    <span>Home</span>
  </a>
  <a class="mbn-item <?= in_array($activePage,['mf_holdings','mf_screener','mf_report','mf_transactions'])?'active':'' ?>" href="<?= APP_URL ?>?page=mf_holdings">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    <span>MF</span>
  </a>
  <!-- t494: Quick Add floating centre button -->
  <button class="mbn-item mbn-add" id="mbnQuickAdd" onclick="QuickDrawer.open()" aria-label="Quick add">
    <span class="mbn-add-circle">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    </span>
    <span>Add</span>
  </button>
  <a class="mbn-item <?= strpos($activePage,'report')===0?'active':'' ?>" href="<?= APP_URL ?>?page=report_fy">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    <span>Reports</span>
    <span class="mbn-badge" id="mbnAlertBadge" style="display:none;"></span>
  </a>
  <a class="mbn-item <?= $activePage==='settings'?'active':'' ?>" href="<?= APP_URL ?>?page=settings">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93A10 10 0 0 0 4.93 4.93"/><path d="M4.93 19.07A10 10 0 0 0 19.07 19.07"/><line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="4.22" y1="4.22" x2="6.34" y2="6.34"/><line x1="17.66" y1="17.66" x2="19.78" y2="19.78"/></svg>
    <span>Settings</span>
  </a>
</nav>

<!-- ═══════════════════════════════════════════════════════
     t494: QUICK TRANSACTION DRAWER
     Slide-up sheet for fast data entry
════════════════════════════════════════════════════════ -->
<div id="quickDrawerOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1100;" onclick="QuickDrawer.close()"></div>
<div id="quickDrawerSheet" style="display:none;position:fixed;bottom:0;left:0;right:0;background:var(--bg);border-radius:20px 20px 0 0;z-index:1101;padding:0 0 env(safe-area-inset-bottom,16px);max-height:85vh;overflow-y:auto;transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1);">
  <!-- Handle bar -->
  <div style="display:flex;justify-content:center;padding:12px 0 8px;">
    <div style="width:40px;height:4px;background:var(--border);border-radius:2px;"></div>
  </div>
  <div style="padding:0 20px 20px;">
    <h3 style="margin:0 0 16px;font-size:16px;font-weight:700;">⚡ Quick Add</h3>
    <!-- Type selector -->
    <div style="display:flex;gap:8px;margin-bottom:20px;">
      <button class="qd-type active" data-type="mf" onclick="QuickDrawer.setType('mf')" style="flex:1;padding:10px 8px;border-radius:10px;border:2px solid var(--accent);background:var(--accent);color:#fff;font-size:13px;font-weight:600;cursor:pointer;">📈 MF</button>
      <button class="qd-type" data-type="fd" onclick="QuickDrawer.setType('fd')" style="flex:1;padding:10px 8px;border-radius:10px;border:2px solid var(--border);background:var(--bg-secondary);color:var(--text);font-size:13px;font-weight:600;cursor:pointer;">🏦 FD</button>
      <button class="qd-type" data-type="nps" onclick="QuickDrawer.setType('nps')" style="flex:1;padding:10px 8px;border-radius:10px;border:2px solid var(--border);background:var(--bg-secondary);color:var(--text);font-size:13px;font-weight:600;cursor:pointer;">🏛️ NPS</button>
    </div>
    <div id="qdForm"></div>
    <div id="qdMsg" style="display:none;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:13px;"></div>
  </div>
</div>

<!-- ===========================
     SCRIPTS
     =========================== -->
<script>
  // Global WealthDash config object used by all JS modules
  window.WD = {
    appUrl           : '<?= e(APP_URL) ?>',
    csrf             : '<?= e(csrf_token()) ?>',
    selectedPortfolio: <?= get_user_portfolio_id((int)($currentUser['id'] ?? 0)) ?>,
    apiUrl           : '<?= e(APP_URL) ?>/api/reports/export_csv.php',
  };
  window.CSRF_TOKEN = window.WD.csrf;

  // esc() — HTML-escape helper used across page scripts
  function esc(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // formatINR global (alias so page scripts don't need to redefine)
  function formatINR(amount, decimals) {
    decimals = decimals === undefined ? 2 : decimals;
    if (isNaN(amount)) return '₹0';
    var abs = Math.abs(+amount);
    var sign = +amount < 0 ? '-' : '';
    var formatted = abs.toFixed(decimals);
    var parts = formatted.split('.');
    var whole = parts[0];
    var dec   = parts[1];
    var result;
    if (whole.length <= 3) {
      result = whole;
    } else {
      var last3 = whole.slice(-3);
      var rest  = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
      result = rest + ',' + last3;
    }
    return sign + '₹' + result + (decimals > 0 ? '.' + dec : '');
  }

  // initFundSearch — autocomplete for fund search inputs
  function initFundSearch(inputId, hiddenId, dropdownId) {
    var input    = document.getElementById(inputId);
    var hidden   = document.getElementById(hiddenId);
    var dropdown = document.getElementById(dropdownId);
    if (!input || !dropdown) return;

    var timer;
    input.addEventListener('input', function() {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 2) { dropdown.style.display = 'none'; return; }
      timer = setTimeout(function() {
        fetch(window.WD.appUrl + '/api/mutual_funds/mf_search.php?q=' + encodeURIComponent(q), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          var funds = data.funds || data.results || [];
          if (!funds.length) { dropdown.style.display = 'none'; return; }
          dropdown.innerHTML = funds.slice(0, 10).map(function(f) {
            return '<div class="autocomplete-item" data-id="' + esc(f.id) + '" data-name="' + esc(f.scheme_name || f.name) + '">' +
              esc(f.scheme_name || f.name) + '</div>';
          }).join('');
          dropdown.style.display = 'block';
          dropdown.querySelectorAll('.autocomplete-item').forEach(function(item) {
            item.addEventListener('click', function() {
              input.value         = item.dataset.name;
              hidden.value        = item.dataset.id;
              dropdown.style.display = 'none';
            });
          });
        })
        .catch(function() { dropdown.style.display = 'none'; });
      }, 300);
    });

    document.addEventListener('click', function(e) {
      if (!input.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
      }
    });
  }
</script>
<!-- SheetJS — XLSX export with formulas (t375) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="<?= APP_URL ?>/public/js/app.js?v=<?= filemtime(APP_ROOT.'/public/js/app.js') ?>"></script>
<?php if (!empty($pageScript)): ?>
  <script src="<?= APP_URL ?>/public/js/<?= e($pageScript) ?>"></script>
<?php endif; ?>

<!-- Extra page scripts (e.g. mf.js, stocks.js) -->
<?php if (!empty($extraScripts)): ?>
  <?= $extraScripts ?>
<?php endif; ?>

<!-- Inline page scripts -->
<?php if (!empty($inlineScript)): ?>
  <script><?= $inlineScript ?></script>
<?php endif; ?>

</body>
</html>