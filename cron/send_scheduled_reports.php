<?php
/**
 * WealthDash — t379: Scheduled Reports Cron        [COMPLETE]
 * Schedule: Every hour  (e.g. 0 * * * * php /path/to/cron/send_scheduled_reports.php)
 *
 * Logic:
 *   1. Find all active schedules where next_send_at <= NOW()
 *   2. For each, build the report email based on report_type
 *   3. Send via Notification::send_email()
 *   4. Update last_sent_at and compute next next_send_at
 */
declare(strict_types=1);
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/cron_logger.php';
$_cronLog = new CronLogger('send_scheduled_reports');
$_cronLog->start();


if (php_sapi_name() !== 'cli' && !defined('WD_CRON_FORCE')) {
    die('CLI only');
}

echo '[' . date('Y-m-d H:i:s') . "] Scheduled Reports Cron started\n";

/* ── ensure table exists ─────────────────────────────────────────────── */
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

/* ── fetch due schedules ─────────────────────────────────────────────── */
$due = DB::fetchAll(
    "SELECT sr.*, u.name AS user_name, u.email AS user_email
     FROM scheduled_reports sr
     JOIN users u ON u.id = sr.user_id
     WHERE sr.is_active = 1
       AND sr.next_send_at IS NOT NULL
       AND sr.next_send_at <= UTC_TIMESTAMP()
     ORDER BY sr.next_send_at ASC
     LIMIT 100"
);

