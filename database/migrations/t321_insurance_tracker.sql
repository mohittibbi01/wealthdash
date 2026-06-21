-- t321: Term Insurance Tracker | t322: Health Insurance Tracker
-- t459: Term Insurance Adequacy | t324: Premium Calendar
-- Adds next_premium_date column to insurance_policies (if not exists)

ALTER TABLE `insurance_policies`
  ADD COLUMN IF NOT EXISTS `next_premium_date` DATE NULL COMMENT 'Next premium due date' AFTER `notes`;

-- Backfill: auto-compute next_premium_date for existing active policies
UPDATE insurance_policies ip
SET ip.next_premium_date = CASE
  WHEN ip.premium_frequency = 'monthly'     THEN DATE_ADD(ip.start_date, INTERVAL TIMESTAMPDIFF(MONTH, ip.start_date, CURDATE())+1 MONTH)
  WHEN ip.premium_frequency = 'quarterly'   THEN DATE_ADD(ip.start_date, INTERVAL (CEIL(TIMESTAMPDIFF(MONTH, ip.start_date, CURDATE())/3.0))*3 MONTH)
  WHEN ip.premium_frequency = 'half_yearly' THEN DATE_ADD(ip.start_date, INTERVAL (CEIL(TIMESTAMPDIFF(MONTH, ip.start_date, CURDATE())/6.0))*6 MONTH)
  WHEN ip.premium_frequency = 'yearly'      THEN DATE_ADD(ip.start_date, INTERVAL TIMESTAMPDIFF(YEAR,  ip.start_date, CURDATE())+1 YEAR)
  ELSE NULL
END
WHERE ip.status = 'active' AND ip.next_premium_date IS NULL AND ip.premium_frequency != 'single';

-- Indexes
ALTER TABLE `insurance_policies`
  ADD INDEX IF NOT EXISTS `idx_ins_next_prem` (`next_premium_date`),
  ADD INDEX IF NOT EXISTS `idx_ins_status`    (`status`);
