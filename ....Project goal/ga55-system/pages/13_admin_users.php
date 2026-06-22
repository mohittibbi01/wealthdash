<?php
// ============================================================
// GA-55A SYSTEM — pages/13_admin_users.php
// Admin only: Add / Edit / Toggle users
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireAdmin();

$pageTitle  = 'Manage Users';
$activePage = 'admin_users';
$flash      = getFlash();
$errors     = [];
$modalOpen  = false;
$editUser   = null;

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error','Invalid request.'); header('Location:13_admin_users.php'); exit;
    }
    $action   = $_POST['action'] ?? '';
    $userId   = (int)($_POST['user_id'] ?? 0);
    $name     = clean($_POST['name']     ?? '');
    $username = clean($_POST['username'] ?? '');
    $role     = $_POST['role'] === 'admin' ? 'admin' : 'user';
    $password = $_POST['password'] ?? '';
    $passConf = $_POST['password_confirm'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        if (!$name)     $errors['name']     = 'Naam zaroori hai';
        if (!$username) $errors['username'] = 'Username zaroori hai';
        elseif (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username))
            $errors['username'] = 'Username 3-50 chars, sirf letters/numbers/._- allowed';

        // Username unique check
        if (!$errors) {
            $excl = $userId ? "AND id != $userId" : '';
            $dup  = $conn->query("SELECT id FROM users WHERE username='".addslashes($username)."' $excl LIMIT 1");
            if ($dup->num_rows > 0) $errors['username'] = "Username '$username' pehle se exist karta hai";
        }

        if ($action === 'add') {
            if (!$password) $errors['password'] = 'Password zaroori hai (new user)';
            elseif (strlen($password) < 6) $errors['password'] = 'Password min 6 characters';
            elseif ($password !== $passConf) $errors['password_confirm'] = 'Passwords match nahi karte';
        } elseif ($password !== '') {
            if (strlen($password) < 6) $errors['password'] = 'Password min 6 characters';
            elseif ($password !== $passConf) $errors['password_confirm'] = 'Passwords match nahi karte';
        }

        if (empty($errors)) {
            if ($action === 'add') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (name,username,password_hash,role) VALUES (?,?,?,?)");
                $stmt->bind_param('ssss',$name,$username,$hash,$role);
                $stmt->execute(); $stmt->close();
                setFlash('success',"User '$name' add ho gaya! Login: $username");
            } else {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET name=?,username=?,password_hash=?,role=? WHERE id=?");
                    $stmt->bind_param('ssssi',$name,$username,$hash,$role,$userId);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET name=?,username=?,role=? WHERE id=?");
                    $stmt->bind_param('sssi',$name,$username,$role,$userId);
                }
                $stmt->execute(); $stmt->close();
                setFlash('success',"User '$name' update ho gaya!");
            }
            header('Location:13_admin_users.php'); exit;
        } else {
            $modalOpen = true;
            if ($userId) {
                $editUser = $conn->query("SELECT * FROM users WHERE id=$userId")->fetch_assoc();
            }
        }
    }

    if ($action === 'toggle') {
        // Cannot deactivate own account
        if ($userId === (int)currentUserId()) {
            setFlash('error','Aap apna account deactivate nahi kar sakte.');
        } else {
            $conn->query("UPDATE users SET is_active = NOT is_active WHERE id=$userId");
            setFlash('success','User status update ho gaya.');
        }
        header('Location:13_admin_users.php'); exit;
    }

    if ($action === 'reset_password') {
        $np = $_POST['new_password'] ?? '';
        $nc = $_POST['new_password_confirm'] ?? '';
        if (strlen($np) < 6) {
            setFlash('error','Password min 6 characters hona chahiye.');
        } elseif ($np !== $nc) {
            setFlash('error','Passwords match nahi karte.');
        } else {
            $hash = password_hash($np, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash=? WHERE id=?");
            $stmt->bind_param('si',$hash,$userId);
            $stmt->execute(); $stmt->close();
            setFlash('success','Password reset ho gaya.');
        }
        header('Location:13_admin_users.php'); exit;
    }
}

