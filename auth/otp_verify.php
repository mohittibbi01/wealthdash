<?php
// Standalone OTP verify endpoint (used by API calls)
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed', [], 405);
}

csrf_verify();

$userId  = (int) ($_SESSION['_otp_user_id'] ?? 0);
$mobile  = $_SESSION['_otp_mobile'] ?? '';
$otp     = clean($_POST['otp'] ?? '');
$purpose = clean($_POST['purpose'] ?? 'login');

if (!$userId || !$mobile) {
    json_response(false, 'Session expired. Please start again.');
}

if (!$otp || !preg_match('/^\d{6}$/', $otp)) {
    json_response(false, 'Please enter a valid 6-digit OTP.');
}

if (Notification::verify_otp($userId, $mobile, $otp, $purpose)) {
    json_response(true, 'OTP verified successfully.', ['user_id' => $userId]);
} else {
    json_response(false, 'Invalid or expired OTP. Please try again.');
}

