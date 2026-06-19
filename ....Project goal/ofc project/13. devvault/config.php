<?php
define('APP_NAME', 'DevVault Pro');
define('APP_VERSION', '3.0.0'); // Single source — reference this everywhere
define('PASSWORD_EXPIRY_DAYS', 90); // Force password change after 90 days
define('DB_PATH', __DIR__ . '/data/vault.db');
define('UPLOAD_DIR', __DIR__ . '/data/uploads');

// ── Load ENCRYPT_KEY from .env file ──────────────────────────────────────────
// .env file must be in same directory as config.php
// Format: ENCRYPT_KEY=your_secret_key_here
function load_env(): void {
    $env_file = __DIR__ . '/.env';
    if (!file_exists($env_file)) {
        die('FATAL ERROR: .env file not found. Create .env file in project root with ENCRYPT_KEY= defined. See README.');
    }
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if (!defined($key)) define($key, $val);
    }
    if (!defined('ENCRYPT_KEY') || ENCRYPT_KEY === '') {
        die('FATAL ERROR: ENCRYPT_KEY not found in .env file.');
    }
    // ENCRYPT_SALT is optional — falls back to legacy mode if missing
    if (!defined('ENCRYPT_SALT')) define('ENCRYPT_SALT', '');
}
load_env();

// ── Encryption helpers (PBKDF2-hardened) ─────────────────────────────────────
// Uses PBKDF2-SHA256 (100,000 iterations) to derive a proper 256-bit AES key.
// Legacy format (base64 of "$iv::$enc") is auto-detected and transparently
// decrypted using the old method, then re-encrypted on next save.
//
// New format: base64( "v2:" . $iv_hex . ":" . $enc_b64 )

function _derive_key(): string {
    $salt = ENCRYPT_SALT ?: 'DevVaultPro_default_salt_v1'; // fallback salt
    return hash_pbkdf2('sha256', ENCRYPT_KEY, $salt, 100000, 32, true);
}

function encrypt_val(string $plain): string {
    if ($plain === '') return '';
    $iv  = random_bytes(16);
    $key = _derive_key();
    $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode('v2:' . bin2hex($iv) . ':' . base64_encode($enc));
}

function decrypt_val(string $cipher): string {
    if ($cipher === '') return '';
    $raw = base64_decode($cipher);
    if ($raw === false) return '';

    // ── New v2 format ─────────────────────────────────────────────────────────
    if (str_starts_with($raw, 'v2:')) {
        $parts = explode(':', $raw, 3);
        if (count($parts) !== 3) return '';
        [, $iv_hex, $enc_b64] = $parts;
        $iv  = hex2bin($iv_hex);
        $enc = base64_decode($enc_b64);
        $key = _derive_key();
        return openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv) ?: '';
    }

    // ── Legacy format: base64( "$iv::$enc" ) ─────────────────────────────────
    if (str_contains($raw, '::')) {
        [$iv, $enc] = explode('::', $raw, 2);
        return openssl_decrypt($enc, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv) ?: '';
    }

    return '';
}

