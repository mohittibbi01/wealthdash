<?php
require_once __DIR__ . '/auth.php';
require_login(); require_admin();
$db = get_db();

// ── Ensure ip_whitelist table exists ─────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS ip_whitelist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL UNIQUE,
    label TEXT,
    added_by INTEGER,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active INTEGER DEFAULT 1
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {

    // ── Restore soft-deleted project ─────────────────────────────────────────
    if ($action === 'restore_project') {
        $id = intval($_POST['id'] ?? 0);
        $db->prepare("UPDATE projects SET deleted_at=NULL, deleted_by=NULL WHERE id=?")->execute([$id]);
        $name = $db->query("SELECT project_name FROM projects WHERE id=$id")->fetchColumn();
        log_activity('restore_project', $id, $name ?: "ID:$id");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Project restored successfully."];
        header('Location: admin.php?tab=trash'); exit;
    }

    // ── Hard delete project (permanent) ───────────────────────────────────────
    if ($action === 'hard_delete_project') {
        $id = intval($_POST['id'] ?? 0);
        $row = $db->query("SELECT project_name FROM projects WHERE id=$id")->fetch();
        if ($row) {
            $db->prepare("DELETE FROM projects WHERE id=?")->execute([$id]);
            log_activity('hard_delete_project', $id, $row['project_name']);
            $_SESSION['flash'] = ['type'=>'success','msg'=>"🗑 Project permanently deleted."];
        }
        header('Location: admin.php?tab=trash'); exit;
    }

    // ── Unlock locked IP ──────────────────────────────────────────────────────
    if ($action === 'unlock_ip') {
        $ip = $_POST['ip'] ?? '';
        if ($ip) {
            $db->prepare("DELETE FROM login_attempts WHERE ip_address=?")->execute([$ip]);
            log_activity('admin_unlock_account', null, "Unlocked IP: " . $ip);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "✅ IP {$ip} unlocked successfully."];
        }
        header('Location: admin.php?tab=users'); exit;
    }

    $action = $_POST['action'] ?? '';

    // ── User actions ──────────────────────────────────────────────────────────
    if ($action === 'add_user') {
        $u    = trim($_POST['username'] ?? '');
        $pw   = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? '', ['admin', 'member', 'viewer']) ? $_POST['role'] : 'member';
        if ($u && strlen($pw) >= 8) try {
            $db->prepare("INSERT INTO users(username,password_hash,role,is_active,password_changed) VALUES(?,?,?,1,1)")
               ->execute([$u, password_hash($pw, PASSWORD_DEFAULT), $role]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "✅ User \"$u\" added."];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => '⚠ Username already exists.'];
        } elseif ($u && strlen($pw) < 8) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => '⚠ Password minimum 8 characters ka hona chahiye.'];
        }
    }

    if ($action === 'delete_user') {
        $uid = intval($_POST['uid'] ?? 0);
        if ($uid !== $_SESSION['user_id']) {
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '🗑 User deleted.'];
        }
    }

    if ($action === 'change_role') {
        $uid      = intval($_POST['uid'] ?? 0);
        $role     = in_array($_POST['role'] ?? '', ['admin', 'member', 'viewer']) ? $_POST['role'] : 'member';
        $admin_pw = $_POST['admin_pw'] ?? '';

        // Verify admin password before role change
        $admin_hash = $db->prepare("SELECT password FROM users WHERE id=?");
        $admin_hash->execute([$_SESSION['user_id']]);
        $hash = $admin_hash->fetchColumn();

        if (!password_verify($admin_pw, $hash)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => '⛔ Galat password — role change cancel.'];
        } elseif ($uid !== $_SESSION['user_id']) {
            $db->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role, $uid]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Role updated to ' . ucfirst($role) . '.'];
        }
    }

    if ($action === 'toggle_active') {
        $uid = intval($_POST['uid'] ?? 0);
        if ($uid !== $_SESSION['user_id']) {
            $st = $db->prepare("SELECT is_active FROM users WHERE id=?");
            $st->execute([$uid]);
            $cur = (int)($st->fetchColumn() ?? 1);
            $db->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$cur ? 0 : 1, $uid]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $cur ? '⛔ User deactivated.' : '✅ User activated.'];
        }
    }

    if ($action === 'reset_pw') {
        $uid = intval($_POST['uid'] ?? 0);
        $np  = $_POST['new_pw'] ?? '';
        if ($np && strlen($np) >= 8) {
            $db->prepare("UPDATE users SET password_hash=?, password_changed=1 WHERE id=?")
               ->execute([password_hash($np, PASSWORD_DEFAULT), $uid]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '🔑 Password reset.'];
        }
    }

    if ($action === 'delete_option') {
        $oid  = intval($_POST['oid'] ?? 0);
        $pw   = $_POST['confirm_pw'] ?? '';
        $typed = trim($_POST['confirm_name'] ?? '');

        // Verify admin password
        $row = $db->prepare("SELECT password FROM users WHERE id=?")->execute([$_SESSION['user_id']])->fetchColumn()
               ?? $db->prepare("SELECT password FROM users WHERE id=?")->execute([$_SESSION['user_id']]);
        $row = $db->prepare("SELECT password,option_value FROM users u, dynamic_options o WHERE u.id=? AND o.id=?");
        $row->execute([$_SESSION['user_id'], $oid]);
        $data = $row->fetch();
        $admin_pw_row = $db->prepare("SELECT password FROM users WHERE id=?");
        $admin_pw_row->execute([$_SESSION['user_id']]);
        $admin_hash = $admin_pw_row->fetchColumn();

        $opt_row = $db->prepare("SELECT option_value,option_group FROM dynamic_options WHERE id=?");
        $opt_row->execute([$oid]);
        $opt = $opt_row->fetch();

        $err_del = '';
        if (!$opt) { $err_del = 'Option not found.'; }
        elseif (!password_verify($pw, $admin_hash)) { $err_del = 'Galat password — delete cancel.'; }
        elseif (strtolower($typed) !== strtolower($opt['option_value'])) { $err_del = 'Value name match nahi kiya — delete cancel.'; }

        if ($err_del) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => '⛔ ' . $err_del];
        } else {
            $db->prepare("DELETE FROM dynamic_options WHERE id=?")->execute([$oid]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '🗑 Option "' . htmlspecialchars($opt['option_value']) . '" deleted.'];
        }
    }

    if ($action === 'add_checklist_item') {
        $name = trim($_POST['item_name'] ?? '');
        if ($name) {
            $max = $db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM checklist_items")->fetchColumn();
            try {
                $db->prepare("INSERT INTO checklist_items (item_name,sort_order) VALUES (?,?)")->execute([$name, $max]);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => "✅ Checklist item \"$name\" added."];
            } catch (Exception $e) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => '⚠ Item already exists.'];
            }
        }
    }

    if ($action === 'delete_checklist_item') {
        $iid = intval($_POST['iid'] ?? 0);
        $db->prepare("DELETE FROM checklist_responses WHERE item_id=?")->execute([$iid]);
        $db->prepare("DELETE FROM checklist_items WHERE id=?")->execute([$iid]);
    }

    // ── IP Whitelist actions ──────────────────────────────────────────────────
    if ($action === 'add_ip') {
        $ip    = trim($_POST['ip_address'] ?? '');
        $label = trim($_POST['ip_label'] ?? '');
        if ($ip) {
            // Validate IP format (IPv4 or IPv6)
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                try {
                    $db->prepare("INSERT INTO ip_whitelist (ip_address, label, added_by, is_active) VALUES (?,?,?,1)")
                       ->execute([$ip, $label ?: 'No label', $_SESSION['user_id']]);
                    log_activity('ip_whitelist_add', null, "Added IP: $ip ($label)");
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => "✅ IP $ip added to whitelist."];
                } catch (Exception $e) {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => '⚠ IP already exists in whitelist.'];
                }
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => "⚠ Invalid IP address format: $ip"];
            }
        }
    }

    if ($action === 'toggle_ip') {
        $iid = intval($_POST['iid'] ?? 0);
        $st  = $db->prepare("SELECT ip_address, is_active FROM ip_whitelist WHERE id=?");
        $st->execute([$iid]);
        $row = $st->fetch();
        if ($row) {
            $new = $row['is_active'] ? 0 : 1;
            $db->prepare("UPDATE ip_whitelist SET is_active=? WHERE id=?")->execute([$new, $iid]);
            log_activity('ip_whitelist_toggle', null, ($new ? 'Enabled' : 'Disabled') . " IP: " . $row['ip_address']);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => ($new ? '✅ IP enabled.' : '⛔ IP disabled.')];
        }
    }

    if ($action === 'delete_ip') {
        $iid = intval($_POST['iid'] ?? 0);
        $st  = $db->prepare("SELECT ip_address FROM ip_whitelist WHERE id=?");
        $st->execute([$iid]);
        $row = $st->fetch();
        if ($row) {
            $db->prepare("DELETE FROM ip_whitelist WHERE id=?")->execute([$iid]);
            log_activity('ip_whitelist_remove', null, "Removed IP: " . $row['ip_address']);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '🗑 IP removed from whitelist.'];
        }
    }

    if ($action === 'add_current_ip') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $label = trim($_POST['current_ip_label'] ?? 'My Current IP');
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            try {
                $db->prepare("INSERT INTO ip_whitelist (ip_address, label, added_by, is_active) VALUES (?,?,?,1)")
                   ->execute([$ip, $label, $_SESSION['user_id']]);
                log_activity('ip_whitelist_add', null, "Added current IP: $ip ($label)");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => "✅ Your current IP ($ip) added to whitelist."];
            } catch (Exception $e) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => "⚠ IP $ip already exists in whitelist."];
            }
        }
    }

    header('Location: admin.php' . (in_array($action, ['add_ip','toggle_ip','delete_ip','add_current_ip']) ? '?tab=ip' : '')); exit;
}

