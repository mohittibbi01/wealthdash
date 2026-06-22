<?php
// ============================================================
// GA-55A SYSTEM — api/api_02_bills.php
// Bills API: duplicate check, delete
// ============================================================
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
requireLogin();

$action = clean($_GET['action'] ?? '');
$uid    = currentUserId();

// ── Check duplicate ──
if ($action === 'check_duplicate') {
    $billNo = clean($_GET['bill_no'] ?? '');
    $tvNo   = clean($_GET['tv_no']   ?? '');
    $editId = (int)($_GET['edit_id'] ?? 0);

    $exclude = $editId ? "AND id != $editId" : '';
    $stmt = $conn->prepare("SELECT id FROM salary_bills
        WHERE user_id=? AND bill_no=? AND tv_no=? $exclude LIMIT 1");
    $stmt->bind_param('iss', $uid, $billNo, $tvNo);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    jsonResponse(true, '', ['exists' => $exists]);
}

// ── Delete bill ──
if ($action === 'delete') {
    if (!verifyCsrf($_GET['csrf'] ?? '')) {
        setFlash('error', 'Invalid request.');
        header('Location: ../pages/07_bill_list.php');
        exit;
    }
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM salary_bills WHERE id=? AND user_id=?");
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    setFlash($affected ? 'success' : 'error', $affected ? 'Bill delete ho gaya.' : 'Delete nahi hua.');
    header('Location: ../pages/07_bill_list.php');
    exit;
}

jsonResponse(false, 'Invalid action');
