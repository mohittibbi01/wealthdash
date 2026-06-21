<?php
/**
 * WealthDash — t297: Customizable Dashboard — Widget Layout
 * File: api/dashboard/dashboard_layout.php
 * Actions: dashboard_layout_get, dashboard_layout_save, dashboard_layout_reset
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

// Available widgets registry
function _available_widgets(): array {
    return [
        ['id'=>'portfolio_value',  'title'=>'Portfolio Value',    'icon'=>'💰', 'size'=>'md', 'description'=>'Total portfolio value and gain'],
        ['id'=>'sip_summary',      'title'=>'SIP Summary',        'icon'=>'📈', 'size'=>'md', 'description'=>'Monthly SIP amount and next date'],
        ['id'=>'asset_allocation', 'title'=>'Asset Allocation',   'icon'=>'🥧', 'size'=>'lg', 'description'=>'Doughnut chart of holdings'],
        ['id'=>'top_holdings',     'title'=>'Top Holdings',       'icon'=>'📋', 'size'=>'lg', 'description'=>'Best performing funds'],
        ['id'=>'market_pulse',     'title'=>'Market Pulse',       'icon'=>'📡', 'size'=>'md', 'description'=>'Nifty 50, Sensex snapshot'],
        ['id'=>'goal_progress',    'title'=>'Goal Progress',      'icon'=>'🎯', 'size'=>'md', 'description'=>'Financial goals tracker'],
        ['id'=>'insurance_alert',  'title'=>'Insurance Alerts',   'icon'=>'🛡', 'size'=>'sm', 'description'=>'Upcoming premium dues'],
        ['id'=>'loan_emi',         'title'=>'EMI Tracker',        'icon'=>'🏦', 'size'=>'sm', 'description'=>'Upcoming EMI payments'],
        ['id'=>'sip_discipline',   'title'=>'SIP Discipline',     'icon'=>'🏅', 'size'=>'sm', 'description'=>'SIP consistency score'],
        ['id'=>'tax_estimate',     'title'=>'Tax Estimate',       'icon'=>'🧾', 'size'=>'md', 'description'=>'FY LTCG/STCG estimate'],
        ['id'=>'recent_txns',      'title'=>'Recent Transactions','icon'=>'📜', 'size'=>'lg', 'description'=>'Latest fund transactions'],
        ['id'=>'ai_insight',       'title'=>'AI Insight',         'icon'=>'🤖', 'size'=>'md', 'description'=>'One-line AI portfolio tip'],
    ];
}

// Default layout
function _default_layout(): array {
    return [
        ['id'=>'portfolio_value',  'col'=>0, 'row'=>0, 'visible'=>true],
        ['id'=>'sip_summary',      'col'=>1, 'row'=>0, 'visible'=>true],
        ['id'=>'market_pulse',     'col'=>2, 'row'=>0, 'visible'=>true],
        ['id'=>'asset_allocation', 'col'=>0, 'row'=>1, 'visible'=>true],
        ['id'=>'top_holdings',     'col'=>1, 'row'=>1, 'visible'=>true],
        ['id'=>'goal_progress',    'col'=>2, 'row'=>1, 'visible'=>true],
        ['id'=>'recent_txns',      'col'=>0, 'row'=>2, 'visible'=>true],
        ['id'=>'sip_discipline',   'col'=>1, 'row'=>2, 'visible'=>true],
        ['id'=>'insurance_alert',  'col'=>2, 'row'=>2, 'visible'=>false],
        ['id'=>'loan_emi',         'col'=>0, 'row'=>3, 'visible'=>false],
        ['id'=>'tax_estimate',     'col'=>1, 'row'=>3, 'visible'=>false],
        ['id'=>'ai_insight',       'col'=>2, 'row'=>3, 'visible'=>false],
    ];
}

switch ($action) {

    case 'dashboard_layout_get': {
        $row = DB::fetchRow("SELECT layout FROM dashboard_layouts WHERE user_id=?", [$userId]);
        $layout = $row ? json_decode($row['layout'], true) : _default_layout();
        json_response(true, 'ok', [
            'layout'   => $layout,
            'widgets'  => _available_widgets(),
        ]);
        break;
    }

    case 'dashboard_layout_save': {
        csrf_verify();
        $layout = $_POST['layout'] ?? '[]';
        json_decode($layout);
        if (json_last_error()) json_response(false, 'Invalid layout JSON.');

        $existing = DB::fetchVal("SELECT id FROM dashboard_layouts WHERE user_id=?", [$userId]);
        if ($existing) {
            DB::execute("UPDATE dashboard_layouts SET layout=?,updated_at=NOW() WHERE user_id=?", [$layout, $userId]);
        } else {
            DB::execute("INSERT INTO dashboard_layouts(user_id,layout,created_at,updated_at) VALUES(?,?,NOW(),NOW())", [$userId, $layout]);
        }
        json_response(true, 'Layout saved.');
        break;
    }

    case 'dashboard_layout_reset': {
        csrf_verify();
        $default = json_encode(_default_layout());
        $existing = DB::fetchVal("SELECT id FROM dashboard_layouts WHERE user_id=?", [$userId]);
        if ($existing) {
            DB::execute("UPDATE dashboard_layouts SET layout=?,updated_at=NOW() WHERE user_id=?", [$default, $userId]);
        } else {
            DB::execute("INSERT INTO dashboard_layouts(user_id,layout,created_at,updated_at) VALUES(?,?,NOW(),NOW())", [$userId, $default]);
        }
        json_response(true, 'Layout reset to default.');
        break;
    }

    default: json_response(false, 'Unknown action.', [], 400);
}