// ── Data fetch ────────────────────────────────────────────────────────────────
$users          = $db->query("SELECT *,(SELECT COUNT(*) FROM projects WHERE created_by=users.id) as pc FROM users ORDER BY role DESC,username")->fetchAll();

// ── Locked accounts ────────────────────────────────────────────────────────────
$locked_accounts = [];
try {
    $locked_accounts = $db->query(
        "SELECT ip_address, attempts, last_attempt_at FROM login_attempts
         WHERE attempts >= 5
         AND (strftime('%s','now') - strftime('%s', last_attempt_at)) < 900
         ORDER BY last_attempt_at DESC"
    )->fetchAll();
} catch (Exception $e) {}

// ── Soft-deleted projects (Trash) ─────────────────────────────────────────────
$deleted_projects = [];
try {
    $deleted_projects = $db->query(
        "SELECT p.*, u.username as deleted_by_name
         FROM projects p
         LEFT JOIN users u ON p.deleted_by = u.id
         WHERE p.deleted_at IS NOT NULL
         ORDER BY p.deleted_at DESC"
    )->fetchAll();
} catch (Exception $e) {}
// ── Activity log pagination ───────────────────────────────────────────────────
$log_per_page   = 50;
$log_page       = max(1, intval($_GET['log_page'] ?? 1));
$log_offset     = ($log_page - 1) * $log_per_page;
$log_total      = (int)$db->query("SELECT COUNT(*) FROM activity_log")->fetchColumn();
$log_pages      = max(1, (int)ceil($log_total / $log_per_page));
if ($log_page > $log_pages) $log_page = $log_pages;
$logs           = $db->query("SELECT l.*,u.username FROM activity_log l LEFT JOIN users u ON l.user_id=u.id ORDER BY l.created_at DESC LIMIT {$log_per_page} OFFSET {$log_offset}")->fetchAll();
$dynOpts        = $db->query("SELECT * FROM dynamic_options ORDER BY option_group,sort_order")->fetchAll();
$checklistItems = $db->query("SELECT * FROM checklist_items ORDER BY sort_order, id")->fetchAll();
$ipList         = $db->query("SELECT w.*, u.username as added_by_name FROM ip_whitelist w LEFT JOIN users u ON w.added_by=u.id ORDER BY w.added_at DESC")->fetchAll();
$flash          = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

$groupLabels = ['technology' => 'Technology', 'app_os' => 'App OS', 'db_technology' => 'DB Technology', 'db_version' => 'DB Version', 'db_os' => 'DB OS'];
$optsByGroup = [];
foreach ($dynOpts as $o) $optsByGroup[$o['option_group']][] = $o;

$accent  = user_pref('accent', '#00d4ff');
$bg      = user_pref('bg_color', '');
$theme   = user_pref('theme', 'dark');
$fsize   = user_pref('font_size', '14');
$ffamily = user_pref('font_family', 'Rajdhani');
// ── Sanitize user preferences at read time (CSS injection prevention) ─────────
$accent  = preg_replace('/[^#a-fA-F0-9]/', '', $accent);
if (empty($accent)) $accent = '#00d4ff';
if (!empty($bg)) {
    $bg = '#' . preg_replace('/[^a-fA-F0-9]/', '', ltrim($bg, '#'));
}
$theme   = in_array($theme, ['dark', 'light']) ? $theme : 'dark';
$fsize   = max(11, min(18, (int)$fsize));
$ffamily = in_array($ffamily, ['Rajdhani', 'Share Tech Mono', 'Orbitron']) ? $ffamily : 'Rajdhani';

