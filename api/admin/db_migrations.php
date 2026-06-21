<?php
/**
 * WealthDash — t417: DB Migration Runner v2 — API
 * api/admin/db_migrations.php
 *
 * Actions:
 *   admin_migrations_list   — list all SQL files with run status
 *   admin_migrations_run    — run one or all pending migrations
 *   admin_migrations_rollback — remove last batch from log
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$db = DB::conn();

// ── Ensure migration_log table ──────────────────────────────────────────────
function migEnsureLog(PDO $db): void {
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

// ── Helpers ─────────────────────────────────────────────────────────────────
function migSortKey(string $name): string {
    if (preg_match('/^(\d+)[_\-]/', $name, $m)) return sprintf('%06d', (int)$m[1]) . $name;
    if (preg_match('/^t(\d+)[_\-]/i', $name, $m)) return '9' . sprintf('%06d', (int)$m[1]) . $name;
    return '999999' . $name;
}

function migCollectFiles(): array {
    $root  = APP_ROOT . '/database';
    $files = [];
    foreach (glob($root . '/*.sql')            as $f) $files[] = ['path'=>$f,'name'=>basename($f),'dir'=>'database'];
    foreach (glob($root . '/migrations/*.sql') as $f) $files[] = ['path'=>$f,'name'=>basename($f),'dir'=>'migrations'];
    usort($files, fn($a,$b) => migSortKey($a['name']) <=> migSortKey($b['name']));
    return $files;
}

function migFetchRan(PDO $db): array {
    try {
        $rows = $db->query("SELECT filename, checksum, batch, executed_at, duration_ms FROM migration_log ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $map  = [];
        foreach ($rows as $r) $map[$r['filename']] = $r;
        return $map;
    } catch (Throwable $e) { return []; }
}

function migNextBatch(PDO $db): int {
    try { $m = $db->query("SELECT MAX(batch) FROM migration_log")->fetchColumn(); return $m ? (int)$m + 1 : 1; }
    catch (Throwable $e) { return 1; }
}

function migSplitStmts(string $sql): array {
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

function migRunFile(PDO $db, array $file, int $batch): array {
    $sql      = file_get_contents($file['path']);
    $checksum = hash('sha256', $sql);
    $stmts    = migSplitStmts($sql);
    $t0       = microtime(true);
    $db->beginTransaction();
    try {
        foreach ($stmts as $stmt) $db->exec($stmt);
        $db->prepare("INSERT INTO migration_log (filename,checksum,batch,duration_ms) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE checksum=VALUES(checksum),batch=VALUES(batch),executed_at=NOW(),duration_ms=VALUES(duration_ms)")
           ->execute([$file['name'],$checksum,$batch,(int)((microtime(true)-$t0)*1000)]);
        $db->commit();
        return ['ok'=>true,'file'=>$file['name'],'stmts'=>count($stmts),'ms'=>(int)((microtime(true)-$t0)*1000)];
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok'=>false,'file'=>$file['name'],'error'=>$e->getMessage()];
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ═══════════════════════════════════════════════════════════════════════════════

migEnsureLog($db);

// ── LIST ────────────────────────────────────────────────────────────────────
if ($action === 'admin_migrations_list') {
    $files = migCollectFiles();
    $ran   = migFetchRan($db);

    $out   = ['total'=>count($files),'pending'=>0,'done'=>0,'files'=>[],'batches'=>[]];
    foreach ($files as $f) {
        $isRan = isset($ran[$f['name']]);
        if ($isRan) {
            $out['done']++;
            $out['batches'][$ran[$f['name']]['batch']][] = $f['name'];
        } else {
            $out['pending']++;
        }
        $size = file_exists($f['path']) ? filesize($f['path']) : 0;
        $out['files'][] = [
            'name'        => $f['name'],
            'dir'         => $f['dir'],
            'status'      => $isRan ? 'done' : 'pending',
            'batch'       => $isRan ? (int)$ran[$f['name']]['batch'] : null,
            'executed_at' => $isRan ? $ran[$f['name']]['executed_at'] : null,
            'duration_ms' => $isRan ? $ran[$f['name']]['duration_ms'] : null,
            'size_bytes'  => $size,
            'checksum'    => $isRan ? $ran[$f['name']]['checksum'] : null,
        ];
    }
    $maxBatch = $db->query("SELECT MAX(batch) FROM migration_log")->fetchColumn();
    $out['max_batch'] = $maxBatch ? (int)$maxBatch : 0;
    json_response(true, 'ok', $out);
}

// ── RUN ─────────────────────────────────────────────────────────────────────
if ($action === 'admin_migrations_run') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $runFile  = $body['filename'] ?? null; // if set, run only this file
    $runAll   = !$runFile;

    $files = migCollectFiles();
    $ran   = migFetchRan($db);
    $batch = migNextBatch($db);

    $toRun = [];
    foreach ($files as $f) {
        if ($runFile && $f['name'] !== $runFile) continue;
        if (isset($ran[$f['name']])) continue; // skip already-ran
        $toRun[] = $f;
    }

    if (empty($toRun)) {
        json_response(true, 'Nothing to run — all migrations are up to date.', ['ran'=>0,'failed'=>0,'batch'=>$batch-1]);
    }

    $results = ['ran'=>0,'failed'=>0,'batch'=>$batch,'details'=>[]];
    foreach ($toRun as $f) {
        $r = migRunFile($db, $f, $batch);
        $results['details'][] = $r;
        if ($r['ok']) $results['ran']++;
        else          $results['failed']++;
    }

    $ok  = $results['failed'] === 0;
    $msg = $ok
        ? "{$results['ran']} migration(s) executed in batch #{$batch}."
        : "{$results['failed']} migration(s) failed. {$results['ran']} succeeded.";
    json_response($ok, $msg, $results);
}

// ── ROLLBACK ─────────────────────────────────────────────────────────────────
if ($action === 'admin_migrations_rollback') {
    $maxBatch = $db->query("SELECT MAX(batch) FROM migration_log")->fetchColumn();
    if (!$maxBatch) {
        json_response(false, 'No migrations to roll back.');
    }
    $toRemove = $db->query("SELECT filename FROM migration_log WHERE batch=$maxBatch ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
    $ph = implode(',', array_fill(0, count($toRemove), '?'));
    $db->prepare("DELETE FROM migration_log WHERE filename IN ($ph)")->execute($toRemove);
    json_response(true, "Batch #{$maxBatch} removed from log (" . count($toRemove) . " file(s)). Schema changes were NOT reversed.", [
        'batch'   => (int)$maxBatch,
        'removed' => $toRemove,
    ]);
}
