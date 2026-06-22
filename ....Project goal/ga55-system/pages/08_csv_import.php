<?php
// ============================================================
// GA-55A SYSTEM — pages/08_csv_import.php
// CSV Upload → Validate → Show errors → User fix → Save to DB
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle  = 'CSV Import';
$activePage = 'csv_import';
$uid        = currentUserId();
$flash      = getFlash();

// Fetch active columns for template download and validation
$earningCols   = $conn->query("SELECT * FROM salary_columns WHERE col_type='earning'   AND is_active=1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$deductionCols = $conn->query("SELECT * FROM salary_columns WHERE col_type='deduction' AND is_active=1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$allCols       = array_merge($earningCols, $deductionCols);

// Check if temp rows exist for this user
$sessionKey = $_SESSION['import_session'] ?? '';
$tempRows   = [];
$hasTemp    = false;

if ($sessionKey) {
    $stmt = $conn->prepare("SELECT ti.*, GROUP_CONCAT(tiv.col_key,'|',COALESCE(tiv.raw_value,''),'|',tiv.has_error,'|',COALESCE(tiv.error_msg,'') ORDER BY tiv.col_key SEPARATOR ';;') as col_data
        FROM temp_import ti
        LEFT JOIN temp_import_values tiv ON tiv.temp_id = ti.id
        WHERE ti.session_key=? AND ti.user_id=?
        GROUP BY ti.id ORDER BY ti.row_number");
    $stmt->bind_param('si', $sessionKey, $uid);
    $stmt->execute();
    $tempRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $hasTemp = !empty($tempRows);
}

// ── STEP 1: File Upload + Parse ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.'); header('Location: 08_csv_import.php'); exit;
    }
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'File upload error.'); header('Location: 08_csv_import.php'); exit;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        setFlash('error', 'Sirf CSV file allowed hai.'); header('Location: 08_csv_import.php'); exit;
    }

    // Clear old temp data for this user
    $conn->query("DELETE FROM temp_import WHERE user_id=$uid");

    // New session key
    $sessionKey = bin2hex(random_bytes(16));
    $_SESSION['import_session'] = $sessionKey;

    $handle   = fopen($file['tmp_name'], 'r');
    $headers  = array_map('trim', fgetcsv($handle));
    $rowNum   = 1;
    $colKeys  = array_column($allCols, 'col_key');
    $months   = getMonthList();
    $fyList   = getFYList();

    // Required header columns
    $requiredHeaders = ['bill_no','bill_date','tv_no','tv_date','fy','month_no'];

    $stmt = $conn->prepare("INSERT INTO temp_import (session_key,user_id,row_number,raw_data,bill_no,bill_date,tv_no,tv_date,fy,month_no,remark,has_error,error_details) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmtV= $conn->prepare("INSERT INTO temp_import_values (temp_id,col_key,raw_value,clean_value,has_error,error_msg) VALUES (?,?,?,?,?,?)");

    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;
        if (count(array_filter($row)) === 0) continue; // Skip empty rows

        $data     = array_combine($headers, array_pad($row, count($headers), ''));
        $raw      = json_encode($data);
        $rowErrors= [];

        // Field validation
        $billNo   = trim($data['bill_no']   ?? '');
        $billDate = trim($data['bill_date'] ?? '');
        $tvNo     = trim($data['tv_no']     ?? '');
        $tvDate   = trim($data['tv_date']   ?? '');
        $fy       = trim($data['fy']        ?? '');
        $monthNo  = trim($data['month_no']  ?? '');
        $remark   = trim($data['remark']    ?? '');

        if (!$billNo)  $rowErrors['bill_no']   = 'Bill No zaroori hai';
        if (!$billDate) $rowErrors['bill_date'] = 'Bill Date zaroori hai';
        elseif (!DateTime::createFromFormat('d-m-Y', $billDate) && !DateTime::createFromFormat('Y-m-d', $billDate))
            $rowErrors['bill_date'] = 'Date format: DD-MM-YYYY';
        if (!$tvNo)    $rowErrors['tv_no']     = 'TV No zaroori hai';
        if (!$tvDate)  $rowErrors['tv_date']   = 'TV Date zaroori hai';
        if (!$fy)      $rowErrors['fy']        = 'FY zaroori hai';
        if (!$monthNo) $rowErrors['month_no']  = 'Month zaroori hai';
        elseif (!isset($months[str_pad($monthNo,2,'0',STR_PAD_LEFT)]))
            $rowErrors['month_no'] = 'Month galat hai (01-12)';

        // Uniqueness check
        if ($billNo && $tvNo) {
            $chk = $conn->prepare("SELECT id FROM salary_bills WHERE user_id=? AND bill_no=? AND tv_no=? LIMIT 1");
            $chk->bind_param('iss', $uid, $billNo, $tvNo);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0)
                $rowErrors['bill_no'] = "Bill No '$billNo' + TV No '$tvNo' pehle se exist karta hai";
            $chk->close();
        }

        $hasError = !empty($rowErrors) ? 1 : 0;
        $errJson  = $rowErrors ? json_encode($rowErrors) : null;

        // Parse date
        $parsedBillDate = $billDate;
        $parsedTvDate   = $tvDate;
        foreach (['parsedBillDate'=>$billDate,'parsedTvDate'=>$tvDate] as $var=>&$src) {
            $dt = DateTime::createFromFormat('d-m-Y',$src) ?: DateTime::createFromFormat('Y-m-d',$src);
            if ($dt) $src = $dt->format('Y-m-d');
        }

        $moPad = str_pad($monthNo, 2, '0', STR_PAD_LEFT);
        $stmt->bind_param('siisssssssssi',
            $sessionKey,$uid,$rowNum,$raw,
            $billNo,$parsedBillDate,$tvNo,$parsedTvDate,$fy,$moPad,$remark,$hasError,$errJson);
        $stmt->execute();
        $tempId = $conn->insert_id;

        // Amount columns
        foreach ($allCols as $col) {
            $key = $col['col_key'];
            $raw_val = trim($data[$key] ?? '');
            $colErr  = 0; $colMsg = null; $cleanVal = null;
            if ($raw_val !== '') {
                $num = (float)str_replace(',','',$raw_val);
                if (!is_numeric(str_replace(',','',$raw_val)) || $num < 0) {
                    $colErr = 1; $colMsg = 'Galat amount'; $hasError = 1;
                } else {
                    $cleanVal = $num;
                }
            }
            $stmtV->bind_param('issdis', $tempId,$key,$raw_val,$cleanVal,$colErr,$colMsg);
            $stmtV->execute();
        }
    }
    fclose($handle);
    $stmt->close();
    $stmtV->close();

    setFlash('success', 'File parse ho gayi. Errors check karein aur save karein.');
    header('Location: 08_csv_import.php'); exit;
}

