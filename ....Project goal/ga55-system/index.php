<?php
// ============================================================
// GA-55A SYSTEM — index.php
// Entry point — login page pe redirect karta hai
// ============================================================
require_once 'config.php';
header('Location: ' . BASE_URL . '/pages/01_login.php');
exit;
