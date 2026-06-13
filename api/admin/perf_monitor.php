<?php
/**
 * WealthDash — System Performance Monitor API [t308]
 * File: api/admin/perf_monitor.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
require_auth(ROLE_ADMIN);

define('PERF_SLOW_THRESHOLD_MS', 500);  // Alert if >500ms
define('PERF_LOG_SAMPLE_RATE',   1.0);  // 1.0 = 100% sampling (reduce on high traffic)

// ── Static: record a single request (called from router middleware) ──────────
function perf_record(string $action, float $startTime, int $startMem, int $statusCode = 200): void {
    if (mt_rand(1, 100) > (PERF_LOG_SAMPLE_RATE * 100)) return;

    $ms  = round((microtime(true) - $startTime) * 1000, 2);
    $mem = memory_get_usage(true) - $startMem;

    try {
        DB::run(
            'INSERT INTO perf_request_log (action, user_id, duration_ms, memory_bytes, status_code, ip_address)
             VALUES (?,?,?,?,?,?)',
            [
                $action,
                (int)($_SESSION['user_id'] ?? 0) ?: null,
                $ms, $mem > 0 ? $mem : null,
                $statusCode,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
        // Fire slow alert
        if ($ms > PERF_SLOW_THRESHOLD_MS) {
            DB::run(
                'INSERT INTO perf_slow_alerts (action, duration_ms, threshold_ms, user_id, ip_address)
                 VALUES (?,?,?,?,?)',
                [$action, $ms, PERF_SLOW_THRESHOLD_MS,
                 (int)($_SESSION['user_id'] ?? 0) ?: null,
                 $_SERVER['REMOTE_ADDR'] ?? null]
            );
        }
    } catch (Exception $e) { /* non-fatal */ }
}

