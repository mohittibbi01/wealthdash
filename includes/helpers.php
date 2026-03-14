<?php
/**
 * WealthDash — Helper Functions
 * INR formatting, date helpers, CAGR calc, CSRF, FY utils
 */
declare(strict_types=1);

// -------------------------------------------------------
// CURRENCY HELPERS
// -------------------------------------------------------

/**
 * Format number as INR with ₹ symbol
 * e.g. 1234567.89 → ₹12,34,567.89
 */
function inr(float|int|string $amount, int $decimals = 2): string {
    $amount = (float) $amount;
    $abs    = abs($amount);
    $sign   = $amount < 0 ? '-' : '';

    if ($abs < 1000) {
        return $sign . '₹' . number_format($abs, $decimals);
    }

    // Indian numbering: last 3 digits, then groups of 2
    $formatted = number_format($abs, $decimals, '.', '');
    [$whole, $decimal] = array_pad(explode('.', $formatted), 2, '');

    if (strlen($whole) <= 3) {
        $result = $whole;
    } else {
        $last3 = substr($whole, -3);
        $rest  = substr($whole, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $result = $rest . ',' . $last3;
    }

    return $sign . '₹' . $result . ($decimals > 0 ? '.' . str_pad($decimal, $decimals, '0') : '');
}

/**
 * Format large numbers in lakhs/crores
 */
function inr_compact(float $amount): string {
    $abs = abs($amount);
    $sign = $amount < 0 ? '-' : '';
    if ($abs >= 1_00_00_000) { // 1 Crore
        return $sign . '₹' . number_format($abs / 1_00_00_000, 2) . ' Cr';
    }
    if ($abs >= 1_00_000) { // 1 Lakh
        return $sign . '₹' . number_format($abs / 1_00_000, 2) . ' L';
    }
    return $sign . inr($abs);
}

/**
 * Format percentage with color class
 */
function pct(float $value, int $decimals = 2): string {
    return number_format($value, $decimals) . '%';
}

function gain_class(float $value): string {
    return $value >= 0 ? 'text-gain' : 'text-loss';
}

// -------------------------------------------------------
// DATE HELPERS
// -------------------------------------------------------

/**
 * Convert DB date (YYYY-MM-DD) to display (DD-MM-YYYY)
 */
function date_display(string|null $dbDate): string {
    if (empty($dbDate)) return '—';
    return date(DATE_DISPLAY, strtotime($dbDate));
}

/**
 * Convert display date (DD-MM-YYYY) to DB format (YYYY-MM-DD)
 */
function date_to_db(string $displayDate): string {
    $d = DateTime::createFromFormat('d-m-Y', $displayDate);
    return $d ? $d->format('Y-m-d') : $displayDate;
}

/**
 * Get current financial year string e.g. "2024-25"
 */
function current_fy(): string {
    $month = (int) date('n');
    $year  = (int) date('Y');
    if ($month >= FY_START_MONTH) {
        return $year . '-' . substr((string)($year + 1), -2);
    }
    return ($year - 1) . '-' . substr((string)$year, -2);
}

/**
 * Get FY from a date string (YYYY-MM-DD)
 */
function date_to_fy(string $date): string {
    $d     = new DateTime($date);
    $month = (int) $d->format('n');
    $year  = (int) $d->format('Y');
    if ($month >= FY_START_MONTH) {
        return $year . '-' . substr((string)($year + 1), -2);
    }
    return ($year - 1) . '-' . substr((string)$year, -2);
}

/**
 * Get FY start and end dates
 */
function fy_dates(string $fy): array {
    [$startYear] = explode('-', $fy);
    $startYear = (int) $startYear;
    return [
        'start' => "{$startYear}-04-01",
        'end'   => ($startYear + 1) . '-03-31',
    ];
}

/**
 * List of recent financial years (last 10)
 */
function fy_list(int $count = 10): array {
    $fys  = [];
    $year = (int) date('Y');
    $month = (int) date('n');
    $currentFyStart = $month >= 4 ? $year : $year - 1;

    for ($i = 0; $i < $count; $i++) {
        $y = $currentFyStart - $i;
        $fys[] = $y . '-' . substr((string)($y + 1), -2);
    }
    return $fys;
}

/**
 * Days between two date strings
 */
function days_between(string $date1, string $date2 = ''): int {
    $d1 = new DateTime($date1);
    $d2 = $date2 ? new DateTime($date2) : new DateTime();
    return (int) $d1->diff($d2)->days;
}

// -------------------------------------------------------
// FINANCIAL CALCULATORS
// -------------------------------------------------------

/**
 * Calculate CAGR
 * @param float $startValue  Invested amount
 * @param float $endValue    Current value
 * @param float $years       Holding period in years
 */
function cagr(float $startValue, float $endValue, float $years): float|null {
    if ($startValue <= 0 || $years <= 0 || $endValue <= 0) return null;
    return (pow($endValue / $startValue, 1 / $years) - 1) * 100;
}

/**
 * Calculate XIRR — Extended Internal Rate of Return
 * Handles multiple cash flows at different dates (SIP / multiple purchases)
 *
 * @param array  $cashFlows  [ ['amount' => float, 'date' => 'YYYY-MM-DD'], ... ]
 *                            Investments are NEGATIVE, current value is POSITIVE
 * @param float  $guess      Initial guess (default 0.1 = 10%)
 * @return float|null        Annualised return % or null if not converged
 */
function xirr(array $cashFlows, float $guess = 0.1): float|null {
    if (count($cashFlows) < 2) return null;

    // Use first date as base date
    $baseDate = strtotime($cashFlows[0]['date']);
    if (!$baseDate) return null;

    // Convert dates to year fractions from base date
    $flows = [];
    foreach ($cashFlows as $cf) {
        $t = strtotime($cf['date']);
        if (!$t) return null;
        $days    = ($t - $baseDate) / 86400;
        $flows[] = ['amount' => (float)$cf['amount'], 'years' => $days / 365];
    }

    // Newton-Raphson iteration
    $rate    = $guess;
    $maxIter = 200;
    $tol     = 1e-7;

    for ($i = 0; $i < $maxIter; $i++) {
        $npv  = 0.0;
        $dnpv = 0.0; // derivative

        foreach ($flows as $f) {
            $base  = 1 + $rate;
            if ($base <= 0) { $rate += 0.01; continue 2; }
            $exp   = pow($base, $f['years']);
            $npv  += $f['amount'] / $exp;
            $dnpv -= $f['years'] * $f['amount'] / ($exp * $base);
        }

        if (abs($dnpv) < 1e-12) break;
        $newRate = $rate - $npv / $dnpv;

        if (abs($newRate - $rate) < $tol) {
            return round($newRate * 100, 2); // return as %
        }
        $rate = $newRate;
    }

    return null; // did not converge
}

/**
 * Build XIRR cash flow array from MF transactions + current value
 * Investments = negative, current redemption value = positive
 *
 * @param array  $txns       DB rows with txn_date, transaction_type, value_at_cost
 * @param float  $valueNow   Current market value
 * @param string $today      Today's date YYYY-MM-DD
 * @return float|null        XIRR % or null
 */
function xirr_from_txns(array $txns, float $valueNow, string $today): float|null {
    if (empty($txns) || $valueNow <= 0) return null;

    $cashFlows = [];
    foreach ($txns as $t) {
        $type = $t['transaction_type'] ?? $t['txn_type'] ?? '';
        $cost = (float)($t['value_at_cost'] ?? ($t['amount'] ?? 0));
        $date = $t['txn_date'];

        if (in_array($type, ['BUY', 'DIV_REINVEST', 'SWITCH_IN', 'STP_IN'])) {
            $cashFlows[] = ['amount' => -$cost, 'date' => $date]; // outflow = negative
        } elseif (in_array($type, ['SELL', 'SWITCH_OUT', 'STP_OUT', 'SWP'])) {
            $cashFlows[] = ['amount' => +$cost, 'date' => $date]; // inflow = positive
        }
    }

    if (empty($cashFlows)) return null;

    // Add today's value as final positive cash flow
    $cashFlows[] = ['amount' => $valueNow, 'date' => $today];

    // Sort by date
    usort($cashFlows, fn($a, $b) => strcmp($a['date'], $b['date']));

    return xirr($cashFlows);
}

/**
 * Calculate absolute return %
 */
function abs_return_pct(float $invested, float $currentValue): float {
    if ($invested <= 0) return 0;
    return (($currentValue - $invested) / $invested) * 100;
}

/**
 * Determine gain type: LTCG or STCG
 * @param string $purchaseDate  YYYY-MM-DD
 * @param int    $ltcgDays      Minimum days for LTCG
 */
function gain_type(string $purchaseDate, int $ltcgDays = EQUITY_LTCG_DAYS): string {
    $held = days_between($purchaseDate);
    return $held >= $ltcgDays ? 'LTCG' : 'STCG';
}

/**
 * FD maturity amount (compound interest)
 * compoundFreq: 12=monthly, 4=quarterly, 2=half_yearly, 1=yearly
 */
function fd_maturity(float $principal, float $rateAnnual, int $tenureDays, int $compoundFreq = 4): float {
    $years = $tenureDays / 365;
    $n     = $compoundFreq;
    $r     = $rateAnnual / 100;
    return round($principal * pow(1 + $r / $n, $n * $years), 2);
}

/**
 * FD accrued interest for a given period within an FY
 */
function fd_accrued_interest(float $principal, float $rateAnnual, int $days): float {
    return round($principal * ($rateAnnual / 100) * ($days / 365), 2);
}

/**
 * Savings account monthly interest (simple)
 */
function savings_monthly_interest(float $balance, float $rateAnnual): float {
    return round($balance * ($rateAnnual / 100) / 12, 2);
}

// -------------------------------------------------------
// SECURITY HELPERS
// -------------------------------------------------------

// Fix: define CSRF_TOKEN_EXPIRE before csrf_token() uses it
define('CSRF_TOKEN_EXPIRE', (int) env('CSRF_TOKEN_EXPIRE', 3600));

/**
 * Generate or retrieve CSRF token
 */
function csrf_token(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token']    = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token_ts'] = time();
    }
    // Rotate if older than 1 hour
    if (time() - ($_SESSION['_csrf_token_ts'] ?? 0) > CSRF_TOKEN_EXPIRE) {
        $_SESSION['_csrf_token']    = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token_ts'] = time();
    }
    return $_SESSION['_csrf_token'];
}

