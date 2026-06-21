-- ============================================================
-- WealthDash — Migration 023: Fund Ratings System
-- Task: t111 — WealthDash internal star rating (1–5 stars)
-- Formula: Returns(40%) + Consistency(25%) + Risk(20%) + Expense(15%)
-- ============================================================

-- ── fund_ratings table — server-side rating storage ─────────────────────
CREATE TABLE IF NOT EXISTS `fund_ratings` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id`          INT UNSIGNED NOT NULL,
  `stars`            TINYINT UNSIGNED NOT NULL COMMENT '1-5 star rating',
  `score_total`      DECIMAL(6,4)  DEFAULT NULL COMMENT 'Raw weighted score before rounding',
  `score_breakdown`  JSON          DEFAULT NULL COMMENT '{"returns":2.5,"expense":0.75,"drawdown":0.75,"direct":0.2}',
  `rated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fr_fund` (`fund_id`),
  KEY `idx_fr_stars` (`stars`),
  KEY `idx_fr_rated` (`rated_at`),
  CONSTRAINT `fk_fr_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='WealthDash internal fund star ratings — recalculated weekly by cron';

-- ── Denorm column on funds for fast screener sort ────────────────────────
ALTER TABLE `funds`
  ADD COLUMN IF NOT EXISTS `wd_stars` TINYINT UNSIGNED DEFAULT NULL COMMENT 'WD star rating 1-5 (denorm from fund_ratings)',
  ADD INDEX IF NOT EXISTS `idx_funds_wd_stars` (`wd_stars`);

-- ── Weekly cron pseudo-code (add to cron/calculate_returns.php) ──────────
/*
  // After per-fund returns calculation loop, update ratings:
  foreach ($funds as $fund) {
      $ret   = $fund['returns_3y'] ?? $fund['returns_1y'] ?? null;
      $exp   = $fund['expense_ratio'] ?? null;
      $dd    = $fund['drawdown_pct'] ?? null;
      $isDirect = stripos($fund['scheme_name'], 'direct') !== false;

      $retScore = 0;
      if ($ret !== null) {
          if ($ret >= 20)      $retScore = 3;
          elseif ($ret >= 15)  $retScore = 2.5;
          elseif ($ret >= 10)  $retScore = 2;
          elseif ($ret >= 5)   $retScore = 1;
      }
      $expScore = 0.5; // neutral if no data
      if ($exp !== null) {
          if ($exp < 0.5)      $expScore = 1;
          elseif ($exp < 1)    $expScore = 0.75;
          elseif ($exp < 1.5)  $expScore = 0.5;
      }
      $ddScore = 0;
      if ($dd !== null) {
          if ($dd <= 0)        $ddScore = 1;
          elseif ($dd < 10)    $ddScore = 0.75;
          elseif ($dd < 20)    $ddScore = 0.5;
      }
      $directBonus = $isDirect ? 0.2 : 0;
      $total = $retScore + $expScore + $ddScore + $directBonus;
      $stars = min(5, max(1, round($total)));
      $breakdown = json_encode(['returns'=>$retScore,'expense'=>$expScore,'drawdown'=>$ddScore,'direct'=>$directBonus]);

      $db->prepare("
          INSERT INTO fund_ratings (fund_id, stars, score_total, score_breakdown)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE stars=VALUES(stars), score_total=VALUES(score_total),
                                  score_breakdown=VALUES(score_breakdown), rated_at=NOW()
      ")->execute([$fund['id'], $stars, round($total,4), $breakdown]);

      $db->prepare("UPDATE funds SET wd_stars=? WHERE id=?")->execute([$stars, $fund['id']]);
  }
*/

-- ── Verify ────────────────────────────────────────────────────────────────
SELECT 'fund_ratings table created ✅' AS status;
SELECT COUNT(*) AS rated_funds FROM fund_ratings;
-- ============================================================
