<?php
/**
 * WealthDash — t373: Widget Mode — Small Dashboard Cards
 * File: api/dashboard/widget_mode.php
 * Actions: widget_mode_render, widget_mode_list
 *
 * Provides compact, embeddable card snapshots (designed for small spaces:
 * browser extension popup, home-screen widget API, partner embed, etc.)
 * Returns minimal JSON optimized for small-screen rendering.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

function _available_widget_modes(): array {
    return [
        ['id'=>'portfolio_mini',  'title'=>'Portfolio',      'icon'=>'💰'],
        ['id'=>'sip_mini',        'title'=>'SIPs',           'icon'=>'🔁'],
        ['id'=>'goal_mini',       'title'=>'Top Goal',       'icon'=>'🎯'],
        ['id'=>'market_mini',     'title'=>'Market',         'icon'=>'📡'],
        ['id'=>'streak_mini',     'title'=>'Streak',         'icon'=>'🔥'],
    ];
}

switch ($action) {

    case 'widget_mode_list': {
        json_response(true,'ok',['modes'=>_available_widget_modes()]);
        break;
    }

    // ── Render a single compact widget's data ────────────────────────
    case 'widget_mode_render': {
        $mode = clean($_GET['mode'] ?? 'portfolio_mini');
        $data = [];

        switch ($mode) {
            case 'portfolio_mini': {
                $v = (float)(DB::fetchVal("SELECT COALESCE(SUM(h.units*COALESCE(n.nav,h.avg_cost_per_unit)),0) FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",[$userId,$portfolioId])??0);
                $inv=(float)(DB::fetchVal("SELECT COALESCE(SUM(h.units*h.avg_cost_per_unit),0) FROM mf_holdings h WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",[$userId,$portfolioId])??0);
                $data = ['label'=>'Portfolio Value','value'=>round($v),'sub'=>($inv>0?round(($v-$inv)/$inv*100,1):0).'%','sub_positive'=>$v>=$inv];
                break;
            }
            case 'sip_mini': {
                $count=(int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId])??0);
                $total=(float)(DB::fetchVal("SELECT COALESCE(SUM(sip_amount),0) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId])??0);
                $data = ['label'=>'Active SIPs','value'=>$count,'sub'=>formatINR_w373($total).'/mo','sub_positive'=>true];
                break;
            }
            case 'goal_mini': {
                $g = DB::fetchRow("SELECT goal_name,target_amount,(SELECT COALESCE(SUM(amount),0) FROM goal_checkins WHERE goal_id=g.id) AS invested FROM goals g WHERE user_id=? AND status='active' ORDER BY target_date ASC LIMIT 1",[$userId]);
                if ($g) {
                    $pct = (float)$g['target_amount']>0 ? round((float)$g['invested']/(float)$g['target_amount']*100,1) : 0;
                    $data = ['label'=>$g['goal_name'],'value'=>$pct.'%','sub'=>'of '.formatINR_w373((float)$g['target_amount']),'sub_positive'=>true];
                } else {
                    $data = ['label'=>'No Goals','value'=>'—','sub'=>'Set a goal','sub_positive'=>true];
                }
                break;
            }
            case 'market_mini': {
                $cached = $_SESSION['market_pulse_cache'] ?? [];
                $first = $cached[0] ?? ['name'=>'Nifty 50','value'=>'—','change_pct'=>0];
                $data = ['label'=>$first['name'],'value'=>$first['value'],'sub'=>($first['change_pct']>=0?'+':'').$first['change_pct'].'%','sub_positive'=>$first['change_pct']>=0];
                break;
            }
            case 'streak_mini': {
                $streak = (int)(DB::fetchVal("SELECT current_streak FROM investor_streaks WHERE user_id=?",[$userId]) ?? 0);
                $data = ['label'=>'Investing Streak','value'=>$streak.' mo','sub'=>'🔥 Keep it up!','sub_positive'=>true];
                break;
            }
            default: $data = ['label'=>'Unknown','value'=>'—','sub'=>'','sub_positive'=>true];
        }

        json_response(true,'ok',['mode'=>$mode,'data'=>$data]);
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}

function formatINR_w373(float $v): string {
    $a = abs($v);
    if ($a >= 1e7) return '₹'.number_format($a/1e7,1).'Cr';
    if ($a >= 1e5) return '₹'.number_format($a/1e5,1).'L';
    if ($a >= 1e3) return '₹'.number_format($a/1e3,1).'K';
    return '₹'.number_format($a,0);
}
