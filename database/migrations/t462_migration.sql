-- WealthDash — t462: Premium Calendar Migration
CREATE TABLE IF NOT EXISTS premium_payments (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED  NOT NULL,
  policy_id      INT UNSIGNED  NOT NULL,
  due_date       DATE          NOT NULL,
  amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_date      DATE          NOT NULL,
  payment_method VARCHAR(30)   NULL,
  notes          VARCHAR(255)  NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_policy_due (user_id, policy_id, due_date),
  KEY idx_user_paid (user_id, paid_date),
  CONSTRAINT fk_pp_user   FOREIGN KEY (user_id)   REFERENCES users(id)               ON DELETE CASCADE,
  CONSTRAINT fk_pp_policy FOREIGN KEY (policy_id) REFERENCES insurance_policies(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
