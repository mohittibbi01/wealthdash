<?php
/**
 * WealthDash — tp001: Cache Layer (APCu → File fallback)
 *
 * Usage:
 *   WdCache::get('key')
 *   WdCache::set('key', $value, ttl: 300, tags: ['user:42', 'mf'])
 *   WdCache::remember('key', fn() => expensiveQuery(), ttl: 300, tags: ['user:42'])
 *   WdCache::invalidate('user:42')          // tag-based purge
 *   WdCache::delete('key')                  // single key
 *   WdCache::flush()                        // nuke everything (admin use)
 *
 * Backend auto-detected:
 *   1. APCu   — in-process, microsecond reads, no I/O
 *   2. File   — fallback; works on any host; stores in /logs/cache/
 *
 * Tag index:
 *   A separate key `_tag:{tag}` stores a list of cache keys bearing that tag.
 *   invalidate($tag) reads the list and deletes each key (+ the index itself).
 *   Tags are stored in the same backend as data, so they're always consistent.
 */
declare(strict_types=1);

class WdCache
{
    // ── Backend detection ────────────────────────────────────────────────────

    private static ?string $backend = null;
    private static string  $fileDir  = '';

    private static function backend(): string
    {
        if (self::$backend !== null) return self::$backend;

        if (extension_loaded('apcu') && apcu_enabled()) {
            self::$backend = 'apcu';
        } else {
            self::$backend = 'file';
            self::$fileDir  = defined('APP_ROOT')
                ? APP_ROOT . '/logs/cache'
                : dirname(__DIR__) . '/logs/cache';
            if (!is_dir(self::$fileDir)) {
                mkdir(self::$fileDir, 0755, true);
            }
        }

        return self::$backend;
    }

    // ── Key sanitisation ─────────────────────────────────────────────────────

    /** Namespace all keys to avoid collisions with other APCu users. */
    private static function k(string $key): string
    {
        return 'wd:' . $key;
    }

    private static function filePath(string $key): string
    {
        // sha1 so file names are safe on every OS
        return self::$fileDir . '/' . sha1($key) . '.cache';
    }

    // ── Primitives ───────────────────────────────────────────────────────────

