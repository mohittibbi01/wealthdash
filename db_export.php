<?php
/**
 * WealthDash — DB Schema + Data Export
 * Place at: wealthdash/db_export.php
 * Access: Admin only
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
if ($currentUser['role'] !== 'admin') die('Admin only');

$db = DB::conn();
$action = $_POST['action'] ?? $_GET['action'] ?? 'view';

// ── EXPORT SQL ───────────────────────────────────────────────
if ($action === 'export') {
    $type = $_GET['type'] ?? 'full'; // full | schema | data
    $filename = 'wealthdash_' . $type . '_' . date('Y-m-d_H-i-s') . '.sql';
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "-- WealthDash DB Export\n";
    echo "-- Type: $type\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Tables: " . count($tables) . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";
    
    foreach ($tables as $table) {
        // Schema
        if ($type !== 'data') {
            $create = $db->query("SHOW CREATE TABLE `$table`")->fetch();
            echo "-- Table: $table\n";
            echo "DROP TABLE IF EXISTS `$table`;\n";
            echo $create['Create Table'] . ";\n\n";
        }
        
        // Data
        if ($type !== 'schema') {
            $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                echo "INSERT INTO `$table` ($cols) VALUES\n";
                $vals = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function($v) use ($db) {
                        if ($v === null) return 'NULL';
                        return $db->quote($v);
                    }, array_values($row));
                    $vals[] = '(' . implode(', ', $escaped) . ')';
                }
                echo implode(",\n", $vals) . ";\n\n";
            }
        }
    }
    
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

// ── IMPORT SQL ───────────────────────────────────────────────
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = ['success' => false, 'message' => '', 'executed' => 0, 'errors' => []];
    
    $sqlContent = '';
    
    // From file upload
    if (!empty($_FILES['sql_file']['tmp_name'])) {
        $sqlContent = file_get_contents($_FILES['sql_file']['tmp_name']);
    }
    // From textarea
    elseif (!empty($_POST['sql_text'])) {
        $sqlContent = $_POST['sql_text'];
    }
    
    if (empty($sqlContent)) {
        $result['message'] = 'No SQL provided';
    } else {
        // Split into statements
        $statements = array_filter(
            array_map('trim', explode(';', $sqlContent)),
            fn($s) => !empty($s) && !preg_match('/^--/', $s)
        );
        
        $db->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach ($statements as $stmt) {
            if (empty(trim($stmt))) continue;
            try {
                $db->exec($stmt);
                $result['executed']++;
            } catch (PDOException $e) {
                $result['errors'][] = substr($stmt, 0, 60) . '... → ' . $e->getMessage();
            }
        }
        $db->exec("SET FOREIGN_KEY_CHECKS=1");
        
        $result['success'] = true;
        $result['message'] = "Executed {$result['executed']} statements. " . count($result['errors']) . " errors.";
    }
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// ── GET TABLE STATS ──────────────────────────────────────────
if ($action === 'stats') {
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $stats = [];
    foreach ($tables as $t) {
        $count = $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $size  = $db->query("SELECT ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024, 1) 
                             FROM information_schema.TABLES 
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'")->fetchColumn();
        $stats[] = ['table' => $t, 'rows' => $count, 'size_kb' => $size ?? 0];
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'tables' => $stats]);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>WealthDash DB Manager</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f1f5f9;min-height:100vh}
.container{max-width:900px;margin:0 auto;padding:24px}
h1{font-size:1.4rem;font-weight:700;color:#1e293b;margin-bottom:4px}
.sub{color:#64748b;font-size:13px;margin-bottom:24px}
.card{background:#fff;border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.card h2{font-size:14px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}.btn-primary:hover{background:#1d4ed8}
.btn-green{background:#16a34a;color:#fff}.btn-green:hover{background:#15803d}
.btn-red{background:#dc2626;color:#fff}.btn-red:hover{background:#b91c1c}
.btn-gray{background:#f1f5f9;color:#374151;border:1px solid #e2e8f0}.btn-gray:hover{background:#e2e8f0}
.btn-group{display:flex;gap:8px;flex-wrap:wrap}
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:#f8fafc;padding:8px 12px;text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0}
td{padding:7px 12px;border-bottom:1px solid #f1f5f9;color:#1e293b}
tr:hover td{background:#f8fafc}
.badge{padding:2px 8px;border-radius:9px;font-size:11px;font-weight:700}
.badge-blue{background:#dbeafe;color:#1d4ed8}
textarea{width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-family:monospace;font-size:12px;resize:vertical;min-height:150px}
.upload-area{border:2px dashed #cbd5e1;border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:all .2s}
.upload-area:hover{border-color:#2563eb;background:#eff6ff}
input[type=file]{display:none}
#importResult{margin-top:12px;padding:10px 14px;border-radius:8px;font-size:13px;display:none}
.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d}
.err{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
#tableStats td:nth-child(2){text-align:right}
#tableStats td:nth-child(3){text-align:right;color:#64748b}
</style>
</head>
<body>
<div class="container">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
    <a href="<?= APP_URL ?>/templates/pages/admin.php" class="btn btn-gray">← Admin</a>
    <div>
      <h1>🗄️ Database Manager</h1>
      <div class="sub">Export schema/data as SQL · Import SQL files · View table stats</div>
    </div>
  </div>

  <!-- Export -->
  <div class="card">
    <h2>📤 Export Database</h2>
    <div class="btn-group">
      <a href="?action=export&type=full" class="btn btn-primary">⬇ Full Export (Schema + Data)</a>
      <a href="?action=export&type=schema" class="btn btn-gray">⬇ Schema Only</a>
      <a href="?action=export&type=data" class="btn btn-gray">⬇ Data Only</a>
    </div>
    <div style="margin-top:10px;font-size:12px;color:#64748b">
      Full export includes all tables. File will auto-download as .sql
    </div>
  </div>

  <!-- Import -->
  <div class="card">
    <h2>📥 Import SQL</h2>
    <div class="upload-area" onclick="document.getElementById('sqlFile').click()" id="dropArea">
      <div style="font-size:24px;margin-bottom:8px">📂</div>
      <div style="font-weight:600;color:#374151">Click to select .sql file</div>
      <div style="font-size:12px;color:#94a3b8;margin-top:4px" id="fileName">or paste SQL below</div>
    </div>
    <input type="file" id="sqlFile" accept=".sql,.txt" onchange="handleFileSelect(this)">
    <textarea id="sqlText" placeholder="Or paste SQL statements here..." style="margin-top:10px"></textarea>
    <div style="margin-top:10px;display:flex;align-items:center;gap:10px">
      <button class="btn btn-green" onclick="importSQL()">▶ Execute SQL</button>
      <button class="btn btn-gray" onclick="document.getElementById('sqlText').value='';document.getElementById('fileName').textContent='or paste SQL below'">Clear</button>
      <span style="font-size:12px;color:#ef4444">⚠ This will modify your database</span>
    </div>
    <div id="importResult"></div>
  </div>

  <!-- Table Stats -->
  <div class="card">
    <h2>📊 Table Statistics <button class="btn btn-gray" style="padding:4px 10px;font-size:11px" onclick="loadStats()">Refresh</button></h2>
    <table id="tableStats">
      <thead><tr><th>Table</th><th>Rows</th><th>Size (KB)</th></tr></thead>
      <tbody id="statsBody"><tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:20px">Click Refresh to load</td></tr></tbody>
    </table>
  </div>

  <!-- Quick SQL -->
  <div class="card">
    <h2>⚡ Quick SQL Fix</h2>
    <div style="font-size:12px;color:#64748b;margin-bottom:10px">Common fixes for WealthDash:</div>
    <div class="btn-group">
      <button class="btn btn-gray" onclick="runQuick('ALTER TABLE sip_schedules MODIFY COLUMN frequency ENUM(\'daily\',\'weekly\',\'fortnightly\',\'monthly\',\'quarterly\',\'yearly\') NOT NULL DEFAULT \'monthly\'')">Fix frequency ENUM</button>
      <button class="btn btn-gray" onclick="runQuick('UPDATE sip_schedules SET schedule_type = CASE WHEN notes = \'SWP\' THEN \'SWP\' ELSE \'SIP\' END WHERE schedule_type IS NULL OR schedule_type = \'\'')">Backfill schedule_type</button>
    </div>
    <div id="quickResult" style="margin-top:10px;font-size:13px"></div>
  </div>
</div>

<script>
function handleFileSelect(input) {
  const file = input.files[0];
  if (!file) return;
  document.getElementById('fileName').textContent = file.name + ' (' + Math.round(file.size/1024) + ' KB)';
  const reader = new FileReader();
  reader.onload = e => document.getElementById('sqlText').value = e.target.result;
  reader.readAsText(file);
}

async function importSQL() {
  const sql = document.getElementById('sqlText').value.trim();
  if (!sql) { alert('No SQL to execute'); return; }
  if (!confirm('Execute this SQL on the database?')) return;
  
  const res = document.getElementById('importResult');
  res.style.display = 'block';
  res.className = '';
  res.textContent = 'Executing...';
  
  const fd = new FormData();
  fd.append('action', 'import');
  fd.append('sql_text', sql);
  
  try {
    const r = await fetch(window.location.href, { method: 'POST', body: fd });
    const d = await r.json();
    res.className = d.success ? 'ok' : 'err';
    res.innerHTML = '<strong>' + d.message + '</strong>';
    if (d.errors && d.errors.length) {
      res.innerHTML += '<ul style="margin-top:8px">' + d.errors.map(e => '<li>' + e + '</li>').join('') + '</ul>';
    }
  } catch(e) {
    res.className = 'err';
    res.textContent = 'Error: ' + e.message;
  }
}

async function runQuick(sql) {
  document.getElementById('sqlText').value = sql;
  await importSQL();
  document.getElementById('quickResult').innerHTML = '';
}

async function loadStats() {
  const r = await fetch('?action=stats');
  const d = await r.json();
  const tbody = document.getElementById('statsBody');
  tbody.innerHTML = d.tables.map(t =>
    '<tr><td>' + t.table + '</td><td>' + Number(t.rows).toLocaleString() + '</td><td>' + t.size_kb + '</td></tr>'
  ).join('');
}

// Auto-load stats
loadStats();
</script>
</body>
</html>