if (empty($due)) {
    echo "[" . date('Y-m-d H:i:s') . "] No scheduled reports due. Exiting.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($due) . " schedule(s) due.\n";

$sent = 0;
$failed = 0;

foreach ($due as $schedule) {
    $userId     = (int) $schedule['user_id'];
    $toEmail    = $schedule['email'] ?: $schedule['user_email'];
    $toName     = $schedule['user_name'];
    $reportType = $schedule['report_type'];
    $scheduleId = (int) $schedule['id'];

    if (!$toEmail) {
        echo "  [SKIP] Schedule #{$scheduleId}: no email for user #{$userId}\n";
        advance_schedule($scheduleId, $schedule);
        continue;
    }

    // Get user's active portfolio
    $portfolio = DB::fetchOne(
        "SELECT * FROM portfolios WHERE user_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1",
        [$userId]
    );

    if (!$portfolio) {
        echo "  [SKIP] Schedule #{$scheduleId}: no active portfolio for user #{$userId}\n";
        advance_schedule($scheduleId, $schedule);
        continue;
    }

    $portfolioId = (int) $portfolio['id'];

    try {
        [$subject, $body] = build_report_email($reportType, $userId, $portfolioId, $toName, $schedule);
        $ok = Notification::send_email($toEmail, $toName, $subject, $body);

        if ($ok) {
            $sent++;
            echo "  [OK] Schedule #{$scheduleId} ({$reportType}) → {$toEmail}\n";
        } else {
            $failed++;
            echo "  [FAIL] Schedule #{$scheduleId}: send_email returned false for {$toEmail}\n";
        }
    } catch (Throwable $e) {
        $failed++;
        echo "  [ERROR] Schedule #{$scheduleId}: " . $e->getMessage() . "\n";
        error_log("t379 cron error schedule #{$scheduleId}: " . $e->getMessage());
    }

    advance_schedule($scheduleId, $schedule);
}

echo "[" . date('Y-m-d H:i:s') . "] Done. Sent: {$sent}, Failed: {$failed}\n";
\$_cronLog->finish(\$failed > 0 ? 'warning' : 'success', "Sent: \$sent, Failed: \$failed", \$sent);


/* ═══════════════════════════════════════════════════════════════════════
   helpers
═══════════════════════════════════════════════════════════════════════ */

function advance_schedule(int $id, array $s): void
{
    $nextSend = compute_next_send($s['frequency'], $s['day_of_week'], $s['day_of_month'], (int)$s['send_hour']);
    DB::execute(
        "UPDATE scheduled_reports SET last_sent_at=UTC_TIMESTAMP(), next_send_at=? WHERE id=?",
        [$nextSend, $id]
    );
}

function compute_next_send(string $frequency, $dow, $dom, int $hour): string
{
    $tz  = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $candidate = clone $now;
    $candidate->setTime($hour, 0, 0);

    switch ($frequency) {
        case 'daily':
            if ($candidate <= $now) $candidate->modify('+1 day');
            break;

        case 'weekly':
            $targetDow = (int)($dow ?? 1);
            $todayDow  = (int)$now->format('w');
            $daysUntil = ($targetDow - $todayDow + 7) % 7;
            if ($daysUntil === 0 && $candidate <= $now) $daysUntil = 7;
            $candidate->modify("+{$daysUntil} days");
            break;

        case 'monthly':
        default:
            $targetDom = max(1, min(28, (int)($dom ?? 1)));
            $candidate->setDate((int)$now->format('Y'), (int)$now->format('n'), $targetDom);
            if ($candidate <= $now) $candidate->modify('+1 month');
            break;
    }

    $candidate->setTimezone(new DateTimeZone('UTC'));
    return $candidate->format('Y-m-d H:i:s');
}

/* ── report builders ─────────────────────────────────────────────────── */

function build_report_email(string $type, int $userId, int $portfolioId, string $name, array $schedule): array
{
    $appUrl      = defined('APP_URL') ? APP_URL : '#';
    $freqLabel   = ucfirst($schedule['frequency']);
    $dateStr     = date('d M Y');
    $safeType    = str_replace('_', ' ', ucwords($type, '_'));

    switch ($type) {
        case 'net_worth':
            return build_net_worth_email($userId, $portfolioId, $name, $appUrl, $freqLabel, $dateStr);

        case 'fy_gains':
            return build_fy_gains_email($userId, $portfolioId, $name, $appUrl, $freqLabel, $dateStr);

        case 'fd_summary':
            return build_fd_summary_email($userId, $portfolioId, $name, $appUrl, $freqLabel, $dateStr);

        case 'sip_summary':
            return build_sip_summary_email($userId, $portfolioId, $name, $appUrl, $freqLabel, $dateStr);

        case 'full_report':
            return build_full_report_email($userId, $portfolioId, $name, $appUrl, $freqLabel, $dateStr);

        case 'portfolio_summary':
        default:
            return build_portfolio_summary_email($userId, $portfolioId, $name, $appUrl, $freqLabel, $dateStr);
    }
}

/* ── portfolio_summary ───────────────────────────────────────────────── */
function build_portfolio_summary_email(int $userId, int $portfolioId, string $name, string $appUrl, string $freqLabel, string $dateStr): array
{
    $mfs = DB::fetchAll(
        "SELECT SUM(current_value) AS cv, SUM(invested_amount) AS iv
         FROM mf_holdings WHERE portfolio_id = ?",
        [$portfolioId]
    );
    $mfCv = (float)($mfs[0]['cv'] ?? 0);
    $mfIv = (float)($mfs[0]['iv'] ?? 0);

    $stocks = DB::fetchOne(
        "SELECT SUM(quantity * current_price) AS cv, SUM(quantity * avg_price) AS iv
         FROM stock_holdings WHERE portfolio_id = ?",
        [$portfolioId]
    );
    $stCv = (float)($stocks['cv'] ?? 0);
    $stIv = (float)($stocks['iv'] ?? 0);

    $fds = DB::fetchOne(
        "SELECT SUM(principal_amount) AS iv, SUM(maturity_amount) AS cv
         FROM fd_accounts WHERE portfolio_id = ? AND status='active'",
        [$portfolioId]
    );
    $fdCv = (float)($fds['cv'] ?? 0);
    $fdIv = (float)($fds['iv'] ?? 0);

    $totalCv = $mfCv + $stCv + $fdCv;
    $totalIv = $mfIv + $stIv + $fdIv;
    $pnl     = $totalCv - $totalIv;
    $pnlPct  = $totalIv > 0 ? ($pnl / $totalIv * 100) : 0;
    $pnlColor = $pnl >= 0 ? '#16a34a' : '#dc2626';
    $pnlSign  = $pnl >= 0 ? '+' : '';

    $safeName = htmlspecialchars($name, ENT_QUOTES);
    $subject  = "📊 {$freqLabel} Portfolio Summary — {$dateStr} | WealthDash";

    $body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;color:#111827;">
  <div style="background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:24px 28px;border-radius:12px 12px 0 0;">
    <h2 style="margin:0;color:#fff;font-size:20px;">📊 Portfolio Summary</h2>
    <p style="margin:4px 0 0;color:#c7d2fe;font-size:13px;">{$freqLabel} Report · {$dateStr}</p>
  </div>
  <div style="background:#f8fafc;padding:20px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;">
    <p>Hi {$safeName},</p>
    <p>Here is your {$freqLabel} portfolio snapshot:</p>

    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">
      <tr style="background:#f1f5f9;">
        <th style="padding:10px 14px;text-align:left;border:1px solid #e2e8f0;">Asset Class</th>
        <th style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;">Invested</th>
        <th style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;">Current Value</th>
        <th style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;">P&L</th>
      </tr>
      <tr>
        <td style="padding:10px 14px;border:1px solid #e2e8f0;">Mutual Funds</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;">₹ {$_fmt($mfIv)}</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;">₹ {$_fmt($mfCv)}</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;color:{$pnlColor};">{$pnlSign}₹ {$_fmt($mfCv - $mfIv)}</td>
      </tr>
      <tr style="background:#f9fafb;">
        <td style="padding:10px 14px;border:1px solid #e2e8f0;">Stocks</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;">₹ {$_fmt($stIv)}</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;">₹ {$_fmt($stCv)}</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;color:{$pnlColor};">{$pnlSign}₹ {$_fmt($stCv - $stIv)}</td>
      </tr>
      <tr>
        <td style="padding:10px 14px;border:1px solid #e2e8f0;">Fixed Deposits</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;">₹ {$_fmt($fdIv)}</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;">₹ {$_fmt($fdCv)}</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #e2e8f0;color:#16a34a;">+₹ {$_fmt($fdCv - $fdIv)}</td>
      </tr>
      <tr style="font-weight:700;background:#ede9fe;">
        <td style="padding:10px 14px;border:1px solid #c4b5fd;">Total</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #c4b5fd;">₹ {$_fmt($totalIv)}</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #c4b5fd;">₹ {$_fmt($totalCv)}</td>
        <td style="padding:10px 14px;text-align:right;border:1px solid #c4b5fd;color:{$pnlColor};">{$pnlSign}₹ {$_fmt($pnl)} ({$pnlSign}{$_fmt($pnlPct)}%)</td>
      </tr>
    </table>

    <div style="text-align:center;margin:24px 0;">
      <a href="{$appUrl}" style="display:inline-block;background:#4f46e5;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:700;">Open WealthDash →</a>
    </div>
    <p style="font-size:11px;color:#9ca3af;">Automated {$freqLabel} report from WealthDash. Manage schedules in Settings → Notifications.</p>
  </div>
</div>
HTML;

    // Fix closure-like usage — replace placeholders with actual values
    $body = preg_replace_callback('/\{\$_fmt\(([^)]+)\)\}/', function($m) {
        // evaluate simple arithmetic
        $val = eval("return (float)({$m[1]});");
        return number_format(abs($val), 0, '.', ',');
    }, $body);

    return [$subject, $body];
}

/* ── net_worth ───────────────────────────────────────────────────────── */
function build_net_worth_email(int $userId, int $portfolioId, string $name, string $appUrl, string $freqLabel, string $dateStr): array
{
    $subject = "💰 {$freqLabel} Net Worth Report — {$dateStr} | WealthDash";
    $safeName = htmlspecialchars($name, ENT_QUOTES);

    // Aggregate from all portfolios for this user
    $nw = DB::fetchOne(
        "SELECT
           (SELECT COALESCE(SUM(current_value),0) FROM mf_holdings mh JOIN portfolios p ON p.id=mh.portfolio_id WHERE p.user_id=?) +
           (SELECT COALESCE(SUM(quantity*current_price),0) FROM stock_holdings sh JOIN portfolios p ON p.id=sh.portfolio_id WHERE p.user_id=?) +
           (SELECT COALESCE(SUM(maturity_amount),0) FROM fd_accounts fa JOIN portfolios p ON p.id=fa.portfolio_id WHERE p.user_id=? AND fa.status='active')
         AS net_worth",
        [$userId, $userId, $userId]
    );
    $netWorth = (float)($nw['net_worth'] ?? 0);

    $body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;color:#111827;">
  <div style="background:linear-gradient(135deg,#059669,#10b981);padding:24px 28px;border-radius:12px 12px 0 0;">
    <h2 style="margin:0;color:#fff;font-size:20px;">💰 Net Worth Report</h2>
    <p style="margin:4px 0 0;color:#a7f3d0;font-size:13px;">{$freqLabel} Report · {$dateStr}</p>
  </div>
  <div style="background:#f8fafc;padding:20px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;">
    <p>Hi {$safeName},</p>
    <div style="text-align:center;padding:28px;background:#ecfdf5;border-radius:10px;margin:16px 0;">
      <p style="margin:0 0 6px;font-size:13px;color:#6b7280;">Total Net Worth (as of {$dateStr})</p>
      <p style="margin:0;font-size:36px;font-weight:800;color:#059669;">₹ NETWORTH_PLACEHOLDER</p>
    </div>
    <div style="text-align:center;margin:24px 0;">
      <a href="{$appUrl}" style="display:inline-block;background:#059669;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:700;">View Full Dashboard →</a>
    </div>
    <p style="font-size:11px;color:#9ca3af;">Automated {$freqLabel} report from WealthDash. Manage schedules in Settings → Notifications.</p>
  </div>
</div>
HTML;
    $body = str_replace('NETWORTH_PLACEHOLDER', number_format($netWorth, 0, '.', ','), $body);
    return [$subject, $body];
}

/* ── fy_gains ────────────────────────────────────────────────────────── */
function build_fy_gains_email(int $userId, int $portfolioId, string $name, string $appUrl, string $freqLabel, string $dateStr): array
{
    $subject  = "📋 {$freqLabel} FY Capital Gains Report — {$dateStr} | WealthDash";
    $safeName = htmlspecialchars($name, ENT_QUOTES);
    $fyStart  = date('Y') . '-04-01';
    if (date('m') < 4) $fyStart = (date('Y') - 1) . '-04-01';
    $fyLabel  = 'FY ' . date('Y', strtotime($fyStart)) . '–' . (date('Y', strtotime($fyStart)) + 1);

    $body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;color:#111827;">
  <div style="background:linear-gradient(135deg,#d97706,#f59e0b);padding:24px 28px;border-radius:12px 12px 0 0;">
    <h2 style="margin:0;color:#fff;font-size:20px;">📋 Capital Gains Summary</h2>
    <p style="margin:4px 0 0;color:#fef3c7;font-size:13px;">{$fyLabel} · {$freqLabel} Report · {$dateStr}</p>
  </div>
  <div style="background:#f8fafc;padding:20px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;">
    <p>Hi {$safeName},</p>
    <p>Your capital gains summary for <strong>{$fyLabel}</strong> is ready. Visit WealthDash to view the detailed ITR-ready report with LTCG/STCG breakdown and Schedule 112A data.</p>
    <div style="text-align:center;margin:24px 0;">
      <a href="{$appUrl}/templates/pages/report_fy.php" style="display:inline-block;background:#d97706;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:700;">View FY Gains Report →</a>
    </div>
    <p style="font-size:11px;color:#9ca3af;">Automated {$freqLabel} report from WealthDash. Manage schedules in Settings → Notifications.</p>
  </div>
</div>
HTML;
    return [$subject, $body];
}

/* ── fd_summary ──────────────────────────────────────────────────────── */
function build_fd_summary_email(int $userId, int $portfolioId, string $name, string $appUrl, string $freqLabel, string $dateStr): array
{
    $subject  = "🏦 {$freqLabel} FD Summary — {$dateStr} | WealthDash";
    $safeName = htmlspecialchars($name, ENT_QUOTES);

    $fds = DB::fetchAll(
        "SELECT fa.bank_name, fa.principal_amount, fa.interest_rate, fa.maturity_date, fa.maturity_amount
         FROM fd_accounts fa
         JOIN portfolios p ON p.id = fa.portfolio_id
         WHERE p.user_id = ? AND fa.status = 'active'
         ORDER BY fa.maturity_date ASC
         LIMIT 10",
        [$userId]
    );

    $rows = '';
    $totalPrincipal = 0;
    $totalMaturity  = 0;
    foreach ($fds as $fd) {
        $totalPrincipal += (float)$fd['principal_amount'];
        $totalMaturity  += (float)$fd['maturity_amount'];
        $daysLeft = max(0, (int)ceil((strtotime($fd['maturity_date']) - time()) / 86400));
        $urgentColor = $daysLeft <= 30 ? '#dc2626' : '#374151';
        $rows .= "<tr>
            <td style='padding:8px 12px;border:1px solid #e2e8f0;'>" . htmlspecialchars($fd['bank_name'], ENT_QUOTES) . "</td>
            <td style='padding:8px 12px;border:1px solid #e2e8f0;text-align:right;'>₹ " . number_format((float)$fd['principal_amount'], 0, '.', ',') . "</td>
            <td style='padding:8px 12px;border:1px solid #e2e8f0;text-align:center;'>" . number_format((float)$fd['interest_rate'], 2) . "%</td>
            <td style='padding:8px 12px;border:1px solid #e2e8f0;text-align:center;color:{$urgentColor};font-weight:" . ($daysLeft <= 30 ? '700' : '400') . ";'>" . date('d M Y', strtotime($fd['maturity_date'])) . " ({$daysLeft}d)</td>
            <td style='padding:8px 12px;border:1px solid #e2e8f0;text-align:right;'>₹ " . number_format((float)$fd['maturity_amount'], 0, '.', ',') . "</td>
        </tr>";
    }

    $body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:640px;margin:0 auto;color:#111827;">
  <div style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);padding:24px 28px;border-radius:12px 12px 0 0;">
    <h2 style="margin:0;color:#fff;font-size:20px;">🏦 Fixed Deposit Summary</h2>
    <p style="margin:4px 0 0;color:#bfdbfe;font-size:13px;">{$freqLabel} Report · {$dateStr}</p>
  </div>
  <div style="background:#f8fafc;padding:20px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;">
    <p>Hi {$safeName},</p>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin:12px 0;">
      <thead>
        <tr style="background:#f1f5f9;">
          <th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:left;">Bank</th>
          <th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:right;">Principal</th>
          <th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:center;">Rate</th>
          <th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:center;">Maturity</th>
          <th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:right;">Maturity Amt</th>
        </tr>
      </thead>
      <tbody>
        ROWS_PLACEHOLDER
        <tr style="font-weight:700;background:#eff6ff;">
          <td style="padding:8px 12px;border:1px solid #e2e8f0;" colspan="1">Total</td>
          <td style="padding:8px 12px;border:1px solid #e2e8f0;text-align:right;">₹ TOTAL_PRINCIPAL</td>
          <td colspan="2" style="border:1px solid #e2e8f0;"></td>
          <td style="padding:8px 12px;border:1px solid #e2e8f0;text-align:right;">₹ TOTAL_MATURITY</td>
        </tr>
      </tbody>
    </table>
    <div style="text-align:center;margin:24px 0;">
      <a href="{$appUrl}" style="display:inline-block;background:#1d4ed8;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:700;">View All FDs →</a>
    </div>
    <p style="font-size:11px;color:#9ca3af;">Automated {$freqLabel} report from WealthDash. Manage schedules in Settings → Notifications.</p>
  </div>
</div>
HTML;

    $body = str_replace('ROWS_PLACEHOLDER', $rows ?: '<tr><td colspan="5" style="padding:12px;text-align:center;color:#9ca3af;">No active FDs</td></tr>', $body);
    $body = str_replace('TOTAL_PRINCIPAL', number_format($totalPrincipal, 0, '.', ','), $body);
    $body = str_replace('TOTAL_MATURITY',  number_format($totalMaturity,  0, '.', ','), $body);

    return [$subject, $body];
}

