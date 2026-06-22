<?php
// ============================================================
// GA-55A SYSTEM — pages/12_admin_columns.php
// Admin only: Add / Edit / Toggle / Reorder salary columns
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireAdmin();

$pageTitle  = 'Manage Columns';
$activePage = 'admin_cols';
$flash      = getFlash();
$errors     = [];

// ── Add / Edit column ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error','Invalid request.'); header('Location:12_admin_columns.php'); exit;
    }
    $action  = $_POST['action'];
    $colId   = (int)($_POST['col_id'] ?? 0);
    $label   = clean($_POST['label']    ?? '');
    $colKey  = strtolower(preg_replace('/[^a-z0-9]/','_', clean($_POST['col_key'] ?? '')));
    $colType = $_POST['col_type'] === 'earning' ? 'earning' : 'deduction';
    $sortOrd = (int)($_POST['sort_order'] ?? 0);

    if ($action === 'add' || $action === 'edit') {
        if (!$label)   $errors['label']   = 'Column label zaroori hai';
        if (!$colKey)  $errors['col_key'] = 'Column key zaroori hai (a-z, 0-9, _)';

        // Duplicate key check
        if (!$errors) {
            $excl = $colId ? "AND id != $colId" : '';
            $dup  = $conn->query("SELECT id FROM salary_columns WHERE col_key='$colKey' $excl LIMIT 1");
            if ($dup->num_rows > 0) $errors['col_key'] = "Key '$colKey' pehle se exist karta hai";
        }

        if (empty($errors)) {
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO salary_columns (col_key,label,col_type,sort_order) VALUES (?,?,?,?)");
                $stmt->bind_param('sssi',$colKey,$label,$colType,$sortOrd);
                $stmt->execute(); $stmt->close();
                setFlash('success',"Column '$label' add ho gaya!");
            } else {
                $stmt = $conn->prepare("UPDATE salary_columns SET label=?,col_type=?,sort_order=? WHERE id=?");
                $stmt->bind_param('ssii',$label,$colType,$sortOrd,$colId);
                $stmt->execute(); $stmt->close();
                setFlash('success',"Column update ho gaya!");
            }
            header('Location:12_admin_columns.php'); exit;
        }
    }

    if ($action === 'toggle') {
        $conn->query("UPDATE salary_columns SET is_active = NOT is_active WHERE id=$colId");
        setFlash('success','Column status update ho gaya.');
        header('Location:12_admin_columns.php'); exit;
    }

    if ($action === 'delete') {
        // Check if any bills use this column
        $used = $conn->query("SELECT COUNT(*) FROM bill_values bv JOIN salary_columns sc ON sc.id=bv.col_id WHERE sc.id=$colId")->fetch_row()[0];
        if ($used > 0) {
            setFlash('error',"Yeh column $used bills me use ho raha hai. Pehle disable karein, delete nahi kar sakte.");
        } else {
            $conn->query("DELETE FROM salary_columns WHERE id=$colId");
            setFlash('success','Column delete ho gaya.');
        }
        header('Location:12_admin_columns.php'); exit;
    }

    if ($action === 'reorder') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        foreach ($order as $i => $id) {
            $conn->query("UPDATE salary_columns SET sort_order=" . ($i+1) . " WHERE id=" . (int)$id);
        }
        header('Content-Type: application/json');
        echo json_encode(['success'=>true]); exit;
    }
}

// Fetch all columns
$earningCols   = $conn->query("SELECT * FROM salary_columns WHERE col_type='earning'   ORDER BY sort_order,id")->fetch_all(MYSQLI_ASSOC);
$deductionCols = $conn->query("SELECT * FROM salary_columns WHERE col_type='deduction' ORDER BY sort_order,id")->fetch_all(MYSQLI_ASSOC);