$roleColors = ['admin' => '#ea80fc', 'member' => '#00d4ff', 'viewer' => '#ffd740'];
$roleLabel  = ['admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'];

$client_ip      = $_SERVER['REMOTE_ADDR'] ?? '';
$ip_total       = count($ipList);
$ip_active      = count(array_filter($ipList, fn($r) => $r['is_active']));
$whitelist_on   = $ip_total > 0; // whitelist is enforced when table is non-empty
$active_tab     = $_GET['tab'] ?? 'users';
if ($active_tab === 'add') $active_tab = 'users'; // merged into users tab
?>
<?php
$page_title = 'Admin';
$nav_active = 'admin';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style nonce="<?= csp_nonce() ?>">
/* Admin-specific styles */
.tab-pane{display:none}.tab-pane.active{display:block}

/* Users table */
.admin-table{width:100%;border-collapse:collapse;font-size:13px}
.admin-table th{font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1.1px;color:var(--tx2);padding:9px 14px;border-bottom:2px solid var(--bdr);background:var(--sur2);text-align:left;white-space:nowrap}
.admin-table td{padding:10px 14px;border-bottom:1px solid var(--bdr);vertical-align:middle;color:var(--tx)}
.admin-table tr:last-child td{border-bottom:none}
.admin-table tbody tr:hover{background:color-mix(in srgb,var(--acc) 4%,var(--sur))}

/* Opt pills */
.opt-pills{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}
.opt-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:var(--sur2);border:1px solid var(--bdr);border-radius:20px;font-size:12px;font-weight:500;color:var(--tx);transition:border-color .14s}
.opt-pill:hover{border-color:var(--acc)}
.rm{background:none;border:none;cursor:pointer;color:var(--tx3);font-size:13px;line-height:1;padding:0 2px;transition:color .14s;display:inline-flex;align-items:center}
.rm:hover{color:var(--err)}

/* Form sections */
.admin-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.field-group{display:flex;flex-direction:column;gap:4px}
.field-group label{font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1.1px;color:var(--tx2)}

/* IP list */
.ip-row{display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid var(--bdr)}
.ip-row:last-child{border-bottom:none}

/* Log entries */
.log-entry{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid color-mix(in srgb,var(--bdr) 60%,transparent);font-size:12.5px}
.log-entry:last-child{border-bottom:none}
.log-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}

/* Role permissions table */
.perm-table{width:100%;border-collapse:collapse;font-size:12px}
.perm-table th,.perm-table td{padding:7px 12px;border-bottom:1px solid var(--bdr);text-align:center}
.perm-table th:first-child,.perm-table td:first-child{text-align:left;font-family:'JetBrains Mono',monospace;font-size:11px}
.perm-table th{background:var(--sur2);font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--tx2)}
</style>
<div class="dv-content">

<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
<?php endif; ?>

<!-- TABS -->
<div class="dv-tabs">
  <button class="dv-tab <?= in_array($active_tab, ['users','add']) ? 'active' : '' ?>" data-tab="users">👥 Users (<?= count($users) ?>)</button>
  <button class="dv-tab <?= $active_tab === 'ip'        ? 'active' : '' ?>\" data-tab="ip">🛡 IP Whitelist <?= $ip_total > 0 ? "($ip_active/$ip_total)" : '' ?></button>
  <button class="dv-tab <?= $active_tab === 'options'   ? 'active' : '' ?>" data-tab="options">🔧 Dropdown Options</button>
  <button class="dv-tab <?= $active_tab === 'checklist' ? 'active' : '' ?>" data-tab="checklist">✅ Checklist Items</button>
  <button class="dv-tab <?= $active_tab === 'logs'      ? 'active' : '' ?>" data-tab="logs">📋 Activity Log</button>
  <button class="dv-tab <?= $active_tab === 'trash' ? 'active' : '' ?>" data-tab="trash">🗑 Trash <?= count($deleted_projects)>0?'('.count($deleted_projects).')':'' ?></button>
</div>

<!-- ═══ USERS TAB (merged with Add User) ═══ -->
<div class="tab-pane <?= in_array($active_tab, ['users','add']) ? 'active' : '' ?>" id="tab-users">

