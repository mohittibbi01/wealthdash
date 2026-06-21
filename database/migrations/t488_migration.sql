-- WealthDash — t488: Yield Curve Migration

CREATE TABLE IF NOT EXISTS yield_curve_reference (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenure_months SMALLINT     NOT NULL UNIQUE,
  tenure_label  VARCHAR(10)  NOT NULL,
  rate          DECIMAL(5,2) NOT NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tenure (tenure_months)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default reference curve (typical Indian bank FD rates)
INSERT IGNORE INTO yield_curve_reference (tenure_months, tenure_label, rate) VALUES
  (3,   '3M',  6.50),
  (6,   '6M',  7.00),
  (12,  '1Y',  7.10),
  (24,  '2Y',  7.25),
  (36,  '3Y',  7.00),
  (60,  '5Y',  6.75),
  (120, '10Y', 6.50);

-- NOTE: Gracefully handles absence of fd_investments table (skipped via
-- try/catch in PHP) — this is part of another module (api/mf/* or similar,
-- skipped per session rules). If fd_investments exists, user FDs auto-plot.
