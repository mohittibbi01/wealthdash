<?php
// ============================================================
// GA-55A SYSTEM — pages/10_report_fy_summary.php
// FY-wise summary report
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle  = 'FY Summary';
$activePage = 'report_fy';
$uid        = currentUserId();

$fySummary = $conn->query("SELECT
    fy,
    COUNT(*) as total_bills,
    SUM(gross_amount) as total_gross,
    SUM(total_deduction) as total_ded,
    SUM(net_payable) as total_net,
    MIN(bill_date) as first_bill,
    MAX(bill_date) as last_bill
    FROM salary_bills WHERE user_id=$uid
    GROUP BY fy ORDER BY fy DESC")->fetch_all(MYSQLI_ASSOC);

$monthSummary = $conn->query("SELECT
    fy, month_no,
    MONTHNAME(STR_TO_DATE(month_no,'%m')) as month_name,
    COUNT(*) as bills,
    SUM(gross_amount) as gross,
    SUM(total_deduction) as ded,
    SUM(net_payable) as net
    FROM salary_bills WHERE user_id=$uid
    GROUP BY fy, month_no ORDER BY fy DESC, month_no")->fetch_all(MYSQLI_ASSOC);

// Group month summary by FY
$byFY = [];
foreach ($monthSummary as $r) $byFY[$r['fy']][] = $r;

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title">FY Summary</div>
            <div class="page-sub">Financial Year wise salary analysis</div>
        </div>
        <div class="topbar-right">
            <a href="11_export_excel.php" class="btn btn-primary btn-sm">
                <i class="ti ti-download"></i> Export Excel
            </a>
        </div>
    </div>

    <div class="page-content">

        <?php if (empty($fySummary)): ?>
        <div class="empty-state">
            <i class="ti ti-chart-bar"></i>
            <p>Abhi koi data nahi. <a href="06_bill_entry.php">Pehla bill add karein</a></p>
        </div>
        <?php else: ?>

        <!-- FY Cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:20px">
        <?php foreach ($fySummary as $fy): ?>
        <div class="card" style="border-top:3px solid var(--clr-accent)">
            <div class="card-body">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <div>
                        <div style="font-size:16px;font-weight:700;color:var(--clr-primary-dark)"><?= $fy['fy'] ?></div>
                        <div style="font-size:10px;color:var(--clr-text-muted)"><?= $fy['total_bills'] ?> bills</div>
                    </div>
                    <span class="badge badge-info"><?= $fy['total_bills'] ?> bills</span>
                </div>
                <div class="divider"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:11px">
                    <div>
                        <div style="color:var(--clr-text-muted);font-size:10px">Gross</div>
                        <div style="font-weight:600">₹<?= indianFormat($fy['total_gross']) ?></div>
                    </div>
                    <div>
                        <div style="color:var(--clr-text-muted);font-size:10px">Deduction</div>
                        <div style="font-weight:600;color:var(--clr-error)">₹<?= indianFormat($fy['total_ded']) ?></div>
                    </div>
                </div>
                <div class="amount-section" style="margin-top:10px;padding:8px 12px">
                    <div class="amount-row net" style="font-size:12px">
                        <span>Net Payable</span>
                        <span>₹<?= indianFormat($fy['total_net']) ?></span>
                    </div>
                </div>
                <div style="margin-top:8px;display:flex;gap:6px">
                    <a href="09_report_ga55a.php?fy=<?= $fy['fy'] ?>" class="btn btn-outline btn-sm" style="flex:1;justify-content:center">
                        <i class="ti ti-eye"></i> View
                    </a>
                    <a href="11_export_excel.php?fy=<?= $fy['fy'] ?>&download=1" class="btn btn-primary btn-sm" style="flex:1;justify-content:center">
                        <i class="ti ti-download"></i> Export
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- Month-wise Breakdown -->
        <?php foreach ($fySummary as $fy):
            $mRows = $byFY[$fy['fy']] ?? [];
            if (empty($mRows)) continue; ?>
        <div class="card mb-md">
            <div class="card-header">
                <span class="card-title">
                    <i class="ti ti-calendar-stats" style="vertical-align:-2px;margin-right:5px"></i>
                    <?= $fy['fy'] ?> — Month-wise
                </span>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="text-right">Bills</th>
                            <th class="text-right">Gross Amount</th>
                            <th class="text-right">Total Deduction</th>
                            <th class="text-right">Net Payable</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mRows as $m): ?>
                    <tr>
                        <td><strong><?= $m['month_name'] ?></strong></td>
                        <td class="text-right"><?= $m['bills'] ?></td>
                        <td class="text-right">₹<?= indianFormat($m['gross']) ?></td>
                        <td class="text-right" style="color:var(--clr-error)">₹<?= indianFormat($m['ded']) ?></td>
                        <td class="text-right" style="color:var(--clr-accent);font-weight:600">₹<?= indianFormat($m['net']) ?></td>
                        <td>
                            <a href="09_report_ga55a.php?fy=<?= $m['fy'] ?>&month=<?= $m['month_no'] ?>" class="btn btn-outline btn-sm">
                                <i class="ti ti-eye"></i>
                            </a>
                            <a href="11_export_excel.php?fy=<?= $m['fy'] ?>&month=<?= $m['month_no'] ?>&download=1" class="btn btn-primary btn-sm">
                                <i class="ti ti-download"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>FY Total</td>
                            <td class="text-right"><?= $fy['total_bills'] ?></td>
                            <td class="text-right">₹<?= indianFormat($fy['total_gross']) ?></td>
                            <td class="text-right">₹<?= indianFormat($fy['total_ded']) ?></td>
                            <td class="text-right">₹<?= indianFormat($fy['total_net']) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
