<?php
/**
 * WealthDash — Global Settings Control API [t52]
 * File: api/admin/global_settings.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
require_auth(ROLE_ADMIN);

$actingUserId = (int) $_SESSION['user_id'];

// ── Helpers ──────────────────────────────────────────────────────────────────
function settings_audit(string $key, string $old, string $new): void {
    DB::run(
        'INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
         VALUES (?,?,?,?,?,?,?)',
        [
            (int)$_SESSION['user_id'], 'setting_changed', 'app_settings', 0,
            json_encode([$key => $old]),
            json_encode([$key => $new]),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
}

function cast_val(string $type, string $raw): string {
    return match($type) {
        'boolean' => in_array(strtolower($raw), ['true','1','yes','on']) ? 'true' : 'false',
        'integer' => (string)(int)$raw,
        default   => $raw,
    };
}

switch ($action) {

    // ── GET ALL SETTINGS ──────────────────────────────────────────────────────
    case 'gs_list': {
        $group = clean($_GET['group'] ?? '');
        $where = $group ? 'WHERE setting_group = ?' : 'WHERE 1=1';
        $p     = $group ? [$group] : [];

        $rows = DB::fetchAll(
            "SELECT setting_key, setting_group, setting_type, label, description,
                    setting_val, is_public, is_locked, updated_at
             FROM app_settings
             $where
             ORDER BY setting_group, id",
            $p
        );

        // Group them
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['setting_group']][] = $r;
        }

        // All group names
        $groups = DB::fetchAll(
            "SELECT DISTINCT setting_group FROM app_settings ORDER BY setting_group"
        );

        json_response(true, '', ['grouped' => $grouped, 'groups' => array_column($groups, 'setting_group')]);
    }

    // ── GET SINGLE ────────────────────────────────────────────────────────────
    case 'gs_get': {
        $key = clean($_GET['key'] ?? '');
        if (!$key) json_response(false, 'Key required.');
        $row = DB::fetchOne('SELECT * FROM app_settings WHERE setting_key = ?', [$key]);
        if (!$row) json_response(false, 'Setting not found.', [], 404);
        json_response(true, '', ['setting' => $row]);
    }

    // ── SAVE SINGLE ───────────────────────────────────────────────────────────
    case 'gs_save': {
        csrf_verify();
        $key = clean($_POST['key'] ?? '');
        $val = $_POST['value'] ?? '';
        if (!$key) json_response(false, 'Key required.');

        $row = DB::fetchOne('SELECT * FROM app_settings WHERE setting_key = ?', [$key]);
        if (!$row) json_response(false, 'Setting not found.');
        if ($row['is_locked']) json_response(false, 'This setting is locked and cannot be changed via UI.');

        $val = cast_val($row['setting_type'], $val);
        $old = $row['setting_val'] ?? '';

        DB::run(
            'UPDATE app_settings SET setting_val=?, updated_by=? WHERE setting_key=?',
            [$val, $actingUserId, $key]
        );
        settings_audit($key, $old, $val);
        DB::invalidateCache('app_settings');

        json_response(true, 'Setting saved.', ['key' => $key, 'value' => $val]);
    }

    // ── SAVE BULK (all settings in a group at once) ───────────────────────────
    case 'gs_save_bulk': {
        csrf_verify();
        $settings = $_POST['settings'] ?? [];
        if (!is_array($settings) || empty($settings)) json_response(false, 'No settings provided.');

        DB::beginTransaction();
        try {
            $saved = 0;
            foreach ($settings as $key => $val) {
                $key = clean((string)$key);
                $row = DB::fetchOne(
                    'SELECT setting_type, setting_val, is_locked FROM app_settings WHERE setting_key=?',
                    [$key]
                );
                if (!$row || $row['is_locked']) continue;
                $val = cast_val($row['setting_type'], (string)$val);
                DB::run(
                    'UPDATE app_settings SET setting_val=?, updated_by=? WHERE setting_key=?',
                    [$val, $actingUserId, $key]
                );
                settings_audit($key, $row['setting_val'] ?? '', $val);
                $saved++;
            }
            DB::commit();
        } catch (Exception $e) {
            DB::rollback(); throw $e;
        }

        DB::invalidateCache('app_settings');
        json_response(true, "{$saved} setting(s) saved.");
    }

    // ── RESET TO DEFAULT (single) ─────────────────────────────────────────────
    case 'gs_reset': {
        csrf_verify();
        $key = clean($_POST['key'] ?? '');
        if (!$key) json_response(false, 'Key required.');
        $defaults = [
            'allow_registration'   => 'false',
            'maintenance_mode'     => 'false',
            'nav_auto_fetch'       => 'true',
            'session_lifetime_hours' => '24',
            'login_max_attempts'   => '5',
            'items_per_page'       => '25',
            'cache_ttl_nav'        => '300',
        ];
        $default = $defaults[$key] ?? null;
        if ($default === null) json_response(false, 'No default defined for this key.');
        $old = DB::fetchVal('SELECT setting_val FROM app_settings WHERE setting_key=?', [$key]);
        DB::run('UPDATE app_settings SET setting_val=?, updated_by=? WHERE setting_key=?',
            [$default, $actingUserId, $key]);
        settings_audit($key, (string)$old, $default);
        DB::invalidateCache('app_settings');
        json_response(true, 'Reset to default.', ['value' => $default]);
    }

    // ── MAINTENANCE MODE TOGGLE ───────────────────────────────────────────────
    case 'gs_maintenance_toggle': {
        csrf_verify();
        $current = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='maintenance_mode'");
        $new     = ($current === 'true') ? 'false' : 'true';
        DB::run("UPDATE app_settings SET setting_val=?, updated_by=? WHERE setting_key='maintenance_mode'",
            [$new, $actingUserId]);
        settings_audit('maintenance_mode', (string)$current, $new);
        DB::invalidateCache('app_settings');
        json_response(true, 'Maintenance mode ' . ($new === 'true' ? 'ENABLED' : 'disabled') . '.', ['active' => $new === 'true']);
    }

    // ── AUDIT LOG FOR SETTINGS ────────────────────────────────────────────────
    case 'gs_audit': {
        $rows = DB::fetchAll(
            "SELECT al.*, u.name, u.email FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.entity_type = 'app_settings'
             ORDER BY al.created_at DESC LIMIT 100"
        );
        json_response(true, '', ['log' => $rows]);
    }

    // ── TEST EMAIL (SMTP) ──────────────────────────────────────────────────────
    case 'gs_test_email': {
        csrf_verify();
        $to = clean($_POST['to'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_response(false, 'Invalid email address.');
        // Basic mail() test — real SMTP integration is via PHPMailer in production
        $subject = 'WealthDash — Test Email';
        $body    = 'This is a test email from WealthDash admin panel. If you see this, email is working.';
        $sent    = @mail($to, $subject, $body, 'From: ' . env('APP_NAME','WealthDash') . ' <noreply@wealthdash.local>');
        json_response($sent, $sent ? "Test email sent to {$to}." : 'mail() failed — check SMTP config.');
    }

    default:
        json_response(false, "Unknown action: {$action}", [], 400);
}