/* ── sip_summary ─────────────────────────────────────────────────────── */
function build_sip_summary_email(int $userId, int $portfolioId, string $name, string $appUrl, string $freqLabel, string $dateStr): array
{
    $subject  = "🔁 {$freqLabel} SIP Summary — {$dateStr} | WealthDash";
    $safeName = htmlspecialchars($name, ENT_QUOTES);

    $sips = DB::fetchAll(
        "SELECT s.fund_name, s.sip_amount, s.frequency, s.next_date, s.status
         FROM sips s
         JOIN portfolios p ON p.id = s.portfolio_id
         WHERE p.user_id = ? AND s.status = 'active'
         ORDER BY s.next_date ASC
         LIMIT 15",
        [$userId]
    );

    $totalSip = 0;
    $rows = '';
    foreach ($sips as $sip) {
        $totalSip += (float)$sip['sip_amount'];
        $rows .= "<tr>
            <td style='padding:8px 12px;border:1px solid #e2e8f0;'>" . htmlspecialchars($sip['fund_name'], ENT_QUOTES) . "</td>
            <td style='padding:8px 12px;border:1px solid #e2e8f0;text-align:right;'>₹ " . number_format((float)$sip['sip_amount'], 0, '.', ',') . "</td>
            <td style='padding:8px 12px;border:1px solid #e2e8f0;text-align:center;'>" . ucfirst($sip['frequency']) . "</td>
            <td style='padding:8px 12px;border:1px solid #e2e8f0;text-align:center;'>" . ($sip['next_date'] ? date('d M Y', strtotime($sip['next_date'])) : '—') . "</td>
        </tr>";
    }

    $body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;color:#111827;">
  <div style="background:linear-gradient(135deg,#7c3aed,#a78bfa);padding:24px 28px;border-radius:12px 12px 0 0;">
    <h2 style="margin:0;color:#fff;font-size:20px;">🔁 SIP Summary</h2>
    <p style="margin:4px 0 0;color:#ede9fe;font-size:13px;">{$freqLabel} Report · {$dateStr}</p>
  </div>
  <div style="background:#f8fafc;padding:20px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;">
    <p>Hi {$safeName},</p>
    <p>Your active SIPs — Total monthly outflow: <strong>₹ TOTAL_SIP</strong></p>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin:12px 0;">
      <thead>
        <tr style="background:#f5f3ff;">
          <th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:left;">Fund</th>
          <th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:right;">Amount</th>
          <th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:center;">Frequency</th>
          <th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:center;">Next Date</th>
        </tr>
      </thead>
      <tbody>ROWS_PLACEHOLDER</tbody>
    </table>
    <div style="text-align:center;margin:24px 0;">
      <a href="{$appUrl}" style="display:inline-block;background:#7c3aed;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:700;">Manage SIPs →</a>
    </div>
    <p style="font-size:11px;color:#9ca3af;">Automated {$freqLabel} report from WealthDash. Manage schedules in Settings → Notifications.</p>
  </div>
</div>
HTML;

    $body = str_replace('ROWS_PLACEHOLDER', $rows ?: '<tr><td colspan="4" style="padding:12px;text-align:center;color:#9ca3af;">No active SIPs</td></tr>', $body);
    $body = str_replace('TOTAL_SIP', number_format($totalSip, 0, '.', ','), $body);
    return [$subject, $body];
}

