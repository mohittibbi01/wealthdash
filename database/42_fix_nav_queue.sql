-- ============================================================
-- WealthDash Migration 42 — Fix NAV Download Queue (BUG-01)
-- Run once: fixes SQLSTATE[42S22] missing column errors
-- ============================================================

-- Fix nav_download_queue missing columns
ALTER TABLE nav_download_queue
  ADD COLUMN IF NOT EXISTS retry_count       TINYINT      NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS nav_records_added INT          NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS error_msg         VARCHAR(500)          DEFAULT NULL;

-- Ensure funds table has nav_date (should exist per schema but verify)
ALTER TABLE funds
  ADD COLUMN IF NOT EXISTS nav_date DATE DEFAULT NULL;

-- Ensure nav_download_progress table exists (for nav_download/processor.php)
CREATE TABLE IF NOT EXISTS nav_download_progress (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  scheme_code   VARCHAR(20)  NOT NULL,
  fund_id       INT          DEFAULT NULL,
  status        ENUM('pending','running','completed','error') NOT NULL DEFAULT 'pending',
  last_downloaded_date DATE  DEFAULT NULL,
  records_saved INT          NOT NULL DEFAULT 0,
  error_msg     VARCHAR(500) DEFAULT NULL,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_scheme (scheme_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed nav_download_progress from funds table (pending for all active funds)
INSERT IGNORE INTO nav_download_progress (scheme_code, fund_id, status)
  SELECT scheme_code, id, 'pending' FROM funds WHERE scheme_code IS NOT NULL;

SELECT 'Migration 42 complete — nav_download_queue columns added' AS result;
