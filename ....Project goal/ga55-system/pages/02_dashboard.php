<?php
// ============================================================
// GA-55A SYSTEM — pages/02_dashboard.php
// Main dashboard
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$uid        = currentUserId();

// Stats for this user
$totalBills = $conn->query("SELECT COUNT(*) FROM salary_bills WHERE user_id = $uid")->fetch_row()[0];
$totalGross = $conn->query("SELECT COALESCE(SUM(gross_amount),0) FROM salary_bills WHERE user_id = $uid")->fetch_row()[0];
$totalNet   = $conn->query("SELECT COALESCE(SUM(net_payable),0) FROM salary_bills WHERE user_id = $uid")->fetch_row()[0];
$totalDed   = $conn->query("SELECT COALESCE(SUM(total_deduction),0) FROM salary_bills WHERE user_id = $uid")->fetch_row()[0];

// Current FY bills
$currentFY  = date('Y') . '-' . substr(date('Y')+1, -2);
if ((int)date('m') < 4) $currentFY = (date('Y')-1) . '-' . substr(date('Y'), -2);
$fyBills    = $conn->query("SELECT COUNT(*) FROM salary_bills WHERE user_id=$uid AND fy='$currentFY'")->fetch_row()[0];

// Recent 5 bills
$recentBills = $conn->query("SELECT b.*, DATE_FORMAT(b.bill_date,'%d-%m-%Y') as bill_date_fmt,
    DATE_FORMAT(b.bill_date,'%M') as month_name
    FROM salary_bills b WHERE b.user_id = $uid
    ORDER BY b.created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// FY wise summary
$fySummary = $conn->query("SELECT fy, COUNT(*) as bills,
    SUM(gross_amount) as gross, SUM(net_payable) as net
    FROM salary_bills WHERE user_id=$uid
    GROUP BY fy ORDER BY fy DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

$flash = getFlash();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title">Dashboard</div>
            <div class="page-sub">FY <?= $currentFY ?> | Namaste, <?= clean(currentUserName()) ?>!</div>
        </div>
        <div class="topbar-right">
            <span class="topbar-badge"><i class="ti ti-calendar" style="vertical-align:-2px"></i> <?= date('d M Y') ?></span>
            <a href="06_bill_entry.php" class="btn btn-primary btn-sm">
                <i class="ti ti-plus"></i> New Bill
            </a>
        </div>
    </div>

    <div class="page-content">

        <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>">
            <i class="ti ti-<?= $flash['type']==='success'?'check':'alert-circle' ?>"></i>
            <?= clean($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon si-green" style="background:var(--clr-success-bg);color:var(--clr-success)">
                    <i class="ti ti-file-invoice"></i>
                </div>
                <div class="stat-label">Total Bills</div>
                <div class="stat-value"><?= $totalBills ?></div>
                <div class="stat-sub">Current FY: <?= $fyBills ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--clr-info-bg);color:var(--clr-info);float:right;width:32px;height:32px;border-radius:4px;display:flex;align-items:center;justify-content:center;">
                    <i class="ti ti-cash"></i>
                </div>
                <div class="stat-label">Total Gross Amount</div>
                <div class="stat-value" style="font-size:18px">₹<?= indianFormat($totalGross) ?></div>
                <div class="stat-sub">All time</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--clr-success-bg);color:var(--clr-success);float:right;width:32px;height:32px;border-radius:4px;display:flex;align-items:center;justify-content:center;">
                    <i class="ti ti-wallet"></i>
                </div>
                <div class="stat-label">Total Net Payable</div>
                <div class="stat-value" style="font-size:18px">₹<?= indianFormat($totalNet) ?></div>
                <div class="stat-sub">All time</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--clr-warning-bg);color:var(--clr-warning);float:right;width:32px;height:32px;border-radius:4px;display:flex;align-items:center;justify-content:center;">
                    <i class="ti ti-minus"></i>
                </div>
                <div class="stat-label">Total Deductions</div>
                <div class="stat-value" style="font-size:18px">₹<?= indianFormat($totalDed) ?></div>
                <div class="stat-sub">All time</div>
            </div>
        </div>

        <div class="grid-2 mb-lg">
            <!-- Recent Bills -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="ti ti-file-invoice" style="vertical-align:-2px;margin-right:5px"></i>Recent Bills</span>
                    <a href="07_bill_list.php" class="btn btn-outline btn-sm">View All</a>
                </div>
                <div style="overflow-x:auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Bill No</th>
                            <th>Month</th>
                            <th>FY</th>
                            <th class="text-right">Net Payable</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recentBills)): ?>
                        <tr><td colspan="4" class="text-center" style="color:var(--clr-text-muted);padding:20px">
                            Koi bill nahi mila. <a href="06_bill_entry.php">Pehla bill add karein</a>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($recentBills as $b): ?>
                        <tr>
                            <td><?= clean($b['bill_no']) ?></td>
                            <td><?= clean($b['month_name']) ?></td>
                            <td><?= clean($b['fy']) ?></td>
                            <td class="text-right">₹<?= indianFormat($b['net_payable']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- FY Summary -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="ti ti-chart-bar" style="vertical-align:-2px;margin-right:5px"></i>FY-wise Summary</span>
                    <a href="10_report_fy_summary.php" class="btn btn-outline btn-sm">Full Report</a>
                </div>
                <div class="card-body">
                <?php if (empty($fySummary)): ?>
                    <div class="empty-state"><i class="ti ti-chart-bar"></i><p>Data nahi hai abhi</p></div>
                <?php else: ?>
                    <?php
                    $maxNet = max(array_column($fySummary, 'net'));
                    foreach ($fySummary as $fy):
                        $pct = $maxNet > 0 ? round(($fy['net'] / $maxNet) * 100) : 0;
                    ?>
                    <div style="margin-bottom:10px">
                        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px">
                            <span style="color:var(--clr-text-main);font-weight:500"><?= $fy['fy'] ?></span>
                            <span style="color:var(--clr-accent);font-weight:600">₹<?= indianFormat($fy['net']) ?></span>
                        </div>
                        <div style="background:var(--clr-border);border-radius:4px;height:6px">
                            <div style="width:<?= $pct ?>%;background:var(--clr-accent);height:100%;border-radius:4px;transition:width 0.4s"></div>
                        </div>
                        <div style="font-size:9px;color:var(--clr-text-muted);margin-top:2px"><?= $fy['bills'] ?> bills</div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid-3">
            <a href="06_bill_entry.php" class="card" style="padding:14px;display:flex;align-items:center;gap:12px;text-decoration:none">
                <div style="width:38px;height:38px;border-radius:8px;background:var(--clr-success-bg);color:var(--clr-success);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">
                    <i class="ti ti-file-plus"></i>
                </div>
                <div>
                    <div style="font-size:12px;font-weight:600;color:var(--clr-text-main)">New Bill Entry</div>
                    <div style="font-size:10px;color:var(--clr-text-muted)">GA-55A manual entry</div>
                </div>
            </a>
            <a href="08_csv_import.php" class="card" style="padding:14px;display:flex;align-items:center;gap:12px;text-decoration:none">
                <div style="width:38px;height:38px;border-radius:8px;background:var(--clr-warning-bg);color:var(--clr-warning);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">
                    <i class="ti ti-upload"></i>
                </div>
                <div>
                    <div style="font-size:12px;font-weight:600;color:var(--clr-text-main)">CSV Import</div>
                    <div style="font-size:10px;color:var(--clr-text-muted)">Bulk upload karo</div>
                </div>
            </a>
            <a href="11_export_excel.php" class="card" style="padding:14px;display:flex;align-items:center;gap:12px;text-decoration:none">
                <div style="width:38px;height:38px;border-radius:8px;background:var(--clr-info-bg);color:var(--clr-info);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">
                    <i class="ti ti-table-export"></i>
                </div>
                <div>
                    <div style="font-size:12px;font-weight:600;color:var(--clr-text-main)">Export Excel</div>
                    <div style="font-size:10px;color:var(--clr-text-muted)">Report download karo</div>
                </div>
            </a>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>
