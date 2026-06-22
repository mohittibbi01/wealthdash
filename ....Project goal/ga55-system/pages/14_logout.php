<?php
// ============================================================
// GA-55A SYSTEM — pages/14_logout.php
// Session destroy + redirect to login
// ============================================================
require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
session_destroy();
header('Location: ' . BASE_URL . '/pages/01_login.php');
exit;
