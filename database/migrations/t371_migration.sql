-- WealthDash — t371: WebAuthn Biometric Login Migration
CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED  NOT NULL,
  credential_id  VARCHAR(512)  NOT NULL UNIQUE,
  public_key_spki TEXT         NULL COMMENT 'Raw attestation object (base64)',
  device_name    VARCHAR(100)  NOT NULL DEFAULT 'My Device',
  sign_count     INT UNSIGNED  NOT NULL DEFAULT 0,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at   DATETIME      NULL,
  KEY idx_user (user_id),
  CONSTRAINT fk_wc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
