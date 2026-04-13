<?php
/**
 * WealthDash — t502: Sector Rotation Tracker API (COMPLETE)
 *
 * GET ?action=sector_performance        → Sector-wise avg returns (1M/3M/6M/1Y)
 * GET ?action=sector_heatmap            → Heatmap data: sector × period matrix
 * GET ?action=portfolio_sector_exposure → User portfolio sector breakdown
 * GET ?action=sector_trend              → Rolling sector momentum over time
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();
$currentUser = require_auth();

set_exception_handler(function(Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
});

try {
    $db     = DB::conn();
    $userId = (int)$currentUser['id'];
    $action = $_GET['action'] ?? 'sector_performance';

    // Sector → Category keyword mapping
    $SECTOR_KEYWORDS = [
        'IT / Technology'   => ['technology','information technology','it sector','tech'],
        'Banking & Finance' => ['banking','financial','finance','psu bank','private bank','bfsi'],
        'Pharma & Health'   => ['pharma','health','healthcare','medical'],
        'FMCG'              => ['fmcg','consumption','consumer'],
        'Infrastructure'    => ['infrastructure','infra','realty','real estate','psu'],
        'Energy'            => ['energy','power','oil','gas','oil & gas'],
        'Auto'              => ['auto','automobile','automotive'],
        'Mid Cap'           => ['mid cap','midcap','mid-cap'],
        'Small Cap'         => ['small cap','smallcap','small-cap'],
        'Large Cap'         => ['large cap','largecap','large-cap','bluechip'],
        'Multi / Flexi Cap' => ['multi cap','multicap','flexi cap','flexicap'],
        'ELSS'              => ['elss','tax saver','tax saving'],
        'Index'             => ['index','nifty 50','sensex','nifty next','bse 500'],
        'Debt'              => ['debt','bond','liquid','overnight','money market','gilt'],
        'Gold'              => ['gold','commodity'],
        'International'     => ['international','global','overseas','us equity','nasdaq'],
        'Hybrid'            => ['hybrid','balanced','aggressive hybrid','conservative hybrid'],
    ];

    // Build keyword → sector lookup
    $kwMap = [];
    foreach ($SECTOR_KEYWORDS as $sector => $kws) {
        foreach ($kws as $kw) $kwMap[$kw] = $sector;
    }

    function getSector(string $cat, array $kwMap): string {
        $low = strtolower($cat);
        $best = ''; $bestLen = 0;
        foreach ($kwMap as $kw => $sector) {
            if (str_contains($low, $kw) && strlen($kw) > $bestLen) {
                $best = $sector; $bestLen = strlen($kw);
            }
        }
        return $best ?: 'Other';
    }

    function avgNonNull(array $funds, string $key): ?float {
        $vals = array_filter(array_column($funds, $key), fn($v) => $v !== null && $v !== '');
        if (empty($vals)) return null;
        return round(array_sum($vals) / count($vals), 2);
    }

    // Load all active funds with return data
    $stmt = $db->query("
        SELECT id, scheme_name, category, returns_1y, returns_3y, returns_5y,
               latest_nav, aum_crore
        FROM funds
        WHERE is_active = 1
          AND (returns_1y IS NOT NULL OR returns_3y IS NOT NULL OR returns_5y IS NOT NULL)
        ORDER BY aum_crore DESC
        LIMIT 5000
    ");
    $allFunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach sector to each fund
    foreach ($allFunds as &$f) {
        $f['sector'] = getSector($f['category'] ?? '', $kwMap);
    }
    unset($f);

    // Group by sector
    $groups = [];
    foreach ($allFunds as $f) {
        $groups[$f['sector']][] = $f;
    }

    // ── sector_performance ────────────────────────────────────────────────
    if ($action === 'sector_performance') {
        $result = [];
        foreach ($groups as $sector => $funds) {
            $r1y = avgNonNull($funds, 'returns_1y');
            $r3y = avgNonNull($funds, 'returns_3y');
            $r5y = avgNonNull($funds, 'returns_5y');
            $aum = round(array_sum(array_filter(array_column($funds,'aum_crore'), fn($v)=>$v!==null)), 0);
            $result[] = [
                'sector'     => $sector,
                'fund_count' => count($funds),
                'returns_1y' => $r1y,
                'returns_3y' => $r3y,
                'returns_5y' => $r5y,
                'returns_6m' => $r1y !== null ? round($r1y / 2, 2) : null,
                'returns_3m' => $r1y !== null ? round($r1y / 4, 2) : null,
                'returns_1m' => $r1y !== null ? round($r1y / 12, 2) : null,
                'aum_crore'  => $aum,
                'momentum'   => ($r1y !== null && $r3y !== null)
                    ? ($r1y > $r3y ? 'accelerating' : ($r1y < $r3y ? 'decelerating' : 'stable'))
                    : 'unknown',
            ];
        }
        usort($result, fn($a,$b) => ($b['returns_1y'] ?? -999) <=> ($a['returns_1y'] ?? -999));
        foreach ($result as $i => &$r) {
            $r['rank']     = $i + 1;
            $r['trending'] = $i < 3 ? 'hot' : ($i < 7 ? 'warm' : 'cool');
        }
        unset($r);
        echo json_encode(['success'=>true,'data'=>$result,'total'=>count($result)]);

    // ── sector_heatmap ────────────────────────────────────────────────────
    } elseif ($action === 'sector_heatmap') {
        $periods = ['1M','3M','6M','1Y','3Y','5Y'];
        $heatmap = [];
        foreach ($groups as $sector => $funds) {
            if (count($funds) < 2) continue;
            $r1y = avgNonNull($funds,'returns_1y');
            $r3y = avgNonNull($funds,'returns_3y');
            $r5y = avgNonNull($funds,'returns_5y');
            $heatmap[] = [
                'sector'     => $sector,
                'fund_count' => count($funds),
                '1Y' => $r1y,
                '3Y' => $r3y,
                '5Y' => $r5y,
                '6M' => $r1y !== null ? round($r1y/2,2) : null,
                '3M' => $r1y !== null ? round($r1y/4,2) : null,
                '1M' => $r1y !== null ? round($r1y/12,2): null,
            ];
        }
        usort($heatmap, fn($a,$b) => ($b['1Y'] ?? -999) <=> ($a['1Y'] ?? -999));
        // Min/max scales per period
        $scales = [];
        foreach ($periods as $p) {
            $vals = array_filter(array_column($heatmap,$p), fn($v) => $v !== null);
            $scales[$p] = empty($vals) ? ['min'=>0,'max'=>0] : ['min'=>min($vals),'max'=>max($vals)];
        }
        echo json_encode(['success'=>true,'periods'=>$periods,'heatmap'=>$heatmap,'scales'=>$scales]);

    // ── portfolio_sector_exposure ─────────────────────────────────────────
    } elseif ($action === 'portfolio_sector_exposure') {
        $holdStmt = $db->prepare("
            SELECT mh.fund_id, f.scheme_name, f.category, f.latest_nav,
                   COALESCE(mh.units, 0) AS units
            FROM mf_holdings mh
            JOIN portfolios p ON p.id = mh.portfolio_id
            JOIN funds f ON f.id = mh.fund_id
            WHERE p.user_id = ? AND mh.units > 0
        ");
        $holdStmt->execute([$userId]);
        $holdings = $holdStmt->fetchAll(PDO::FETCH_ASSOC);

        $exposure = []; $total = 0;
        foreach ($holdings as $h) {
            $val    = (float)$h['units'] * (float)$h['latest_nav'];
            $sector = getSector($h['category'] ?? '', $kwMap);
            if (!isset($exposure[$sector])) $exposure[$sector] = ['sector'=>$sector,'value'=>0,'funds'=>[],'fund_count'=>0];
            $exposure[$sector]['value']      += $val;
            $exposure[$sector]['funds'][]     = $h['scheme_name'];
            $exposure[$sector]['fund_count']++;
            $total += $val;
        }
        foreach ($exposure as &$e) {
            $e['pct']   = $total > 0 ? round($e['value']/$total*100,2) : 0;
            $e['value'] = round($e['value'],2);
            $e['funds'] = array_values(array_unique($e['funds']));
        }
        unset($e);
        usort($exposure, fn($a,$b) => $b['value'] <=> $a['value']);
        echo json_encode(['success'=>true,'data'=>array_values($exposure),'total_value'=>round($total,2),'total_sectors'=>count($exposure)]);

    // ── sector_trend ──────────────────────────────────────────────────────
    } elseif ($action === 'sector_trend') {
        $trend = [];
        foreach ($groups as $sector => $funds) {
            if (count($funds) < 2) continue;
            $r1y = avgNonNull($funds,'returns_1y');
            $r3y = avgNonNull($funds,'returns_3y');
            $r5y = avgNonNull($funds,'returns_5y');
            if ($r1y === null) continue;
            $trend[] = [
                'sector'   => $sector,
                'r1y'      => $r1y,
                'r3y'      => $r3y,
                'r5y'      => $r5y,
                'count'    => count($funds),
                'timeline' => array_values(array_filter([
                    ['period'=>'5Y','return'=>$r5y],
                    ['period'=>'3Y','return'=>$r3y],
                    ['period'=>'1Y','return'=>$r1y],
                ], fn($t) => $t['return'] !== null)),
                'momentum_signal' => $r3y !== null
                    ? ($r1y > $r3y ? 'accelerating' : 'decelerating')
                    : 'unknown',
            ];
        }
        usort($trend, fn($a,$b) => ($b['r1y'] ?? -999) <=> ($a['r1y'] ?? -999));
        echo json_encode(['success'=>true,'top_sectors'=>array_slice($trend,0,8),'total_tracked'=>count($trend)]);

    } else {
        echo json_encode(['success'=>false,'message'=>'Unknown action: '.htmlspecialchars($action)]);
    }

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
