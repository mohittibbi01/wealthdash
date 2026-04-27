-- t134: 54EC Bond Tracker
-- 54EC bonds: REC / NHAI / PFC / IRFC — capital gains exemption u/s 54EC
CREATE TABLE IF NOT EXISTS `bonds_54ec` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `bond_issuer`     ENUM('REC','NHAI','PFC','IRFC','OTHER') NOT NULL DEFAULT 'REC',
  `issuer_name`     VARCHAR(100) NOT NULL DEFAULT '',          -- custom name if OTHER
  `investment_date` DATE NOT NULL,
  `maturity_date`   DATE NOT NULL,
  `face_value`      DECIMAL(14,2) NOT NULL DEFAULT 10000.00,  -- per bond ₹10,000
  `num_bonds`       INT UNSIGNED NOT NULL DEFAULT 1,
  `total_invested`  DECIMAL(14,2) NOT NULL,                   -- num_bonds × face_value
  `interest_rate`   DECIMAL(5,2) NOT NULL DEFAULT 5.00,       -- p.a.
  `interest_freq`   ENUM('annual','cumulative') NOT NULL DEFAULT 'annual',
  `ltcg_exempted`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,      -- original LTCG claim
  `sale_asset_date` DATE NULL,                                -- date of original asset sale
  `folio_number`    VARCHAR(80) NULL,
  `notes`           TEXT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_54ec_user` (`user_id`),
  INDEX `idx_54ec_maturity` (`maturity_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