<?php
// Stats for summary
$cnt_active   = count(array_filter($users, fn($u) => (int)($u['is_active']??1) === 1));
$cnt_inactive = count($users) - $cnt_active;
$cnt_admin    = count(array_filter($users, fn($u) => $u['role']==='admin'));
$cnt_member   = count(array_filter($users, fn($u) => $u['role']==='member'));
$cnt_viewer   = count(array_filter($users, fn($u) => $u['role']==='viewer'));
$cnt_total    = count($users);
?>

  <?php if (!empty($locked_accounts)): ?>
  <div class="flash flash-error" style="margin-bottom:12px">
    <div>
      <strong>🔒 <?= count($locked_accounts) ?> IP(s) Currently Locked</strong>
      <?php foreach ($locked_accounts as $la): ?>
      <div style="display:flex;align-items:center;gap:10px;margin-top:6px;font-size:12px">
        <span style="font-family:'JetBrains Mono',monospace"><?= htmlspecialchars($la['ip_address']) ?></span>
        <span style="color:var(--err);font-weight:700"><?= intval($la['attempts']) ?> attempts</span>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="unlock_ip">
          <input type="hidden" name="ip" value="<?= htmlspecialchars($la['ip_address']) ?>">
          <button type="submit" class="btn btn-success btn-sm" data-confirm="IP <?= htmlspecialchars($la['ip_address']) ?> ko unlock karo?">🔓 Unlock</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── ROW 1: Stats + Transposed Permissions ──────────────────────── -->
  <div style="display:grid;grid-template-columns:auto 1fr;gap:12px;margin-bottom:12px;align-items:start">

    <!-- Stats card (same style as IP Whitelist) -->
    <div class="card" style="min-width:260px">
      <div style="display:flex;gap:0;border:1px solid var(--bdr);border-radius:10px;overflow:hidden;margin-bottom:12px">
        <div style="flex:1;text-align:center;padding:12px 8px;border-right:1px solid var(--bdr)">
          <div style="font-size:24px;font-weight:700;font-family:'JetBrains Mono',monospace;color:var(--acc)"><?= $cnt_total ?></div>
          <div style="font-size:10px;color:var(--tx2);text-transform:uppercase;letter-spacing:.8px;margin-top:2px">Total</div>
        </div>
        <div style="flex:1;text-align:center;padding:12px 8px;border-right:1px solid var(--bdr)">
          <div style="font-size:24px;font-weight:700;font-family:'JetBrains Mono',monospace;color:var(--ok)"><?= $cnt_active ?></div>
          <div style="font-size:10px;color:var(--tx2);text-transform:uppercase;letter-spacing:.8px;margin-top:2px">Active</div>
        </div>
        <div style="flex:1;text-align:center;padding:12px 8px">
          <div style="font-size:24px;font-weight:700;font-family:'JetBrains Mono',monospace;color:var(--err)"><?= $cnt_inactive ?></div>
          <div style="font-size:10px;color:var(--tx2);text-transform:uppercase;letter-spacing:.8px;margin-top:2px">Inactive</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
        <div style="text-align:center;background:var(--sur2);border-radius:8px;padding:8px 4px">
          <div style="font-size:20px;font-weight:700;font-family:'JetBrains Mono',monospace;color:<?= $roleColors['admin'] ?>"><?= $cnt_admin ?></div>
          <div style="font-size:10px;color:var(--tx2);text-transform:uppercase;letter-spacing:.6px">Admin</div>
        </div>
        <div style="text-align:center;background:var(--sur2);border-radius:8px;padding:8px 4px">
          <div style="font-size:20px;font-weight:700;font-family:'JetBrains Mono',monospace;color:<?= $roleColors['member'] ?>"><?= $cnt_member ?></div>
          <div style="font-size:10px;color:var(--tx2);text-transform:uppercase;letter-spacing:.6px">Member</div>
        </div>
        <div style="text-align:center;background:var(--sur2);border-radius:8px;padding:8px 4px">
          <div style="font-size:20px;font-weight:700;font-family:'JetBrains Mono',monospace;color:<?= $roleColors['viewer'] ?>"><?= $cnt_viewer ?></div>
          <div style="font-size:10px;color:var(--tx2);text-transform:uppercase;letter-spacing:.6px">Viewer</div>
        </div>
      </div>
    </div>

    <!-- Transposed permissions: Roles as rows, Permissions as columns -->
    <div class="card" style="padding:0;overflow:hidden">
      <?php
      $perms = ['View Projects','Search & Filter','View Passwords','Export Data','Add Project','Edit Project','Delete Project','Manage Users','IP Whitelist','Admin Panel'];
      $rolePerms = [
        'viewer' => [1,1,1,0,0,0,0,0,0,0],
        'member' => [1,1,1,1,1,1,0,0,0,0],
        'admin'  => [1,1,1,1,1,1,1,1,1,1],
      ];
      ?>
      <table style="width:100%;border-collapse:collapse;font-size:11px">
        <thead>
          <tr style="background:var(--sur2)">
            <th style="padding:8px 12px;text-align:left;font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2);border-bottom:2px solid var(--bdr);white-space:nowrap;min-width:80px">Role</th>
            <?php foreach ($perms as $p): ?>
            <th style="padding:8px 6px;text-align:center;font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:.8px;color:var(--tx2);border-bottom:2px solid var(--bdr);white-space:nowrap"><?= str_replace(' ','<br>',$p) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach (['viewer','member','admin'] as $role):
            $rclr = $roleColors[$role];
          ?>
          <tr style="border-bottom:1px solid var(--bdr)">
            <td style="padding:8px 12px">
              <span class="badge" style="color:<?= $rclr ?>;border-color:<?= $rclr ?>40;background:<?= $rclr ?>14;white-space:nowrap"><?= ucfirst($role) ?></span>
            </td>
            <?php foreach ($rolePerms[$role] as $has): ?>
            <td style="text-align:center;padding:8px 4px">
              <?= $has ? '<span style="color:var(--ok);font-weight:700;font-size:14px">✓</span>' : '<span style="color:var(--bdr);font-size:14px">–</span>' ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── ROW 2: Add New User (all in one row) ──────────────────────── -->
  <div class="card" style="margin-bottom:12px">
    <div class="card-title">➕ Add New User</div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add_user">
      <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:nowrap">
        <div style="flex:1.2;display:flex;flex-direction:column;gap:4px;min-width:0">
          <label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">Username</label>
          <input type="text" name="username" placeholder="e.g. john_dev" required style="height:36px">
        </div>
        <div style="flex:1.2;display:flex;flex-direction:column;gap:4px;min-width:0">
          <label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">Password</label>
          <input type="password" name="password" placeholder="Min 8 chars" required style="height:36px">
        </div>
        <div style="flex:1;display:flex;flex-direction:column;gap:4px;min-width:0">
          <label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">Role</label>
          <select name="role" style="height:36px">
            <option value="member">Member</option>
            <option value="viewer">Viewer</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" style="height:36px;white-space:nowrap;flex-shrink:0">➕ Add User</button>
      </div>
    </form>
  </div>

  <!-- ── ROW 3: Users Table ─────────────────────────────────────────── -->
  <div class="card" style="padding:0;overflow:hidden">
    <table class="admin-table">
      <thead><tr>
        <th>#</th><th>●</th><th>Username</th><th>Role</th>
        <th style="text-align:center">Proj.</th><th>Joined</th><th>PW Age</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u):
        $isActive = (int)($u['is_active'] ?? 1);
        $rclr = $roleColors[$u['role']] ?? '#8b949e';
      ?>
      <tr style="<?= !$isActive ? 'opacity:.5' : '' ?>">
        <td class="td-mono" style="width:40px"><?= $u['id'] ?></td>
        <td style="width:24px">
          <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= $isActive ? 'var(--ok)' : 'var(--err)' ?>" title="<?= $isActive ? 'Active' : 'Inactive' ?>"></span>
        </td>
        <td style="font-weight:700;font-size:13px"><?= htmlspecialchars($u['username']) ?></td>
        <td>
          <span class="badge" style="color:<?= $rclr ?>;border-color:<?= $rclr ?>40;background:<?= $rclr ?>14">
            <?= $roleLabel[$u['role']] ?? $u['role'] ?>
          </span>
        </td>
        <td style="text-align:center;font-family:'JetBrains Mono',monospace"><?= $u['pc'] ?></td>
        <td class="td-mono"><?= date('d M y', strtotime($u['created_at'])) ?></td>
        <td class="td-mono">
          <?php
            $pw_date = $u['password_changed_at'] ?? null;
            if ($pw_date) {
              $age_days = (int)round((time() - strtotime($pw_date)) / 86400);
              $age_col  = $age_days >= PASSWORD_EXPIRY_DAYS ? 'var(--err)' : ($age_days >= 80 ? 'var(--warn)' : 'var(--ok)');
              echo "<span style='color:{$age_col};font-weight:700'>{$age_days}d</span>";
            } else { echo '<span style="color:var(--tx3)">N/A</span>'; }
          ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($u['id'] !== $_SESSION['user_id']): ?>
          <div style="display:flex;gap:5px;align-items:center">
            <!-- Role select — compact width -->
            <select data-uid="<?= $u['id'] ?>" data-action="change-role"
              style="background:var(--sur2);border:1px solid var(--bdr);border-radius:6px;padding:3px 6px;color:var(--tx);font-size:11px;font-family:'JetBrains Mono',monospace;outline:none;cursor:pointer;height:28px;width:76px">
              <option value="admin"  <?= $u['role']==='admin'  ? 'selected':'' ?>>Admin</option>
              <option value="member" <?= $u['role']==='member' ? 'selected':'' ?>>Member</option>
              <option value="viewer" <?= $u['role']==='viewer' ? 'selected':'' ?>>Viewer</option>
            </select>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <button type="submit" class="btn <?= $isActive ? 'btn-danger' : 'btn-success' ?> btn-sm">
                <?= $isActive ? '⛔' : '✅' ?>
              </button>
            </form>
            <button class="btn btn-ghost btn-sm" data-action="reset-pw" data-uid="<?= $u['id'] ?>" data-uname="<?= htmlspecialchars($u['username']) ?>">🔑</button>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete user <?= htmlspecialchars($u['username']) ?>?">🗑</button>
            </form>
          </div>
          <?php else: ?>
          <span style="font-size:11px;color:var(--tx3);font-family:'JetBrains Mono',monospace">(you)</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ (empty hidden tab-add for backward compat with URL ?tab=add) ═══ -->
