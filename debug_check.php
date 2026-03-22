getMessage()];
    $errors++;
}

// 2. Required Tables
$tables = ['users','mf_holdings','mf_transactions','funds','nav_history',
           'nps_holdings','fd_accounts','savings_accounts','sip_schedules',
           'post_office_schemes','stocks_holdings'];
foreach ($tables as $tbl) {
    $res = $db->query("SHOW TABLES LIKE '$tbl'");
    if ($res->rowCount() > 0)
        $results[] = ['pass','DB Table',"Table `$tbl` exists"];
    else {
        $results[] = ['fail','DB Table',"MISSING table: `$tbl`"];
        $errors++;
    }
}

// 3. Required Columns
$cols = [
    'mf_holdings' => ['updated_at','scheme_code','fund_name','units','avg_nav'],
    'funds'       => ['scheme_code','fund_name','category','exit_load_percent'],
    'nav_history' => ['scheme_code','nav_date','nav'],
];
foreach ($cols as $tbl => $columns) {
    foreach ($columns as $col) {
        $res = $db->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'");
        if ($res->rowCount() > 0)
            $results[] = ['pass','Column',"`$tbl`.`$col` exists"];
        else {
            $results[] = ['fail','Column',"MISSING column `$col` in `$tbl`"];
            $errors++;
        }
    }
}

// 4. API Files exist
$apis = [
    'api/mutual_funds/mf_list.php','api/mutual_funds/mf_add.php',
    'api/reports/fy_gains.php','api/reports/net_worth.php',
    'api/nav/update_amfi.php','cron/update_nav_daily.php',
];
foreach ($apis as $f) {
    if (file_exists(__DIR__.'/'.$f))
        $results[] = ['pass','File',"$f found"];
    else {
        $results[] = ['fail','File',"MISSING file: $f"];
        $errors++;
    }
}

// 5. PHP Syntax check (php -l)
$phpFiles = glob(__DIR__.'/api/**/*.php');
foreach ($phpFiles as $f) {
    $out = shell_exec("php -l ".escapeshellarg($f)." 2>&1");
    if (strpos($out,'No syntax errors') !== false)
        $results[] = ['pass','Syntax',basename($f).' — OK'];
    else {
        $results[] = ['fail','Syntax',basename($f).' — '.$out];
        $errors++;
    }
}

// 6. NAV freshness
$row = $db->query("SELECT MAX(nav_date) as d FROM nav_history")->fetch();
$dayOld = $row ? round((time()-strtotime($row['d']))/86400) : 999;
if ($dayOld <= 1)      $results[] = ['pass','Data','NAV data is fresh ('.$row['d'].')'];
elseif ($dayOld <= 5)  { $results[] = ['warn','Data',"NAV is $dayOld days old"]; $warns++; }
else                   { $results[] = ['fail','Data',"NAV is STALE ($dayOld days old)"]; $errors++; }

// Output HTML Report
header('Content-Type: text/html; charset=utf-8');
echo "WealthDash Debug
body{font-family:system-ui;padding:20px;background:#f0f4f8;color:#1e293b}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}
th{background:#6366f1;color:#fff;padding:10px 14px;font-size:12px;text-align:left}
td{padding:8px 14px;font-size:12px;border-bottom:1px solid #e2e8f0}
.pass{color:#16a34a}.fail{color:#dc2626;font-weight:700}.warn{color:#ca8a04}
.summary{padding:14px 18px;margin-bottom:16px;border-radius:10px;font-size:13px;font-weight:700}
.s-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a}
.s-fail{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
";
echo "🔧 WealthDash Debug Report — ".date('d M Y H:i')."";
$cls = $errors > 0 ? 's-fail' : 's-ok';
$ico = $errors > 0 ? '❌' : '✅';
echo "$ico Total: ".count($results)." checks · Errors: $errors · Warnings: $warns";
echo "";
foreach ($results as $r) {
    $cls = $r[0]; $ico = $r[0]==='pass'?'✅':($r[0]==='warn'?'⚠️':'❌');
    echo "";
}
echo "StatusCategoryDetail$ico$r[1]$r[2]";
?>