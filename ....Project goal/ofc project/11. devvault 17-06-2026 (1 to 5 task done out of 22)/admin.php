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
    $action = $_POST['action'] ?? '';

    // ── User actions ──────────────────────────────────────────────────────────
    if ($action === 'add_user') {
        $u    = trim($_POST['username'] ?? '');
        $pw   = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? '', ['admin', 'member', 'viewer']) ? $_POST['role'] : 'member';
        if ($u && $pw) try {
            $db->prepare("INSERT INTO users(username,password_hash,role,is_active,password_changed) VALUES(?,?,?,1,1)")
               ->execute([$u, password_hash($pw, PASSWORD_DEFAULT), $role]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "✅ User \"$u\" added."];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => '⚠ Username already exists.'];
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
        $uid  = intval($_POST['uid'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['admin', 'member', 'viewer']) ? $_POST['role'] : 'member';
        if ($uid !== $_SESSION['user_id'])
            $db->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role, $uid]);
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
        if ($np && strlen($np) >= 4) {
            $db->prepare("UPDATE users SET password_hash=?, password_changed=1 WHERE id=?")
               ->execute([password_hash($np, PASSWORD_DEFAULT), $uid]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '🔑 Password reset.'];
        }
    }

    if ($action === 'delete_option') {
        $oid = intval($_POST['oid'] ?? 0);
        $db->prepare("DELETE FROM dynamic_options WHERE id=?")->execute([$oid]);
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
$logs           = $db->query("SELECT l.*,u.username FROM activity_log l LEFT JOIN users u ON l.user_id=u.id ORDER BY l.created_at DESC LIMIT 150")->fetchAll();
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
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault Pro — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=Orbitron:wght@700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --accent:<?= $accent ?>;
  --fs:<?= $fsize ?>px;
  --bg:#070b14;--surface:#0d1422;--surface2:#111a2e;--surface3:#16213e;
  --border:#1e2d4a;--text:#e8edf5;--muted:#5a7a9a;
  --success:#00e676;--danger:#ff3d5a;--amber:#ffd740;--purple:#ea80fc;--blue:#40c4ff;
}
[data-theme="light"]{
  --bg:#f0f4f8;--surface:#fff;--surface2:#e8edf5;--surface3:#dde3ed;
  --border:#c8d4e0;--text:#0d1422;--muted:#5a7a9a;
}
html{font-size:var(--fs)}
body{font-family:'<?= $ffamily ?>',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
body::before{content:'';position:fixed;inset:0;
  background-image:linear-gradient(rgba(0,212,255,.018) 1px,transparent 1px),
    linear-gradient(90deg,rgba(0,212,255,.018) 1px,transparent 1px);
  background-size:40px 40px;pointer-events:none;z-index:0}
[data-theme="light"] body::before{opacity:.3}

/* ── TOPBAR ── */
.topbar{position:sticky;top:0;z-index:100;background:rgba(7,11,20,.95);
  border-bottom:1px solid var(--border);backdrop-filter:blur(12px);
  padding:0 20px;height:52px;display:flex;align-items:center;gap:10px}
[data-theme="light"] .topbar{background:rgba(240,244,248,.95)}
.logo-txt{font-family:'Orbitron',monospace;font-size:14px;font-weight:900;
  letter-spacing:2px;color:var(--accent);text-shadow:0 0 16px var(--accent)}

.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:7px;
  font-size:12px;font-weight:600;font-family:'Rajdhani',sans-serif;letter-spacing:.4px;
  cursor:pointer;border:none;text-decoration:none;transition:all .15s;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-ghost{background:var(--surface2);color:var(--muted);border:1px solid var(--border)}
.btn-ghost:hover{color:var(--text);border-color:var(--muted)}
.btn-accent{background:var(--accent);color:#000}
.btn-accent:hover{opacity:.85}
.btn-danger{background:rgba(255,61,90,.12);color:var(--danger);border:1px solid rgba(255,61,90,.25)}
.btn-danger:hover{background:rgba(255,61,90,.22)}
.btn-warn{background:rgba(255,215,64,.12);color:var(--amber);border:1px solid rgba(255,215,64,.25)}
.btn-warn:hover{background:rgba(255,215,64,.22)}
.btn-success{background:rgba(0,230,118,.12);color:var(--success);border:1px solid rgba(0,230,118,.25)}
.btn-success:hover{background:rgba(0,230,118,.22)}
.btn-info{background:rgba(64,196,255,.12);color:var(--blue);border:1px solid rgba(64,196,255,.25)}
.btn-info:hover{background:rgba(64,196,255,.22)}

/* ── CONTENT ── */
.content{padding:16px 20px;position:relative;z-index:1}

/* ── TABS ── */
.tabs{display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);
  border-radius:10px;padding:4px;margin-bottom:14px;flex-wrap:wrap}
.tab{flex:1;text-align:center;padding:8px 10px;border-radius:7px;cursor:pointer;
  font-size:13px;font-weight:700;font-family:'Rajdhani',sans-serif;border:none;
  background:none;color:var(--muted);transition:all .15s;min-width:80px}
.tab.active{background:var(--accent);color:#000}
.tab:hover:not(.active){background:var(--surface2);color:var(--text)}
.tab-pane{display:none}
.tab-pane.active{display:block}

/* ── FLASH ── */
.flash{padding:10px 14px;border-radius:8px;font-size:12px;
  font-family:'Share Tech Mono',monospace;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.flash-success{background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.25);color:var(--success)}
.flash-error{background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.25);color:var(--danger)}

/* ── LAYOUT ── */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px}
.panel h2{font-family:'Share Tech Mono',monospace;font-size:10px;text-transform:uppercase;
  letter-spacing:1.5px;color:var(--muted);margin-bottom:12px;padding-bottom:8px;
  border-bottom:1px solid var(--border)}

