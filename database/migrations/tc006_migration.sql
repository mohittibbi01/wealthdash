-- WealthDash — tc006: Cold Wallet Tracker Migration
CREATE TABLE IF NOT EXISTS cold_wallets (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NOT NULL,
  name        VARCHAR(100)  NOT NULL,
  type        ENUM('hardware','paper','mobile','air_gapped') NOT NULL DEFAULT 'hardware',
  device      VARCHAR(80)   NULL,
  address     VARCHAR(200)  NULL,
  network     VARCHAR(50)   NULL,
  notes       TEXT          NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_cw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cold_wallet_holdings (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wallet_id   INT UNSIGNED  NOT NULL,
  coin        VARCHAR(20)   NOT NULL,
  quantity    DECIMAL(24,8) NOT NULL DEFAULT 0,
  buy_price   DECIMAL(18,2) NULL DEFAULT 0,
  value_inr   DECIMAL(18,2) NULL DEFAULT 0,
  notes       VARCHAR(255)  NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_wallet_coin (wallet_id, coin),
  CONSTRAINT fk_cwh_wallet FOREIGN KEY (wallet_id) REFERENCES cold_wallets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
