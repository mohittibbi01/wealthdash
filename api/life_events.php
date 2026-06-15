<?php
/**
 * WealthDash — t360: Life Events Calendar
 * File: api/life_events.php
 * Actions: life_events_list, life_event_add, life_event_update, life_event_delete
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {
    case 'life_events_list': {
        $rows = DB::fetchAll(
            "SELECT * FROM life_events WHERE user_id=? ORDER BY event_date ASC",
            [$userId]
        );
        json_response(true, 'ok', ['events' => $rows]);
        break;
    }
    case 'life_event_add': {
        csrf_verify();
        $name  = clean($_POST['event_name']  ?? '');
        $type  = clean($_POST['event_type']  ?? 'milestone');
        $date  = clean($_POST['event_date']  ?? '');
        $notes = clean($_POST['notes']       ?? '');
        $impact= clean($_POST['financial_impact'] ?? '');
        if (!$name || !$date) json_response(false, 'Name and date required.');
        DB::execute(
            "INSERT INTO life_events(user_id,event_name,event_type,event_date,financial_impact,notes,created_at) VALUES(?,?,?,?,?,?,NOW())",
            [$userId,$name,$type,$date,$impact,$notes]
        );
        json_response(true, 'Event added.', ['id' => DB::lastInsertId()]);
        break;
    }
    case 'life_event_update': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM life_events WHERE id=? AND user_id=?", [$id,$userId]);
        if (!$own) json_response(false, 'Not found.');
        $fields = ['event_name','event_type','event_date','financial_impact','notes'];
        $sets=[]; $params=[];
        foreach($fields as $f){if(isset($_POST[$f])){$sets[]="$f=?";$params[]=clean($_POST[$f]);}}
        if(!$sets) json_response(false,'Nothing to update.');
        $params[]=$id;
        DB::execute("UPDATE life_events SET ".implode(',',$sets)." WHERE id=?",$params);
        json_response(true,'Updated.');
        break;
    }
    case 'life_event_delete': {
        csrf_verify();
        $id=(int)($_POST['id']??0);
        $own=DB::fetchVal("SELECT id FROM life_events WHERE id=? AND user_id=?",[$id,$userId]);
        if(!$own) json_response(false,'Not found.');
        DB::execute("DELETE FROM life_events WHERE id=?",[$id]);
        json_response(true,'Deleted.');
        break;
    }
    default: json_response(false,'Unknown action.',[],400);
}
