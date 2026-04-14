<?php
/**
 * WealthDash — Fund Ratings API (tv01 + t362)
 *
 * tv01: WealthDash Internal Star Rating (1–5 ⭐)
 *       Formula: Returns(30%) + Consistency(25%) + Risk(20%) + Cost(15%) + Manager Tenure(10%)
 *
 * t362: Fund Health Score (1–100)
 *       Composite: Returns(30%) + Consistency(25%) + Risk(20%) + Cost(15%) + Tenure(10%)
 *       Score > 70 = Good, > 85 = Excellent
 *
 * Actions:
 *   GET  ?action=fund_ratings_get&fund_id=X      → single fund rating + breakdown
 *   GET  ?action=fund_ratings_list&min_stars=4   → all rated funds (paginated)
 *   POST action=fund_ratings_recalc              → recalc + persist (admin/cron)
 *   GET  ?action=fund_health_score&fund_id=X     → health score 1-100 + explanation
 *   GET  ?action=fund_health_top                 → top-scored funds
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// ── Ensure fund_ratings table + denorm columns exist ─────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS `fund_ratings` (
        `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `fund_id`          INT UNSIGNED NOT NULL,
        `stars`            TINYINT UNSIGNED NOT NULL COMMENT '1–5 star rating',
        `health_score`     TINYINT UNSIGNED DEFAULT NULL COMMENT '1–100 composite health score',
        `score_total`      DECIMAL(7,4) DEFAULT NULL COMMENT 'Raw weighted score before rounding',
        `score_breakdown`  JSON DEFAULT NULL COMMENT '{returns, consistency, risk, cost, tenure}',
        `health_breakdown` JSON DEFAULT NULL COMMENT '{returns_30, consistency_25, risk_20, cost_15, tenure_10}',
        `rated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_fr_fund` (`fund_id`),
        KEY `idx_fr_stars`  (`stars`),
        KEY `idx_fr_health` (`health_score`),
        KEY `idx_fr_rated`  (`rated_at`),
        CONSTRAINT `fk_fr_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Denorm columns for fast screener sort
try { $db->exec("ALTER TABLE `funds` ADD COLUMN IF NOT EXISTS `wd_stars`      TINYINT UNSIGNED DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD COLUMN IF NOT EXISTS `health_score`  TINYINT UNSIGNED DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD INDEX IF NOT EXISTS `idx_funds_wd_stars`    (`wd_stars`)"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD INDEX IF NOT EXISTS `idx_funds_health_score`(`health_score`)"); } catch(Exception $e) {}

// ── Rating engine ─────────────────────────────────────────────────────────
/**
 * Calculate star rating + health score for a single fund row.
 * @param array $f Fund row with: returns_1y, returns_3y, returns_5y,
 *                 category_avg_1y, category_avg_3y, expense_ratio,
 *                 sharpe_ratio, max_drawdown, inception_date,
 *                 manager_since, scheme_name
 * @return array ['stars'=>int, 'health_score'=>int, 'breakdown'=>array, 'health_breakdown'=>array]
 */
