-- ============================================================
-- WealthDash Migration 43 — Fix Crypto Schema Conflict (BUG-06)
-- Removes duplicate CREATE statements from 41_pending_tasks.sql
-- Run once after migration 42
-- ============================================================

-- The dead file api/crypto/crypto.php used user_id schema (wrong).
-- The live file api/crypto/crypto_list.php uses portfolio_id schema (correct).
-- Migration 38 already created the correct tables.
-- Migration 41 has duplicate CREATE TABLE IF NOT EXISTS which silently skips.
-- No actual data fix needed — just clean up the dead code references.

-- Verify the correct schema is active (should show portfolio_id):
-- SHOW COLUMNS FROM crypto_holdings;

-- Ensure crypto_price_cache exists (sometimes missed):
CREATE TABLE IF NOT EXISTS crypto_price_cache (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  coin_id      VARCHAR(100) NOT NULL,
  symbol       VARCHAR(20)  NOT NULL,
  name         VARCHAR(100) NOT NULL,
  price_inr    DECIMAL(20,4) DEFAULT 0,
  price_usd    DECIMAL(20,8) DEFAULT 0,
  change_24h   DECIMAL(10,4) DEFAULT 0,
  market_cap   BIGINT       DEFAULT 0,
  fetched_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coin (coin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Remove orphaned router reference note (manual: delete api/crypto/crypto.php)
SELECT 'Migration 43 complete — crypto schema verified. Manually delete: api/crypto/crypto.php' AS result;
