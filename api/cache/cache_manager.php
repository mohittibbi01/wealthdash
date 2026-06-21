<?php
/**
 * WealthDash — tp001: Cache Management API
 * File: api/cache/cache_manager.php
 * Actions: cache_stats, cache_flush_user, cache_flush_all (admin only)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

require_once APP_ROOT . '/includes/wd_cache.php';

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {

    // ── Stats (admin) ────────────────────────────────────────────
    case 'cache_stats': {
        if (!$isAdmin) json_response(false, 'Admin required.', [], 403);
        json_response(true, 'ok', [
            'mode'  => WDCache::mode(),
            'active'=> WDCache::isActive(),
            'stats' => WDCache::stats(),
        ]);
        break;
    }

    // ── Flush current user's cache ───────────────────────────────
    case 'cache_flush_user': {
        $count = WDCache::invalidateUser($userId);
        json_response(true, "Cache cleared ($count entries).", ['count' => $count]);
        break;
    }

    // ── Flush all (admin) ─────────────────────────────────────────
    case 'cache_flush_all': {
        if (!$isAdmin) json_response(false, 'Admin required.', [], 403);
        csrf_verify();
        $count = WDCache::flush('wd:');
        audit_log($userId, 'cache_flush_all', "Flushed $count cache entries");
        json_response(true, "All cache cleared ($count entries).", ['count' => $count]);
        break;
    }

    default:
        json_response(false, 'Unknown action.', [], 400);
}
