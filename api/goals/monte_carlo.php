<?php
/**
 * WealthDash — tg001: Monte Carlo Goal Probability Simulator
 *
 * Runs N stochastic simulations on a financial goal using:
 *   - Log-normal monthly returns (mean + volatility)
 *   - Optional monthly SIP contributions
 *   - Inflation-adjusted target
 *   - Percentile bands: P10, P25, P50, P75, P90
 *
 * Actions:
 *   monte_carlo_run      — run simulation (no DB save)
 *   monte_carlo_save     — run + save result to mc_simulations
 *   monte_carlo_history  — list saved simulations for portfolio
 *   monte_carlo_delete   — delete a saved simulation
 *   monte_carlo_presets  — return asset-class return/volatility presets
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$isAdmin     = is_admin();
$db          = DB::conn();

$action      = $_GET['action'] ?? $_POST['action'] ?? 'monte_carlo_run';
$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);

if (!in_array($action, ['monte_carlo_presets'])) {
    if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
        json_response(false, 'Invalid or inaccessible portfolio.');
    }
}

// ══════════════════════════════════════════════════════════════════
// PRESETS — Asset class return/volatility defaults (India-centric)
// ══════════════════════════════════════════════════════════════════
function mc_presets(): array {
    return [
        ['key'=>'equity_large',   'label'=>'Large Cap Equity',      'return'=>12.0, 'volatility'=>15.0, 'icon'=>'📈'],
        ['key'=>'equity_mid',     'label'=>'Mid Cap Equity',         'return'=>14.0, 'volatility'=>20.0, 'icon'=>'📊'],
        ['key'=>'equity_small',   'label'=>'Small Cap Equity',       'return'=>16.0, 'volatility'=>28.0, 'icon'=>'🚀'],
        ['key'=>'flexi_cap',      'label'=>'Flexi Cap / Multi Asset','return'=>13.0, 'volatility'=>16.0, 'icon'=>'🔀'],
        ['key'=>'hybrid_balanced','label'=>'Balanced Hybrid',        'return'=>10.0, 'volatility'=>10.0, 'icon'=>'⚖️'],
        ['key'=>'debt_short',     'label'=>'Short Duration Debt',    'return'=>7.0,  'volatility'=>2.0,  'icon'=>'🏦'],
        ['key'=>'debt_gilt',      'label'=>'Gilt / Long Duration',   'return'=>7.5,  'volatility'=>6.0,  'icon'=>'📜'],
        ['key'=>'nifty50',        'label'=>'Nifty 50 (Index)',       'return'=>12.5, 'volatility'=>16.0, 'icon'=>'🇮🇳'],
        ['key'=>'nifty_next50',   'label'=>'Nifty Next 50',          'return'=>14.0, 'volatility'=>22.0, 'icon'=>'📉'],
        ['key'=>'gold',           'label'=>'Gold / SGB',             'return'=>8.5,  'volatility'=>14.0, 'icon'=>'🥇'],
        ['key'=>'fd',             'label'=>'Fixed Deposit',          'return'=>7.0,  'volatility'=>0.5,  'icon'=>'🔒'],
        ['key'=>'ppf',            'label'=>'PPF / EPF',              'return'=>7.1,  'volatility'=>0.2,  'icon'=>'🏛️'],
        ['key'=>'real_estate',    'label'=>'Real Estate',            'return'=>9.0,  'volatility'=>12.0, 'icon'=>'🏠'],
        ['key'=>'custom',         'label'=>'Custom',                 'return'=>12.0, 'volatility'=>12.0, 'icon'=>'⚙️'],
    ];
}

// ══════════════════════════════════════════════════════════════════
// CORE SIMULATION ENGINE
// ══════════════════════════════════════════════════════════════════
/**
 * Run Monte Carlo simulation.
 *
 * @param float $targetAmount       Goal target in ₹
 * @param float $currentSaved       Already saved / corpus today
 * @param float $monthlyContrib     Monthly SIP / contribution
 * @param float $annualReturn       Expected annual return % (mean)
 * @param float $annualVolatility   Annual volatility / std dev %
 * @param int   $months             Time horizon in months
 * @param int   $iterations         Number of simulations (default 5000)
 * @param float $inflationPct       Annual inflation % (for real target adj)
 * @param float $sipStepUpPct       Annual SIP step-up % (0 = no step-up)
 * @return array
 */
