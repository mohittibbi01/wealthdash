<?php
// ============================================================
// GA-55A SYSTEM — pages/04_employees_edit.php
// Profile create / edit with validation
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle  = 'Edit Profile';
$activePage = 'employees';
$uid        = currentUserId();
$errors     = [];
$values     = [];

// Fetch existing record
$stmt = $conn->prepare("SELECT * FROM employees WHERE user_id = ?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$emp = $stmt->get_result()->fetch_assoc();
$stmt->close();
$isNew = !$emp;

// Pre-fill values
$defaults = [
    'emp_code'=>'','name'=>'','designation'=>'','department'=>'',
    'ddo_name'=>'','ddo_code'=>'','gpf_no'=>'','pan_no'=>'',
    'rghs_no'=>'','bank_name'=>'','bank_account'=>'','bank_ifsc'=>'',
    'mobile'=>'','email'=>''
];
$values = $emp ? array_merge($defaults, $emp) : $defaults;

// ── VALIDATION RULES ──
function validateProfile($d) {
    $e = [];
    if (trim($d['name']) === '')
        $e['name'] = 'Naam zaroori hai';
    elseif (strlen(trim($d['name'])) < 3)
        $e['name'] = 'Naam kam se kam 3 characters ka hona chahiye';

    if ($d['pan_no'] !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', strtoupper($d['pan_no'])))
        $e['pan_no'] = 'PAN format galat hai (e.g. ABCDE1234F)';

    if ($d['mobile'] !== '' && !preg_match('/^[6-9]\d{9}$/', $d['mobile']))
        $e['mobile'] = 'Mobile number galat hai (10 digit, 6-9 se shuru)';

    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL))
        $e['email'] = 'Email format galat hai';

    if ($d['bank_ifsc'] !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper($d['bank_ifsc'])))
        $e['bank_ifsc'] = 'IFSC format galat hai (e.g. SBIN0001234)';

    if ($d['bank_account'] !== '' && !preg_match('/^\d{9,18}$/', $d['bank_account']))
        $e['bank_account'] = 'Account number 9-18 digits ka hona chahiye';

    return $e;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors['_global'] = 'Invalid request. Refresh karein.';
    } else {
        $d = [
            'emp_code'     => clean($_POST['emp_code'] ?? ''),
            'name'         => clean($_POST['name'] ?? ''),
            'designation'  => clean($_POST['designation'] ?? ''),
            'department'   => clean($_POST['department'] ?? ''),
            'ddo_name'     => clean($_POST['ddo_name'] ?? ''),
            'ddo_code'     => clean($_POST['ddo_code'] ?? ''),
            'gpf_no'       => clean($_POST['gpf_no'] ?? ''),
            'pan_no'       => strtoupper(clean($_POST['pan_no'] ?? '')),
            'rghs_no'      => clean($_POST['rghs_no'] ?? ''),
            'bank_name'    => clean($_POST['bank_name'] ?? ''),
            'bank_account' => clean($_POST['bank_account'] ?? ''),
            'bank_ifsc'    => strtoupper(clean($_POST['bank_ifsc'] ?? '')),
            'mobile'       => clean($_POST['mobile'] ?? ''),
            'email'        => clean($_POST['email'] ?? ''),
        ];
        $values  = $d;
        $errors  = validateProfile($d);

        if (empty($errors)) {
            if ($isNew) {
                $stmt = $conn->prepare("INSERT INTO employees
                    (user_id,emp_code,name,designation,department,ddo_name,ddo_code,
                     gpf_no,pan_no,rghs_no,bank_name,bank_account,bank_ifsc,mobile,email)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('issssssssssssss',
                    $uid,$d['emp_code'],$d['name'],$d['designation'],$d['department'],
                    $d['ddo_name'],$d['ddo_code'],$d['gpf_no'],$d['pan_no'],
                    $d['rghs_no'],$d['bank_name'],$d['bank_account'],$d['bank_ifsc'],
                    $d['mobile'],$d['email']);
            } else {
                $stmt = $conn->prepare("UPDATE employees SET
                    emp_code=?,name=?,designation=?,department=?,ddo_name=?,ddo_code=?,
                    gpf_no=?,pan_no=?,rghs_no=?,bank_name=?,bank_account=?,bank_ifsc=?,
                    mobile=?,email=?
                    WHERE user_id=?");
                $stmt->bind_param('ssssssssssssssi',
                    $d['emp_code'],$d['name'],$d['designation'],$d['department'],
                    $d['ddo_name'],$d['ddo_code'],$d['gpf_no'],$d['pan_no'],
                    $d['rghs_no'],$d['bank_name'],$d['bank_account'],$d['bank_ifsc'],
                    $d['mobile'],$d['email'],$uid);
            }
            $stmt->execute();
            $stmt->close();
            setFlash('success', 'Profile successfully ' . ($isNew ? 'create' : 'update') . ' ho gaya!');
            header('Location: 03_employees_list.php');
            exit;
        }
    }
}

$extraJs = [];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title"><?= $isNew ? 'Profile Setup' : 'Edit Profile' ?></div>
            <div class="page-sub"><?= $isNew ? 'Pehli baar apni details bharein' : 'Apni details update karein' ?></div>
        </div>
        <div class="topbar-right">
            <a href="03_employees_list.php" class="btn btn-light btn-sm"><i class="ti ti-arrow-left"></i> Back</a>
        </div>
    </div>

    <div class="page-content">

        <?php if (!empty($errors['_global'])): ?>
        <div class="alert alert-error"><i class="ti ti-alert-circle"></i><?= $errors['_global'] ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="ti ti-alert-circle"></i>
            <div>
                <strong>Kuch fields me error hai. Neeche dekh ke theek karein:</strong>
                <ul style="margin-top:5px;padding-left:16px">
                    <?php foreach ($errors as $k => $msg): if($k==='_global') continue; ?>
                    <li style="font-size:11px"><?= $msg ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate id="profileForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <div class="card mb-md">
                <div class="card-header">
                    <span class="card-title"><i class="ti ti-user" style="vertical-align:-2px;margin-right:5px"></i>Personal Details</span>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <?php
                        function field($name, $label, $values, $errors, $required=false, $hint='', $type='text', $placeholder='') {
                            $hasErr = isset($errors[$name]);
                            $cls    = $hasErr ? ' is-invalid' : '';
                            $req    = $required ? '<span class="required">*</span>' : '';
                            echo "<div class='form-group'>
                                <label class='form-label' for='$name'>$label $req</label>
                                <input type='$type' id='$name' name='$name'
                                    class='form-control$cls'
                                    value='" . htmlspecialchars($values[$name] ?? '') . "'
                                    placeholder='$placeholder'>
                                " . ($hasErr ? "<span class='field-error'><i class='ti ti-alert-circle'></i>{$errors[$name]}</span>" : '') . "
                                " . ($hint ? "<span class='field-hint'>$hint</span>" : '') . "
                            </div>";
                        }
                        ?>
                        <?php field('emp_code',    'Employee Code', $values, $errors, false, 'Optional') ?>
                        <?php field('name',        'Full Name',     $values, $errors, true,  '', 'text', 'Poora naam dalein') ?>
                        <?php field('designation', 'Designation',   $values, $errors, false, '', 'text', 'e.g. Junior Assistant') ?>
                        <?php field('department',  'Department',    $values, $errors, false, '', 'text', 'e.g. Treasury Office') ?>
                        <?php field('ddo_name',    'DDO Name',      $values, $errors, false) ?>
                        <?php field('ddo_code',    'DDO Code',      $values, $errors, false) ?>
                        <?php field('mobile',      'Mobile Number', $values, $errors, false, '10 digit', 'text', '9876543210') ?>
                        <?php field('email',       'Email',         $values, $errors, false, '', 'email', 'example@gmail.com') ?>
                    </div>
                </div>
            </div>

            <div class="card mb-md">
                <div class="card-header">
                    <span class="card-title"><i class="ti ti-credit-card" style="vertical-align:-2px;margin-right:5px"></i>Financial & Bank Details</span>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <?php field('gpf_no',       'GPF Number',      $values, $errors, false, 'General Provident Fund number') ?>
                        <?php field('pan_no',        'PAN Number',      $values, $errors, false, 'e.g. ABCDE1234F', 'text', 'ABCDE1234F') ?>
                        <?php field('rghs_no',       'RGHS Number',     $values, $errors, false) ?>
                        <?php field('bank_name',     'Bank Name',       $values, $errors, false, '', 'text', 'e.g. State Bank of India') ?>
                        <?php field('bank_account',  'Account Number',  $values, $errors, false, '9-18 digits') ?>
                        <?php field('bank_ifsc',     'IFSC Code',       $values, $errors, false, 'e.g. SBIN0001234', 'text', 'SBIN0001234') ?>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="03_employees_list.php" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-device-floppy"></i>
                    <?= $isNew ? 'Profile Save Karein' : 'Update Karein' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// PAN uppercase auto
document.getElementById('pan_no').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});
document.getElementById('bank_ifsc').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});
// Mobile - only numbers
document.getElementById('mobile').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
});
</script>

<?php include '../includes/footer.php'; ?>
