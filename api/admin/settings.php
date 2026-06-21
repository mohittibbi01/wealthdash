<?php
/**
 * WealthDash — t52: Global Settings Control
 * File: api/admin/settings.php
 * Actions: admin_settings_get, admin_settings_save, admin_settings_reset
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
if (!$isAdmin) json_response(false, 'Admin access required.', [], 403);

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

// ── Default settings schema ───────────────────────────────────────────────
function _settings_schema(): array {
    return [
        'app' => [
            'label' => '🏠 Application',
            'settings' => [
                'app_name'            => ['label'=>'App Name',            'type'=>'text',   'default'=>'WealthDash'],
                'app_url'             => ['label'=>'App URL',             'type'=>'text',   'default'=>''],
                'maintenance_mode'    => ['label'=>'Maintenance Mode',    'type'=>'bool',   'default'=>'0'],
                'registration_open'   => ['label'=>'Allow Registration',  'type'=>'bool',   'default'=>'1'],
                'default_theme'       => ['label'=>'Default Theme',       'type'=>'select', 'default'=>'light', 'options'=>['light','dark']],
                'items_per_page'      => ['label'=>'Items Per Page',      'type'=>'number', 'default'=>'25'],
            ],
        ],
        'security' => [
            'label' => '🔒 Security',
            'settings' => [
                'session_timeout_min'  => ['label'=>'Session Timeout (min)',  'type'=>'number', 'default'=>'120'],
                'max_login_attempts'   => ['label'=>'Max Login Attempts',     'type'=>'number', 'default'=>'5'],
                'lockout_duration_min' => ['label'=>'Lockout Duration (min)', 'type'=>'number', 'default'=>'15'],
                'require_2fa_admin'    => ['label'=>'Require 2FA for Admins', 'type'=>'bool',   'default'=>'0'],
                'password_min_length'  => ['label'=>'Min Password Length',    'type'=>'number', 'default'=>'8'],
            ],
        ],
        'api' => [
            'label' => '🔌 API & Cron',
            'settings' => [
                'mf_nav_update_time'   => ['label'=>'MF NAV Update Time (HH:MM)', 'type'=>'text',   'default'=>'22:00'],
                'api_rate_limit_rpm'   => ['label'=>'API Rate Limit (req/min)',   'type'=>'number', 'default'=>'60'],
                'cron_enabled'         => ['label'=>'Cron Jobs Enabled',           'type'=>'bool',   'default'=>'1'],
                'cache_ttl_seconds'    => ['label'=>'Cache TTL (seconds)',          'type'=>'number', 'default'=>'300'],
                'enable_ai_features'   => ['label'=>'Enable AI Features',           'type'=>'bool',   'default'=>'1'],
            ],
        ],
        'notifications' => [
            'label' => '🔔 Notifications',
            'settings' => [
                'email_enabled'        => ['label'=>'Email Notifications',    'type'=>'bool', 'default'=>'0'],
                'smtp_host'            => ['label'=>'SMTP Host',              'type'=>'text', 'default'=>''],
                'smtp_port'            => ['label'=>'SMTP Port',              'type'=>'number','default'=>'587'],
                'smtp_user'            => ['label'=>'SMTP Username',          'type'=>'text', 'default'=>''],
                'smtp_from_name'       => ['label'=>'From Name',              'type'=>'text', 'default'=>'WealthDash'],
                'sip_reminder_enabled' => ['label'=>'SIP Due Reminders',      'type'=>'bool', 'default'=>'1'],
            ],
        ],
    ];
}

switch ($action) {

    case 'admin_settings_get': {
        $schema  = _settings_schema();
        $dbRows  = DB::fetchAll("SELECT setting_key, setting_val FROM app_settings");
        $dbMap   = array_column($dbRows, 'setting_val', 'setting_key');

        // Merge DB values into schema
        foreach ($schema as &$group) {
            foreach ($group['settings'] as $key => &$def) {
                $def['value'] = $dbMap[$key] ?? $def['default'];
            }
        }
        json_response(true, 'ok', ['schema' => $schema, 'raw' => $dbMap]);
        break;
    }

    case 'admin_settings_save': {
        csrf_verify();
        $updates = $_POST['settings'] ?? null;
        if (!$updates || !is_array($updates)) {
            // Try JSON body
            $updates = json_decode($_POST['settings'] ?? '{}', true);
        }
        if (empty($updates)) json_response(false, 'No settings provided.');

        $schema   = _settings_schema();
        $allKeys  = [];
        foreach ($schema as $group) {
            foreach ($group['settings'] as $key => $def) {
                $allKeys[$key] = $def;
            }
        }

        $saved = 0;
        foreach ($updates as $key => $val) {
            $key = clean($key);
            if (!isset($allKeys[$key])) continue; // only save known keys
            $val = is_array($val) ? json_encode($val) : clean($val);
            $existing = DB::fetchVal("SELECT id FROM app_settings WHERE setting_key=?", [$key]);
            if ($existing) {
                DB::execute("UPDATE app_settings SET setting_val=?, updated_at=NOW() WHERE setting_key=?", [$val, $key]);
            } else {
                DB::execute("INSERT INTO app_settings(setting_key,setting_val,created_at,updated_at) VALUES(?,?,NOW(),NOW())", [$key, $val]);
            }
            $saved++;
        }
        audit_log($userId, 'admin_settings_save', "Saved $saved settings");
        json_response(true, "$saved settings saved.");
        break;
    }

    case 'admin_settings_reset': {
        csrf_verify();
        $group = clean($_POST['group'] ?? '');
        $schema = _settings_schema();
        if ($group && !isset($schema[$group])) json_response(false, 'Unknown group.');

        $groups = $group ? [$group => $schema[$group]] : $schema;
        $reset = 0;
        foreach ($groups as $g) {
            foreach ($g['settings'] as $key => $def) {
                $existing = DB::fetchVal("SELECT id FROM app_settings WHERE setting_key=?", [$key]);
                if ($existing) {
                    DB::execute("UPDATE app_settings SET setting_val=? WHERE setting_key=?", [$def['default'], $key]);
                } else {
                    DB::execute("INSERT INTO app_settings(setting_key,setting_val,created_at,updated_at) VALUES(?,?,NOW(),NOW())", [$key, $def['default']]);
                }
                $reset++;
            }
        }
        audit_log($userId, 'admin_settings_reset', "Reset $reset settings" . ($group ? " in group $group" : ''));
        json_response(true, "$reset settings reset to defaults.");
        break;
    }

    default:
        json_response(false, 'Unknown action.', [], 400);
}
