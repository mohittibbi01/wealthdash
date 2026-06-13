-- WealthDash — t300: Portfolio Heatmap Migration (FIXED)
-- mf_funds table exist nahi karti — ALTER hata diya.
-- Heatmap category field mf_holdings se hi aayega.

-- mf_nav_history: historical NAV for period return calculation
CREATE TABLE IF NOT EXISTS mf_nav_history (
  id        INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mf_id     INT UNSIGNED  NOT NULL,
  nav_date  DATE          NOT NULL,
  nav       DECIMAL(14,4) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_mf_date (mf_id, nav_date),
  KEY idx_mf_id    (mf_id),
  KEY idx_nav_date (nav_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
