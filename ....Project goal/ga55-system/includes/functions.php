<?php
// ============================================================
// GA-55A SYSTEM — COMMON FUNCTIONS
// ============================================================

// Clean input — XSS se bachao
function clean($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

// Safe integer
function cleanInt($value) {
    return (int) trim($value);
}

// Safe decimal (amounts)
function cleanDecimal($value) {
    return round((float) str_replace(',', '', trim($value)), 2);
}

// Indian number format — 1,23,456.00
function indianFormat($number) {
    $number = number_format((float)$number, 2, '.', '');
    $parts  = explode('.', $number);
    $int    = $parts[0];
    $dec    = $parts[1];
    $lastThree = substr($int, -3);
    $remaining = substr($int, 0, -3);
    if ($remaining !== '' && $remaining !== '-') {
        $lastThree = ',' . $lastThree;
    }
    $result = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining) . $lastThree . '.' . $dec;
    return $result;
}

// FY list — 2010-11 to current+1
function getFYList() {
    $years = [];
    $currentYear = (int) date('Y');
    $startYear   = 2010;
    for ($y = $startYear; $y <= $currentYear + 1; $y++) {
        $years[] = $y . '-' . substr($y + 1, -2);
    }
    return array_reverse($years);
}

// Month list
function getMonthList() {
    return [
        '01' => 'January',  '02' => 'February', '03' => 'March',
        '04' => 'April',    '05' => 'May',       '06' => 'June',
        '07' => 'July',     '08' => 'August',    '09' => 'September',
        '10' => 'October',  '11' => 'November',  '12' => 'December'
    ];
}

// Flash message — set
function setFlash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

// Flash message — get & clear
function getFlash() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// CSRF token generate
function csrfToken() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// CSRF verify
function verifyCsrf($token) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// JSON response — API files me use karo
function jsonResponse($success, $message = '', $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ]);
    exit;
}
