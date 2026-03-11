<?php
/**
 * WealthDash — MF Fund Search (Autocomplete)
 * GET /api/mutual_funds/mf_search.php?q=hdfc&limit=10
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json');
$currentUser = require_auth();

$q     = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 15), 50);

if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $db = DB::conn();

    $search = '%' . $q . '%';
    $stmt = $db->prepare("
        SELECT f.id, f.scheme_code, f.scheme_name, f.category, f.sub_category,
               f.fund_type, f.option_type, f.latest_nav, f.latest_nav_date,
               fh.name AS fund_house_name, fh.short_name AS fund_house_short
        FROM funds f
        JOIN fund_houses fh ON fh.id = f.fund_house_id
        WHERE f.scheme_name LIKE :q
           OR f.scheme_code LIKE :q2
           OR fh.name LIKE :q3
        ORDER BY
            CASE WHEN f.scheme_name LIKE :q4 THEN 0 ELSE 1 END,
            f.scheme_name
        LIMIT :lim
    ");
    $stmt->bindValue(':q',   $search);
    $stmt->bindValue(':q2',  $search);
    $stmt->bindValue(':q3',  $search);
    $stmt->bindValue(':q4',  $q . '%');
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $funds = $stmt->fetchAll();

    $result = array_map(function($f) {
        return [
            'id'               => (int)$f['id'],
            'scheme_code'      => $f['scheme_code'],
            'scheme_name'      => $f['scheme_name'],
            'category'         => $f['category'],
            'sub_category'     => $f['sub_category'],
            'fund_type'        => $f['fund_type'],
            'option_type'      => $f['option_type'],
            'latest_nav'       => $f['latest_nav'] ? (float)$f['latest_nav'] : null,
            'latest_nav_date'  => $f['latest_nav_date'],
            'fund_house'       => $f['fund_house_name'],
            'fund_house_short' => $f['fund_house_short'],
            'label'            => $f['scheme_name'] . ' (' . $f['scheme_code'] . ')',
        ];
    }, $funds);

    echo json_encode(['success' => true, 'data' => $result]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Search failed: ' . $e->getMessage()]);
}

