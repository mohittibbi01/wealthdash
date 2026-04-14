<?php
/**
 * WealthDash — NAV Download Worker API
 * Incremental: sirf missing NAV dates fetch karta hai
 * Table: nav_history (fund_id, nav_date, nav)
 * Funds: funds (id, scheme_code, scheme_name, last_nav_date, is_active)
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    die(json_encode(['error' => 'Admin only']));
}
header('Content-Type: application/json; charset=UTF-8');

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? $_GET['action'] ?? 'status';

try {
    $db = DB::conn();
    match ($action) {
        'status'       => actionStatus($db),
        'start'        => actionStart($db),
        'pause'        => actionPause($db),
        'retry_errors' => actionRetryErrors($db),
        'export_csv'   => actionExportCsv($db),
        'reset'        => actionReset($db, $body),
        'process_next' => actionProcessNext($db),
        default        => respond(false, 'Unknown action'),
    };
} catch (Throwable $e) {
    respond(false, 'DB Error: ' . $e->getMessage());
}

function actionStatus(PDO $db): void {
    ensureQueueTable($db);
    $total      = safeCount($db, "SELECT COUNT(*) FROM funds WHERE is_active=1");
    $navRecords = safeCount($db, "SELECT COUNT(*) FROM nav_history");
    $pending    = safeCount($db, "SELECT COUNT(*) FROM nav_download_queue WHERE status='pending'");
    $inProgress = safeCount($db, "SELECT COUNT(*) FROM nav_download_queue WHERE status='in_progress'");
    $errors     = safeCount($db, "SELECT COUNT(*) FROM nav_download_queue WHERE status='error'");
    $downloaded = safeCount($db, "SELECT COUNT(*) FROM nav_download_queue WHERE status='done'");

    $oldest = null; $latest = null;
    try {
        $oldest = $db->query("SELECT MIN(nav_date) FROM nav_history")->fetchColumn() ?: null;
        $latest = $db->query("SELECT MAX(nav_date) FROM nav_history")->fetchColumn() ?: null;
    } catch (Throwable $e) {}

    $lastDone = null;
    try {
        $lastDone = $db->query("SELECT scheme_code, scheme_name, updated_at FROM nav_download_queue WHERE status='done' ORDER BY updated_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    respond(true, '', [
        'total_funds'          => $total,
        'nav_records'          => $navRecords,
        'queue'                => ['pending'=>$pending,'in_progress'=>$inProgress,'errors'=>$errors,'downloaded'=>$downloaded],
        'date_range'           => ['oldest'=>$oldest,'latest'=>$latest],
        'last_done'            => $lastDone,
        'funds_needing_update' => countFundsNeedingUpdate($db),
        'total_elapsed_sec'    => (int)(getSetting($db,'nav_dl_total_elapsed') ?? 0),
        'paused'               => getSetting($db,'nav_dl_paused') === '1',
    ]);
}

function actionStart(PDO $db): void {
    ensureQueueTable($db);
    $inProg = safeCount($db, "SELECT COUNT(*) FROM nav_download_queue WHERE status IN ('pending','in_progress')");
    if ($inProg > 0) respond(false, 'Download chal raha hai. Pehle pause karo.');

    $db->exec("DELETE FROM nav_download_queue WHERE status IN ('done','pending','in_progress')");
    $today = date('Y-m-d');

    $funds = $db->query("
        SELECT id, scheme_code, scheme_name, last_nav_date
        FROM funds WHERE is_active=1
        AND (last_nav_date IS NULL OR last_nav_date < '$today')
        ORDER BY scheme_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($funds)) respond(true, 'Sab funds up-to-date! Koi download nahi chahiye.', ['queued'=>0]);

    $ins = $db->prepare("INSERT INTO nav_download_queue (fund_id,scheme_code,scheme_name,status,from_date,created_at) VALUES (?,?,?,'pending',?,NOW()) ON DUPLICATE KEY UPDATE status='pending',from_date=VALUES(from_date),updated_at=NOW()");
    foreach ($funds as $f) {
        $from = $f['last_nav_date'] ? date('Y-m-d', strtotime($f['last_nav_date'].' +1 day')) : date('Y-m-d', strtotime('-1 year'));
        $ins->execute([$f['id'], $f['scheme_code'], $f['scheme_name'], $from]);
    }
    setSetting($db,'nav_dl_session_start',date('Y-m-d H:i:s'));
    setSetting($db,'nav_dl_paused','0');
    respond(true, count($funds).' funds queued.', ['queued'=>count($funds)]);
}

function actionPause(PDO $db): void {
    ensureQueueTable($db);
    $cur = getSetting($db,'nav_dl_paused') === '1';
    setSetting($db,'nav_dl_paused',$cur?'0':'1');
    if (!$cur) $db->exec("UPDATE nav_download_queue SET status='pending' WHERE status='in_progress'");
    respond(true, $cur?'Resumed':'Paused', ['paused'=>!$cur]);
}

function actionRetryErrors(PDO $db): void {
    ensureQueueTable($db);
    $cnt = safeCount($db,"SELECT COUNT(*) FROM nav_download_queue WHERE status='error'");
    $db->exec("UPDATE nav_download_queue SET status='pending',error_msg=NULL WHERE status='error'");
    setSetting($db,'nav_dl_paused','0');
    respond(true,"{$cnt} funds re-queued.",['retried'=>$cnt]);
}

function actionProcessNext(PDO $db): void {
    ensureQueueTable($db);
    if (getSetting($db,'nav_dl_paused')==='1') respond(true,'paused',['status'=>'paused']);

    $fund = $db->query("SELECT id,fund_id,scheme_code,scheme_name,from_date FROM nav_download_queue WHERE status='pending' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$fund) {
        $ss = getSetting($db,'nav_dl_session_start');
        if ($ss) {
            $elapsed = time()-strtotime($ss);
            setSetting($db,'nav_dl_total_elapsed',(string)((int)(getSetting($db,'nav_dl_total_elapsed')??0)+$elapsed));
            setSetting($db,'nav_dl_session_start','');
        }
        respond(true,'queue_empty',['status'=>'idle']);
    }

    $db->prepare("UPDATE nav_download_queue SET status='in_progress',updated_at=NOW() WHERE id=?")->execute([$fund['id']]);

    $code   = $fund['scheme_code'];
    $from   = $fund['from_date'] ?? date('Y-m-d',strtotime('-30 days'));
    $fundId = (int)$fund['fund_id'];

    $raw = @file_get_contents("https://api.mfapi.in/mf/{$code}", false, stream_context_create(['http'=>['timeout'=>15,'ignore_errors'=>true]]));
    if ($raw===false) { markError($db,$fund['id'],'Network timeout'); respond(true,'error',['status'=>'error','scheme'=>$code,'error'=>'Network error']); }

    $json = json_decode($raw,true);
    if (!$json || empty($json['data'])) { markError($db,$fund['id'],'No API data'); respond(true,'error',['status'=>'error','scheme'=>$code,'error'=>'No data']); }

    $ins = $db->prepare("INSERT INTO nav_history (fund_id,nav_date,nav,created_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE nav=VALUES(nav)");
    $inserted=0; $latestDate=null; $latestNav=null;

    foreach ($json['data'] as $row) {
        $nd = date('Y-m-d',strtotime($row['date']));
        if ($nd >= $from) {
            try { $ins->execute([$fundId,$nd,(float)$row['nav']]); $inserted++;
                if (!$latestDate||$nd>$latestDate){$latestDate=$nd;$latestNav=(float)$row['nav'];}
            } catch (Throwable $e) {}
        }
    }

    if ($latestDate) {
        try { $db->prepare("UPDATE funds SET last_nav_date=?,nav=?,updated_at=NOW() WHERE id=?")->execute([$latestDate,$latestNav,$fundId]); }
        catch (Throwable $e) {
            try { $db->prepare("UPDATE funds SET last_nav_date=?,updated_at=NOW() WHERE id=?")->execute([$latestDate,$fundId]); } catch(Throwable $e2){}
        }
    }

    $db->prepare("UPDATE nav_download_queue SET status='done',nav_records_added=?,updated_at=NOW() WHERE id=?")->execute([$inserted,$fund['id']]);
    respond(true,'processed',['status'=>'processed','scheme'=>$code,'inserted'=>$inserted,'name'=>$fund['scheme_name']]);
}

function actionExportCsv(PDO $db): void {
    while(ob_get_level())ob_end_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="nav_export_'.date('Ymd_His').'.csv"');
    $fp = fopen('php://output','w');
    fputcsv($fp,['Scheme Code','Scheme Name','NAV Date','NAV']);
    $stmt = $db->query("SELECT f.scheme_code,f.scheme_name,h.nav_date,h.nav FROM nav_history h JOIN funds f ON f.id=h.fund_id ORDER BY f.scheme_code,h.nav_date DESC LIMIT 200000");
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($fp,$row);
    fclose($fp); exit;
}

function actionReset(PDO $db, array $body): void {
    if (empty($body['confirm'])) respond(false,'confirm:true pass karo.');
    ensureQueueTable($db);
    $db->exec("TRUNCATE TABLE nav_download_queue");
    setSetting($db,'nav_dl_paused','0'); setSetting($db,'nav_dl_session_start',''); setSetting($db,'nav_dl_total_elapsed','0');
    respond(true,'Queue cleared. NAV history safe hai.');
}

function ensureQueueTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS nav_download_queue (
        id INT AUTO_INCREMENT PRIMARY KEY, fund_id INT, scheme_code VARCHAR(20),
        scheme_name VARCHAR(255), status ENUM('pending','in_progress','done','error') DEFAULT 'pending',
        from_date DATE, nav_records_added INT DEFAULT 0, error_msg VARCHAR(500),
        created_at DATETIME, updated_at DATETIME, UNIQUE KEY uq_fund (fund_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function countFundsNeedingUpdate(PDO $db): int {
    try { return (int)$db->query("SELECT COUNT(*) FROM funds WHERE is_active=1 AND (last_nav_date IS NULL OR last_nav_date < CURDATE())")->fetchColumn(); }
    catch(Throwable $e){return 0;}
}

function markError(PDO $db, int $id, string $msg): void {
    $db->prepare("UPDATE nav_download_queue SET status='error',error_msg=?,updated_at=NOW() WHERE id=?")->execute([$msg,$id]);
}

function safeCount(PDO $db, string $sql): int {
    try{return (int)$db->query($sql)->fetchColumn();}catch(Throwable $e){return 0;}
}

function getSetting(PDO $db, string $key): ?string {
    try{$s=$db->prepare("SELECT setting_val FROM app_settings WHERE setting_key=?");$s->execute([$key]);$v=$s->fetchColumn();return $v!==false?(string)$v:null;}catch(Throwable $e){return null;}
}

function setSetting(PDO $db, string $key, string $val): void {
    try{
        $s=$db->prepare("SELECT COUNT(*) FROM app_settings WHERE setting_key=?");$s->execute([$key]);
        if((int)$s->fetchColumn()>0) $db->prepare("UPDATE app_settings SET setting_val=? WHERE setting_key=?")->execute([$val,$key]);
        else $db->prepare("INSERT INTO app_settings (setting_key,setting_val) VALUES (?,?)")->execute([$key,$val]);
    }catch(Throwable $e){}
}

function respond(bool $ok, string $msg, array $data=[]): void {
    echo json_encode(array_merge(['ok'=>$ok,'message'=>$msg],$data)); exit;
}