// ── STEP 2: Save temp rows (after user review) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_valid') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error','Invalid request.'); header('Location:08_csv_import.php'); exit;
    }
    // Only save rows with no errors
    $rows = $conn->query("SELECT * FROM temp_import WHERE session_key='$sessionKey' AND user_id=$uid AND has_error=0")->fetch_all(MYSQLI_ASSOC);
    $saved = 0; $skipped = 0;
    $conn->begin_transaction();
    try {
        $stmtB = $conn->prepare("INSERT INTO salary_bills (user_id,bill_no,bill_date,tv_no,tv_date,fy,month_no,gross_amount,total_deduction,net_payable,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmtV = $conn->prepare("SELECT tiv.col_key, sc.id as col_id, tiv.clean_value FROM temp_import_values tiv JOIN salary_columns sc ON sc.col_key=tiv.col_key WHERE tiv.temp_id=? AND tiv.clean_value IS NOT NULL AND tiv.has_error=0");
        $stmtBV= $conn->prepare("INSERT INTO bill_values (bill_id,col_id,amount) VALUES (?,?,?)");
        $stmtColType = $conn->prepare("SELECT col_type FROM salary_columns WHERE col_key=?");

        foreach ($rows as $r) {
            // Calc totals
            $stmtV->bind_param('i', $r['id']);
            $stmtV->execute();
            $colRows  = $stmtV->get_result()->fetch_all(MYSQLI_ASSOC);
            $gross=0; $ded=0;
            foreach ($colRows as $cr) {
                $stmtColType->bind_param('s',$cr['col_key']);
                $stmtColType->execute();
                $t = $stmtColType->get_result()->fetch_assoc()['col_type'] ?? '';
                if ($t==='earning') $gross += $cr['clean_value'];
                else                $ded   += $cr['clean_value'];
            }
            $net = $gross - $ded;
            $stmtB->bind_param('issssssddds',$uid,$r['bill_no'],$r['bill_date'],$r['tv_no'],$r['tv_date'],$r['fy'],$r['month_no'],$gross,$ded,$net,$r['remark']);
            $stmtB->execute();
            $billId = $conn->insert_id;
            foreach ($colRows as $cr) {
                $stmtBV->bind_param('iid',$billId,$cr['col_id'],$cr['clean_value']);
                $stmtBV->execute();
            }
            $saved++;
        }
        $conn->commit();
        // Remove saved rows from temp
        $conn->query("DELETE FROM temp_import WHERE session_key='$sessionKey' AND user_id=$uid AND has_error=0");
        unset($_SESSION['import_session']);
        setFlash('success', "$saved bills successfully import ho gaye!");
        header('Location: 07_bill_list.php'); exit;
    } catch (Exception $ex) {
        $conn->rollback();
        setFlash('error','Save error: '.$ex->getMessage());
        header('Location: 08_csv_import.php'); exit;
    }
}

