<?php
/**
 * WealthDash — t466: Real Estate Holdings API
 * CRUD for real_estate table
 * Actions: list, add, update, delete, summary
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
// Normalize action: 'realestate_add' -> 'add', 'realestate_list' -> 'list', etc.
if (str_starts_with($action, 'realestate_')) {
    $action = substr($action, strlen('realestate_'));
}
$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin ?? false)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

// ─── LIST ──────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $rows = DB::fetchAll(
        "SELECT id, property_name, property_type, city, state,
                purchase_date, purchase_price, current_value, last_valued_date,
                is_self_occupied, monthly_rental, annual_expenses,
                outstanding_loan, ownership_pct, notes, is_active,
                ROUND(current_value - purchase_price, 2) AS gain_loss,
                ROUND(current_value * ownership_pct / 100, 2) AS your_share_value
         FROM real_estate
         WHERE portfolio_id = ? AND is_active = 1
         ORDER BY current_value DESC",
        [$portfolioId]
    );

    $summary = DB::fetchRow(
        "SELECT COUNT(*) AS count,
                COALESCE(SUM(purchase_price), 0) AS total_invested,
                COALESCE(SUM(current_value * ownership_pct / 100), 0) AS total_current,
                COALESCE(SUM(outstanding_loan), 0) AS total_outstanding_loan,
                COALESCE(SUM(monthly_rental * 12), 0) AS annual_rental_income
         FROM real_estate WHERE portfolio_id = ? AND is_active = 1",
        [$portfolioId]
    );

    json_response(true, 'Real estate loaded.', [
        'properties' => $rows,
        'summary'    => [
            'count'                => (int)$summary['count'],
            'total_invested'       => round((float)$summary['total_invested'], 2),
            'total_current_value'  => round((float)$summary['total_current'], 2),
            'total_gain_loss'      => round((float)$summary['total_current'] - (float)$summary['total_invested'], 2),
            'total_outstanding_loan'=> round((float)$summary['total_outstanding_loan'], 2),
            'annual_rental_income' => round((float)$summary['annual_rental_income'], 2),
            'net_equity'           => round((float)$summary['total_current'] - (float)$summary['total_outstanding_loan'], 2),
        ]
    ]);
}

// ─── ADD ───────────────────────────────────────────────────────────────────
elseif ($action === 'add') {
    $name         = trim($_POST['property_name'] ?? '');
    $type         = $_POST['property_type'] ?? 'residential';
    $address      = trim($_POST['address'] ?? '');
    $city         = trim($_POST['city'] ?? '');
    $state        = trim($_POST['state'] ?? '');
    $purchaseDate = $_POST['purchase_date'] ?? '';
    $purchasePrice= (float)($_POST['purchase_price'] ?? 0);
    $currentVal   = (float)($_POST['current_value'] ?? 0);
    $lastValDate  = $_POST['last_valued_date'] ?: null;
    $selfOccupied = (int)($_POST['is_self_occupied'] ?? 0);
    $rental       = $_POST['monthly_rental'] !== '' ? (float)$_POST['monthly_rental'] : null;
    $annualExp    = $_POST['annual_expenses'] !== '' ? (float)$_POST['annual_expenses'] : null;
    $outstandingLoan = $_POST['outstanding_loan'] !== '' ? (float)$_POST['outstanding_loan'] : null;
    $ownershipPct = (float)($_POST['ownership_pct'] ?? 100);
    $notes        = trim($_POST['notes'] ?? '');

    if (!$name || !$purchaseDate || $purchasePrice <= 0 || $currentVal <= 0) {
        json_response(false, 'Required: property_name, purchase_date, purchase_price, current_value.');
    }

    $validTypes = ['residential','commercial','plot','agricultural','other'];
    if (!in_array($type, $validTypes)) $type = 'residential';
    if ($ownershipPct <= 0 || $ownershipPct > 100) $ownershipPct = 100;

    DB::execute(
        "INSERT INTO real_estate
            (portfolio_id, property_name, property_type, address, city, state,
             purchase_date, purchase_price, current_value, last_valued_date,
             is_self_occupied, monthly_rental, annual_expenses,
             outstanding_loan, ownership_pct, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$portfolioId, $name, $type, $address, $city, $state,
         $purchaseDate, $purchasePrice, $currentVal, $lastValDate,
         $selfOccupied, $rental, $annualExp, $outstandingLoan, $ownershipPct, $notes]
    );
    $newId = $db->lastInsertId();
    json_response(true, 'Property added.', ['id' => (int)$newId]);
}

// ─── UPDATE ────────────────────────────────────────────────────────────────
elseif ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'id required.');

    // Verify ownership
    $row = DB::fetchRow("SELECT id FROM real_estate WHERE id = ? AND portfolio_id = ?", [$id, $portfolioId]);
    if (!$row) json_response(false, 'Property not found.');

    $fields = [];
    $params = [];
    $allowed = [
        'property_name','property_type','address','city','state',
        'purchase_date','purchase_price','current_value','last_valued_date',
        'is_self_occupied','monthly_rental','annual_expenses',
        'outstanding_loan','ownership_pct','notes'
    ];
    foreach ($allowed as $f) {
        if (isset($_POST[$f])) {
            $fields[] = "`$f` = ?";
            $params[] = $_POST[$f] === '' && in_array($f, ['monthly_rental','annual_expenses','outstanding_loan','last_valued_date']) ? null : $_POST[$f];
        }
    }
    if (empty($fields)) json_response(false, 'Nothing to update.');

    $params[] = $id;
    DB::execute("UPDATE real_estate SET " . implode(', ', $fields) . " WHERE id = ?", $params);
    json_response(true, 'Property updated.');
}

// ─── DELETE ────────────────────────────────────────────────────────────────
elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json_response(false, 'id required.');

    $row = DB::fetchRow("SELECT id FROM real_estate WHERE id = ? AND portfolio_id = ?", [$id, $portfolioId]);
    if (!$row) json_response(false, 'Property not found.');

    DB::execute("UPDATE real_estate SET is_active = 0 WHERE id = ?", [$id]);
    json_response(true, 'Property removed.');
}

// ─── SUMMARY (for net worth rollup) ────────────────────────────────────────
elseif ($action === 'summary') {
    $row = DB::fetchRow(
        "SELECT
            COUNT(*) AS property_count,
            COALESCE(SUM(purchase_price), 0) AS total_invested,
            COALESCE(SUM(current_value * ownership_pct / 100), 0) AS total_current_value,
            COALESCE(SUM(CASE WHEN outstanding_loan IS NOT NULL THEN outstanding_loan ELSE 0 END), 0) AS total_outstanding_loan,
            COALESCE(SUM(CASE WHEN monthly_rental IS NOT NULL THEN monthly_rental * 12 ELSE 0 END), 0) AS annual_rental_income,
            COALESCE(SUM(CASE WHEN is_self_occupied = 1 THEN current_value * ownership_pct / 100 ELSE 0 END), 0) AS self_occupied_value,
            COALESCE(SUM(CASE WHEN is_self_occupied = 0 THEN current_value * ownership_pct / 100 ELSE 0 END), 0) AS investment_value
         FROM real_estate WHERE portfolio_id = ? AND is_active = 1",
        [$portfolioId]
    );
    json_response(true, 'Summary.', $row);
}

else {
    json_response(false, 'Unknown action.');
}
