-- WealthDash — t59: AI Auto Categorization Migration
CREATE TABLE IF NOT EXISTS category_rules (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  keyword     VARCHAR(80)  NOT NULL,
  category    VARCHAR(60)  NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_keyword (user_id, keyword),
  CONSTRAINT fk_cr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: budget_actuals (t471_migration.sql)
