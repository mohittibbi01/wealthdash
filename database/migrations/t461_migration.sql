-- WealthDash — t461: ULIP Tracker Full Migration
CREATE TABLE IF NOT EXISTS ulip_switches (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ulip_id     INT UNSIGNED  NOT NULL,
  from_fund   VARCHAR(150)  NOT NULL,
  to_fund     VARCHAR(150)  NOT NULL,
  amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  switch_date DATE          NOT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ulip (ulip_id, switch_date),
  CONSTRAINT fk_usw_ulip FOREIGN KEY (ulip_id) REFERENCES ulip_policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ulip_fund_values
  ADD INDEX IF NOT EXISTS idx_ulip_fund_date (ulip_id, fund_name, value_date);