function calculate_fund_rating(array $f): array {

    // ── Component 1: Returns (30 pts) ──────────────────────────────────
    // Compare to category average; bonus for beating category
    $ret1y = $f['returns_1y'] !== null ? (float)$f['returns_1y'] : null;
    $ret3y = $f['returns_3y'] !== null ? (float)$f['returns_3y'] : null;
    $ret5y = $f['returns_5y'] !== null ? (float)$f['returns_5y'] : null;
    $cat1y = $f['category_avg_1y'] !== null ? (float)$f['category_avg_1y'] : null;
    $cat3y = $f['category_avg_3y'] !== null ? (float)$f['category_avg_3y'] : null;

    $retScore = 0; // 0–30
    $bestRet  = $ret3y ?? $ret1y ?? $ret5y;
    $catBench = $cat3y ?? $cat1y;

    if ($bestRet !== null) {
        // Absolute return score (0–20)
        if ($bestRet >= 20)       $retScore += 20;
        elseif ($bestRet >= 15)   $retScore += 16;
        elseif ($bestRet >= 10)   $retScore += 12;
        elseif ($bestRet >= 5)    $retScore +=  7;
        elseif ($bestRet >= 0)    $retScore +=  3;
        // else: negative → 0

        // Relative to category (0–10)
        if ($catBench !== null) {
            $alpha = $bestRet - $catBench;
            if ($alpha >= 5)       $retScore += 10;
            elseif ($alpha >= 2)   $retScore +=  7;
            elseif ($alpha >= 0)   $retScore +=  5;
            elseif ($alpha >= -2)  $retScore +=  3;
            // else: underperforming by > 2% → 0
        } else {
            $retScore += 5; // neutral if no category avg
        }
    } else {
        $retScore = 5; // insufficient data
    }
    $retScore = min(30, max(0, $retScore));

    // ── Component 2: Consistency (25 pts) ──────────────────────────────
    // Presence of multi-period returns signals consistency
    $consScore = 0;
    $periods   = array_filter([$ret1y, $ret3y, $ret5y], fn($v) => $v !== null);
    $periodCnt = count($periods);

    if ($periodCnt >= 3) {
        // All positive → excellent
        $allPositive = count(array_filter($periods, fn($v) => $v > 0)) === $periodCnt;
        $consScore = $allPositive ? 25 : 18;
        // Check improving trend 1Y > 3Y or stable
        if ($ret1y !== null && $ret3y !== null) {
            if (abs($ret1y - $ret3y) < 3) $consScore = min(25, $consScore + 3); // stable
        }
    } elseif ($periodCnt === 2) {
        $allPositive = count(array_filter($periods, fn($v) => $v > 0)) === 2;
        $consScore = $allPositive ? 18 : 12;
    } elseif ($periodCnt === 1) {
        $v = array_values($periods)[0];
        $consScore = $v > 5 ? 12 : ($v > 0 ? 8 : 3);
    } else {
        $consScore = 5; // no data
    }
    $consScore = min(25, max(0, $consScore));

    // ── Component 3: Risk (20 pts) ─────────────────────────────────────
    $sharpe = $f['sharpe_ratio'] !== null ? (float)$f['sharpe_ratio'] : null;
    $mdd    = $f['max_drawdown'] !== null ? (float)$f['max_drawdown'] : null; // negative %

    $riskScore = 0;
    if ($sharpe !== null) {
        if ($sharpe >= 2.0)      $riskScore += 12;
        elseif ($sharpe >= 1.5)  $riskScore += 10;
        elseif ($sharpe >= 1.0)  $riskScore +=  8;
        elseif ($sharpe >= 0.5)  $riskScore +=  5;
        else                     $riskScore +=  2;
    } else {
        $riskScore += 6; // neutral
    }
    if ($mdd !== null) {
        $mddAbs = abs($mdd); // drawdown as positive %
        if ($mddAbs <= 5)       $riskScore +=  8;
        elseif ($mddAbs <= 10)  $riskScore +=  6;
        elseif ($mddAbs <= 20)  $riskScore +=  4;
        elseif ($mddAbs <= 35)  $riskScore +=  2;
        // else > 35% drawdown → 0
    } else {
        $riskScore += 4; // neutral
    }
    $riskScore = min(20, max(0, $riskScore));

    // ── Component 4: Cost (15 pts) ─────────────────────────────────────
    $exp       = $f['expense_ratio'] !== null ? (float)$f['expense_ratio'] : null;
    $isDirect  = stripos($f['scheme_name'] ?? '', 'direct') !== false;

    $costScore = 0;
    if ($exp !== null) {
        // Direct plans get cheaper → higher score naturally
        if ($exp < 0.25)       $costScore = 15;
        elseif ($exp < 0.5)    $costScore = 13;
        elseif ($exp < 0.75)   $costScore = 11;
        elseif ($exp < 1.0)    $costScore =  9;
        elseif ($exp < 1.5)    $costScore =  6;
        elseif ($exp < 2.0)    $costScore =  4;
        else                   $costScore =  2;
    } else {
        // Direct plan bonus even without TER data
        $costScore = $isDirect ? 10 : 7;
    }
    $costScore = min(15, max(0, $costScore));

    // ── Component 5: Manager Tenure (10 pts) ───────────────────────────
    $managerSince = $f['manager_since'] ?? null;
    $inceptionDate= $f['inception_date'] ?? null;

    $tenureScore = 5; // neutral default
    if ($managerSince) {
        $yearsManaging = (int)((time() - strtotime($managerSince)) / (365.25 * 86400));
        if ($yearsManaging >= 10)      $tenureScore = 10;
        elseif ($yearsManaging >= 7)   $tenureScore =  9;
        elseif ($yearsManaging >= 5)   $tenureScore =  7;
        elseif ($yearsManaging >= 3)   $tenureScore =  5;
        elseif ($yearsManaging >= 1)   $tenureScore =  3;
        else                           $tenureScore =  1;
    } elseif ($inceptionDate) {
        // Use fund age as proxy for tenure
        $yearsOld = (int)((time() - strtotime($inceptionDate)) / (365.25 * 86400));
        if ($yearsOld >= 15)      $tenureScore =  8;
        elseif ($yearsOld >= 10)  $tenureScore =  7;
        elseif ($yearsOld >= 5)   $tenureScore =  5;
        elseif ($yearsOld >= 3)   $tenureScore =  3;
        else                      $tenureScore =  2; // new fund
    }
    $tenureScore = min(10, max(0, $tenureScore));

    // ── Health Score (1–100) ───────────────────────────────────────────
    $healthScore = $retScore + $consScore + $riskScore + $costScore + $tenureScore;
    $healthScore = min(100, max(1, (int)round($healthScore)));

    // ── Star Rating (1–5) ──────────────────────────────────────────────
    // Health Score → Stars mapping
    if ($healthScore >= 85)      $stars = 5;
    elseif ($healthScore >= 70)  $stars = 4;
    elseif ($healthScore >= 55)  $stars = 3;
    elseif ($healthScore >= 40)  $stars = 2;
    else                         $stars = 1;

    $breakdown = [
        'returns'     => $retScore,
        'consistency' => $consScore,
        'risk'        => $riskScore,
        'cost'        => $costScore,
        'tenure'      => $tenureScore,
    ];

    $healthBreakdown = [
        'returns_30'      => $retScore,
        'consistency_25'  => $consScore,
        'risk_20'         => $riskScore,
        'cost_15'         => $costScore,
        'tenure_10'       => $tenureScore,
        'total_100'       => $healthScore,
        'label'           => $healthScore >= 85 ? 'Excellent' : ($healthScore >= 70 ? 'Good' : ($healthScore >= 55 ? 'Average' : ($healthScore >= 40 ? 'Below Average' : 'Poor'))),
    ];

    return compact('stars', 'healthScore', 'breakdown', 'healthBreakdown');
}

