<?php
// ============================================================
// GA-55A SYSTEM — includes/sidebar.php
// Left navigation — har protected page pe include karo
// $activePage variable set karo upar se (e.g. 'dashboard')
// ============================================================
if (!isset($activePage)) $activePage = '';
$name    = currentUserName();
$role    = currentUserRole();
$initials = strtoupper(substr($name, 0, 2));

function navItem($href, $icon, $label, $page, $activePage) {
    $cls = ($activePage === $page) ? 'nav-item active' : 'nav-item';
    echo "<a href='" . BASE_URL . "/pages/$href' class='$cls'>
            <i class='ti ti-$icon'></i> $label
          </a>";
}
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <div class="logo-icon"><i class="ti ti-building-bank"></i></div>
        <div class="logo-title"><?= APP_NAME ?></div>
        <div class="logo-sub">Government Salary System</div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <?php navItem('02_dashboard.php',       'layout-dashboard', 'Dashboard',       'dashboard',   $activePage) ?>
        <?php navItem('03_employees_list.php',  'user-circle',      'My Profile',      'employees',   $activePage) ?>

        <div class="nav-section">Bills</div>
        <?php navItem('06_bill_entry.php',      'file-plus',        'New Bill Entry',  'bill_entry',  $activePage) ?>
        <?php navItem('07_bill_list.php',       'file-invoice',     'All Bills',       'bill_list',   $activePage) ?>
        <?php navItem('08_csv_import.php',      'upload',           'CSV Import',      'csv_import',  $activePage) ?>

        <div class="nav-section">Reports</div>
        <?php navItem('09_report_ga55a.php',    'report',           'GA-55A Report',   'report_ga55', $activePage) ?>
        <?php navItem('10_report_fy_summary.php','chart-bar',       'FY Summary',      'report_fy',   $activePage) ?>
        <?php navItem('11_export_excel.php',    'table-export',     'Export Excel',    'export',      $activePage) ?>

        <?php if (isAdmin()): ?>
        <div class="nav-section">Admin</div>
        <?php navItem('12_admin_columns.php',   'columns',          'Manage Columns',  'admin_cols',  $activePage) ?>
        <?php navItem('13_admin_users.php',     'users-cog',        'Manage Users',    'admin_users', $activePage) ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-bottom">
        <div class="sb-user">
            <div class="sb-avatar"><?= $initials ?></div>
            <div>
                <div class="sb-uname"><?= clean($name) ?></div>
                <div class="sb-role"><?= ucfirst($role) ?></div>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/pages/14_logout.php" class="sb-logout">
            <i class="ti ti-logout"></i> Logout
        </a>
    </div>
</aside>
