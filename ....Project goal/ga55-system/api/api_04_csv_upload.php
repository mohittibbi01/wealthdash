<?php
// ============================================================
// GA-55A SYSTEM — api/api_04_csv_upload.php
// CSV template download
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$action = clean($_GET['action'] ?? '');

if ($action === 'template') {
    $cols = $conn->query("SELECT col_key, label FROM salary_columns WHERE is_active=1 ORDER BY col_type DESC, sort_order")->fetch_all(MYSQLI_ASSOC);

    $headers = ['bill_no','bill_date','tv_no','tv_date','fy','month_no','remark'];
    foreach ($cols as $c) $headers[] = $c['col_key'];

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ga55_import_template.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, $headers);

    // Sample row
    $sample = ['1001','25-06-2026','5001','25-06-2026','2025-26','06',''];
    foreach ($cols as $c) $sample[] = '0.00';
    fputcsv($out, $sample);
    fclose($out);
    exit;
}

jsonResponse(false, 'Invalid action');
