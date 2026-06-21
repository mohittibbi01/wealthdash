<?php
/**
 * WealthDash — t198: NPS Screener — PFM + Asset Class Filter (Enhanced)
 * Replaces t100 stub. Full screener with filtering, sorting, pagination, compare.
 * GET params: q, pfm, tier, asset_class, sort, page, per_page, compare, min_return_1y, max_nav
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
header('Content-Type: application/json; charset=utf-8');
error_reporting(0); ini_set('display_errors','0'); ob_start();
require_auth();

try {
    $q          = trim($_GET['q']           ?? '');
    $pfm        = clean($_GET['pfm']        ?? '');
    $tier       = clean($_GET['tier']       ?? '');
    $assetClass = clean($_GET['asset_class'] ?? '');
    $sort       = clean($_GET['sort']       ?? 'return_1y');
    $page       = max(1, (int)($_GET['page']     ?? 1));
    $perPage    = min(max(1, (int)($_GET['per_page'] ?? 40)), 100);
    $offset     = ($page - 1) * $perPage;
    $compare    = array_filter(array_map('intval', explode(',', $_GET['compare'] ?? '')));
    $minReturn1y= isset($_GET['min_return_1y']) ? (float)$_GET['min_return_1y'] : null;
    $maxNav     = isset($_GET['max_nav'])        ? (float)$_GET['max_nav']       : null;
    $minAum     = isset($_GET['min_aum'])        ? (float)$_GET['min_aum']       : null;
    $userHoldings= (int)($_GET['user_holdings']  ?? 0); // 1=only schemes user holds
    $userId     = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    $where  = ['s.is_active = 1']; $params = [];

    if ($q!=='') { $like='%'.$q.'%'; $where[]='(s.scheme_name LIKE ? OR s.pfm_name LIKE ? OR s.scheme_code LIKE ?)'; array_push($params,$like,$like,$like); }
    if ($pfm)        { $where[]='s.pfm_name = ?';        $params[]=$pfm; }
    if ($tier)       { $where[]='s.tier = ?';             $params[]=$tier; }
    if ($assetClass) { $where[]='s.asset_class = ?';      $params[]=$assetClass; }
    if ($minReturn1y!==null) { $where[]='s.return_1y >= ?'; $params[]=$minReturn1y; }
    if ($maxNav!==null)     { $where[]='s.latest_nav <= ?'; $params[]=$maxNav; }
    if ($minAum!==null)     { $where[]='s.aum_cr >= ?';     $params[]=$minAum; }
    if ($userHoldings&&$userId) { $where[]="EXISTS(SELECT 1 FROM nps_holdings nh JOIN portfolios p ON p.id=nh.portfolio_id WHERE nh.scheme_id=s.id AND p.user_id=?)"; $params[]=$userId; }

    $sortMap=['return_1y'=>'s.return_1y DESC','return_3y'=>'s.return_3y DESC','return_5y'=>'s.return_5y DESC','return_since'=>'s.return_since DESC','nav_desc'=>'s.latest_nav DESC','nav_asc'=>'s.latest_nav ASC','name'=>'s.scheme_name ASC','aum'=>'s.aum_cr DESC','pfm'=>'s.pfm_name ASC, s.scheme_name ASC'];
    $orderBy=$sortMap[$sort]??'s.return_1y DESC';
    $whereStr=implode(' AND ',$where);

    $totalCount=(int)(DB::fetchVal("SELECT COUNT(*) FROM nps_schemes s WHERE {$whereStr}",$params)?:0);
    $schemes=DB::fetchAll("SELECT s.id,s.scheme_name,s.pfm_name,s.tier,s.asset_class,s.scheme_code,s.latest_nav,s.latest_nav_date,s.return_1y,s.return_3y,s.return_5y,s.return_since,s.aum_cr,(SELECT COUNT(*) FROM nps_nav_history nh WHERE nh.scheme_id=s.id) AS nav_count FROM nps_schemes s WHERE {$whereStr} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}",$params);

    // Asset class labels
    $acLabels=['E'=>'Equity','C'=>'Corporate Debt','G'=>'Govt Securities','A'=>'Alternative Assets'];
    foreach ($schemes as &$s) {
        $s['asset_class_label']=$acLabels[$s['asset_class']]??$s['asset_class'];
        $s['tier_label']=strtoupper(str_replace('tier','',$s['tier']))==='1'?'Tier I':'Tier II';
    } unset($s);

    // Compare
    $compareData=[];
    if (!empty($compare)) {
        $ids=implode(',',array_slice($compare,0,3));
        $cs=DB::fetchAll("SELECT s.*,(SELECT COUNT(*) FROM nps_nav_history nh WHERE nh.scheme_id=s.id) AS nav_count FROM nps_schemes s WHERE s.id IN ({$ids})");
        foreach ($cs as $c) { $nav=DB::fetchAll("SELECT nav_date,nav FROM nps_nav_history WHERE scheme_id=? AND nav_date>=DATE_SUB(CURDATE(),INTERVAL 3 YEAR) ORDER BY nav_date ASC",[$c['id']]); $c['nav_chart']=$nav; $c['asset_class_label']=$acLabels[$c['asset_class']]??$c['asset_class']; $compareData[]=$c; }
    }

    // PFM performance summary
    $pfmSummary=DB::fetchAll("SELECT pfm_name,COUNT(*) AS scheme_count,AVG(return_1y) AS avg_return_1y,AVG(return_3y) AS avg_return_3y,AVG(return_5y) AS avg_return_5y,SUM(aum_cr) AS total_aum FROM nps_schemes WHERE is_active=1 GROUP BY pfm_name ORDER BY avg_return_1y DESC");

    $pfmList=DB::fetchAll("SELECT DISTINCT pfm_name FROM nps_schemes WHERE is_active=1 ORDER BY pfm_name");
    $acList=DB::fetchAll("SELECT DISTINCT asset_class FROM nps_schemes WHERE is_active=1 ORDER BY asset_class");
    $stats=DB::fetchOne("SELECT COUNT(*) AS total,SUM(CASE WHEN return_1y IS NOT NULL THEN 1 ELSE 0 END) AS with_returns,MAX(latest_nav_date) AS latest_nav_date FROM nps_schemes WHERE is_active=1");

    ob_end_clean();
    echo json_encode(['success'=>true,'schemes'=>$schemes,'total'=>$totalCount,'page'=>$page,'per_page'=>$perPage,'pages'=>(int)ceil($totalCount/$perPage),'compare'=>$compareData,'pfm_summary'=>$pfmSummary,'meta'=>['pfms'=>array_column($pfmList,'pfm_name'),'asset_classes'=>array_column($acList,'asset_class'),'asset_class_labels'=>$acLabels,'stats'=>$stats]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
}
