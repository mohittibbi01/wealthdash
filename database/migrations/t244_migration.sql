-- WealthDash — t244: AI Portfolio Narrative Migration
-- No new tables required.
-- Depends on existing tables (must exist before running):
--   ✅ mf_holdings      (existing)
--   ✅ mf_nav_latest    (existing)
--   ✅ mf_funds         (existing — category column needed, added in t243_migration.sql)

-- ai_narrative_log: optional — stores generated narratives per user per month
CREATE TABLE IF NOT EXISTS ai_narrative_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  month       VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM',
  mode        ENUM('ai','rule_based') NOT NULL DEFAULT 'rule_based',
  narrative   TEXT         NOT NULL,
  stats       JSON         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_month (user_id, month),
  CONSTRAINT fk_anl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
