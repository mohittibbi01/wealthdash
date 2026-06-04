-- WealthDash — t42: Crypto Tax Calculator Migration
CREATE TABLE IF NOT EXISTS crypto_tax_reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  fy          VARCHAR(7)   NOT NULL COMMENT 'e.g. 2024-25',
  label       VARCHAR(100) NOT NULL DEFAULT 'Crypto Tax',
  trades      JSON         NOT NULL,
  summary     JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_fy (user_id, fy),
  CONSTRAINT fk_ctx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