<div class="tab-pane" id="tab-add" style="display:none"></div>

<!-- ═══ IP WHITELIST TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'ip' ? 'active' : '' ?>" id="tab-ip">

  <!-- Row 1: Status + Stats in one card -->
  <div class="card" style="margin-bottom:12px">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="flex:2;min-width:220px">
        <?php if ($ip_total === 0): ?>
        <div style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--ok)">
          🟢 <span>Whitelist Inactive — All IPs allowed (safe default for fresh setup)</span>
        </div>
        <?php else: ?>
        <div style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--err)">
          🔴 <span>Whitelist Active — Only <?= $ip_active ?> enabled IP(s) can access. Others see blocked page.</span>
        </div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:20px;flex-shrink:0">
        <div style="text-align:center"><div style="font-size:22px;font-weight:700;font-family:'JetBrains Mono',monospace;color:var(--acc)"><?= $ip_total ?></div><div style="font-size:10px;color:var(--tx2);text-transform:uppercase;letter-spacing:.8px">Total</div></div>
        <div style="text-align:center"><div style="font-size:22px;font-weight:700;font-family:'JetBrains Mono',monospace;color:var(--ok)"><?= $ip_active ?></div><div style="font-size:10px;color:var(--tx2);text-transform:uppercase;letter-spacing:.8px">Enabled</div></div>
        <div style="text-align:center"><div style="font-size:22px;font-weight:700;font-family:'JetBrains Mono',monospace;color:var(--err)"><?= $ip_total - $ip_active ?></div><div style="font-size:10px;color:var(--tx2);text-transform:uppercase;letter-spacing:.8px">Disabled</div></div>
      </div>
    </div>
  </div>

  <!-- Row 2: Your current IP + quick add -->
  <div class="card" style="margin-bottom:12px">
    <?php
      $already = false;
      foreach ($ipList as $ipr) { if ($ipr['ip_address'] === $client_ip) { $already = true; break; } }
    ?>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2);white-space:nowrap">Your Current IP</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:15px;font-weight:700;color:var(--acc)"><?= htmlspecialchars($client_ip) ?></div>
      <?php if ($already): ?>
        <span class="badge badge-member">✓ Already in whitelist</span>
      <?php else: ?>
        <form method="POST" style="display:flex;gap:8px;align-items:center;flex:1;min-width:200px">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="add_current_ip">
          <input type="text" name="current_ip_label" placeholder="Label (e.g. My PC)" style="max-width:180px">
          <button type="submit" class="btn btn-success btn-sm" style="white-space:nowrap">+ Add My IP</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Row 3: Add IP Manually (inline) -->
  <div class="card" style="margin-bottom:12px">
    <div class="card-title">🛡 Add IP Manually</div>
    <form method="POST" action="admin.php">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add_ip">
      <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <div style="flex:1;min-width:160px;display:flex;flex-direction:column;gap:4px">
          <label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">IP Address (IPv4 or IPv6)</label>
          <input type="text" name="ip_address" placeholder="e.g. 192.168.1.45" required>
        </div>
        <div style="flex:1.5;min-width:180px;display:flex;flex-direction:column;gap:4px">
          <label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">Label / Description</label>
          <input type="text" name="ip_label" placeholder="e.g. Cabin 3 PC, Server Room">
        </div>
        <button type="submit" class="btn btn-primary" style="white-space:nowrap">🛡 Add to Whitelist</button>
      </div>
    </form>
  </div>

  <!-- IP List table -->
  <div class="card" style="padding:0;overflow:hidden;margin-bottom:12px">
    <div style="padding:12px 16px;border-bottom:1px solid var(--bdr);font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.2px;color:var(--tx2)">Whitelisted IPs (<?= $ip_total ?>)</div>
    <?php if ($ipList): ?>
    <table class="admin-table">
      <thead><tr>
        <th>#</th><th>IP Address</th><th>Label</th><th>Added By</th><th>Added On</th><th>Status</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ipList as $ipr): ?>
      <tr style="<?= !$ipr['is_active'] ? 'opacity:.5' : '' ?>">
        <td class="td-mono"><?= $ipr['id'] ?></td>
        <td style="font-weight:700;color:<?= $ipr['ip_address'] === $client_ip ? 'var(--acc)' : 'var(--tx)' ?>;font-family:'JetBrains Mono',monospace;font-size:12px">
          <?= htmlspecialchars($ipr['ip_address']) ?>
          <?php if ($ipr['ip_address'] === $client_ip): ?>
          <span style="font-size:9px;color:var(--acc);margin-left:4px">(you)</span>
          <?php endif; ?>
        </td>
        <td style="color:var(--tx2)"><?= htmlspecialchars($ipr['label'] ?? '') ?></td>
        <td style="color:var(--tx2)"><?= htmlspecialchars($ipr['added_by_name'] ?? 'system') ?></td>
        <td class="td-mono"><?= date('d M y H:i', strtotime($ipr['added_at'])) ?></td>
        <td><span class="badge" style="color:<?= $ipr['is_active'] ? 'var(--ok)' : 'var(--err)' ?>;border-color:<?= $ipr['is_active'] ? 'var(--ok)' : 'var(--err)' ?>40;background:<?= $ipr['is_active'] ? 'var(--ok-bg)' : 'var(--err-bg)' ?>"><?= $ipr['is_active'] ? 'Active' : 'Disabled' ?></span></td>
        <td>
          <div style="display:flex;gap:6px">
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="toggle_ip">
              <input type="hidden" name="iid" value="<?= $ipr['id'] ?>">
              <button type="submit" class="btn <?= $ipr['is_active'] ? 'btn-danger' : 'btn-success' ?> btn-sm"><?= $ipr['is_active'] ? '⛔ Disable' : '✅ Enable' ?></button>
            </form>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete_ip">
              <input type="hidden" name="iid" value="<?= $ipr['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" data-confirm-ip="<?= htmlspecialchars($ipr['ip_address']) ?>" data-is-own="<?= $ipr['ip_address'] === $client_ip ? 'yes' : 'no' ?>">🗑</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="no-data">No IPs added yet. Use the form above to add your IP first.</div>
    <?php endif; ?>
  </div>

  <!-- Info card -->
  <div class="card">
    <div class="card-title">ℹ How IP Whitelist Works</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;color:var(--tx2);line-height:1.7">
      <div>📌 <strong style="color:var(--tx)">Empty list</strong> — All IPs allowed (safe default)</div>
      <div>🔴 <strong style="color:var(--tx)">With entries</strong> — ONLY listed active IPs can access</div>
      <div>🔄 <strong style="color:var(--tx)">Disable vs Delete</strong> — Disable temporarily, Delete permanently</div>
      <div>🏠 <strong style="color:var(--tx)">Localhost</strong> — 127.0.0.1 and ::1 always allowed</div>
      <div style="grid-column:1/-1">⚠️ <strong style="color:var(--warn)">Always add your own IP first</strong> before adding others. Locked out? Delete ip_whitelist table in vault.db directly.</div>
    </div>
  </div>
