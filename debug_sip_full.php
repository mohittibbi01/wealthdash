<?php
/**
 * WealthDash — Full SIP Debug File
 * Visit: localhost/wealthdash/debug_sip_full.php
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);

header('Content-Type: text/html; charset=UTF-8');

function ok($msg)  { echo "<div style='color:#15803d;font-weight:600;'>✅ $msg</div>"; }
function err($msg) { echo "<div style='color:#dc2626;font-weight:700;'>❌ $msg</div>"; }
function warn($msg){ echo "<div style='color:#d97706;font-weight:600;'>⚠️  $msg</div>"; }
function info($msg){ echo "<div style='color:#1d4ed8;'>ℹ️  $msg</div>"; }
function section($title){ echo "<h2 style='margin:24px 0 8px;padding:8px 12px;background:#f1f5f9;border-left:4px solid #2563eb;font-family:monospace;'>$title</h2>"; }
function row($k,$v){ echo "<div style='margin:3px 0;font-family:monospace;'><b style='color:#475569;min-width:200px;display:inline-block;'>$k</b> $v</div>"; }
?>
<!DOCTYPE html>
<html>
<head><title>WealthDash SIP Debug</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 960px; margin: 30px auto; padding: 0 20px; background: #f8fafc; }
pre { background: #1e293b; color: #e2e8f0; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { background: #334155; color: #fff; padding: 6px 10px; text-align: left; }
td { padding: 5px 10px; border-bottom: 1px solid #e2e8f0; font-family: monospace; }
tr:hover td { background: #f0f9ff; }
.badge-green { background:#dcfce7;color:#15803d;padding:1px 8px;border-radius:9px;font-weight:700;font-size:11px; }
.badge-red   { background:#fee2e2;color:#dc2626;padding:1px 8px;border-radius:9px;font-weight:700;font-size:11px; }
.badge-blue  { background:#dbeafe;color:#1d4ed8;padding:1px 8px;border-radius:9px;font-weight:700;font-size:11px; }
</style>
</head>
<body>
<h1 style="font-family:monospace;">🔍 WealthDash SIP Full Debug</h1>

<?php
$db = DB::conn();

// ── 1. SESSION & USER ────────────────────────────────────────
section("1. Session & User");
row("userId",      $userId);
row("portfolioId", $portfolioId ?: "<span style='color:red'>0 — NOT SET! This is why SIPs save to wrong portfolio</span>");

$portfolios = $db->prepare("SELECT id, name FROM portfolios WHERE user_id = ? ORDER BY id");
$portfolios->execute([$userId]);
$portfolios = $portfolios->fetchAll();
echo "<div style='margin:8px 0;'><b>All portfolios for this user:</b></div>";
foreach ($portfolios as $p) {
    $marker = $p['id'] == $portfolioId ? "← <b>SELECTED</b>" : "";
    echo "<div style='margin:2px 0 2px 16px;font-family:monospace;'>id={$p['id']} name={$p['name']} $marker</div>";
}
if (!$portfolioId) {
    err("portfolio_id is 0 in session — SIPs are being saved to portfolio_id=0 which doesn't exist!");
    warn("Fix: Go to Dashboard and select a portfolio first, then try adding a SIP");
}

// ── 2. sip_schedules TABLE ───────────────────────────────────
section("2. sip_schedules Table — Schema Check");
try {
    $cols = $db->query("SHOW COLUMNS FROM sip_schedules")->fetchAll();
    $colNames = array_column($cols, 'Field');
    echo "<table><tr><th>Column</th><th>Type</th><th>Null</th><th>Default</th></tr>";
    foreach ($cols as $c) {
        $highlight = in_array($c['Field'], ['frequency','notes','is_active','schedule_type']) 
            ? "style='background:#fef9c3'" : "";
        echo "<tr $highlight><td>{$c['Field']}</td><td>{$c['Type']}</td><td>{$c['Null']}</td><td>{$c['Default']}</td></tr>";
    }
    echo "</table>";

    // Check for schedule_type column
    if (in_array('schedule_type', $colNames)) {
        warn("schedule_type column EXISTS — but sip_tracker.php doesn't save it! mf_list.php queries it and always gets 0.");
        err("FIX NEEDED: Either drop schedule_type or update INSERT to save it.");
    } else {
        ok("schedule_type column does NOT exist (correct — we use notes='SWP' for SWP)");
    }

    // Check frequency ENUM
    $freqCol = array_filter($cols, fn($c) => $c['Field'] === 'frequency');
    $freqCol = reset($freqCol);
    if ($freqCol) {
        if (strpos($freqCol['Type'], 'daily') !== false && strpos($freqCol['Type'], 'fortnightly') !== false) {
            ok("frequency ENUM includes daily & fortnightly: {$freqCol['Type']}");
        } else {
            err("frequency ENUM missing daily/fortnightly: {$freqCol['Type']}");
            echo "<pre>-- Run this SQL to fix:\nALTER TABLE sip_schedules MODIFY COLUMN frequency ENUM('daily','weekly','fortnightly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly';</pre>";
        }
    }
} catch (Exception $e) {
    err("sip_schedules table error: " . $e->getMessage());
}

// ── 3. ALL SIPs IN DB (all portfolios) ───────────────────────
section("3. All sip_schedules Rows (all portfolios, last 20)");
try {
    $allSips = $db->query("
        SELECT s.id, s.portfolio_id, s.fund_id, s.sip_amount, s.frequency, 
               s.start_date, s.is_active, s.notes, s.created_at,
               f.scheme_name
        FROM sip_schedules s
        LEFT JOIN funds f ON f.id = s.fund_id
        ORDER BY s.id DESC LIMIT 20
    ")->fetchAll();

    if (empty($allSips)) {
        warn("No SIP records found in sip_schedules at all — SIPs are not being saved to DB!");
    } else {
        ok(count($allSips) . " SIP records found in DB");
        echo "<table><tr><th>id</th><th>portfolio_id</th><th>fund_id</th><th>amount</th><th>freq</th><th>start</th><th>active</th><th>notes(SIP/SWP)</th><th>created</th><th>fund name</th></tr>";
        foreach ($allSips as $s) {
            $type   = (strtoupper($s['notes'] ?? '') === 'SWP') ? "<span class='badge-red'>SWP</span>" : "<span class='badge-green'>SIP</span>";
            $active = $s['is_active'] ? "<span class='badge-green'>Active</span>" : "<span class='badge-red'>Stopped</span>";
            $portMatch = ($s['portfolio_id'] == $portfolioId) ? "" : "<span class='badge-red'>DIFF PORTFOLIO</span>";
            echo "<tr><td>{$s['id']}</td><td>{$s['portfolio_id']} $portMatch</td><td>{$s['fund_id']}</td><td>₹{$s['sip_amount']}</td><td>{$s['frequency']}</td><td>{$s['start_date']}</td><td>$active</td><td>$type</td><td>{$s['created_at']}</td><td style='max-width:200px;overflow:hidden;'>{$s['scheme_name']}</td></tr>";
        }
        echo "</table>";

        // Check if any SIP has wrong portfolio_id
        $wrongPortfolio = array_filter($allSips, fn($s) => $portfolioId && $s['portfolio_id'] != $portfolioId);
        if (!empty($wrongPortfolio)) {
            err(count($wrongPortfolio) . " SIP(s) saved to different portfolio_id than current session! This is likely the main bug.");
        }
    }
} catch (Exception $e) {
    err("Error querying sip_schedules: " . $e->getMessage());
}

// ── 4. SIPs FOR CURRENT PORTFOLIO ────────────────────────────
section("4. SIPs for Current Portfolio (id=$portfolioId)");
if ($portfolioId) {
    $mySips = $db->prepare("
        SELECT s.*, f.scheme_name, f.scheme_code
        FROM sip_schedules s 
        LEFT JOIN funds f ON f.id = s.fund_id
        WHERE s.portfolio_id = ?
        ORDER BY s.is_active DESC, s.id DESC
    ");
    $mySips->execute([$portfolioId]);
    $mySips = $mySips->fetchAll();

    if (empty($mySips)) {
        warn("No SIPs found for portfolio_id=$portfolioId");
    } else {
        ok(count($mySips) . " SIP(s) for this portfolio");
        foreach ($mySips as $s) {
            $type = strtoupper($s['notes'] ?? '') === 'SWP' ? '💸 SWP' : '🔄 SIP';
            $active = $s['is_active'] ? 'Active' : 'Stopped';
            echo "<div style='margin:4px 0 4px 12px;font-family:monospace;'>id={$s['id']} | $type | ₹{$s['sip_amount']}/{$s['frequency']} | start={$s['start_date']} | $active | {$s['scheme_name']}</div>";
        }
    }
} else {
    warn("Skipped — portfolioId is 0");
}

// ── 5. HOLDINGS + SIP JOIN TEST ──────────────────────────────
section("5. Holdings ↔ SIP Join Test (mf_list query simulation)");
if ($portfolioId) {
    $stmt = $db->prepare("
        SELECT h.fund_id, f.scheme_name,
               (SELECT COUNT(*) FROM sip_schedules s 
                WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                  AND s.is_active = 1 AND s.asset_type = 'mf'
                  AND (s.notes IS NULL OR s.notes != 'SWP')) AS sip_count,
               (SELECT COUNT(*) FROM sip_schedules s 
                WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                  AND s.is_active = 1 AND s.asset_type = 'mf'
                  AND s.notes = 'SWP') AS swp_count,
               (SELECT s.sip_amount FROM sip_schedules s
                WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                  AND s.is_active = 1 AND (s.notes IS NULL OR s.notes != 'SWP')
                ORDER BY s.created_at DESC LIMIT 1) AS sip_amount,
               (SELECT s.frequency FROM sip_schedules s
                WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
                  AND s.is_active = 1 AND (s.notes IS NULL OR s.notes != 'SWP')
                ORDER BY s.created_at DESC LIMIT 1) AS sip_freq
        FROM mf_holdings h
        JOIN funds f ON f.id = h.fund_id
        WHERE h.portfolio_id = ? AND h.is_active = 1
        ORDER BY h.total_invested DESC
        LIMIT 25
    ");
    $stmt->execute([$portfolioId]);
    $rows = $stmt->fetchAll();

    echo "<table><tr><th>Fund</th><th>SIP Count</th><th>SWP Count</th><th>SIP Amount</th><th>Frequency</th></tr>";
    $sipFound = 0;
    foreach ($rows as $r) {
        $sipBadge = $r['sip_count'] > 0 ? "<span class='badge-green'>🔄 SIP ₹{$r['sip_amount']}/{$r['sip_freq']}</span>" : "—";
        $swpBadge = $r['swp_count'] > 0 ? "<span class='badge-red'>💸 SWP</span>" : "—";
        if ($r['sip_count'] > 0 || $r['swp_count'] > 0) $sipFound++;
        echo "<tr><td>{$r['scheme_name']}</td><td>{$sipBadge}</td><td>{$swpBadge}</td><td>".($r['sip_amount']?:'—')."</td><td>".($r['sip_freq']?:'—')."</td></tr>";
    }
    echo "</table>";

    if ($sipFound === 0 && !empty($mySips ?? [])) {
        err("SIPs exist in DB but join returns 0 — fund_id mismatch or asset_type issue!");
    } elseif ($sipFound > 0) {
        ok("$sipFound holding(s) show SIP/SWP badge — join is working!");
    }
} else {
    warn("Skipped — portfolioId is 0");
}

// ── 6. NAV DOWNLOAD STATUS ───────────────────────────────────
section("6. NAV Download Progress (for SIP funds)");
try {
    $navProg = $db->query("
        SELECT ndp.*, f.scheme_name
        FROM nav_download_progress ndp
        LEFT JOIN funds f ON f.id = ndp.fund_id
        ORDER BY ndp.updated_at DESC LIMIT 20
    ")->fetchAll();

    if (empty($navProg)) {
        info("No NAV download records — this is fine if no SIPs have been saved yet");
    } else {
        echo "<table><tr><th>Fund</th><th>Scheme Code</th><th>Status</th><th>From Date</th><th>Records</th><th>Updated</th><th>Error</th></tr>";
        foreach ($navProg as $n) {
            $statusClass = match($n['status']) {
                'completed'   => 'badge-green',
                'in_progress' => 'badge-blue',
                'pending'     => 'badge-blue',
                default       => 'badge-red',
            };
            $err = $n['error_message'] ? "<span style='color:red'>{$n['error_message']}</span>" : "—";
            echo "<tr><td>{$n['scheme_name']}</td><td>{$n['scheme_code']}</td><td><span class='{$statusClass}'>{$n['status']}</span></td><td>{$n['from_date']}</td><td>{$n['records_saved']}</td><td>{$n['updated_at']}</td><td>$err</td></tr>";
        }
        echo "</table>";

        $pending = array_filter($navProg, fn($n) => $n['status'] === 'pending');
        if (!empty($pending)) {
            warn(count($pending) . " NAV download(s) are PENDING — they were queued but never actually triggered!");
            info("Reason: sip_nav_fetch.php is triggered by JS from report_sip.php page only. From mf_holdings page (openQuickSip), the trigger runs but redirects away before fetch completes.");
        }

        $errors = array_filter($navProg, fn($n) => $n['status'] === 'error');
        if (!empty($errors)) {
            err(count($errors) . " NAV download(s) FAILED");
        }
    }
} catch (Exception $e) {
    err("nav_download_progress error: " . $e->getMessage());
}

// ── 7. SIMULATE A SAVE (dry run) ─────────────────────────────
section("7. SIP Save Simulation (Dry Run — no actual DB write)");
if ($portfolioId) {
    // Get first fund from holdings
    $testFund = $db->prepare("
        SELECT h.fund_id, f.scheme_name, f.scheme_code, f.latest_nav
        FROM mf_holdings h JOIN funds f ON f.id = h.fund_id
        WHERE h.portfolio_id = ? AND h.is_active = 1
        LIMIT 1
    ");
    $testFund->execute([$portfolioId]);
    $tf = $testFund->fetch();

    if ($tf) {
        row("Test fund", "{$tf['scheme_name']} (id={$tf['fund_id']}, code={$tf['scheme_code']})");

        // Check what _saveQuickSip would send
        row("portfolio_id that JS sends", "window.WD.selectedPortfolio (set in layout.php from session) = <b>$portfolioId</b>");
        row("fund_id", $tf['fund_id']);
        row("frequency (test)", "monthly");
        row("notes for SIP", "NULL (blank)");
        row("notes for SWP", "'SWP'");

        // Check if fund_id matches what would be queried back
        $existingSip = $db->prepare("SELECT id, sip_amount, notes FROM sip_schedules WHERE fund_id=? AND portfolio_id=? AND is_active=1");
        $existingSip->execute([$tf['fund_id'], $portfolioId]);
        $es = $existingSip->fetch();
        if ($es) {
            $type = strtoupper($es['notes'] ?? '') === 'SWP' ? 'SWP' : 'SIP';
            ok("Existing active $type found for this fund: id={$es['id']}, amount=₹{$es['sip_amount']}");
        } else {
            info("No existing active SIP/SWP for this fund in this portfolio");
        }

        // NAV availability check
        $navCount = (int)$db->prepare("SELECT COUNT(*) FROM nav_history WHERE fund_id=?")->execute([$tf['fund_id']]) ? 
            $db->query("SELECT COUNT(*) FROM nav_history WHERE fund_id={$tf['fund_id']}")->fetchColumn() : 0;
        row("nav_history records for this fund", $navCount);
        if ($navCount === 0) {
            warn("No NAV history for this fund — XIRR will need download");
        } else {
            ok("NAV history exists ($navCount records)");
        }
    }
} else {
    warn("Skipped — portfolioId is 0");
}

// ── 8. SUMMARY & FIXES ───────────────────────────────────────
section("8. Summary & Required Fixes");

$issues = [];

if (!$portfolioId) {
    $issues[] = ["CRITICAL", "portfolio_id=0 in session — go to Dashboard, select portfolio, then retry"];
}

// Check schedule_type
try {
    $hasSchedType = $db->query("SHOW COLUMNS FROM sip_schedules LIKE 'schedule_type'")->fetch();
    if ($hasSchedType) {
        $issues[] = ["HIGH", "schedule_type column exists but is never saved — mf_list.php queries it and always returns 0 for SIP count. Run: <code>ALTER TABLE sip_schedules DROP COLUMN schedule_type;</code>"];
    }
} catch(Exception $e) {}

// Check frequency ENUM
try {
    $freqType = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_NAME='sip_schedules' AND COLUMN_NAME='frequency' AND TABLE_SCHEMA=DATABASE()")->fetchColumn();
    if ($freqType && strpos($freqType, 'daily') === false) {
        $issues[] = ["HIGH", "frequency ENUM missing 'daily'/'fortnightly'. Run: <code>ALTER TABLE sip_schedules MODIFY COLUMN frequency ENUM('daily','weekly','fortnightly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly';</code>"];
    }
} catch(Exception $e) {}

// Check SIPs saved to wrong portfolio
try {
    $wrongCount = $db->query("SELECT COUNT(*) FROM sip_schedules WHERE portfolio_id NOT IN (SELECT id FROM portfolios)")->fetchColumn();
    if ($wrongCount > 0) {
        $issues[] = ["HIGH", "$wrongCount SIP(s) saved to non-existent portfolio_id — these will never show up. Run: <code>DELETE FROM sip_schedules WHERE portfolio_id NOT IN (SELECT id FROM portfolios);</code>"];
    }
} catch(Exception $e) {}

if (empty($issues)) {
    ok("No critical issues detected! Check Section 3 above for SIP data.");
} else {
    echo "<div style='background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:16px;margin:8px 0;'>";
    foreach ($issues as [$level, $msg]) {
        $color = $level === 'CRITICAL' ? '#dc2626' : '#d97706';
        echo "<div style='margin:8px 0;'><span style='color:$color;font-weight:700;'>[$level]</span> $msg</div>";
    }
    echo "</div>";
}

echo "<br><div style='background:#f0fdf4;padding:12px;border-radius:8px;font-family:monospace;font-size:13px;'>Debug complete — " . date('Y-m-d H:i:s') . "</div>";
?>
</body></html>
