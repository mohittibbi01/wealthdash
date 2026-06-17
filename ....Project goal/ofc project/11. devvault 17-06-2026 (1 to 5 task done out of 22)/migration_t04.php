<?php
/**
 * DevVault Pro — T-04 Migration Script
 * Adds tech_subtype and AMC fields to projects table.
 * Run ONCE, then DELETE this file.
 */

// Add columns directly before loading config (safe for existing DBs)
define('DB_T04_PATH', __DIR__ . '/data/vault.db');
$steps = [];

if (file_exists(DB_T04_PATH)) {
    $db = new PDO('sqlite:' . DB_T04_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

    $cols = [
        "ALTER TABLE projects ADD COLUMN tech_subtype TEXT DEFAULT ''",
        "ALTER TABLE projects ADD COLUMN amc_amount REAL DEFAULT 0",
        "ALTER TABLE projects ADD COLUMN amc_type TEXT DEFAULT ''",
        "ALTER TABLE projects ADD COLUMN amc_start_date TEXT DEFAULT ''",
        "ALTER TABLE projects ADD COLUMN amc_end_date TEXT DEFAULT ''",
        "ALTER TABLE projects ADD COLUMN amc_remarks TEXT DEFAULT ''",
    ];

    foreach ($cols as $sql) {
        $col = preg_match('/ADD COLUMN (\w+)/', $sql, $m) ? $m[1] : $sql;
        $result = $db->exec($sql);
        if ($result !== false) {
            $steps[] = "✅ Added column: $col";
        } else {
            $steps[] = "ℹ️  Column already exists (skipped): $col";
        }
    }
    $db = null;
} else {
    $steps[] = "⚠️ Database not found at: " . DB_T04_PATH;
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>T-04 Migration</title>
<style>body{font-family:monospace;background:#070b14;color:#e8edf5;padding:40px;line-height:2}
h1{color:#00d4ff;margin-bottom:24px}.step{font-size:14px}
.done{color:#00e676;margin-top:24px;font-size:16px;font-weight:bold}
.warn{color:#ffb300;margin-top:12px;font-size:13px}</style>
</head><body>
<h1>🔧 DevVault Pro — T-04 Migration</h1>
<?php foreach($steps as $s): ?><div class="step"><?=htmlspecialchars($s)?></div><?php endforeach; ?>
<div class="done">✅ T-04 Migration complete.</div>
<div class="warn">⚠️ DELETE this file after verifying.</div>
</body></html>
