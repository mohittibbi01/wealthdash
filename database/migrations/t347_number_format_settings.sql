-- Task t347: Number Formatting — Indian system everywhere
-- Migration: add number format preferences to user_settings

CREATE TABLE IF NOT EXISTS user_settings (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id               INT UNSIGNED NOT NULL,
  number_format         VARCHAR(10)  NOT NULL DEFAULT 'indian'  COMMENT 'indian | international',
  compact_large_numbers TINYINT(1)   NOT NULL DEFAULT 1         COMMENT '1 = show 1.5L/2.3Cr',
  currency_symbol       VARCHAR(5)   NOT NULL DEFAULT '₹',
  decimal_places        TINYINT      NOT NULL DEFAULT 2,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If table already exists, add columns safely
ALTER TABLE user_settings
  ADD COLUMN IF NOT EXISTS number_format         VARCHAR(10) NOT NULL DEFAULT 'indian',
  ADD COLUMN IF NOT EXISTS compact_large_numbers TINYINT(1)  NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS currency_symbol       VARCHAR(5)  NOT NULL DEFAULT '₹',
  ADD COLUMN IF NOT EXISTS decimal_places        TINYINT     NOT NULL DEFAULT 2;
