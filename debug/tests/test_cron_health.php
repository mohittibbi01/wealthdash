<?php
/**
 * WealthDash Debug — test_cron_health.php
 * Tests: Cron files runnable, log files present, data freshness.
 */
defined('WD_DEBUG_RUNNER') or die('Direct access not allowed');

$BASE = dirname(__DIR__, 2); // debug/tests/ → debug/ → wealthdash/

// ── 1. Cron files exist and have no syntax errors ─────────────────────────
$crons = [
    'cron/update_nav_daily.php'   => 'NAV daily update',
    'cron/update_stocks_daily.php'=> 'Stocks daily update',
    'cron/nps_nav_scraper.php'    => 'NPS NAV scraper',
    'cron/fd_maturity_alert.php'  => 'FD maturity alert',
];
foreach ($crons as $rel => $label) {
    $full = $BASE . '/' . $rel;
    if (!file_exists($full)) { wd_fail('Cron', $rel, "MISSING — $label"); continue; }
    $src = @file_get_contents($full);
    $parseError = null;
    set_error_handler(function($no, $msg) use (&$parseError) { $parseError = $msg; return true; });
    try { token_get_all($src, TOKEN_PARSE); } catch (\ParseError $e) { $parseError = $e->getMessage() . ' on line ' . $e->getLine(); }
    restore_error_handler();
    if ($parseError) wd_fail('Cron', $rel, "Syntax error: $parseError");
    else              wd_pass('Cron', $rel, "Syntax OK — $label");
}

// ── 2. Check last-modified time of cron files as proxy for last run ────────
// Real last-run tracking would need a log table; this is a lightweight proxy.
foreach (array_keys($crons) as $rel) {
    $full = $BASE . '/' . $rel;
    if (!file_exists($full)) continue;
    // We look for a sibling .lastrun file written by the cron itself (optional)
    $runFile = $BASE . '/debug/lastrun/' . str_replace('/', '_', $rel) . '.txt';
    if (file_exists($runFile)) {
        $ts      = (int)trim(file_get_contents($runFile));
        $ageHrs  = round((time() - $ts) / 3600, 1);
        $timeStr = date('d-M-Y H:i', $ts);
        if ($ageHrs <= 26)
            wd_pass('Cron Last Run', $rel, "Last ran: $timeStr ({$ageHrs}h ago)");
        elseif ($ageHrs <= 72)
            wd_warn('Cron Last Run', $rel, "Last ran: $timeStr ({$ageHrs}h ago) — check schedule");
        else
            wd_fail('Cron Last Run', $rel, "Last ran: $timeStr ({$ageHrs}h ago) — cron NOT running");
    } else {
        wd_warn('Cron Last Run', $rel, "No lastrun file yet — add wd_write_lastrun() to this cron");
    }
}

// ── 3. Peak NAV processor check ───────────────────────────────────────────
try {
    $pdo = DB::conn();
    $hasPeak = $pdo->query("SHOW TABLES LIKE 'peak_nav_queue'")->rowCount() > 0;
    if ($hasPeak) {
        $r = $pdo->query("SELECT status, COUNT(*) c FROM peak_nav_queue GROUP BY status")->fetchAll();
        $map = array_column($r, 'c', 'status');
        $pending  = (int)($map['pending']  ?? 0);
        $done     = (int)($map['done']     ?? 0);
        $failed   = (int)($map['failed']   ?? 0);
        $total    = array_sum(array_map('intval', array_column($r, 'c')));
        $pct      = $total > 0 ? round($done / $total * 100) : 0;
        if ($failed > 0)
            wd_warn('Peak NAV', 'Queue status', "Done: $done/$total ($pct%) — $failed failed jobs");
        elseif ($pending > 100)
            wd_warn('Peak NAV', 'Queue status', "Done: $done/$total ($pct%) — $pending pending");
        else
            wd_pass('Peak NAV', 'Queue status', "Done: $done/$total ($pct%)");
    } else {
        wd_warn('Peak NAV', 'Queue table', 'peak_nav_queue not found — run peak_nav/setup.sql');
    }
} catch (Throwable $e) {
    wd_warn('Peak NAV', 'Check failed', $e->getMessage());
}
