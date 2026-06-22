<?php
// ============================================================
// GA-55A SYSTEM — pages/06_bill_entry.php
// New bill entry — dynamic earning + deduction columns
// Uniqueness: user_id + bill_no + tv_no
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle  = 'New Bill Entry';
$activePage = 'bill_entry';
$uid        = currentUserId();
$errors     = [];
$values     = [];

// Fetch dynamic columns
$earningCols   = $conn->query("SELECT * FROM salary_columns WHERE col_type='earning'   AND is_active=1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$deductionCols = $conn->query("SELECT * FROM salary_columns WHERE col_type='deduction' AND is_active=1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$allCols       = array_merge($earningCols, $deductionCols);
$months        = getMonthList();
$fyList        = getFYList();

// Edit mode?
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editBill = null;
if ($editId) {
    $stmt = $conn->prepare("SELECT * FROM salary_bills WHERE id=? AND user_id=?");
    $stmt->bind_param('ii', $editId, $uid);
    $stmt->execute();
    $editBill = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$editBill) { header('Location: 07_bill_list.php'); exit; }
    $pageTitle = 'Edit Bill';

    // Fetch existing values
    $existingVals = [];
    $stmt2 = $conn->prepare("SELECT sc.col_key, bv.amount FROM bill_values bv
        JOIN salary_columns sc ON sc.id = bv.col_id WHERE bv.bill_id=?");
    $stmt2->bind_param('i', $editId);
    $stmt2->execute();
    $rows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();
    foreach ($rows as $r) $existingVals[$r['col_key']] = $r['amount'];
}

// ── VALIDATION ──
function validateBill($d, $cols, $conn, $uid, $editId=0) {
    $e = [];

    if (trim($d['bill_no']) === '')
        $e['bill_no'] = 'Bill No zaroori hai';

    if (trim($d['bill_date']) === '')
        $e['bill_date'] = 'Bill Date zaroori hai';
    elseif (!DateTime::createFromFormat('Y-m-d', $d['bill_date']))
        $e['bill_date'] = 'Date format galat hai';

    if (trim($d['tv_no']) === '')
        $e['tv_no'] = 'TV No zaroori hai';

    if (trim($d['tv_date']) === '')
        $e['tv_date'] = 'TV Date zaroori hai';
    elseif (!DateTime::createFromFormat('Y-m-d', $d['tv_date']))
        $e['tv_date'] = 'Date format galat hai';

    if (trim($d['fy']) === '')
        $e['fy'] = 'Financial Year zaroori hai';

    if (trim($d['month_no']) === '')
        $e['month_no'] = 'Month zaroori hai';

    // Uniqueness check
    if (empty($e['bill_no']) && empty($e['tv_no'])) {
        $excludeClause = $editId ? "AND id != $editId" : '';
        $stmt = $conn->prepare("SELECT id FROM salary_bills
            WHERE user_id=? AND bill_no=? AND tv_no=? $excludeClause LIMIT 1");
        $stmt->bind_param('iss', $uid, $d['bill_no'], $d['tv_no']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0)
            $e['bill_no'] = "Bill No '{$d['bill_no']}' aur TV No '{$d['tv_no']}' ka combination pehle se exist karta hai";
        $stmt->close();
    }

    // Amount fields — must be numeric and >= 0
    foreach ($cols as $col) {
        $key = $col['col_key'];
        $val = $d['amounts'][$key] ?? '';
        if ($val !== '' && (!is_numeric(str_replace(',','',$val)) || (float)str_replace(',','',$val) < 0))
            $e['amt_'.$key] = $col['label'] . ' mein galat amount hai';
    }

    return $e;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors['_global'] = 'Invalid request.';
    } else {
        $d = [
            'bill_no'   => clean($_POST['bill_no']   ?? ''),
            'bill_date' => clean($_POST['bill_date']  ?? ''),
            'tv_no'     => clean($_POST['tv_no']      ?? ''),
            'tv_date'   => clean($_POST['tv_date']    ?? ''),
            'fy'        => clean($_POST['fy']          ?? ''),
            'month_no'  => clean($_POST['month_no']   ?? ''),
            'remark'    => clean($_POST['remark']      ?? ''),
            'amounts'   => [],
        ];
        foreach ($allCols as $col) {
            $d['amounts'][$col['col_key']] = clean($_POST['amt_'.$col['col_key']] ?? '');
        }
        $values = $d;
        $errors = validateBill($d, $allCols, $conn, $uid, $editId);

        if (empty($errors)) {
            // Calculate totals
            $gross = 0; $ded = 0;
            foreach ($earningCols   as $col) $gross += cleanDecimal($d['amounts'][$col['col_key']] ?? 0);
            foreach ($deductionCols as $col) $ded   += cleanDecimal($d['amounts'][$col['col_key']] ?? 0);
            $net = $gross - $ded;

            $conn->begin_transaction();
            try {
                if ($editId) {
                    $stmt = $conn->prepare("UPDATE salary_bills SET
                        bill_no=?,bill_date=?,tv_no=?,tv_date=?,fy=?,month_no=?,
                        gross_amount=?,total_deduction=?,net_payable=?,remark=?
                        WHERE id=? AND user_id=?");
                    $stmt->bind_param('ssssssdddsii',
                        $d['bill_no'],$d['bill_date'],$d['tv_no'],$d['tv_date'],
                        $d['fy'],$d['month_no'],$gross,$ded,$net,$d['remark'],$editId,$uid);
                    $stmt->execute(); $stmt->close();
                    $billId = $editId;
                    // Delete old values
                    $conn->query("DELETE FROM bill_values WHERE bill_id=$billId");
                } else {
                    $stmt = $conn->prepare("INSERT INTO salary_bills
                        (user_id,bill_no,bill_date,tv_no,tv_date,fy,month_no,
                         gross_amount,total_deduction,net_payable,remark)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('issssssddds',
                        $uid,$d['bill_no'],$d['bill_date'],$d['tv_no'],$d['tv_date'],
                        $d['fy'],$d['month_no'],$gross,$ded,$net,$d['remark']);
                    $stmt->execute();
                    $billId = $conn->insert_id;
                    $stmt->close();
                }

                // Insert bill_values
                $stmt2 = $conn->prepare("INSERT INTO bill_values (bill_id,col_id,amount) VALUES (?,?,?)");
                foreach ($allCols as $col) {
                    $amt = cleanDecimal($d['amounts'][$col['col_key']] ?? 0);
                    if ($amt > 0) {
                        $stmt2->bind_param('iid', $billId, $col['id'], $amt);
                        $stmt2->execute();
                    }
                }
                $stmt2->close();
                $conn->commit();
                setFlash('success', 'Bill successfully ' . ($editId ? 'update' : 'save') . ' ho gaya! Bill No: ' . $d['bill_no']);
                header('Location: 07_bill_list.php');
                exit;
            } catch (Exception $ex) {
                $conn->rollback();
                $errors['_global'] = 'Save nahi hua: ' . $ex->getMessage();
            }
        }
    }
}

// For display: use POST values or existing edit values
function getAmt($key, $values, $existingVals) {
    if (!empty($values['amounts'][$key])) return $values['amounts'][$key];
    if (isset($existingVals[$key]))        return number_format($existingVals[$key], 2, '.', '');
    return '';
}

$extraJs = ['02_bill_entry.js'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title"><?= $pageTitle ?></div>
            <div class="page-sub">GA-55A Salary Bill Entry</div>
        </div>
        <div class="topbar-right">
            <a href="07_bill_list.php" class="btn btn-light btn-sm"><i class="ti ti-arrow-left"></i> Back</a>
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
                <strong><?= count($errors) ?> field(s) me error hai:</strong>
                <ul style="margin-top:4px;padding-left:14px">
                <?php foreach ($errors as $k=>$msg): if($k==='_global') continue; ?>
                    <li style="font-size:11px"><?= $msg ?></li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate id="billForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <!-- Bill Header -->
            <div class="card mb-md">
                <div class="card-header">
                    <span class="card-title"><i class="ti ti-file-invoice" style="vertical-align:-2px;margin-right:5px"></i>Bill Details</span>
                </div>
                <div class="card-body">
                    <div class="form-grid-3">
                        <?php
                        function bf($name,$label,$values,$errors,$editBill,$type='text',$req=false,$placeholder='') {
                            $val    = $values[$name] ?? ($editBill[$name] ?? '');
                            $hasErr = isset($errors[$name]);
                            $cls    = $hasErr ? ' is-invalid' : '';
                            $rq     = $req ? '<span class="required">*</span>' : '';
                            echo "<div class='form-group'>
                                <label class='form-label' for='$name'>$label $rq</label>
                                <input type='$type' id='$name' name='$name'
                                    class='form-control$cls' value='".htmlspecialchars($val)."'
                                    placeholder='$placeholder'>
                                ".($hasErr?"<span class='field-error'><i class='ti ti-alert-circle'></i>{$errors[$name]}</span>":'')."
                            </div>";
                        }
                        ?>
                        <?php bf('bill_no',   'Bill No',   $values,$errors,$editBill,'text',true,'e.g. 1024') ?>
                        <?php bf('bill_date', 'Bill Date', $values,$errors,$editBill,'date',true) ?>
                        <?php bf('tv_no',     'TV No',     $values,$errors,$editBill,'text',true,'e.g. 5001') ?>
                        <?php bf('tv_date',   'TV Date',   $values,$errors,$editBill,'date',true) ?>

                        <!-- FY Dropdown -->
                        <div class="form-group">
                            <label class="form-label" for="fy">Financial Year <span class="required">*</span></label>
                            <select id="fy" name="fy" class="form-control<?= isset($errors['fy'])?' is-invalid':'' ?>">
                                <option value="">— Select FY —</option>
                                <?php foreach ($fyList as $fy):
                                    $sel = ($values['fy'] ?? ($editBill['fy'] ?? '')) === $fy ? 'selected' : ''; ?>
                                <option value="<?= $fy ?>" <?= $sel ?>><?= $fy ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if(isset($errors['fy'])): ?>
                            <span class="field-error"><i class="ti ti-alert-circle"></i><?= $errors['fy'] ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Month Dropdown -->
                        <div class="form-group">
                            <label class="form-label" for="month_no">Month <span class="required">*</span></label>
                            <select id="month_no" name="month_no" class="form-control<?= isset($errors['month_no'])?' is-invalid':'' ?>">
                                <option value="">— Select Month —</option>
                                <?php foreach ($months as $num => $name):
                                    $sel = ($values['month_no'] ?? ($editBill['month_no'] ?? '')) === $num ? 'selected' : ''; ?>
                                <option value="<?= $num ?>" <?= $sel ?>><?= $name ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if(isset($errors['month_no'])): ?>
                            <span class="field-error"><i class="ti ti-alert-circle"></i><?= $errors['month_no'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Earnings -->
            <div class="card mb-md">
                <div class="card-header">
                    <span class="card-title" style="color:var(--clr-success)">
                        <i class="ti ti-trending-up" style="vertical-align:-2px;margin-right:5px"></i>
                        Earnings
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-grid-3">
                    <?php foreach ($earningCols as $col):
                        $key = $col['col_key'];
                        $val = getAmt($key, $values, $existingVals ?? []);
                        $hasErr = isset($errors['amt_'.$key]);
                    ?>
                    <div class="form-group">
                        <label class="form-label" for="amt_<?= $key ?>"><?= clean($col['label']) ?></label>
                        <input type="text" id="amt_<?= $key ?>" name="amt_<?= $key ?>"
                            class="form-control num earning-field<?= $hasErr?' is-invalid':'' ?>"
                            value="<?= htmlspecialchars($val) ?>"
                            placeholder="0.00" autocomplete="off"
                            data-label="<?= clean($col['label']) ?>">
                        <?php if($hasErr): ?>
                        <span class="field-error"><i class="ti ti-alert-circle"></i><?= $errors['amt_'.$key] ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Deductions -->
            <div class="card mb-md">
                <div class="card-header">
                    <span class="card-title" style="color:var(--clr-error)">
                        <i class="ti ti-trending-down" style="vertical-align:-2px;margin-right:5px"></i>
                        Deductions
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-grid-3">
                    <?php foreach ($deductionCols as $col):
                        $key = $col['col_key'];
                        $val = getAmt($key, $values, $existingVals ?? []);
                        $hasErr = isset($errors['amt_'.$key]);
                    ?>
                    <div class="form-group">
                        <label class="form-label" for="amt_<?= $key ?>"><?= clean($col['label']) ?></label>
                        <input type="text" id="amt_<?= $key ?>" name="amt_<?= $key ?>"
                            class="form-control num deduction-field<?= $hasErr?' is-invalid':'' ?>"
                            value="<?= htmlspecialchars($val) ?>"
                            placeholder="0.00" autocomplete="off"
                            data-label="<?= clean($col['label']) ?>">
                        <?php if($hasErr): ?>
                        <span class="field-error"><i class="ti ti-alert-circle"></i><?= $errors['amt_'.$key] ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Auto-calculated totals -->
            <div class="card mb-md">
                <div class="card-header">
                    <span class="card-title"><i class="ti ti-calculator" style="vertical-align:-2px;margin-right:5px"></i>Summary (Auto-calculated)</span>
                </div>
                <div class="card-body">
                    <div class="amount-section">
                        <div class="amount-row">
                            <span class="amount-label">Gross Amount (Total Earnings)</span>
                            <span class="amount-val" id="displayGross">₹0.00</span>
                        </div>
                        <div class="amount-row">
                            <span class="amount-label">Total Deductions</span>
                            <span class="amount-val" style="color:var(--clr-error)" id="displayDed">₹0.00</span>
                        </div>
                        <div class="amount-row net">
                            <span>Net Payable Amount</span>
                            <span id="displayNet">₹0.00</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Remark -->
            <div class="card mb-md">
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label" for="remark">Remark (Optional)</label>
                        <textarea id="remark" name="remark" class="form-control"
                            placeholder="Koi note ya remark..."><?= htmlspecialchars($values['remark'] ?? ($editBill['remark'] ?? '')) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="07_bill_list.php" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-device-floppy"></i>
                    <?= $editId ? 'Bill Update Karein' : 'Bill Save Karein' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
