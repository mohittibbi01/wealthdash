-- WealthDash — tg003: Retirement Corpus Calculator Migration

CREATE TABLE IF NOT EXISTS retirement_plans (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NOT NULL,
  plan_name   VARCHAR(100)  NOT NULL DEFAULT 'My Retirement Plan',
  inputs      JSON          NOT NULL COMMENT 'Calculator input parameters',
  results     JSON          NOT NULL COMMENT 'Last calculated results',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_id (user_id),
  CONSTRAINT fk_ret_plan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
