-- WealthDash — t246: AI Anomaly Detector Migration (FIXED)
-- mf_transactions mein mf_id nahi hai — ALTER hata diya.
-- sip_id column bhi skip — transactions table structure as-is use hoga.

-- anomaly_log: stores flagged anomalies per scan
CREATE TABLE IF NOT EXISTS anomaly_log (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED  NOT NULL,
  scan_date    DATE          NOT NULL,
  anomaly_type VARCHAR(60)   NOT NULL,
  txn_date     DATE          NULL,
  fund_name    VARCHAR(150)  NULL,
  txn_type     VARCHAR(30)   NULL,
  amount       DECIMAL(14,2) NULL,
  reason       VARCHAR(255)  NULL,
  severity     ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  resolved     TINYINT(1)    NOT NULL DEFAULT 0,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_scan     (user_id, scan_date),
  KEY idx_user_resolved (user_id, resolved),
  CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
