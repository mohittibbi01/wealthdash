<?php
// ============================================================
// GA-55A SYSTEM — pages/07_bill_list.php
// All bills for logged-in user — search, filter, paginate
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle  = 'All Bills';
$activePage = 'bill_list';
$uid        = currentUserId();
$flash      = getFlash();

// Filters
$filterFY    = clean($_GET['fy']    ?? '');
$filterMonth = clean($_GET['month'] ?? '');
$filterQ     = clean($_GET['q']     ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 15;

// Build WHERE
$where  = "WHERE b.user_id = $uid";
$params = [];
$types  = '';
if ($filterFY)    { $where .= " AND b.fy = ?";       $params[] = $filterFY;    $types .= 's'; }
if ($filterMonth) { $where .= " AND b.month_no = ?";  $params[] = $filterMonth; $types .= 's'; }
if ($filterQ)     { $where .= " AND (b.bill_no LIKE ? OR b.tv_no LIKE ?)";
                    $q = "%$filterQ%"; $params[] = $q; $params[] = $q; $types .= 'ss'; }

// Total count
$countSql  = "SELECT COUNT(*) FROM salary_bills b $where";
$countStmt = $conn->prepare($countSql);
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows  = $countStmt->get_result()->fetch_row()[0];
$countStmt->close();
$totalPages = ceil($totalRows / $perPage);
$offset     = ($page - 1) * $perPage;

// Fetch bills
$sql  = "SELECT b.*, MONTHNAME(STR_TO_DATE(b.month_no,'%m')) as month_name
         FROM salary_bills b $where ORDER BY b.bill_date DESC, b.id DESC LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$fyList  = getFYList();
$months  = getMonthList();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title">All Bills</div>
            <div class="page-sub">Total: <?= $totalRows ?> bills</div>
        </div>
        <div class="topbar-right">
            <a href="06_bill_entry.php" class="btn btn-primary btn-sm"><i class="ti ti-plus"></i> New Bill</a>
            <a href="11_export_excel.php" class="btn btn-outline btn-sm"><i class="ti ti-download"></i> Export</a>
        </div>
    </div>

    <div class="page-content">

        <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>" data-autohide>
            <i class="ti ti-<?= $flash['type']==='success'?'check':'alert-circle' ?>"></i>
            <?= clean($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
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
                            <?php foreach ($months as $num=>$name): ?>
                            <option value="<?= $num ?>" <?= $filterMonth===$num?'selected':'' ?>><?= $name ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;flex:1;min-width:180px">
                        <label class="form-label">Search</label>
                        <input type="text" name="q" class="form-control"
                            placeholder="Bill No ya TV No..." value="<?= $filterQ ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="height:34px">
                        <i class="ti ti-search"></i> Filter
                    </button>
                    <a href="07_bill_list.php" class="btn btn-light btn-sm" style="height:34px">Clear</a>
                </form>
            </div>
        </div>

        <!-- Table -->
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Bill No</th>
                            <th>Bill Date</th>
                            <th>TV No</th>
                            <th>TV Date</th>
                            <th>FY</th>
                            <th>Month</th>
                            <th class="text-right">Gross</th>
                            <th class="text-right">Deduction</th>
                            <th class="text-right">Net Payable</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($bills)): ?>
                        <tr><td colspan="11" class="text-center" style="padding:30px;color:var(--clr-text-muted)">
                            Koi bill nahi mila.
                            <?php if (!$filterFY && !$filterMonth && !$filterQ): ?>
                            <a href="06_bill_entry.php">Pehla bill add karein →</a>
                            <?php endif; ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($bills as $i => $b): ?>
                        <tr>
                            <td style="color:var(--clr-text-muted)"><?= $offset + $i + 1 ?></td>
                            <td><strong><?= clean($b['bill_no']) ?></strong></td>
                            <td><?= date('d-m-Y', strtotime($b['bill_date'])) ?></td>
                            <td><?= clean($b['tv_no']) ?></td>
                            <td><?= date('d-m-Y', strtotime($b['tv_date'])) ?></td>
                            <td><span class="badge badge-info"><?= clean($b['fy']) ?></span></td>
                            <td><?= clean($b['month_name']) ?></td>
                            <td class="text-right">₹<?= indianFormat($b['gross_amount']) ?></td>
                            <td class="text-right" style="color:var(--clr-error)">₹<?= indianFormat($b['total_deduction']) ?></td>
                            <td class="text-right"><strong style="color:var(--clr-accent)">₹<?= indianFormat($b['net_payable']) ?></strong></td>
                            <td>
                                <a href="06_bill_entry.php?edit=<?= $b['id'] ?>" class="btn btn-outline btn-sm" title="Edit">
                                    <i class="ti ti-edit"></i>
                                </a>
                                <a href="09_report_ga55a.php?bill_id=<?= $b['id'] ?>" class="btn btn-light btn-sm" title="View Report">
                                    <i class="ti ti-eye"></i>
                                </a>
                                <a href="../api/api_02_bills.php?action=delete&id=<?= $b['id'] ?>&csrf=<?= csrfToken() ?>"
                                   class="btn btn-danger btn-sm"
                                   data-confirm="Bill No '<?= clean($b['bill_no']) ?>' delete karna chahte hain?"
                                   title="Delete">
                                    <i class="ti ti-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($bills)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="7" class="text-right">Page Total:</td>
                            <td class="text-right">₹<?= indianFormat(array_sum(array_column($bills,'gross_amount'))) ?></td>
                            <td class="text-right">₹<?= indianFormat(array_sum(array_column($bills,'total_deduction'))) ?></td>
                            <td class="text-right">₹<?= indianFormat(array_sum(array_column($bills,'net_payable'))) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div style="padding:12px 16px;border-top:1px solid var(--clr-border)">
                <div class="pagination">
                    <?php
                    $qStr = http_build_query(['fy'=>$filterFY,'month'=>$filterMonth,'q'=>$filterQ]);
                    if ($page > 1): ?>
                    <a href="?<?= $qStr ?>&page=<?= $page-1 ?>" class="page-btn"><i class="ti ti-chevron-left"></i></a>
                    <?php endif;
                    for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
                    <a href="?<?= $qStr ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                    <?php endfor;
                    if ($page < $totalPages): ?>
                    <a href="?<?= $qStr ?>&page=<?= $page+1 ?>" class="page-btn"><i class="ti ti-chevron-right"></i></a>
                    <?php endif; ?>
                    <span style="font-size:11px;color:var(--clr-text-muted);margin-left:8px">
                        Page <?= $page ?> of <?= $totalPages ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
