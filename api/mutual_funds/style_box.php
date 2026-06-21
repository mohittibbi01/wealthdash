<?php
/**
 * WealthDash — Style Box API (tv04)
 *
 * Morningstar-style 3×3 Style Box:
 *   Size axis:   Large | Mid | Small
 *   Style axis:  Value | Blend | Growth
 *
 * Category-based mapping (no portfolio holdings required).
 * Optional: holdings-based override if fund_holdings_detail table exists.
 *
 * Actions:
 *   GET  ?action=style_box_map              → category → style_box mapping table
 *   GET  ?action=style_box_fund&fund_id=X   → single fund style box
 *   GET  ?action=style_box_screener         → screener grid counts
 *   POST action=style_box_recalc            → batch recalculate (admin)
 *   GET  ?action=style_box_portfolio        → portfolio style drift summary
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// Ensure style_box columns exist on funds table
try { $db->exec("ALTER TABLE `funds` ADD COLUMN IF NOT EXISTS `style_size`       ENUM('large','mid','small') DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD COLUMN IF NOT EXISTS `style_value`      ENUM('value','blend','growth') DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD COLUMN IF NOT EXISTS `style_drift_note` VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD INDEX IF NOT EXISTS `idx_style_size`  (`style_size`)"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD INDEX IF NOT EXISTS `idx_style_value` (`style_value`)"); } catch(Exception $e) {}

// ── Category → Style Box mapping ─────────────────────────────────────────
/**
 * SEBI-based category to style_size + style_value mapping.
 * Returns ['size' => 'large'|'mid'|'small'|null, 'value' => 'value'|'blend'|'growth'|null]
 */
function category_to_style(string $cat): array {
    $c = strtolower(trim($cat));

    // ── Size axis ──────────────────────────────────────────────────────
    $size = null;
    if (str_contains($c, 'large cap') || str_contains($c, 'largecap'))
        $size = 'large';
    elseif (str_contains($c, 'mid cap') || str_contains($c, 'midcap'))
        $size = 'mid';
    elseif (str_contains($c, 'small cap') || str_contains($c, 'smallcap'))
        $size = 'small';
    elseif (str_contains($c, 'multi cap') || str_contains($c, 'multicap') ||
            str_contains($c, 'flexi cap') || str_contains($c, 'flexicap') ||
            str_contains($c, 'focused fund'))
        $size = 'large'; // Tend to have large-cap tilt
    elseif (str_contains($c, 'large & mid') || str_contains($c, 'large and mid'))
        $size = 'large'; // Dominated by large
    elseif (str_contains($c, 'mid & small') || str_contains($c, 'mid and small'))
        $size = 'mid';
    elseif (str_contains($c, 'micro cap'))
        $size = 'small';
    elseif (str_contains($c, 'index') || str_contains($c, 'etf')) {
        // Index fund size from index name
        if (str_contains($c, 'midcap') || str_contains($c, 'mid cap')) $size = 'mid';
        elseif (str_contains($c, 'smallcap') || str_contains($c, 'small cap')) $size = 'small';
        else $size = 'large'; // nifty 50, sensex etc.
    } elseif (str_contains($c, 'elss') || str_contains($c, 'tax sav'))
        $size = 'large'; // Most ELSS are large-cap tilted

    // ── Style axis ────────────────────────────────────────────────────
    $value = null;
    if (str_contains($c, 'value') || str_contains($c, 'contra'))
        $value = 'value';
    elseif (str_contains($c, 'growth'))
        $value = 'growth';
    elseif (str_contains($c, 'momentum') || str_contains($c, 'quality') ||
            str_contains($c, 'alpha') || str_contains($c, 'factor'))
        $value = 'growth'; // Factor/momentum funds are growth-tilted
    elseif (str_contains($c, 'dividend') || str_contains($c, 'income'))
        $value = 'value'; // Income-focused = value tilt
    else
        $value = 'blend'; // default for most standard categories

    return ['size' => $size, 'value' => $value];
}