function mc_simulate(
    float $targetAmount,
    float $currentSaved,
    float $monthlyContrib,
    float $annualReturn,
    float $annualVolatility,
    int   $months,
    int   $iterations    = 5000,
    float $inflationPct  = 0.0,
    float $sipStepUpPct  = 0.0
): array {
    if ($months <= 0 || $targetAmount <= 0) {
        return ['error' => 'Invalid inputs: months and target_amount must be > 0'];
    }

    // Convert annual → monthly parameters (log-normal)
    $mu    = ($annualReturn / 100) / 12;                         // monthly mean return
    $sigma = ($annualVolatility / 100) / sqrt(12);               // monthly std dev
    $logMu    = log(1 + $mu) - 0.5 * $sigma * $sigma;           // log-normal mean
    $logSigma = sqrt(log(1 + ($sigma * $sigma) / ((1 + $mu) ** 2))); // log-normal sigma

    // Inflation-adjusted target (future value of target in today's money)
    $realTarget = $targetAmount;
    if ($inflationPct > 0) {
        $realTarget = $targetAmount * pow(1 + $inflationPct / 100, $months / 12);
    }

    $successCount   = 0;
    $finalValues    = [];
    // Track percentile paths: store corpus at each month for P10/P25/P50/P75/P90
    // For efficiency, only collect detailed paths for a subset (200 paths)
    $trackPaths     = min(200, $iterations);
    $paths          = [];           // [path_idx][month] = corpus
    $pathStep       = max(1, (int)floor($iterations / $trackPaths));

    for ($i = 0; $i < $iterations; $i++) {
        $corpus  = $currentSaved;
        $sip     = $monthlyContrib;
        $savePath = ($i % $pathStep === 0);
        $pathData = $savePath ? [] : null;

        for ($m = 1; $m <= $months; $m++) {
            // Log-normal random return for this month
            $z       = mc_box_muller();
            $ret     = exp($logMu + $logSigma * $z);
            $corpus  = $corpus * $ret + $sip;

            if ($savePath && $pathData !== null) {
                // Store every 3rd month for chart efficiency
                if ($m % 3 === 0 || $m === $months) {
                    $pathData[$m] = round($corpus, 2);
                }
            }

            // Annual SIP step-up
            if ($sipStepUpPct > 0 && $m % 12 === 0) {
                $sip *= (1 + $sipStepUpPct / 100);
            }
        }

        $finalValues[] = $corpus;
        if ($corpus >= $realTarget) $successCount++;
        if ($savePath && $pathData !== null) {
            $paths[] = $pathData;
        }
    }

    sort($finalValues);

    // Percentile function
    $perc = function(array $sorted, float $p) use ($iterations): float {
        $idx = (int)floor($p / 100 * ($iterations - 1));
        return $sorted[min($idx, $iterations - 1)];
    };

    $p10 = $perc($finalValues, 10);
    $p25 = $perc($finalValues, 25);
    $p50 = $perc($finalValues, 50);
    $p75 = $perc($finalValues, 75);
    $p90 = $perc($finalValues, 90);

    $successProbability = round($successCount / $iterations * 100, 1);

    // Build fan chart: for each time point, compute percentile across all paths
    // Use paths array — get sorted corpus values at each month point
    $fanChart  = [];
    $chartMonths = [];
    for ($m = 3; $m <= $months; $m += max(1, (int)floor($months / 24))) {
        $chartMonths[] = $m;
    }
    if (!in_array($months, $chartMonths)) $chartMonths[] = $months;

    if (!empty($paths)) {
        foreach ($chartMonths as $cm) {
            $vals = [];
            foreach ($paths as $path) {
                // Find closest month in path
                $closest = null;
                $minDiff = PHP_INT_MAX;
                foreach (array_keys($path) as $pm) {
                    $diff = abs($pm - $cm);
                    if ($diff < $minDiff) { $minDiff = $diff; $closest = $pm; }
                }
                if ($closest !== null) $vals[] = $path[$closest];
            }
            if (!empty($vals)) {
                sort($vals);
                $n = count($vals);
                $fanChart[] = [
                    'month' => $cm,
                    'year'  => round($cm / 12, 1),
                    'p10'   => round($vals[(int)floor(0.10 * ($n - 1))], 0),
                    'p25'   => round($vals[(int)floor(0.25 * ($n - 1))], 0),
                    'p50'   => round($vals[(int)floor(0.50 * ($n - 1))], 0),
                    'p75'   => round($vals[(int)floor(0.75 * ($n - 1))], 0),
                    'p90'   => round($vals[(int)floor(0.90 * ($n - 1))], 0),
                    'target'=> round($realTarget, 0),
                ];
            }
        }
    }

    // Deterministic projection (straight line for reference)
    $detChart = [];
    $r = $mu;
    foreach ($chartMonths as $cm) {
        $fv = $currentSaved * pow(1 + $r, $cm);
        if ($monthlyContrib > 0 && $r > 0) {
            $fv += $monthlyContrib * (pow(1 + $r, $cm) - 1) / $r;
        } else {
            $fv += $monthlyContrib * $cm;
        }
        $detChart[] = ['month' => $cm, 'value' => round($fv, 0)];
    }

    // Risk label
    if ($successProbability >= 85) $riskLabel = ['label'=>'High Confidence','color'=>'#16a34a','emoji'=>'✅'];
    elseif ($successProbability >= 65) $riskLabel = ['label'=>'Moderate Confidence','color'=>'#d97706','emoji'=>'⚠️'];
    elseif ($successProbability >= 40) $riskLabel = ['label'=>'Low Confidence','color'=>'#ea580c','emoji'=>'🔶'];
    else $riskLabel = ['label'=>'At Risk','color'=>'#dc2626','emoji'=>'❌'];

    // How much extra SIP needed to hit 80% probability?
    $sipFor80 = null;
    if ($successProbability < 80 && $months > 0) {
        // Binary search for SIP that gives ~80% probability
        $lo = $monthlyContrib; $hi = $monthlyContrib + $targetAmount / $months * 3;
        for ($attempt = 0; $attempt < 15; $attempt++) {
            $mid = ($lo + $hi) / 2;
            // Quick 500-iteration check
            $hits = 0;
            for ($k = 0; $k < 500; $k++) {
                $c = $currentSaved; $s = $mid;
                for ($m = 1; $m <= $months; $m++) {
                    $c = $c * exp($logMu + $logSigma * mc_box_muller()) + $s;
                    if ($sipStepUpPct > 0 && $m % 12 === 0) $s *= (1 + $sipStepUpPct / 100);
                }
                if ($c >= $realTarget) $hits++;
            }
            $prob80 = $hits / 500 * 100;
            if ($prob80 >= 80) $hi = $mid;
            else $lo = $mid;
            if ($hi - $lo < 100) break;
        }
        $sipFor80 = round(($lo + $hi) / 2, 0);
        if ($sipFor80 <= $monthlyContrib + 100) $sipFor80 = null;
    }

    return [
        'success_probability'  => $successProbability,
        'risk'                 => $riskLabel,
        'iterations'           => $iterations,
        'target_amount'        => round($targetAmount, 2),
        'inflation_adj_target' => round($realTarget, 2),
        'months'               => $months,
        'years'                => round($months / 12, 1),
        'current_saved'        => round($currentSaved, 2),
        'monthly_contrib'      => round($monthlyContrib, 2),
        'annual_return'        => $annualReturn,
        'annual_volatility'    => $annualVolatility,
        'inflation_pct'        => $inflationPct,
        'sip_stepup_pct'       => $sipStepUpPct,
        'percentiles' => [
            'p10' => round($p10, 2),
            'p25' => round($p25, 2),
            'p50' => round($p50, 2),
            'p75' => round($p75, 2),
            'p90' => round($p90, 2),
        ],
        'median_final'        => round($p50, 2),
        'best_case'           => round($p90, 2),
        'worst_case'          => round($p10, 2),
        'sip_needed_for_80pct'=> $sipFor80,
        'fan_chart'           => $fanChart,
        'det_chart'           => $detChart,
        'simulated_at'        => date('Y-m-d H:i:s'),
    ];
}

