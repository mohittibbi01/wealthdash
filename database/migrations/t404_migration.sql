-- WealthDash — t404: Smart Alerts v2 Migration
CREATE TABLE IF NOT EXISTS smart_alerts (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED  NOT NULL,
  alert_type    VARCHAR(40)   NOT NULL,
  dedup_key     VARCHAR(150)  NOT NULL,
  icon          VARCHAR(8)    NULL,
  message       VARCHAR(255)  NOT NULL,
  severity      ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  relevant_date DATE          NULL,
  is_read       TINYINT(1)    NOT NULL DEFAULT 0,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_dedup (user_id, dedup_key),
  KEY idx_user_read (user_id, is_read),
  CONSTRAINT fk_sa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smart_alert_settings (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL UNIQUE,
  sip_due         TINYINT(1)   NOT NULL DEFAULT 1,
  insurance_due   TINYINT(1)   NOT NULL DEFAULT 1,
  loan_emi_due    TINYINT(1)   NOT NULL DEFAULT 1,
  drawdown_alert  TINYINT(1)   NOT NULL DEFAULT 1,
  gain_alert      TINYINT(1)   NOT NULL DEFAULT 1,
  goal_milestone  TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_sas_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_sips, insurance_policies (t122), loans (t123), mf_holdings, mf_nav_latest
