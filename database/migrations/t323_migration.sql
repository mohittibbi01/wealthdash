-- WealthDash — t323: ULIP Tracker Migration
CREATE TABLE IF NOT EXISTS ulip_policies (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED  NOT NULL,
  policy_name         VARCHAR(150)  NOT NULL,
  insurer             VARCHAR(100)  NULL,
  policy_number       VARCHAR(60)   NULL,
  premium_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  premium_frequency   ENUM('monthly','quarterly','half_yearly','annual') NOT NULL DEFAULT 'annual',
  sum_assured         DECIMAL(16,2) NOT NULL DEFAULT 0,
  start_date          DATE          NULL,
  maturity_date       DATE          NULL,
  current_fund_value  DECIMAL(16,2) NOT NULL DEFAULT 0,
  total_premium_paid  DECIMAL(16,2) NOT NULL DEFAULT 0,
  lock_in_years       TINYINT       NOT NULL DEFAULT 5,
  status              ENUM('active','surrendered','matured','lapsed') NOT NULL DEFAULT 'active',
  notes               TEXT          NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_ulip_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ulip_fund_values (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ulip_id     INT UNSIGNED  NOT NULL,
  fund_name   VARCHAR(150)  NOT NULL,
  units       DECIMAL(14,4) NOT NULL DEFAULT 0,
  nav         DECIMAL(12,4) NOT NULL DEFAULT 0,
  value_date  DATE          NOT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ulip (ulip_id, value_date),
  CONSTRAINT fk_ufv_ulip FOREIGN KEY (ulip_id) REFERENCES ulip_policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
