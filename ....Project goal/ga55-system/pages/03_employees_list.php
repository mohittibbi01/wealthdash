<?php
// ============================================================
// GA-55A SYSTEM — pages/03_employees_list.php
// Logged-in user apna profile dekhega aur edit karega
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle  = 'My Profile';
$activePage = 'employees';
$uid        = currentUserId();
$flash      = getFlash();

// Fetch this user's employee record
$stmt = $conn->prepare("SELECT e.*, u.username, u.name as user_name
    FROM employees e
    JOIN users u ON u.id = e.user_id
    WHERE e.user_id = ?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$emp = $stmt->get_result()->fetch_assoc();
$stmt->close();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title">My Profile</div>
            <div class="page-sub">Apni personal aur banking details manage karein</div>
        </div>
        <div class="topbar-right">
            <a href="04_employees_edit.php" class="btn btn-primary btn-sm">
                <i class="ti ti-edit"></i> Edit Profile
            </a>
        </div>
    </div>

    <div class="page-content">

        <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>" data-autohide>
            <i class="ti ti-<?= $flash['type']==='success'?'check':'alert-circle' ?>"></i>
            <?= clean($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if (!$emp): ?>
        <div class="alert alert-warning">
            <i class="ti ti-alert-triangle"></i>
            Aapka profile abhi tak setup nahi hua. Pehle profile complete karein.
        </div>
        <div style="text-align:center;padding:40px">
            <a href="04_employees_edit.php" class="btn btn-primary">
                <i class="ti ti-user-plus"></i> Profile Setup Karein
            </a>
        </div>
        <?php else: ?>

        <div class="grid-2 mb-md">
            <!-- Personal Details -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="ti ti-user" style="vertical-align:-2px;margin-right:5px"></i>Personal Details</span>
                </div>
                <div class="card-body">
                    <?php
                    $fields = [
                        'Employee Code'  => $emp['emp_code'],
                        'Full Name'      => $emp['name'],
                        'Designation'    => $emp['designation'],
                        'Department'     => $emp['department'],
                        'DDO Name'       => $emp['ddo_name'],
                        'DDO Code'       => $emp['ddo_code'],
                        'Mobile'         => $emp['mobile'],
                        'Email'          => $emp['email'],
                    ];
                    foreach ($fields as $label => $val): ?>
                    <div style="display:flex;padding:7px 0;border-bottom:1px solid var(--clr-border);font-size:12px">
                        <span style="color:var(--clr-text-muted);width:140px;flex-shrink:0"><?= $label ?></span>
                        <span style="color:var(--clr-text-main);font-weight:500"><?= clean($val ?: '—') ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Financial & Bank Details -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="ti ti-credit-card" style="vertical-align:-2px;margin-right:5px"></i>Financial & Bank Details</span>
                </div>
                <div class="card-body">
                    <?php
                    $fields2 = [
                        'GPF Number'     => $emp['gpf_no'],
                        'PAN Number'     => $emp['pan_no'],
                        'RGHS Number'    => $emp['rghs_no'],
                        'Bank Name'      => $emp['bank_name'],
                        'Account Number' => $emp['bank_account'],
                        'IFSC Code'      => $emp['bank_ifsc'],
                    ];
                    foreach ($fields2 as $label => $val): ?>
                    <div style="display:flex;padding:7px 0;border-bottom:1px solid var(--clr-border);font-size:12px">
                        <span style="color:var(--clr-text-muted);width:140px;flex-shrink:0"><?= $label ?></span>
                        <span style="color:var(--clr-text-main);font-weight:500;font-family:<?= in_array($label,['PAN Number','Account Number','IFSC Code'])?'var(--font-mono)':'inherit' ?>"><?= clean($val ?: '—') ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
