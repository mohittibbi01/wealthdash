<?php
/**
 * WealthDash — Top Bar
 * Hamburger menu, page title, portfolio selector, theme toggle, user menu
 */
if (!defined('WEALTHDASH')) die();

$portfolios = get_user_portfolios((int)$currentUser['id'], is_admin());
$selectedPortfolioId = $_SESSION['selected_portfolio_id'] ?? ($portfolios[0]['id'] ?? null);
?>

<div class="topbar-left">
  <!-- Hamburger (mobile only) -->
  <button class="topbar-hamburger" onclick="openSidebar()" aria-label="Open menu">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <line x1="3" y1="6" x2="21" y2="6"/>
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>

  <!-- Desktop Sidebar Toggle -->
  <button class="topbar-sidebar-toggle" onclick="toggleSidebarCollapse()" aria-label="Toggle sidebar" title="Toggle sidebar">
    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <line x1="3" y1="6" x2="21" y2="6"/>
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>

  <!-- Page title (breadcrumb area) -->
  <div class="topbar-title">
    <h1 class="page-heading"><?= e($pageTitle) ?></h1>
  </div>
</div>

<div class="topbar-right">

  <!-- Portfolio Selector (Custom Dropdown) -->
  <?php if (!empty($portfolios)):
    $activePortfolio = null;
    foreach ($portfolios as $p) {
      if ($p['id'] == $selectedPortfolioId) { $activePortfolio = $p; break; }
    }
    if (!$activePortfolio) $activePortfolio = $portfolios[0];
  ?>
  <!-- Hidden select for compatibility with reports.js -->
  <select id="portfolioSelect" style="display:none;">
    <?php foreach ($portfolios as $p): ?>
      <option value="<?= e($p['id']) ?>" <?= $p['id'] == $selectedPortfolioId ? 'selected' : '' ?>><?= e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>

  <div class="portfolio-selector-custom" id="portfolioSelectorMenu">
    <button class="portfolio-trigger" onclick="togglePortfolioDropdown()" aria-expanded="false">
      <div class="portfolio-trigger-dot" style="background: <?= e($activePortfolio['color'] ?? '#2563EB') ?>;"></div>
      <span class="portfolio-trigger-name" id="activePfName"><?= e($activePortfolio['name']) ?></span>
      <?php if (isset($activePortfolio['is_owner']) && !$activePortfolio['is_owner']): ?>
        <span class="portfolio-shared-badge">Shared</span>
      <?php endif; ?>
      <svg class="portfolio-trigger-chevron" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    </button>

    <div class="portfolio-dropdown" id="portfolioDropdown">
      <div class="portfolio-dropdown-header">
        <span>My Portfolios</span>
      </div>
      <ul class="portfolio-dropdown-list">
        <?php foreach ($portfolios as $p):
          $isActive = $p['id'] == $selectedPortfolioId;
          $initial  = strtoupper(substr($p['name'], 0, 1));
          $color    = $p['color'] ?? '#2563EB';
          $isShared = isset($p['is_owner']) && !$p['is_owner'];
        ?>
        <li>
          <button class="portfolio-dropdown-item <?= $isActive ? 'active' : '' ?>"
                  onclick="switchPortfolio(<?= (int)$p['id'] ?>)">
            <div class="portfolio-item-icon" style="background: <?= e($color) ?>20; color: <?= e($color) ?>;">
              <?= $initial ?>
            </div>
            <div class="portfolio-item-info">
              <span class="portfolio-item-name"><?= e($p['name']) ?></span>
              <?php if ($isShared): ?>
                <span class="portfolio-item-tag">Shared</span>
              <?php endif; ?>
            </div>
            <?php if ($isActive): ?>
            <svg class="portfolio-item-check" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            <?php endif; ?>
          </button>
        </li>
        <?php endforeach; ?>
      </ul>
      <div class="portfolio-dropdown-footer">
        <button class="portfolio-new-btn" onclick="openNewPortfolioModal(); togglePortfolioDropdown();">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          New Portfolio
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Number Format Toggle -->
  <button class="topbar-btn" id="numFormatToggle" onclick="toggleNumFormat()" title="Toggle number format (Short/Full)">
    <span id="numFormatLabel" style="font-size:11px;font-weight:600;letter-spacing:0.3px;">1.3L</span>
  </button>

  <!-- Theme Toggle -->
  <button class="topbar-btn" id="themeToggle" onclick="toggleTheme()" aria-label="Toggle theme" title="Toggle Dark/Light mode">
    <svg class="icon-sun" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="5"/>
      <line x1="12" y1="1" x2="12" y2="3"/>
      <line x1="12" y1="21" x2="12" y2="23"/>
      <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
      <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
      <line x1="1" y1="12" x2="3" y2="12"/>
      <line x1="21" y1="12" x2="23" y2="12"/>
      <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
      <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
    </svg>
    <svg class="icon-moon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
  </button>

  <!-- Notifications bell (placeholder) -->
  <button class="topbar-btn" aria-label="Notifications" title="Notifications">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
      <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
    </svg>
  </button>

  <!-- User Menu Dropdown -->
  <div class="user-menu" id="userMenu">
    <button class="user-menu-trigger" onclick="toggleUserMenu()" aria-expanded="false" aria-label="User menu">
      <div class="user-avatar">
        <?= strtoupper(substr($currentUser['name'] ?? 'U', 0, 1)) ?>
      </div>
      <span class="user-name"><?= e(explode(' ', $currentUser['name'] ?? '')[0]) ?></span>
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    </button>

    <div class="user-dropdown" id="userDropdown">
      <div class="user-dropdown-header">
        <div class="user-info">
          <div class="user-info-name"><?= e($currentUser['name']) ?></div>
          <div class="user-info-email"><?= e($currentUser['email']) ?></div>
          <?php if ($currentUser['role'] === 'admin'): ?>
          <span class="badge badge-blue">Admin</span>
          <?php endif; ?>
        </div>
      </div>
      <ul class="user-dropdown-list">
        <li><a href="#" class="dropdown-item">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
          Profile Settings
        </a></li>
        <li><a href="#" class="dropdown-item" onclick="openNewPortfolioModal(); return false;">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          New Portfolio
        </a></li>
        <?php if (is_admin()): ?>
        <li><a href="<?= APP_URL ?>/templates/pages/admin.php" class="dropdown-item">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
          </svg>
          Admin Panel
        </a></li>
        <?php endif; ?>
        <li class="dropdown-divider"></li>
        <li><a href="<?= APP_URL ?>/auth/logout.php" class="dropdown-item dropdown-item-danger">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
          Sign Out
        </a></li>
      </ul>
    </div>
  </div>

</div>