-- WealthDash — t471: Monthly Budget Tracker Migration

CREATE TABLE IF NOT EXISTS budget_plans (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  month       VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM',
  plan_json   JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_month (user_id, month),
  CONSTRAINT fk_bp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_actuals (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NOT NULL,
  category    VARCHAR(60)   NOT NULL,
  txn_type    ENUM('income','expense','savings') NOT NULL DEFAULT 'expense',
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  txn_date    DATE          NOT NULL,
  description VARCHAR(255)  NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_month  (user_id, txn_date),
  KEY idx_user_cat    (user_id, category),
  CONSTRAINT fk_ba_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
