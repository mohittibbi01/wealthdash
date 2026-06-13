-- WealthDash — t243: AI Fund Recommendation Migration (FIXED)
-- mf_funds table exist nahi karti — ALTER hata diya.
-- Fund category mapping PHP code mein rule-based handle hogi.

-- Rate limit log (if not already created by security module)
CREATE TABLE IF NOT EXISTS rate_limit_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(80)  NOT NULL,
  ip         VARCHAR(45)  NULL,
  hit_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_action_time (action, hit_at),
  KEY idx_user_action (user_id, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
