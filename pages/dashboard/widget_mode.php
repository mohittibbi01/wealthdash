<?php
/**
 * WealthDash — t373: Widget Mode Page — Compact embeddable view
 * File: pages/dashboard/widget_mode.php
 * Access: ?page=widget_mode&mode=portfolio_mini (standalone, minimal chrome)
 *
 * This page intentionally does NOT use the full layout.php — it's
 * designed to be embedded in an <iframe> or browser extension popup.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$mode = clean($_GET['mode'] ?? 'portfolio_mini');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($currentUser['theme'] ?? 'light') ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title>WealthDash Widget</title>
  <?php
  $appCssMin = APP_ROOT . '/public/css/app.min.css';
  $appCssSrc = APP_ROOT . '/public/css/app.css';
  if (file_exists($appCssMin)) echo '<link rel="stylesheet" href="' . APP_URL . '/public/css/app.min.css?v=' . filemtime($appCssMin) . '">';
  else echo '<link rel="stylesheet" href="' . APP_URL . '/public/css/app.css?v=' . filemtime($appCssSrc) . '">';
  ?>
  <style>
    body{margin:0;padding:12px;background:transparent;}
    .wm-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:14px;padding:16px 18px;max-width:240px;}
    .wm-label{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
    .wm-value{font-size:24px;font-weight:800;color:var(--text);line-height:1.2;}
    .wm-sub{font-size:13px;margin-top:4px;}
    .wm-sub.positive{color:var(--gain);}
    .wm-sub.negative{color:var(--loss);}
    .wm-footer{margin-top:10px;font-size:10px;color:var(--text-muted);}
  </style>
</head>
<body class="app-body">
  <div class="wm-card" id="wm-card">
    <div class="wm-label">Loading…</div>
    <div class="wm-value">—</div>
  </div>
  <div class="wm-footer">WealthDash · <?= date('H:i') ?></div>

  <script>
    window.WD = { appUrl: '<?= e(APP_URL) ?>', csrf: '<?= e(csrf_token()) ?>' };
    window.CSRF_TOKEN = window.WD.csrf;
    function esc(s){return s==null?'':String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
    function apiPost(data) {
      return fetch(window.WD.appUrl + '/api/router.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': window.WD.csrf },
        body: JSON.stringify(data),
      }).then(r => r.json());
    }

    apiPost({ action: 'widget_mode_render', mode: '<?= e($mode) ?>' }).then(r => {
      const card = document.getElementById('wm-card');
      if (!r.ok) { card.innerHTML = '<div class="wm-label">Error</div><div class="wm-value">—</div>'; return; }
      const d = r.data.data;
      card.innerHTML = `
        <div class="wm-label">${esc(d.label)}</div>
        <div class="wm-value">${esc(d.value)}</div>
        <div class="wm-sub ${d.sub_positive ? 'positive' : 'negative'}">${esc(d.sub)}</div>`;
    });

    // Auto-refresh every 60s
    setInterval(() => {
      apiPost({ action: 'widget_mode_render', mode: '<?= e($mode) ?>' }).then(r => {
        if (!r.ok) return;
        const d = r.data.data;
        const card = document.getElementById('wm-card');
        card.innerHTML = `
          <div class="wm-label">${esc(d.label)}</div>
          <div class="wm-value">${esc(d.value)}</div>
          <div class="wm-sub ${d.sub_positive ? 'positive' : 'negative'}">${esc(d.sub)}</div>`;
      });
    }, 60000);
  </script>
</body>
</html>