// ── Inline edit save ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fix_row') {
    header('Content-Type: application/json');
    $tempId = (int)($_POST['temp_id'] ?? 0);
    // Verify ownership
    $own = $conn->query("SELECT id FROM temp_import WHERE id=$tempId AND user_id=$uid LIMIT 1")->num_rows;
    if (!$own) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

    $fields = ['bill_no','bill_date','tv_no','tv_date','fy','month_no','remark'];
    $errs   = [];
    $d      = [];
    foreach ($fields as $f) $d[$f] = clean($_POST[$f] ?? '');

    if (!$d['bill_no'])  $errs['bill_no']   = 'Zaroori hai';
    if (!$d['bill_date']) $errs['bill_date'] = 'Zaroori hai';
    if (!$d['tv_no'])    $errs['tv_no']     = 'Zaroori hai';
    if (!$d['fy'])       $errs['fy']        = 'Zaroori hai';
    if (!$d['month_no']) $errs['month_no']  = 'Zaroori hai';

    // Uniqueness
    if (!$errs) {
        $chk = $conn->prepare("SELECT id FROM salary_bills WHERE user_id=? AND bill_no=? AND tv_no=? LIMIT 1");
        $chk->bind_param('iss',$uid,$d['bill_no'],$d['tv_no']);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) $errs['bill_no'] = 'Duplicate combination';
        $chk->close();
    }

    $hasErr = !empty($errs) ? 1 : 0;
    $errJson= $errs ? json_encode($errs) : null;
    $stmt   = $conn->prepare("UPDATE temp_import SET bill_no=?,bill_date=?,tv_no=?,tv_date=?,fy=?,month_no=?,remark=?,has_error=?,error_details=? WHERE id=? AND user_id=?");
    $stmt->bind_param('sssssssisi',$d['bill_no'],$d['bill_date'],$d['tv_no'],$d['tv_date'],$d['fy'],$d['month_no'],$d['remark'],$hasErr,$errJson,$tempId,$uid);
    $stmt->execute(); $stmt->close();

    // Update col amounts
    foreach ($allCols as $col) {
        $k = $col['col_key'];
        $v = clean($_POST['amt_'.$k] ?? '');
        $cv= is_numeric(str_replace(',',$v)) && (float)str_replace(',',$v)>=0 ? (float)str_replace(',',$v) : null;
        $ce= ($v!=='' && $cv===null) ? 1 : 0;
        $cm= $ce ? 'Galat amount' : null;
        $upd=$conn->prepare("UPDATE temp_import_values SET raw_value=?,clean_value=?,has_error=?,error_msg=? WHERE temp_id=? AND col_key=?");
        $upd->bind_param('sdiisi',$v,$cv,$ce,$cm,$tempId,$k);
        $upd->execute(); $upd->close();
    }
    echo json_encode(['success'=>true,'message'=>'Row update ho gayi','has_error'=>$hasErr]);
    exit;
}

