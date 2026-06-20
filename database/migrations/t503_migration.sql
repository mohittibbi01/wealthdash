-- WealthDash — t503: Credit Card Optimizer Migration

CREATE TABLE IF NOT EXISTS credit_cards (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED  NOT NULL,
  card_name      VARCHAR(100)  NOT NULL,
  bank           VARCHAR(80)   NULL,
  credit_limit   DECIMAL(12,2) NOT NULL DEFAULT 0,
  outstanding    DECIMAL(12,2) NOT NULL DEFAULT 0,
  reward_rate    DECIMAL(5,2)  NOT NULL DEFAULT 1.00,
  reward_type    ENUM('cashback','points','miles') NOT NULL DEFAULT 'cashback',
  interest_rate  DECIMAL(5,2)  NOT NULL DEFAULT 42.00,
  due_date       TINYINT       NOT NULL DEFAULT 15,
  annual_fee     DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_cc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
