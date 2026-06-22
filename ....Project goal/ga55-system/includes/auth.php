<?php
// ============================================================
// GA-55A SYSTEM — AUTH / SESSION GUARD
// Har protected page ke top pe include karo
// ============================================================

require_once dirname(__DIR__) . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session timeout check
if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_TIMEOUT) {
    session_destroy();
    header('Location: ' . BASE_URL . '/pages/01_login.php?reason=timeout');
    exit;
}
$_SESSION['last_active'] = time();

// Login check
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/pages/01_login.php');
        exit;
    }
}

// Admin check
function requireAdmin() {
    requireLogin();
    if ($_SESSION['user_role'] !== 'admin') {
        header('Location: ' . BASE_URL . '/pages/02_dashboard.php?error=access_denied');
        exit;
    }
}

// Current user info helpers
function currentUserId()   { return $_SESSION['user_id']   ?? null; }
function currentUserName() { return $_SESSION['user_name'] ?? ''; }
function currentUserRole() { return $_SESSION['user_role'] ?? ''; }
function isAdmin()         { return ($_SESSION['user_role'] ?? '') === 'admin'; }
