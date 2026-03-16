<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

// Simulate logged-in session
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
if (!$portfolioId) {
    $p = DB::fetchOne("SELECT id FROM portfolios WHERE user_id=? LIMIT 1", [$userId]);
    $portfolioId = $p ? (int)$p['id'] : 0;
}

header('Content-Type: text/plain');
echo "=== SIP ADD DIRECT TEST ===\n";
echo "userId=$userId portfolioId=$portfolioId\n\n";

// Simulate exactly what sip_add does
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    // Step 1: can_edit_portfolio
    $canEdit = can_edit_portfolio($portfolioId, $userId, false);
    echo "can_edit_portfolio: " . ($canEdit ? 'YES' : 'NO - FAIL') . "\n";
    if (!$canEdit) { echo "STOP: No edit access\n"; exit; }

    // Step 2: Get a fund from holdings
    $fund = DB::fetchOne(
        "SELECT f.id, f.scheme_name, f.scheme_code FROM mf_holdings h
         JOIN funds f ON f.id = h.fund_id
         WHERE h.portfolio_id=? AND h.is_active=1 LIMIT 1",
        [$portfolioId]
    );
    echo "fund: {$fund['scheme_name']} (id={$fund['id']})\n";

    // Step 3: Simulate POST data (exactly what browser sends)
    $testPost = [
        'fund_id'      => $fund['id'],
        'sip_amount'   => 1000,
        'frequency'    => 'monthly', // use monthly to avoid ENUM issue
        'sip_day'      => 1,
        'start_date'   => '01-01-2025',
        'end_date'     => '',
        'folio_number' => '',
        'platform'     => '',
        'notes'        => '',
    ];
    echo "POST data: " . json_encode($testPost) . "\n";

    $fundId    = (int)$testPost['fund_id'];
    $amount    = (float)$testPost['sip_amount'];
    $frequency = in_array($testPost['frequency'], ['monthly','quarterly','weekly','yearly'])
                 ? $testPost['frequency'] : 'monthly';
    $sipDay    = 1;
    $startDate = date_to_db($testPost['start_date']);
    $endDate   = null;

    echo "startDate after date_to_db: '$startDate'\n";
    echo "frequency: '$frequency'\n";

    if (!$fundId || $amount <= 0 || !$startDate) {
        echo "FAIL: Required fields missing (fundId=$fundId amount=$amount startDate='$startDate')\n";
        exit;
    }

    // Step 4: Verify fund
    $fundRow = DB::fetchOne('SELECT id, scheme_name FROM funds WHERE id=?', [$fundId]);
    echo "fund exists: " . ($fundRow ? 'YES' : 'NO - FAIL') . "\n";
    if (!$fundRow) exit;

    // Step 5: INSERT (in transaction, rollback)
    $pdo = DB::conn();
    $pdo->beginTransaction();
    echo "Attempting INSERT...\n";
    DB::run(
        'INSERT INTO sip_schedules
         (portfolio_id, asset_type, fund_id, folio_number, sip_amount, frequency,
          sip_day, start_date, end_date, platform, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$portfolioId, 'mf', $fundId, null, $amount, $frequency,
         $sipDay, $startDate, $endDate, null, null]
    );
    $newId = (int)$pdo->lastInsertId();
    $pdo->rollBack();
    echo "INSERT OK, id=$newId (ROLLED BACK)\n";

    // Step 6: Build JSON response
    $response = json_encode([
        'success' => true,
        'message' => 'SIP added successfully.',
        'data'    => ['id' => $newId, 'nav_status' => 'available'],
    ]);
    echo "JSON response: $response\n";
    echo "JSON length: " . strlen($response) . "\n\n";

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

$warnings = ob_get_clean();
echo "=== PHP WARNINGS/OUTPUT ===\n";
echo $warnings ?: "(none)\n";
echo "=== END ===\n";
