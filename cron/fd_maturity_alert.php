<?php
/**
 * WealthDash — FD Maturity Alert Cron                [t80 COMPLETE]
 * Schedule: Daily at 9:00 AM IST
 *
 * Tiered alerts:
 *   30 days → "Plan karo — renew ya withdraw"
 *    7 days → Urgent notice with current rate comparison
 *    1 day  → Final reminder
 *
 * Dedup via fd_alert_log table (auto-created on first run).
 */
declare(strict_types=1);
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

echo '[' . date('Y-m-d H:i:s') . "] FD Maturity Alert Cron started\n";

/* ── ensure alert-log table exists (idempotent) ──────────────────────── */
DB::conn()->exec("
    CREATE TABLE IF NOT EXISTS `fd_alert_log` (
        `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `fd_id`   INT UNSIGNED NOT NULL,
        `tier`    TINYINT UNSIGNED NOT NULL COMMENT '30, 7 or 1',
        `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_fd_tier` (`fd_id`, `tier`),
        KEY `idx_al_fd` (`fd_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ── alert tiers (days-before-maturity) ─────────────────────────────── */
$TIERS = [
    ['days' => 30, 'label' => '30 days'],
    ['days' => 7,  'label' => '7 days'],
    ['days' => 1,  'label' => '1 day'],
];

/* indicative market rates for comparison email */
$MARKET_RATES = ['1 yr' => 6.75, '2 yr' => 7.00, '3 yr' => 7.10, '5 yr' => 7.25];

$totalSent = 0;

foreach ($TIERS as $tier) {
    $daysLeft = $tier['days'];
    $label    = $tier['label'];

    // 1-day window centred on exactly N days from today
    $fds = DB::fetchAll(
        "SELECT fd.*,
                p.user_id,
                u.name  AS user_name,
                u.email AS user_email
         FROM fd_accounts fd
         JOIN portfolios p ON p.id  = fd.portfolio_id
         JOIN users u       ON u.id = p.user_id
         WHERE fd.status = 'active'
           AND fd.maturity_date BETWEEN DATE_ADD(CURDATE(), INTERVAL ? DAY)
                                    AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
           AND NOT EXISTS (
               SELECT 1 FROM fd_alert_log al
               WHERE al.fd_id = fd.id AND al.tier = ?
           )
         ORDER BY u.id, fd.maturity_date ASC",
        [$daysLeft - 1, $daysLeft, $daysLeft]
    );

    if (empty($fds)) {
        echo "  Tier {$label}: no FDs due\n";
        continue;
    }

    $byUser = [];
    foreach ($fds as $fd) { $byUser[$fd['user_id']][] = $fd; }

    foreach ($byUser as $userId => $userFds) {
        $user = DB::fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user || !$user['email']) continue;

        $ok = sendTieredFdAlert($user, $userFds, $daysLeft, $MARKET_RATES);

        if ($ok) {
            foreach ($userFds as $fd) {
                DB::execute(
                    "INSERT IGNORE INTO fd_alert_log (fd_id, tier) VALUES (?,?)",
                    [(int)$fd['id'], $daysLeft]
                );
            }
            $cnt = count($userFds);
            echo "  Tier {$label}: sent to {$user['email']} ({$cnt} FD)\n";
            $totalSent++;
        }
    }
}

echo '[' . date('Y-m-d H:i:s') . "] Done. Batches sent: {$totalSent}\n";

/* ── email builder ───────────────────────────────────────────────────── */
function sendTieredFdAlert(array $user, array $fds, int $days, array $mktRates): bool
{
    $name = htmlspecialchars($user['name'] ?? 'there', ENT_QUOTES);

    [$bg, $bd, $tc, $emoji] = match(true) {
        $days === 1 => ['#fef2f2','#fca5a5','#b91c1c','🚨'],
        $days <= 7  => ['#fffbeb','#fcd34d','#b45309','⚠️'],
        default     => ['#eff6ff','#93c5fd','#1d4ed8','📅'],
    };

    $subj = match(true) {
        $days === 1 => '🚨 FINAL REMINDER: FD matures tomorrow — WealthDash',
        $days <= 7  => "⚠️ Urgent: FD maturing in {$days} days — WealthDash",
        default     => '📅 Heads Up: FD maturing in 30 days — WealthDash',
    };

    $headline = match(true) {
        $days === 1 => 'Your FD matures <strong>tomorrow</strong>. Decide now — renew or withdraw.',
        $days <= 7  => "Your FD matures in <strong>{$days} days</strong>. Act soon — compare current rates below.",
        default     => 'Your FD matures in <strong>30 days</strong>. Start planning — renew or redeploy.',
    };

    /* FD table rows */
    $rows = '';
    foreach ($fds as $fd) {
        $bank     = htmlspecialchars($fd['bank_name'] ?? '—', ENT_QUOTES);
        $pri      = '₹' . number_format((float)($fd['principal_amount'] ?? 0), 2);
        $mat      = $fd['maturity_amount'] ? '₹' . number_format((float)$fd['maturity_amount'], 2) : '—';
        $rate     = number_format((float)($fd['interest_rate'] ?? 0), 2) . '% p.a.';
        $matDate  = date('d M Y', strtotime($fd['maturity_date']));
        $dLeft    = max(0, (int) ceil((strtotime($fd['maturity_date']) - time()) / 86400));
        $dTag     = $dLeft <= 1
                  ? '<span style="color:#b91c1c;font-weight:700">Tomorrow!</span>'
                  : "<span style=\"color:#b45309\">{$dLeft}d</span>";
        $renew    = $fd['auto_renew'] ? '<span style="color:#16a34a">Auto ✓</span>'
                                      : '<span style="color:#6b7280">Manual</span>';
        $rows .= "<tr>
          <td style='padding:9px 11px;border:1px solid #e5e7eb'>{$bank}</td>
          <td style='padding:9px 11px;border:1px solid #e5e7eb;text-align:right'>{$pri}</td>
          <td style='padding:9px 11px;border:1px solid #e5e7eb;text-align:center'>{$rate}</td>
          <td style='padding:9px 11px;border:1px solid #e5e7eb;text-align:center'>{$matDate}</td>
          <td style='padding:9px 11px;border:1px solid #e5e7eb;text-align:center'>{$dTag}</td>
          <td style='padding:9px 11px;border:1px solid #e5e7eb;text-align:right'>{$mat}</td>
          <td style='padding:9px 11px;border:1px solid #e5e7eb;text-align:center'>{$renew}</td>
        </tr>";
    }

    /* market rate comparison */
    $rateRows = '';
    foreach ($mktRates as $tenure => $r) {
        $rateRows .= "<tr>
          <td style='padding:5px 10px;border:1px solid #e5e7eb'>{$tenure}</td>
          <td style='padding:5px 10px;border:1px solid #e5e7eb;font-weight:700;color:#16a34a'>{$r}%</td>
        </tr>";
    }

    $appUrl = defined('APP_URL') ? APP_URL : '#';

    $body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:640px;margin:0 auto;color:#111827;">
  <div style="background:{$bg};border:1.5px solid {$bd};border-radius:10px;padding:14px 18px;margin-bottom:20px;">
    <p style="margin:0;font-size:15px;font-weight:700;color:{$tc}">{$emoji} {$headline}</p>
  </div>
  <p>Hi {$name},</p>
  <p>The following Fixed Deposits need your attention:</p>

  <table style="width:100%;border-collapse:collapse;font-size:13px;margin:12px 0 20px;">
    <thead>
      <tr style="background:#f9fafb">
        <th style="padding:9px 11px;border:1px solid #e5e7eb;text-align:left">Bank</th>
        <th style="padding:9px 11px;border:1px solid #e5e7eb;text-align:right">Principal</th>
        <th style="padding:9px 11px;border:1px solid #e5e7eb">Rate</th>
        <th style="padding:9px 11px;border:1px solid #e5e7eb">Maturity</th>
        <th style="padding:9px 11px;border:1px solid #e5e7eb">Days Left</th>
        <th style="padding:9px 11px;border:1px solid #e5e7eb;text-align:right">Maturity Amt</th>
        <th style="padding:9px 11px;border:1px solid #e5e7eb">Renewal</th>
      </tr>
    </thead>
    <tbody>{$rows}</tbody>
  </table>

  <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin-bottom:20px;">
    <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#374151">📊 Current Market FD Rates (Indicative)</p>
    <table style="font-size:12px;border-collapse:collapse">
      <thead><tr>
        <th style="padding:5px 10px;border:1px solid #e5e7eb;background:#f9fafb;text-align:left">Tenure</th>
        <th style="padding:5px 10px;border:1px solid #e5e7eb;background:#f9fafb;text-align:left">Rate</th>
      </tr></thead>
      <tbody>{$rateRows}</tbody>
    </table>
    <p style="margin:8px 0 0;font-size:11px;color:#9ca3af">Rates are indicative averages. Check your bank for actual offers.</p>
  </div>

  <div style="text-align:center;margin:24px 0;">
    <a href="{$appUrl}" style="display:inline-block;background:#4f46e5;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:700;">
      Open WealthDash →
    </a>
  </div>
  <p style="font-size:11px;color:#9ca3af">Automated alert from WealthDash. You will receive this once per tier (30d/7d/1d) per FD.</p>
</div>
HTML;

    return Notification::send_email($user['email'], $user['name'], $subj, $body);
}
