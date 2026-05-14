-- WealthDash — t151: EPFO Passbook Sync Migration

CREATE TABLE IF NOT EXISTS epfo_accounts (
  id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED    NOT NULL,
  uan                 VARCHAR(12)     NOT NULL,
  member_id           VARCHAR(30)     NULL,
  member_name         VARCHAR(120)    NULL,
  establishment_name  VARCHAR(200)    NULL,
  balance_employee    DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  balance_employer    DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  balance_total       DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  last_sync_at        DATETIME        NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_uan (user_id, uan),
  KEY idx_user_id (user_id),
  CONSTRAINT fk_epfo_acc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epfo_passbook (
  id           INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id   INT UNSIGNED    NOT NULL,
  txn_date     DATE            NOT NULL,
  wage_month   VARCHAR(7)      NULL COMMENT 'YYYY-MM',
  type         ENUM('employee','employer','pension','withdrawal','interest','other') NOT NULL DEFAULT 'other',
  amount       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  remarks      VARCHAR(255)    NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_account_date (account_id, txn_date),
  CONSTRAINT fk_epfo_pb_acc FOREIGN KEY (account_id) REFERENCES epfo_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
