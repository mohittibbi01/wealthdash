<?php
/**
 * DevVault Pro — Auto Backup Script (FE-07)
 * ──────────────────────────────────────────
 * Run via Windows Task Scheduler or cron:
 *   php E:\project\run\backup.php
 *
 * What it does:
 *   1. Copies vault.db → data/backups/vault_YYYYMMDD_HHMMSS.db
 *   2. Keeps last 7 daily backups (older ones auto-deleted)
 *   3. Writes last_backup timestamp to data/backups/.last_backup
 *   4. Logs to data/backups/backup.log
 *
 * Schedule: Daily at 2:00 AM
 * Windows Task Scheduler: Action → php.exe "E:\project\run\backup.php"
 */

define('DB_PATH',     __DIR__ . '/data/vault.db');
define('BACKUP_DIR',  __DIR__ . '/data/backups');
define('KEEP_DAYS',   7);
define('LOG_FILE',    BACKUP_DIR . '/backup.log');

// ── Setup ─────────────────────────────────────────────────────────────────────
if (!file_exists(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
}

function log_bk(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// ── Check source DB ───────────────────────────────────────────────────────────
if (!file_exists(DB_PATH)) {
    log_bk("ERROR: vault.db not found at " . DB_PATH);
    exit(1);
}

// ── Create timestamped backup ─────────────────────────────────────────────────
$ts      = date('Ymd_His');
$dest    = BACKUP_DIR . "/vault_{$ts}.db";

// Use SQLite VACUUM INTO for consistent backup (requires PHP SQLite3)
try {
    $sqlite = new SQLite3(DB_PATH);
    // VACUUM INTO creates a clean defragmented copy
    if (method_exists($sqlite, 'exec') && version_compare(SQLite3::version()['versionString'], '3.27.0', '>=')) {
        $sqlite->exec("VACUUM INTO " . $sqlite->escapeString($dest));
        $sqlite->close();
        log_bk("BACKUP OK (VACUUM INTO): " . basename($dest) . " (" . round(filesize($dest)/1024, 1) . " KB)");
    } else {
        // Fallback: file copy (safe when no active writes)
        $sqlite->close();
        if (!copy(DB_PATH, $dest)) throw new Exception("copy() failed");
        log_bk("BACKUP OK (file copy): " . basename($dest) . " (" . round(filesize($dest)/1024, 1) . " KB)");
    }
} catch (Exception $e) {
    // Final fallback: direct file copy
    if (copy(DB_PATH, $dest)) {
        log_bk("BACKUP OK (fallback copy): " . basename($dest));
    } else {
        log_bk("ERROR: Backup failed — " . $e->getMessage());
        exit(1);
    }
}

// ── Write last backup timestamp ────────────────────────────────────────────────
file_put_contents(BACKUP_DIR . '/.last_backup', date('Y-m-d H:i:s'));

// ── Rotate: delete backups older than KEEP_DAYS ───────────────────────────────
$cutoff   = time() - (KEEP_DAYS * 86400);
$backups  = glob(BACKUP_DIR . '/vault_*.db');
$deleted  = 0;

if ($backups) {
    foreach ($backups as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
            $deleted++;
            log_bk("ROTATED: " . basename($file));
        }
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
$remaining = count(glob(BACKUP_DIR . '/vault_*.db'));
log_bk("DONE — {$remaining} backup(s) kept, {$deleted} rotated out.");
exit(0);
