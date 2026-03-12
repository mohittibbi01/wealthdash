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

<!-- ===========================
     SCRIPTS
     =========================== -->
<script>
  // Global WealthDash config object used by all JS modules
  window.WD = {
    appUrl           : '<?= e(APP_URL) ?>',
    csrf             : '<?= e(csrf_token()) ?>',
    selectedPortfolio: <?= (int)($_SESSION['selected_portfolio_id'] ?? 0) ?>,
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
<script src="<?= APP_URL ?>/public/js/app.js?v=2"></script>
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