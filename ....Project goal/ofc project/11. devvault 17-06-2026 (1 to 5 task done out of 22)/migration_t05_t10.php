<?php
/**
 * DevVault Pro — Migration T-05 to T-10
 * Run ONCE after T-01 and T-04 migrations are already done.
 * Adds all new tables for v3.0 features.
 * DELETE this file after running.
 *
 * Usage: Open in browser OR run via CLI: php migration_t05_t10.php
 */

define('DB_PATH_MIGRATE', __DIR__ . '/data/vault.db');
$steps = [];

if (!file_exists(DB_PATH_MIGRATE)) {
    die('<h2 style="color:red">DB not found at: ' . DB_PATH_MIGRATE . '</h2>');
}

$db = new PDO('sqlite:' . DB_PATH_MIGRATE);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$db->exec('PRAGMA journal_mode=WAL');

// ─────────────────────────────────────────────────────────────────────────────
// T-05: project_visitor_log table
// ─────────────────────────────────────────────────────────────────────────────
$r = $db->exec("CREATE TABLE IF NOT EXISTS project_visitor_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    entry_date DATE NOT NULL,
    visitor_count INTEGER NOT NULL,
    site_last_update_date DATE,
    entered_by INTEGER,
    entered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    remarks TEXT,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
)");
$steps[] = $r !== false
    ? "✅ T-05: Created 'project_visitor_log' table."
    : "ℹ️  T-05: 'project_visitor_log' already exists — skipped.";

// T-05: Migrate existing visitor data from projects table
$projects = $db->query("SELECT id, total_visitor_counter, last_update_date FROM projects WHERE total_visitor_counter IS NOT NULL AND total_visitor_counter != ''")->fetchAll(PDO::FETCH_ASSOC);
$migrated = 0;
foreach ($projects as $p) {
    $count_raw = preg_replace('/[^0-9]/', '', $p['total_visitor_counter']);
    if (!$count_raw) continue;
    $count = (int)$count_raw;
    $upd_date = ($p['last_update_date'] && $p['last_update_date'] !== '') ? $p['last_update_date'] : null;
    // Insert only if no log entry exists yet for this project
    $existing = $db->prepare("SELECT COUNT(*) FROM project_visitor_log WHERE project_id=?");
    $existing->execute([$p['id']]);
    if ((int)$existing->fetchColumn() === 0) {
        $db->prepare("INSERT INTO project_visitor_log (project_id, entry_date, visitor_count, site_last_update_date, remarks) VALUES (?,date('now'),?,?,'Migrated from v2.5')")
           ->execute([$p['id'], $count, $upd_date]);
        $migrated++;
    }
}
$steps[] = "✅ T-05: Migrated $migrated existing project(s) visitor data to log table.";

// ─────────────────────────────────────────────────────────────────────────────
// T-06: technology_change_log table
// ─────────────────────────────────────────────────────────────────────────────
$r = $db->exec("CREATE TABLE IF NOT EXISTS technology_change_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    from_technology TEXT,
    from_subtype TEXT,
    to_technology TEXT NOT NULL,
    to_subtype TEXT,
    change_date DATE NOT NULL,
    reason TEXT,
    changed_by INTEGER,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
)");
$steps[] = $r !== false
    ? "✅ T-06: Created 'technology_change_log' table."
    : "ℹ️  T-06: 'technology_change_log' already exists — skipped.";

// ─────────────────────────────────────────────────────────────────────────────
// T-07: service_requests table
// ─────────────────────────────────────────────────────────────────────────────
$r = $db->exec("CREATE TABLE IF NOT EXISTS service_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    sr_number TEXT NOT NULL,
    sr_date DATE NOT NULL,
    purpose TEXT NOT NULL,
    raised_by TEXT,
    current_status TEXT DEFAULT 'Open',
    resolution_date DATE,
    remarks TEXT,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
)");
$steps[] = $r !== false
    ? "✅ T-07: Created 'service_requests' table."
    : "ℹ️  T-07: 'service_requests' already exists — skipped.";

// ─────────────────────────────────────────────────────────────────────────────
// T-08: audit_findings table
// ─────────────────────────────────────────────────────────────────────────────
$r = $db->exec("CREATE TABLE IF NOT EXISTS audit_findings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    finding_description TEXT NOT NULL,
    severity TEXT DEFAULT 'Minor',
    found_by TEXT,
    found_date DATE NOT NULL,
    assigned_to TEXT,
    target_date DATE,
    current_status TEXT DEFAULT 'Open',
    closure_date DATE,
    closure_remarks TEXT,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
)");
$steps[] = $r !== false
    ? "✅ T-08: Created 'audit_findings' table."
    : "ℹ️  T-08: 'audit_findings' already exists — skipped.";

