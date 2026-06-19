-- WealthDash — t463: Property Portfolio Migration
CREATE TABLE IF NOT EXISTS properties (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED  NOT NULL,
  property_name    VARCHAR(150)  NOT NULL,
  property_type    ENUM('residential','commercial','land','agricultural','other') NOT NULL DEFAULT 'residential',
  address          VARCHAR(255)  NULL,
  area_sqft        DECIMAL(10,2) NULL,
  purchase_price   DECIMAL(16,2) NOT NULL DEFAULT 0,
  purchase_date    DATE          NULL,
  loan_outstanding DECIMAL(16,2) NOT NULL DEFAULT 0,
  monthly_rental   DECIMAL(12,2) NOT NULL DEFAULT 0,
  status           ENUM('active','sold') NOT NULL DEFAULT 'active',
  notes            TEXT          NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_type   (user_id, property_type),
  KEY idx_user_status (user_id, status),
  CONSTRAINT fk_prop_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_valuations (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  property_id     INT UNSIGNED  NOT NULL,
  current_value   DECIMAL(16,2) NOT NULL DEFAULT 0,
  valuation_date  DATE          NOT NULL,
  source          VARCHAR(50)   NULL DEFAULT 'manual',
  notes           VARCHAR(255)  NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_property_date (property_id, valuation_date),
  CONSTRAINT fk_pv_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
