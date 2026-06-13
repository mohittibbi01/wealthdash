<?php
define('APP_NAME', 'DevVault Pro');
define('APP_VERSION', '2.5.0');
define('DB_PATH', __DIR__ . '/data/vault.db');
define('UPLOAD_DIR', __DIR__ . '/data/uploads');
define('ENCRYPT_KEY', 'DevVaultPro_S3cur3_K3y_2025_Ch4ng3Me!');

function encrypt_val(string $plain): string {
    if (!$plain) return '';
    $iv = random_bytes(16);
    $enc = openssl_encrypt($plain, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
    return base64_encode($iv . '::' . $enc);
}

function decrypt_val(string $cipher): string {
    if (!$cipher) return '';
    $dec = base64_decode($cipher);
    if (!str_contains($dec, '::')) return '';
    [$iv, $enc] = explode('::', $dec, 2);
    return openssl_decrypt($enc, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv) ?: '';
}

function get_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT 'member',
            is_active INTEGER DEFAULT 1,
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
    ");

    // Seed default admin
    $db->exec("INSERT OR IGNORE INTO users (username, password_hash, role)
        VALUES ('admin', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'admin')");

    // Seed default dynamic options
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

    // Seed default checklist items
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

    // ── Migrations for existing DBs ──
    $migrations = [
        "ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1",
        "ALTER TABLE users ADD COLUMN bg_color TEXT DEFAULT ''",
        "ALTER TABLE projects ADD COLUMN parent_admin_dept TEXT",
        "ALTER TABLE projects ADD COLUMN db_technology TEXT",
        "ALTER TABLE projects ADD COLUMN db_technology_other TEXT",
    ];
    foreach ($migrations as $sql) {
        try { $db->exec($sql); } catch (Exception $e) {}
    }

    return $db;
}

function can_edit(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'member']);
}

function require_edit(): void {
    if (!can_edit()) { header('Location: index.php?err=readonly'); exit; }
}

function get_options_arr(string $group): array {
    $db = get_db();
    $st = $db->prepare("SELECT option_value FROM dynamic_options WHERE option_group=? ORDER BY sort_order, id");
    $st->execute([$group]);
    return array_column($st->fetchAll(), 'option_value');
}

function add_option(string $group, string $value): void {
    $db = get_db();
    $max = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM dynamic_options WHERE option_group=?");
    $max->execute([$group]);
    $order = (int)$max->fetchColumn();
    $st = $db->prepare("INSERT OR IGNORE INTO dynamic_options (option_group, option_value, sort_order) VALUES (?,?,?)");
    $st->execute([$group, trim($value), $order]);
}

function log_activity(string $action, ?int $project_id = null, string $detail = ''): void {
    if (!isset($_SESSION['user_id'])) return;
    $db = get_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $db->prepare("INSERT INTO activity_log (user_id,action,project_id,detail,ip_address) VALUES (?,?,?,?,?)")
       ->execute([$_SESSION['user_id'], $action, $project_id, $detail, $ip]);
}

function backup_json(): void {
    $db = get_db();
    $projects = $db->query("SELECT * FROM projects")->fetchAll();
    file_put_contents(
        __DIR__ . '/data/vault_backup.json',
        json_encode(['exported_at' => date('Y-m-d H:i:s'), 'projects' => $projects], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

// ── File upload helper ──
function safe_upload(array $file, int $projectId, string $docType, string $title, int $userId): array {
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok'=>false,'err'=>'Upload error: '.$file['error']];

    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) return ['ok'=>false,'err'=>'File too large (max 10MB)'];

    $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','zip','txt','csv'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return ['ok'=>false,'err'=>'File type not allowed: .'.$ext];

    $stored = 'doc_' . $projectId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = UPLOAD_DIR . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return ['ok'=>false,'err'=>'Failed to save file'];

    $db = get_db();
    $db->prepare("INSERT INTO project_documents (project_id,doc_type,title,filename,stored_name,file_size,uploaded_by)
        VALUES (?,?,?,?,?,?,?)")
       ->execute([$projectId, $docType, $title ?: $file['name'], $file['name'], $stored, $file['size'], $userId]);

    return ['ok'=>true];
}
