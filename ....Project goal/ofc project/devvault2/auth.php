<?php
require_once __DIR__ . '/config.php';
session_start();

function is_logged_in(): bool {
    return isset($_SESSION['user_id'], $_SESSION['username']);
}

function is_admin(): bool {
    return is_logged_in() && ($_SESSION['role'] ?? '') === 'admin';
}

function require_login(): void {
    if (!is_logged_in()) { header('Location: login.php'); exit; }
}

function require_admin(): void {
    if (!is_admin()) { header('Location: index.php?err=access'); exit; }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verify_csrf(): bool {
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}

// User theme/font preferences
function user_pref(string $key, string $default = ''): string {
    return $_SESSION['prefs'][$key] ?? $default;
}
