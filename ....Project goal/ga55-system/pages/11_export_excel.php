<?php
// ============================================================
// GA-55A SYSTEM — pages/11_export_excel.php
// Export bills as Excel-compatible CSV (opens in Excel)
// No external library needed — pure PHP
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$uid      = currentUserId();
$filterFY = clean($_GET['fy']    ?? '');
$filterMo = clean($_GET['month'] ?? '');
$download = isset($_GET['download']);

// Fetch active columns
$earningCols   = $conn->query("SELECT * FROM salary_columns WHERE col_type='earning'   AND is_active=1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$deductionCols = $conn->query("SELECT * FROM salary_columns WHERE col_type='deduction' AND is_active=1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$allCols       = array_merge($earningCols, $deductionCols);

// Build query
$where  = "WHERE b.user_id = $uid";
$params = []; $types = '';
if ($filterFY) { $where .= " AND b.fy=?"; $params[] = $filterFY; $types .= 's'; }
if ($filterMo) { $where .= " AND b.month_no=?"; $params[] = $filterMo; $types .= 's'; }

$stmt = $conn->prepare("SELECT b.*, MONTHNAME(STR_TO_DATE(b.month_no,'%m')) as month_name FROM salary_bills b $where ORDER BY b.bill_date, b.id");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch all bill_values for these bills in one query
$billIds = array_column($bills, 'id');
$colValsMap = [];
if ($billIds) {
    $ids = implode(',', $billIds);
    $vals = $conn->query("SELECT bv.bill_id, sc.col_key, bv.amount FROM bill_values bv JOIN salary_columns sc ON sc.id=bv.col_id WHERE bv.bill_id IN ($ids)")->fetch_all(MYSQLI_ASSOC);
    foreach ($vals as $v) $colValsMap[$v['bill_id']][$v['col_key']] = $v['amount'];
}

// ── DOWNLOAD ──
if ($download) {
    $fname = 'GA55_Export_' . ($filterFY ?: 'All') . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output','w');
    // BOM for Excel UTF-8
    fputs($out, "\xEF\xBB\xBF");

    // Headers
    $row = ['Bill No','Bill Date','TV No','TV Date','FY','Month','Remark'];
    foreach ($earningCols   as $c) $row[] = $c['label'];
    $row[] = 'Gross Amount';
    foreach ($deductionCols as $c) $row[] = $c['label'];
    $row[] = 'Total Deduction';
    $row[] = 'Net Payable';
    fputcsv($out, $row);

    // Data rows
    $tGross=0; $tDed=0; $tNet=0;
    foreach ($bills as $b) {
        $r = [
            $b['bill_no'],
            date('d-m-Y',strtotime($b['bill_date'])),
            $b['tv_no'],
            date('d-m-Y',strtotime($b['tv_date'])),
            $b['fy'],
            $b['month_name'],
            $b['remark']
        ];
        $cv = $colValsMap[$b['id']] ?? [];
        foreach ($earningCols   as $c) $r[] = number_format($cv[$c['col_key']] ?? 0, 2, '.', '');
        $r[] = number_format($b['gross_amount'],    2, '.', '');
        foreach ($deductionCols as $c) $r[] = number_format($cv[$c['col_key']] ?? 0, 2, '.', '');
        $r[] = number_format($b['total_deduction'], 2, '.', '');
        $r[] = number_format($b['net_payable'],     2, '.', '');
        fputcsv($out, $r);
        $tGross += $b['gross_amount']; $tDed += $b['total_deduction']; $tNet += $b['net_payable'];
    }
    // Grand Total row
    $gr = ['GRAND TOTAL','','','','','',''];
    foreach ($earningCols as $c) {
        $s = array_sum(array_map(fn($b)=>$colValsMap[$b['id']][$c['col_key']] ?? 0, $bills));
        $gr[] = number_format($s, 2, '.', '');
    }
    $gr[] = number_format($tGross, 2, '.', '');
    foreach ($deductionCols as $c) {
        $s = array_sum(array_map(fn($b)=>$colValsMap[$b['id']][$c['col_key']] ?? 0, $bills));
        $gr[] = number_format($s, 2, '.', '');
    }
    $gr[] = number_format($tDed, 2, '.', '');
    $gr[] = number_format($tNet, 2, '.', '');
    fputcsv($out, $gr);
    fclose($out);
    exit;
}

// ── PAGE ──
$pageTitle  = 'Export Excel';
$activePage = 'export';
$fyList     = getFYList();
$months     = getMonthList();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title">Export Excel</div>
            <div class="page-sub">GA-55A data download karein Excel format me</div>
        </div>
    </div>
    <div class="page-content">
        <div class="grid-2">
            <!-- Filter -->
            <div class="card">
                <div class="card-header"><span class="card-title"><i class="ti ti-filter" style="vertical-align:-2px;margin-right:5px"></i>Filter & Export</span></div>
                <div class="card-body">
                    <form method="GET">
                        <div class="form-group mb-md">
                            <label class="form-label">Financial Year</label>
                            <select name="fy" class="form-control">
                                <option value="">All FY (saara data)</option>
                                <?php foreach ($fyList as $fy): ?>
                                <option value="<?= $fy ?>" <?= $filterFY===$fy?'selected':'' ?>><?= $fy ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-md">
                            <label class="form-label">Month</label>
                            <select name="month" class="form-control">
                                <option value="">All Months</option>
                                <?php foreach ($months as $n=>$m): ?>
                                <option value="<?= $n ?>" <?= $filterMo===$n?'selected':'' ?>><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button type="submit" class="btn btn-outline">
                                <i class="ti ti-eye"></i> Preview
                            </button>
                            <button type="submit" name="download" value="1" class="btn btn-primary">
                                <i class="ti ti-download"></i> Download Excel
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Summary -->
            <div class="card">
                <div class="card-header"><span class="card-title"><i class="ti ti-chart-bar" style="vertical-align:-2px;margin-right:5px"></i>Export Summary</span></div>
                <div class="card-body">
                    <div class="amount-section">
                        <div class="amount-row">
                            <span class="amount-label">Total Bills</span>
                            <span class="amount-val"><?= count($bills) ?></span>
                        </div>
                        <div class="amount-row">
                            <span class="amount-label">Total Gross</span>
                            <span class="amount-val">₹<?= indianFormat(array_sum(array_column($bills,'gross_amount'))) ?></span>
                        </div>
                        <div class="amount-row">
                            <span class="amount-label">Total Deduction</span>
                            <span class="amount-val" style="color:var(--clr-error)">₹<?= indianFormat(array_sum(array_column($bills,'total_deduction'))) ?></span>
                        </div>
                        <div class="amount-row net">
                            <span>Total Net Payable</span>
                            <span>₹<?= indianFormat(array_sum(array_column($bills,'net_payable'))) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview Table -->
        <?php if (!empty($bills)): ?>
        <div class="card" style="margin-top:14px">
            <div class="card-header">
                <span class="card-title">Preview — <?= count($bills) ?> records</span>
                <a href="?fy=<?= $filterFY ?>&month=<?= $filterMo ?>&download=1" class="btn btn-primary btn-sm">
                    <i class="ti ti-download"></i> Download
                </a>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Bill No</th>
                            <th>Bill Date</th>
                            <th>TV No</th>
                            <th>FY</th>
                            <th>Month</th>
                            <?php foreach ($earningCols as $c): ?><th class="text-right"><?= clean($c['label']) ?></th><?php endforeach; ?>
                            <th class="text-right">Gross</th>
                            <?php foreach ($deductionCols as $c): ?><th class="text-right"><?= clean($c['label']) ?></th><?php endforeach; ?>
                            <th class="text-right">Total Ded.</th>
                            <th class="text-right">Net Payable</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bills as $b):
                        $cv = $colValsMap[$b['id']] ?? [];
                    ?>
                    <tr>
                        <td><?= clean($b['bill_no']) ?></td>
                        <td><?= date('d-m-Y',strtotime($b['bill_date'])) ?></td>
                        <td><?= clean($b['tv_no']) ?></td>
                        <td><?= clean($b['fy']) ?></td>
                        <td><?= clean($b['month_name']) ?></td>
                        <?php foreach ($earningCols as $c): ?>
                        <td class="text-right"><?= indianFormat($cv[$c['col_key']] ?? 0) ?></td>
                        <?php endforeach; ?>
                        <td class="text-right"><strong>₹<?= indianFormat($b['gross_amount']) ?></strong></td>
                        <?php foreach ($deductionCols as $c): ?>
                        <td class="text-right"><?= indianFormat($cv[$c['col_key']] ?? 0) ?></td>
                        <?php endforeach; ?>
                        <td class="text-right" style="color:var(--clr-error)">₹<?= indianFormat($b['total_deduction']) ?></td>
                        <td class="text-right"><strong style="color:var(--clr-accent)">₹<?= indianFormat($b['net_payable']) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="text-right">Grand Total</td>
                            <?php
                            $tGross=0; $tDed=0; $tNet=0;
                            foreach ($earningCols as $c):
                                $s=array_sum(array_map(fn($b)=>$colValsMap[$b['id']][$c['col_key']]??0,$bills));
                                $tGross+=$s;
                            ?>
                            <td class="text-right">₹<?= indianFormat($s) ?></td>
                            <?php endforeach; ?>
                            <td class="text-right">₹<?= indianFormat(array_sum(array_column($bills,'gross_amount'))) ?></td>
                            <?php foreach ($deductionCols as $c):
                                $s=array_sum(array_map(fn($b)=>$colValsMap[$b['id']][$c['col_key']]??0,$bills));
                                $tDed+=$s;
                            ?>
                            <td class="text-right">₹<?= indianFormat($s) ?></td>
                            <?php endforeach; ?>
                            <td class="text-right">₹<?= indianFormat(array_sum(array_column($bills,'total_deduction'))) ?></td>
                            <td class="text-right">₹<?= indianFormat(array_sum(array_column($bills,'net_payable'))) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
