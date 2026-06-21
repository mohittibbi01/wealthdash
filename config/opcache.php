<?php
/**
 * WealthDash — tp004: PHP OPcache Configuration
 * File: config/opcache.php
 *
 * USAGE:
 *   Include this at the start of index.php / bootstrap BEFORE any other requires:
 *   require_once APP_ROOT . '/config/opcache.php';
 *
 * This file does NOT enable OPcache (PHP ini handles that).
 * It validates OPcache is running and provides the admin OPcache status API helper.
 *
 * To ENABLE OPcache in php.ini (or .user.ini for shared hosting):
 *   See the recommended settings below in the INI comment block.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

// ── Runtime check ─────────────────────────────────────────────────
if (!defined('WD_OPCACHE_CHECKED')) {
    define('WD_OPCACHE_CHECKED', true);

    $opcacheEnabled = function_exists('opcache_get_status') && opcache_get_status(false) !== false;
    define('WD_OPCACHE_ENABLED', $opcacheEnabled);

    // Optional: log warning if OPcache is missing (non-fatal)
    if (!$opcacheEnabled && function_exists('error_log')) {
        error_log('[WealthDash] OPcache not enabled — consider enabling for better performance.');
    }
}

// ── Status helper (used by admin/system_health.php) ───────────────
function wd_opcache_status(): array {
    if (!function_exists('opcache_get_status')) {
        return ['enabled' => false, 'reason' => 'Extension not loaded'];
    }
    $status = @opcache_get_status(false);
    if (!$status) {
        return ['enabled' => false, 'reason' => 'OPcache disabled or restricted'];
    }
    $mem = $status['memory_usage'] ?? [];
    $stats = $status['opcache_statistics'] ?? [];
    return [
        'enabled'        => true,
        'used_mb'        => round(($mem['used_memory'] ?? 0) / 1048576, 2),
        'free_mb'        => round(($mem['free_memory'] ?? 0) / 1048576, 2),
        'wasted_mb'      => round(($mem['wasted_memory'] ?? 0) / 1048576, 2),
        'cached_scripts' => $stats['num_cached_scripts'] ?? 0,
        'hits'           => $stats['hits'] ?? 0,
        'misses'         => $stats['misses'] ?? 0,
        'hit_rate'       => $stats['opcache_hit_rate'] ?? 0,
        'blacklist_miss' => $stats['blacklist_misses'] ?? 0,
        'start_time'     => isset($stats['start_time']) ? date('Y-m-d H:i:s', $stats['start_time']) : null,
    ];
}

// ── Reset OPcache (admin only, safe to call on code deploy) ────────
function wd_opcache_reset(): bool {
    if (!function_exists('opcache_reset')) return false;
    return opcache_reset();
}

// ── Invalidate single file ────────────────────────────────────────
function wd_opcache_invalidate(string $filepath): bool {
    if (!function_exists('opcache_invalidate')) return false;
    return opcache_invalidate($filepath, true);
}
