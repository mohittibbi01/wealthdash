<?php
/**
 * WealthDash — t51: System Health Dashboard
 * File: api/admin/system_health.php
 * Actions: admin_stats, admin_system_health
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
if (!$isAdmin) json_response(false, 'Admin access required.', [], 403);

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {

    case 'admin_stats':
    case 'admin_system_health': {

        // ── Users ─────────────────────────────────────────────────
        $users = DB::fetchRow(
            "SELECT COUNT(*) AS total,
                    SUM(status='active')    AS active,
                    SUM(status='suspended') AS suspended,
                    SUM(role='admin')       AS admins,
                    SUM(COALESCE(totp_enabled,0)=1) AS with_2fa,
                    SUM(last_login_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)) AS active_7d
             FROM users WHERE status != 'deleted'"
        );

        // ── Portfolios & Holdings ─────────────────────────────────
        $portfolios = (int)DB::fetchVal("SELECT COUNT(*) FROM portfolios");
        $mfHoldings = (int)DB::fetchVal("SELECT COUNT(*) FROM mf_holdings");
        $mfSips     = (int)DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE status='active'");
        $mfTxns     = (int)DB::fetchVal("SELECT COUNT(*) FROM mf_transactions");

        // ── DB Size ───────────────────────────────────────────────
        $dbName = DB::fetchVal("SELECT DATABASE()");
        $dbSize = DB::fetchRow(
            "SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) AS size_mb,
                    COUNT(*) AS table_count
             FROM information_schema.tables
             WHERE table_schema = ?",
            [$dbName]
        );

        // ── Table row counts (top tables) ─────────────────────────
        $topTables = DB::fetchAll(
            "SELECT table_name, table_rows,
                    ROUND((data_length+index_length)/1024/1024,2) AS size_mb
             FROM information_schema.tables
             WHERE table_schema = ?
             ORDER BY data_length+index_length DESC
             LIMIT 10",
            [$dbName]
        );

        // ── PHP / Server info ─────────────────────────────────────
        $phpVersion  = PHP_VERSION;
        $memLimit    = ini_get('memory_limit');
        $uploadLimit = ini_get('upload_max_filesize');
        $memUsage    = round(memory_get_usage(true) / 1024 / 1024, 2);
        $memPeak     = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        // ── Disk ──────────────────────────────────────────────────
        $diskTotal = disk_total_space(APP_ROOT);
        $diskFree  = disk_free_space(APP_ROOT);
        $diskUsed  = $diskTotal - $diskFree;

        // ── Cache availability ────────────────────────────────────
        $apcu    = function_exists('apcu_enabled') && apcu_enabled();
        $redis   = class_exists('Redis');
        $opcache = function_exists('opcache_get_status');

        // ── Recent errors (from logs if accessible) ───────────────
        $recentErrors = [];
        $logFile = APP_ROOT . '/logs/error.log';
        if (file_exists($logFile)) {
            $lines = array_slice(file($logFile), -20);
            $recentErrors = array_map('rtrim', $lines);
        }

        // ── Audit log recent ──────────────────────────────────────
        $recentActivity = DB::fetchAll(
            "SELECT a.action, a.detail, a.created_at, u.name, u.email
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.created_at DESC LIMIT 15"
        );

        // ── Cron status ───────────────────────────────────────────
        $lastCron = DB::fetchVal(
            "SELECT MAX(ran_at) FROM cron_log WHERE status='success'"
        ) ?? null;

        json_response(true, 'ok', [
            'users'           => $users,
            'portfolios'      => $portfolios,
            'mf_holdings'     => $mfHoldings,
            'mf_sips_active'  => $mfSips,
            'mf_transactions' => $mfTxns,
            'db'              => [
                'name'        => $dbName,
                'size_mb'     => $dbSize['size_mb'] ?? 0,
                'table_count' => $dbSize['table_count'] ?? 0,
                'top_tables'  => $topTables,
            ],
            'php'             => [
                'version'     => $phpVersion,
                'mem_limit'   => $memLimit,
                'upload_limit'=> $uploadLimit,
                'mem_usage_mb'=> $memUsage,
                'mem_peak_mb' => $memPeak,
            ],
            'disk'            => [
                'total_gb'    => round($diskTotal / 1073741824, 1),
                'free_gb'     => round($diskFree  / 1073741824, 1),
                'used_pct'    => $diskTotal > 0 ? round($diskUsed / $diskTotal * 100) : 0,
            ],
            'cache'           => [
                'apcu'        => $apcu,
                'redis'       => $redis,
                'opcache'     => $opcache,
            ],
            'last_cron'       => $lastCron,
            'recent_activity' => $recentActivity,
            'recent_errors'   => array_slice($recentErrors, -10),
            'server_time'     => date('Y-m-d H:i:s'),
        ]);
        break;
    }

    default:
        json_response(false, 'Unknown action.', [], 400);
}
