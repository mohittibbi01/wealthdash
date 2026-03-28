<?php
/**
 * WealthDash — NPS NAV Scraper (t99 — Complete Rewrite)
 *
 * Sources (in order):
 *   1. npstrust.org.in JSON (primary)
 *   2. NSDL CRA scrape (fallback)
 *   3. Manual fallback via Admin panel
 *
 * Modes:
 *   php nps_nav_scraper.php            → daily (today's NAV)
 *   php nps_nav_scraper.php backfill   → last N years history (run once)
 *   php nps_nav_scraper.php returns    → recalculate 1Y/3Y/5Y returns
 *
 * Cron: Daily at 9 PM | Admin panel se bhi trigger hota hai
 */
declare(strict_types=1);
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

$mode  = $argv[1] ?? 'daily';
$years = (int)(DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='nps_historical_years'") ?: 5);

log_nps("=== NPS NAV Scraper | mode={$mode} | " . date('Y-m-d H:i:s') . " ===");
update_nps_status('running');

try {
    $schemes = DB::fetchAll("SELECT * FROM nps_schemes WHERE is_active=1 ORDER BY pfm_name, tier, scheme_name");

    if (empty($schemes)) {
        log_nps("No active NPS schemes. Add via Admin panel.");
        update_nps_status('no_schemes');
        exit(0);
    }

    $total   = count($schemes);
    $updated = 0;
    $failed  = 0;

    // ── BACKFILL MODE ────────────────────────────────────────────────────────
    if ($mode === 'backfill') {
        $fromDate = date('Y-m-d', strtotime("-{$years} years"));
        log_nps("Backfill: {$total} schemes from {$fromDate}");

        foreach ($schemes as $s) {
            $rows = fetch_nps_history($s['scheme_code'], $fromDate, date('Y-m-d'));
            if ($rows) {
                $ins = insert_nav_bulk((int)$s['id'], $rows);
                log_nps("  [{$s['scheme_code']}] {$ins} records saved");
                $updated++;
            } else {
                log_nps("  [{$s['scheme_code']}] No data — enter manually via Admin");
                $failed++;
            }
            usleep(400_000);
        }
        recalculate_returns($schemes);

    // ── RETURNS RECALC ONLY ──────────────────────────────────────────────────
    } elseif ($mode === 'returns') {
        recalculate_returns($schemes);
        log_nps("Returns recalculation done.");

    // ── DAILY MODE ───────────────────────────────────────────────────────────
    } else {
        $today    = date('Y-m-d');
        $bulkNavs = fetch_bulk_navs_today();
        log_nps("Daily | found " . count($bulkNavs) . " NAVs from bulk fetch");

        foreach ($schemes as $s) {
            $nav = $bulkNavs[$s['scheme_code']] ?? fetch_single_nav($s['scheme_code']);

            if ($nav && $nav > 0) {
                DB::run(
                    "INSERT INTO nps_nav_history (scheme_id, nav_date, nav)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE nav=VALUES(nav)",
                    [$s['id'], $today, $nav]
                );
                DB::run(
                    "UPDATE nps_schemes SET latest_nav=?, latest_nav_date=?, updated_at=NOW() WHERE id=?",
                    [$nav, $today, $s['id']]
                );
                $updated++;
            } else {
                log_nps("  [{$s['scheme_code']}] NAV not available — manual update needed");
                $failed++;
            }
        }

        if ($updated > 0) recalculate_returns($schemes);
    }

    $status = match(true) {
        $failed === 0            => 'success',
        $updated > 0             => 'partial',
        default                  => 'failed',
    };
    log_nps("Done. Updated={$updated} Failed={$failed}");
    update_nps_status($status, "Updated:{$updated} Failed:{$failed}");

} catch (Throwable $e) {
    log_nps("ERROR: " . $e->getMessage());
    update_nps_status('error', $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════════

function fetch_bulk_navs_today(): array {
    $navs = [];

    // Source 1: npstrust.org.in
    $json = nps_http_get('https://www.npstrust.org.in/content/pension-fund-wise-scheme-performance-detail');
    if ($json) {
        $data = json_decode($json, true);
        if (is_array($data)) {
            foreach ($data as $row) {
                $code = $row['scheme_code'] ?? $row['SchemeCode'] ?? '';
                $nav  = (float)($row['nav'] ?? $row['NAV'] ?? 0);
                if ($code && $nav > 0) $navs[$code] = $nav;
            }
        }
    }

    // Source 2: NSDL CRA (fallback)
    if (empty($navs)) {
        $html = nps_http_get('https://npscra.nsdl.co.in/nps-nav-summary.php');
        if ($html) {
            preg_match_all('/([A-Z]{2}-[A-Z0-9\-]{4,30})\D+([0-9]{2,5}\.[0-9]{4})/i', $html, $m, PREG_SET_ORDER);
            foreach ($m as $match) $navs[$match[1]] = (float)$match[2];
        }
    }

    return $navs;
}

function fetch_single_nav(string $code): ?float {
    $json = nps_http_get("https://www.npstrust.org.in/content/nav?scheme_code=" . urlencode($code));
    if ($json) {
        $d = json_decode($json, true);
        $v = (float)($d['nav'] ?? $d['NAV'] ?? 0);
        if ($v > 0) return $v;
    }
    return null;
}

function fetch_nps_history(string $code, string $from, string $to): array {
    $url = sprintf(
        'https://www.npstrust.org.in/content/scheme-nav-history?scheme_code=%s&from_date=%s&to_date=%s',
        urlencode($code), urlencode($from), urlencode($to)
    );
    $json = nps_http_get($url, 30);
    if (!$json) return [];

    $data = json_decode($json, true);
    if (!is_array($data)) return [];

    $rows = [];
    foreach ($data as $row) {
        $date = $row['nav_date'] ?? $row['Date'] ?? $row['date'] ?? null;
        $nav  = (float)($row['nav'] ?? $row['NAV'] ?? 0);
        if ($date && $nav > 0) {
            $rows[] = ['nav_date' => date('Y-m-d', strtotime($date)), 'nav' => $nav];
        }
    }
    return $rows;
}

function insert_nav_bulk(int $schemeId, array $rows): int {
    $stmt = DB::pdo()->prepare(
        "INSERT IGNORE INTO nps_nav_history (scheme_id, nav_date, nav) VALUES (?, ?, ?)"
    );
    $ins = 0;
    foreach ($rows as $r) {
        $stmt->execute([$schemeId, $r['nav_date'], $r['nav']]);
        $ins += $stmt->rowCount();
    }
    // Update latest on scheme
    usort($rows, fn($a, $b) => strcmp($b['nav_date'], $a['nav_date']));
    DB::run(
        "UPDATE nps_schemes SET latest_nav=?, latest_nav_date=?, updated_at=NOW() WHERE id=?",
        [$rows[0]['nav'], $rows[0]['nav_date'], $schemeId]
    );
    return $ins;
}

function recalculate_returns(array $schemes): void {
    log_nps("Recalculating 1Y/3Y/5Y returns for " . count($schemes) . " schemes...");
    $d1y = date('Y-m-d', strtotime('-1 year'));
    $d3y = date('Y-m-d', strtotime('-3 years'));
    $d5y = date('Y-m-d', strtotime('-5 years'));

    foreach ($schemes as $s) {
        $sid    = (int)$s['id'];
        $navNow = (float)($s['latest_nav'] ?? 0);
        if ($navNow <= 0) {
            // Refresh from DB
            $navNow = (float)(DB::fetchVal("SELECT latest_nav FROM nps_schemes WHERE id=?", [$sid]) ?: 0);
            if ($navNow <= 0) continue;
        }

        $oldest = DB::fetchOne(
            "SELECT nav, nav_date FROM nps_nav_history WHERE scheme_id=? ORDER BY nav_date ASC LIMIT 1",
            [$sid]
        );

        $rSince = null;
        if ($oldest && (float)$oldest['nav'] > 0) {
            $days  = max(1, (int)((time() - strtotime($oldest['nav_date'])) / 86400));
            $yrs   = $days / 365.25;
            $rSince = round((pow($navNow / (float)$oldest['nav'], 1 / $yrs) - 1) * 100, 4);
        }

        DB::run(
            "UPDATE nps_schemes
             SET return_1y=?, return_3y=?, return_5y=?, return_since=?,
                 nav_returns_updated_at=NOW()
             WHERE id=?",
            [
                nav_period_return($sid, $d1y, $navNow),
                nav_period_return($sid, $d3y, $navNow),
                nav_period_return($sid, $d5y, $navNow),
                $rSince,
                $sid,
            ]
        );
    }
}

function nav_period_return(int $sid, string $fromDate, float $navNow): ?float {
    $row = DB::fetchOne(
        "SELECT nav, nav_date FROM nps_nav_history
         WHERE scheme_id=? AND nav_date <= ?
         ORDER BY nav_date DESC LIMIT 1",
        [$sid, $fromDate]
    );
    if (!$row || (float)$row['nav'] <= 0) return null;
    $days = max(1, (int)((time() - strtotime($row['nav_date'])) / 86400));
    $yrs  = $days / 365.25;
    if ($yrs < 0.5) return null;
    return round((pow($navNow / (float)$row['nav'], 1 / $yrs) - 1) * 100, 4);
}

function nps_http_get(string $url, int $timeout = 15): ?string {
    $ctx = stream_context_create([
        'http' => [
            'timeout'    => $timeout,
            'user_agent' => 'Mozilla/5.0 (WealthDash/2.0 NPS-Bot)',
            'header'     => "Accept: application/json, text/html\r\nAccept-Language: en-IN\r\n",
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    return $res !== false ? $res : null;
}

function update_nps_status(string $status, string $detail = ''): void {
    $val = $detail ? "{$status}: {$detail}" : $status;
    DB::run("UPDATE app_settings SET setting_val=?, updated_at=NOW() WHERE setting_key='nps_nav_last_status'", [$val]);
    DB::run("UPDATE app_settings SET setting_val=NOW() WHERE setting_key='nps_nav_last_run'");
}

function log_nps(string $msg): void {
    echo "[" . date('H:i:s') . "] " . $msg . "\n";
}
