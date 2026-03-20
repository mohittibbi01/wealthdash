<?php
/**
 * WealthDash — Admin: Fund Rules API
 * Search funds + get/set min_ltcg_days & lock_in_days
 *
 * Actions (all admin-only):
 *   GET  admin_fund_rules_search     ?q=...&limit=20&category=...
 *   POST admin_fund_rules_update     {fund_id, min_ltcg_days, lock_in_days}
 *   GET  admin_fund_rules_get        ?fund_id=...
 *   GET  admin_fund_rules_categories — list all distinct categories with fund counts
 *   POST admin_fund_rules_bulk_update {fund_ids[], min_ltcg_days, lock_in_days}
 *                                  OR {category_filter, min_ltcg_days, lock_in_days}
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$db = DB::conn();

// ── LIST CATEGORIES ───────────────────────────────────────────────────────
if ($action === 'admin_fund_rules_categories') {
    $stmt = $db->query("
        SELECT
            category,
            COUNT(*)                                           AS fund_count,
            SUM(CASE WHEN lock_in_days > 0 THEN 1 ELSE 0 END) AS lockin_count,
            MIN(min_ltcg_days)  AS ltcg_days_min,
            MAX(min_ltcg_days)  AS ltcg_days_max,
            MIN(lock_in_days)   AS lock_days_min,
            MAX(lock_in_days)   AS lock_days_max
        FROM funds
        WHERE is_active = 1
          AND category IS NOT NULL AND category != ''
        GROUP BY category
        ORDER BY fund_count DESC
    ");
    $rows = $stmt->fetchAll();

    $categories = array_map(fn($r) => [
        'category'      => $r['category'],
        'fund_count'    => (int)$r['fund_count'],
        'lockin_count'  => (int)$r['lockin_count'],
        'ltcg_days_min' => (int)$r['ltcg_days_min'],
        'ltcg_days_max' => (int)$r['ltcg_days_max'],
        'lock_days_min' => (int)$r['lock_days_min'],
        'lock_days_max' => (int)$r['lock_days_max'],
        'is_uniform'    => $r['ltcg_days_min'] === $r['ltcg_days_max']
                        && $r['lock_days_min'] === $r['lock_days_max'],
    ], $rows);

    json_response(true, 'ok', ['categories' => $categories]);
}

// ── SEARCH ────────────────────────────────────────────────────────────────
if ($action === 'admin_fund_rules_search') {
    $q        = trim($_GET['q'] ?? '');
    $limit    = min((int)($_GET['limit'] ?? 20), 500);
    $category = trim($_GET['category'] ?? '');

    if ($category !== '' && $q === '') {
        $stmt = $db->prepare("
            SELECT f.id, f.scheme_code, f.scheme_name, f.category, f.sub_category,
                   f.min_ltcg_days, f.lock_in_days, f.is_active,
                   fh.short_name AS fund_house
            FROM funds f
            JOIN fund_houses fh ON fh.id = f.fund_house_id
            WHERE f.category = ? AND f.is_active = 1
            ORDER BY f.scheme_name
            LIMIT ?
        ");
        $stmt->bindValue(1, $category);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        json_response(true, 'ok', ['funds' => _map_funds($rows), 'total' => count($rows)]);
    }

    if (strlen($q) < 2 && $category === '') {
        json_response(true, 'ok', ['funds' => [], 'total' => 0]);
    }

    $search   = '%' . $q . '%';
    $catWhere = $category !== '' ? 'AND f.category = :cat' : '';

    $stmt = $db->prepare("
        SELECT f.id, f.scheme_code, f.scheme_name, f.category, f.sub_category,
               f.min_ltcg_days, f.lock_in_days, f.is_active,
               fh.short_name AS fund_house
        FROM funds f
        JOIN fund_houses fh ON fh.id = f.fund_house_id
        WHERE (f.scheme_name LIKE :q1 OR f.scheme_code LIKE :q2 OR fh.name LIKE :q3)
          $catWhere
        ORDER BY
            CASE WHEN f.scheme_name LIKE :q4 THEN 0 ELSE 1 END,
            f.scheme_name
        LIMIT :lim
    ");
    $stmt->bindValue(':q1', $search);
    $stmt->bindValue(':q2', $search);
    $stmt->bindValue(':q3', $search);
    $stmt->bindValue(':q4', $q . '%');
    if ($category !== '') $stmt->bindValue(':cat', $category);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    json_response(true, 'ok', ['funds' => _map_funds($rows), 'total' => count($rows)]);
}

// ── GET single fund ───────────────────────────────────────────────────────
if ($action === 'admin_fund_rules_get') {
    $fundId = (int)($_GET['fund_id'] ?? 0);
    if (!$fundId) json_response(false, 'fund_id required');
    $row = DB::fetchOne("
        SELECT f.id, f.scheme_code, f.scheme_name, f.category,
               f.min_ltcg_days, f.lock_in_days, fh.short_name AS fund_house
        FROM funds f JOIN fund_houses fh ON fh.id = f.fund_house_id
        WHERE f.id = ?
    ", [$fundId]);
    if (!$row) json_response(false, 'Fund not found');
    json_response(true, 'ok', ['fund' => $row]);
}

// ── SINGLE UPDATE ─────────────────────────────────────────────────────────
if ($action === 'admin_fund_rules_update') {
    $fundId      = (int)($_POST['fund_id']       ?? 0);
    $minLtcgDays = (int)($_POST['min_ltcg_days'] ?? 0);
    $lockInDays  = (int)($_POST['lock_in_days']  ?? 0);

    if (!$fundId)         json_response(false, 'fund_id required');
    if ($minLtcgDays < 1) json_response(false, 'min_ltcg_days must be >= 1');
    if ($lockInDays  < 0) json_response(false, 'lock_in_days cannot be negative');

    $stmt = $db->prepare("UPDATE funds SET min_ltcg_days = ?, lock_in_days = ? WHERE id = ?");
    $stmt->execute([$minLtcgDays, $lockInDays, $fundId]);

    if ($stmt->rowCount() === 0) {
        $exists = DB::fetchOne("SELECT id FROM funds WHERE id = ?", [$fundId]);
        if (!$exists) json_response(false, 'Fund not found');
    }

    $fund = DB::fetchOne("SELECT scheme_name FROM funds WHERE id = ?", [$fundId]);
    json_response(true, "Rules updated for: " . ($fund['scheme_name'] ?? "Fund #{$fundId}"), [
        'fund_id' => $fundId, 'min_ltcg_days' => $minLtcgDays, 'lock_in_days' => $lockInDays,
    ]);
}

// ── BULK UPDATE ───────────────────────────────────────────────────────────
if ($action === 'admin_fund_rules_bulk_update') {
    $minLtcgDays    = (int)($_POST['min_ltcg_days']  ?? 0);
    $lockInDays     = (int)($_POST['lock_in_days']   ?? 0);
    $categoryFilter = trim($_POST['category_filter'] ?? '');
    $fundIdsRaw     = $_POST['fund_ids']             ?? [];

    if ($minLtcgDays < 1) json_response(false, 'min_ltcg_days must be >= 1');
    if ($lockInDays  < 0) json_response(false, 'lock_in_days cannot be negative');

    // Mode A: entire category
    if ($categoryFilter !== '' && empty($fundIdsRaw)) {
        $stmt = $db->prepare("UPDATE funds SET min_ltcg_days = ?, lock_in_days = ? WHERE category = ?");
        $stmt->execute([$minLtcgDays, $lockInDays, $categoryFilter]);
        $n = $stmt->rowCount();
        json_response(true, "Updated {$n} funds in category.", [
            'updated_count' => $n, 'min_ltcg_days' => $minLtcgDays, 'lock_in_days' => $lockInDays,
        ]);
    }

    // Mode B: specific fund IDs
    if (!empty($fundIdsRaw)) {
        $fundIds = array_values(array_filter(array_map('intval', (array)$fundIdsRaw)));
        if (empty($fundIds))       json_response(false, 'No valid fund IDs');
        if (count($fundIds) > 500) json_response(false, 'Max 500 funds per request');

        $placeholders = implode(',', array_fill(0, count($fundIds), '?'));
        $params       = array_merge([$minLtcgDays, $lockInDays], $fundIds);
        $stmt = $db->prepare("UPDATE funds SET min_ltcg_days = ?, lock_in_days = ? WHERE id IN ($placeholders)");
        $stmt->execute($params);
        $n = $stmt->rowCount();
        json_response(true, "Updated {$n} funds.", [
            'updated_count' => $n, 'min_ltcg_days' => $minLtcgDays, 'lock_in_days' => $lockInDays,
        ]);
    }

    json_response(false, 'Provide category_filter or fund_ids');
}

function _map_funds(array $rows): array {
    return array_map(fn($r) => [
        'id'            => (int)$r['id'],
        'scheme_code'   => $r['scheme_code'],
        'scheme_name'   => $r['scheme_name'],
        'fund_house'    => $r['fund_house'],
        'category'      => $r['category'],
        'sub_category'  => $r['sub_category'],
        'min_ltcg_days' => (int)$r['min_ltcg_days'],
        'lock_in_days'  => (int)$r['lock_in_days'],
        'is_active'     => (bool)$r['is_active'],
    ], $rows);
}

json_response(false, 'Unknown action', [], 400);