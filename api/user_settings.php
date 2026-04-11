<?php
/**
 * WealthDash — User Settings API (t54)
 * Actions: user_profile_save, user_change_password
 */
define('WEALTHDASH', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0); ini_set('display_errors', '0');
ob_start();
$user   = require_auth();
$userId = (int)$user['id'];
$db     = DB::conn();
$action = clean($_POST['action'] ?? '');

function saveUserPref(int $uid, string $key, string $val): void {
    DB::conn()->prepare("INSERT INTO app_settings (setting_key, setting_value)
        VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=?"
    )->execute(["user_{$uid}_{$key}", $val, $val]);
}

try {

    /* ── Save Profile ── */
    if ($action === 'user_profile_save') {
        $name   = substr(clean($_POST['name'] ?? ''), 0, 120);
        $income = clean($_POST['income_slab']  ?? '');
        $risk   = in_array($_POST['risk_appetite'] ?? '', ['conservative','moderate','aggressive'])
                  ? $_POST['risk_appetite'] : 'moderate';
        $regime = in_array($_POST['tax_regime'] ?? '', ['old','new']) ? $_POST['tax_regime'] : 'new';
        if (!$name) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Name required']); exit; }
        // Update name in users table
        $db->prepare("UPDATE users SET name=?, updated_at=NOW() WHERE id=?")->execute([$name, $userId]);
        // Store other prefs as app_settings
        saveUserPref($userId, 'income_slab',   $income);
        saveUserPref($userId, 'risk_appetite',  $risk);
        saveUserPref($userId, 'tax_regime',     $regime);
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Profile updated']);
        exit;
    }

    /* ── Change Password ── */
    if ($action === 'user_change_password') {
        $pwd = $_POST['password'] ?? '';
        if (strlen($pwd) < 8) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Password must be at least 8 characters']); exit; }
        $hash = password_hash($pwd, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password=?, updated_at=NOW() WHERE id=?")->execute([$hash, $userId]);
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
        exit;
    }

    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
