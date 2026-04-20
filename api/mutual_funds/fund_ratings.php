<?php
/**
 * WealthDash — Fund Ratings API
 * Task: tv01 — WealthDash Internal Star Rating (1-5 ⭐)
 * Actions: fund_ratings_get | fund_ratings_list | fund_ratings_recalc
 *          fund_health_score | fund_health_top
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'fund_ratings_list';
$db          = DB::conn();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS fund_ratings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fund_id INT UNSIGNED NOT NULL UNIQUE,
            rating_stars TINYINT(1) DEFAULT NULL,
            return_score DECIMAL(5,2) DEFAULT NULL,
            consistency_score DECIMAL(5,2) DEFAULT NULL,
            risk_score DECIMAL(5,2) DEFAULT NULL,
            cost_score DECIMAL(5,2) DEFAULT NULL,
            manager_score DECIMAL(5,2) DEFAULT NULL,
            total_score DECIMAL(5,2) DEFAULT NULL,
            calc_date DATE NOT NULL,
            INDEX idx_stars (rating_stars), INDEX idx_score (total_score)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

switch ($action) {

case 'fund_ratings_get':
    $fundId = (int)($_GET['fund_id'] ?? 0);
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }
    $stmt = $db->prepare("SELECT fr.*, f.fund_name, f.category, f.returns_1y, f.category_avg_1y FROM fund_ratings fr JOIN funds f ON f.id=fr.fund_id WHERE fr.fund_id=?");
    $stmt->execute([$fundId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $data ?: null]);
    break;

case 'fund_ratings_list':
    $minStars = (int)($_GET['min_stars'] ?? 0);
    $category = $_GET['category'] ?? null;
    $limit    = min(200, (int)($_GET['limit'] ?? 50));
    $where = ["fr.rating_stars IS NOT NULL"];
    $params = [];
    if ($minStars) { $where[] = "fr.rating_stars >= ?"; $params[] = $minStars; }
    if ($category) { $where[] = "f.category = ?"; $params[] = $category; }
    $stmt = $db->prepare("
        SELECT fr.fund_id, fr.rating_stars, fr.total_score, fr.return_score,
               fr.consistency_score, fr.risk_score, fr.cost_score, fr.calc_date,
               f.fund_name, f.fund_house, f.category, f.returns_1y, f.returns_3y,
               f.expense_ratio, f.sharpe_ratio, f.aum
        FROM fund_ratings fr JOIN funds f ON f.id=fr.fund_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY fr.total_score DESC LIMIT $limit
    ");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case 'fund_ratings_recalc':
    // Admin only
    if (($currentUser['role'] ?? 'user') !== 'admin') {
        echo json_encode(['success' => false, 'msg' => 'Admin only']); break;
    }
    @exec('php ' . __DIR__ . '/../../cron/calculate_returns.php > /dev/null 2>&1 &');
    echo json_encode(['success' => true, 'msg' => 'Recalculation started in background']);
    break;

case 'fund_health_score':
    $fundId = (int)($_GET['fund_id'] ?? 0);
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }
    $stmt = $db->prepare("SELECT health_score, rating_stars, fund_name FROM funds WHERE id=?");
    $stmt->execute([$fundId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    break;

case 'fund_health_top':
    $limit = min(50, (int)($_GET['limit'] ?? 10));
    $category = $_GET['category'] ?? null;
    $where = ["f.health_score IS NOT NULL", "f.is_active = 1"];
    $params = [];
    if ($category) { $where[] = "f.category LIKE ?"; $params[] = "%$category%"; }
    $stmt = $db->prepare("
        SELECT f.id, f.fund_name, f.fund_house, f.category, f.health_score, f.rating_stars,
               f.returns_1y, f.returns_3y, f.sharpe_ratio, f.expense_ratio
        FROM funds f WHERE " . implode(' AND ', $where) . "
        ORDER BY f.health_score DESC LIMIT $limit
    ");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

default:
    echo json_encode(['success' => false, 'msg' => "Unknown action: $action"]);
}
