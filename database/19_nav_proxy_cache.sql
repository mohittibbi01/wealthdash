-- ============================================================
-- WealthDash — Migration: NAV Proxy Cache Table (t163)
-- Task: t163 — Local cache for MFAPI responses
-- ============================================================
-- NOTE: nav_proxy.php (api/mutual_funds/nav_proxy.php) banana hai.
--       Ye migration sirf DB-level cache table create karta hai.
--       PHP file alag banana padega.
-- ============================================================

CREATE TABLE IF NOT EXISTS `nav_proxy_cache` (
  `fund_id`      INT UNSIGNED NOT NULL,
  `period`       ENUM('1M','3M','6M','1Y','3Y','5Y','ALL') NOT NULL,
  `data_json`    LONGTEXT     NOT NULL COMMENT 'JSON: [{date:"YYYY-MM-DD", nav:"X.XX"}, ...]',
  `data_points`  INT          NOT NULL DEFAULT 0,
  `cached_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `source`       ENUM('nav_history','mfapi') NOT NULL DEFAULT 'nav_history',
  PRIMARY KEY (`fund_id`, `period`),
  KEY `idx_npc_cached_at` (`cached_at`),
  CONSTRAINT `fk_npc_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Server-side cache for NAV chart data — serves screener drawer charts faster';

-- ── Cache invalidation: daily cron mein add karo ─────────────────────────
-- After update_nav_daily.php, clear today's cache entries:
--   DELETE FROM nav_proxy_cache WHERE cached_at < DATE(NOW());
-- Ya cached_at se TTL check karo PHP mein (24 hours)

-- ── api/mutual_funds/nav_proxy.php create karna hai ─────────────────────
/*
  PHP pseudo-code for nav_proxy.php:
  
  $fundId = (int)($_GET['fund_id'] ?? 0);
  $period = $_GET['period'] ?? '1Y';
  
  // 1. Check DB cache
  $cached = DB::fetchOne("SELECT data_json, cached_at FROM nav_proxy_cache
                          WHERE fund_id=? AND period=?", [$fundId, $period]);
  if ($cached && strtotime($cached['cached_at']) > time() - 86400) {
      echo $cached['data_json']; exit;
  }
  
  // 2. Build from nav_history (fast, local)
  $days = ['1M'=>30,'3M'=>90,'6M'=>180,'1Y'=>365,'3Y'=>1095,'5Y'=>1825,'ALL'=>9999][$period] ?? 365;
  $rows = DB::fetchAll("SELECT nav_date AS date, nav_value AS nav
                        FROM nav_history WHERE fund_id=? AND nav_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
                        ORDER BY nav_date ASC", [$fundId, $days]);
  
  // 3. If insufficient data, fallback to MFAPI
  if (count($rows) < 5) { ... fetch from mfapi.in ... }
  
  // 4. Cache + return
  $json = json_encode(['data' => $rows, 'source' => 'nav_history']);
  DB::exec("INSERT INTO nav_proxy_cache (fund_id,period,data_json,data_points,source)
            VALUES (?,?,?,?,'nav_history') ON DUPLICATE KEY UPDATE data_json=VALUES(data_json),
            data_points=VALUES(data_points), cached_at=NOW()",
           [$fundId, $period, $json, count($rows)]);
  header('Content-Type: application/json');
  echo $json;
*/

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'nav_proxy_cache table created ✅' AS status;
