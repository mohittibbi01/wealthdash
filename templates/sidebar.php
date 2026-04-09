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
            'mf_holdings'     => ['label' => 'Holdings',     'href' => APP_URL . '/templates/pages/mf_holdings.php',     'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>'],
            'mf_transactions' => ['label' => 'Transactions', 'href' => APP_URL . '/templates/pages/mf_transactions.php', 'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M7 16V4m0 0L3 8m4-4 4 4"/><path d="M17 8v12m0 0 4-4m-4 4-4-4"/></svg>'],
            'mf_screener'     => ['label' => 'Find Funds',   'href' => APP_URL . '/templates/pages/mf_screener.php',     'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>'],
            'mf_report'       => ['label' => 'Report & Tools','href' => APP_URL . '/templates/pages/mf_report.php',      'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>'],
        ],
    ],
    'nps' => [
        'label' => 'NPS',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>',
        'children' => [
            'nps'          => ['label' => 'Holdings',        'href' => APP_URL . '/templates/pages/nps.php',           'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>'],
            'nps_screener' => ['label' => 'Find NPS Scheme', 'href' => APP_URL . '/templates/pages/nps_screener.php',  'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>'],
        ],
    ],
    'stocks' => [
        'label'    => 'Stocks & ETF',
        'icon'     => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
        'children' => [
            'stocks'  => ['label' => 'Stocks',      'href' => APP_URL . '/templates/pages/stocks.php', 'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>'],
            'etf'     => ['label' => 'ETF Holdings', 'href' => APP_URL . '/templates/pages/etf.php',    'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 3H8a2 2 0 0 0-2 2v2h12V5a2 2 0 0 0-2-2z"/></svg>'],
            'nfo'     => ['label' => 'NFO Tracker',  'href' => APP_URL . '/templates/pages/nfo.php',    'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="12" y2="16"/></svg>'],
        ],
    ],
        'market_indexes' => [
        'label' => 'Market Indexes',
        'href'  => APP_URL . '/templates/pages/market_indexes.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10"/></svg>',
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
    'post_office' => [
        'label' => 'Post Office',
        'href'  => APP_URL . '/templates/pages/post_office.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
    ],
    'goals' => [
        'label' => 'Goals',
        'href'  => APP_URL . '/templates/pages/goals.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
    ],
    'banking' => [
        'label'    => 'Banking & Loans',
        'icon'     => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
        'children' => [
            'banks'     => ['label' => 'Bank Accounts', 'href' => APP_URL . '/templates/banks.php', 'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="3" y1="21" x2="21" y2="21"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="5 10 5 3 19 3 19 10"/><line x1="7" y1="21" x2="7" y2="10"/><line x1="17" y1="21" x2="17" y2="10"/></svg>'],
            'loans'     => ['label' => 'Loans',          'href' => APP_URL . '/templates/loans.php', 'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>'],
        ],
    ],
    'insurance' => [
        'label' => 'Insurance',
        'href'  => APP_URL . '/templates/insurance.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    ],
    'epf' => [
        'label' => 'EPF',
        'href'  => APP_URL . '/templates/epf.php',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    ],
    'reports' => [
        'label' => 'Reports',
        'icon'  => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'children' => [
            'report_fy'        => ['label' => 'FY Gains',     'href' => APP_URL . '/templates/pages/report_fy.php',          'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>'],
            'report_tax'       => ['label' => 'Tax Planning', 'href' => APP_URL . '/templates/pages/report_tax.php',         'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="9" y1="14" x2="15" y2="14"/><line x1="9" y1="18" x2="15" y2="18"/></svg>'],
            'report_networth'  => ['label' => 'Net Worth',    'href' => APP_URL . '/templates/pages/report_networth.php',    'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
            'report_rebalance' => ['label' => 'Rebalancing',  'href' => APP_URL . '/templates/pages/report_rebalancing.php', 'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 15l-6-6-6 6"/></svg>'],
            'report_sip'       => ['label' => 'MF SIP/SWP',   'href' => APP_URL . '/templates/pages/report_sip.php',         'icon' => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 12 3-3 3 3 5-5"/></svg>'],
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
      <rect width="40" height="40" rx="10" fill="#4f46e5"/>
      <path d="M10 28L18 16L24 22L30 12" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="30" cy="12" r="2" fill="#a5b4fc"/>
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
                  <?php if (!empty($child['icon'])): ?>
                    <span class="nav-icon nav-child-icon"><?= $child['icon'] ?></span>
                  <?php endif; ?>
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