/* ── full_report ─────────────────────────────────────────────────────── */
function build_full_report_email(int $userId, int $portfolioId, string $name, string $appUrl, string $freqLabel, string $dateStr): array
{
    $subject  = "📈 {$freqLabel} Full Wealth Report — {$dateStr} | WealthDash";
    $safeName = htmlspecialchars($name, ENT_QUOTES);

    $body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;color:#111827;">
  <div style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:24px 28px;border-radius:12px 12px 0 0;">
    <h2 style="margin:0;color:#fff;font-size:20px;">📈 Full Wealth Report</h2>
    <p style="margin:4px 0 0;color:#94a3b8;font-size:13px;">{$freqLabel} Report · {$dateStr}</p>
  </div>
  <div style="background:#f8fafc;padding:20px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;">
    <p>Hi {$safeName},</p>
    <p>Your comprehensive wealth report for <strong>{$dateStr}</strong> is ready. It includes:</p>
    <ul style="color:#374151;line-height:1.8;">
      <li>Portfolio summary (MFs, Stocks, FDs)</li>
      <li>Net worth snapshot</li>
      <li>Active SIPs and upcoming payments</li>
      <li>Capital gains (LTCG/STCG) overview</li>
      <li>Goal progress</li>
    </ul>
    <div style="text-align:center;margin:24px 0;">
      <a href="{$appUrl}" style="display:inline-block;background:#0f172a;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:700;">Open Full Dashboard →</a>
    </div>
    <p style="font-size:11px;color:#9ca3af;">Automated {$freqLabel} report from WealthDash. Manage schedules in Settings → Notifications.</p>
  </div>
</div>
HTML;
    return [$subject, $body];
}
