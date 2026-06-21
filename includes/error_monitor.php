<?php
/**
 * WealthDash — Error Monitoring [t414]
 * File: includes/error_monitor.php
 * Worker: ID-M
 *
 * Usage in config.php (after DB is loaded):
 *   require_once APP_ROOT . '/includes/error_monitor.php';
 *   WDErrorMonitor::register();
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

class WDErrorMonitor {

    private static bool $registered = false;

    // ── Register PHP error + exception handlers ───────────────────────────────
    public static function register(): void {
        if (self::$registered) return;
        self::$registered = true;

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    // ── PHP error handler ─────────────────────────────────────────────────────
    public static function handleError(
        int    $errno, string $errstr,
        string $errfile = '', int $errline = 0
    ): bool {
        if (!(error_reporting() & $errno)) return false;

        $typeMap = [
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_NOTICE            => 'E_NOTICE',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        ];
        $type = $typeMap[$errno] ?? "E_UNKNOWN({$errno})";
        self::capture($type, $errstr, $errfile, $errline);
        return false; // Let PHP handle it too
    }

    // ── Uncaught exception handler ────────────────────────────────────────────
    public static function handleException(Throwable $e): void {
        self::capture(
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        if (IS_LOCAL) {
            echo '<pre style="background:#1a0000;color:#ff9999;padding:12px;font-size:12px">';
            echo "<strong>" . get_class($e) . ":</strong> " . htmlspecialchars($e->getMessage()) . "\n";
            echo htmlspecialchars($e->getTraceAsString());
            echo '</pre>';
        } else {
            http_response_code(500);
            echo '{"success":false,"message":"Internal server error"}';
        }
    }

    // ── Fatal error shutdown handler ──────────────────────────────────────────
    public static function handleShutdown(): void {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::capture('FATAL', $error['message'], $error['file'], $error['line']);
        }
    }

    // ── Core capture ──────────────────────────────────────────────────────────
    public static function capture(
        string  $type,
        string  $message,
        string  $file    = '',
        int     $line    = 0,
        string  $trace   = ''
    ): void {
        try {
            // Shorten file path
            $shortFile = str_replace(APP_ROOT, '', $file);

            $fingerprint = sha1($type . '|' . $shortFile . '|' . $line . '|' . substr($message, 0, 100));

            $userId = (int)($_SESSION['user_id'] ?? 0) ?: null;
            $url    = substr($_SERVER['REQUEST_URI'] ?? '', 0, 500);
            $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
            $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

            // Upsert — increment count if same fingerprint seen again
            DB::run(
                'INSERT INTO error_events
                 (fingerprint, error_type, message, file, line, stack_trace, url, user_id,
                  ip_address, user_agent, env)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                     count    = count + 1,
                     last_seen = NOW(),
                     url      = VALUES(url),
                     user_id  = COALESCE(VALUES(user_id), user_id)',
                [
                    $fingerprint, $type,
                    substr($message, 0, 5000),
                    $shortFile ?: null, $line ?: null,
                    $trace ? substr($trace, 0, 8000) : null,
                    $url ?: null, $userId, $ip, $ua ?: null,
                    APP_ENV,
                ]
            );
        } catch (Throwable $e) {
            // Never throw from error handler — silently log to file
            error_log('[WDErrorMonitor] Capture failed: ' . $e->getMessage());
        }
    }

    // ── Manual capture (call from catch blocks) ───────────────────────────────
    public static function report(Throwable $e, string $context = ''): void {
        $msg = $context ? "[{$context}] " . $e->getMessage() : $e->getMessage();
        self::capture(get_class($e), $msg, $e->getFile(), $e->getLine(), $e->getTraceAsString());
    }
}

// ── API Actions ───────────────────────────────────────────────────────────────
// This file is also routed as an API when $action is set
if (!empty($action)) {
    require_auth(ROLE_ADMIN);

    switch ($action) {

        case 'err_list': {
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $perPage  = 50;
            $resolved = isset($_GET['resolved']) ? (int)$_GET['resolved'] : 0;
            $type     = clean($_GET['type'] ?? '');
            $search   = clean($_GET['search'] ?? '');

            $where  = 'WHERE is_resolved=?';
            $params = [$resolved];
            if ($type)   { $where .= ' AND error_type=?';   $params[] = $type; }
            if ($search) { $where .= ' AND (message LIKE ? OR file LIKE ?)';
                           $params[] = "%{$search}%"; $params[] = "%{$search}%"; }

            $total = (int) DB::fetchVal("SELECT COUNT(*) FROM error_events $where", $params);
            $rows  = DB::fetchAll(
                "SELECT ee.*, u.name as resolved_by_name
                 FROM error_events ee
                 LEFT JOIN users u ON u.id = ee.resolved_by
                 $where ORDER BY last_seen DESC LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, ($page-1)*$perPage])
            );

            $stats = DB::fetchOne(
                "SELECT COUNT(*) as total,
                        SUM(is_resolved=0) as open,
                        SUM(is_resolved=1) as resolved,
                        SUM(count) as total_occurrences,
                        COUNT(DISTINCT error_type) as unique_types
                 FROM error_events"
            );

            json_response(true, '', ['rows'=>$rows,'total'=>$total,'stats'=>$stats,
                'page'=>$page,'total_pages'=>(int)ceil($total/$perPage)]);
        }

        case 'err_resolve': {
            csrf_verify();
            $id    = (int)($_POST['id'] ?? 0);
            $notes = clean($_POST['notes'] ?? '');
            DB::run(
                'UPDATE error_events SET is_resolved=1, resolved_at=NOW(), resolved_by=?, notes=? WHERE id=?',
                [(int)$_SESSION['user_id'], $notes, $id]
            );
            json_response(true, 'Marked as resolved.');
        }

        case 'err_unresolve': {
            csrf_verify();
            $id = (int)($_POST['id'] ?? 0);
            DB::run('UPDATE error_events SET is_resolved=0, resolved_at=NULL, resolved_by=NULL WHERE id=?', [$id]);
            json_response(true, 'Marked as open.');
        }

        case 'err_delete': {
            csrf_verify();
            $id = (int)($_POST['id'] ?? 0);
            DB::run('DELETE FROM error_events WHERE id=?', [$id]);
            json_response(true, 'Error event deleted.');
        }

        case 'err_purge_resolved': {
            csrf_verify();
            $n = (int) DB::fetchVal("SELECT COUNT(*) FROM error_events WHERE is_resolved=1");
            DB::run("DELETE FROM error_events WHERE is_resolved=1");
            json_response(true, "Purged {$n} resolved error(s).", ['count'=>$n]);
        }

        case 'err_types': {
            $rows = DB::fetchAll("SELECT DISTINCT error_type, COUNT(*) as cnt FROM error_events GROUP BY error_type ORDER BY cnt DESC");
            json_response(true, '', ['types' => $rows]);
        }

        case 'err_trend': {
            $days = max(1, min((int)($_GET['days'] ?? 7), 30));
            $rows = DB::fetchAll(
                "SELECT DATE(last_seen) as date, COUNT(*) as events, SUM(count) as occurrences
                 FROM error_events WHERE last_seen >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY DATE(last_seen) ORDER BY date ASC",
                [$days]
            );
            json_response(true, '', ['trend' => $rows]);
        }

        case 'err_capture': {
            // JS frontend error capture endpoint
            csrf_verify();
            $type    = clean($_POST['type']    ?? 'JS_ERROR');
            $message = clean($_POST['message'] ?? '');
            $file    = clean($_POST['file']    ?? '');
            $line    = (int)($_POST['line']    ?? 0);
            $trace   = clean($_POST['trace']   ?? '');
            if (!$message) json_response(false, 'message required.');
            WDErrorMonitor::capture($type, $message, $file, $line, $trace);
            json_response(true, 'Error captured.');
        }

        default:
            json_response(false, "Unknown action: {$action}", [], 400);
    }
}