// ── Database ──────────────────────────────────────────────────────────────────
function get_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout = 5000');

    // ── Add new columns to existing DBs (safe: IF NOT EXISTS via try/catch) ──
    $new_cols = [
        "ALTER TABLE projects ADD COLUMN parent_sectoral_portal TEXT DEFAULT ''",
        "ALTER TABLE users ADD COLUMN password_changed_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE login_attempts ADD COLUMN admin_unlocked_at DATETIME DEFAULT NULL",
    ];
    foreach ($new_cols as $_sql) {
        try { $db->exec($_sql); } catch (Exception $_e) {}
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT 'member',
            is_active INTEGER DEFAULT 1,
            password_changed INTEGER DEFAULT 0,
            theme TEXT DEFAULT 'dark',
            accent_color TEXT DEFAULT '#00d4ff',
            bg_color TEXT DEFAULT '',
            font_size INTEGER DEFAULT 14,
            font_family TEXT DEFAULT 'Rajdhani',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS dynamic_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            option_group TEXT NOT NULL,
            option_value TEXT NOT NULL,
            sort_order INTEGER DEFAULT 99,
            UNIQUE(option_group, option_value)
        );

        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_admin_dept TEXT,
            department_name TEXT,
            nodal_officer_name TEXT,
            nodal_designation TEXT,
            nodal_contact TEXT,
            dept_email TEXT,
            technology TEXT,
            technology_other TEXT,
            website_app TEXT,
            project_name TEXT NOT NULL,
            description TEXT,
            current_status TEXT DEFAULT 'under_development',
            last_audit_date TEXT,
            live_date TEXT,
            total_visitor_counter TEXT,
            last_update_date TEXT,
            env_local_url TEXT, env_local_admin_url TEXT, env_local_id TEXT, env_local_password TEXT, env_local_remark TEXT,
            env_staging_url TEXT, env_staging_admin_url TEXT, env_staging_id TEXT, env_staging_password TEXT, env_staging_remark TEXT,
            env_production_url TEXT, env_production_admin_url TEXT, env_production_id TEXT, env_production_password TEXT, env_production_remark TEXT,
            env_audit_url TEXT, env_audit_admin_url TEXT, env_audit_id TEXT, env_audit_password TEXT, env_audit_remark TEXT,
            env_other_url TEXT, env_other_admin_url TEXT, env_other_id TEXT, env_other_password TEXT, env_other_remark TEXT,
            app_ip TEXT, app_lb_ip TEXT, app_os TEXT, app_os_other TEXT,
            app_core TEXT, app_ram TEXT, app_primary_storage TEXT, app_secondary_storage TEXT,
            app_hosting_type TEXT DEFAULT 'individual', app_infra_remark TEXT,
            db_ip TEXT, db_name TEXT, db_technology TEXT, db_technology_other TEXT,
            db_version TEXT, db_version_other TEXT,
            db_os TEXT, db_os_other TEXT, db_hosting_type TEXT DEFAULT 'individual', db_remark TEXT,
            general_remark TEXT,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS project_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            name TEXT,
            designation TEXT,
            contact TEXT,
            email TEXT,
            sort_order INTEGER DEFAULT 0,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS project_documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            doc_type TEXT,
            title TEXT,
            filename TEXT,
            stored_name TEXT,
            file_size INTEGER DEFAULT 0,
            uploaded_by INTEGER,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS checklist_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name TEXT NOT NULL UNIQUE,
            sort_order INTEGER DEFAULT 99
        );

        CREATE TABLE IF NOT EXISTS checklist_responses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            checked INTEGER DEFAULT 0,
            notes TEXT,
            UNIQUE(project_id, item_id),
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY(item_id) REFERENCES checklist_items(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT,
            project_id INTEGER,
            detail TEXT,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS project_visitor_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            entry_date DATE NOT NULL,
            visitor_count INTEGER NOT NULL,
            site_last_update_date DATE,
            entered_by INTEGER,
            entered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            remarks TEXT,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS technology_change_log (
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
        );

        CREATE TABLE IF NOT EXISTS service_requests (
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
        );

        CREATE TABLE IF NOT EXISTS audit_findings (
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
        );

        CREATE TABLE IF NOT EXISTS work_orders (
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
        );

        CREATE TABLE IF NOT EXISTS work_order_sites (
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
        );

        CREATE TABLE IF NOT EXISTS work_order_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            work_order_id INTEGER NOT NULL,
            project_id INTEGER,
            action TEXT,
            changed_by INTEGER,
            changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            detail TEXT
        );

        CREATE TABLE IF NOT EXISTS login_attempts (
            ip_address TEXT PRIMARY KEY,
            attempts   INTEGER DEFAULT 1,
            last_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // ── Performance indexes on high-frequency FK columns ─────────────────────
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_contacts_pid   ON project_contacts(project_id);
        CREATE INDEX IF NOT EXISTS idx_docs_pid       ON project_documents(project_id);
        CREATE INDEX IF NOT EXISTS idx_chk_pid        ON checklist_responses(project_id);
        CREATE INDEX IF NOT EXISTS idx_chk_item       ON checklist_responses(item_id);
        CREATE INDEX IF NOT EXISTS idx_log_pid        ON activity_log(project_id);
        CREATE INDEX IF NOT EXISTS idx_log_uid        ON activity_log(user_id);
        CREATE INDEX IF NOT EXISTS idx_log_at         ON activity_log(created_at);
        CREATE INDEX IF NOT EXISTS idx_wos_woid       ON work_order_sites(work_order_id);
        CREATE INDEX IF NOT EXISTS idx_wos_pid        ON work_order_sites(project_id);
        CREATE INDEX IF NOT EXISTS idx_findings_pid   ON audit_findings(project_id);
        CREATE INDEX IF NOT EXISTS idx_vlog_pid       ON project_visitor_log(project_id);
        CREATE INDEX IF NOT EXISTS idx_vlog_date      ON project_visitor_log(entry_date);
        CREATE INDEX IF NOT EXISTS idx_projects_status ON projects(current_status);
        CREATE INDEX IF NOT EXISTS idx_projects_upd    ON projects(updated_at);
    ");

    // ── Migrate existing login_attempts table if it's missing columns ─────────
    // This handles the case where the table was created by an older version
    // without the 'attempts' and 'last_attempt_at' columns.
    $existing_cols = array_column(
        $db->query("PRAGMA table_info(login_attempts)")->fetchAll(), 'name'
    );
    if (!empty($existing_cols) && !in_array('attempts', $existing_cols)) {
        // Table exists but is outdated — drop and recreate cleanly
        $db->exec("DROP TABLE login_attempts");
        $db->exec("CREATE TABLE login_attempts (
            ip_address TEXT PRIMARY KEY,
            attempts   INTEGER DEFAULT 1,
            last_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    // ── Seed default admin (only inserts if not already present)
    // password_changed column is added via migration_t01.php — do not reference it here
    $db->exec("INSERT OR IGNORE INTO users (username, password_hash, role)
        VALUES ('admin', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'admin')");

    // ── Seed default dynamic options
    $defaults = [
        'technology'    => ['Dot Net', 'WebMyWay', 'AEM', 'Other'],
        'app_os'        => ['Win 2012', 'Win 2014', 'Win 2016', 'Other'],
        'db_technology' => ['MySQL', 'MSSQL / SQL Server', 'PostgreSQL', 'Oracle', 'MongoDB', 'NoSQL - Other', 'SQLite', 'Other'],
        'db_version'    => ['MySQL 5.7', 'MySQL 8.0', 'MSSQL 2016', 'MSSQL 2019', 'PostgreSQL 14', 'Oracle 19c', 'MongoDB 6.0', 'Other'],
        'db_os'         => ['Win 2012', 'Win 2014', 'Win 2016', 'Ubuntu 20', 'Ubuntu 22', 'Other'],
    ];
    foreach ($defaults as $grp => $vals) {
        foreach ($vals as $i => $v) {
            $db->exec("INSERT OR IGNORE INTO dynamic_options (option_group, option_value, sort_order)
                VALUES ('$grp', " . $db->quote($v) . ", $i)");
        }
    }

    // ── Seed default checklist items
    $checklistDefaults = [
        'Necessary Logos (NIC / Govt Emblem)',
        'Minister Photo',
        'CM / PM Photo (if applicable)',
        'Accessibility Compliance (GIGW)',
        'SSL Certificate Installed',
        'Privacy Policy Page',
        'Sitemap Available',
        'Contact Us Page',
        'RTI Link',
        'Mobile Responsive',
        'Favicon Set',
        'Footer with Department Info',
    ];
    foreach ($checklistDefaults as $i => $item) {
        $db->exec("INSERT OR IGNORE INTO checklist_items (item_name, sort_order) VALUES (" . $db->quote($item) . ", $i)");
    }

    // ── Safe migrations for existing databases (run silently, ignore if column exists)
    $migrations = [
        "ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1",
        "ALTER TABLE users ADD COLUMN bg_color TEXT DEFAULT ''",
        "ALTER TABLE users ADD COLUMN password_changed INTEGER DEFAULT 0",
        "ALTER TABLE users ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE projects ADD COLUMN parent_admin_dept TEXT",
        "ALTER TABLE projects ADD COLUMN db_technology TEXT",
        "ALTER TABLE projects ADD COLUMN db_technology_other TEXT",
        "ALTER TABLE projects ADD COLUMN tech_subtype TEXT DEFAULT ''",
        "ALTER TABLE projects ADD COLUMN amc_amount REAL DEFAULT 0",
        "ALTER TABLE projects ADD COLUMN amc_type TEXT DEFAULT ''",
        "ALTER TABLE projects ADD COLUMN amc_start_date TEXT DEFAULT ''",
        "ALTER TABLE projects ADD COLUMN amc_end_date TEXT DEFAULT ''",
        "ALTER TABLE projects ADD COLUMN amc_remarks TEXT DEFAULT ''",
        "ALTER TABLE projects ADD COLUMN closed_date TEXT DEFAULT ''",
    ];
    foreach ($migrations as $sql) {
        try { $db->exec($sql); } catch (Exception $e) {}
    }

    // ── Ensure existing admin user has password_changed flag set
    // (If admin was created before this migration, they already changed password manually
    //  so mark them as changed to avoid forcing them again)
    // NOTE: Only sets to 1 for non-default admin users (those who registered themselves).
    // The seeded admin above is already inserted with password_changed=0 via INSERT OR IGNORE.
    // We do NOT auto-set existing admins to 1 — let admin decide via README instructions.

    return $db;
}

// ── Access control helpers ────────────────────────────────────────────────────
function can_edit(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'member']);
}

function require_edit(): void {
    if (!can_edit()) { header('Location: index.php?err=readonly'); exit; }
}

// ── Dynamic option helpers ────────────────────────────────────────────────────
function get_options_arr(string $group): array {
    $db = get_db();
    $st = $db->prepare("SELECT option_value FROM dynamic_options WHERE option_group=? ORDER BY sort_order, id");
    $st->execute([$group]);
    return array_column($st->fetchAll(), 'option_value');
}

function add_option(string $group, string $value): void {
    $db  = get_db();
    $max = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM dynamic_options WHERE option_group=?");
    $max->execute([$group]);
    $order = (int)$max->fetchColumn();
    $st = $db->prepare("INSERT OR IGNORE INTO dynamic_options (option_group, option_value, sort_order) VALUES (?,?,?)");
    $st->execute([$group, trim($value), $order]);
}

// ── Activity logger ───────────────────────────────────────────────────────────
function log_activity(string $action, ?int $project_id = null, string $detail = ''): void {
    if (!isset($_SESSION['user_id'])) return;
    $db = get_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $db->prepare("INSERT INTO activity_log (user_id,action,project_id,detail,ip_address) VALUES (?,?,?,?,?)")
       ->execute([$_SESSION['user_id'], $action, $project_id, $detail, $ip]);
}

// ── JSON backup ───────────────────────────────────────────────────────────────
function backup_json(): void {
    // T17 SECURITY FIX: Auto-backup disabled — vault_backup.json was web-accessible.
    // Use Export page (export.php) for manual admin-only JSON export.
    // This function is kept as a stub to avoid breaking any existing calls.
    return;

    // ── DISABLED CODE BELOW ───────────────────────────────────────────────────
    // $db       = get_db();
    // $projects = $db->query("SELECT * FROM projects")->fetchAll();
    // file_put_contents(
    //     __DIR__ . '/data/vault_backup.json',
    //     json_encode(['exported_at' => date('Y-m-d H:i:s'), 'projects' => $projects], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    // );
}

// ── File upload with MIME validation ─────────────────────────────────────────
function safe_upload(array $file, int $projectId, string $docType, string $title, int $userId): array {
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'err' => 'Upload error code: ' . $file['error']];

    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) return ['ok' => false, 'err' => 'File too large (max 10MB)'];

    // ── Extension check
    $allowed_ext = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','zip','txt','csv'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) return ['ok' => false, 'err' => 'File type not allowed: .' . $ext];

    // ── MIME type check (actual file content, not just extension)
    $allowed_mime = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
    ];

    if (function_exists('finfo_open')) {
        $finfo     = finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $permitted = $allowed_mime[$ext] ?? [];
        if (!empty($permitted) && !in_array($real_mime, $permitted)) {
            return ['ok' => false, 'err' => "File content does not match extension (detected: $real_mime)"];
        }
    }

    // ── Save file
    $stored = 'doc_' . $projectId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest   = UPLOAD_DIR . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return ['ok' => false, 'err' => 'Failed to save file on server'];

    $db = get_db();
    $db->prepare("INSERT INTO project_documents (project_id,doc_type,title,filename,stored_name,file_size,uploaded_by)
        VALUES (?,?,?,?,?,?,?)")
       ->execute([$projectId, $docType, $title ?: $file['name'], $file['name'], $stored, $file['size'], $userId]);

    // Log the upload event
    log_activity('upload_document', $projectId, $file['name'] . ' (' . round($file['size']/1024,1) . ' KB)');

    return ['ok' => true];
}