</div>

<!-- ═══ OPTIONS TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'options' ? 'active' : '' ?>" id="tab-options">
  <div class="two-col">
    <?php foreach ($groupLabels as $grp => $lbl): ?>
    <div class="card" style="margin-bottom:14px">
      <h2><?= $lbl ?> Options</h2>
      <div class="opt-pills">
        <?php foreach ($optsByGroup[$grp] ?? [] as $o):
          // T-3: Count usage in projects table
          $usageCount = 0;
          $usageCol = ['technology'=>'technology','app_os'=>'app_os','db_technology'=>'db_technology','db_version'=>'db_version','db_os'=>'db_os'];
          if(isset($usageCol[$grp])){
            $uc=$db->prepare("SELECT COUNT(*) FROM projects WHERE ".$usageCol[$grp]."=?");
            $uc->execute([$o['option_value']]);
            $usageCount=(int)$uc->fetchColumn();
          }
        ?>
        <span class="opt-pill">
          <?= htmlspecialchars($o['option_value']) ?>
          <?php if($usageCount>0):?>
          <span style="font-size:9px;background:var(--warn-bg);color:var(--warn);padding:0 4px;border-radius:4px;margin-left:2px"><?=$usageCount?>✦</span>
          <?php endif;?>
          <button type="button" class="rm"
            data-action="del-opt-modal"
            data-oid="<?=$o['id']?>"
            data-val="<?=htmlspecialchars($o['option_value'])?>"
            data-usage="<?=$usageCount?>"
            title="Delete option">✕</button>
        </span>
        <?php endforeach; ?>
        <?php if (empty($optsByGroup[$grp])): ?>
        <span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--tx3)">No options yet</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="card" style="grid-column:1/-1;margin-top:8px">
      <p style="font-size:12px;color:var(--tx2);line-height:1.7">
        💡 <strong style="color:var(--tx)">How it works:</strong> Project form mein "Other" select karo aur value type karo — wo automatically yahan save ho jaegi.<br>
        Yahan se unwanted options remove kar sakte ho. <strong style="color:var(--warn)">⚠ Delete se pehle password confirm karni hogi.</strong><br>
        <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--err)">✦ = Projects using this value (deleting will not affect existing data)</span>
      </p>
    </div>
  </div>
</div>