// ── Cancel import ──
if (isset($_GET['cancel'])) {
    if ($sessionKey) $conn->query("DELETE FROM temp_import WHERE session_key='$sessionKey' AND user_id=$uid");
    unset($_SESSION['import_session']);
    setFlash('success','Import cancel ho gaya.');
    header('Location: 08_csv_import.php'); exit;
}

$errorCount = 0; $validCount = 0;
foreach ($tempRows as $r) { $r['has_error'] ? $errorCount++ : $validCount++; }

$extraJs = ['03_csv_import.js'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title">CSV Import</div>
            <div class="page-sub">Bulk bill upload via CSV file</div>
        </div>
        <div class="topbar-right">
            <a href="../api/api_04_csv_upload.php?action=template" class="btn btn-outline btn-sm">
                <i class="ti ti-download"></i> CSV Template Download
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

        <?php if (!$hasTemp): ?>
        <!-- Upload Form -->
        <div class="card" style="max-width:560px;margin:0 auto">
            <div class="card-header"><span class="card-title"><i class="ti ti-upload" style="vertical-align:-2px;margin-right:5px"></i>CSV File Upload</span></div>
            <div class="card-body">
                <div class="alert alert-info" style="margin-bottom:14px">
                    <i class="ti ti-info-circle"></i>
                    <div>
                        <strong>CSV format:</strong> Pehle template download karein. Required columns: bill_no, bill_date (DD-MM-YYYY), tv_no, tv_date, fy, month_no (01-12)
                    </div>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <div class="file-drop" id="fileDrop" onclick="document.getElementById('csvFile').click()">
                        <i class="ti ti-file-spreadsheet"></i>
                        <p>CSV file yahan drop karein ya click karein</p>
                        <span id="fileNameDisplay">Koi file select nahi</span>
                    </div>
                    <input type="file" id="csvFile" name="csv_file" accept=".csv" style="display:none" required>
                    <div class="form-actions" style="margin-top:14px">
                        <button type="submit" class="btn btn-primary" id="uploadBtn" disabled>
                            <i class="ti ti-upload"></i> Upload & Validate
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- Validation Results -->
        <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap">
            <div class="stat-card" style="flex:1;min-width:140px">
                <div class="stat-label">Total Rows</div>
                <div class="stat-value"><?= count($tempRows) ?></div>
            </div>
            <div class="stat-card" style="flex:1;min-width:140px;border-color:var(--clr-success-border)">
                <div class="stat-label">Valid Rows</div>
                <div class="stat-value" style="color:var(--clr-success)"><?= $validCount ?></div>
            </div>
            <div class="stat-card" style="flex:1;min-width:140px;border-color:var(--clr-error-border)">
                <div class="stat-label">Error Rows</div>
                <div class="stat-value" style="color:var(--clr-error)"><?= $errorCount ?></div>
            </div>
        </div>

        <?php if ($errorCount > 0): ?>
        <div class="alert alert-warning">
            <i class="ti ti-alert-triangle"></i>
            <div><strong><?= $errorCount ?> rows me error hai.</strong> Error wali rows orange me hain. Table me seedha fix karein → Save row → phir "Save Valid Rows" click karein.</div>
        </div>
        <?php endif; ?>

        <!-- Table with inline edit -->
        <div class="card mb-md">
            <div class="card-header">
                <span class="card-title">Preview & Fix Errors</span>
                <div style="display:flex;gap:8px">
                    <a href="08_csv_import.php?cancel=1" class="btn btn-light btn-sm"
                       data-confirm="Import cancel karna chahte hain? Sab data delete ho jaayega.">
                        <i class="ti ti-x"></i> Cancel
                    </a>
                    <?php if ($validCount > 0): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="save_valid">
                        <button type="submit" class="btn btn-primary btn-sm"
                            onclick="return confirm('<?= $validCount ?> valid rows save karna chahte hain?')">
                            <i class="ti ti-device-floppy"></i> Save <?= $validCount ?> Valid Rows
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-wrap">
                <table class="table" id="importTable">
                    <thead>
                        <tr>
                            <th>Row</th>
                            <th>Status</th>
                            <th>Bill No<span class="required">*</span></th>
                            <th>Bill Date<span class="required">*</span></th>
                            <th>TV No<span class="required">*</span></th>
                            <th>FY</th>
                            <th>Month</th>
                            <?php foreach ($allCols as $col): ?>
                            <th><?= clean($col['label']) ?></th>
                            <?php endforeach; ?>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tempRows as $r):
                        $errs = $r['error_details'] ? json_decode($r['error_details'], true) : [];
                        // Parse col data
                        $colVals = [];
                        if ($r['col_data']) {
                            foreach (explode(';;', $r['col_data']) as $cv) {
                                $parts = explode('|', $cv, 4);
                                if (count($parts) === 4) {
                                    $colVals[$parts[0]] = ['val'=>$parts[1],'err'=>$parts[2],'msg'=>$parts[3]];
                                }
                            }
                        }
                        $rowCls = $r['has_error'] ? 'row-error' : '';
                    ?>
                    <tr class="<?= $rowCls ?>" data-id="<?= $r['id'] ?>" id="row_<?= $r['id'] ?>">
                        <td><?= $r['row_number'] ?></td>
                        <td>
                            <?php if ($r['has_error']): ?>
                            <span class="badge badge-error"><i class="ti ti-alert-circle"></i> Error</span>
                            <?php else: ?>
                            <span class="badge badge-success"><i class="ti ti-check"></i> Valid</span>
                            <?php endif; ?>
                        </td>
                        <td class="<?= isset($errs['bill_no'])?'cell-error':'' ?>"
                            data-err="<?= clean($errs['bill_no'] ?? '') ?>"
                            data-field="bill_no" data-id="<?= $r['id'] ?>">
                            <?= clean($r['bill_no']) ?>
                        </td>
                        <td class="<?= isset($errs['bill_date'])?'cell-error':'' ?>"
                            data-err="<?= clean($errs['bill_date'] ?? '') ?>">
                            <?= clean($r['bill_date']) ?>
                        </td>
                        <td class="<?= isset($errs['tv_no'])?'cell-error':'' ?>"
                            data-err="<?= clean($errs['tv_no'] ?? '') ?>">
                            <?= clean($r['tv_no']) ?>
                        </td>
                        <td><?= clean($r['fy']) ?></td>
                        <td><?= clean($r['month_no']) ?></td>
                        <?php foreach ($allCols as $col):
                            $k   = $col['col_key'];
                            $cv  = $colVals[$k] ?? ['val'=>'','err'=>0,'msg'=>''];
                            $cls = $cv['err'] ? 'cell-error' : ($cv['val'] ? 'cell-ok' : '');
                        ?>
                        <td class="<?= $cls ?>" data-err="<?= clean($cv['msg']) ?>">
                            <?= clean($cv['val'] ?: '—') ?>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <?php if ($r['has_error']): ?>
                            <button class="btn btn-warning btn-sm" onclick="openFixModal(<?= $r['id'] ?>)">
                                <i class="ti ti-edit"></i> Fix
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<!-- Fix Modal -->
<div class="modal-overlay" id="fixModal">
    <div class="modal-box" style="max-width:700px">
        <div class="modal-header">
            <span class="modal-title"><i class="ti ti-edit" style="vertical-align:-2px;margin-right:5px"></i>Row Fix Karein</span>
            <button class="modal-close" onclick="closeFixModal()">×</button>
        </div>
        <div class="modal-body" id="fixModalBody">Loading...</div>
        <div class="modal-footer">
            <button class="btn btn-light" onclick="closeFixModal()">Cancel</button>
            <button class="btn btn-primary" id="fixSaveBtn"><i class="ti ti-device-floppy"></i> Save Row</button>
        </div>
    </div>
