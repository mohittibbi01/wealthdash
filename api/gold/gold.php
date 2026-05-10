<?php
/**
 * WealthDash — t465: Physical Gold & Jewelry API
 * CRUD for physical_gold table
 * Actions: list, add, update, delete, summary, update_rate
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
// Normalize: 'gold_add' -> 'add', etc.
if (str_starts_with($action, 'gold_')) {
    $action = substr($action, strlen('gold_'));
}
$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin ?? false)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

// Helper: compute current value from weight and rate
function computeGoldValue(float $weightGrams, float $purityKarat, float $ratePerGram24K): float {
    // rate given is for 24K; adjust for purity
    $purityFactor = $purityKarat / 24.0;
    return round($weightGrams * $purityFactor * $ratePerGram24K, 2);
}

// ─── LIST ──────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $rows = DB::fetchAll(
        "SELECT id, description, gold_type, purity_karat, weight_grams,
                purchase_date, purchase_price, purchase_rate, current_rate,
                current_value, storage, is_insured, notes, is_active,
                ROUND(COALESCE(current_value, 0) - COALESCE(purchase_price, 0), 2) AS gain_loss
         FROM physical_gold
         WHERE portfolio_id = ? AND is_active = 1
         ORDER BY current_value DESC",
        [$portfolioId]
    );

    $summary = DB::fetchRow(
        "SELECT COUNT(*) AS count,
                COALESCE(SUM(weight_grams), 0) AS total_weight_grams,
                COALESCE(SUM(purchase_price), 0) AS total_invested,
                COALESCE(SUM(current_value), 0) AS total_current_value
         FROM physical_gold WHERE portfolio_id = ? AND is_active = 1",
        [$portfolioId]
    );

    json_response(true, 'Gold holdings loaded.', [
        'holdings' => $rows,
        'summary'  => [
            'count'               => (int)$summary['count'],
            'total_weight_grams'  => round((float)$summary['total_weight_grams'], 3),
            'total_invested'      => round((float)$summary['total_invested'], 2),
            'total_current_value' => round((float)$summary['total_current_value'], 2),
            'total_gain_loss'     => round((float)$summary['total_current_value'] - (float)$summary['total_invested'], 2),
        ]
    ]);
}

// ─── ADD ───────────────────────────────────────────────────────────────────
elseif ($action === 'add') {
    $description  = trim($_POST['description'] ?? '');
    $goldType     = $_POST['gold_type'] ?? 'jewellery';
    $purityKarat  = (int)($_POST['purity_karat'] ?? 22);
    $weightGrams  = (float)($_POST['weight_grams'] ?? 0);
    $purchaseDate = $_POST['purchase_date'] ?? null;
    $purchasePrice= isset($_POST['purchase_price']) && $_POST['purchase_price'] !== '' ? (float)$_POST['purchase_price'] : null;
    $purchaseRate = isset($_POST['purchase_rate']) && $_POST['purchase_rate'] !== '' ? (float)$_POST['purchase_rate'] : null;
    $currentRate  = isset($_POST['current_rate']) && $_POST['current_rate'] !== '' ? (float)$_POST['current_rate'] : null;
    $storage      = $_POST['storage'] ?? 'home';
    $isInsured    = (int)($_POST['is_insured'] ?? 0);
    $notes        = trim($_POST['notes'] ?? '');

    if (!$description || $weightGrams <= 0) {
        json_response(false, 'Required: description, weight_grams.');
    }

    $validTypes = ['jewellery','coin','bar','biscuit','other'];
    if (!in_array($goldType, $validTypes)) $goldType = 'jewellery';
    if (!in_array($purityKarat, [14, 18, 22, 24])) $purityKarat = 22;

    // Compute current_value if we have current_rate
    $currentValue = null;
    if ($currentRate !== null && $currentRate > 0) {
        $currentValue = computeGoldValue($weightGrams, $purityKarat, $currentRate);
    } elseif ($purchaseRate !== null && $purchaseRate > 0) {
        $currentValue = computeGoldValue($weightGrams, $purityKarat, $purchaseRate);
    } elseif ($purchasePrice !== null) {
        $currentValue = $purchasePrice;
    }

    DB::execute(
        "INSERT INTO physical_gold
            (portfolio_id, description, gold_type, purity_karat, weight_grams,
             purchase_date, purchase_price, purchase_rate, current_rate,
             current_value, storage, is_insured, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$portfolioId, $description, $goldType, $purityKarat, $weightGrams,
         $purchaseDate, $purchasePrice, $purchaseRate, $currentRate,
         $currentValue, $storage, $isInsured, $notes]
    );
    $newId = $db->lastInsertId();
    json_response(true, 'Gold holding added.', ['id' => (int)$newId]);
}

// ─── UPDATE ────────────────────────────────────────────────────────────────
elseif ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'id required.');

    $row = DB::fetchRow("SELECT id, weight_grams, purity_karat FROM physical_gold WHERE id = ? AND portfolio_id = ?", [$id, $portfolioId]);
    if (!$row) json_response(false, 'Holding not found.');

    $fields = [];
    $params = [];
    $allowed = ['description','gold_type','purity_karat','weight_grams','purchase_date',
                'purchase_price','purchase_rate','current_rate','current_value','storage','is_insured','notes'];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $_POST)) {
            $fields[] = "`$f` = ?";
            $params[] = $_POST[$f] === '' && in_array($f, ['purchase_date','purchase_price','purchase_rate','current_rate','current_value']) ? null : $_POST[$f];
        }
    }

    // Recompute current_value if rate or weight changed
    $newWeight  = isset($_POST['weight_grams'])  ? (float)$_POST['weight_grams']  : (float)$row['weight_grams'];
    $newPurity  = isset($_POST['purity_karat'])  ? (int)$_POST['purity_karat']    : (int)$row['purity_karat'];
    $newRate    = isset($_POST['current_rate'])  && $_POST['current_rate'] !== '' ? (float)$_POST['current_rate'] : null;

    if ($newRate !== null && $newRate > 0 && !isset($_POST['current_value'])) {
        $fields[] = '`current_value` = ?';
        $params[] = computeGoldValue($newWeight, $newPurity, $newRate);
    }

    if (empty($fields)) json_response(false, 'Nothing to update.');

    $params[] = $id;
    DB::execute("UPDATE physical_gold SET " . implode(', ', $fields) . " WHERE id = ?", $params);
    json_response(true, 'Gold holding updated.');
}

// ─── UPDATE_RATE (bulk rate update — e.g. today's gold price) ─────────────
elseif ($action === 'update_rate') {
    $ratePerGram24K = (float)($_POST['rate_24k'] ?? 0);
    if ($ratePerGram24K <= 0) json_response(false, 'rate_24k required and must be > 0.');

    $holdings = DB::fetchAll(
        "SELECT id, weight_grams, purity_karat FROM physical_gold WHERE portfolio_id = ? AND is_active = 1",
        [$portfolioId]
    );
    $updated = 0;
    foreach ($holdings as $h) {
        $newVal = computeGoldValue((float)$h['weight_grams'], (float)$h['purity_karat'], $ratePerGram24K);
        DB::execute(
            "UPDATE physical_gold SET current_rate = ?, current_value = ? WHERE id = ?",
            [$ratePerGram24K, $newVal, $h['id']]
        );
        $updated++;
    }
    json_response(true, "Updated $updated gold holdings with new rate.", ['updated' => $updated, 'rate_24k' => $ratePerGram24K]);
}

// ─── DELETE ────────────────────────────────────────────────────────────────
elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json_response(false, 'id required.');

    $row = DB::fetchRow("SELECT id FROM physical_gold WHERE id = ? AND portfolio_id = ?", [$id, $portfolioId]);
    if (!$row) json_response(false, 'Holding not found.');

    DB::execute("UPDATE physical_gold SET is_active = 0 WHERE id = ?", [$id]);
    json_response(true, 'Gold holding removed.');
}

// ─── SUMMARY ───────────────────────────────────────────────────────────────
elseif ($action === 'summary') {
    $row = DB::fetchRow(
        "SELECT COUNT(*) AS count,
                COALESCE(SUM(weight_grams), 0) AS total_weight_grams,
                COALESCE(SUM(purchase_price), 0) AS total_invested,
                COALESCE(SUM(current_value), 0) AS total_current_value,
                COALESCE(AVG(current_rate), 0) AS avg_rate_used
         FROM physical_gold WHERE portfolio_id = ? AND is_active = 1",
        [$portfolioId]
    );
    json_response(true, 'Gold summary.', $row);
}

else {
    json_response(false, 'Unknown action.');
}
