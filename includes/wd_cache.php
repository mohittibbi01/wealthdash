<?php
/**
 * WealthDash — tp001: Redis/APCu Cache Layer
 * File: includes/wd_cache.php
 *
 * Auto-selects: Redis → APCu → File cache (fallback)
 * Usage:
 *   WDCache::get('key')
 *   WDCache::set('key', $value, $ttl)
 *   WDCache::delete('key')
 *   WDCache::flush('prefix:')
 *   WDCache::remember('key', $ttl, fn() => expensive_computation())
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

class WDCache {

    private static ?object $_driver = null;
    private static string  $_mode   = 'none';

    // ── Boot: pick best available driver ────────────────────────────
    public static function boot(): void {
        if (self::$_driver !== null) return;

        // 1. Redis
        if (class_exists('Redis') && defined('REDIS_HOST')) {
            try {
                $r = new Redis();
                $r->connect(REDIS_HOST, defined('REDIS_PORT') ? REDIS_PORT : 6379, 2.0);
                if (defined('REDIS_PASSWORD') && REDIS_PASSWORD) $r->auth(REDIS_PASSWORD);
                $r->ping();
                self::$_driver = $r;
                self::$_mode   = 'redis';
                return;
            } catch (\Throwable) {}
        }

        // 2. APCu
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            self::$_mode = 'apcu';
            return;
        }

        // 3. File cache fallback
        $dir = defined('CACHE_DIR') ? CACHE_DIR : (APP_ROOT . '/storage/cache');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (is_writable($dir)) {
            self::$_driver = (object)['dir' => $dir];
            self::$_mode   = 'file';
            return;
        }

        self::$_mode = 'none';
    }

    // ── Get ──────────────────────────────────────────────────────────
    public static function get(string $key): mixed {
        self::boot();
        $k = self::_key($key);
        return match(self::$_mode) {
            'redis' => self::_redisGet($k),
            'apcu'  => self::_apcuGet($k),
            'file'  => self::_fileGet($k),
            default => null,
        };
    }

    // ── Set ──────────────────────────────────────────────────────────
    public static function set(string $key, mixed $value, int $ttl = 300): bool {
        self::boot();
        $k = self::_key($key);
        return match(self::$_mode) {
            'redis' => (bool)self::$_driver->setEx($k, $ttl, serialize($value)),
            'apcu'  => apcu_store($k, $value, $ttl),
            'file'  => self::_fileSet($k, $value, $ttl),
            default => false,
        };
    }

    // ── Delete ───────────────────────────────────────────────────────
    public static function delete(string $key): bool {
        self::boot();
        $k = self::_key($key);
        return match(self::$_mode) {
            'redis' => (bool)self::$_driver->del($k),
            'apcu'  => apcu_delete($k),
            'file'  => self::_fileDel($k),
            default => false,
        };
    }

    // ── Flush by prefix ──────────────────────────────────────────────
    public static function flush(string $prefix = ''): int {
        self::boot();
        $p = self::_key($prefix);
        $count = 0;
        if (self::$_mode === 'redis') {
            $keys = self::$_driver->keys($p . '*');
            if ($keys) { self::$_driver->del($keys); $count = count($keys); }
        } elseif (self::$_mode === 'apcu') {
            $info = apcu_cache_info(false);
            foreach ($info['cache_list'] ?? [] as $entry) {
                if (str_starts_with($entry['info'], $p)) {
                    apcu_delete($entry['info']);
                    $count++;
                }
            }
        } elseif (self::$_mode === 'file') {
            $dir = self::$_driver->dir;
            foreach (glob($dir . '/' . md5($p) . '*') ?: [] as $f) {
                @unlink($f);
                $count++;
            }
        }
        return $count;
    }

    // ── Remember: get or compute+store ──────────────────────────────
    public static function remember(string $key, int $ttl, callable $fn): mixed {
        $cached = self::get($key);
        if ($cached !== null) return $cached;
        $value = $fn();
        if ($value !== null) self::set($key, $value, $ttl);
        return $value;
    }

    // ── Remember for a specific user/portfolio ───────────────────────
    public static function userRemember(int $userId, int $portfolioId, string $suffix, int $ttl, callable $fn): mixed {
        $key = "wd:u{$userId}:p{$portfolioId}:{$suffix}";
        return self::remember($key, $ttl, $fn);
    }

    // ── Invalidate all cache for a user ──────────────────────────────
    public static function invalidateUser(int $userId): int {
        return self::flush("wd:u{$userId}:");
    }

    // ── Driver info ──────────────────────────────────────────────────
    public static function mode(): string    { self::boot(); return self::$_mode; }
    public static function isActive(): bool  { self::boot(); return self::$_mode !== 'none'; }

    // ── Stats (for admin dashboard) ───────────────────────────────────
    public static function stats(): array {
        self::boot();
        return match(self::$_mode) {
            'redis' => [
                'mode'   => 'redis',
                'info'   => self::$_driver->info('memory'),
                'keys'   => self::$_driver->dbSize(),
            ],
            'apcu' => [
                'mode'   => 'apcu',
                'info'   => apcu_sma_info(),
                'keys'   => count(apcu_cache_info(false)['cache_list'] ?? []),
            ],
            'file' => [
                'mode'   => 'file',
                'dir'    => self::$_driver->dir,
                'files'  => count(glob(self::$_driver->dir . '/*.cache') ?: []),
            ],
            default => ['mode' => 'none'],
        };
    }

    // ── Private helpers ──────────────────────────────────────────────
    private static function _key(string $k): string {
        // Namespace with app name to avoid collisions on shared cache
        return 'wd:' . $k;
    }

    private static function _redisGet(string $k): mixed {
        $v = self::$_driver->get($k);
        return ($v === false) ? null : unserialize($v);
    }

    private static function _apcuGet(string $k): mixed {
        $v = apcu_fetch($k, $success);
        return $success ? $v : null;
    }

    private static function _fileGet(string $k): mixed {
        $f = self::$_driver->dir . '/' . md5($k) . '.cache';
        if (!file_exists($f)) return null;
        $data = @unserialize(file_get_contents($f));
        if (!$data || $data['expires'] < time()) { @unlink($f); return null; }
        return $data['value'];
    }

    private static function _fileSet(string $k, mixed $v, int $ttl): bool {
        $f    = self::$_driver->dir . '/' . md5($k) . '.cache';
        $data = serialize(['expires' => time() + $ttl, 'value' => $v]);
        return (bool)file_put_contents($f, $data, LOCK_EX);
    }

    private static function _fileDel(string $k): bool {
        $f = self::$_driver->dir . '/' . md5($k) . '.cache';
        return file_exists($f) ? (bool)@unlink($f) : true;
    }
}

// ── Procedural helpers (for quick use in page scripts) ───────────────
function wd_cache_get(string $key): mixed    { return WDCache::get($key); }
function wd_cache_set(string $key, mixed $v, int $ttl = 300): bool { return WDCache::set($key, $v, $ttl); }
function wd_cache_del(string $key): bool     { return WDCache::delete($key); }
function wd_cache_remember(string $key, int $ttl, callable $fn): mixed { return WDCache::remember($key, $ttl, $fn); }
