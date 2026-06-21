<?php
/**
 * WealthDash — t417: DB Migration Runner v2
 * database/migrate.php
 *
 * Usage:
 *   php database/migrate.php                   — run all pending migrations
 *   php database/migrate.php --dry-run         — show what would run, don't execute
 *   php database/migrate.php --from=25         — run migrations with numeric prefix >= 25
 *   php database/migrate.php --file=t417_migration_log.sql  — run a single file
 *   php database/migrate.php --rollback        — undo last batch (removes log entries)
 *   php database/migrate.php --status          — show migration status table only
 *   php database/migrate.php --force           — re-run already-ran migrations (danger!)
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

if (php_sapi_name() !== 'cli') { die("This script must be run from the command line.\n"); }

// ── ANSI colours ───────────────────────────────────────────────────────────
function clr(string $text, string $colour): string {
    $map = ['red'=>"\033[31m",'green'=>"\033[32m",'yellow'=>"\033[33m",
            'cyan'=>"\033[36m",'bold'=>"\033[1m",'dim'=>"\033[2m",'reset'=>"\033[0m"];
    return ($map[$colour] ?? '') . $text . $map['reset'];
}
function println(string $line = ''): void { echo $line . "\n"; }
function hr(string $c = '─', int $n = 62): void { println(str_repeat($c, $n)); }

// ── CLI options ────────────────────────────────────────────────────────────
$opts       = getopt('', ['dry-run','from:','file:','rollback','status','force']);
$dryRun     = isset($opts['dry-run']);
$fromNum    = isset($opts['from'])   ? (int)$opts['from'] : 0;
$onlyFile   = $opts['file']  ?? null;
$doRollback = isset($opts['rollback']);
$statusOnly = isset($opts['status']);
$force      = isset($opts['force']);

// ── DB Connection ──────────────────────────────────────────────────────────
try {
    $db = DB::conn();
} catch (Throwable $e) {
    println(clr('Cannot connect to database: ' . $e->getMessage(), 'red')); exit(1);
}

// ── Ensure migration_log table ─────────────────────────────────────────────
function ensureMigrationLog(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `migration_log` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `filename`    VARCHAR(255) NOT NULL,
        `checksum`    VARCHAR(64)  NOT NULL,
        `batch`       SMALLINT     NOT NULL DEFAULT 1,
        `executed_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `duration_ms` INT          NULL,
        `notes`       TEXT         NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_filename` (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ── Collect all SQL files ──────────────────────────────────────────────────
function collectMigrationFiles(): array {
    $root  = dirname(__DIR__) . '/database';
    $files = [];
    foreach (glob($root . '/*.sql')            as $f) $files[] = ['path'=>$f,'name'=>basename($f)];
    foreach (glob($root . '/migrations/*.sql') as $f) $files[] = ['path'=>$f,'name'=>basename($f)];
    usort($files, fn($a,$b) => migSortKey($a['name']) <=> migSortKey($b['name']));
    return $files;
}

function migSortKey(string $name): string {
    if (preg_match('/^(\d+)[_\-]/', $name, $m)) return sprintf('%06d', (int)$m[1]) . $name;
    if (preg_match('/^t(\d+)[_\-]/i', $name, $m)) return '9' . sprintf('%06d', (int)$m[1]) . $name;
    return '999999' . $name;
}

function migNumber(string $name): int {
    if (preg_match('/^(\d+)/', $name, $m))   return (int)$m[1];
    if (preg_match('/^t(\d+)/i', $name, $m)) return (int)$m[1];
    return 0;
}

// ── Fetch already-run log ──────────────────────────────────────────────────
function fetchRan(PDO $db): array {
    try {
        $rows = $db->query("SELECT filename, checksum, batch, executed_at FROM migration_log ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $map  = [];
        foreach ($rows as $r) $map[$r['filename']] = $r;
        return $map;
    } catch (Throwable $e) { return []; }
}

function nextBatch(PDO $db): int {
    try { $m = $db->query("SELECT MAX(batch) FROM migration_log")->fetchColumn(); return $m ? (int)$m + 1 : 1; }
    catch (Throwable $e) { return 1; }
}

// ── Split SQL into statements ──────────────────────────────────────────────
function splitStmts(string $sql): array {
    $stmts = []; $buf = ''; $delim = ';';
    foreach (explode("\n", $sql) as $line) {
        $t = trim($line);
        if (str_starts_with($t,'--') || str_starts_with($t,'#')) continue;
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) { $delim=$m[1]; continue; }
        $buf .= $line . "\n";
        if (str_ends_with(rtrim($line), $delim)) {
            $s = trim($delim===';' ? rtrim(trim($buf),';') : substr(trim($buf),0,-strlen($delim)));
            if ($s !== '') $stmts[] = $s;
            $buf = '';
        }
    }
    $s = trim($buf);
    if ($s !== '' && $s !== $delim) $stmts[] = $s;
    return $stmts;
}

// ── Run one file ───────────────────────────────────────────────────────────
function runMigration(PDO $db, array $file, int $batch, bool $dryRun): array {
    $sql      = file_get_contents($file['path']);
    $checksum = hash('sha256', $sql);
    $stmts    = splitStmts($sql);
    $t0       = microtime(true);
    if ($dryRun) return ['ok'=>true,'stmts'=>count($stmts),'ms'=>0,'checksum'=>$checksum,'dry'=>true];
    $db->beginTransaction();
    try {
        foreach ($stmts as $stmt) $db->exec($stmt);
        $db->prepare("INSERT INTO migration_log (filename,checksum,batch,duration_ms) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE checksum=VALUES(checksum),batch=VALUES(batch),executed_at=NOW(),duration_ms=VALUES(duration_ms)")
           ->execute([$file['name'],$checksum,$batch,(int)((microtime(true)-$t0)*1000)]);
        $db->commit();
        return ['ok'=>true,'stmts'=>count($stmts),'ms'=>(int)((microtime(true)-$t0)*1000),'checksum'=>$checksum];
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok'=>false,'error'=>$e->getMessage(),'ms'=>(int)((microtime(true)-$t0)*1000)];
    }
}

// ── MAIN ───────────────────────────────────────────────────────────────────
println(); println(clr('  WealthDash — DB Migration Runner v2', 'bold')); hr('═');
ensureMigrationLog($db);
$allFiles = collectMigrationFiles();
$ran      = fetchRan($db);

// --rollback
if ($doRollback) {
    $maxBatch = $db->query("SELECT MAX(batch) FROM migration_log")->fetchColumn();
    if (!$maxBatch) { println(clr('  Nothing to roll back.','yellow')); exit(0); }
    $toRemove = $db->query("SELECT filename FROM migration_log WHERE batch=$maxBatch ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($toRemove)) { println(clr('  Nothing to roll back.','yellow')); exit(0); }
    println(clr("  Rolling back batch #$maxBatch (" . count($toRemove) . " migration(s)):",'yellow'));
    foreach ($toRemove as $fn) println('  ' . clr('↩','yellow') . '  ' . $fn);
    $ph = implode(',', array_fill(0, count($toRemove), '?'));
    $db->prepare("DELETE FROM migration_log WHERE filename IN ($ph)")->execute($toRemove);
    println(clr("  Batch #$maxBatch removed from migration_log.",'green'));
    println(clr('  WARNING: SQL was NOT reversed — schema changes remain in DB.','yellow'));
    println(); exit(0);
}

// --status
if ($statusOnly) {
    $pending=0; $done=0;
    foreach ($allFiles as $f) { if (isset($ran[$f['name']])) $done++; else $pending++; }
    hr();
    printf("  %-52s %s\n", clr('File','bold'), clr('Status','bold')); hr();
    foreach ($allFiles as $f) {
        $isRan  = isset($ran[$f['name']]);
        $status = $isRan
            ? clr('✅ batch #'.$ran[$f['name']]['batch'].'  '.$ran[$f['name']]['executed_at'],'green')
            : clr('⏳ pending','yellow');
        printf("  %-52s %s\n", $f['name'], $status);
    }
    hr();
    println("  Total: ".count($allFiles)."  |  ".clr("Done: $done",'green')."  |  ".clr("Pending: $pending",'yellow'));
    println(); exit(0);
}

// Build run list
$toRun = [];
foreach ($allFiles as $f) {
    if ($onlyFile && $f['name'] !== $onlyFile) continue;
    if ($fromNum > 0 && migNumber($f['name']) < $fromNum) continue;
    if (!$force && isset($ran[$f['name']])) continue;
    $toRun[] = $f;
}

if (empty($toRun)) {
    println(clr('  All migrations are up to date. Nothing to run.','green')); println(); exit(0);
}

$batch = nextBatch($db);
$mode  = $dryRun ? clr('DRY RUN','yellow') : clr('LIVE','cyan');
println("  Mode: $mode  |  Batch: #$batch  |  Files: " . count($toRun));
if ($dryRun) println(clr('  (No SQL will be executed)','dim'));
hr();

$ok=0; $fail=0;
foreach ($toRun as $f) {
    $label  = str_pad($f['name'], 50);
    $result = runMigration($db, $f, $batch, $dryRun);
    if ($result['ok']) {
        $tag  = $dryRun ? clr('[DRY] ','yellow') : clr('[OK]  ','green');
        $info = $dryRun ? clr($result['stmts'].' stmt(s)','dim') : clr($result['stmts'].' stmt(s) '.$result['ms'].'ms','dim');
        println("  $tag $label $info");
        $ok++;
    } else {
        println("  " . clr('[ERR] ','red') . "$label " . clr($result['error'],'red'));
        $fail++;
    }
}

hr();
if ($dryRun) {
    println("  " . clr("DRY RUN complete.",'yellow') . "  $ok file(s) would run.");
} else {
    $fStr = $fail > 0 ? clr("$fail failed",'red') : clr("0 failed",'dim');
    println("  " . clr("Migration complete.",'green') . "  $ok succeeded  |  $fStr");
}
println(); exit($fail > 0 ? 1 : 0);