switch ($action) {

    // ── LIVE STATS ────────────────────────────────────────────────────────────
    case 'perf_live': {
        // Last 5 minutes
        $recent = DB::fetchOne(
            "SELECT COUNT(*) as requests,
                    ROUND(AVG(duration_ms),2) as avg_ms,
                    ROUND(MAX(duration_ms),2) as max_ms,
                    SUM(status_code >= 400) as errors,
                    ROUND(AVG(memory_bytes)/1048576,2) as avg_mem_mb
             FROM perf_request_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );

        // Last 1 hour by minute
        $byMinute = DB::fetchAll(
            "SELECT DATE_FORMAT(created_at,'%H:%i') as minute,
                    COUNT(*) as requests,
                    ROUND(AVG(duration_ms),1) as avg_ms,
                    SUM(status_code>=400) as errors
             FROM perf_request_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
             GROUP BY minute ORDER BY minute ASC"
        );

        // Slowest endpoints (last 24h)
        $slowest = DB::fetchAll(
            "SELECT action,
                    COUNT(*) as calls,
                    ROUND(AVG(duration_ms),1) as avg_ms,
                    ROUND(MAX(duration_ms),1) as max_ms,
                    ROUND(MIN(duration_ms),1) as min_ms,
                    SUM(status_code>=400) as errors
             FROM perf_request_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY action
             ORDER BY avg_ms DESC LIMIT 20"
        );

        // Active sessions
        $sessions = (int) DB::fetchVal(
            "SELECT COUNT(*) FROM sessions WHERE last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );

        // DB stats
        $dbConns = DB::fetchOne("SHOW STATUS LIKE 'Threads_connected'")['Value'] ?? 0;
        $slowQ   = DB::fetchOne("SHOW STATUS LIKE 'Slow_queries'")['Value'] ?? 0;
        $uptime  = DB::fetchOne("SHOW STATUS LIKE 'Uptime'")['Value'] ?? 0;

        json_response(true, '', [
            'summary'   => $recent,
            'by_minute' => $byMinute,
            'slowest'   => $slowest,
            'system'    => [
                'php_memory_mb'  => round(memory_get_usage(true) / 1048576, 2),
                'php_peak_mb'    => round(memory_get_peak_usage(true) / 1048576, 2),
                'active_sessions'=> $sessions,
                'db_connections' => (int)$dbConns,
                'db_slow_queries'=> (int)$slowQ,
                'db_uptime_hrs'  => round((int)$uptime / 3600, 1),
            ],
            'ts' => date('Y-m-d H:i:s'),
        ]);
    }

    // ── HISTORICAL (hourly/daily) ─────────────────────────────────────────────
    case 'perf_history': {
        $days   = max(1, min((int)($_GET['days'] ?? 7), 90));
        $action = clean($_GET['action'] ?? '');

        $where  = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params = [$days];
        if ($action) { $where .= ' AND action = ?'; $params[] = $action; }

        $byHour = DB::fetchAll(
            "SELECT DATE_FORMAT(created_at,'%Y-%m-%d %H:00') as hour,
                    COUNT(*) as requests,
                    ROUND(AVG(duration_ms),1) as avg_ms,
                    ROUND(MAX(duration_ms),1) as max_ms,
                    SUM(status_code>=400) as errors
             FROM perf_request_log
             $where
             GROUP BY hour ORDER BY hour ASC",
            $params
        );

        $byAction = DB::fetchAll(
            "SELECT action,
                    COUNT(*) as calls,
                    ROUND(AVG(duration_ms),1) as avg_ms,
                    ROUND(MAX(duration_ms),1) as max_ms,
                    SUM(status_code>=400) as errors
             FROM perf_request_log
             $where
             GROUP BY action ORDER BY calls DESC LIMIT 30",
            $params
        );

        json_response(true, '', [
            'by_hour'   => $byHour,
            'by_action' => $byAction,
            'days'      => $days,
        ]);
    }

    // ── SLOW ALERTS ───────────────────────────────────────────────────────────
    case 'perf_slow_alerts': {
        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $rows  = DB::fetchAll(
            'SELECT psa.*, u.name, u.email FROM perf_slow_alerts psa
             LEFT JOIN users u ON u.id = psa.user_id
             ORDER BY psa.created_at DESC LIMIT ?',
            [$limit]
        );
        $threshold = PERF_SLOW_THRESHOLD_MS;
        json_response(true, '', ['alerts' => $rows, 'threshold_ms' => $threshold]);
    }

    // ── PERCENTILES (P50, P95, P99) ───────────────────────────────────────────
    case 'perf_percentiles': {
        $days   = max(1, min((int)($_GET['days'] ?? 1), 30));
        $action = clean($_GET['action'] ?? '');

        $where  = 'WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params = [$days];
        if ($action) { $where .= ' AND action = ?'; $params[] = $action; }

        // MySQL doesn't have PERCENTILE_CONT natively — simulate with subquery
        $total = (int) DB::fetchVal("SELECT COUNT(*) FROM perf_request_log $where", $params);
        if (!$total) { json_response(true, '', ['p50'=>0,'p95'=>0,'p99'=>0,'total'=>0]); }

        $p50idx = max(1, (int)($total * 0.50));
        $p95idx = max(1, (int)($total * 0.95));
        $p99idx = max(1, (int)($total * 0.99));

        $getData = function(int $idx) use ($where, $params) {
            return (float) DB::fetchVal(
                "SELECT duration_ms FROM perf_request_log $where
                 ORDER BY duration_ms ASC LIMIT 1 OFFSET ?",
                array_merge($params, [$idx - 1])
            );
        };

        json_response(true, '', [
            'p50'   => $getData($p50idx),
            'p95'   => $getData($p95idx),
            'p99'   => $getData($p99idx),
            'total' => $total,
            'days'  => $days,
        ]);
    }

    // ── PURGE OLD PERF DATA ───────────────────────────────────────────────────
    case 'perf_purge': {
        csrf_verify();
        $days = max(7, (int)($_POST['days'] ?? 30));
        $n    = (int) DB::fetchVal(
            'SELECT COUNT(*) FROM perf_request_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
        DB::run('DELETE FROM perf_request_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
        json_response(true, "Purged {$n} perf records older than {$days} days.", ['count' => $n]);
    }

    // ── ENDPOINT LIST (for filter dropdown) ───────────────────────────────────
    case 'perf_actions': {
        $rows = DB::fetchAll(
            "SELECT DISTINCT action FROM perf_request_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY action LIMIT 200"
        );
        json_response(true, '', ['actions' => array_column($rows, 'action')]);
    }

    default:
        json_response(false, "Unknown action: {$action}", [], 400);
}