</div>

<script>
// Store columns for modal
const allCols = <?= json_encode($allCols) ?>;
const months  = <?= json_encode($months) ?>;
const fyList  = <?= json_encode($fyList) ?>;

function openFixModal(id) {
    const modal = document.getElementById('fixModal');
    modal.classList.add('open');
    modal.dataset.tempId = id;

    // Get row data from table
    const row = document.getElementById('row_' + id);
    const cells = row.querySelectorAll('td');

    let html = `<input type="hidden" id="fix_temp_id" value="${id}">
    <div class="form-grid-3">
        <div class="form-group">
            <label class="form-label">Bill No <span class="required">*</span></label>
            <input type="text" id="fix_bill_no" class="form-control" value="${cells[2].textContent.trim()}">
        </div>
        <div class="form-group">
            <label class="form-label">Bill Date <span class="required">*</span></label>
            <input type="date" id="fix_bill_date" class="form-control" value="${cells[3].textContent.trim()}">
        </div>
        <div class="form-group">
            <label class="form-label">TV No <span class="required">*</span></label>
            <input type="text" id="fix_tv_no" class="form-control" value="${cells[4].textContent.trim()}">
        </div>
        <div class="form-group">
            <label class="form-label">TV Date</label>
            <input type="date" id="fix_tv_date" class="form-control">
        </div>
        <div class="form-group">
            <label class="form-label">FY</label>
            <select id="fix_fy" class="form-control">
                <option value="">— Select —</option>
                ${fyList.map(f=>`<option value="${f}">${f}</option>`).join('')}
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Month</label>
            <select id="fix_month_no" class="form-control">
                <option value="">— Select —</option>
                ${Object.entries(months).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}
            </select>
        </div>
    </div>
    <div class="divider"></div>
    <div class="form-section"><i class="ti ti-trending-up"></i> Earnings</div>
    <div class="form-grid-3">`;

    allCols.filter(c=>c.col_type==='earning').forEach(col=>{
        const cellIdx = 7 + allCols.findIndex(c=>c.col_key===col.col_key);
        const rawVal  = cells[cellIdx]?.textContent.trim().replace('—','') || '';
        html += `<div class="form-group">
            <label class="form-label">${col.label}</label>
            <input type="text" id="fix_amt_${col.col_key}" class="form-control num" value="${rawVal}" placeholder="0.00">
        </div>`;
    });
    html += `</div><div class="form-section" style="margin-top:10px"><i class="ti ti-trending-down"></i> Deductions</div><div class="form-grid-3">`;
    allCols.filter(c=>c.col_type==='deduction').forEach(col=>{
        const cellIdx = 7 + allCols.findIndex(c=>c.col_key===col.col_key);
        const rawVal  = cells[cellIdx]?.textContent.trim().replace('—','') || '';
        html += `<div class="form-group">
            <label class="form-label">${col.label}</label>
            <input type="text" id="fix_amt_${col.col_key}" class="form-control num" value="${rawVal}" placeholder="0.00">
        </div>`;
    });
    html += '</div>';
    document.getElementById('fixModalBody').innerHTML = html;

    // Set FY and month selects
    document.getElementById('fix_fy').value       = cells[5].textContent.trim();
    document.getElementById('fix_month_no').value = cells[6].textContent.trim();
}

