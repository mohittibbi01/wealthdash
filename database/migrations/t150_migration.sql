-- WealthDash — t150: DigiLocker Integration Migration

CREATE TABLE IF NOT EXISTS user_documents (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NOT NULL,
  category    VARCHAR(40)   NOT NULL DEFAULT 'other',
  doc_name    VARCHAR(150)  NOT NULL,
  file_path   VARCHAR(255)  NOT NULL COMMENT 'Random filename in storage/documents/<user_id>/',
  file_size   INT UNSIGNED  NOT NULL DEFAULT 0,
  expiry_date DATE          NULL,
  source      ENUM('manual_upload','digilocker') NOT NULL DEFAULT 'manual_upload',
  uploaded_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_category (user_id, category),
  KEY idx_user_expiry   (user_id, expiry_date),
  CONSTRAINT fk_ud_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digilocker_connections (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL UNIQUE,
  connected     TINYINT(1)   NOT NULL DEFAULT 0,
  access_token  VARCHAR(500) NULL COMMENT 'Encrypted with WDCrypt when OAuth is wired in',
  connected_at  DATETIME     NULL,
  CONSTRAINT fk_dc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IMPORTANT: storage/documents/ must be writable by PHP and NON-web-accessible
-- (serve files only through an authenticated download action, not directly).
