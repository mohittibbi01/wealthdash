<?php
/**
 * WealthDash — Sidebar Navigation
 */
if (!defined('WEALTHDASH')) die();

// ── SVG Icon helper ──────────────────────────────────────────────────────────
function si(string $path, string $extra = ''): string {
    return '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" '.$extra.'>'.$path.'</svg>';
}

$navItems = [
    'dashboard' => [
        'label' => 'Dashboard',
        'href'  => APP_URL . '/index.php',
        'icon'  => si('<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>'),
    ],
    'mf' => [
        'label' => 'Mutual Funds',
        'icon'  => si('<path d="M3 3v18h18"/><path d="m7 16 4-8 4 6 4-4"/>'),
        'children' => [
            'mf_holdings'     => [
                'label' => 'Holdings',
                'href'  => APP_URL . '/templates/pages/mf_holdings.php',
                'icon'  => si('<path d="M3 3v18h18"/><path d="M7 12h4v6H7z"/><path d="M14 8h3v10h-3z"/>'),
            ],
            'mf_transactions' => [
                'label' => 'Transactions',
                'href'  => APP_URL . '/templates/pages/mf_transactions.php',
                'icon'  => si('<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/>'),
            ],
            'mf_screener'     => [
                'label' => 'Find Funds',
                'href'  => APP_URL . '/templates/pages/mf_screener.php',
                'icon'  => si('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>'),
            ],
        ],
    ],
    'nps' => [
        'label' => 'NPS',
        'href'  => APP_URL . '/templates/pages/nps.php',
        'icon'  => si('<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>'),
    ],
    'stocks' => [
        'label' => 'Stocks & ETF',
        'href'  => APP_URL . '/templates/pages/stocks.php',
        'icon'  => si('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>'),
    ],
    'market_indexes' => [
        'label' => 'Market Indexes',
        'href'  => APP_URL . '/templates/pages/market_indexes.php',
        'icon'  => si('<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'),
    ],
    'fd' => [
        'label' => 'Fixed Deposits',
        'href'  => APP_URL . '/templates/pages/fd.php',
        'icon'  => si('<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><circle cx="12" cy="15" r="1" fill="currentColor"/>'),
    ],
    'savings' => [
        'label' => 'Savings',
        'href'  => APP_URL . '/templates/pages/savings.php',
        'icon'  => si('<path d="M19 5H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="2"/><path d="M2 10h20"/>'),
    ],
    'post_office' => [
        'label' => 'Post Office',
        'href'  => APP_URL . '/templates/pages/post_office.php',
        'icon'  => si('<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>'),
    ],
    'goals' => [
        'label' => 'Goals',
        'href'  => APP_URL . '/templates/pages/goals.php',
        'icon'  => si('<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>'),
    ],
    'reports' => [
        'label' => 'Reports',
        'icon'  => si('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'),
        'children' => [
            'report_fy'        => [
                'label' => 'FY Gains',
                'href'  => APP_URL . '/templates/pages/report_fy.php',
                'icon'  => si('<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'),
            ],
            'report_tax'       => [
                'label' => 'Tax Planning',
                'href'  => APP_URL . '/templates/pages/report_tax.php',
                'icon'  => si('<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>'),
            ],
            'report_networth'  => [
                'label' => 'Net Worth',
                'href'  => APP_URL . '/templates/pages/report_networth.php',
                'icon'  => si('<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'),
            ],
            'report_rebalance' => [
                'label' => 'Rebalancing',
                'href'  => APP_URL . '/templates/pages/report_rebalancing.php',
                'icon'  => si('<path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/>'),
            ],
            'report_sip'       => [
                'label' => 'MF SIP / SWP',
                'href'  => APP_URL . '/templates/pages/report_sip.php',
                'icon'  => si('<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>'),
            ],
        ],
    ],
];

if (is_admin()) {
    $navItems['admin'] = [
        'label' => 'Admin',
        'href'  => APP_URL . '/templates/pages/admin.php',
        'icon'  => si('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'),
    ];
}
?>