/* ── TABLES ── */
.user-table{width:100%;border-collapse:collapse;font-size:12px}
.user-table th{text-align:left;padding:7px 10px;font-family:'Share Tech Mono',monospace;
  font-size:9px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);
  border-bottom:1px solid var(--border)}
.user-table td{padding:8px 10px;border-bottom:1px solid rgba(30,45,74,.4);
  font-family:'Share Tech Mono',monospace;font-size:11px;vertical-align:middle}
.user-table tr:last-child td{border-bottom:none}
.user-table tr:hover td{background:rgba(0,212,255,.02)}

.role-badge{padding:2px 8px;border-radius:20px;font-size:9px;font-weight:700;
  text-transform:uppercase;letter-spacing:.8px;border:1px solid currentColor}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}
.actions-cell{display:flex;gap:4px;align-items:center;flex-wrap:wrap}

/* ── FORM FIELDS ── */
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.field{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.field label{font-family:'Share Tech Mono',monospace;font-size:9.5px;text-transform:uppercase;
  letter-spacing:1.2px;color:var(--muted)}
input,select{background:var(--surface2);border:1px solid var(--border);border-radius:7px;
  padding:8px 10px;color:var(--text);font-size:13px;font-family:'Share Tech Mono',monospace;
  outline:none;transition:border-color .2s;width:100%}
input:focus,select:focus{border-color:var(--accent)}
select option{background:var(--surface2)}

/* ── LOG ROWS ── */
.log-row{display:flex;gap:8px;padding:6px 0;border-bottom:1px solid rgba(30,45,74,.35);
  font-size:11px;font-family:'Share Tech Mono',monospace;align-items:flex-start}
.log-row:last-child{border-bottom:none}
.log-time{color:var(--muted);min-width:110px;flex-shrink:0;font-size:10px}
.log-user{color:var(--accent);min-width:80px;flex-shrink:0}
.log-action{flex:1}
.log-ip{color:var(--border);font-size:10px;margin-left:4px}

/* ── OPTS ── */
.opt-group{margin-bottom:14px}
.opt-group h3{font-family:'Share Tech Mono',monospace;font-size:10px;text-transform:uppercase;
  letter-spacing:1px;color:var(--accent);margin-bottom:7px}
.opt-pills{display:flex;flex-wrap:wrap;gap:5px}
.opt-pill{display:inline-flex;align-items:center;gap:5px;background:var(--surface2);
  border:1px solid var(--border);border-radius:20px;padding:3px 9px;
  font-size:11px;font-family:'Share Tech Mono',monospace}
.opt-pill .rm{background:none;border:none;cursor:pointer;color:var(--danger);
  font-size:11px;padding:0;line-height:1;transition:opacity .15s}
.opt-pill .rm:hover{opacity:.7}

/* ── IP WHITELIST SPECIFIC ── */
.ip-status-banner{border-radius:10px;padding:14px 16px;margin-bottom:14px;
  font-family:'Share Tech Mono',monospace;font-size:12px;line-height:1.7}
.ip-on{background:rgba(255,61,90,.06);border:1px solid rgba(255,61,90,.25);color:var(--danger)}
.ip-off{background:rgba(0,230,118,.06);border:1px solid rgba(0,230,118,.2);color:var(--success)}
.ip-mine{background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.25);
  border-radius:8px;padding:10px 14px;margin-bottom:14px;
  font-family:'Share Tech Mono',monospace;font-size:12px}
