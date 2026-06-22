<?php
// ============================================================
// GA-55A SYSTEM — pages/09_report_ga55a.php
// GA-55A format report — single bill or filtered list
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle  = 'GA-55A Report';
$activePage = 'report_ga55';
$uid        = currentUserId();

// Fetch active columns
$earningCols   = $conn->query("SELECT * FROM salary_columns WHERE col_type='earning'   AND is_active=1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$deductionCols = $conn->query("SELECT * FROM salary_columns WHERE col_type='deduction' AND is_active=1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$allCols       = array_merge($earningCols, $deductionCols);

$filterFY    = clean($_GET['fy']      ?? '');
$filterMonth = clean($_GET['month']   ?? '');
$billId      = (int)($_GET['bill_id'] ?? 0);

// Fetch employee info
$emp = $conn->query("SELECT * FROM employees WHERE user_id=$uid")->fetch_assoc();

// Build bill query
$where = "WHERE b.user_id=$uid";
if ($billId)     $where .= " AND b.id=$billId";
if ($filterFY)   $where .= " AND b.fy='".addslashes($filterFY)."'";
if ($filterMonth)$where .= " AND b.month_no='".addslashes($filterMonth)."'";

$bills = $conn->query("SELECT b.*, MONTHNAME(STR_TO_DATE(b.month_no,'%m')) as month_name
    FROM salary_bills b $where ORDER BY b.fy, b.month_no, b.id")->fetch_all(MYSQLI_ASSOC);

// Fetch bill values
$bids = array_column($bills,'id');
$colValsMap = [];
if ($bids) {
    $ids = implode(',', $bids);
    $rows= $conn->query("SELECT bv.bill_id,sc.col_key,bv.amount FROM bill_values bv JOIN salary_columns sc ON sc.id=bv.col_id WHERE bv.bill_id IN ($ids)")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) $colValsMap[$r['bill_id']][$r['col_key']] = $r['amount'];
}

$fyList  = getFYList();
$months  = getMonthList();
$isPrint = isset($_GET['print']);

if ($isPrint) {
    // Print-only mode — no sidebar
    ?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="UTF-8">
    <title>GA-55A Report</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/01_variables.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/02_reset.css">
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; background: #fff; }
        .report-wrap { max-width: 1100px; margin: 0 auto; padding: 10px; }
        .report-title { text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 4px; }
        .report-sub   { text-align: center; font-size: 11px; margin-bottom: 10px; }
        .emp-info { display: grid; grid-template-columns: repeat(3,1fr); gap: 4px; margin-bottom: 10px; font-size: 10px; border: 1px solid #ccc; padding: 6px; }
        .emp-row { display: flex; gap: 4px; }
        .emp-label { color: #666; min-width: 90px; }
        table { border-collapse: collapse; width: 100%; font-size: 10px; }
        th, td { border: 1px solid #aaa; padding: 4px 6px; }
        th { background: #ddd; font-weight: bold; text-align: center; }
        td.num { text-align: right; }
        tfoot td { background: #eee; font-weight: bold; }
        .no-print { display: none; }
        @media print { @page { size: landscape; margin: 10mm; } }
    </style>
</head>
<body onload="window.print()">
<div class="report-wrap">
    <div class="report-title">Salary Bill — GA-55A Format</div>
    <div class="report-sub">
        <?= clean($emp['ddo_name'] ?? '') ?> | <?= clean($emp['ddo_code'] ?? '') ?>
        <?php if ($filterFY || $filterMonth): ?>
        | FY: <?= $filterFY ?: 'All' ?> <?= ($filterMonth && isset($months[$filterMonth])) ? '| '.$months[$filterMonth] : '' ?>
        <?php endif; ?>
    </div>

    <?php if ($emp): ?>
    <div class="emp-info">
        <div class="emp-row"><span class="emp-label">Name:</span> <strong><?= clean($emp['name']) ?></strong></div>
        <div class="emp-row"><span class="emp-label">Designation:</span> <?= clean($emp['designation']) ?></div>
        <div class="emp-row"><span class="emp-label">Department:</span> <?= clean($emp['department']) ?></div>
        <div class="emp-row"><span class="emp-label">GPF No:</span> <?= clean($emp['gpf_no']) ?></div>
        <div class="emp-row"><span class="emp-label">PAN:</span> <?= clean($emp['pan_no']) ?></div>
        <div class="emp-row"><span class="emp-label">RGHS No:</span> <?= clean($emp['rghs_no']) ?></div>
    </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Bill No</th><th>Bill Date</th><th>TV No</th><th>TV Date</th><th>FY</th><th>Month</th>
                <?php foreach ($earningCols   as $c): ?><th><?= clean($c['label']) ?></th><?php endforeach; ?>
                <th>Gross Amount</th>
                <?php foreach ($deductionCols as $c): ?><th><?= clean($c['label']) ?></th><?php endforeach; ?>
                <th>Total Deduction</th>
                <th>Net Payable</th>
                <?php if (!empty($bills) && $bills[0]['remark']): ?><th>Remark</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($bills as $b):
            $cv = $colValsMap[$b['id']] ?? []; ?>
        <tr>
            <td><?= clean($b['bill_no']) ?></td>
            <td><?= date('d-m-Y',strtotime($b['bill_date'])) ?></td>
            <td><?= clean($b['tv_no']) ?></td>
            <td><?= date('d-m-Y',strtotime($b['tv_date'])) ?></td>
            <td><?= clean($b['fy']) ?></td>
            <td><?= clean($b['month_name']) ?></td>
            <?php foreach ($earningCols   as $c): ?><td class="num"><?= indianFormat($cv[$c['col_key']] ?? 0) ?></td><?php endforeach; ?>
            <td class="num"><strong><?= indianFormat($b['gross_amount']) ?></strong></td>
            <?php foreach ($deductionCols as $c): ?><td class="num"><?= indianFormat($cv[$c['col_key']] ?? 0) ?></td><?php endforeach; ?>
            <td class="num"><?= indianFormat($b['total_deduction']) ?></td>
            <td class="num"><strong><?= indianFormat($b['net_payable']) ?></strong></td>
            <?php if (!empty($bills) && isset($bills[0]['remark'])): ?><td><?= clean($b['remark']) ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" style="text-align:right">Grand Total</td>
                <?php foreach ($earningCols as $c):
                    $s=array_sum(array_map(fn($b)=>$colValsMap[$b['id']][$c['col_key']]??0,$bills)); ?>
                <td class="num"><?= indianFormat($s) ?></td>
                <?php endforeach; ?>
                <td class="num"><?= indianFormat(array_sum(array_column($bills,'gross_amount'))) ?></td>
                <?php foreach ($deductionCols as $c):
                    $s=array_sum(array_map(fn($b)=>$colValsMap[$b['id']][$c['col_key']]??0,$bills)); ?>
                <td class="num"><?= indianFormat($s) ?></td>
                <?php endforeach; ?>
                <td class="num"><?= indianFormat(array_sum(array_column($bills,'total_deduction'))) ?></td>
                <td class="num"><?= indianFormat(array_sum(array_column($bills,'net_payable'))) ?></td>
                <?php if (!empty($bills) && isset($bills[0]['remark'])): ?><td></td><?php endif; ?>
            </tr>
        </tfoot>
    </table>
    <div style="margin-top:30px;display:grid;grid-template-columns:repeat(3,1fr);gap:20px">
        <div style="border-top:1px solid #000;padding-top:5px;text-align:center;font-size:10px">Prepared by</div>
        <div style="border-top:1px solid #000;padding-top:5px;text-align:center;font-size:10px">Checked by</div>
        <div style="border-top:1px solid #000;padding-top:5px;text-align:center;font-size:10px">DDO Signature</div>
    </div>
</div>
</body>
</html>
    <?php exit; }

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title">GA-55A Report</div>
            <div class="page-sub"><?= count($bills) ?> records</div>
        </div>
        <div class="topbar-right">
            <?php
            $pq = http_build_query(['fy'=>$filterFY,'month'=>$filterMonth,'bill_id'=>$billId,'print'=>1]);
            ?>
            <a href="?<?= $pq ?>" target="_blank" class="btn btn-outline btn-sm">
                <i class="ti ti-printer"></i> Print
            </a>
            <a href="11_export_excel.php?fy=<?= $filterFY ?>&month=<?= $filterMonth ?>&download=1" class="btn btn-primary btn-sm">
                <i class="ti ti-download"></i> Excel
            </a>
        </div>
    </div>

    <div class="page-content">
        <!-- Filter -->
        <div class="card mb-md">
            <div class="card-body" style="padding:12px">
                <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                    <div class="form-group" style="margin:0;min-width:120px">
                        <label class="form-label">FY</label>
                        <select name="fy" class="form-control">
                            <option value="">All FY</option>
                            <?php foreach ($fyList as $fy): ?>
                            <option value="<?= $fy ?>" <?= $filterFY===$fy?'selected':'' ?>><?= $fy ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;min-width:130px">
                        <label class="form-label">Month</label>
                        <select name="month" class="form-control">
                            <option value="">All Months</option>
                            <?php foreach ($months as $n=>$m): ?>
                            <option value="<?= $n ?>" <?= $filterMonth===$n?'selected':'' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="height:34px">
                        <i class="ti ti-search"></i> Filter
                    </button>
                    <a href="09_report_ga55a.php" class="btn btn-light btn-sm" style="height:34px">Clear</a>
                </form>
            </div>
        </div>

        <!-- Report Table -->
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Bill No</th><th>Bill Date</th><th>TV No</th><th>FY</th><th>Month</th>
                            <?php foreach ($earningCols   as $c): ?><th class="text-right"><?= clean($c['label']) ?></th><?php endforeach; ?>
                            <th class="text-right" style="background:var(--clr-success-bg)">Gross</th>
                            <?php foreach ($deductionCols as $c): ?><th class="text-right"><?= clean($c['label']) ?></th><?php endforeach; ?>
                            <th class="text-right" style="background:var(--clr-error-bg)">Total Ded.</th>
                            <th class="text-right" style="background:var(--clr-primary-light)">Net Payable</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($bills)): ?>
                        <tr><td colspan="99" class="text-center" style="padding:30px;color:var(--clr-text-muted)">Koi data nahi mila.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bills as $b):
                            $cv = $colValsMap[$b['id']] ?? []; ?>
                        <tr>
                            <td><strong><?= clean($b['bill_no']) ?></strong></td>
                            <td><?= date('d-m-Y',strtotime($b['bill_date'])) ?></td>
                            <td><?= clean($b['tv_no']) ?></td>
                            <td><?= clean($b['fy']) ?></td>
                            <td><?= clean($b['month_name']) ?></td>
                            <?php foreach ($earningCols   as $c): ?><td class="text-right"><?= indianFormat($cv[$c['col_key']] ?? 0) ?></td><?php endforeach; ?>
                            <td class="text-right" style="background:var(--clr-success-bg);font-weight:600">₹<?= indianFormat($b['gross_amount']) ?></td>
                            <?php foreach ($deductionCols as $c): ?><td class="text-right"><?= indianFormat($cv[$c['col_key']] ?? 0) ?></td><?php endforeach; ?>
                            <td class="text-right" style="background:var(--clr-error-bg);color:var(--clr-error)">₹<?= indianFormat($b['total_deduction']) ?></td>
                            <td class="text-right" style="background:var(--clr-primary-light);color:var(--clr-accent);font-weight:700">₹<?= indianFormat($b['net_payable']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($bills)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="text-right">Grand Total</td>
                            <?php foreach ($earningCols as $c):
                                $s=array_sum(array_map(fn($b)=>$colValsMap[$b['id']][$c['col_key']]??0,$bills)); ?>
                            <td class="text-right">₹<?= indianFormat($s) ?></td>
                            <?php endforeach; ?>
                            <td class="text-right">₹<?= indianFormat(array_sum(array_column($bills,'gross_amount'))) ?></td>
                            <?php foreach ($deductionCols as $c):
                                $s=array_sum(array_map(fn($b)=>$colValsMap[$b['id']][$c['col_key']]??0,$bills)); ?>
                            <td class="text-right">₹<?= indianFormat($s) ?></td>
                            <?php endforeach; ?>
                            <td class="text-right">₹<?= indianFormat(array_sum(array_column($bills,'total_deduction'))) ?></td>
                            <td class="text-right">₹<?= indianFormat(array_sum(array_column($bills,'net_payable'))) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
