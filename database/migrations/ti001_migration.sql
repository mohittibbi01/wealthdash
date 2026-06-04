-- WealthDash — ti001: Personal Finance Profile Migration
CREATE TABLE IF NOT EXISTS finance_profiles (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id               INT UNSIGNED  NOT NULL UNIQUE,
  age                   TINYINT       NULL,
  employment_type       ENUM('salaried','self_employed','business','freelancer','retired') NOT NULL DEFAULT 'salaried',
  annual_income         DECIMAL(14,2) NULL DEFAULT 0,
  tax_slab              VARCHAR(5)    NULL DEFAULT '30',
  risk_profile          ENUM('conservative','moderate','moderately_aggressive','aggressive') NOT NULL DEFAULT 'moderate',
  investment_horizon    ENUM('short','medium','long') NOT NULL DEFAULT 'long',
  monthly_expenses      DECIMAL(12,2) NULL DEFAULT 0,
  monthly_savings       DECIMAL(12,2) NULL DEFAULT 0,
  emergency_fund_months TINYINT       NULL DEFAULT 0,
  dependents            TINYINT       NULL DEFAULT 0,
  has_life_insurance    TINYINT(1)    NOT NULL DEFAULT 0,
  has_health_insurance  TINYINT(1)    NOT NULL DEFAULT 0,
  goals                 JSON          NULL,
  income_sources        JSON          NULL,
  notes                 TEXT          NULL,
  created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