<div class="sidebar-header">
  <a href="<?= APP_URL ?>/index.php" class="sidebar-brand">
    <svg width="32" height="32" viewBox="0 0 40 40" fill="none" style="flex-shrink:0">
      <rect width="40" height="40" rx="10" fill="#2563EB"/>
      <path d="M10 28L18 16L24 22L30 12" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="30" cy="12" r="2" fill="#34D399"/>
    </svg>
    <span class="sidebar-brand-text"><?= e(APP_NAME) ?></span>
  </a>
  <button class="sidebar-close" onclick="closeSidebar()" aria-label="Close sidebar">
    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
    </svg>
  </button>
</div>

<nav class="sidebar-nav">
  <ul class="nav-list">
    <?php foreach ($navItems as $key => $item): ?>

      <?php if (!empty($item['children'])): ?>
        <?php $isGroupActive = $activeSection === $key || array_key_exists($activePage, $item['children']); ?>
        <li class="nav-item has-children <?= $isGroupActive ? 'open' : '' ?>">

          <!-- Parent toggle — shows icon + label + chevron -->
          <button class="nav-link nav-group-toggle"
                  onclick="toggleNavGroup(this)"
                  data-label="<?= e($item['label']) ?>"
                  aria-expanded="<?= $isGroupActive ? 'true' : 'false' ?>">
            <span class="nav-icon"><?= $item['icon'] ?></span>
            <span class="nav-label"><?= e($item['label']) ?></span>
            <svg class="nav-chevron" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>

          <!-- Children — each has its own icon -->
          <ul class="nav-children">
            <?php foreach ($item['children'] as $childKey => $child): ?>
              <li class="nav-item">
                <a href="<?= e($child['href']) ?>"
                   class="nav-link nav-child-link <?= $activePage === $childKey ? 'active' : '' ?>"
                   data-label="<?= e($child['label']) ?>">
                  <span class="nav-icon nav-child-icon"><?= $child['icon'] ?></span>
                  <span class="nav-label"><?= e($child['label']) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </li>

      <?php else: ?>
        <li class="nav-item">
          <a href="<?= e($item['href']) ?>"
             class="nav-link <?= $activePage === $key ? 'active' : '' ?>"
             data-label="<?= e($item['label']) ?>">
            <span class="nav-icon"><?= $item['icon'] ?></span>
            <span class="nav-label"><?= e($item['label']) ?></span>
          </a>
        </li>
      <?php endif; ?>

    <?php endforeach; ?>
  </ul>
</nav>

<!-- Sidebar footer: NAV status -->
<div class="sidebar-footer">
  <?php
  $navDate = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key = 'nav_last_updated'");
  ?>
  <div class="nav-status">
    <span class="nav-status-dot <?= $navDate ? 'dot-green' : 'dot-red' ?>"></span>
    <span class="nav-status-text">
      MF NAV: <?= $navDate ? date(DATE_DISPLAY, strtotime($navDate)) : 'Not updated' ?>
    </span>
  </div>
</div>

<script>
// ── Collapsed sidebar flyout: position sub-menu panels correctly ─────────────
(function() {
  function positionFlyouts() {
    if (!document.body.classList.contains('sidebar-collapsed')) return;
    document.querySelectorAll('.has-children').forEach(item => {
      const toggle  = item.querySelector('.nav-group-toggle');
      const flyout  = item.querySelector('.nav-children');
      if (!toggle || !flyout) return;

      // On mouseenter — position flyout vertically aligned to parent icon
      item.addEventListener('mouseenter', () => {
        if (!document.body.classList.contains('sidebar-collapsed')) return;
        const rect = toggle.getBoundingClientRect();
        flyout.style.top  = rect.top + 'px';
      });
    });
  }

  // Run after DOM ready + after sidebar toggle
  document.addEventListener('DOMContentLoaded', positionFlyouts);

  // Re-run when sidebar collapses/expands
  const observer = new MutationObserver(() => positionFlyouts());
  observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
})();
</script>