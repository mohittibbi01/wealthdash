<?php
/**
 * WealthDash — t55: Dashboard Widget Customizer
 * File: api/dashboard/widget_customizer.php
 * Actions: widget_layout_get, widget_layout_save, widget_layout_reset,
 *          widget_data_get (fetch data for a single widget by id)
 *
 * Extends/replaces t297 (dashboard_layout.php) with richer widget registry
 * and per-widget config support (chart type, date range, etc.)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

function _widget_registry(): array {
    return [
        // id, title, icon, size(sm/md/lg), category, description
        ['id'=>'portfolio_value',   'title'=>'Portfolio Value',      'icon'=>'💰','size'=>'md','cat'=>'portfolio','desc'=>'Total value + gain/loss'],
        ['id'=>'portfolio_chart',   'title'=>'Portfolio Chart',      'icon'=>'📈','size'=>'lg','cat'=>'portfolio','desc'=>'Value over time line chart'],
        ['id'=>'asset_allocation',  'title'=>'Asset Allocation',     'icon'=>'🥧','size'=>'lg','cat'=>'portfolio','desc'=>'Doughnut chart of categories'],
        ['id'=>'top_holdings',      'title'=>'Top Holdings',         'icon'=>'📋','size'=>'lg','cat'=>'portfolio','desc'=>'Best performing funds'],
        ['id'=>'sip_summary',       'title'=>'SIP Summary',          'icon'=>'🔁','size'=>'md','cat'=>'sip',      'desc'=>'Monthly SIP total + next dates'],
        ['id'=>'sip_calendar',      'title'=>'SIP Calendar',         'icon'=>'📅','size'=>'md','cat'=>'sip',      'desc'=>'Upcoming SIP due dates'],
        ['id'=>'market_pulse',      'title'=>'Market Pulse',         'icon'=>'📡','size'=>'md','cat'=>'market',   'desc'=>'Nifty, Sensex, Gold, USD/INR'],
        ['id'=>'goal_progress',     'title'=>'Goal Progress',        'icon'=>'🎯','size'=>'lg','cat'=>'goals',    'desc'=>'Progress rings for active goals'],
        ['id'=>'goal_coach_nudges', 'title'=>'Goal Coach',           'icon'=>'🧑‍🏫','size'=>'md','cat'=>'goals',   'desc'=>'AI daily goal nudges'],
        ['id'=>'net_worth',         'title'=>'Net Worth',            'icon'=>'💎','size'=>'md','cat'=>'portfolio','desc'=>'Total assets minus liabilities'],
        ['id'=>'tax_estimate',      'title'=>'Tax Estimate',         'icon'=>'🧾','size'=>'md','cat'=>'tax',      'desc'=>'FY LTCG/STCG estimate'],
        ['id'=>'insurance_alerts',  'title'=>'Insurance Alerts',     'icon'=>'🛡', 'size'=>'sm','cat'=>'insurance','desc'=>'Premium due reminders'],
        ['id'=>'loan_emi',          'title'=>'EMI Tracker',          'icon'=>'🏦','size'=>'sm','cat'=>'loans',    'desc'=>'Next EMI dates'],
        ['id'=>'morning_briefing',  'title'=>'Morning Briefing',     'icon'=>'☀️','size'=>'md','cat'=>'ai',       'desc'=>'Daily AI market + portfolio digest'],
        ['id'=>'ai_report_card',    'title'=>'Portfolio Report Card','icon'=>'📋','size'=>'md','cat'=>'ai',       'desc'=>'Monthly AI grade'],
        ['id'=>'anomaly_alerts',    'title'=>'Anomaly Alerts',       'icon'=>'🔍','size'=>'sm','cat'=>'ai',       'desc'=>'Unusual transaction flags'],
        ['id'=>'property_value',    'title'=>'Property Value',       'icon'=>'🏠','size'=>'md','cat'=>'property', 'desc'=>'Real estate portfolio total'],
        ['id'=>'budget_snapshot',   'title'=>'Budget Snapshot',      'icon'=>'💹','size'=>'md','cat'=>'budget',   'desc'=>'This month income vs expenses'],
        ['id'=>'recent_txns',       'title'=>'Recent Transactions',  'icon'=>'📜','size'=>'lg','cat'=>'portfolio','desc'=>'Latest fund transactions'],
    ];
}

function _default_layout(): array {
    return [
        ['id'=>'portfolio_value','visible'=>true,'row'=>0,'col'=>0],
        ['id'=>'sip_summary',   'visible'=>true,'row'=>0,'col'=>1],
        ['id'=>'market_pulse',  'visible'=>true,'row'=>0,'col'=>2],
        ['id'=>'asset_allocation','visible'=>true,'row'=>1,'col'=>0],
        ['id'=>'goal_progress', 'visible'=>true,'row'=>1,'col'=>1],
        ['id'=>'morning_briefing','visible'=>true,'row'=>1,'col'=>2],
        ['id'=>'top_holdings',  'visible'=>true,'row'=>2,'col'=>0],
        ['id'=>'recent_txns',   'visible'=>true,'row'=>2,'col'=>1],
        ['id'=>'tax_estimate',  'visible'=>false,'row'=>3,'col'=>0],
        ['id'=>'insurance_alerts','visible'=>false,'row'=>3,'col'=>1],
        ['id'=>'loan_emi',      'visible'=>false,'row'=>3,'col'=>2],
        ['id'=>'budget_snapshot','visible'=>false,'row'=>4,'col'=>0],
        ['id'=>'portfolio_chart','visible'=>false,'row'=>4,'col'=>1],
        ['id'=>'net_worth',     'visible'=>false,'row'=>4,'col'=>2],
        ['id'=>'goal_coach_nudges','visible'=>false,'row'=>5,'col'=>0],
        ['id'=>'ai_report_card','visible'=>false,'row'=>5,'col'=>1],
        ['id'=>'anomaly_alerts','visible'=>false,'row'=>5,'col'=>2],
        ['id'=>'property_value','visible'=>false,'row'=>6,'col'=>0],
    ];
}

switch ($action) {

    case 'widget_layout_get': {
        $row = DB::fetchRow("SELECT layout_json FROM dashboard_widget_layouts WHERE user_id=?", [$userId]);
        $layout = $row ? json_decode($row['layout_json'], true) : _default_layout();
        json_response(true,'ok',['layout'=>$layout,'registry'=>_widget_registry()]);
        break;
    }

    case 'widget_layout_save': {
        csrf_verify();
        $json = $_POST['layout'] ?? '[]';
        json_decode($json); if (json_last_error()) json_response(false,'Invalid JSON.');
        $ex = DB::fetchVal("SELECT id FROM dashboard_widget_layouts WHERE user_id=?", [$userId]);
        if ($ex) DB::execute("UPDATE dashboard_widget_layouts SET layout_json=?,updated_at=NOW() WHERE id=?", [$json,$ex]);
        else     DB::execute("INSERT INTO dashboard_widget_layouts(user_id,layout_json,created_at,updated_at) VALUES(?,?,NOW(),NOW())", [$userId,$json]);
        json_response(true,'Layout saved.');
        break;
    }

    case 'widget_layout_reset': {
        csrf_verify();
        $json = json_encode(_default_layout());
        $ex = DB::fetchVal("SELECT id FROM dashboard_widget_layouts WHERE user_id=?", [$userId]);
        if ($ex) DB::execute("UPDATE dashboard_widget_layouts SET layout_json=?,updated_at=NOW() WHERE id=?", [$json,$ex]);
        else     DB::execute("INSERT INTO dashboard_widget_layouts(user_id,layout_json,created_at,updated_at) VALUES(?,?,NOW(),NOW())", [$userId,$json]);
        json_response(true,'Reset to default.');
        break;
    }

    // ── Fetch live data for a single widget ──────────────────────────
    case 'widget_data_get': {
        $widgetId = clean($_GET['widget_id'] ?? '');
        $result   = [];

        switch ($widgetId) {
            case 'portfolio_value': {
                $v  = (float)(DB::fetchVal("SELECT COALESCE(SUM(h.units*COALESCE(n.nav,h.avg_cost_per_unit)),0) FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0", [$userId,$portfolioId])??0);
                $inv= (float)(DB::fetchVal("SELECT COALESCE(SUM(h.units*h.avg_cost_per_unit),0) FROM mf_holdings h WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0", [$userId,$portfolioId])??0);
                $result = ['value'=>round($v),'gain'=>round($v-$inv),'gain_pct'=>$inv>0?round(($v-$inv)/$inv*100,2):0];
                break;
            }
            case 'sip_summary': {
                $count = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId])??0);
                $total = (float)(DB::fetchVal("SELECT COALESCE(SUM(sip_amount),0) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId])??0);
                $result = ['count'=>$count,'monthly_total'=>round($total)];
                break;
            }
            case 'tax_estimate': {
                $v  = (float)(DB::fetchVal("SELECT COALESCE(SUM(h.units*COALESCE(n.nav,h.avg_cost_per_unit)),0) FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",[$userId,$portfolioId])??0);
                $inv= (float)(DB::fetchVal("SELECT COALESCE(SUM(h.units*h.avg_cost_per_unit),0) FROM mf_holdings h WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",[$userId,$portfolioId])??0);
                $gain = max(0,$v-$inv); $taxable = max(0,$gain-125000); $tax = round($taxable*0.125,2);
                $result = ['unrealised_gain'=>round($gain),'taxable'=>round($taxable),'estimated_tax'=>$tax];
                break;
            }
            case 'insurance_alerts': {
                $rows = DB::fetchAll("SELECT policy_name,premium_amount,next_premium_date FROM insurance_policies WHERE user_id=? AND status='active' AND next_premium_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) ORDER BY next_premium_date ASC LIMIT 5",[$userId]);
                $result = ['upcoming'=>$rows,'count'=>count($rows)];
                break;
            }
            case 'budget_snapshot': {
                $month = date('Y-m');
                $ia = (float)(DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM budget_actuals WHERE user_id=? AND txn_type='income' AND DATE_FORMAT(txn_date,'%Y-%m')=?",[$userId,$month])??0);
                $ea = (float)(DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM budget_actuals WHERE user_id=? AND txn_type='expense' AND DATE_FORMAT(txn_date,'%Y-%m')=?",[$userId,$month])??0);
                $result = ['income'=>round($ia),'expenses'=>round($ea),'savings'=>round($ia-$ea),'month'=>$month];
                break;
            }
            case 'property_value': {
                $v  = (float)(DB::fetchVal("SELECT COALESCE(SUM(COALESCE((SELECT pv.current_value FROM property_valuations pv WHERE pv.property_id=p.id ORDER BY pv.valuation_date DESC LIMIT 1),p.purchase_price)),0) FROM properties p WHERE p.user_id=? AND p.status='active'",[$userId])??0);
                $tl = (float)(DB::fetchVal("SELECT COALESCE(SUM(loan_outstanding),0) FROM properties WHERE user_id=? AND status='active'",[$userId])??0);
                $result = ['total_value'=>round($v),'total_equity'=>round($v-$tl)];
                break;
            }
            default: $result = ['widget_id'=>$widgetId,'message'=>'No live data — load via dedicated API'];
        }
        json_response(true,'ok',['widget_id'=>$widgetId,'data'=>$result]);
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
