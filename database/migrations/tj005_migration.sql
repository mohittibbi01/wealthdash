-- WealthDash — tj005: Annual Financial Review Wizard Migration

CREATE TABLE IF NOT EXISTS annual_reviews (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  review_year   SMALLINT     NOT NULL,
  checked_items JSON         NOT NULL DEFAULT ('[]'),
  notes         TEXT         NULL,
  completed_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_year (user_id, review_year),
  CONSTRAINT fk_ar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
