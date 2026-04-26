<?php
/**
 * WealthDash — FD Rate Tracker API (t421)
 *
 * Endpoints (all under /api/fd/fd_rates.php):
 *   GET  ?action=rate_grid                   → full rates matrix (banks × tenures)
 *   GET  ?action=best_rates                  → top-N best rates, optionally filtered
 *   GET  ?action=compare&portfolio_id=X      → user FDs vs market rates
 *   GET  ?action=opportunities&portfolio_id=X→ renewal opportunity cards
 *   GET  ?action=rate_history&bank=SBI       → rate trend for one bank + tenure
 *   GET  ?action=banks                       → list of tracked banks
 *   POST ?action=update_rate   (admin only)  → update a bank's rate
 *   POST ?action=bulk_update   (admin only)  → JSON array of rate updates
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

$currentUser = require_auth();

set_exception_handler(function (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
});

try {
    $db     = DB::conn();
    $action = $_GET['action'] ?? $_POST['action'] ?? 'rate_grid';
    $userId = (int)$currentUser['id'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── Ensure tables exist ──────────────────────────────
    ensure_tables($db);

    // ── Route ────────────────────────────────────────────
    if ($method === 'POST') {
        if (!is_admin()) {
            ob_clean();
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin only.']);
            exit;
        }
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $result = match ($action) {
            'update_rate' => rate_update($db, $body),
            'bulk_update' => rate_bulk_update($db, $body),
            default       => throw new InvalidArgumentException("Unknown action: $action"),
        };
    } else {
        $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
        $result = match ($action) {
            'rate_grid'    => rate_grid($db),
            'best_rates'   => best_rates($db, $_GET),
            'compare'      => compare_user_fds($db, $userId, $portfolioId),
            'opportunities'=> opportunities($db, $userId, $portfolioId),
            'rate_history' => rate_history($db, $_GET),
            'banks'        => banks_list($db),
            default        => throw new InvalidArgumentException("Unknown action: $action"),
        };
    }

    ob_clean();
    echo json_encode(array_merge(['success' => true, 'action' => $action], $result),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════
// ENSURE TABLES
// ═══════════════════════════════════════════════════════════
function ensure_tables(PDO $db): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try { $db->query('SELECT 1 FROM fd_bank_rates LIMIT 1'); }
    catch (Exception $e) {
        // Table missing — run migration inline (minimal version)
        $db->exec("CREATE TABLE IF NOT EXISTS fd_bank_rates (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bank_name      VARCHAR(120) NOT NULL,
            bank_type      VARCHAR(30)  NOT NULL DEFAULT 'private',
            tenure_months  SMALLINT UNSIGNED NOT NULL,
            rate_regular   DECIMAL(5,2) NOT NULL,
            rate_senior    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            min_amount     INT UNSIGNED NOT NULL DEFAULT 1000,
            is_special     TINYINT(1)   NOT NULL DEFAULT 0,
            is_active      TINYINT(1)   NOT NULL DEFAULT 1,
            effective_date DATE         NOT NULL,
            source_url     VARCHAR(300) DEFAULT NULL,
            notes          VARCHAR(200) DEFAULT NULL,
            updated_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_bank_tenure (bank_name, tenure_months),
            INDEX idx_tenure    (tenure_months),
            INDEX idx_bank_type (bank_type),
            INDEX idx_rate      (rate_regular DESC),
            INDEX idx_effective (effective_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    try { $db->query('SELECT 1 FROM fd_rate_history LIMIT 1'); }
    catch (Exception $e) {
        $db->exec("CREATE TABLE IF NOT EXISTS fd_rate_history (
            id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bank_name      VARCHAR(120) NOT NULL,
            tenure_months  SMALLINT UNSIGNED NOT NULL,
            rate_regular   DECIMAL(5,2) NOT NULL,
            rate_senior    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            effective_date DATE NOT NULL,
            recorded_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bank_tenure (bank_name, tenure_months),
            INDEX idx_date (effective_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// ═══════════════════════════════════════════════════════════
// RATE GRID — full matrix of banks × tenures
// ═══════════════════════════════════════════════════════════
function rate_grid(PDO $db): array
{
    $tenures = [3, 6, 9, 12, 18, 24, 36, 60];

    // Fetch all active rates
    $rows = $db->query("
        SELECT bank_name, bank_type, tenure_months, rate_regular, rate_senior,
               min_amount, is_special, effective_date
        FROM fd_bank_rates
        WHERE is_active = 1
        ORDER BY bank_type, bank_name, tenure_months
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Build matrix [bank_name => [tenure => rate]]
    $banks  = [];
    $matrix = [];
    foreach ($rows as $r) {
        $key = $r['bank_name'];
        if (!isset($matrix[$key])) {
            $matrix[$key] = ['bank_name' => $key, 'bank_type' => $r['bank_type'], 'rates' => [], 'min_amount' => (int)$r['min_amount']];
            $banks[] = $key;
        }
        $matrix[$key]['rates'][(int)$r['tenure_months']] = [
            'regular'    => (float)$r['rate_regular'],
            'senior'     => (float)$r['rate_senior'],
            'is_special' => (bool)$r['is_special'],
        ];
    }

    // Per-tenure best rate for highlighting
    $bestPerTenure = [];
    foreach ($tenures as $t) {
        $best = 0;
        foreach ($matrix as $b) {
            if (isset($b['rates'][$t])) $best = max($best, $b['rates'][$t]['regular']);
        }
        $bestPerTenure[$t] = $best;
    }

    $lastUpdated = null;
    try {
        $lu = $db->query("SELECT MAX(updated_at) AS lu FROM fd_bank_rates")->fetchColumn();
        $lastUpdated = $lu ? date('d M Y', strtotime($lu)) : null;
    } catch (Exception $e) {}

    return [
        'tenures'          => $tenures,
        'banks'            => array_values($matrix),
        'best_per_tenure'  => $bestPerTenure,
        'last_updated'     => $lastUpdated ?? date('d M Y'),
        'bank_type_labels' => [
            'public'        => 'PSU Banks',
            'private_large' => 'Large Private',
            'private'       => 'Private Banks',
            'small_finance' => 'Small Finance Banks',
            'government'    => 'Government Schemes',
            'cooperative'   => 'Co-operative Banks',
        ],
        'disclaimer' => 'Rates are indicative. Verify current rates with respective bank before investing. Senior citizen rates are typically regular + 0.25–0.50%.',
    ];
}

// ═══════════════════════════════════════════════════════════
// BEST RATES — top N, optional filters
// ═══════════════════════════════════════════════════════════
function best_rates(PDO $db, array $params): array
{
    $tenure    = (int)($params['tenure_months'] ?? 0);
    $bankType  = $params['bank_type'] ?? '';
    $isSenior  = (bool)($params['senior'] ?? false);
    $limit     = max(5, min(30, (int)($params['limit'] ?? 10)));
    $rateCol   = $isSenior ? 'rate_senior' : 'rate_regular';

    $where  = ['is_active = 1'];
    $bind   = [];

    if ($tenure > 0) {
        // Allow ±3 months tolerance
        $where[] = 'tenure_months BETWEEN ? AND ?';
        $bind[]  = max(1, $tenure - 3);
        $bind[]  = $tenure + 3;
    }
    if ($bankType) {
        $where[] = 'bank_type = ?';
        $bind[]  = $bankType;
    }

    $stmt = $db->prepare("
        SELECT bank_name, bank_type, tenure_months,
               rate_regular, rate_senior, min_amount, is_special, effective_date
        FROM fd_bank_rates
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $rateCol DESC, tenure_months ASC
        LIMIT ?
    ");
    $bind[] = $limit;
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['rate_regular'] = (float)$r['rate_regular'];
        $r['rate_senior']  = (float)$r['rate_senior'];
        $r['min_amount']   = (int)$r['min_amount'];
        $r['is_special']   = (bool)$r['is_special'];
        $r['display_rate'] = $isSenior ? $r['rate_senior'] : $r['rate_regular'];
        $r['tenure_label'] = tenure_label((int)$r['tenure_months']);
    }

    return [
        'best_rates'   => $rows,
        'tenure_months'=> $tenure,
        'bank_type'    => $bankType,
        'is_senior'    => $isSenior,
        'count'        => count($rows),
    ];
}

// ═══════════════════════════════════════════════════════════
// COMPARE — user's FDs vs best available market rates
// ═══════════════════════════════════════════════════════════
function compare_user_fds(PDO $db, int $userId, int $portfolioId): array
{
    // Fetch user's active FDs
    $pWhere = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    $stmt = $db->prepare("
        SELECT fa.id, fa.bank_name, fa.principal, fa.interest_rate AS your_rate,
               fa.start_date, fa.maturity_date,
               DATEDIFF(fa.maturity_date, fa.start_date) AS tenure_days,
               DATEDIFF(fa.maturity_date, CURDATE())     AS days_to_maturity,
               fa.maturity_amount, fa.is_senior_citizen
        FROM fd_accounts fa
        JOIN portfolios p ON p.id = fa.portfolio_id
        WHERE fa.status = 'active' $pWhere
        ORDER BY fa.maturity_date ASC
    ");
    $stmt->execute([$pParam]);
    $userFDs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($userFDs)) {
        return ['comparisons' => [], 'summary' => ['total_fds' => 0, 'opportunity_cost_annual' => 0]];
    }

    // Fetch all active market rates once
    $mktRates = $db->query("
        SELECT bank_name, bank_type, tenure_months, rate_regular, rate_senior, min_amount
        FROM fd_bank_rates WHERE is_active = 1
        ORDER BY rate_regular DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $comparisons       = [];
    $totalOpportunity  = 0;

    foreach ($userFDs as $fd) {
        $tenureMonths  = round((int)$fd['tenure_days'] / 30);
        $isSenior      = (bool)$fd['is_senior_citizen'];
        $yourRate      = (float)$fd['your_rate'];
        $principal     = (float)$fd['principal'];
        $daysLeft      = (int)$fd['days_to_maturity'];

        // Find best matching rate (±3 month tolerance)
        $best = find_best_market_rate($mktRates, $tenureMonths, $isSenior, (int)$principal);

        $bestRate      = $best['rate'];
        $gap           = round($bestRate - $yourRate, 2);
        $annualLoss    = $gap > 0 ? round($principal * $gap / 100, 0) : 0;
        $totalOpportunity += $annualLoss;

        $status = $gap <= 0
            ? 'optimal'
            : ($gap < 0.5 ? 'acceptable' : ($gap < 1.0 ? 'suboptimal' : 'poor'));

        $comparisons[] = [
            'fd_id'           => (int)$fd['id'],
            'bank_name'       => $fd['bank_name'],
            'principal'       => $principal,
            'your_rate'       => $yourRate,
            'best_rate'       => $bestRate,
            'best_bank'       => $best['bank'],
            'best_bank_type'  => $best['bank_type'],
            'best_tenure_months' => $best['tenure_months'],
            'gap_pct'         => $gap,
            'annual_opportunity_cost' => $annualLoss,
            'days_to_maturity'=> $daysLeft,
            'tenure_label'    => tenure_label((int)$tenureMonths),
            'maturity_date'   => $fd['maturity_date'],
            'status'          => $status,
            'action_text'     => generate_action_text($fd, $gap, $best, $daysLeft),
        ];
    }

    // Sort: highest opportunity cost first
    usort($comparisons, fn($a, $b) => $b['annual_opportunity_cost'] <=> $a['annual_opportunity_cost']);

    $optimalCount = count(array_filter($comparisons, fn($c) => $c['status'] === 'optimal'));

    return [
        'comparisons' => $comparisons,
        'summary' => [
            'total_fds'                 => count($comparisons),
            'optimal_fds'               => $optimalCount,
            'suboptimal_fds'            => count($comparisons) - $optimalCount,
            'opportunity_cost_annual'   => round($totalOpportunity, 0),
            'opportunity_cost_5yr'      => round($totalOpportunity * 5, 0),
        ],
    ];
}

// ═══════════════════════════════════════════════════════════
// OPPORTUNITIES — top renewal suggestions
// ═══════════════════════════════════════════════════════════
function opportunities(PDO $db, int $userId, int $portfolioId): array
{
    $compare = compare_user_fds($db, $userId, $portfolioId);
    $cards   = array_filter(
        $compare['comparisons'],
        fn($c) => $c['gap_pct'] >= 0.25 && $c['annual_opportunity_cost'] > 0
    );
    $cards = array_values($cards);

    // Sort by annual_opportunity_cost DESC
    usort($cards, fn($a, $b) => $b['annual_opportunity_cost'] <=> $a['annual_opportunity_cost']);

    return [
        'opportunities'           => array_slice($cards, 0, 8),
        'total_opportunity_count' => count($cards),
        'total_opportunity_annual'=> $compare['summary']['opportunity_cost_annual'],
        'summary'                 => $compare['summary'],
    ];
}

// ═══════════════════════════════════════════════════════════
// RATE HISTORY — trend for one bank + tenure
// ═══════════════════════════════════════════════════════════
function rate_history(PDO $db, array $params): array
{
    $bank    = $params['bank']           ?? '';
    $tenure  = (int)($params['tenure_months'] ?? 12);
    $months  = min(24, max(3, (int)($params['months'] ?? 12)));

    if (!$bank) throw new InvalidArgumentException('bank param required');

    $stmt = $db->prepare("
        SELECT effective_date, rate_regular, rate_senior
        FROM fd_rate_history
        WHERE bank_name = ? AND tenure_months = ?
          AND effective_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        ORDER BY effective_date ASC
    ");
    $stmt->execute([$bank, $tenure, $months]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also include current rate as last point
    $current = $db->prepare("
        SELECT rate_regular, rate_senior, updated_at
        FROM fd_bank_rates
        WHERE bank_name = ? AND tenure_months = ?
    ");
    $current->execute([$bank, $tenure]);
    $curr = $current->fetch(PDO::FETCH_ASSOC);

    if ($curr) {
        $history[] = [
            'effective_date' => date('Y-m-d'),
            'rate_regular'   => (float)$curr['rate_regular'],
            'rate_senior'    => (float)$curr['rate_senior'],
            'is_current'     => true,
        ];
    }

    $direction = null;
    if (count($history) >= 2) {
        $first = (float)$history[0]['rate_regular'];
        $last  = (float)end($history)['rate_regular'];
        $direction = $last > $first ? 'up' : ($last < $first ? 'down' : 'stable');
    }

    return [
        'bank'          => $bank,
        'tenure_months' => $tenure,
        'tenure_label'  => tenure_label($tenure),
        'history'       => $history,
        'current_rate'  => $curr ? (float)$curr['rate_regular'] : null,
        'trend'         => $direction,
    ];
}

// ═══════════════════════════════════════════════════════════
// BANKS LIST
// ═══════════════════════════════════════════════════════════
function banks_list(PDO $db): array
{
    $rows = $db->query("
        SELECT bank_name, bank_type, MIN(rate_regular) AS min_rate, MAX(rate_regular) AS max_rate,
               COUNT(*) AS tenure_count, MAX(updated_at) AS last_updated
        FROM fd_bank_rates
        WHERE is_active = 1
        GROUP BY bank_name, bank_type
        ORDER BY bank_type, bank_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['min_rate']  = (float)$r['min_rate'];
        $r['max_rate']  = (float)$r['max_rate'];
        $r['tenure_count'] = (int)$r['tenure_count'];
    }

    return ['banks' => $rows, 'count' => count($rows)];
}

// ═══════════════════════════════════════════════════════════
// ADMIN — update single rate
// ═══════════════════════════════════════════════════════════
function rate_update(PDO $db, array $body): array
{
    $bank    = trim($body['bank_name']    ?? '');
    $tenure  = (int)($body['tenure_months'] ?? 0);
    $regular = (float)($body['rate_regular'] ?? 0);
    $senior  = (float)($body['rate_senior']  ?? $regular + 0.25);
    $date    = $body['effective_date'] ?? date('Y-m-d');

    if (!$bank || !$tenure || $regular <= 0) {
        throw new InvalidArgumentException('bank_name, tenure_months, rate_regular required');
    }

    // Fetch existing for history
    $existing = $db->prepare(
        "SELECT rate_regular, rate_senior FROM fd_bank_rates WHERE bank_name=? AND tenure_months=?"
    );
    $existing->execute([$bank, $tenure]);
    $old = $existing->fetch(PDO::FETCH_ASSOC);

    if ($old && ((float)$old['rate_regular'] !== $regular || (float)$old['rate_senior'] !== $senior)) {
        // Log old rate to history
        $db->prepare("
            INSERT INTO fd_rate_history (bank_name, tenure_months, rate_regular, rate_senior, effective_date)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$bank, $tenure, $old['rate_regular'], $old['rate_senior'], $date]);
    }

    // Upsert
    $db->prepare("
        INSERT INTO fd_bank_rates (bank_name, bank_type, tenure_months, rate_regular, rate_senior, effective_date)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            rate_regular = VALUES(rate_regular),
            rate_senior  = VALUES(rate_senior),
            effective_date = VALUES(effective_date),
            updated_at   = CURRENT_TIMESTAMP
    ")->execute([
        $bank,
        $body['bank_type'] ?? 'private',
        $tenure,
        $regular,
        $senior,
        $date,
    ]);

    return ['updated' => true, 'bank' => $bank, 'tenure_months' => $tenure, 'rate_regular' => $regular];
}

// ═══════════════════════════════════════════════════════════
// ADMIN — bulk update (JSON array)
// ═══════════════════════════════════════════════════════════
function rate_bulk_update(PDO $db, array $body): array
{
    $items   = $body['rates'] ?? $body;
    if (!is_array($items)) throw new InvalidArgumentException('rates array required');
    $updated = 0;
    $errors  = [];
    foreach ($items as $item) {
        try { rate_update($db, $item); $updated++; }
        catch (Exception $e) { $errors[] = ($item['bank_name'] ?? '?') . ': ' . $e->getMessage(); }
    }
    return ['updated' => $updated, 'errors' => $errors];
}

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════
function find_best_market_rate(array $rates, int $tenureMonths, bool $isSenior, int $principal): array
{
    $rateKey = $isSenior ? 'rate_senior' : 'rate_regular';
    $best    = null;
    foreach ($rates as $r) {
        if (abs((int)$r['tenure_months'] - $tenureMonths) > 3) continue;
        if ((int)$r['min_amount'] > $principal) continue;
        $rate = (float)$r[$rateKey];
        if ($rate <= 0) continue;
        if (!$best || $rate > (float)$best[$rateKey]) {
            $best = $r;
        }
    }
    if (!$best) return ['rate' => 0, 'bank' => 'N/A', 'bank_type' => 'unknown', 'tenure_months' => $tenureMonths];
    return [
        'rate'          => (float)$best[$rateKey],
        'bank'          => $best['bank_name'],
        'bank_type'     => $best['bank_type'],
        'tenure_months' => (int)$best['tenure_months'],
    ];
}

function generate_action_text(array $fd, float $gap, array $best, int $daysLeft): string
{
    if ($gap <= 0) return '✅ Your rate is at or above market best for this tenure.';
    $bestName = htmlspecialchars($best['bank'], ENT_QUOTES);
    if ($daysLeft <= 30) {
        return "🔔 FD maturing soon! Reinvest at {$bestName} for {$best['rate']}% (+{$gap}% more).";
    }
    if ($daysLeft <= 90) {
        return "💡 Matures in {$daysLeft} days — plan to reinvest at {$bestName} ({$best['rate']}%).";
    }
    return "ℹ️ At maturity, consider switching to {$bestName} to earn {$best['rate']}% (+{$gap}% p.a.).";
}

function tenure_label(int $months): string
{
    if ($months < 12) return "{$months} Months";
    if ($months % 12 === 0) {
        $y = $months / 12;
        return "{$y} Year" . ($y > 1 ? 's' : '');
    }
    $y = intdiv($months, 12);
    $m = $months % 12;
    return "{$y}Y {$m}M";
}
