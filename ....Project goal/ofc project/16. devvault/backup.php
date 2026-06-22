<?php
/**
 * DevVault Pro — Auto Backup Script
 * Run via Windows Task Scheduler or cron: php backup.php
 * Keeps last 30 backups (31st created = 1st deleted, sequential rotation)
 */

define('DB_PATH',    __DIR__ . '/data/vault.db');
define('BACKUP_DIR', __DIR__ . '/data/backups');
define('MAX_FILES',  30);
define('LOG_FILE',   BACKUP_DIR . '/backup.log');

if (!file_exists(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);

function log_bk(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

if (!file_exists(DB_PATH)) { log_bk("ERROR: vault.db not found"); exit(1); }

// Create timestamped backup
$ts   = date('Ymd_His');
$dest = BACKUP_DIR . "/vault_{$ts}.db";

try {
    $sqlite = new SQLite3(DB_PATH);
    if (version_compare(SQLite3::version()['versionString'], '3.27.0', '>=')) {
        $sqlite->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
        $sqlite->close();
        log_bk("BACKUP OK (VACUUM INTO): " . basename($dest) . " (" . round(filesize($dest)/1024,1) . " KB)");
    } else {
        $sqlite->close();
        if (!copy(DB_PATH, $dest)) throw new Exception("copy() failed");
        log_bk("BACKUP OK (copy): " . basename($dest) . " (" . round(filesize($dest)/1024,1) . " KB)");
    }
} catch (Exception $e) {
    if (copy(DB_PATH, $dest)) log_bk("BACKUP OK (fallback): " . basename($dest));
    else { log_bk("ERROR: " . $e->getMessage()); exit(1); }
}

file_put_contents(BACKUP_DIR . '/.last_backup', date('Y-m-d H:i:s'));

// Count-based rotation: keep only MAX_FILES, delete oldest first
$backups = glob(BACKUP_DIR . '/vault_*.db');
if ($backups) {
    sort($backups); // oldest first (filename has timestamp)
    $deleted = 0;
    while (count($backups) > MAX_FILES) {
        $oldest = array_shift($backups);
        unlink($oldest);
        $deleted++;
        log_bk("ROTATED (oldest): " . basename($oldest));
    }
    log_bk("DONE — " . count($backups) . " backup(s) kept, {$deleted} rotated.");
}
exit(0);