<!-- ═══ CHECKLIST TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'checklist' ? 'active' : '' ?>" id="tab-checklist">

  <!-- Add item: textbox + button in one row -->
  <div class="card" style="margin-bottom:12px">
    <div class="card-title">✅ Add Checklist Item</div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add_checklist_item">
      <div style="display:flex;gap:10px;align-items:center">
        <input type="text" name="item_name" placeholder="e.g. Sitemap Available" required style="flex:1">
        <button type="submit" class="btn btn-primary" style="white-space:nowrap">➕ Add Item</button>
      </div>
      <p style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--tx3);margin-top:8px">
        💡 Yeh items har project ke "Website Compliance Checklist" mein dikhenge — jaise logos, photos, accessibility, etc.
      </p>
    </form>
  </div>

  <!-- Current items table -->
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:12px 16px;border-bottom:1px solid var(--bdr);font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.2px;color:var(--tx2)">
      Current Checklist Items (<?= count($checklistItems) ?>)
    </div>
    <table class="admin-table">
      <thead><tr><th style="width:50px">#</th><th>Item Name</th><th style="width:80px;text-align:center">Action</th></tr></thead>
      <tbody>
      <?php foreach ($checklistItems as $ci): ?>
      <tr>
        <td class="td-mono"><?= $ci['id'] ?></td>
        <td style="font-size:13px"><?= htmlspecialchars($ci['item_name']) ?></td>
        <td style="text-align:center">
          <form method="POST" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete_checklist_item">
            <input type="hidden" name="iid" value="<?= $ci['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this checklist item? Responses will be removed too.">🗑</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$checklistItems): ?>
      <tr><td colspan="3" class="no-data">No items yet</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ LOGS TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'logs' ? 'active' : '' ?>" id="tab-logs">
  <div class="card" style="margin-bottom:14px">
    <h2>Activity Log — <?= intval($log_total) ?> Total &nbsp;·&nbsp; Page <?= intval($log_page) ?>/<?= intval($log_pages) ?></h2>
    <?php
    $icons = ['login'=>'🟢','logout'=>'🔴','add_project'=>'➕','edit_project'=>'✏',
              'delete_project'=>'🗑','view_password'=>'👁','export_csv'=>'📊',
              'export_json'=>'📄','export_report'=>'🖨','change_password'=>'🔑',
              'ip_whitelist_add'=>'🛡','ip_whitelist_remove'=>'🚫','ip_whitelist_toggle'=>'🔄'];
    foreach ($logs as $l):
      $icon = $icons[$l['action']] ?? '•'; ?>
    <div class="log-row">
      <span class="log-time"><?= date('d M H:i', strtotime($l['created_at'])) ?></span>
      <span class="log-user"><?= htmlspecialchars($l['username'] ?? 'system') ?></span>
      <span class="log-action">
        <?= $icon ?> <?= htmlspecialchars($l['action']) ?>
        <?php if ($l['detail']): ?><span style="color:var(--tx2)"> — <?= htmlspecialchars($l['detail']) ?></span><?php endif; ?>
        <?php if ($l['ip_address']): ?><span class="log-ip">[<?= htmlspecialchars($l['ip_address']) ?>]</span><?php endif; ?>
      </span>
    </div>
    <?php endforeach; ?>
    <?php if (!$logs): ?>
    <p style="color:var(--tx2);font-size:11px;font-family:'Courier New',Consolas,monospace">No activity yet.</p>
    <?php endif; ?>

    <?php if($log_pages > 1): ?>
    <div class="pagination-bar" style="margin-top:14px">
      <div class="pag-info"><?= intval($log_total) ?> entries &nbsp;·&nbsp; Showing <?= intval($log_offset+1) ?>–<?= intval(min($log_offset+$log_per_page,$log_total)) ?></div>
      <div class="pag-btns">
        <?php if($log_page > 1): ?>
          <a href="?tab=logs&log_page=1" class="pag-btn" title="First">«</a>
          <a href="?tab=logs&log_page=<?= $log_page-1 ?>" class="pag-btn">‹ Prev</a>
        <?php else: ?>
          <span class="pag-btn disabled">«</span>
          <span class="pag-btn disabled">‹ Prev</span>
        <?php endif; ?>
        <?php
        $ls = max(1, $log_page-2); $le = min($log_pages, $log_page+2);
        for($lp=$ls;$lp<=$le;$lp++): ?>
          <a href="?tab=logs&log_page=<?= $lp ?>" class="pag-btn<?= $lp===$log_page?' active':'' ?>"><?= $lp ?></a>
        <?php endfor; ?>
        <?php if($log_page < $log_pages): ?>
          <a href="?tab=logs&log_page=<?= $log_page+1 ?>" class="pag-btn">Next ›</a>
          <a href="?tab=logs&log_page=<?= $log_pages ?>" class="pag-btn" title="Last">»</a>
        <?php else: ?>
          <span class="pag-btn disabled">Next ›</span>
          <span class="pag-btn disabled">»</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ TRASH TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'trash' ? 'active' : '' ?>" id="tab-trash">
  <h2 style="font-size:14px;color:var(--tx2);margin-bottom:16px;font-family:'Courier New',Consolas,monospace">
    🗑 DELETED PROJECTS — <?= count($deleted_projects) ?> items
  </h2>
  <?php if (empty($deleted_projects)): ?>
  <p style="color:var(--tx2);font-size:13px;font-family:'Courier New',Consolas,monospace">Trash is empty.</p>
  <?php else: ?>
  <table class="admin-table">
    <tr>
      <th>Project Name</th><th>Department</th><th>Status</th>
      <th>Deleted At</th><th>Deleted By</th><th>Actions</th>
    </tr>
    <?php foreach ($deleted_projects as $dp): ?>
    <tr>
      <td><?= htmlspecialchars($dp['project_name']) ?></td>
      <td><?= htmlspecialchars($dp['department_name'] ?? '') ?></td>
      <td style="font-size:11px;color:var(--tx2)"><?= htmlspecialchars($dp['current_status'] ?? '') ?></td>
      <td style="font-family:'Courier New',Consolas,monospace;font-size:11px;color:var(--tx2)">
        <?= htmlspecialchars($dp['deleted_at'] ?? '') ?>
      </td>
      <td style="font-size:12px"><?= htmlspecialchars($dp['deleted_by_name'] ?? 'N/A') ?></td>
      <td style="display:flex;gap:6px">
        <form method="POST">
          <input type="hidden" name="csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="restore_project">
          <input type="hidden" name="id"     value="<?= intval($dp['id']) ?>">
          <button class="btn btn-ghost btn-sm" style="color:#00e676;border-color:#00e676">♻ Restore</button>
        </form>
        <form method="POST" onsubmit="return confirm('Permanently delete '<?= htmlspecialchars(addslashes($dp['project_name'])) ?>'? This CANNOT be undone.')">
          <input type="hidden" name="csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="hard_delete_project">
          <input type="hidden" name="id"     value="<?= intval($dp['id']) ?>">
          <button class="btn btn-ghost btn-sm" style="color:#ff3d5a;border-color:#ff3d5a">🗑 Delete Forever</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

</div><!-- /content -->

<!-- Hidden forms for reset-pw -->
<form method="POST" id="reset-form" style="display:none">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="reset_pw">
  <input type="hidden" name="uid" id="rpf-uid">
  <input type="hidden" name="new_pw" id="rpf-pw">
</form>

<script nonce="<?= csp_nonce() ?>">
function showTab(name, btn) {
  document.querySelectorAll('.tab-pane').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.dv-tab').forEach(t => t.classList.remove('active'));
  const pane = document.getElementById('tab-' + name);
  if (pane) pane.classList.add('active');
  if (btn) btn.classList.add('active');
}

// ── Global event delegation (CSP fix — replaces ALL inline onclick) ───────────
document.addEventListener('click', function(e) {
  // Tab switching
  const tabBtn = e.target.closest('.dv-tab[data-tab]');
  if (tabBtn) { showTab(tabBtn.dataset.tab, tabBtn); return; }

  // Generic confirm before form submit
  const confirmBtn = e.target.closest('[data-confirm]');
  if (confirmBtn && confirmBtn.type === 'submit') {
    if (!confirm(confirmBtn.dataset.confirm)) e.preventDefault();
    return;
  }

  // Reset password
  const rpBtn = e.target.closest('[data-action="reset-pw"]');
  if (rpBtn) { resetPw(parseInt(rpBtn.dataset.uid), rpBtn.dataset.uname); return; }

  // Delete IP with ownership warning
  const delIpBtn = e.target.closest('[data-confirm-ip]');
  if (delIpBtn && delIpBtn.type === 'submit') {
    if (!confirmDelIp(delIpBtn.dataset.confirmIp, delIpBtn.dataset.isOwn)) e.preventDefault();
    return;
  }
});

// Role select change via event delegation
document.addEventListener('change', function(e) {
  var sel = e.target.closest('select[data-action="change-role"]');
  if (sel) {
    // Show confirm modal instead of direct submit
    var uid  = parseInt(sel.dataset.uid);
    var role = sel.value;
    var orig = sel.dataset.orig || sel.value;
    // Revert immediately — only submit after password confirm
    sel.value = orig;
    openRoleConfirm(uid, role, orig, sel);
  }
});

