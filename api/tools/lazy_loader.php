<?php
/**
 * WealthDash — tp002: Lazy Loading — Heavy Calculations On Demand
 * File: api/tools/lazy_loader.php
 *
 * Actions: lazy_xirr, lazy_portfolio_summary, lazy_sip_analysis,
 *          lazy_goal_progress, lazy_tax_estimate, lazy_widget
 *
 * Each action computes one expensive value, caches it, and returns it.
 * Frontend calls these after initial page paint.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

require_once APP_ROOT . '/includes/wd_cache.php';

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    // ── Portfolio XIRR (most expensive) ──────────────────────────
    case 'lazy_xirr': {
        $cacheKey = "wd:u{$userId}:p{$portfolioId}:portfolio:xirr";
        $cached   = WDCache::get($cacheKey);
        if ($cached !== null) {
            json_response(true, 'ok', $cached + ['_cached' => true]);
            break;
        }

        // Fetch all transactions for XIRR computation
        $txns = DB::fetchAll(
            "SELECT txn_date, txn_type, amount, units
             FROM mf_transactions
             WHERE user_id=? AND portfolio_id=?
             ORDER BY txn_date ASC",
            [$userId, $portfolioId]
        );

        // Current portfolio value
        $currentValue = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(h.units * n.nav), 0)
             FROM mf_holdings h
             JOIN mf_nav_latest n ON n.mf_id = h.mf_id
             WHERE h.user_id=? AND h.portfolio_id=?",
            [$userId, $portfolioId]
        ) ?? 0);

        $xirr = _compute_xirr($txns, $currentValue);

        $result = [
            'xirr_pct'     => $xirr,
            'current_value'=> round($currentValue, 2),
            'txn_count'    => count($txns),
        ];
        WDCache::set($cacheKey, $result, 1800);
        json_response(true, 'ok', $result + ['_cached' => false]);
        break;
    }

    // ── Full portfolio summary ─────────────────────────────────────
    case 'lazy_portfolio_summary': {
        $cacheKey = "wd:u{$userId}:p{$portfolioId}:portfolio:summary";
        $cached   = WDCache::get($cacheKey);
        if ($cached !== null) { json_response(true, 'ok', $cached + ['_cached' => true]); break; }

        $invested = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(CASE WHEN txn_type='purchase' OR txn_type='sip' THEN amount ELSE -amount END), 0)
             FROM mf_transactions WHERE user_id=? AND portfolio_id=?",
            [$userId, $portfolioId]
        ) ?? 0);

        $currentValue = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(h.units * n.nav), 0)
             FROM mf_holdings h
             JOIN mf_nav_latest n ON n.mf_id = h.mf_id
             WHERE h.user_id=? AND h.portfolio_id=?",
            [$userId, $portfolioId]
        ) ?? 0);

        $gain      = $currentValue - $invested;
        $gainPct   = $invested > 0 ? round(($gain / $invested) * 100, 2) : 0;

        $holdingsCount = (int)(DB::fetchVal(
            "SELECT COUNT(*) FROM mf_holdings WHERE user_id=? AND portfolio_id=? AND units > 0",
            [$userId, $portfolioId]
        ) ?? 0);

        $activeSIPs = (int)(DB::fetchVal(
            "SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",
            [$userId, $portfolioId]
        ) ?? 0);

        $result = [
            'invested'       => round($invested, 2),
            'current_value'  => round($currentValue, 2),
            'gain'           => round($gain, 2),
            'gain_pct'       => $gainPct,
            'holdings_count' => $holdingsCount,
            'active_sips'    => $activeSIPs,
        ];
        WDCache::set($cacheKey, $result, 300);
        json_response(true, 'ok', $result + ['_cached' => false]);
        break;
    }

    // ── SIP analysis (monthly committed, next SIP dates) ──────────
    case 'lazy_sip_analysis': {
        $cacheKey = "wd:u{$userId}:p{$portfolioId}:sip:analysis";
        $cached   = WDCache::get($cacheKey);
        if ($cached !== null) { json_response(true, 'ok', $cached + ['_cached' => true]); break; }

        $sips = DB::fetchAll(
            "SELECT sip_amount, sip_date, sip_frequency, fund_name
             FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",
            [$userId, $portfolioId]
        );

        $monthlyTotal = 0;
        $nextDates    = [];
        $today        = date('Y-m-d');
        $thisMonth    = date('Y-m');

        foreach ($sips as $s) {
            $freq = strtolower($s['sip_frequency'] ?? 'monthly');
            $mul  = match($freq) { 'weekly' => 4.33, 'fortnightly' => 2, 'quarterly' => 0.33, 'yearly' => 0.083, default => 1 };
            $monthlyTotal += (float)$s['sip_amount'] * $mul;

            $dom  = (int)($s['sip_date'] ?? 1);
            $next = $thisMonth . '-' . str_pad($dom, 2, '0', STR_PAD_LEFT);
            if ($next < $today) {
                $d = new DateTime($next);
                $d->modify('+1 month');
                $next = $d->format('Y-m-d');
            }
            $nextDates[] = ['fund' => $s['fund_name'], 'date' => $next, 'amount' => (float)$s['sip_amount']];
        }
        usort($nextDates, fn($a, $b) => strcmp($a['date'], $b['date']));

        $result = [
            'monthly_total' => round($monthlyTotal, 2),
            'sip_count'     => count($sips),
            'next_dates'    => array_slice($nextDates, 0, 5),
        ];
        WDCache::set($cacheKey, $result, 600);
        json_response(true, 'ok', $result + ['_cached' => false]);
        break;
    }

    // ── Goal progress overview ─────────────────────────────────────
    case 'lazy_goal_progress': {
        $cacheKey = "wd:u{$userId}:p{$portfolioId}:goals:progress";
        $cached   = WDCache::get($cacheKey);
        if ($cached !== null) { json_response(true, 'ok', $cached + ['_cached' => true]); break; }

        $goals = DB::fetchAll(
            "SELECT g.id, g.goal_name, g.target_amount, g.target_date,
                    COALESCE(SUM(gc.amount),0) AS invested
             FROM goals g
             LEFT JOIN goal_checkins gc ON gc.goal_id = g.id
             WHERE g.user_id=? AND g.status='active'
             GROUP BY g.id",
            [$userId]
        );

        $on_track = 0; $behind = 0;
        foreach ($goals as &$g) {
            $g['target_amount'] = (float)$g['target_amount'];
            $g['invested']      = (float)$g['invested'];
            $g['pct']           = $g['target_amount'] > 0 ? round(($g['invested']/$g['target_amount'])*100,1) : 0;
            // Simple on-track check: days elapsed ratio vs amount ratio
            $totalDays   = max(1, (strtotime($g['target_date']) - strtotime(date('Y') . '-01-01')) / 86400);
            $elapsedDays = max(0, (time() - strtotime(date('Y') . '-01-01')) / 86400);
            $timeRatio   = min(1, $elapsedDays / $totalDays);
            $amtRatio    = $g['target_amount'] > 0 ? $g['invested'] / $g['target_amount'] : 0;
            $g['status'] = $amtRatio >= $timeRatio ? 'on_track' : 'behind';
            if ($g['status'] === 'on_track') $on_track++; else $behind++;
        }

        $result = [
            'goals'    => $goals,
            'on_track' => $on_track,
            'behind'   => $behind,
            'total'    => count($goals),
        ];
        WDCache::set($cacheKey, $result, 600);
        json_response(true, 'ok', $result + ['_cached' => false]);
        break;
    }

    // ── Tax estimate (LTCG/STCG rough calculation) ────────────────
    case 'lazy_tax_estimate': {
        $fy       = clean($_GET['fy'] ?? date('Y') . '-' . substr(date('Y')+1,-2));
        $cacheKey = "wd:u{$userId}:p{$portfolioId}:tax:estimate:{$fy}";
        $cached   = WDCache::get($cacheKey);
        if ($cached !== null) { json_response(true, 'ok', $cached + ['_cached' => true]); break; }

        preg_match('/^(\d{4})-(\d{2})$/', $fy, $m);
        $fyStart = $m[1] . '-04-01';
        $fyEnd   = '20' . $m[2] . '-03-31';

        // Redemptions in FY
        $redemptions = DB::fetchAll(
            "SELECT t.txn_date, t.amount, t.units, t.mf_id,
                    h.avg_cost_per_unit
             FROM mf_transactions t
             LEFT JOIN mf_holdings h ON h.mf_id=t.mf_id AND h.user_id=t.user_id
             WHERE t.user_id=? AND t.portfolio_id=?
               AND t.txn_type='redemption'
               AND t.txn_date BETWEEN ? AND ?",
            [$userId, $portfolioId, $fyStart, $fyEnd]
        );

        $ltcg = 0; $stcg = 0;
        foreach ($redemptions as $r) {
            $gain     = (float)$r['amount'] - ((float)($r['avg_cost_per_unit'] ?? 0) * (float)$r['units']);
            // Simplified: assume LTCG if holding > 1 year (would need purchase date for accuracy)
            $ltcg += max(0, $gain);
        }
        $ltcg_taxable = max(0, $ltcg - 125000); // ₹1.25L exemption
        $ltcg_tax     = round($ltcg_taxable * 0.125, 2); // 12.5%
        $stcg_tax     = round($stcg * 0.20, 2); // 20%

        $result = [
            'fy'           => $fy,
            'ltcg'         => round($ltcg, 2),
            'stcg'         => round($stcg, 2),
            'ltcg_taxable' => round($ltcg_taxable, 2),
            'ltcg_tax'     => $ltcg_tax,
            'stcg_tax'     => $stcg_tax,
            'total_tax'    => round($ltcg_tax + $stcg_tax, 2),
            'disclaimer'   => 'Estimated. Consult a CA for accurate tax computation.',
        ];
        WDCache::set($cacheKey, $result, 3600);
        json_response(true, 'ok', $result + ['_cached' => false]);
        break;
    }

    default:
        json_response(false, 'Unknown action.', [], 400);
}

// ── Simple XIRR approximation (Newton-Raphson) ─────────────────────
function _compute_xirr(array $txns, float $currentValue): float {
    if (empty($txns) || $currentValue <= 0) return 0.0;

    // Build cashflows: negative for investments, positive for redemptions
    $cashflows = [];
    foreach ($txns as $t) {
        $sign  = in_array($t['txn_type'], ['purchase','sip']) ? -1 : 1;
        $cashflows[] = ['date' => $t['txn_date'], 'amount' => $sign * (float)$t['amount']];
    }
    // Final value as positive cashflow today
    $cashflows[] = ['date' => date('Y-m-d'), 'amount' => $currentValue];

    $baseDate = strtotime($cashflows[0]['date']);
    $rate = 0.1; // initial guess
    for ($iter = 0; $iter < 100; $iter++) {
        $f = 0; $df = 0;
        foreach ($cashflows as $cf) {
            $t = (strtotime($cf['date']) - $baseDate) / (365.25 * 86400);
            $d = pow(1 + $rate, $t);
            if ($d == 0) continue;
            $f  += $cf['amount'] / $d;
            $df -= $t * $cf['amount'] / ($d * (1 + $rate));
        }
        if (abs($df) < 1e-10) break;
        $newRate = $rate - $f / $df;
        if (abs($newRate - $rate) < 1e-7) { $rate = $newRate; break; }
        $rate = $newRate;
        if ($rate < -0.99) $rate = -0.99;
    }
    return round($rate * 100, 2);
}
