<?php
/**
 * WealthDash — tp001: Cache Integration Guide
 * File: includes/wd_cache_integration.php
 *
 * Drop-in wrappers for existing heavy API endpoints.
 * Include wd_cache.php FIRST, then this file.
 *
 * USAGE IN EXISTING API FILES:
 *
 *   require_once APP_ROOT . '/includes/wd_cache.php';
 *   require_once APP_ROOT . '/includes/wd_cache_integration.php';
 *
 *   // Instead of calling DB directly:
 *   $data = WDCacheIntegration::dashboardUnified($userId, $portfolioId);
 *   $data = WDCacheIntegration::mfHoldings($userId, $portfolioId);
 *   $data = WDCacheIntegration::portfolioXIRR($userId, $portfolioId);
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

class WDCacheIntegration {

    // ── TTL constants ────────────────────────────────────────────────
    const TTL_DASHBOARD  = 300;   // 5 min  — dashboard widgets
    const TTL_HOLDINGS   = 600;   // 10 min — holdings list
    const TTL_XIRR       = 1800;  // 30 min — XIRR computation (expensive)
    const TTL_NAV        = 3600;  // 1 hr   — NAV data
    const TTL_MARKET     = 900;   // 15 min — market pulse
    const TTL_REPORT     = 3600;  // 1 hr   — FY reports
    const TTL_STATIC     = 86400; // 24 hr  — static data (fund names, etc.)

    // ── Dashboard unified data ───────────────────────────────────────
    public static function dashboardUnified(int $userId, int $portfolioId, callable $fn): mixed {
        return WDCache::userRemember($userId, $portfolioId, 'dashboard:unified', self::TTL_DASHBOARD, $fn);
    }

    // ── MF Holdings ───────────────────────────────────────────────────
    public static function mfHoldings(int $userId, int $portfolioId, callable $fn): mixed {
        return WDCache::userRemember($userId, $portfolioId, 'mf:holdings', self::TTL_HOLDINGS, $fn);
    }

    // ── Portfolio XIRR ────────────────────────────────────────────────
    public static function portfolioXIRR(int $userId, int $portfolioId, callable $fn): mixed {
        return WDCache::userRemember($userId, $portfolioId, 'portfolio:xirr', self::TTL_XIRR, $fn);
    }

    // ── Market Pulse ─────────────────────────────────────────────────
    public static function marketPulse(int $userId, callable $fn): mixed {
        // Market pulse is shared across users (same data)
        return WDCache::remember('shared:market_pulse', self::TTL_MARKET, $fn);
    }

    // ── NAV for a fund ───────────────────────────────────────────────
    public static function fundNav(int $mfId, callable $fn): mixed {
        return WDCache::remember("shared:nav:{$mfId}", self::TTL_NAV, $fn);
    }

    // ── FY Report ────────────────────────────────────────────────────
    public static function fyReport(int $userId, int $portfolioId, string $fy, callable $fn): mixed {
        return WDCache::userRemember($userId, $portfolioId, "report:fy:{$fy}", self::TTL_REPORT, $fn);
    }

    // ── Fund search results ───────────────────────────────────────────
    public static function fundSearch(string $query, callable $fn): mixed {
        $q = strtolower(trim($query));
        return WDCache::remember("shared:fundsearch:" . md5($q), self::TTL_STATIC, $fn);
    }

    // ── Invalidation helpers ──────────────────────────────────────────
    // Call these when data changes (after a new transaction, SIP update, etc.)

    /** Invalidate holdings + dashboard for user */
    public static function invalidateOnTransaction(int $userId, int $portfolioId): void {
        WDCache::delete("wd:u{$userId}:p{$portfolioId}:mf:holdings");
        WDCache::delete("wd:u{$userId}:p{$portfolioId}:dashboard:unified");
        WDCache::delete("wd:u{$userId}:p{$portfolioId}:portfolio:xirr");
    }

    /** Invalidate all FY reports for user */
    public static function invalidateReports(int $userId, int $portfolioId): void {
        WDCache::flush("wd:u{$userId}:p{$portfolioId}:report:");
    }

    /** Invalidate all user cache */
    public static function invalidateAll(int $userId): void {
        WDCache::invalidateUser($userId);
    }
}

// ── Middleware: auto-add cache headers to JSON responses ────────────
function wd_cache_headers(int $ttl = 0): void {
    if ($ttl > 0) {
        header("Cache-Control: private, max-age={$ttl}");
        header("X-WD-Cache: hit");
    } else {
        header('Cache-Control: no-store, no-cache');
        header('X-WD-Cache: miss');
    }
}