.ip-mine strong{color:var(--accent);font-size:14px}
.ip-stat-row{display:flex;gap:10px;margin-bottom:14px}
.ip-stat{flex:1;background:var(--surface2);border:1px solid var(--border);border-radius:8px;
  padding:10px 12px;text-align:center;font-family:'Share Tech Mono',monospace}
.ip-stat .val{font-size:22px;font-weight:700;display:block}
.ip-stat .lbl{font-size:10px;color:var(--muted);display:block;margin-top:2px}

/* ── ROLE INFO ── */
.role-info{background:var(--surface2);border:1px solid var(--border);border-radius:8px;
  padding:10px 12px;font-family:'Share Tech Mono',monospace;font-size:11px;
  margin-top:12px;line-height:1.8}
.ri{display:flex;align-items:center;gap:6px;margin-bottom:4px}
.ri:last-child{margin-bottom:0}
</style>
</head>
<body>

<div class="topbar">
  <span class="logo-txt">DEVVAULT</span>
  <span style="color:var(--border)">|</span>
  <span style="font-size:15px;font-weight:700">⚙ Admin Panel</span>
  <div style="margin-left:auto;display:flex;gap:8px">
    <a href="index.php" class="btn btn-ghost">← Dashboard</a>
    <a href="logout.php" class="btn btn-ghost" title="Logout">⏏</a>
    <span id="session-timer-display" title="Session timer">⏱ 05:00</span>
  </div>
</div>

<div class="content">

<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
<?php endif; ?>

<!-- TABS -->
<div class="tabs">
  <button class="tab <?= $active_tab === 'users'     ? 'active' : '' ?>" onclick="showTab('users',this)">👥 Users (<?= count($users) ?>)</button>
  <button class="tab <?= $active_tab === 'add'       ? 'active' : '' ?>" onclick="showTab('add',this)">➕ Add User</button>
  <button class="tab <?= $active_tab === 'ip'        ? 'active' : '' ?>" onclick="showTab('ip',this)">🛡 IP Whitelist <?= $ip_total > 0 ? "($ip_active/$ip_total)" : '' ?></button>
  <button class="tab <?= $active_tab === 'options'   ? 'active' : '' ?>" onclick="showTab('options',this)">🔧 Dropdown Options</button>
  <button class="tab <?= $active_tab === 'checklist' ? 'active' : '' ?>" onclick="showTab('checklist',this)">✅ Checklist Items</button>
  <button class="tab <?= $active_tab === 'logs'      ? 'active' : '' ?>" onclick="showTab('logs',this)">📋 Activity Log</button>
</div>