// Edit mode?
$editCol = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $editCol = $conn->query("SELECT * FROM salary_columns WHERE id=$eid")->fetch_assoc();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title">Manage Columns</div>
            <div class="page-sub">Earning aur Deduction columns add/edit/disable karein</div>
        </div>
        <div class="topbar-right">
            <button class="btn btn-primary btn-sm" onclick="openAddModal()">
                <i class="ti ti-plus"></i> New Column
            </button>
        </div>
    </div>

    <div class="page-content">

        <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>" data-autohide>
            <i class="ti ti-<?= $flash['type']==='success'?'check':'alert-circle' ?>"></i>
            <?= clean($flash['message']) ?>
        </div>
        <?php endif; ?>

        <div class="alert alert-info">
            <i class="ti ti-info-circle"></i>
            <div>
                <strong>Dynamic Columns:</strong> Naye columns yahan add karein — Bill Entry form aur CSV template me automatically aa jaayenge.
                Disable karne se column hide ho jaata hai lekin data delete nahi hota.
                <strong>Delete sirf tab hoga jab kisi bill me yeh column use na hua ho.</strong>
            </div>
        </div>

        <div class="grid-2">
            <!-- Earnings -->
            <div class="card">
                <div class="card-header" style="background:var(--clr-success-bg)">
                    <span class="card-title" style="color:var(--clr-success)">
                        <i class="ti ti-trending-up" style="vertical-align:-2px;margin-right:5px"></i>
                        Earning Columns (<?= count($earningCols) ?>)
                    </span>
                    <button class="btn btn-sm" style="background:var(--clr-success);color:#fff"
                        onclick="openAddModal('earning')">
                        <i class="ti ti-plus"></i> Add
                    </button>
                </div>
                <div class="table-wrap">
                    <table class="table" id="earningTable">
                        <thead>
                            <tr>
                                <th style="width:28px"></th>
                                <th>#</th>
                                <th>Label</th>
                                <th>Key</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="earningBody">
                        <?php foreach ($earningCols as $i => $col): ?>
                        <tr data-id="<?= $col['id'] ?>" style="opacity:<?= $col['is_active']?'1':'0.5' ?>">
                            <td style="cursor:grab;color:var(--clr-text-muted)"><i class="ti ti-grip-vertical"></i></td>
                            <td><?= $i+1 ?></td>
                            <td><strong><?= clean($col['label']) ?></strong></td>
                            <td><code style="font-size:10px;background:#f0f0ee;padding:2px 6px;border-radius:3px"><?= clean($col['col_key']) ?></code></td>
                            <td>
                                <span class="badge <?= $col['is_active']?'badge-success':'badge-muted' ?>">
                                    <?= $col['is_active']?'Active':'Disabled' ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-outline btn-sm" onclick="openEditModal(<?= $col['id'] ?>,'<?= addslashes($col['label']) ?>','<?= $col['col_key'] ?>','<?= $col['col_type'] ?>',<?= $col['sort_order'] ?>)">
                                    <i class="ti ti-edit"></i>
                                </button>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="col_id" value="<?= $col['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $col['is_active']?'btn-warning':'btn-light' ?>"
                                        title="<?= $col['is_active']?'Disable':'Enable' ?>">
                                        <i class="ti ti-<?= $col['is_active']?'eye-off':'eye' ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="col_id" value="<?= $col['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                        data-confirm="'<?= clean($col['label']) ?>' delete karna chahte hain?">
                                        <i class="ti ti-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Deductions -->
            <div class="card">
                <div class="card-header" style="background:var(--clr-error-bg)">
                    <span class="card-title" style="color:var(--clr-error)">
                        <i class="ti ti-trending-down" style="vertical-align:-2px;margin-right:5px"></i>
                        Deduction Columns (<?= count($deductionCols) ?>)
                    </span>
                    <button class="btn btn-sm" style="background:var(--clr-error);color:#fff"
                        onclick="openAddModal('deduction')">
                        <i class="ti ti-plus"></i> Add
                    </button>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:28px"></th>
                                <th>#</th>
                                <th>Label</th>
                                <th>Key</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="deductionBody">
                        <?php foreach ($deductionCols as $i => $col): ?>
                        <tr data-id="<?= $col['id'] ?>" style="opacity:<?= $col['is_active']?'1':'0.5' ?>">
                            <td style="cursor:grab;color:var(--clr-text-muted)"><i class="ti ti-grip-vertical"></i></td>
                            <td><?= $i+1 ?></td>
                            <td><strong><?= clean($col['label']) ?></strong></td>
                            <td><code style="font-size:10px;background:#f0f0ee;padding:2px 6px;border-radius:3px"><?= clean($col['col_key']) ?></code></td>
                            <td>
                                <span class="badge <?= $col['is_active']?'badge-success':'badge-muted' ?>">
                                    <?= $col['is_active']?'Active':'Disabled' ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-outline btn-sm" onclick="openEditModal(<?= $col['id'] ?>,'<?= addslashes($col['label']) ?>','<?= $col['col_key'] ?>','<?= $col['col_type'] ?>',<?= $col['sort_order'] ?>)">
                                    <i class="ti ti-edit"></i>
                                </button>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="col_id" value="<?= $col['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $col['is_active']?'btn-warning':'btn-light' ?>">
                                        <i class="ti ti-<?= $col['is_active']?'eye-off':'eye' ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="col_id" value="<?= $col['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                        data-confirm="'<?= clean($col['label']) ?>' delete karna chahte hain?">
                                        <i class="ti ti-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal-overlay" id="colModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="modalTitle">New Column</span>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <form method="POST" id="colForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action"   id="formAction" value="add">
                <input type="hidden" name="col_id"   id="formColId"  value="0">

                <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $e): ?><div><?= $e ?></div><?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="form-group mb-md">
                    <label class="form-label">Column Label <span class="required">*</span></label>
                    <input type="text" name="label" id="formLabel" class="form-control"
                        placeholder="e.g. House Rent Allowance">
                    <span class="field-hint">Jo naam Bill Entry form me dikhega</span>
                </div>
                <div class="form-group mb-md">
                    <label class="form-label">Column Key <span class="required">*</span></label>
                    <input type="text" name="col_key" id="formKey" class="form-control"
                        placeholder="e.g. hra" readonly>
                    <span class="field-hint">Auto-generated from label. CSV header me yahi name use karein.</span>
                </div>
                <div class="form-group mb-md">
                    <label class="form-label">Type <span class="required">*</span></label>
                    <select name="col_type" id="formType" class="form-control">
                        <option value="earning">Earning (Gross me add hoga)</option>
                        <option value="deduction">Deduction (Net se minus hoga)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" id="formSort" class="form-control" value="99" min="1">
                    <span class="field-hint">Chhota number = pehle dikhega</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn">
                    <i class="ti ti-device-floppy"></i> Save Column
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal(type) {
    document.getElementById('modalTitle').textContent = 'New Column';
    document.getElementById('formAction').value = 'add';
    document.getElementById('formColId').value  = '0';
    document.getElementById('formLabel').value  = '';
    document.getElementById('formKey').value    = '';
    document.getElementById('formSort').value   = '99';
    document.getElementById('formLabel').readOnly = false;
    document.getElementById('formKey').readOnly   = true;
    if (type) document.getElementById('formType').value = type;
    document.getElementById('colModal').classList.add('open');
    document.getElementById('formLabel').focus();
}

function openEditModal(id, label, key, type, sort) {
    document.getElementById('modalTitle').textContent = 'Edit Column';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formColId').value  = id;
    document.getElementById('formLabel').value  = label;
    document.getElementById('formKey').value    = key;
    document.getElementById('formType').value   = type;
    document.getElementById('formSort').value   = sort;
    document.getElementById('formKey').readOnly = true; // key cannot change after creation
    document.getElementById('colModal').classList.add('open');
}

function closeModal() {
    document.getElementById('colModal').classList.remove('open');
}

// Auto-generate key from label
document.getElementById('formLabel').addEventListener('input', function() {
    if (document.getElementById('formAction').value === 'add') {
        const key = this.value.toLowerCase()
            .replace(/[^a-z0-9\s]/g,'')
            .trim().replace(/\s+/g,'_');
        document.getElementById('formKey').value = key;
    }
});

// Close modal on overlay click
document.getElementById('colModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php include '../includes/footer.php'; ?>
