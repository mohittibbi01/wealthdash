-- WealthDash — t445: Customizable Overview Cards Migration
CREATE TABLE IF NOT EXISTS overview_card_prefs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL UNIQUE,
  card_order  JSON         NOT NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ocp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_holdings, mf_nav_latest, mf_sips, goals, goal_checkins (tg005),
--             insurance_policies (t122), loans (t123)