<!-- ═══ USERS TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'users' ? 'active' : '' ?>" id="tab-users">
  <div class="panel">
    <h2>Team Members</h2>
    <table class="user-table">
      <tr>
        <th>#</th><th>Status</th><th>Username</th><th>Role</th>
        <th>Projects</th><th>Joined</th><th>Actions</th>
      </tr>
      <?php foreach ($users as $u):
        $isActive = (int)($u['is_active'] ?? 1);
        $rclr = $roleColors[$u['role']] ?? '#8b949e';
      ?>
      <tr style="<?= !$isActive ? 'opacity:.5' : '' ?>">
        <td><?= $u['id'] ?></td>
        <td>
          <span class="status-dot" style="background:<?= $isActive ? 'var(--success)' : 'var(--danger)' ?>"
            title="<?= $isActive ? 'Active' : 'Inactive' ?>"></span>
        </td>
        <td style="font-weight:700;color:var(--text);font-size:12px"><?= htmlspecialchars($u['username']) ?></td>
        <td>
          <span class="role-badge" style="color:<?= $rclr ?>;border-color:<?= $rclr ?>40">
            <?= $roleLabel[$u['role']] ?? $u['role'] ?>
          </span>
        </td>
        <td style="text-align:center"><?= $u['pc'] ?></td>
        <td style="color:var(--muted)"><?= date('d M y', strtotime($u['created_at'])) ?></td>
        <td>
          <?php if ($u['id'] !== $_SESSION['user_id']): ?>
          <div class="actions-cell">
            <select class="role-sel" data-uid="<?= $u['id'] ?>"
              style="background:var(--surface2);border:1px solid var(--border);border-radius:5px;
                padding:3px 6px;color:var(--text);font-size:10px;font-family:'Share Tech Mono',monospace;
                outline:none;cursor:pointer;width:auto"
              onchange="changeRole(<?= $u['id'] ?>,this.value)">
              <option value="admin"  <?= $u['role'] === 'admin'  ? 'selected' : '' ?>>Admin</option>
              <option value="member" <?= $u['role'] === 'member' ? 'selected' : '' ?>>Member</option>
              <option value="viewer" <?= $u['role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
            </select>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <button type="submit" class="btn <?= $isActive ? 'btn-warn' : 'btn-success' ?>" style="padding:4px 8px">
                <?= $isActive ? '⛔ Deactivate' : '✅ Activate' ?>
              </button>
            </form>
            <button class="btn btn-ghost" onclick="resetPw(<?= $u['id'] ?>,'<?= htmlspecialchars($u['username']) ?>')"
              style="padding:4px 7px">🔑</button>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-danger" style="padding:4px 7px"
                onclick="return confirm('Delete user <?= htmlspecialchars($u['username']) ?>?')">🗑</button>
            </form>
          </div>
          <?php else: ?>
          <span style="font-size:10px;color:var(--muted);font-family:'Share Tech Mono',monospace">(you)</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div class="role-info" style="margin-top:14px">
      <div class="ri"><span class="role-badge" style="color:<?= $roleColors['admin'] ?>;border-color:<?= $roleColors['admin'] ?>40">Admin</span> — Full access: add, edit, delete projects + manage users</div>
      <div class="ri"><span class="role-badge" style="color:<?= $roleColors['member'] ?>;border-color:<?= $roleColors['member'] ?>40">Member</span> — Add & edit projects, view all data, export</div>
      <div class="ri"><span class="role-badge" style="color:<?= $roleColors['viewer'] ?>;border-color:<?= $roleColors['viewer'] ?>40">Viewer</span> — View only: cannot add, edit, or delete anything</div>
    </div>
  </div>
</div>

<!-- ═══ ADD USER TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'add' ? 'active' : '' ?>" id="tab-add">
  <div class="two-col">
    <div class="panel">
      <h2>Add New User</h2>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_user">
        <div class="fg2">
          <div class="field"><label>Username</label>
            <input type="text" name="username" placeholder="e.g. john_dev" required>
          </div>
          <div class="field"><label>Password</label>
            <input type="password" name="password" placeholder="Min 6 chars" required>
          </div>
        </div>
        <div class="field"><label>Role</label>
          <select name="role">
            <option value="member">Member — add + edit projects</option>
            <option value="viewer">Viewer — view only (read-only)</option>
            <option value="admin">Admin — full access + users</option>
          </select>
        </div>
        <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center;padding:9px">➕ Add User</button>
      </form>
    </div>
    <div class="panel">
      <h2>Role Permissions</h2>
      <table style="width:100%;border-collapse:collapse;font-size:11px;font-family:'Share Tech Mono',monospace">
        <tr style="border-bottom:1px solid var(--border)">
          <th style="text-align:left;padding:6px 8px;color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:1px">Permission</th>
          <th style="padding:6px 8px;color:<?= $roleColors['viewer'] ?>;font-size:9px;text-transform:uppercase;letter-spacing:1px">Viewer</th>
          <th style="padding:6px 8px;color:<?= $roleColors['member'] ?>;font-size:9px;text-transform:uppercase;letter-spacing:1px">Member</th>
          <th style="padding:6px 8px;color:<?= $roleColors['admin'] ?>;font-size:9px;text-transform:uppercase;letter-spacing:1px">Admin</th>
        </tr>
        <?php foreach ([
          'View Projects'   => [1,1,1],
          'Search & Filter' => [1,1,1],
          'View Passwords'  => [1,1,1],
          'Export Data'     => [0,1,1],
          'Add Project'     => [0,1,1],
          'Edit Project'    => [0,1,1],
          'Delete Project'  => [0,0,1],
          'Manage Users'    => [0,0,1],
          'IP Whitelist'    => [0,0,1],
          'Admin Panel'     => [0,0,1],
        ] as $perm => [$v,$m,$a]): ?>
        <tr style="border-bottom:1px solid rgba(30,45,74,.4)">
          <td style="padding:6px 8px;color:var(--text)"><?= $perm ?></td>
          <td style="text-align:center;padding:6px 8px"><?= $v ? '<span style="color:var(--success)">✓</span>' : '<span style="color:var(--border)">–</span>' ?></td>
          <td style="text-align:center;padding:6px 8px"><?= $m ? '<span style="color:var(--success)">✓</span>' : '<span style="color:var(--border)">–</span>' ?></td>
          <td style="text-align:center;padding:6px 8px"><?= $a ? '<span style="color:var(--success)">✓</span>' : '<span style="color:var(--border)">–</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>

<!-- ═══ IP WHITELIST TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'ip' ? 'active' : '' ?>" id="tab-ip">

  <!-- Status banner -->
  <?php if ($ip_total === 0): ?>
  <div class="ip-status-banner ip-off">
    <strong>🟢 Whitelist Inactive</strong> — Table is empty. All IPs can currently access the system.<br>
    Add at least one IP to enable whitelist enforcement. <strong>Add your own IP first before enabling!</strong>
  </div>
  <?php else: ?>
  <div class="ip-status-banner ip-on">
    <strong>🔴 Whitelist Active</strong> — Only <?= $ip_active ?> enabled IP(s) can access this system. All other IPs will see the blocked page.
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="ip-stat-row">
    <div class="ip-stat">
      <span class="val" style="color:var(--accent)"><?= $ip_total ?></span>
      <span class="lbl">Total IPs</span>
    </div>
    <div class="ip-stat">
      <span class="val" style="color:var(--success)"><?= $ip_active ?></span>
      <span class="lbl">Enabled</span>
    </div>
    <div class="ip-stat">
      <span class="val" style="color:var(--danger)"><?= $ip_total - $ip_active ?></span>
      <span class="lbl">Disabled</span>
    </div>
  </div>

  <!-- Current IP quick-add -->
  <div class="ip-mine">
    <span style="color:var(--muted);font-size:11px">YOUR CURRENT IP</span><br>
    <strong><?= htmlspecialchars($client_ip) ?></strong>
    <?php
      $already = false;
      foreach ($ipList as $ipr) { if ($ipr['ip_address'] === $client_ip) { $already = true; break; } }
    ?>
    <?php if ($already): ?>
      <span style="color:var(--success);font-size:11px;margin-left:10px">✓ Already in whitelist</span>
    <?php else: ?>
      <form method="POST" style="display:inline-flex;gap:8px;align-items:center;margin-left:12px">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_current_ip">
        <input type="text" name="current_ip_label" placeholder="Label (e.g. My PC)"
          style="width:160px;padding:4px 8px;font-size:11px">
        <button type="submit" class="btn btn-success" style="padding:4px 10px">+ Add My IP</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="two-col">
    <!-- IP List -->
    <div class="panel" style="grid-column:1/-1">
      <h2>Whitelisted IPs (<?= $ip_total ?>)</h2>
      <?php if ($ipList): ?>
      <table class="user-table">
        <tr>
          <th>#</th>
          <th>IP Address</th>
          <th>Label</th>
          <th>Added By</th>
          <th>Added On</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
        <?php foreach ($ipList as $ipr): ?>
        <tr style="<?= !$ipr['is_active'] ? 'opacity:.5' : '' ?>">
          <td><?= $ipr['id'] ?></td>
          <td style="font-weight:700;color:<?= $ipr['ip_address'] === $client_ip ? 'var(--accent)' : 'var(--text)' ?>;font-size:12px">
            <?= htmlspecialchars($ipr['ip_address']) ?>
            <?php if ($ipr['ip_address'] === $client_ip): ?>
              <span style="font-size:9px;color:var(--accent);margin-left:4px">(you)</span>
            <?php endif; ?>
          </td>
          <td style="color:var(--muted)"><?= htmlspecialchars($ipr['label'] ?? '') ?></td>
          <td style="color:var(--muted)"><?= htmlspecialchars($ipr['added_by_name'] ?? 'system') ?></td>
          <td style="color:var(--muted)"><?= date('d M y H:i', strtotime($ipr['added_at'])) ?></td>
          <td>
            <span class="status-dot" style="background:<?= $ipr['is_active'] ? 'var(--success)' : 'var(--danger)' ?>"></span>
            <span style="font-size:10px;color:var(--muted);margin-left:4px"><?= $ipr['is_active'] ? 'Active' : 'Disabled' ?></span>
          </td>
          <td>
            <div class="actions-cell">
              <!-- Toggle enable/disable -->
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="toggle_ip">
                <input type="hidden" name="iid" value="<?= $ipr['id'] ?>">
                <button type="submit" class="btn <?= $ipr['is_active'] ? 'btn-warn' : 'btn-success' ?>" style="padding:4px 8px">
                  <?= $ipr['is_active'] ? '⛔ Disable' : '✅ Enable' ?>
                </button>
              </form>
              <!-- Delete -->
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_ip">
                <input type="hidden" name="iid" value="<?= $ipr['id'] ?>">
                <button type="submit" class="btn btn-danger" style="padding:4px 7px"
                  onclick="return confirmDelIp('<?= htmlspecialchars($ipr['ip_address']) ?>','<?= $ipr['ip_address'] === $client_ip ? 'yes' : 'no' ?>')">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php else: ?>
      <p style="color:var(--muted);font-family:'Share Tech Mono',monospace;font-size:12px;padding:12px 0">
        No IPs added yet. Add your own IP first using the quick-add above.
      </p>
      <?php endif; ?>
    </div>

    <!-- Add new IP manually -->
    <div class="panel">
      <h2>Add IP Manually</h2>
      <form method="POST" action="admin.php">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_ip">
        <div class="field">
          <label>IP Address (IPv4 or IPv6)</label>
          <input type="text" name="ip_address" placeholder="e.g. 192.168.1.45" required>
        </div>
        <div class="field">
          <label>Label / Description</label>
          <input type="text" name="ip_label" placeholder="e.g. Cabin 3 PC, Server Room">
        </div>
        <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center;padding:9px">
          🛡 Add to Whitelist
        </button>
      </form>
    </div>

    <!-- Instructions -->
    <div class="panel">
      <h2>How IP Whitelist Works</h2>
      <div style="font-family:'Share Tech Mono',monospace;font-size:11px;color:var(--muted);line-height:1.9">
        <p style="margin-bottom:8px">📌 <strong style="color:var(--text)">When list is EMPTY</strong> — All IPs allowed (safe default for fresh setup)</p>
        <p style="margin-bottom:8px">🔴 <strong style="color:var(--text)">When list has entries</strong> — ONLY listed active IPs can access. Others see blocked page.</p>
        <p style="margin-bottom:8px">⚠️ <strong style="color:var(--amber)">Important:</strong> Always add your own IP before adding others. If you accidentally lock yourself out, delete/rename the ip_whitelist table in vault.db directly.</p>
        <p style="margin-bottom:8px">🔄 <strong style="color:var(--text)">Disable vs Delete</strong> — Disable temporarily blocks an IP without removing it. Delete removes permanently.</p>
        <p>🏠 <strong style="color:var(--text)">Localhost</strong> — 127.0.0.1 and ::1 are always allowed regardless of whitelist.</p>
      </div>
    </div>
  </div>
</div>

<!-- ═══ OPTIONS TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'options' ? 'active' : '' ?>" id="tab-options">
  <div class="two-col">
    <?php foreach ($groupLabels as $grp => $lbl): ?>
    <div class="panel">
      <h2><?= $lbl ?> Options</h2>
      <div class="opt-pills">
        <?php foreach ($optsByGroup[$grp] ?? [] as $o): ?>
        <span class="opt-pill">
          <?= htmlspecialchars($o['option_value']) ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete_option">
            <input type="hidden" name="oid" value="<?= $o['id'] ?>">
            <button type="submit" class="rm" onclick="return confirm('Remove this option?')">✕</button>
          </form>
        </span>
        <?php endforeach; ?>
        <?php if (empty($optsByGroup[$grp])): ?>
        <span style="font-family:'Share Tech Mono',monospace;font-size:11px;color:var(--muted)">No options yet</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="panel" style="grid-column:1/-1">
      <h2>How Options Work</h2>
      <p style="font-family:'Share Tech Mono',monospace;font-size:11px;color:var(--muted);line-height:1.8">
        💡 Project form mein "Other" select karo aur value type karo — wo automatically yahan save ho jaegi.<br>
        Agle baar wo dropdown mein dikhegi. Yahan se unwanted options remove kar sakte ho.
      </p>
    </div>
  </div>
</div>

<!-- ═══ CHECKLIST TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'checklist' ? 'active' : '' ?>" id="tab-checklist">
  <div class="two-col">
    <div class="panel">
      <h2>Add Checklist Item</h2>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_checklist_item">
        <div class="field"><label>Item Name</label>
          <input type="text" name="item_name" placeholder="e.g. Sitemap Available" required>
        </div>
        <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center;padding:9px">➕ Add Item</button>
      </form>
      <p style="font-family:'Share Tech Mono',monospace;font-size:11px;color:var(--muted);margin-top:12px;line-height:1.8">
        💡 Yeh items har project ke "Website Compliance Checklist" mein dikhenge — jaise logos, photos, accessibility, etc.
      </p>
    </div>
    <div class="panel">
      <h2>Current Checklist Items (<?= count($checklistItems) ?>)</h2>
      <table class="user-table">
        <tr><th>#</th><th>Item Name</th><th>Action</th></tr>
        <?php foreach ($checklistItems as $ci): ?>
        <tr>
          <td><?= $ci['id'] ?></td>
          <td style="color:var(--text);font-size:12px"><?= htmlspecialchars($ci['item_name']) ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete_checklist_item">
              <input type="hidden" name="iid" value="<?= $ci['id'] ?>">
              <button type="submit" class="btn btn-danger" style="padding:4px 7px"
                onclick="return confirm('Delete this checklist item? Responses will be removed too.')">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$checklistItems): ?>
        <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:14px">No items yet</td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- ═══ LOGS TAB ═══ -->
