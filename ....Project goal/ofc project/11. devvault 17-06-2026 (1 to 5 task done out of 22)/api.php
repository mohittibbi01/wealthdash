<?php
require_once __DIR__ . '/auth.php';
require_login();
header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';


// ── POST: session keepalive (session timer extend) ───────────────────────
// Called by session_timer.js when user clicks "Extend Session"
if ($action === 'keepalive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { echo json_encode(['ok' => false, 'error' => 'CSRF']); exit; }
    // Touching $_SESSION resets PHP session gc timer
    $_SESSION['last_activity'] = time();
    echo json_encode(['ok' => true, 'ts' => time()]);
    exit;
}

// ── GET: live stats for dashboard refresh ────────────────────────────────
if ($action === 'stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $db = get_db();
    $stats = ['total' => (int)$db->query("SELECT COUNT(*) FROM projects")->fetchColumn()];
    foreach (['live','under_development','redevelopment','hold_by_department','content_updation','closed'] as $s) {
        $st = $db->prepare("SELECT COUNT(*) FROM projects WHERE current_status=?");
        $st->execute([$s]); $stats[$s] = (int)$st->fetchColumn();
    }
    foreach (['production','staging','local','audit','other'] as $e) {
        $st = $db->query("SELECT COUNT(*) FROM projects WHERE env_{$e}_url!='' AND env_{$e}_url IS NOT NULL");
        $stats[$e] = $st ? (int)$st->fetchColumn() : 0;
    }
    $stats['appsrv'] = (int)$db->query("SELECT COUNT(DISTINCT app_ip) FROM projects WHERE app_ip!='' AND app_ip IS NOT NULL")->fetchColumn();
    $stats['dbsrv']  = (int)$db->query("SELECT COUNT(DISTINCT db_ip) FROM projects WHERE db_ip!='' AND db_ip IS NOT NULL")->fetchColumn();
    echo json_encode(['stats' => $stats]);
    exit;
}

// ── GET: reveal password ─────────────────────────────────────────────────
// S03: Restricted to Admin role only
if ($action === 'get_pw' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // S03: Admin-only check
    if (($_SESSION['role'] ?? '') !== 'admin') {
        echo json_encode(['error' => 'Access denied. Admin only.']);
        log_activity('password_reveal_denied', null, "Unauthorized get_pw attempt by: {$_SESSION['username']}");
        exit;
    }
    $id   = intval($_GET['id'] ?? 0);
    $env  = preg_replace('/[^a-z]/', '', $_GET['env'] ?? '');
    $csrf = $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) { echo json_encode(['error'=>'CSRF']); exit; }
    $db  = get_db();
    $col = "env_{$env}_password";
    $st  = $db->prepare("SELECT `$col` FROM projects WHERE id=?");
    $st->execute([$id]); $row = $st->fetch();
    if (!$row) { echo json_encode(['error'=>'Not found']); exit; }
    // S03+S04: Log password reveal with detail
    log_activity('view_password', $id, "Password viewed for env: {$env} by: {$_SESSION['username']}");
    echo json_encode(['pw' => decrypt_val($row[$col] ?? '')]);
    exit;
}

// ── POST: delete project ─────────────────────────────────────────────────
if ($action === 'delete_project' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin()) { echo json_encode(['error'=>'Forbidden']); exit; }
    if (!verify_csrf()) { echo json_encode(['error'=>'CSRF']); exit; }
    $id  = intval($_POST['id'] ?? 0);
    $db  = get_db();
    $st  = $db->prepare("SELECT project_name FROM projects WHERE id=?");
    $st->execute([$id]); $row = $st->fetch();
    if ($row) {
        $db->prepare("DELETE FROM projects WHERE id=?")->execute([$id]);
        log_activity('delete_project', $id, $row['project_name']);
        backup_json();
        $_SESSION['flash'] = ['type'=>'success','msg'=>"🗑 Project \"{$row['project_name']}\" deleted."];
    }
    header('Location: index.php'); exit;
}

// ── POST: save user preferences ──────────────────────────────────────────
if ($action === 'save_prefs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { echo json_encode(['error'=>'CSRF']); exit; }
    $accent = substr(preg_replace('/[^#a-fA-F0-9]/', '', $_POST['accent'] ?? '#00d4ff'), 0, 7);
    $bg     = preg_replace('/[^#a-fA-F0-9]/', '', $_POST['bg'] ?? '');
    $bg     = $bg ? '#'.ltrim($bg,'#') : '';
    $theme  = in_array($_POST['theme'] ?? '', ['dark','light']) ? $_POST['theme'] : 'dark';
    $font   = in_array($_POST['font'] ?? '', ['Rajdhani','Share Tech Mono','Orbitron']) ? $_POST['font'] : 'Rajdhani';
    $fs     = max(11, min(18, intval($_POST['fs'] ?? 14)));

    $db = get_db();
    // Add bg_color column if not exists
    try { $db->exec("ALTER TABLE users ADD COLUMN bg_color TEXT DEFAULT ''"); } catch(Exception $e){}

    $db->prepare("UPDATE users SET accent_color=?,bg_color=?,theme=?,font_family=?,font_size=? WHERE id=?")
       ->execute([$accent, $bg, $theme, $font, $fs, $_SESSION['user_id']]);

    $_SESSION['prefs'] = [
        'accent'      => $accent,
        'bg_color'    => $bg,
        'theme'       => $theme,
        'font_family' => $font,
        'font_size'   => $fs,
    ];
    echo json_encode(['ok' => true]);
    exit;
}

// ── GET: download a document ─────────────────────────────────────────────
if ($action === 'download_doc' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id   = intval($_GET['id'] ?? 0);
    $csrf = $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) { http_response_code(403); exit('CSRF'); }
    $db = get_db();
    $st = $db->prepare("SELECT * FROM project_documents WHERE id=?");
    $st->execute([$id]); $doc = $st->fetch();
    if (!$doc) { http_response_code(404); exit('Not found'); }
    $path = UPLOAD_DIR . '/' . $doc['stored_name'];
    if (!file_exists($path)) { http_response_code(404); exit('File missing'); }
    log_activity('download_document', $doc['project_id'], $doc['filename']);
    header_remove('Content-Type');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($doc['filename']).'"');
    header('Content-Length: '.filesize($path));
    readfile($path);
    exit;
}

// ── POST: delete a document ───────────────────────────────────────────────
if ($action === 'delete_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can_edit()) { echo json_encode(['error'=>'Forbidden']); exit; }
    if (!verify_csrf()) { echo json_encode(['error'=>'CSRF']); exit; }
    $id = intval($_POST['id'] ?? 0);
    $db = get_db();
    $st = $db->prepare("SELECT * FROM project_documents WHERE id=?");
    $st->execute([$id]); $doc = $st->fetch();
    if ($doc) {
        $path = UPLOAD_DIR . '/' . $doc['stored_name'];
        if (file_exists($path)) @unlink($path);
        $db->prepare("DELETE FROM project_documents WHERE id=?")->execute([$id]);
        log_activity('delete_document', $doc['project_id'], $doc['filename']);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
