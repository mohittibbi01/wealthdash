-- ============================================================
-- WealthDash Migration: t479 — Duplicate Transaction Detector
-- ============================================================

-- Add dedup hash columns to MF transactions
ALTER TABLE `mf_transactions`
  ADD COLUMN `dedup_hash` varchar(64) DEFAULT NULL COMMENT 'SHA256 of portfolio+fund+date+type+units+amount',
  ADD COLUMN `is_duplicate` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `duplicate_of` int(10) UNSIGNED DEFAULT NULL,
  ADD KEY `idx_mft_dedup` (`dedup_hash`),
  ADD KEY `idx_mft_is_dup` (`is_duplicate`);

-- Add dedup hash columns to stock transactions
ALTER TABLE `stock_transactions`
  ADD COLUMN `dedup_hash` varchar(64) DEFAULT NULL COMMENT 'SHA256 of portfolio+stock+date+type+qty+price',
  ADD COLUMN `is_duplicate` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `duplicate_of` int(10) UNSIGNED DEFAULT NULL,
  ADD KEY `idx_st_dedup` (`dedup_hash`),
  ADD KEY `idx_st_is_dup` (`is_duplicate`);

-- Dedup review / dismiss log
CREATE TABLE IF NOT EXISTS `dedup_review_log` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_type` enum('mf','stocks','nps') NOT NULL,
  `txn_id` int(10) UNSIGNED NOT NULL,
  `action` enum('merged','dismissed','kept') NOT NULL,
  `reviewed_by` int(10) UNSIGNED NOT NULL,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dedup_txn` (`asset_type`, `txn_id`),
  KEY `idx_dedup_user` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