function closeFixModal() {
    document.getElementById('fixModal').classList.remove('open');
}

document.getElementById('fixSaveBtn').addEventListener('click', function() {
    const id  = document.getElementById('fix_temp_id').value;
    const body= new FormData();
    body.append('action',   'fix_row');
    body.append('csrf_token', '<?= csrfToken() ?>');
    body.append('temp_id',  id);
    ['bill_no','bill_date','tv_no','tv_date','fy','month_no','remark'].forEach(f=>{
        const el = document.getElementById('fix_'+f);
        if (el) body.append(f, el.value);
    });
    allCols.forEach(col=>{
        const el = document.getElementById('fix_amt_'+col.col_key);
        if (el) body.append('amt_'+col.col_key, el.value);
    });

    fetch('08_csv_import.php', { method:'POST', body })
        .then(r=>r.json())
        .then(function(res) {
            if (res.success) {
                closeFixModal();
                location.reload();
            } else {
                alert('Error: ' + res.message);
            }
        });
});

// File drop zone
const dropZone = document.getElementById('fileDrop');
const fileInput = document.getElementById('csvFile');
if (dropZone && fileInput) {
    fileInput.addEventListener('change', function() {
        const name = this.files[0]?.name || 'Koi file select nahi';
        document.getElementById('fileNameDisplay').textContent = name;
        document.getElementById('uploadBtn').disabled = !this.files[0];
    });
    dropZone.addEventListener('dragover', e=>{e.preventDefault();dropZone.classList.add('dragover');});
    dropZone.addEventListener('dragleave', ()=>dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault(); dropZone.classList.remove('dragover');
        if (e.dataTransfer.files[0]) {
            fileInput.files = e.dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>
