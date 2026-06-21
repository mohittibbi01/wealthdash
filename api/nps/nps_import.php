<?php
/**
 * WealthDash — t106: NPS Contribution Auto-detect (Bank Statement Import)
 *
 * Parses uploaded bank statement CSV/text, detects NPS contribution rows
 * using keyword matching, stages them for user review, then imports to
 * nps_transactions on confirmation.
 *
 * Actions (read — CSRF exempt):
 *   nps_import_sessions_list  — list past import sessions
 *   nps_import_staging_list   — list staged rows for a session
 *   nps_import_schemes        — list NPS schemes for dropdown
 *
 * Actions (write — CSRF required):
 *   nps_import_parse          — upload + parse CSV, detect NPS rows, create session
 *   nps_import_staging_update — review: assign scheme_id / tier / units / nav
 *   nps_import_staging_accept — mark row as accepted
 *   nps_import_staging_reject — mark row as rejected
 *   nps_import_confirm        — import all accepted rows to nps_transactions
 *   nps_import_session_delete — delete a session and its staging rows
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_GET['action'] ?? $_POST['action'] ?? 'nps_import_sessions_list';

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);
if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin ?? false)) {
    json_response(false, 'Invalid or inaccessible portfolio.', [], 403);
}

// ── NPS KEYWORD PATTERNS ─────────────────────────────────────────────────
const NPS_KEYWORDS = [
    ['pattern' => '/NPS[\s\-_]?CONTRIBUTION/i',   'confidence' => 100, 'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/NSDL[\s\-]?CRA/i',            'confidence' => 100, 'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/NPS[\s\-]?PFRDA/i',           'confidence' => 100, 'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/PFRDA[\s\-]?NPS/i',           'confidence' => 100, 'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/NPS[\s\-]?TRUST/i',           'confidence' => 100, 'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/NPSTRUST/i',                  'confidence' => 100, 'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/CRA[\s\-]?CONTRIBUTION/i',    'confidence' => 95,  'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/HDFC\s?PENSION/i',            'confidence' => 95,  'pfm' => 'HDFC Pension Management',           'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/SBI\s?PENSION/i',             'confidence' => 95,  'pfm' => 'SBI Pension Funds',                 'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/ICICI\s?PRU\s?PENSION/i',     'confidence' => 95,  'pfm' => 'ICICI Pru Pension Fund',            'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/KOTAK\s?PENSION/i',           'confidence' => 95,  'pfm' => 'Kotak Pension Fund',                'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/UTI\s?RETIREMENT/i',          'confidence' => 95,  'pfm' => 'UTI Retirement Solutions',          'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/LIC\s?PENSION/i',             'confidence' => 95,  'pfm' => 'LIC Pension Fund',                  'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/ADITYA\s?BIRLA\s?PENSION/i', 'confidence' => 95,  'pfm' => 'Aditya Birla Sun Life Pension',     'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/AXIS\s?PENSION/i',            'confidence' => 95,  'pfm' => 'Axis Pension Fund Management',      'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/DSP\s?PENSION/i',             'confidence' => 95,  'pfm' => 'DSP Pension Fund',                  'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/TATA\s?PENSION/i',            'confidence' => 95,  'pfm' => 'Tata Pension Management',           'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/MAX\s?LIFE\s?PENSION/i',      'confidence' => 95,  'pfm' => 'Max Life Pension Fund',             'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/NPS[\s\-]?TIER[\s\-]?2/i',   'confidence' => 100, 'pfm' => null,                                'tier' => 'tier2', 'contrib' => 'SELF'],
    ['pattern' => '/NPS[\s\-]?T2/i',             'confidence' => 95,  'pfm' => null,                                'tier' => 'tier2', 'contrib' => 'SELF'],
    ['pattern' => '/EMPLOYER[\s\-]?NPS/i',        'confidence' => 95,  'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'EMPLOYER'],
    ['pattern' => '/NPS[\s\-]?EMPLOYER/i',        'confidence' => 95,  'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'EMPLOYER'],
    ['pattern' => '/\bNPS\b/i',                   'confidence' => 80,  'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/NATIONAL\s?PENSION/i',        'confidence' => 85,  'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'SELF'],
    ['pattern' => '/\bPFRDA\b/i',                 'confidence' => 90,  'pfm' => null,                                'tier' => 'tier1', 'contrib' => 'SELF'],
];

const BANK_COL_MAP = [
    'SBI'     => ['date'=>0,'narr'=>1,'debit'=>2,'credit'=>3,'bal'=>4],
    'HDFC'    => ['date'=>0,'narr'=>1,'debit'=>3,'credit'=>4,'bal'=>5],
    'ICICI'   => ['date'=>0,'narr'=>1,'debit'=>3,'credit'=>4,'bal'=>5],
    'AXIS'    => ['date'=>0,'narr'=>2,'debit'=>3,'credit'=>4,'bal'=>5],
    'KOTAK'   => ['date'=>0,'narr'=>1,'debit'=>2,'credit'=>3,'bal'=>4],
    'DEFAULT' => ['date'=>0,'narr'=>1,'debit'=>2,'credit'=>3,'bal'=>4],
];

// ── HELPERS ──────────────────────────────────────────────────────────────

function _t106_parse_date(string $raw): ?string {
    $raw = trim(preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $raw));
    $formats = ['d/m/Y','d-m-Y','Y-m-d','d/m/y','d-m-y','d M Y','d-M-Y','j/n/Y','j-n-Y','j M Y','Y/m/d','m/d/Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt) return $dt->format('Y-m-d');
    }
    $ts = @strtotime($raw);
    return ($ts && $ts > mktime(0,0,0,1,1,2000)) ? date('Y-m-d', $ts) : null;
}

function _t106_parse_amount(string $raw): float {
    $raw = preg_replace('/[₹,\s\(\)]/u', '', $raw);
    $raw = preg_replace('/[^\d.\-]/', '', $raw);
    return abs((float)$raw);
}

function _t106_detect_bank(array $headers): string {
    $h = strtolower(implode(' ', $headers));
    if (str_contains($h,'sbi')||str_contains($h,'state bank')) return 'SBI';
    if (str_contains($h,'hdfc')) return 'HDFC';
    if (str_contains($h,'icici')) return 'ICICI';
    if (str_contains($h,'axis')) return 'AXIS';
    if (str_contains($h,'kotak')) return 'KOTAK';
    return 'DEFAULT';
}

function _t106_match_nps(string $narr): ?array {
    $best = 0; $match = null; $kws = [];
    foreach (NPS_KEYWORDS as $kw) {
        if (@preg_match($kw['pattern'], $narr)) {
            $kws[] = trim($kw['pattern'], '/i');
            if ($kw['confidence'] > $best) { $best = $kw['confidence']; $match = $kw; }
        }
    }
    if (!$match) return null;
    return ['confidence'=>$best,'detected_pfm'=>$match['pfm'],'detected_tier'=>$match['tier'],'detected_contrib'=>$match['contrib'],'match_keywords'=>implode(', ',array_unique($kws))];
}

function _t106_parse_csv(string $content): array {
    $rows = [];
    foreach (preg_split('/\r?\n/', trim($content)) as $line) {
        if (trim($line)==='') continue;
        $row = str_getcsv($line,',','"','\\');
        if (count($row)<3) $row = str_getcsv($line,"\t",'"','\\');
        if (count($row)>=3) $rows[] = $row;
    }
    return $rows;
}

function _t106_fy(string $date): string {
    $mo=(int)date('n',strtotime($date)); $yr=(int)date('Y',strtotime($date));
    $fys=$mo>=4?$yr:$yr-1;
    return $fys.'-'.substr((string)($fys+1),2);
}

function _t106_is_duplicate(int $pid, string $date, float $amount): bool {
    return (bool)DB::fetchVal("SELECT id FROM nps_transactions WHERE portfolio_id=? AND txn_date=? AND ABS(amount-?)<0.5",[$pid,$date,$amount]);
}

// ── ACTIONS ──────────────────────────────────────────────────────────────

switch ($action) {

    case 'nps_import_parse': {
        require_csrf();
        $raw=''; $fname=''; $bankHint=clean($_POST['bank']??'');
        if (!empty($_FILES['statement']['tmp_name'])) {
            $fname=basename($_FILES['statement']['name']??'');
            $raw=file_get_contents($_FILES['statement']['tmp_name']);
        } elseif (!empty($_POST['statement_text'])) {
            $raw=$_POST['statement_text']; $fname='pasted_text.csv';
        }
        if (!$raw||strlen($raw)<10) json_response(false,'Bank statement content required. CSV file ya text paste karo.');
        if (strlen($raw)>5*1024*1024) json_response(false,'File too large. Max 5MB.');
        $allRows=_t106_parse_csv($raw);
        if (count($allRows)<2) json_response(false,'CSV parse fail. Minimum 2 rows (header+data) chahiye.');
        $headers=$allRows[0];
        $bank=$bankHint?:_t106_detect_bank($headers);
        $cols=BANK_COL_MAP[$bank]??BANK_COL_MAP['DEFAULT'];
        $dataRows=array_slice($allRows,1);
        $detected=[]; $from=null; $to=null;
        foreach ($dataRows as $rn=>$row) {
            while(count($row)<5) $row[]='';
            $rawDate=$row[$cols['date']]??''; $rawNarr=$row[$cols['narr']]??'';
            $rawDeb=$row[$cols['debit']]??''; $rawCred=$row[$cols['credit']]??''; $rawBal=$row[$cols['bal']]??'';
            if (!$rawDate||!$rawNarr) continue;
            $dt=_t106_parse_date($rawDate); if (!$dt) continue;
            if (!$from||$dt<$from) $from=$dt; if (!$to||$dt>$to) $to=$dt;
            $m=_t106_match_nps($rawNarr); if (!$m) continue;
            $deb=_t106_parse_amount($rawDeb); $cred=_t106_parse_amount($rawCred);
            $amt=$deb>0?$deb:$cred; if ($amt<=0) continue;
            $dup=_t106_is_duplicate($portfolioId,$dt,$amt);
            $detected[]=['raw_row_number'=>$rn+2,'raw_date'=>$rawDate,'raw_narration'=>$rawNarr,'raw_debit'=>$rawDeb,'raw_credit'=>$rawCred,'raw_balance'=>$rawBal,'txn_date'=>$dt,'amount'=>$amt,'detected_bank'=>$bank,'detected_pfm'=>$m['detected_pfm'],'detected_tier'=>$m['detected_tier'],'detected_contrib'=>$m['detected_contrib'],'confidence'=>$m['confidence'],'match_keywords'=>$m['match_keywords'],'is_duplicate'=>$dup,'investment_fy'=>_t106_fy($dt)];
        }
        if (empty($detected)) json_response(false,'Koi NPS transaction detect nahi hua. Narration mein NPS/PFRDA/NSDL keywords hone chahiye.',['total_rows'=>count($dataRows),'bank'=>$bank]);
        $sid=DB::insert("INSERT INTO nps_import_sessions (portfolio_id,user_id,bank_name,statement_from,statement_to,raw_filename,total_rows,detected_rows,status) VALUES (?,?,?,?,?,?,?,?,'pending')",[$portfolioId,$userId,$bank,$from,$to,$fname,count($dataRows),count($detected)]);
        foreach ($detected as $d) {
            DB::insert("INSERT INTO nps_import_staging (session_id,portfolio_id,raw_date,raw_narration,raw_debit,raw_credit,raw_balance,raw_row_number,txn_date,amount,detected_bank,detected_pfm,detected_tier,detected_contrib,confidence,match_keywords,tier,contribution_type,investment_fy,row_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[$sid,$portfolioId,$d['raw_date'],$d['raw_narration'],$d['raw_debit'],$d['raw_credit'],$d['raw_balance'],$d['raw_row_number'],$d['txn_date'],$d['amount'],$d['detected_bank'],$d['detected_pfm'],$d['detected_tier'],$d['detected_contrib'],$d['confidence'],$d['match_keywords'],$d['detected_tier'],$d['detected_contrib'],$d['investment_fy'],$d['is_duplicate']?'duplicate':'detected']);
        }
        $aacc=count(array_filter($detected,fn($d)=>$d['confidence']>=90&&!$d['is_duplicate']));
        $dups=count(array_filter($detected,fn($d)=>$d['is_duplicate']));
        json_response(true,count($detected).' NPS transactions detected! Review karo.',['session_id'=>(int)$sid,'bank_name'=>$bank,'statement_from'=>$from,'statement_to'=>$to,'total_rows_parsed'=>count($dataRows),'detected_count'=>count($detected),'auto_high_confidence'=>$aacc,'duplicates_found'=>$dups,'detected'=>$detected]);
    }

    case 'nps_import_staging_list': {
        $sid=(int)($_GET['session_id']??0);
        if (!$sid) json_response(false,'session_id required.');
        $sess=DB::fetchOne("SELECT * FROM nps_import_sessions WHERE id=? AND portfolio_id=?",[$sid,$portfolioId]);
        if (!$sess) json_response(false,'Session not found.',[],404);
        $rows=DB::fetchAll("SELECT s.*,ns.scheme_name,ns.pfm_name FROM nps_import_staging s LEFT JOIN nps_schemes ns ON ns.id=s.scheme_id WHERE s.session_id=? ORDER BY s.txn_date ASC",[$sid]);
        $counts=['detected'=>0,'accepted'=>0,'rejected'=>0,'duplicate'=>0,'imported'=>0];
        foreach ($rows as $r) { $k=$r['row_status']; if(isset($counts[$k])) $counts[$k]++; }
        $amt=array_sum(array_column(array_filter($rows,fn($r)=>in_array($r['row_status'],['detected','accepted'])),'amount'));
        json_response(true,'',['session'=>$sess,'rows'=>$rows,'counts'=>$counts,'total_amount'=>round($amt,2)]);
    }

    case 'nps_import_staging_update': {
        require_csrf();
        $stid=(int)($_POST['staging_id']??0); $schId=(int)($_POST['scheme_id']??0);
        $tier=clean($_POST['tier']??'tier1'); $contrib=clean($_POST['contribution_type']??'SELF');
        $units=isset($_POST['units'])&&$_POST['units']!==''?(float)$_POST['units']:null;
        $nav=isset($_POST['nav'])&&$_POST['nav']!==''?(float)$_POST['nav']:null;
        $notes=substr(clean($_POST['notes']??''),0,255);
        if (!$stid) json_response(false,'staging_id required.');
        if (!in_array($tier,['tier1','tier2'])) $tier='tier1';
        if (!in_array($contrib,['SELF','EMPLOYER'])) $contrib='SELF';
        $row=DB::fetchOne("SELECT s.* FROM nps_import_staging s WHERE s.id=? AND s.portfolio_id=?",[$stid,$portfolioId]);
        if (!$row) json_response(false,'Row not found.',[],404);
        if ($row['row_status']==='imported') json_response(false,'Already imported — cannot edit.');
        DB::run("UPDATE nps_import_staging SET scheme_id=?,tier=?,contribution_type=?,units=?,nav=?,notes=? WHERE id=?",[$schId?:null,$tier,$contrib,$units,$nav,$notes?:null,$stid]);
        json_response(true,'Row updated.',['staging_id'=>$stid]);
    }

    case 'nps_import_staging_accept': {
        require_csrf();
        $stid=(int)($_POST['staging_id']??0);
        if (!$stid) json_response(false,'staging_id required.');
        $ok=DB::run("UPDATE nps_import_staging SET row_status='accepted' WHERE id=? AND portfolio_id=? AND row_status IN ('detected','rejected','duplicate')",[$stid,$portfolioId]);
        json_response((bool)$ok,$ok?'Row accepted.':'Not found or already imported.');
    }

    case 'nps_import_staging_reject': {
        require_csrf();
        $stid=(int)($_POST['staging_id']??0);
        if (!$stid) json_response(false,'staging_id required.');
        $ok=DB::run("UPDATE nps_import_staging SET row_status='rejected' WHERE id=? AND portfolio_id=? AND row_status!='imported'",[$stid,$portfolioId]);
        json_response((bool)$ok,$ok?'Row rejected.':'Not found.');
    }

    case 'nps_import_confirm': {
        require_csrf();
        $sid=(int)($_POST['session_id']??0); $acceptAll=(bool)($_POST['accept_all']??false);
        if (!$sid) json_response(false,'session_id required.');
        $sess=DB::fetchOne("SELECT * FROM nps_import_sessions WHERE id=? AND portfolio_id=?",[$sid,$portfolioId]);
        if (!$sess) json_response(false,'Session not found.',[],404);
        if ($sess['status']==='imported') json_response(false,'Session already imported.');
        if ($acceptAll) DB::run("UPDATE nps_import_staging SET row_status='accepted' WHERE session_id=? AND row_status='detected' AND confidence>=90",[$sid]);
        $toImport=DB::fetchAll("SELECT * FROM nps_import_staging WHERE session_id=? AND row_status='accepted' ORDER BY txn_date ASC",[$sid]);
        if (empty($toImport)) json_response(false,'Koi accepted rows nahi. Pehle rows accept karo.');
        $imp=0; $skip=0; $errs=[];
        foreach ($toImport as $row) {
            try {
                if (!$row['scheme_id']) { $errs[]="Row #{$row['raw_row_number']}: scheme_id not set — skipped"; $skip++; continue; }
                if (_t106_is_duplicate($portfolioId,$row['txn_date'],(float)$row['amount'])) { DB::run("UPDATE nps_import_staging SET row_status='duplicate' WHERE id=?",[$row['id']]); $skip++; continue; }
                $fy=$row['investment_fy']?:_t106_fy($row['txn_date']);
                $txnId=DB::insert("INSERT INTO nps_transactions (portfolio_id,scheme_id,tier,contribution_type,txn_type,txn_date,units,nav,amount,investment_fy,import_source,staging_id,notes) VALUES (?,?,?,?,'purchase',?,?,?,?,?,?,?,?)",[$portfolioId,$row['scheme_id'],$row['tier'],$row['contribution_type'],$row['txn_date'],(float)($row['units']??0),(float)($row['nav']??0),(float)$row['amount'],$fy,'bank_import',$row['id'],$row['notes']?:$row['raw_narration']]);
                if (function_exists('HoldingCalculator::recalculate_nps_holding')) HoldingCalculator::recalculate_nps_holding($portfolioId,(int)$row['scheme_id'],$row['tier']);
                DB::run("UPDATE nps_import_staging SET row_status='imported',imported_txn_id=? WHERE id=?",[$txnId,$row['id']]);
                if (function_exists('audit_log')) audit_log('nps_import_confirm','nps_transactions',(int)$txnId);
                $imp++;
            } catch (\Throwable $e) { $errs[]="Row #{$row['raw_row_number']}: ".$e->getMessage(); $skip++; }
        }
        DB::run("UPDATE nps_import_sessions SET status=?,confirmed_rows=confirmed_rows+? WHERE id=?",[$imp>0?'imported':'reviewed',$imp,$sid]);
        json_response($imp>0,"{$imp} transactions import ho gayi! {$skip} skipped.",['imported'=>$imp,'skipped'=>$skip,'errors'=>$errs,'session_id'=>$sid]);
    }

    case 'nps_import_sessions_list': {
        $lim=min(50,max(5,(int)($_GET['limit']??20))); $st=clean($_GET['status']??'');
        $w="WHERE portfolio_id=?"; $p=[$portfolioId];
        if ($st) { $w.=" AND status=?"; $p[]=$st; }
        $sess=DB::fetchAll("SELECT * FROM nps_import_sessions {$w} ORDER BY created_at DESC LIMIT ?",array_merge($p,[$lim]));
        json_response(true,'',['sessions'=>$sess,'count'=>count($sess)]);
    }

    case 'nps_import_schemes': {
        $tier=clean($_GET['tier']??''); $pfm=clean($_GET['pfm']??'');
        $w="WHERE is_active=1"; $p=[];
        if ($tier) { $w.=" AND tier=?"; $p[]=$tier; }
        if ($pfm)  { $w.=" AND pfm_name LIKE ?"; $p[]="%{$pfm}%"; }
        $sc=DB::fetchAll("SELECT id,pfm_name,scheme_name,scheme_code,tier,asset_class,nav,nav_date FROM nps_schemes {$w} ORDER BY pfm_name ASC,tier ASC,asset_class ASC",$p);
        $g=[]; foreach($sc as $s) $g[$s['pfm_name']][]=$s;
        json_response(true,'',['schemes'=>$sc,'grouped_by_pfm'=>$g,'count'=>count($sc)]);
    }

    case 'nps_import_session_delete': {
        require_csrf();
        $sid=(int)($_POST['session_id']??0);
        if (!$sid) json_response(false,'session_id required.');
        $sess=DB::fetchOne("SELECT * FROM nps_import_sessions WHERE id=? AND portfolio_id=?",[$sid,$portfolioId]);
        if (!$sess) json_response(false,'Session not found.',[],404);
        if ($sess['status']==='imported') json_response(false,'Imported session delete nahi kar sakte.');
        DB::run("DELETE FROM nps_import_sessions WHERE id=?",[$sid]);
        json_response(true,'Session deleted.');
    }

    default:
        json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