/**
 * Box-Muller transform: generate N(0,1) random variable
 */
function mc_box_muller(): float {
    static $spare = null;
    if ($spare !== null) { $z = $spare; $spare = null; return $z; }
    do {
        $u = mt_rand() / mt_getrandmax();
        $v = mt_rand() / mt_getrandmax();
    } while ($u <= 0);
    $mag   = sqrt(-2.0 * log($u));
    $spare = $mag * cos(2 * M_PI * $v);
    return   $mag * sin(2 * M_PI * $v);
}

// ══════════════════════════════════════════════════════════════════
// ENSURE TABLE
// ══════════════════════════════════════════════════════════════════
function mc_ensure_table(): void {
    try {
        DB::conn()->exec("
            CREATE TABLE IF NOT EXISTS `mc_simulations` (
              `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `portfolio_id`         INT UNSIGNED NOT NULL,
              `goal_id`              INT UNSIGNED DEFAULT NULL,
              `label`                VARCHAR(150) NOT NULL DEFAULT 'Simulation',
              `target_amount`        DECIMAL(16,2) NOT NULL,
              `current_saved`        DECIMAL(16,2) NOT NULL DEFAULT 0,
              `monthly_contrib`      DECIMAL(12,2) NOT NULL DEFAULT 0,
              `annual_return`        DECIMAL(6,2)  NOT NULL,
              `annual_volatility`    DECIMAL(6,2)  NOT NULL,
              `months`               SMALLINT UNSIGNED NOT NULL,
              `iterations`           INT UNSIGNED  NOT NULL DEFAULT 5000,
              `inflation_pct`        DECIMAL(5,2)  NOT NULL DEFAULT 0,
              `sip_stepup_pct`       DECIMAL(5,2)  NOT NULL DEFAULT 0,
              `success_probability`  DECIMAL(5,1)  NOT NULL,
              `p10`                  DECIMAL(16,2) DEFAULT NULL,
              `p50`                  DECIMAL(16,2) DEFAULT NULL,
              `p90`                  DECIMAL(16,2) DEFAULT NULL,
              `result_json`          LONGTEXT      DEFAULT NULL,
              `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_mc_portfolio` (`portfolio_id`),
              INDEX `idx_mc_goal`      (`goal_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {}
}

// ══════════════════════════════════════════════════════════════════
// PARSE INPUTS
// ══════════════════════════════════════════════════════════════════
function mc_parse_inputs(): array {
    $p = array_merge($_GET, $_POST);
    return [
        'target_amount'    => (float)($p['target_amount']    ?? 0),
        'current_saved'    => (float)($p['current_saved']    ?? 0),
        'monthly_contrib'  => (float)($p['monthly_contrib']  ?? 0),
        'annual_return'    => (float)($p['annual_return']    ?? 12.0),
        'annual_volatility'=> (float)($p['annual_volatility']?? 15.0),
        'months'           => (int)  ($p['months']           ?? 120),
        'iterations'       => min((int)($p['iterations']     ?? 5000), 20000),
        'inflation_pct'    => (float)($p['inflation_pct']    ?? 0.0),
        'sip_stepup_pct'   => (float)($p['sip_stepup_pct']  ?? 0.0),
        'goal_id'          => (int)  ($p['goal_id']          ?? 0),
        'label'            => trim($p['label'] ?? 'Simulation'),
    ];
}

// ══════════════════════════════════════════════════════════════════
// ACTIONS
// ══════════════════════════════════════════════════════════════════

if ($action === 'monte_carlo_presets') {
    json_response(true, 'Asset class presets.', ['presets' => mc_presets()]);
}

elseif ($action === 'monte_carlo_run' || $action === 'monte_carlo_save') {
    $inp = mc_parse_inputs();

    if ($inp['target_amount'] <= 0) json_response(false, 'target_amount required (> 0).');
    if ($inp['months'] <= 0)        json_response(false, 'months required (> 0).');
    if ($inp['annual_volatility'] < 0) json_response(false, 'annual_volatility must be >= 0.');

    set_time_limit(60);
    $result = mc_simulate(
        $inp['target_amount'],
        $inp['current_saved'],
        $inp['monthly_contrib'],
        $inp['annual_return'],
        $inp['annual_volatility'],
        $inp['months'],
        $inp['iterations'],
        $inp['inflation_pct'],
        $inp['sip_stepup_pct']
    );

    if (isset($result['error'])) json_response(false, $result['error']);

    if ($action === 'monte_carlo_save') {
        mc_ensure_table();
        $perc = $result['percentiles'];
        DB::execute(
            "INSERT INTO mc_simulations
             (portfolio_id, goal_id, label, target_amount, current_saved, monthly_contrib,
              annual_return, annual_volatility, months, iterations, inflation_pct, sip_stepup_pct,
              success_probability, p10, p50, p90, result_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $portfolioId,
                $inp['goal_id'] ?: null,
                $inp['label'],
                $inp['target_amount'],
                $inp['current_saved'],
                $inp['monthly_contrib'],
                $inp['annual_return'],
                $inp['annual_volatility'],
                $inp['months'],
                $inp['iterations'],
                $inp['inflation_pct'],
                $inp['sip_stepup_pct'],
                $result['success_probability'],
                $perc['p10'],
                $perc['p50'],
                $perc['p90'],
                json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]
        );
        $savedId = (int)$db->lastInsertId();
        $result['saved_id'] = $savedId;
        json_response(true, 'Simulation saved.', $result);
    }

    json_response(true, 'Monte Carlo simulation complete.', $result);
}

elseif ($action === 'monte_carlo_history') {
    mc_ensure_table();
    $rows = DB::fetchAll(
        "SELECT m.id, m.goal_id, m.label, m.target_amount, m.current_saved, m.monthly_contrib,
                m.annual_return, m.annual_volatility, m.months, m.iterations,
                m.inflation_pct, m.sip_stepup_pct, m.success_probability,
                m.p10, m.p50, m.p90, m.created_at,
                g.name AS goal_name
         FROM mc_simulations m
         LEFT JOIN investment_goals g ON g.id = m.goal_id
         WHERE m.portfolio_id = ?
         ORDER BY m.created_at DESC
         LIMIT 50",
        [$portfolioId]
    );
    foreach ($rows as &$r) {
        $r['success_probability'] = (float)$r['success_probability'];
        $r['target_amount']       = (float)$r['target_amount'];
        $r['monthly_contrib']     = (float)$r['monthly_contrib'];
        $r['p10'] = (float)$r['p10'];
        $r['p50'] = (float)$r['p50'];
        $r['p90'] = (float)$r['p90'];
    }
    unset($r);
    json_response(true, 'Simulation history.', ['simulations' => $rows, 'count' => count($rows)]);
}

elseif ($action === 'monte_carlo_delete') {
    mc_ensure_table();
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json_response(false, 'id required.');
    $row = DB::fetchRow("SELECT id FROM mc_simulations WHERE id=? AND portfolio_id=?", [$id, $portfolioId]);
    if (!$row) json_response(false, 'Simulation not found.');
    DB::execute("DELETE FROM mc_simulations WHERE id=?", [$id]);
    json_response(true, 'Simulation deleted.');
}

else {
    json_response(false, 'Unknown action: ' . htmlspecialchars($action));
}