// Fetch all users with bill count
$users = $conn->query("SELECT u.*, COUNT(sb.id) as bill_count FROM users u
    LEFT JOIN salary_bills sb ON sb.user_id = u.id
    GROUP BY u.id ORDER BY u.role DESC, u.name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <div class="page-title">Manage Users</div>
            <div class="page-sub">Total users: <?= count($users) ?></div>
        </div>
        <div class="topbar-right">
            <button class="btn btn-primary btn-sm" onclick="openAddModal()">
                <i class="ti ti-user-plus"></i> New User
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

        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Bills</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $i => $u): ?>
                    <tr style="opacity:<?= $u['is_active']?'1':'0.6' ?>">
                        <td><?= $i+1 ?></td>
                        <td>
                            <div style="font-weight:500"><?= clean($u['name']) ?></div>
                            <?php if ($u['id'] === (int)currentUserId()): ?>
                            <span class="badge badge-info" style="font-size:9px">You</span>
                            <?php endif; ?>
                        </td>
                        <td><code style="font-size:11px"><?= clean($u['username']) ?></code></td>
                        <td>
                            <span class="badge <?= $u['role']==='admin'?'badge-warning':'badge-info' ?>">
                                <?= ucfirst($u['role']) ?>
                            </span>
                        </td>
                        <td><?= $u['bill_count'] ?></td>
                        <td>
                            <span class="badge <?= $u['is_active']?'badge-success':'badge-muted' ?>">
                                <?= $u['is_active']?'Active':'Inactive' ?>
                            </span>
                        </td>
                        <td style="font-size:11px;color:var(--clr-text-muted)">
                            <?= date('d M Y', strtotime($u['created_at'])) ?>
                        </td>
                        <td>
                            <button class="btn btn-outline btn-sm"
                                onclick="openEditModal(<?= $u['id'] ?>,'<?= addslashes($u['name']) ?>','<?= addslashes($u['username']) ?>','<?= $u['role'] ?>')"
                                title="Edit">
                                <i class="ti ti-edit"></i>
                            </button>
                            <button class="btn btn-light btn-sm"
                                onclick="openResetModal(<?= $u['id'] ?>,'<?= addslashes($u['name']) ?>')"
                                title="Reset Password">
                                <i class="ti ti-key"></i>
                            </button>
                            <?php if ($u['id'] !== (int)currentUserId()): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit"
                                    class="btn btn-sm <?= $u['is_active']?'btn-warning':'btn-light' ?>"
                                    data-confirm="<?= $u['is_active']?"'{$u['name']}' ko deactivate karna chahte hain?":"'{$u['name']}' ko activate karna chahte hain?" ?>">
                                    <i class="ti ti-<?= $u['is_active']?'user-off':'user-check' ?>"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal-overlay <?= $modalOpen?'open':'' ?>" id="userModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="userModalTitle">New User</span>
            <button class="modal-close" onclick="document.getElementById('userModal').classList.remove('open')">×</button>
        </div>
        <form method="POST" id="userForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action"  id="userAction"  value="add">
                <input type="hidden" name="user_id" id="userFormId"  value="0">

                <?php if (!empty($errors)): ?>
                <div class="alert alert-error" style="margin-bottom:12px">
                    <?php foreach ($errors as $e): ?><div style="font-size:11px"><?= $e ?></div><?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="form-group mb-md">
                    <label class="form-label">Full Name <span class="required">*</span></label>
                    <input type="text" name="name" id="uName" class="form-control <?= isset($errors['name'])?'is-invalid':'' ?>"
                        value="<?= clean($editUser['name'] ?? '') ?>" placeholder="e.g. Ramesh Kumar Sharma">
                    <?php if(isset($errors['name'])): ?><span class="field-error"><i class="ti ti-alert-circle"></i><?= $errors['name'] ?></span><?php endif; ?>
                </div>
                <div class="form-group mb-md">
                    <label class="form-label">Username <span class="required">*</span></label>
                    <input type="text" name="username" id="uUsername" class="form-control <?= isset($errors['username'])?'is-invalid':'' ?>"
                        value="<?= clean($editUser['username'] ?? '') ?>" placeholder="e.g. ramesh.sharma">
                    <?php if(isset($errors['username'])): ?><span class="field-error"><i class="ti ti-alert-circle"></i><?= $errors['username'] ?></span><?php endif; ?>
                </div>
                <div class="form-group mb-md">
                    <label class="form-label">Role</label>
                    <select name="role" id="uRole" class="form-control">
                        <option value="user">User (apna data khud bharega)</option>
                        <option value="admin">Admin (full access)</option>
                    </select>
                </div>
                <div class="divider"></div>
                <div class="form-group mb-md">
                    <label class="form-label">Password <span class="required" id="passRequired">*</span></label>
                    <input type="password" name="password" id="uPassword" class="form-control <?= isset($errors['password'])?'is-invalid':'' ?>"
                        placeholder="Min 6 characters">
                    <span class="field-hint" id="passHint">New user ke liye zaroori</span>
                    <?php if(isset($errors['password'])): ?><span class="field-error"><i class="ti ti-alert-circle"></i><?= $errors['password'] ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password <span class="required" id="passConfRequired">*</span></label>
                    <input type="password" name="password_confirm" id="uPassConf" class="form-control <?= isset($errors['password_confirm'])?'is-invalid':'' ?>"
                        placeholder="Dobara dalein">
                    <?php if(isset($errors['password_confirm'])): ?><span class="field-error"><i class="ti ti-alert-circle"></i><?= $errors['password_confirm'] ?></span><?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="document.getElementById('userModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-device-floppy"></i> Save User
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetModal">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-header">
            <span class="modal-title"><i class="ti ti-key" style="vertical-align:-2px;margin-right:5px"></i>Reset Password</span>
            <button class="modal-close" onclick="document.getElementById('resetModal').classList.remove('open')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId" value="">
                <p style="font-size:12px;color:var(--clr-text-muted);margin-bottom:14px" id="resetUserName"></p>
                <div class="form-group mb-md">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Min 6 characters">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="new_password_confirm" class="form-control" placeholder="Dobara dalein">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="document.getElementById('resetModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-key"></i> Reset</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('userModalTitle').textContent = 'New User';
    document.getElementById('userAction').value  = 'add';
    document.getElementById('userFormId').value  = '0';
    document.getElementById('uName').value       = '';
    document.getElementById('uUsername').value   = '';
    document.getElementById('uRole').value       = 'user';
    document.getElementById('uPassword').value   = '';
    document.getElementById('uPassConf').value   = '';
    document.getElementById('passRequired').style.display = 'inline';
    document.getElementById('passHint').textContent = 'New user ke liye zaroori';
    document.getElementById('userModal').classList.add('open');
}

function openEditModal(id, name, username, role) {
    document.getElementById('userModalTitle').textContent = 'Edit User';
    document.getElementById('userAction').value  = 'edit';
    document.getElementById('userFormId').value  = id;
    document.getElementById('uName').value       = name;
    document.getElementById('uUsername').value   = username;
    document.getElementById('uRole').value       = role;
    document.getElementById('uPassword').value   = '';
    document.getElementById('uPassConf').value   = '';
    document.getElementById('passRequired').style.display = 'none';
    document.getElementById('passHint').textContent = 'Khali chhodo agar password nahi badalna';
    document.getElementById('userModal').classList.add('open');
}

function openResetModal(id, name) {
    document.getElementById('resetUserId').value    = id;
    document.getElementById('resetUserName').textContent = 'User: ' + name;
    document.getElementById('resetModal').classList.add('open');
}

<?php if ($modalOpen): ?>
document.getElementById('userModal').classList.add('open');
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>