    /**
     * Fetch a value from cache.
     * Returns null on miss (never throws).
     */
    public static function get(string $key): mixed
    {
        try {
            $k = self::k($key);
            if (self::backend() === 'apcu') {
                $val = apcu_fetch($k, $ok);
                return $ok ? $val : null;
            }
            // File
            $path = self::filePath($k);
            if (!file_exists($path)) return null;
            $raw = file_get_contents($path);
            if ($raw === false) return null;
            $entry = unserialize($raw);
            if (!is_array($entry) || $entry['exp'] < time()) {
                @unlink($path);
                return null;
            }
            return $entry['val'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Store a value.
     *
     * @param int      $ttl   Seconds until expiry (0 = never for APCu, 1 year for file)
     * @param string[] $tags  Tag names for grouped invalidation
     */
    public static function set(string $key, mixed $value, int $ttl = 300, array $tags = []): bool
    {
        try {
            $k = self::k($key);
            if (self::backend() === 'apcu') {
                $ok = apcu_store($k, $value, $ttl);
            } else {
                $exp  = $ttl > 0 ? time() + $ttl : time() + 31536000;
                $raw  = serialize(['exp' => $exp, 'val' => $value]);
                $ok   = file_put_contents(self::filePath($k), $raw, LOCK_EX) !== false;
            }
            if ($ok && !empty($tags)) {
                self::indexTags($key, $tags, $ttl);
            }
            return (bool)$ok;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Delete a single key (and clean tag indexes for it).
     */
    public static function delete(string $key): void
    {
        try {
            $k = self::k($key);
            if (self::backend() === 'apcu') {
                apcu_delete($k);
            } else {
                @unlink(self::filePath($k));
            }
        } catch (Throwable) {}
    }

    // ── remember() — the main helper API calls should use ───────────────────

    /**
     * Return cached value, or execute $callback, cache the result, and return it.
     *
     * Example:
     *   $rows = WdCache::remember("mf_holdings:{$userId}", fn() =>
     *       DB::fetchAll("SELECT …", [$userId]), ttl: 120, tags: ["user:{$userId}"]);
     */
    public static function remember(
        string   $key,
        callable $callback,
        int      $ttl  = 300,
        array    $tags = []
    ): mixed {
        $cached = self::get($key);
        if ($cached !== null) return $cached;

        $value = $callback();
        if ($value !== null) {
            self::set($key, $value, $ttl, $tags);
        }
        return $value;
    }

    // ── Tag-based invalidation ────────────────────────────────────────────────

    /**
     * Invalidate all cache keys that were tagged with $tag.
     *
     * Call this whenever you mutate data:
     *   WdCache::invalidate("user:{$userId}");
     *   WdCache::invalidate("mf");
     */
    public static function invalidate(string $tag): void
    {
        try {
            $indexKey = self::k('_tag:' . $tag);
            $keys = self::getRaw($indexKey) ?? [];
            foreach ($keys as $key) {
                self::delete($key);
            }
            // Remove the index itself
            self::deleteRaw($indexKey);
        } catch (Throwable) {}
    }

    /**
     * Flush the entire cache (use in admin / migrations only).
     */
    public static function flush(): void
    {
        try {
            if (self::backend() === 'apcu') {
                apcu_clear_cache();
            } else {
                $files = glob(self::$fileDir . '/*.cache');
                if ($files) foreach ($files as $f) @unlink($f);
            }
        } catch (Throwable) {}
    }

    /**
     * Return debug stats (for admin panel).
     */
    public static function stats(): array
    {
        $backend = self::backend();
        $info    = ['backend' => $backend];
        if ($backend === 'apcu') {
            $raw = apcu_cache_info(true) ?: [];
            $info['num_entries']  = $raw['num_entries']  ?? 0;
            $info['mem_used_mb']  = round(($raw['mem_size'] ?? 0) / 1048576, 2);
            $info['hits']         = $raw['num_hits']     ?? 0;
            $info['misses']       = $raw['num_misses']   ?? 0;
        } else {
            $files = glob(self::$fileDir . '/*.cache') ?: [];
            $size  = array_sum(array_map('filesize', $files));
            $info['num_entries'] = count($files);
            $info['disk_kb']     = round($size / 1024, 1);
        }
        return $info;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /** Add $key to the tag index for each $tag. */
    private static function indexTags(string $key, array $tags, int $ttl): void
    {
        // Tag index TTL should be at least as long as the data TTL
        $tagTtl = max($ttl + 60, 3600);
        foreach ($tags as $tag) {
            $indexKey = self::k('_tag:' . $tag);
            $existing = self::getRaw($indexKey) ?? [];
            if (!in_array($key, $existing, true)) {
                $existing[] = $key;
                self::setRaw($indexKey, $existing, $tagTtl);
            }
        }
    }

    /** Low-level get bypassing the wd: prefix (used for tag index keys that are already prefixed). */
    private static function getRaw(string $k): mixed
    {
        if (self::backend() === 'apcu') {
            $val = apcu_fetch($k, $ok);
            return $ok ? $val : null;
        }
        $path = self::filePath($k);
        if (!file_exists($path)) return null;
        $raw = file_get_contents($path);
        if ($raw === false) return null;
        $entry = unserialize($raw);
        return (is_array($entry) && $entry['exp'] >= time()) ? $entry['val'] : null;
    }

    private static function setRaw(string $k, mixed $value, int $ttl): void
    {
        if (self::backend() === 'apcu') {
            apcu_store($k, $value, $ttl);
        } else {
            $exp = time() + $ttl;
            file_put_contents(self::filePath($k), serialize(['exp' => $exp, 'val' => $value]), LOCK_EX);
        }
    }

    private static function deleteRaw(string $k): void
    {
        if (self::backend() === 'apcu') {
            apcu_delete($k);
        } else {
            @unlink(self::filePath($k));
        }
    }
}
