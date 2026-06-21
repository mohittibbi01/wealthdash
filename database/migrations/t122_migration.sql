-- WealthDash — t122: Insurance Portfolio Migration
CREATE TABLE IF NOT EXISTS insurance_policies (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id            INT UNSIGNED  NOT NULL,
  policy_name        VARCHAR(150)  NOT NULL,
  policy_type        ENUM('term','health','ulip','endowment','vehicle','other') NOT NULL DEFAULT 'term',
  insurer            VARCHAR(100)  NULL,
  policy_number      VARCHAR(60)   NULL,
  sum_assured        DECIMAL(16,2) NOT NULL DEFAULT 0,
  premium_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  premium_frequency  ENUM('monthly','quarterly','half_yearly','annual','single') NOT NULL DEFAULT 'annual',
  start_date         DATE          NULL,
  maturity_date      DATE          NULL,
  next_premium_date  DATE          NULL,
  maturity_amount    DECIMAL(16,2) NULL,
  nominee            VARCHAR(100)  NULL,
  status             ENUM('active','lapsed','surrendered','matured') NOT NULL DEFAULT 'active',
  notes              TEXT          NULL,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_type (user_id, policy_type),
  KEY idx_user_next (user_id, next_premium_date),
  CONSTRAINT fk_ins_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