/**
 * CSRF token hidden field HTML
 */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Verify CSRF token — call on form submission
 */
function csrf_verify(): void {
    $submitted = $_POST['_csrf_token']
              ?? $_POST['csrf_token']
              ?? $_SERVER['HTTP_X_CSRF_TOKEN']
              ?? '';
    if (!hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']));
    }
}

/**
 * Safe HTML output
 */
function e(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitize string input
 */
function clean(string $input): string {
    return trim(strip_tags($input));
}

/**
 * Validate email
 */
function valid_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate Indian mobile number
 */
function valid_mobile(string $mobile): bool {
    return (bool) preg_match('/^[6-9]\d{9}$/', $mobile);
}

// -------------------------------------------------------
// JSON RESPONSE HELPER
// -------------------------------------------------------

/**
 * Send JSON response and exit
 */
function json_response(bool $success, string $message = '', array $data = [], int $code = 200): never {
    // Discard any stray PHP warnings/notices that would corrupt JSON
    if (ob_get_level()) ob_clean();
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------
// REDIRECT
// -------------------------------------------------------
function redirect(string $url): never {
    if (!str_starts_with($url, 'http')) {
        $url = APP_URL . '/' . ltrim($url, '/');
    }
    header('Location: ' . $url);
    exit;
}

// -------------------------------------------------------
// AUDIT LOGGING
// -------------------------------------------------------
function audit_log(string $action, string $entityType = '', int $entityId = 0, array $old = [], array $new = []): void {
    try {
        DB::run(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $_SESSION['user_id'] ?? null,
                $action,
                $entityType ?: null,
                $entityId ?: null,
                $old ? json_encode($old) : null,
                $new  ? json_encode($new)  : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (Exception) {
        // Audit failure should not crash the app
    }
}

// -------------------------------------------------------
// FLASH MESSAGES
// -------------------------------------------------------
function flash_set(string $type, string $msg): void {
    $_SESSION['_flash'][$type][] = $msg;
}

function flash_get(): array {
    $msgs = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $msgs;
}

// ─── Aliases for Phase 2 API files ──────────────────────────────────────────

function format_inr(float $n): string { return inr($n); }
function fmt_date(?string $d): string { return date_display($d); }
function format_date_display(?string $d): string { return date_display($d); }
function format_date(?string $d): string { return date_display($d); }
function get_investment_fy(string $date): string { return date_to_fy($date); }
function get_gain_type(string $purchaseDate, int $ltcgDays = 365): string { return gain_type($purchaseDate, $ltcgDays); }
function days_diff(string $date): int { return days_between($date); }
function calculate_cagr(float $invested, float $valueNow, ?string $purchaseDate): float {
    if (!$purchaseDate || $invested <= 0 || $valueNow <= 0) return 0.0;
    $days = days_between($purchaseDate);
    if ($days <= 0) return 0.0;
    return round(cagr($invested, $valueNow, $days/365) ?? 0, 2);
}

function generate_csrf(): string { return csrf_token(); }
function verify_csrf(string $token): bool {
    // Fix: use correct session key '_csrf_token' (was 'csrf_token' — always empty)
    return !empty($token) && hash_equals($_SESSION['_csrf_token'] ?? '', $token);
}

/**
 * audit_log with PDO (renamed from duplicate — use audit_log_pdo() for direct PDO callers)
 */
function audit_log_pdo(PDO $db, int $userId, string $action, string $entity, string $details = ''): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $s = $db->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_values, ip_address, created_at)
                           VALUES (?,?,?,0,?,?,NOW())");
        $s->execute([$userId, $action, $entity, $details, $ip]);
    } catch (\Throwable $e) { /* non-critical */ }
}

/* ─── Phase 3 helpers ───────────────────────────────────────────────────── */

/**
 * Validate date string in YYYY-MM-DD format
 */
function validate_date(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    [$y, $m, $d] = explode('-', $date);
    return checkdate((int)$m, (int)$d, (int)$y);
}

/**
 * Calculate financial year string from a date
 */
function calculate_fy(string $date): string {
    $d = new DateTime($date);
    $y = (int)$d->format('Y');
    $m = (int)$d->format('n');
    return $m >= 4 ? "{$y}-" . substr((string)($y + 1), -2) : ($y - 1) . '-' . substr((string)$y, -2);
}