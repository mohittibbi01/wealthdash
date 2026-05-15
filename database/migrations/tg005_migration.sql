-- WealthDash — tg005: Goals vs Actual Tracking Migration

-- goal_checkins: monthly investment log per goal
CREATE TABLE IF NOT EXISTS goal_checkins (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  goal_id      INT UNSIGNED  NOT NULL,
  user_id      INT UNSIGNED  NOT NULL,
  amount       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  checkin_date DATE          NOT NULL,
  notes        VARCHAR(255)  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_goal_date   (goal_id, checkin_date),
  KEY idx_user_date   (user_id, checkin_date),
  CONSTRAINT fk_gc_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
  CONSTRAINT fk_gc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure goals table has required columns (add if missing)
-- These are additive ALTER — safe to run on existing table
ALTER TABLE goals
  ADD COLUMN IF NOT EXISTS start_date  DATE         NULL AFTER goal_name,
  ADD COLUMN IF NOT EXISTS goal_type   VARCHAR(50)  NULL DEFAULT 'general' AFTER target_amount,
  ADD COLUMN IF NOT EXISTS priority    ENUM('low','medium','high') NOT NULL DEFAULT 'medium' AFTER goal_type,
  ADD COLUMN IF NOT EXISTS notes       TEXT         NULL AFTER priority;

-- mf_sips: add goal_id FK if not present (optional link)
ALTER TABLE mf_sips
  ADD COLUMN IF NOT EXISTS goal_id INT UNSIGNED NULL DEFAULT NULL AFTER portfolio_id,
  ADD KEY IF NOT EXISTS idx_sip_goal (goal_id);
