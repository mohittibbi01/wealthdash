<?php
/**
 * WealthDash — Notification System (Email + SMS)
 */
declare(strict_types=1);

class Notification {

    // -------------------------------------------------------
    // EMAIL via PHPMailer (Gmail SMTP)
    // -------------------------------------------------------

    public static function send_email(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = ''
    ): bool {
        if (!env('MAIL_ENABLED', false)) {
            error_log("[WealthDash] Email disabled. Would send to: {$toEmail}, Subject: {$subject}");
            return true;
        }

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log('[WealthDash] PHPMailer not installed. Run: composer require phpmailer/phpmailer');
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host       = env('MAIL_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth   = true;
            $mail->Username   = env('MAIL_USERNAME');
            $mail->Password   = env('MAIL_PASSWORD');
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int) env('MAIL_PORT', 587);
            $mail->CharSet    = 'UTF-8';

            // Sender
            $mail->setFrom(env('MAIL_USERNAME'), env('MAIL_FROM_NAME', 'WealthDash'));
            $mail->addAddress($toEmail, $toName);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = self::wrap_email_template($subject, $htmlBody);
            $mail->AltBody = $textBody ?: strip_tags($htmlBody);

            $mail->send();
            return true;

        } catch (Exception $e) {
            error_log('[WealthDash] Email error: ' . $e->getMessage());
            return false;
        }
    }

    private static function wrap_email_template(string $title, string $body): string {
        $appName = APP_NAME;
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"><title>{$title}</title></head>
        <body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px">
          <div style="max-width:600px;margin:auto;background:#fff;border-radius:8px;overflow:hidden">
            <div style="background:#2563eb;padding:24px;text-align:center">
              <h1 style="color:#fff;margin:0;font-size:22px">{$appName}</h1>
            </div>
            <div style="padding:32px">
              {$body}
              <hr style="margin:32px 0;border:none;border-top:1px solid #e5e7eb">
              <p style="font-size:12px;color:#9ca3af;text-align:center">
                This email was sent by {$appName}. Do not reply to this email.
              </p>
            </div>
          </div>
        </body>
        </html>
        HTML;
    }

    // -------------------------------------------------------
    // OTP EMAIL
    // -------------------------------------------------------

    public static function send_otp_email(string $email, string $name, string $otp, string $purpose = 'login'): bool {
        $purposeText = match ($purpose) {
            'register'       => 'complete your registration',
            'password_reset' => 'reset your password',
            default          => 'verify your login',
        };

        $body = <<<HTML
        <h2>Hi {$name},</h2>
        <p>Your OTP to {$purposeText} on <strong>{$_SERVER['HTTP_HOST']}</strong> is:</p>
        <div style="text-align:center;margin:32px 0">
          <span style="font-size:36px;font-weight:bold;letter-spacing:8px;color:#2563eb">{$otp}</span>
        </div>
        <p>This OTP is valid for <strong>10 minutes</strong>.</p>
        <p>If you did not request this, please ignore this email.</p>
        HTML;

        return self::send_email($email, $name, APP_NAME . ': Your OTP', $body);
    }

    // -------------------------------------------------------
    // PASSWORD RESET EMAIL
    // -------------------------------------------------------

    public static function send_password_reset(string $email, string $name, string $token): bool {
        $link = APP_URL . '/auth/forgot_password.php?action=reset&token=' . urlencode($token);

        $body = <<<HTML
        <h2>Hi {$name},</h2>
        <p>We received a request to reset your WealthDash password.</p>
        <div style="text-align:center;margin:32px 0">
          <a href="{$link}" style="background:#2563eb;color:#fff;padding:14px 32px;
             border-radius:6px;text-decoration:none;font-weight:bold;font-size:16px">
            Reset Password
          </a>
        </div>
        <p>Or copy this link: <a href="{$link}">{$link}</a></p>
        <p>This link expires in <strong>1 hour</strong>. If you didn't request this, ignore this email.</p>
        HTML;

        return self::send_email($email, $name, APP_NAME . ': Password Reset', $body);
    }

    // -------------------------------------------------------
    // FD MATURITY ALERT
    // -------------------------------------------------------

    public static function send_fd_maturity_alert(
        string $email,
        string $name,
        array  $fds  // [['bank' => '', 'amount' => '', 'maturity_date' => ''], ...]
    ): bool {
        $rows = '';
        foreach ($fds as $fd) {
            $rows .= "<tr>
                <td style='padding:8px;border:1px solid #e5e7eb'>{$fd['bank_name']}</td>
                <td style='padding:8px;border:1px solid #e5e7eb'>" . inr($fd['principal']) . "</td>
                <td style='padding:8px;border:1px solid #e5e7eb'>" . date_display($fd['maturity_date']) . "</td>
            </tr>";
        }

        $body = <<<HTML
        <h2>Hi {$name},</h2>
        <p>The following Fixed Deposits are maturing in the next <strong>30 days</strong>:</p>
        <table style="width:100%;border-collapse:collapse;margin:16px 0">
          <thead>
            <tr style="background:#f3f4f6">
              <th style="padding:8px;border:1px solid #e5e7eb;text-align:left">Bank</th>
              <th style="padding:8px;border:1px solid #e5e7eb;text-align:left">Amount</th>
              <th style="padding:8px;border:1px solid #e5e7eb;text-align:left">Maturity Date</th>
            </tr>
          </thead>
          <tbody>{$rows}</tbody>
        </table>
        <p>Log in to WealthDash to take action.</p>
        HTML;

        return self::send_email($email, $name, APP_NAME . ': FD Maturity Alert', $body);
    }

    // -------------------------------------------------------
    // SMS OTP via MSG91 or Fast2SMS
    // -------------------------------------------------------

    public static function send_sms_otp(string $mobile, string $otp): bool {
        if (!env('SMS_OTP_ENABLED', false)) {
            error_log("[WealthDash] SMS disabled. OTP for {$mobile}: {$otp}");
            return true; // Don't fail in dev
        }

        $provider = env('SMS_PROVIDER', 'msg91');

        return match ($provider) {
            'fast2sms' => self::send_via_fast2sms($mobile, $otp),
            default    => self::send_via_msg91($mobile, $otp),
        };
    }

    private static function send_via_msg91(string $mobile, string $otp): bool {
        $authKey    = env('MSG91_AUTH_KEY', '');
        $templateId = env('MSG91_TEMPLATE_ID', '');
        $senderId   = env('MSG91_SENDER_ID', 'WLTHDS');

        if (!$authKey || !$templateId) {
            error_log('[WealthDash] MSG91 not configured.');
            return false;
        }

        $payload = json_encode([
            'template_id' => $templateId,
            'mobile'      => '91' . $mobile,
            'authkey'     => $authKey,
            'otp'         => $otp,
        ]);

        $ch = curl_init('https://api.msg91.com/api/v5/otp');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return ($response['type'] ?? '') === 'success';
    }

    private static function send_via_fast2sms(string $mobile, string $otp): bool {
        $apiKey = env('FAST2SMS_API_KEY', '');
        if (!$apiKey) return false;

        $ch = curl_init('https://www.fast2sms.com/dev/bulkV2');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'route'          => 'otp',
                'variables_values' => $otp,
                'flash'          => 0,
                'numbers'        => $mobile,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ["authorization: {$apiKey}"],
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return ($response['return'] ?? false) === true;
    }

    // -------------------------------------------------------
    // GENERATE OTP + STORE IN DB
    // -------------------------------------------------------

    public static function generate_and_store_otp(int $userId, string $mobile, string $purpose = 'login'): string {
        $otp     = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otp, PASSWORD_BCRYPT);
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Invalidate old OTPs
        DB::run(
            'UPDATE otp_tokens SET used = 1 WHERE user_id = ? AND purpose = ? AND used = 0',
            [$userId, $purpose]
        );

        DB::run(
            'INSERT INTO otp_tokens (user_id, mobile, otp_hash, purpose, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $mobile, $otpHash, $purpose, $expires]
        );

        return $otp;
    }

    public static function verify_otp(int $userId, string $mobile, string $otp, string $purpose = 'login'): bool {
        $record = DB::fetchOne(
            'SELECT * FROM otp_tokens
             WHERE user_id = ? AND mobile = ? AND purpose = ? AND used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1',
            [$userId, $mobile, $purpose]
        );

        if (!$record) return false;

        // Increment attempts
        DB::run('UPDATE otp_tokens SET attempts = attempts + 1 WHERE id = ?', [$record['id']]);

        if ($record['attempts'] >= 5) {
            DB::run('UPDATE otp_tokens SET used = 1 WHERE id = ?', [$record['id']]);
            return false;
        }

        if (!password_verify($otp, $record['otp_hash'])) return false;

        DB::run('UPDATE otp_tokens SET used = 1 WHERE id = ?', [$record['id']]);
        return true;
    }
}

