<?php
/**
 * WealthDash — t350: Font Size Preference — Accessibility Setting
 * File: api/security/font_preference.php
 * Actions: font_size_get, font_size_save
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {
    case 'font_size_get': {
        $size = DB::fetchVal("SELECT font_size FROM users WHERE id=?", [$userId]) ?? 'medium';
        json_response(true,'ok',['font_size'=>$size]);
        break;
    }
    case 'font_size_save': {
        csrf_verify();
        $size = clean($_POST['font_size'] ?? 'medium');
        if (!in_array($size, ['small','medium','large','xlarge'])) json_response(false,'Invalid size.');
        DB::execute("UPDATE users SET font_size=? WHERE id=?", [$size, $userId]);
        json_response(true,'Font size updated.');
        break;
    }
    default: json_response(false,'Unknown action.',[],400);
}