/**
 * Attempt holdings-based override (if fund_holdings_detail table exists).
 * Returns null if table doesn't exist or no data.
 */
function style_from_holdings(PDO $db, int $fundId): ?array {
    try {
        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN market_cap_category='large' THEN COALESCE(weight_pct,0) ELSE 0 END) AS large_pct,
                SUM(CASE WHEN market_cap_category='mid'   THEN COALESCE(weight_pct,0) ELSE 0 END) AS mid_pct,
                SUM(CASE WHEN market_cap_category='small' THEN COALESCE(weight_pct,0) ELSE 0 END) AS small_pct,
                AVG(NULLIF(pe_ratio,0)) AS avg_pe
            FROM fund_holdings_detail
            WHERE fund_id = ?
        ");
        $stmt->execute([$fundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || ($row['large_pct'] == 0 && $row['mid_pct'] == 0 && $row['small_pct'] == 0)) {
            return null;
        }

        // Size classification (SEBI-based)
        $lp = (float)$row['large_pct'];
        $mp = (float)$row['mid_pct'];
        $sp = (float)$row['small_pct'];
        $size = ($lp >= 60) ? 'large' : (($mp >= 40) ? 'mid' : 'small');

        // Growth vs Value (P/E based)
        $pe = $row['avg_pe'] !== null ? (float)$row['avg_pe'] : null;
        $value = ($pe !== null) ? (($pe > 30) ? 'growth' : (($pe < 15) ? 'value' : 'blend')) : null;

        return ['size' => $size, 'value' => $value, 'large_pct' => $lp, 'mid_pct' => $mp, 'small_pct' => $sp, 'avg_pe' => $pe];
    } catch(Exception $e) {
        return null;
    }
}

/**
 * Compute style drift note (compares category claim vs actual holdings).
 */
function compute_drift_note(string $category, ?array $holdingsStyle, array $catStyle): ?string {
    if (!$holdingsStyle) return null;

    $catSize = $catStyle['size'];
    $actSize = $holdingsStyle['size'];
    $lp = round($holdingsStyle['large_pct'] ?? 0);
    $mp = round($holdingsStyle['mid_pct'] ?? 0);
    $sp = round($holdingsStyle['small_pct'] ?? 0);

    if ($catSize && $actSize && $catSize !== $actSize) {
        $sizeLabel = ['large' => 'Large Cap', 'mid' => 'Mid Cap', 'small' => 'Small Cap'];
        return sprintf(
            'Category claims %s but actual split: Large %d%%, Mid %d%%, Small %d%%',
            $sizeLabel[$catSize] ?? $catSize, $lp, $mp, $sp
        );
    }
    return null;
}

// ── Full mapping reference table ──────────────────────────────────────────
$CATEGORY_MAP = [
    // SEBI equity categories
    'Large Cap Fund'          => ['large', 'blend'],
    'Mid Cap Fund'            => ['mid',   'blend'],
    'Small Cap Fund'          => ['small', 'blend'],
    'Multi Cap Fund'          => ['large', 'blend'],
    'Flexi Cap Fund'          => ['large', 'blend'],
    'Large & Mid Cap Fund'    => ['large', 'blend'],
    'Focused Fund'            => ['large', 'blend'],
    'Value Fund'              => ['large', 'value'],
    'Contra Fund'             => ['large', 'value'],
    'Dividend Yield Fund'     => ['large', 'value'],
    'ELSS'                    => ['large', 'blend'],
    'Sectoral/Thematic'       => ['large', 'growth'],
    // Debt → N/A
];

