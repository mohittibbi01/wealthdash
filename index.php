<?php
/**
 * WealthDash — Entry Point
 * Redirects to login or dashboard
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

if (!is_logged_in()) {
    redirect('auth/login.php');
}

// Redirect to dashboard
redirect('templates/pages/dashboard.php');

