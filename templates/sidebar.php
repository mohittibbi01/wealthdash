<?php
/**
 * WealthDash — Sidebar Navigation
 */
if (!defined('WEALTHDASH')) die();

$navItems = [
    'dashboard' => [
        'label' => 'Dashboard',
        'href'  => APP_URL . '/index.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
    ],
    'mf' => [
        'label'    => 'Mutual Funds',
        'icon'     => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 16 4-8 4 6 4-4"/></svg>',
        'children' => [
            'mf_holdings'     => ['label' => 'Holdings',     'href' => APP_URL . '/templates/pages/mf_holdings.php'],
            'mf_transactions' => ['label' => 'Transactions', 'href' => APP_URL . '/templates/pages/mf_transactions.php'],
        ],
    ],
    /*'nps' => [
        'label' => 'NPS',
        'href'  => APP_URL . '/templates/pages/nps.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>',
    ],
    'stocks' => [
        'label' => 'Stocks & ETF',
        'href'  => APP_URL . '/templates/pages/stocks.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
    ],
    'fd' => [
        'label' => 'Fixed Deposits',
        'href'  => APP_URL . '/templates/pages/fd.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
    ],
    'savings' => [
        'label' => 'Savings',
        'href'  => APP_URL . '/templates/pages/savings.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 5H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2z"/><path d="M16 10h-1a2 2 0 0 0 0 4h1"/><path d="M2 10h20"/></svg>',
    ],
    'goals' => [
        'label' => 'Goals',
        'href'  => APP_URL . '/templates/pages/goals.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
    ],*/
    'reports' => [
        'label' => 'Reports',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'children' => [
            'report_fy'        => ['label' => 'FY Gains',      'href' => APP_URL . '/templates/pages/report_fy.php'],
            'report_tax'       => ['label' => 'Tax Planning',  'href' => APP_URL . '/templates/pages/report_tax.php'],
            'report_networth'  => ['label' => 'Net Worth',     'href' => APP_URL . '/templates/pages/report_networth.php'],
            'report_rebalance' => ['label' => 'Rebalancing',   'href' => APP_URL . '/templates/pages/report_rebalancing.php'],
            'report_sip'       => ['label' => 'MF SIP/SWP',    'href' => APP_URL . '/templates/pages/report_sip.php'],
        ],
    ],
];

if (is_admin()) {
    $navItems['admin'] = [
        'label' => 'Admin',
        'href'  => APP_URL . '/templates/pages/admin.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>',
    ];
}
?>

<div class="sidebar-header">
  <a href="<?= APP_URL ?>/index.php" class="sidebar-brand">
    <svg width="32" height="32" viewBox="0 0 40 40" fill="none">
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
          <button class="nav-link nav-group-toggle" onclick="toggleNavGroup(this)" data-label="<?= e($item['label']) ?>" aria-expanded="<?= $isGroupActive ? 'true' : 'false' ?>">
            <span class="nav-icon"><?= $item['icon'] ?></span>
            <span class="nav-label"><?= e($item['label']) ?></span>
            <svg class="nav-chevron" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>
          <ul class="nav-children">
            <?php foreach ($item['children'] as $childKey => $child): ?>
              <li class="nav-item">
                <a href="<?= e($child['href']) ?>"
                   class="nav-link nav-child-link <?= $activePage === $childKey ? 'active' : '' ?>">
                  <span class="nav-label"><?= e($child['label']) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </li>

      <?php else: ?>
        <li class="nav-item">
          <a href="<?= e($item['href']) ?>"
             class="nav-link <?= $activePage === $key ? 'active' : '' ?>" data-label="<?= e($item['label']) ?>">
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