<?php
// ============================================================
// GA-55A SYSTEM — DATABASE CONNECTION
// Yeh file sirf ek baar likhni hai, sab jagah include karein
// ============================================================

require_once dirname(__DIR__) . '/config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $conn->connect_error
    ]));
}

$conn->set_charset(DB_CHARSET);
