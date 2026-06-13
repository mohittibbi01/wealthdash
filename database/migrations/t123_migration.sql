-- WealthDash — t123: Loan Tracker Migration
CREATE TABLE IF NOT EXISTS loans (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id               INT UNSIGNED  NOT NULL,
  loan_name             VARCHAR(150)  NOT NULL,
  loan_type             ENUM('home','personal','education','vehicle','gold','other') NOT NULL DEFAULT 'personal',
  lender                VARCHAR(100)  NULL,
  loan_amount           DECIMAL(16,2) NOT NULL DEFAULT 0,
  outstanding_principal DECIMAL(16,2) NOT NULL DEFAULT 0,
  interest_rate         DECIMAL(6,2)  NOT NULL DEFAULT 0,
  tenure_months         SMALLINT      NOT NULL DEFAULT 12,
  emi_amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
  emi_date              TINYINT       NOT NULL DEFAULT 5,
  start_date            DATE          NULL,
  end_date              DATE          NULL,
  total_paid            DECIMAL(16,2) NOT NULL DEFAULT 0,
  status                ENUM('active','closed','written_off') NOT NULL DEFAULT 'active',
  notes                 TEXT          NULL,
  created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_type (user_id, loan_type),
  KEY idx_user_status (user_id, status),
  CONSTRAINT fk_loan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