<div class="tab-pane <?= $active_tab === 'logs' ? 'active' : '' ?>" id="tab-logs">
  <div class="panel">
    <h2>Activity Log — Last 150 Actions</h2>
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
        <?php if ($l['detail']): ?><span style="color:var(--muted)"> — <?= htmlspecialchars($l['detail']) ?></span><?php endif; ?>
        <?php if ($l['ip_address']): ?><span class="log-ip">[<?= htmlspecialchars($l['ip_address']) ?>]</span><?php endif; ?>
      </span>
    </div>
    <?php endforeach; ?>
    <?php if (!$logs): ?>
    <p style="color:var(--muted);font-size:11px;font-family:'Share Tech Mono',monospace">No activity yet.</p>
    <?php endif; ?>
  </div>
</div>

</div><!-- /content -->

<!-- Hidden forms -->
<form method="POST" id="role-form" style="display:none">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="change_role">
  <input type="hidden" name="uid" id="rf-uid">
  <input type="hidden" name="role" id="rf-role">
</form>

<form method="POST" id="reset-form" style="display:none">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="reset_pw">
  <input type="hidden" name="uid" id="rpf-uid">
  <input type="hidden" name="new_pw" id="rpf-pw">
</form>

<script>
function showTab(name, btn) {
  document.querySelectorAll('.tab-pane').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}

function changeRole(uid, role) {
  if (!confirm(`Change role to "${role}"?`)) {
    document.querySelectorAll('.role-sel').forEach(s => { if (parseInt(s.dataset.uid) === uid) s.value = s.dataset.orig || s.value });
    return;
  }
  document.getElementById('rf-uid').value = uid;
  document.getElementById('rf-role').value = role;
  document.getElementById('role-form').submit();
}
document.querySelectorAll('.role-sel').forEach(s => s.dataset.orig = s.value);

function resetPw(uid, name) {
  const pw = prompt(`New password for "${name}" (min 4 chars):`);
  if (!pw || pw.length < 4) { if (pw !== null) alert('Password too short!'); return; }
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
    const btn = document.querySelector(`.tab[onclick*="'${tab}'"]`);
    if (btn) showTab(tab, btn);
  }
})();
</script>
<script>
window.DEVVAULT_CSRF   = '<?= csrf_token() ?>';
window.DEVVAULT_LOGOUT = 'logout.php';
</script>
<script src="session_timer.js"></script>
</body>
</html>