/**
 * Fetch a fund row with all scoring columns.
 */
function fetch_fund_for_rating(PDO $db, int $fundId): ?array {
    $cols = ['f.id', 'f.scheme_name', 'f.category', 'f.expense_ratio', 'f.scheme_code'];
    // Optional columns guarded by try-catch
    $optionals = ['f.returns_1y', 'f.returns_3y', 'f.returns_5y',
                  'f.category_avg_1y', 'f.category_avg_3y',
                  'f.sharpe_ratio', 'f.max_drawdown',
                  'f.inception_date', 'f.manager_since'];
    $available = [];
    foreach ($optionals as $col) {
        $colName = explode('.', $col)[1];
        try { $db->query("SELECT {$colName} FROM funds LIMIT 1"); $available[] = $col; } catch(Exception $e) {}
    }
    $sql = 'SELECT ' . implode(', ', array_merge($cols, $available)) . ' FROM funds f WHERE f.id = ? AND f.is_active = 1';
    $stmt = $db->prepare($sql);
    $stmt->execute([$fundId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Persist rating to DB ──────────────────────────────────────────────────
function persist_rating(PDO $db, int $fundId, int $stars, int $healthScore, array $breakdown, array $healthBreakdown): void {
    $scoreTotal = array_sum($breakdown);
    $db->prepare("
        INSERT INTO fund_ratings (fund_id, stars, health_score, score_total, score_breakdown, health_breakdown)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            stars=VALUES(stars), health_score=VALUES(health_score),
            score_total=VALUES(score_total), score_breakdown=VALUES(score_breakdown),
            health_breakdown=VALUES(health_breakdown), rated_at=NOW()
    ")->execute([
        $fundId, $stars, $healthScore,
        round($scoreTotal, 4),
        json_encode($breakdown),
        json_encode($healthBreakdown),
    ]);
    // Update denorm columns on funds
    try {
        $db->prepare("UPDATE funds SET wd_stars=?, health_score=? WHERE id=?")
           ->execute([$stars, $healthScore, $fundId]);
    } catch(Exception $e) {}
}

// ── Format rating for API response ───────────────────────────────────────
function format_rating_row(array $r): array {
    return [
        'fund_id'          => (int)$r['fund_id'],
        'scheme_name'      => $r['scheme_name'] ?? '',
        'fund_house'       => $r['fund_house']  ?? '',
        'stars'            => (int)$r['stars'],
        'stars_display'    => str_repeat('⭐', (int)$r['stars']) . str_repeat('☆', 5 - (int)$r['stars']),
        'health_score'     => (int)($r['health_score'] ?? 0),
        'health_label'     => health_label((int)($r['health_score'] ?? 0)),
        'score_breakdown'  => $r['score_breakdown']  ? json_decode($r['score_breakdown'],  true) : null,
        'health_breakdown' => $r['health_breakdown'] ? json_decode($r['health_breakdown'], true) : null,
        'rated_at'         => $r['rated_at'] ?? null,
    ];
}

function health_label(int $score): string {
    if ($score >= 85) return 'Excellent';
    if ($score >= 70) return 'Good';
    if ($score >= 55) return 'Average';
    if ($score >= 40) return 'Below Average';
    return 'Poor';
}

// ── Route ─────────────────────────────────────────────────────────────────
switch ($action) {

    // ── GET single fund rating + breakdown ───────────────────────────────
    case 'fund_ratings_get': {
        $fundId = (int)($_GET['fund_id'] ?? 0);
        if ($fundId <= 0) { json_response(false, 'fund_id required'); }

        // Check persisted rating
        $stmt = $db->prepare("
            SELECT fr.*, f.scheme_name, COALESCE(fh.short_name, fh.name) AS fund_house
            FROM fund_ratings fr
            JOIN funds f ON f.id = fr.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            WHERE fr.fund_id = ?
        ");
        $stmt->execute([$fundId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        // If rated within last 7 days, return cached
        if ($existing && $existing['rated_at'] && strtotime($existing['rated_at']) > strtotime('-7 days')) {
            json_response(true, '', ['rating' => format_rating_row($existing), 'cached' => true]);
        }

        // Recalculate on-the-fly
        $fund = fetch_fund_for_rating($db, $fundId);
        if (!$fund) { json_response(false, 'Fund not found'); }

        $result = calculate_fund_rating($fund);
        persist_rating($db, $fundId, $result['stars'], $result['healthScore'], $result['breakdown'], $result['healthBreakdown']);

        $rating = [
            'fund_id'          => $fundId,
            'scheme_name'      => $fund['scheme_name'],
            'stars'            => $result['stars'],
            'stars_display'    => str_repeat('⭐', $result['stars']) . str_repeat('☆', 5 - $result['stars']),
            'health_score'     => $result['healthScore'],
            'health_label'     => health_label($result['healthScore']),
            'score_breakdown'  => $result['breakdown'],
            'health_breakdown' => $result['healthBreakdown'],
            'rated_at'         => date('Y-m-d H:i:s'),
        ];
        json_response(true, '', ['rating' => $rating, 'cached' => false]);
        break;
    }

    // ── GET list of all rated funds ───────────────────────────────────────
    case 'fund_ratings_list': {
        $minStars    = isset($_GET['min_stars']) ? (int)$_GET['min_stars'] : null;
        $minHealth   = isset($_GET['min_health']) ? (int)$_GET['min_health'] : null;
        $label       = trim($_GET['health_label'] ?? ''); // Excellent, Good, etc.
        $page        = max(1, (int)($_GET['page'] ?? 1));
        $perPage     = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
        $offset      = ($page - 1) * $perPage;
        $sort        = $_GET['sort'] ?? 'health_desc';

        $where  = ['f.is_active = 1'];
        $params = [];

        if ($minStars !== null && $minStars >= 1) {
            $where[] = 'fr.stars >= ?'; $params[] = $minStars;
        }
        if ($minHealth !== null && $minHealth >= 1) {
            $where[] = 'fr.health_score >= ?'; $params[] = $minHealth;
        }
        if ($label !== '') {
            $range = match($label) {
                'Excellent'     => [85, 100],
                'Good'          => [70, 84],
                'Average'       => [55, 69],
                'Below Average' => [40, 54],
                'Poor'          => [1, 39],
                default         => null,
            };
            if ($range) {
                $where[] = 'fr.health_score BETWEEN ? AND ?';
                $params[] = $range[0]; $params[] = $range[1];
            }
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $orderSQL = match($sort) {
            'health_asc'  => 'fr.health_score ASC',
            'stars_desc'  => 'fr.stars DESC, fr.health_score DESC',
            'stars_asc'   => 'fr.stars ASC, fr.health_score ASC',
            'name'        => 'f.scheme_name ASC',
            default       => 'fr.health_score DESC',
        };

        $total = (int)$db->prepare("
            SELECT COUNT(*) FROM fund_ratings fr
            JOIN funds f ON f.id = fr.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            $whereSQL
        ")->execute($params) ? $db->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;

        // Correct count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM fund_ratings fr JOIN funds f ON f.id = fr.fund_id $whereSQL");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT fr.*, f.scheme_name, COALESCE(fh.short_name, fh.name) AS fund_house,
                   f.category, f.latest_nav, f.returns_1y, f.returns_3y
            FROM fund_ratings fr
            JOIN funds f ON f.id = fr.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            $whereSQL
            ORDER BY $orderSQL
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ratings = array_map('format_rating_row', $rows);

        json_response(true, '', [
            'data'     => $ratings,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / max(1, $perPage)),
        ]);
        break;
    }

    // ── GET fund health score + explanation ──────────────────────────────
    case 'fund_health_score': {
        $fundId = (int)($_GET['fund_id'] ?? 0);
        if ($fundId <= 0) { json_response(false, 'fund_id required'); }

        $fund = fetch_fund_for_rating($db, $fundId);
        if (!$fund) { json_response(false, 'Fund not found'); }

        $result = calculate_fund_rating($fund);

        // Explanation bullets
        $explanations = [];
        $bd = $result['breakdown'];
        $explanations[] = ($bd['returns'] >= 20)
            ? "📈 Strong multi-period returns (score: {$bd['returns']}/30)"
            : "📉 Returns below category average (score: {$bd['returns']}/30)";
        $explanations[] = ($bd['consistency'] >= 18)
            ? "✅ Consistent performance across periods (score: {$bd['consistency']}/25)"
            : "⚠️ Inconsistent returns across periods (score: {$bd['consistency']}/25)";
        $explanations[] = ($bd['risk'] >= 14)
            ? "🛡️ Well managed risk profile (score: {$bd['risk']}/20)"
            : "⚠️ Higher risk or volatility observed (score: {$bd['risk']}/20)";
        $explanations[] = ($bd['cost'] >= 10)
            ? "💰 Competitive expense ratio (score: {$bd['cost']}/15)"
            : "💸 Relatively higher expense ratio (score: {$bd['cost']}/15)";
        $explanations[] = ($bd['tenure'] >= 7)
            ? "👨‍💼 Experienced fund management (score: {$bd['tenure']}/10)"
            : "ℹ️ Limited management track record (score: {$bd['tenure']}/10)";

        json_response(true, '', [
            'fund_id'         => $fundId,
            'scheme_name'     => $fund['scheme_name'],
            'health_score'    => $result['healthScore'],
            'health_label'    => health_label($result['healthScore']),
            'stars'           => $result['stars'],
            'stars_display'   => str_repeat('⭐', $result['stars']),
            'breakdown'       => $result['breakdown'],
            'breakdown_max'   => ['returns' => 30, 'consistency' => 25, 'risk' => 20, 'cost' => 15, 'tenure' => 10],
            'explanations'    => $explanations,
            'filter_guides'   => [
                'good'      => 'Health Score ≥ 70',
                'excellent' => 'Health Score ≥ 85',
            ],
        ]);
        break;
    }

    // ── GET top health-scored funds ───────────────────────────────────────
    case 'fund_health_top': {
        $limit    = min(50, max(5, (int)($_GET['limit'] ?? 20)));
        $minScore = (int)($_GET['min_score'] ?? 70);
        $category = trim($_GET['category'] ?? '');

        $where  = ['f.is_active = 1', 'fr.health_score >= ?'];
        $params = [$minScore];
        if ($category !== '') { $where[] = 'f.category = ?'; $params[] = $category; }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT fr.fund_id, fr.stars, fr.health_score, fr.health_breakdown, fr.rated_at,
                   f.scheme_name, f.category, f.latest_nav, f.returns_1y, f.returns_3y, f.expense_ratio,
                   COALESCE(fh.short_name, fh.name) AS fund_house
            FROM fund_ratings fr
            JOIN funds f ON f.id = fr.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            $whereSQL
            ORDER BY fr.health_score DESC, fr.stars DESC
            LIMIT ?
        ");
        $params[] = $limit;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $funds = array_map(function($r) {
            return [
                'fund_id'      => (int)$r['fund_id'],
                'scheme_name'  => $r['scheme_name'],
                'fund_house'   => $r['fund_house'],
                'category'     => $r['category'],
                'stars'        => (int)$r['stars'],
                'health_score' => (int)$r['health_score'],
                'health_label' => health_label((int)$r['health_score']),
                'latest_nav'   => $r['latest_nav'] ? (float)$r['latest_nav'] : null,
                'returns_1y'   => $r['returns_1y'] !== null ? round((float)$r['returns_1y'], 2) : null,
                'returns_3y'   => $r['returns_3y'] !== null ? round((float)$r['returns_3y'], 2) : null,
                'expense_ratio'=> $r['expense_ratio'] !== null ? (float)$r['expense_ratio'] : null,
                'rated_at'     => $r['rated_at'],
            ];
        }, $rows);

        json_response(true, '', ['data' => $funds, 'count' => count($funds), 'min_score_filter' => $minScore]);
        break;
    }

    // ── POST recalculate all fund ratings (admin/cron) ────────────────────
    case 'fund_ratings_recalc': {
        if ($currentUser['role'] !== 'admin') {
            json_response(false, 'Admin access required', [], 403);
        }

        $batchSize = (int)($_POST['batch_size'] ?? 200);
        $offset    = (int)($_POST['offset'] ?? 0);

        // Fetch active funds with all scoring columns
        $colCheck = [];
        foreach (['returns_1y','returns_3y','returns_5y','category_avg_1y','category_avg_3y',
                  'sharpe_ratio','max_drawdown','inception_date','manager_since','expense_ratio'] as $c) {
            try { $db->query("SELECT {$c} FROM funds LIMIT 1"); $colCheck[] = "f.{$c}"; } catch(Exception $e) {}
        }
        $extraCols = $colCheck ? ', ' . implode(', ', $colCheck) : '';

        $stmt = $db->prepare("
            SELECT f.id, f.scheme_name, f.category {$extraCols}
            FROM funds f WHERE f.is_active = 1
            ORDER BY f.id LIMIT ? OFFSET ?
        ");
        $stmt->execute([$batchSize, $offset]);
        $funds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0; $errors = 0;
        foreach ($funds as $fund) {
            try {
                $result = calculate_fund_rating($fund);
                persist_rating($db, (int)$fund['id'], $result['stars'], $result['healthScore'],
                               $result['breakdown'], $result['healthBreakdown']);
                $processed++;
            } catch(Exception $e) {
                $errors++;
            }
        }

        $totalFunds = (int)$db->query("SELECT COUNT(*) FROM funds WHERE is_active=1")->fetchColumn();

        json_response(true, "Processed {$processed} funds (errors: {$errors})", [
            'processed'   => $processed,
            'errors'      => $errors,
            'offset'      => $offset,
            'batch_size'  => $batchSize,
            'total_funds' => $totalFunds,
            'has_more'    => ($offset + $batchSize) < $totalFunds,
            'next_offset' => $offset + $batchSize,
        ]);
        break;
    }

    default:
        json_response(false, 'Unknown action');
}
