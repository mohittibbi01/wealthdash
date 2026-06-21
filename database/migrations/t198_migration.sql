-- WealthDash t198: NPS Screener Enhanced
-- nps_screener.php is full overwrite of existing — no new tables needed.
-- Add missing columns to nps_schemes if any.
ALTER TABLE `nps_schemes`
  ADD COLUMN IF NOT EXISTS `return_1y`   decimal(6,2) DEFAULT NULL AFTER `asset_class`,
  ADD COLUMN IF NOT EXISTS `return_3y`   decimal(6,2) DEFAULT NULL AFTER `return_1y`,
  ADD COLUMN IF NOT EXISTS `return_5y`   decimal(6,2) DEFAULT NULL AFTER `return_3y`,
  ADD COLUMN IF NOT EXISTS `return_since`decimal(6,2) DEFAULT NULL AFTER `return_5y`,
  ADD COLUMN IF NOT EXISTS `aum_cr`      decimal(14,2) DEFAULT NULL AFTER `return_since`,
  ADD COLUMN IF NOT EXISTS `latest_nav`  decimal(12,4) DEFAULT NULL AFTER `aum_cr`,
  ADD COLUMN IF NOT EXISTS `latest_nav_date` date DEFAULT NULL AFTER `latest_nav`,
  ADD COLUMN IF NOT EXISTS `nav_returns_updated_at` datetime DEFAULT NULL AFTER `latest_nav_date`,
  ADD COLUMN IF NOT EXISTS `is_active`   tinyint(1) NOT NULL DEFAULT 1 AFTER `nav_returns_updated_at`;
CREATE INDEX IF NOT EXISTS `idx_nps_asset_class` ON `nps_schemes` (`asset_class`);
CREATE INDEX IF NOT EXISTS `idx_nps_return_1y`   ON `nps_schemes` (`return_1y`);
SELECT 't198 NPS Screener migration complete ✅' AS status;
