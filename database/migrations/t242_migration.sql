-- WealthDash — t242: Investor Streak & Milestones Migration

CREATE TABLE IF NOT EXISTS investor_streaks (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL UNIQUE,
  current_streak  INT UNSIGNED NOT NULL DEFAULT 0,
  longest_streak  INT UNSIGNED NOT NULL DEFAULT 0,
  last_checked    DATETIME     NULL,
  CONSTRAINT fk_is_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_checkins (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  checkin_date  DATE         NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_date (user_id, checkin_date),
  CONSTRAINT fk_dci_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_badges (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  badge_key   VARCHAR(40)  NOT NULL,
  earned_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_badge (user_id, badge_key),
  CONSTRAINT fk_ub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_transactions, mf_holdings, mf_nav_latest, goals,
--             insurance_policies (t122), finance_profiles (ti001)
