<?php
/**
 * WealthDash — t379: Scheduled Reports API         [COMPLETE]
 * Actions: scheduled_reports_list | scheduled_report_save | scheduled_report_delete
 */
defined('WEALTHDASH') or die('Direct access not allowed');

/* ── auto-create table on first use ─────────────────────────────────── */
DB::conn()->exec("
    CREATE TABLE IF NOT EXISTS `scheduled_reports` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id`       INT UNSIGNED NOT NULL,
        `report_type`   ENUM('net_worth','portfolio_summary','fy_gains','fd_summary','sip_summary','full_report') NOT NULL DEFAULT 'portfolio_summary',
        `frequency`     ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'monthly',
        `day_of_week`   TINYINT UNSIGNED DEFAULT NULL,
        `day_of_month`  TINYINT UNSIGNED DEFAULT NULL,
        `send_hour`     TINYINT UNSIGNED NOT NULL DEFAULT 8,
        `email`         VARCHAR(320) DEFAULT NULL,
        `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
        `last_sent_at`  DATETIME DEFAULT NULL,
        `next_send_at`  DATETIME DEFAULT NULL,
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_sr_user`      (`user_id`),
        KEY `idx_sr_next_send` (`next_send_at`, `is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* ── helpers ─────────────────────────────────────────────────────────── */

/**
 * Compute next_send_at from schedule params (IST = UTC+5:30)
 */
function compute_next_send(string $frequency, ?int $dow, ?int $dom, int $hour): string
{
    // Work in IST
    $tz  = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $candidate = clone $now;
    $candidate->setTime($hour, 0, 0);

    switch ($frequency) {
        case 'daily':
            // If today's send time already passed, move to tomorrow
            if ($candidate <= $now) {
                $candidate->modify('+1 day');
            }
            break;

        case 'weekly':
            $targetDow = (int)($dow ?? 1); // default Monday
            $todayDow  = (int)$now->format('w'); // 0=Sun
            $daysUntil = ($targetDow - $todayDow + 7) % 7;
            if ($daysUntil === 0 && $candidate <= $now) {
                $daysUntil = 7;
            }
            $candidate->modify("+{$daysUntil} days");
            break;

        case 'monthly':
        default:
            $targetDom = (int)($dom ?? 1); // default 1st of month
            $targetDom = max(1, min(28, $targetDom));
            $candidate->setDate((int)$now->format('Y'), (int)$now->format('n'), $targetDom);
            $candidate->setTime($hour, 0, 0);
            if ($candidate <= $now) {
                $candidate->modify('+1 month');
            }
            break;
    }

    // Return as UTC for DB storage
    $candidate->setTimezone(new DateTimeZone('UTC'));
    return $candidate->format('Y-m-d H:i:s');
}

/* ── action dispatcher ───────────────────────────────────────────────── */

switch ($action) {

    /* ── LIST ─────────────────────────────────────────────────────────── */
    case 'scheduled_reports_list':
        $rows = DB::fetchAll(
            "SELECT * FROM scheduled_reports WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );
        json_response(true, '', ['schedules' => $rows]);
        break;

    /* ── SAVE (insert or update) ─────────────────────────────────────── */
    case 'scheduled_report_save':
        $id          = isset($_POST['id']) && (int)$_POST['id'] > 0 ? (int)$_POST['id'] : null;
        $reportType  = clean($_POST['report_type']  ?? 'portfolio_summary');
        $frequency   = clean($_POST['frequency']    ?? 'monthly');
        $dow         = isset($_POST['day_of_week'])  && $_POST['day_of_week'] !== '' ? (int)$_POST['day_of_week']  : null;
        $dom         = isset($_POST['day_of_month']) && $_POST['day_of_month'] !== '' ? (int)$_POST['day_of_month'] : null;
        $sendHour    = max(0, min(23, (int)($_POST['send_hour'] ?? 8)));
        $emailOver   = trim(clean($_POST['email'] ?? ''));
        $isActive    = isset($_POST['is_active']) ? (int)(bool)$_POST['is_active'] : 1;

        $allowed_types = ['net_worth','portfolio_summary','fy_gains','fd_summary','sip_summary','full_report'];
        $allowed_freqs = ['daily','weekly','monthly'];
        if (!in_array($reportType, $allowed_types, true)) { json_response(false, 'Invalid report_type'); }
        if (!in_array($frequency,  $allowed_freqs, true))  { json_response(false, 'Invalid frequency'); }
        if ($emailOver !== '' && !filter_var($emailOver, FILTER_VALIDATE_EMAIL)) {
            json_response(false, 'Invalid email address');
        }

        $nextSend = compute_next_send($frequency, $dow, $dom, $sendHour);

        if ($id) {
            // Verify ownership
            $existing = DB::fetchOne("SELECT id FROM scheduled_reports WHERE id=? AND user_id=?", [$id, $userId]);
            if (!$existing) { json_response(false, 'Schedule not found'); }

            DB::execute(
                "UPDATE scheduled_reports
                 SET report_type=?, frequency=?, day_of_week=?, day_of_month=?,
                     send_hour=?, email=?, is_active=?, next_send_at=?
                 WHERE id=? AND user_id=?",
                [$reportType, $frequency, $dow, $dom, $sendHour,
                 $emailOver ?: null, $isActive, $nextSend, $id, $userId]
            );
            json_response(true, 'Schedule updated', ['next_send_at' => $nextSend]);
        } else {
            // Max 5 schedules per user
            $count = DB::fetchOne("SELECT COUNT(*) AS c FROM scheduled_reports WHERE user_id=?", [$userId]);
            if ((int)$count['c'] >= 5) { json_response(false, 'Maximum 5 scheduled reports allowed per account'); }

            DB::execute(
                "INSERT INTO scheduled_reports
                     (user_id, report_type, frequency, day_of_week, day_of_month,
                      send_hour, email, is_active, next_send_at)
                 VALUES (?,?,?,?,?,?,?,?,?)",
                [$userId, $reportType, $frequency, $dow, $dom,
                 $sendHour, $emailOver ?: null, $isActive, $nextSend]
            );
            $newId = DB::conn()->lastInsertId();
            json_response(true, 'Schedule created', ['id' => $newId, 'next_send_at' => $nextSend]);
        }
        break;

    /* ── DELETE ──────────────────────────────────────────────────────── */
    case 'scheduled_report_delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { json_response(false, 'Missing id'); }
        $existing = DB::fetchOne("SELECT id FROM scheduled_reports WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$existing) { json_response(false, 'Schedule not found'); }
        DB::execute("DELETE FROM scheduled_reports WHERE id=? AND user_id=?", [$id, $userId]);
        json_response(true, 'Schedule deleted');
        break;

    /* ── TOGGLE ACTIVE ───────────────────────────────────────────────── */
    case 'scheduled_report_toggle':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { json_response(false, 'Missing id'); }
        $existing = DB::fetchOne("SELECT id, is_active FROM scheduled_reports WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$existing) { json_response(false, 'Schedule not found'); }
        $newActive = $existing['is_active'] ? 0 : 1;
        DB::execute("UPDATE scheduled_reports SET is_active=? WHERE id=? AND user_id=?", [$newActive, $id, $userId]);
        json_response(true, $newActive ? 'Schedule enabled' : 'Schedule paused', ['is_active' => $newActive]);
        break;

    default:
        json_response(false, "Unknown action: {$action}", [], 400);
}