// ─────────────────────────────────────────────────────────────────────────────
// T-09: work_orders, work_order_sites, work_order_history tables
// ─────────────────────────────────────────────────────────────────────────────
$r = $db->exec("CREATE TABLE IF NOT EXISTS work_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT,
    instruction_source TEXT,
    applicable_tech TEXT,
    scope TEXT DEFAULT 'all',
    priority TEXT DEFAULT 'Normal',
    deadline DATE,
    assigned_to TEXT,
    created_by INTEGER,
    status TEXT DEFAULT 'Active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$steps[] = $r !== false
    ? "✅ T-09: Created 'work_orders' table."
    : "ℹ️  T-09: 'work_orders' already exists — skipped.";

$r = $db->exec("CREATE TABLE IF NOT EXISTS work_order_sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    work_order_id INTEGER NOT NULL,
    project_id INTEGER NOT NULL,
    site_status TEXT DEFAULT 'Pending',
    done_by INTEGER,
    done_at DATETIME,
    remarks TEXT,
    UNIQUE(work_order_id, project_id),
    FOREIGN KEY(work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
)");
$steps[] = $r !== false
    ? "✅ T-09: Created 'work_order_sites' table."
    : "ℹ️  T-09: 'work_order_sites' already exists — skipped.";

$r = $db->exec("CREATE TABLE IF NOT EXISTS work_order_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    work_order_id INTEGER NOT NULL,
    project_id INTEGER,
    action TEXT,
    changed_by INTEGER,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    detail TEXT
)");
$steps[] = $r !== false
    ? "✅ T-09: Created 'work_order_history' table."
    : "ℹ️  T-09: 'work_order_history' already exists — skipped.";

// ─────────────────────────────────────────────────────────────────────────────
// T-10: closed_date column on projects
// ─────────────────────────────────────────────────────────────────────────────
$r = $db->exec("ALTER TABLE projects ADD COLUMN closed_date TEXT DEFAULT ''");
$steps[] = $r !== false
    ? "✅ T-10: Added 'closed_date' column to projects table."
    : "ℹ️  T-10: 'closed_date' column already exists — skipped.";

// ─────────────────────────────────────────────────────────────────────────────
// T-04 safety check: ensure AMC + tech_subtype columns exist
// (in case migration_t04.php was not run separately)
// ─────────────────────────────────────────────────────────────────────────────
$t04_cols = [
    "ALTER TABLE projects ADD COLUMN tech_subtype TEXT DEFAULT ''",
    "ALTER TABLE projects ADD COLUMN amc_amount REAL DEFAULT 0",
    "ALTER TABLE projects ADD COLUMN amc_type TEXT DEFAULT ''",
    "ALTER TABLE projects ADD COLUMN amc_start_date TEXT DEFAULT ''",
    "ALTER TABLE projects ADD COLUMN amc_end_date TEXT DEFAULT ''",
    "ALTER TABLE projects ADD COLUMN amc_remarks TEXT DEFAULT ''",
];
$t04_added = 0;
foreach ($t04_cols as $sql) {
    if ($db->exec($sql) !== false) $t04_added++;
}
if ($t04_added > 0) {
    $steps[] = "✅ T-04 safety: Added $t04_added missing AMC/subtype column(s).";
} else {
    $steps[] = "ℹ️  T-04: All AMC/subtype columns already present.";
}

// ─────────────────────────────────────────────────────────────────────────────
// Verify final schema
// ─────────────────────────────────────────────────────────────────────────────
$tables_expected = [
    'project_visitor_log','technology_change_log','service_requests',
    'audit_findings','work_orders','work_order_sites','work_order_history'
];
$existing_tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC), 'name');
$missing = array_diff($tables_expected, $existing_tables);
if ($missing) {
    $steps[] = "⚠️  MISSING TABLES: " . implode(', ', $missing);
} else {
    $steps[] = "✅ All v3.0 tables verified present.";
}

$db = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>T-05 to T-10 Migration — DevVault Pro</title>
<style>
body{font-family:monospace;background:#070b14;color:#e8edf5;padding:40px;line-height:2;}
h1{color:#00d4ff;margin-bottom:24px;}
.step{margin:4px 0;font-size:14px;}
.done{color:#00e676;margin-top:28px;font-size:16px;font-weight:bold;}
.warn{color:#ffb300;margin-top:12px;font-size:13px;}
.box{background:#0d1526;border:1px solid #1e2d45;border-radius:8px;padding:24px;max-width:800px;}
</style>
</head>
<body>
<div class="box">
<h1>🔧 DevVault Pro — Migration T-05 to T-10</h1>
<?php foreach ($steps as $s): ?>
  <div class="step"><?= htmlspecialchars($s) ?></div>
<?php endforeach; ?>
<div class="done">✅ Migration complete. All v3.0 tables created.</div>
<div class="warn">⚠️ DELETE this file (migration_t05_t10.php) after verifying everything works.</div>
<div class="warn" style="margin-top:6px;">Next steps: Open <a href="index.php" style="color:#00d4ff;">index.php</a> to verify the app loads correctly.</div>
</div>
</body>
</html>
