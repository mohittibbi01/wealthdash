<?php
/**
 * WealthDash — t471: Monthly Budget Tracker API
 * File: api/budget/budget.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

function _def_cats(): array {
    return [
        ['name'=>'Salary',           'type'=>'income', 'icon'=>'💼','color'=>'#16a34a'],
        ['name'=>'Business Income',  'type'=>'income', 'icon'=>'🏢','color'=>'#059669'],
        ['name'=>'Rental Income',    'type'=>'income', 'icon'=>'🏠','color'=>'#0891b2'],
        ['name'=>'Other Income',     'type'=>'income', 'icon'=>'💰','color'=>'#65a30d'],
        ['name'=>'Housing/Rent',     'type'=>'expense','icon'=>'🏠','color'=>'#dc2626'],
        ['name'=>'Groceries',        'type'=>'expense','icon'=>'🛒','color'=>'#ea580c'],
        ['name'=>'Transport',        'type'=>'expense','icon'=>'🚗','color'=>'#d97706'],
        ['name'=>'Utilities',        'type'=>'expense','icon'=>'💡','color'=>'#ca8a04'],
        ['name'=>'Insurance EMI',    'type'=>'expense','icon'=>'🛡','color'=>'#7c3aed'],
        ['name'=>'Loan EMI',         'type'=>'expense','icon'=>'🏦','color'=>'#6d28d9'],
        ['name'=>'Investments/SIP',  'type'=>'expense','icon'=>'📈','color'=>'#2563eb'],
        ['name'=>'Entertainment',    'type'=>'expense','icon'=>'🎬','color'=>'#be185d'],
        ['name'=>'Healthcare',       'type'=>'expense','icon'=>'🏥','color'=>'#e11d48'],
        ['name'=>'Education',        'type'=>'expense','icon'=>'📚','color'=>'#0891b2'],
        ['name'=>'Shopping',         'type'=>'expense','icon'=>'🛍','color'=>'#c026d3'],
        ['name'=>'Dining Out',       'type'=>'expense','icon'=>'🍽','color'=>'#f97316'],
        ['name'=>'Savings',          'type'=>'savings','icon'=>'🏦','color'=>'#0ea5e9'],
        ['name'=>'Other Expense',    'type'=>'expense','icon'=>'📦','color'=>'#6b7280'],
    ];
}

switch ($action) {
    case 'budget_get': {
        $month = clean($_GET['month'] ?? date('Y-m'));
        $row   = DB::fetchRow("SELECT plan_json FROM budget_plans WHERE user_id=? AND month=?", [$userId,$month]);
        json_response(true,'ok',['month'=>$month,'plan'=>$row?json_decode($row['plan_json']??'{}',true):[]]);
        break;
    }
    case 'budget_save': {
        csrf_verify();
        $month = clean($_POST['month'] ?? date('Y-m'));
        $pj    = $_POST['plan'] ?? '{}';
        json_decode($pj); if (json_last_error()) json_response(false,'Invalid JSON.');
        $ex = DB::fetchVal("SELECT id FROM budget_plans WHERE user_id=? AND month=?",[$userId,$month]);
        if ($ex) DB::execute("UPDATE budget_plans SET plan_json=?,updated_at=NOW() WHERE id=?",[$pj,$ex]);
        else     DB::execute("INSERT INTO budget_plans(user_id,month,plan_json,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())",[$userId,$month,$pj]);
        json_response(true,'Budget saved.');
        break;
    }
    case 'budget_actual_list': {
        $month = clean($_GET['month'] ?? date('Y-m'));
        $rows  = DB::fetchAll("SELECT * FROM budget_actuals WHERE user_id=? AND DATE_FORMAT(txn_date,'%Y-%m')=? ORDER BY txn_date DESC",[$userId,$month]);
        json_response(true,'ok',['actuals'=>$rows]);
        break;
    }
    case 'budget_actual_add': {
        csrf_verify();
        $cat  = clean($_POST['category']    ?? '');
        $type = clean($_POST['txn_type']    ?? 'expense');
        $amt  = (float)($_POST['amount']    ?? 0);
        $td   = clean($_POST['txn_date']    ?? date('Y-m-d'));
        $desc = clean($_POST['description'] ?? '');
        if (!$cat || $amt <= 0) json_response(false,'Category and amount required.');
        DB::execute("INSERT INTO budget_actuals(user_id,category,txn_type,amount,txn_date,description,created_at) VALUES(?,?,?,?,?,?,NOW())",[$userId,$cat,$type,$amt,$td,$desc]);
        json_response(true,'Added.',['id'=>DB::lastInsertId()]);
        break;
    }
    case 'budget_actual_update': {
        csrf_verify();
        $id  = (int)($_POST['id']  ?? 0);
        $own = DB::fetchVal("SELECT id FROM budget_actuals WHERE id=? AND user_id=?",[$id,$userId]);
        if (!$own) json_response(false,'Not found.');
        $sets=[]; $params=[];
        foreach(['category','txn_type','amount','txn_date','description'] as $f) {
            if (isset($_POST[$f])) { $sets[]="$f=?"; $params[]=clean($_POST[$f]); }
        }
        if (!$sets) json_response(false,'Nothing to update.');
        $params[] = $id;
        DB::execute("UPDATE budget_actuals SET ".implode(',',$sets)." WHERE id=?",$params);
        json_response(true,'Updated.');
        break;
    }
    case 'budget_actual_delete': {
        csrf_verify();
        $id  = (int)($_POST['id']  ?? 0);
        $own = DB::fetchVal("SELECT id FROM budget_actuals WHERE id=? AND user_id=?",[$id,$userId]);
        if (!$own) json_response(false,'Not found.');
        DB::execute("DELETE FROM budget_actuals WHERE id=?",[$id]);
        json_response(true,'Deleted.');
        break;
    }
    case 'budget_summary': {
        $month = clean($_GET['month'] ?? date('Y-m'));
        $pr    = DB::fetchRow("SELECT plan_json FROM budget_plans WHERE user_id=? AND month=?",[$userId,$month]);
        $plan  = $pr ? json_decode($pr['plan_json']??'{}',true) : [];
        $acts  = DB::fetchAll("SELECT category,txn_type,SUM(amount) AS total FROM budget_actuals WHERE user_id=? AND DATE_FORMAT(txn_date,'%Y-%m')=? GROUP BY category,txn_type",[$userId,$month]);
        $am = []; foreach($acts as $a) $am[$a['category']] = (float)$a['total'];
        $cats = _def_cats(); $summary = [];
        foreach ($cats as $c) {
            $b   = (float)($plan[$c['name']] ?? 0);
            $act = $am[$c['name']] ?? 0;
            $summary[] = ['name'=>$c['name'],'type'=>$c['type'],'icon'=>$c['icon'],'color'=>$c['color'],'budgeted'=>$b,'actual'=>$act,'variance'=>$c['type']==='expense'?$b-$act:$act-$b,'variance_pct'=>$b>0?round($act/$b*100-100,1):null];
        }
        $ip = array_sum(array_column(array_filter($summary,fn($s)=>$s['type']==='income'),'budgeted'));
        $ia = array_sum(array_column(array_filter($summary,fn($s)=>$s['type']==='income'),'actual'));
        $ep = array_sum(array_column(array_filter($summary,fn($s)=>$s['type']==='expense'),'budgeted'));
        $ea = array_sum(array_column(array_filter($summary,fn($s)=>$s['type']==='expense'),'actual'));
        json_response(true,'ok',['month'=>$month,'summary'=>array_values(array_filter($summary,fn($s)=>$s['budgeted']>0||$s['actual']>0)),'income_planned'=>round($ip,2),'income_actual'=>round($ia,2),'expense_planned'=>round($ep,2),'expense_actual'=>round($ea,2),'savings'=>round($ia-$ea,2),'savings_rate'=>$ia>0?round(($ia-$ea)/$ia*100,1):0]);
        break;
    }
    case 'budget_categories_list': {
        json_response(true,'ok',['categories'=>_def_cats()]);
        break;
    }
    default: json_response(false,'Unknown action.',[],400);
}
