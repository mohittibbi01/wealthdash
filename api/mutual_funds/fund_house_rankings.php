<?php
/**
 * WealthDash — t168: Fund House Rankings API
 * Groups funds by fund_house, computes avg returns, fund count, top fund
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

require_auth();

header('Content-Type: application/json');

$sort    = in_array($_GET['sort'] ?? '', ['ret1y','ret3y','ret5y','funds','aum']) ? ($_GET['sort'] ?? 'ret1y') : 'ret1y';
$minFund = max(1, (int)($_GET['min_funds'] ?? 1));

try {
    $hasRet  = true;
    try { DB::conn()->query("SELECT returns_1y FROM funds LIMIT 1"); } catch(Exception $e){ $hasRet=false; }
    $hasAum  = true;
    try { DB::conn()->query("SELECT aum_crore FROM funds LIMIT 1"); } catch(Exception $e){ $hasAum=false; }

    $retCols = $hasRet  ? ', AVG(CASE WHEN f.returns_1y IS NOT NULL THEN f.returns_1y END) AS avg_ret1y,
                            AVG(CASE WHEN f.returns_3y IS NOT NULL THEN f.returns_3y END) AS avg_ret3y,
                            AVG(CASE WHEN f.returns_5y IS NOT NULL THEN f.returns_5y END) AS avg_ret5y' : ', NULL AS avg_ret1y, NULL AS avg_ret3y, NULL AS avg_ret5y';
    $aumCol  = $hasAum  ? ', SUM(f.aum_crore) AS total_aum' : ', NULL AS total_aum';

    $sortMap = [
        'ret1y' => 'avg_ret1y DESC',
        'ret3y' => 'avg_ret3y DESC',
        'ret5y' => 'avg_ret5y DESC',
        'funds' => 'fund_count DESC',
        'aum'   => 'total_aum DESC',
    ];
    $orderBy = $sortMap[$sort];

    $rows = DB::fetchAll(
        "SELECT
            COALESCE(fh.short_name, fh.name, f.fund_house, 'Unknown') AS house_name,
            COUNT(*) AS fund_count
            $retCols
            $aumCol
         FROM funds f
         LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
         WHERE f.is_active = 1
         GROUP BY COALESCE(fh.short_name, fh.name, f.fund_house, 'Unknown')
         HAVING fund_count >= ?
         ORDER BY $orderBy
         LIMIT 50",
        [$minFund]
    );

    // For each house, get top performing fund name
    foreach ($rows as &$row) {
        if (!$hasRet) { $row['top_fund'] = null; continue; }
        $top = DB::fetchOne(
            "SELECT f.scheme_name, f.returns_1y
             FROM funds f
             LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
             WHERE COALESCE(fh.short_name, fh.name, f.fund_house, 'Unknown') = ?
               AND f.is_active = 1 AND f.returns_1y IS NOT NULL
             ORDER BY f.returns_1y DESC LIMIT 1",
            [$row['house_name']]
        );
        $row['top_fund']     = $top['scheme_name'] ?? null;
        $row['top_fund_ret'] = $top['returns_1y']  ?? null;

        // Round for display
        $row['avg_ret1y'] = $row['avg_ret1y'] !== null ? round((float)$row['avg_ret1y'], 2) : null;
        $row['avg_ret3y'] = $row['avg_ret3y'] !== null ? round((float)$row['avg_ret3y'], 2) : null;
        $row['avg_ret5y'] = $row['avg_ret5y'] !== null ? round((float)$row['avg_ret5y'], 2) : null;
        $row['total_aum'] = $row['total_aum'] !== null ? round((float)$row['total_aum'], 0) : null;
        $row['fund_count']= (int)$row['fund_count'];
    }
    unset($row);

    json_response(true, '', ['rankings' => $rows, 'sort' => $sort]);
} catch (Exception $e) {
    json_response(false, 'Rankings failed: ' . $e->getMessage());
}