function openRoleConfirm(uid, newRole, origRole, selEl) {
  document.getElementById('rcm-uid').value  = uid;
  document.getElementById('rcm-role').value = newRole;
  document.getElementById('rcm-pw').value   = '';
  document.getElementById('rcm-newrole').textContent = newRole.charAt(0).toUpperCase() + newRole.slice(1);
  document.getElementById('rcm-cancel').onclick = function() {
    if (selEl) selEl.value = origRole;
    document.getElementById('role-confirm-modal').classList.remove('open');
  };
  document.getElementById('role-confirm-modal').classList.add('open');
  setTimeout(function(){ document.getElementById('rcm-pw').focus(); }, 100);
}

document.addEventListener('DOMContentLoaded', function() {
  // Store original role values on all role selects
  document.querySelectorAll('select[data-action="change-role"]').forEach(function(s) {
    s.dataset.orig = s.value;
  });
  // Role confirm modal submit
  var rcForm = document.getElementById('role-confirm-form');
  if (rcForm) rcForm.addEventListener('submit', function(e) {
    var pw = document.getElementById('rcm-pw').value;
    if (!pw) { e.preventDefault(); alert('Password required!'); return; }
    document.getElementById('role-confirm-modal').classList.remove('open');
  });
  // Close on backdrop click
  var rcModal = document.getElementById('role-confirm-modal');
  if (rcModal) rcModal.addEventListener('click', function(e) {
    if (e.target === rcModal) rcModal.classList.remove('open');
  });
});

function changeRole(uid, role) {
  // Legacy — now handled by openRoleConfirm
}

function resetPw(uid, name) {
  const pw = prompt(`New password for "${name}" (min 8 chars):`);
  if (!pw || pw.length < 8) { if (pw !== null) alert('Password minimum 8 characters hona chahiye!'); return; }
  document.getElementById('rpf-uid').value = uid;
  document.getElementById('rpf-pw').value = pw;
  document.getElementById('reset-form').submit();
}

function confirmDelIp(ip, isYou) {
  if (isYou === 'yes') {
    return confirm(`⚠️ WARNING: You are about to remove YOUR OWN IP (${ip}) from the whitelist.\n\nIf the whitelist has other entries, you will be LOCKED OUT immediately!\n\nAre you absolutely sure?`);
  }
  return confirm(`Remove IP ${ip} from whitelist?`);
}

// Auto-open tab from URL param
(function() {
  const tab = new URLSearchParams(window.location.search).get('tab');
  if (tab) {
    const btn = document.querySelector(`.dv-tab[data-tab="${tab}"]`);
    if (btn) showTab(tab, btn);
  }
})();
</script>
<script nonce="<?= csp_nonce() ?>">
window.DEVVAULT_CSRF   = '<?= csrf_token() ?>';

// ── Delete Option Modal (T-2 & T-3) ──────────────────────────────
document.addEventListener('click', function(e) {
  var btn = e.target.closest('[data-action="del-opt-modal"]');
  if (!btn) return;
  var oid   = btn.dataset.oid;
  var val   = btn.dataset.val;
  var usage = parseInt(btn.dataset.usage) || 0;

  document.getElementById('delopt-oid').value   = oid;
  document.getElementById('delopt-val').value   = '';
  document.getElementById('delopt-pw').value    = '';
  document.getElementById('delopt-name-disp').textContent = val;
  document.getElementById('delopt-usage').textContent =
    usage > 0 ? '⚠ ' + usage + ' project(s) mein yeh value use ho rahi hai. Delete se existing data affect nahi hoga, lekin dropdown se hat jaayegi.' : '✅ Koi project is value ka use nahi karta.';
  document.getElementById('delopt-usage').style.color = usage > 0 ? 'var(--warn)' : 'var(--ok)';
  document.getElementById('del-opt-modal').classList.add('open');
  document.getElementById('delopt-pw').focus();
});
document.getElementById('delopt-cancel').addEventListener('click', function(){
  document.getElementById('del-opt-modal').classList.remove('open');
});
document.getElementById('del-opt-modal').addEventListener('click', function(e){
  if(e.target === this) this.classList.remove('open');
});

window.DEVVAULT_LOGOUT = 'logout.php';
</script>
<!-- ── Delete Option Confirm Modal (T-2 T-3) ──────────────────────── -->
<div class="modal-backdrop" id="del-opt-modal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <span class="modal-title">🗑 Delete Dropdown Option</span>
      <button class="modal-close" id="delopt-cancel">✕</button>
    </div>
    <p style="font-size:13px;color:var(--tx2);margin-bottom:12px">
      Option <strong id="delopt-name-disp" style="color:var(--err)"></strong> ko permanently delete karna chahte ho?
    </p>
    <div id="delopt-usage" style="font-size:12px;padding:8px 12px;border-radius:8px;background:var(--sur2);margin-bottom:14px;line-height:1.5"></div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="delete_option">
      <input type="hidden" name="oid" id="delopt-oid">
      <div style="display:flex;flex-direction:column;gap:12px">
        <div class="field">
          <label class="field-label">Option ka naam likhkar confirm karo <span class="req">*</span></label>
          <input type="text" name="confirm_name" id="delopt-val" placeholder="Exact naam likhein…" required autocomplete="off">
          <span class="field-hint">Case-insensitive match karega</span>
        </div>
        <div class="field">
          <label class="field-label">Apna Admin Password <span class="req">*</span></label>
          <input type="password" name="confirm_pw" id="delopt-pw" placeholder="Current password…" required autocomplete="current-password">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" id="delopt-cancel2" data-action="close-delopt">Cancel</button>
        <button type="submit" class="btn btn-danger">🗑 Haan, Delete Karo</button>
      </div>
    </form>
  </div>
</div>
<!-- ── Role Change Confirm Modal ──────────────────────────────────────────── -->
<div class="modal-backdrop" id="role-confirm-modal">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <span class="modal-title">🔐 Confirm Role Change</span>
      <button class="modal-close" id="rcm-cancel">✕</button>
    </div>
    <p style="font-size:13px;color:var(--tx2);margin-bottom:14px">
      User ka role change karke <strong id="rcm-newrole" style="color:var(--acc)"></strong> karna chahte ho?<br>
      Confirm karne ke liye apna admin password daalen:
    </p>
    <form method="POST" id="role-confirm-form">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="change_role">
      <input type="hidden" name="uid" id="rcm-uid">
      <input type="hidden" name="role" id="rcm-role">
      <div style="margin-bottom:14px">
        <input type="password" id="rcm-pw" name="admin_pw" placeholder="Your admin password…" required autocomplete="current-password">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" id="rcm-cancel2" onclick="document.getElementById('role-confirm-modal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">✅ Confirm Change</button>
      </div>
    </form>
  </div>
</div>

</div><!-- /.dv-content -->
<?php require_once __DIR__ . '/includes/sidebar_footer.php'; ?>
