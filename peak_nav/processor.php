	<?php
	/**
	 * WealthDash — Peak NAV Processor (Parallel curl_multi)
	 * Path: wealthdash/peak_nav/processor.php
	 *
	 * Opens in its own tab/window — runs independently
	 * Status page polls api.php to track progress
	 */

	define('DB_HOST',     'localhost');
	define('DB_USER',     'root');
	define('DB_PASS',     '');
	define('DB_NAME',     'wealthdash');
	define('EXEC_LIMIT',  85);
	define('API_TIMEOUT', 20);
	define('MFAPI_BASE',  'https://api.mfapi.in/mf/');

	// Dynamic parallel size from URL param
	$PARALLEL_SIZE = isset($_GET['parallel']) ? (int)$_GET['parallel'] : 8;
	$PARALLEL_SIZE = max(1, min(50, $PARALLEL_SIZE));

	set_time_limit(180);
	header('Content-Type: text/plain; charset=utf-8');
	// Disable output buffering so logs stream live
	if (ob_get_level()) ob_end_clean();

	$runStart = time();

	function lm(string $msg): void {
		echo '[' . date('H:i:s') . '] ' . $msg . "\n";
		flush();
	}

	function overtime(): bool {
		global $runStart;
		return (time() - $runStart) >= EXEC_LIMIT;
	}

	// ── DB ──────────────────────────────────────────────────
	try {
		$pdo = new PDO(
			'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
			DB_USER, DB_PASS,
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
	} catch (PDOException $e) {
		die('[FATAL] DB: ' . $e->getMessage() . "\n");
	}

	lm('=== WealthDash Peak NAV Processor ===');
	lm('Parallel: ' . $PARALLEL_SIZE);

	$today = date('Y-m-d');

	// Mark run as active in app_settings
	$pdo->prepare("UPDATE app_settings SET setting_val='running' WHERE setting_key='peak_nav_status'")
		->execute();

	// Fetch schemes needing work
	$schemes = $pdo->query("
		SELECT
			p.scheme_code,
			p.last_processed_date,
			COALESCE(f.highest_nav, 0) AS highest_nav,
			f.highest_nav_date
		FROM mf_peak_progress p
		LEFT JOIN funds f ON f.scheme_code = p.scheme_code
		WHERE p.status IN ('pending','error')
		   OR (p.status = 'completed' AND p.last_processed_date < CURDATE())
		ORDER BY
			CASE p.status WHEN 'pending' THEN 1 WHEN 'error' THEN 2 ELSE 3 END,
			p.scheme_code
	")->fetchAll(PDO::FETCH_ASSOC);

	$total = count($schemes);

	if ($total === 0) {
		lm("All schemes already up to date.");
		$pdo->prepare("UPDATE app_settings SET setting_val='idle' WHERE setting_key='peak_nav_status'")->execute();
		echo "ALL_COMPLETE\n";
		exit;
	}

	lm("Schemes to process: {$total}");

	$done = 0;
	$errs = 0;

	// ── PARALLEL FETCH with curl_multi ──────────────────────
	function parallelFetch(array $batch, int $timeout, int $parallelSize): array {
		$mh      = curl_multi_init();
		$handles = [];

		// Note: $mh is a variable, not escaped
		curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $parallelSize);

		foreach ($batch as $row) {
			$ch = curl_init(MFAPI_BASE . $row['scheme_code']);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_CONNECTTIMEOUT => 8,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_USERAGENT      => 'WealthDash/5.0',
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_ENCODING       => 'gzip',
			]);
			curl_multi_add_handle($mh, $ch);
			$handles[$row['scheme_code']] = $ch;
		}

		$running = null;
		do {
			$status = curl_multi_exec($mh, $running);
			if ($running) curl_multi_select($mh, 1.0);
		} while ($running > 0 && $status == CURLM_OK);

		$results = [];
		foreach ($handles as $sc => $ch) {
			$body = curl_multi_getcontent($ch);
			$err  = curl_error($ch);
			$results[$sc] = ($err || !$body) ? null : $body;
			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}
		curl_multi_close($mh);
		return $results;
	}

	// ── PROCESS ONE RESULT ──────────────────────────────────
	function processOne(array $row, ?string $raw, PDO $pdo, string $today): bool {
		$sc = $row['scheme_code'];
		if ($raw === null) return false;

		$json = json_decode($raw, true);
		if (empty($json['data'])) {
			$pdo->prepare("UPDATE mf_peak_progress SET status='completed', last_processed_date=?, error_message=NULL, updated_at=NOW() WHERE scheme_code=?")
				->execute([$today, $sc]);
			return true;
		}

		$lastProcessed = $row['last_processed_date'];
		$peakNAV       = (float)$row['highest_nav'];
		$peakDate      = $row['highest_nav_date'];

		foreach ($json['data'] as $entry) {
			$p = explode('-', $entry['date']);
			if (count($p) !== 3) continue;
			$isoDate = "{$p[2]}-{$p[1]}-{$p[0]}";
			if ($lastProcessed && $isoDate <= $lastProcessed) continue;
			$nav = (float)$entry['nav'];
			if ($nav > $peakNAV) { $peakNAV = $nav; $peakDate = $isoDate; }
		}

		if ($peakNAV > (float)$row['highest_nav']) {
			$pdo->prepare("UPDATE funds SET highest_nav=?, highest_nav_date=?, updated_at=NOW() WHERE scheme_code=?")
				->execute([$peakNAV, $peakDate, $sc]);
		}

		$pdo->prepare("UPDATE mf_peak_progress SET status='completed', last_processed_date=?, error_message=NULL, updated_at=NOW() WHERE scheme_code=?")
			->execute([$today, $sc]);
		return true;
	}

	// ── MAIN LOOP ───────────────────────────────────────────
	$chunks    = array_chunk($schemes, $PARALLEL_SIZE);
	$checkStop = $pdo->prepare("SELECT setting_val FROM app_settings WHERE setting_key='peak_nav_stop'");

	foreach ($chunks as $chunkIdx => $chunk) {

		// ── Check stop flag ──────────────────────────────
		$checkStop->execute();
		$stopVal = $checkStop->fetchColumn();
		if ($stopVal === '1') {
			$codes = array_column($chunk, 'scheme_code');
			$ph    = implode(',', array_fill(0, count($codes), '?'));
			$pdo->prepare("UPDATE mf_peak_progress SET status='pending', updated_at=NOW() WHERE scheme_code IN ({$ph}) AND status='in_progress'")->execute($codes);
			$pdo->exec("UPDATE app_settings SET setting_val='0' WHERE setting_key='peak_nav_stop'");
			lm("⛔ Stop requested. Halted at chunk #{$chunkIdx}. Run again to continue.");
			$pdo->prepare("UPDATE app_settings SET setting_val='idle' WHERE setting_key='peak_nav_status'")->execute();
			echo "STOPPED\n";
			exit;
		}

		if (overtime()) {
			// Revert in_progress back to pending
			$codes = array_column($chunk, 'scheme_code');
			$ph    = implode(',', array_fill(0, count($codes), '?'));
			$pdo->prepare("UPDATE mf_peak_progress SET status='pending', updated_at=NOW() WHERE scheme_code IN ({$ph}) AND status='in_progress'")
				->execute($codes);
			lm("Time limit reached at chunk #{$chunkIdx}. Run again to continue.");
			break;
		}

		// Mark batch as in_progress
		$codes = array_column($chunk, 'scheme_code');
		$ph    = implode(',', array_fill(0, count($codes), '?'));
		$pdo->prepare("UPDATE mf_peak_progress SET status='in_progress', updated_at=NOW() WHERE scheme_code IN ({$ph})")
			->execute($codes);

		// First parallel attempt
		$results = parallelFetch($chunk, API_TIMEOUT, $PARALLEL_SIZE);

		$failed = [];
		foreach ($chunk as $row) {
			$raw = $results[$row['scheme_code']] ?? null;
			if (processOne($row, $raw, $pdo, $today)) {
				$done++;
			} else {
				$failed[] = $row;
			}
		}

		// Retry failed ones
		if (!empty($failed)) {
			usleep(300000); // 0.3s pause
			$retryResults = parallelFetch($failed, API_TIMEOUT + 10, max(1, intdiv($PARALLEL_SIZE, 2)));
			foreach ($failed as $row) {
				$raw = $retryResults[$row['scheme_code']] ?? null;
				if (processOne($row, $raw, $pdo, $today)) {
					$done++;
				} else {
					$pdo->prepare("UPDATE mf_peak_progress SET status='error', error_message='API timeout after retry', updated_at=NOW() WHERE scheme_code=?")
						->execute([$row['scheme_code']]);
					$errs++;
				}
			}
		}

		if (($chunkIdx + 1) % 5 === 0) {
			$elapsed = time() - $runStart;
			lm("Progress | Done: {$done} | Errors: {$errs} | " . round($done / max(1,$elapsed), 1) . "/s");
		}
	}

	$elapsed   = time() - $runStart;
	$remaining = $pdo->query("SELECT COUNT(*) FROM mf_peak_progress WHERE status IN ('pending','error','in_progress')")->fetchColumn();

	lm('---');
	lm("Done: {$done} | Errors: {$errs} | Time: {$elapsed}s");
	lm("Remaining: {$remaining}");

	// Mark run as idle
	$pdo->prepare("UPDATE app_settings SET setting_val='idle' WHERE setting_key='peak_nav_status'")->execute();

	if ((int)$remaining === 0) {
		echo "ALL_COMPLETE\n";
	} else {
		echo "TIME_LIMIT\n";
	}

	$pdo = null;