// ── Route ─────────────────────────────────────────────────────────────────
switch ($action) {

    // ── GET category → style box mapping reference ───────────────────────
    case 'style_box_map': {
        $stmt = $db->query("
            SELECT category, COUNT(*) AS fund_count,
                   style_size, style_value
            FROM funds
            WHERE is_active=1 AND category IS NOT NULL
            GROUP BY category, style_size, style_value
            ORDER BY fund_count DESC
            LIMIT 200
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mapped = [];
        foreach ($rows as $r) {
            $cat = $r['category'];
            $computed = category_to_style($cat);
            $mapped[] = [
                'category'   => $cat,
                'fund_count' => (int)$r['fund_count'],
                'style_size' => $r['style_size'] ?? $computed['size'],
                'style_value'=> $r['style_value'] ?? $computed['value'],
                'source'     => $r['style_size'] ? 'db' : 'computed',
            ];
        }

        json_response(true, '', ['data' => $mapped, 'count' => count($mapped)]);
        break;
    }

    // ── GET single fund style box ─────────────────────────────────────────
    case 'style_box_fund': {
        $fundId = (int)($_GET['fund_id'] ?? 0);
        if ($fundId <= 0) json_response(false, 'fund_id required');

        $stmt = $db->prepare("
            SELECT f.id, f.scheme_name, f.category, f.style_size, f.style_value, f.style_drift_note
            FROM funds f WHERE f.id = ? AND f.is_active = 1
        ");
        $stmt->execute([$fundId]);
        $fund = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fund) json_response(false, 'Fund not found');

        $catStyle = category_to_style($fund['category'] ?? '');
        $holdingsStyle = style_from_holdings($db, $fundId);

        // Final style: holdings override > db stored > category computed
        $finalSize  = $holdingsStyle['size']  ?? $fund['style_size']  ?? $catStyle['size'];
        $finalValue = $holdingsStyle['value'] ?? $fund['style_value'] ?? $catStyle['value'];
        $driftNote  = compute_drift_note($fund['category'] ?? '', $holdingsStyle, $catStyle) ?? $fund['style_drift_note'];

        // 9-cell grid: which cell is active
        $cells = [];
        foreach (['large','mid','small'] as $sz) {
            foreach (['value','blend','growth'] as $vl) {
                $cells["{$sz}_{$vl}"] = ($sz === $finalSize && $vl === $finalValue);
            }
        }

        json_response(true, '', [
            'fund_id'           => $fundId,
            'scheme_name'       => $fund['scheme_name'],
            'category'          => $fund['category'],
            'style_size'        => $finalSize,
            'style_value'       => $finalValue,
            'style_box'         => $finalSize && $finalValue ? "{$finalSize}_{$finalValue}" : null,
            'style_drift_note'  => $driftNote,
            'cells'             => $cells,
            'source'            => $holdingsStyle ? 'holdings' : ($fund['style_size'] ? 'db' : 'category'),
            'holdings_data'     => $holdingsStyle ? array_intersect_key($holdingsStyle, array_flip(['large_pct','mid_pct','small_pct','avg_pe'])) : null,
        ]);
        break;
    }

    // ── GET screener grid — fund count per style box cell ────────────────
    case 'style_box_screener': {
        $category  = trim($_GET['category'] ?? '');
        $fundHouse = trim($_GET['fund_house'] ?? '');

        $where  = ['f.is_active = 1'];
        $params = [];
        if ($category !== '')  { $where[] = 'f.category = ?';                   $params[] = $category; }
        if ($fundHouse !== '') { $where[] = 'COALESCE(fh.short_name,fh.name) = ?'; $params[] = $fundHouse; }
        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        // Funds with DB-stored style
        $stmt = $db->prepare("
            SELECT f.style_size, f.style_value, COUNT(*) AS cnt
            FROM funds f
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            $whereSQL AND f.style_size IS NOT NULL AND f.style_value IS NOT NULL
            GROUP BY f.style_size, f.style_value
        ");
        $stmt->execute($params);
        $dbCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Initialize 9-cell grid
        $grid = [];
        foreach (['large','mid','small'] as $sz) {
            foreach (['value','blend','growth'] as $vl) {
                $grid["{$sz}_{$vl}"] = 0;
            }
        }

        // Funds without style_size → compute from category
        $stmt2 = $db->prepare("
            SELECT f.category, COUNT(*) AS cnt
            FROM funds f
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            $whereSQL AND (f.style_size IS NULL OR f.style_value IS NULL)
            GROUP BY f.category
        ");
        $stmt2->execute($params);
        $catCounts = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        foreach ($dbCounts as $r) {
            $key = "{$r['style_size']}_{$r['style_value']}";
            if (isset($grid[$key])) $grid[$key] += (int)$r['cnt'];
        }
        foreach ($catCounts as $r) {
            $s = category_to_style($r['category'] ?? '');
            if ($s['size'] && $s['value']) {
                $key = "{$s['size']}_{$s['value']}";
                if (isset($grid[$key])) $grid[$key] += (int)$r['cnt'];
            }
        }

        // Total categorized
        $total = array_sum($grid);

        json_response(true, '', [
            'grid'  => $grid,
            'total' => $total,
            'axes'  => [
                'size'  => ['large', 'mid', 'small'],
                'value' => ['value', 'blend', 'growth'],
            ],
        ]);
        break;
    }

    // ── GET portfolio style drift ─────────────────────────────────────────
    case 'style_box_portfolio': {
        $portfolioId = (int)($_GET['portfolio_id'] ?? 0);

        $where  = ['p.user_id = ?'];
        $params = [$userId];
        if ($portfolioId > 0) { $where[] = 'p.id = ?'; $params[] = $portfolioId; }

        // Get user's held fund categories/styles
        $stmt = $db->prepare("
            SELECT f.id AS fund_id, f.scheme_name, f.category,
                   f.style_size, f.style_value,
                   COALESCE(SUM(t.units), 0) AS total_units
            FROM mf_transactions t
            JOIN funds f  ON f.id  = t.fund_id
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE " . implode(' AND ', $where) . "
              AND t.txn_type IN ('buy','sip','switch_in','reinvest')
            GROUP BY f.id, f.scheme_name, f.category, f.style_size, f.style_value
            HAVING total_units > 0
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grid = [];
        foreach (['large','mid','small'] as $sz) {
            foreach (['value','blend','growth'] as $vl) {
                $grid["{$sz}_{$vl}"] = ['count' => 0, 'funds' => []];
            }
        }

        foreach ($rows as $r) {
            $s = [
                'size'  => $r['style_size'],
                'value' => $r['style_value'],
            ];
            if (!$s['size'] || !$s['value']) {
                $s = category_to_style($r['category'] ?? '');
            }
            if ($s['size'] && $s['value']) {
                $key = "{$s['size']}_{$s['value']}";
                if (isset($grid[$key])) {
                    $grid[$key]['count']++;
                    $grid[$key]['funds'][] = $r['scheme_name'];
                }
            }
        }

        json_response(true, '', [
            'grid'        => $grid,
            'total_funds' => count($rows),
        ]);
        break;
    }

    // ── POST batch recalculate style boxes (admin) ────────────────────────
    case 'style_box_recalc': {
        if ($currentUser['role'] !== 'admin') {
            json_response(false, 'Admin access required', [], 403);
        }

        $batchSize = (int)($_POST['batch_size'] ?? 500);
        $offset    = (int)($_POST['offset'] ?? 0);

        $stmt = $db->prepare("
            SELECT id, scheme_name, category FROM funds
            WHERE is_active = 1 ORDER BY id LIMIT ? OFFSET ?
        ");
        $stmt->execute([$batchSize, $offset]);
        $funds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updated = 0;
        $updateStmt = $db->prepare("
            UPDATE funds SET style_size=?, style_value=? WHERE id=?
        ");
        foreach ($funds as $f) {
            $s = category_to_style($f['category'] ?? '');
            if ($s['size'] || $s['value']) {
                // Don't overwrite existing holdings-based data
                $updateStmt->execute([$s['size'], $s['value'], (int)$f['id']]);
                $updated++;
            }
        }

        $totalFunds = (int)$db->query("SELECT COUNT(*) FROM funds WHERE is_active=1")->fetchColumn();

        json_response(true, "Updated {$updated} funds", [
            'updated'     => $updated,
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
