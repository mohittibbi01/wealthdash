-- ============================================================
-- t460: Health Insurance Tracker
-- Adds health-specific fields + claims + family members tables
-- ============================================================

-- Step 1: Add health-specific columns to insurance_policies
ALTER TABLE `insurance_policies`
  ADD COLUMN IF NOT EXISTS `health_type`          ENUM('individual','family_floater','senior_citizen','super_topup','critical_illness','personal_accident') NULL COMMENT 'Health plan subtype' AFTER `policy_type`,
  ADD COLUMN IF NOT EXISTS `room_rent_limit`       DECIMAL(12,2) NULL COMMENT 'Per day room rent limit (NULL = no limit)' AFTER `health_type`,
  ADD COLUMN IF NOT EXISTS `copay_pct`             TINYINT UNSIGNED NULL DEFAULT 0 COMMENT 'Copay % (0 = no copay)' AFTER `room_rent_limit`,
  ADD COLUMN IF NOT EXISTS `deductible`            DECIMAL(12,2) NULL DEFAULT 0 COMMENT 'Deductible/excess amount' AFTER `copay_pct`,
  ADD COLUMN IF NOT EXISTS `waiting_period_initial` SMALLINT UNSIGNED NULL DEFAULT 30 COMMENT 'Initial waiting period in days' AFTER `deductible`,
  ADD COLUMN IF NOT EXISTS `waiting_period_pd`     SMALLINT UNSIGNED NULL DEFAULT 1095 COMMENT 'Pre-existing disease waiting period in days' AFTER `waiting_period_initial`,
  ADD COLUMN IF NOT EXISTS `no_claim_bonus`        DECIMAL(6,2) NULL DEFAULT 0 COMMENT 'No-claim bonus % accumulated' AFTER `waiting_period_pd`,
  ADD COLUMN IF NOT EXISTS `network_hospitals`     TEXT NULL COMMENT 'Key network hospitals (CSV or notes)' AFTER `no_claim_bonus`,
  ADD COLUMN IF NOT EXISTS `tpa_name`              VARCHAR(100) NULL COMMENT 'TPA / Third Party Administrator' AFTER `network_hospitals`,
  ADD COLUMN IF NOT EXISTS `tpa_contact`           VARCHAR(50)  NULL AFTER `tpa_name`,
  ADD COLUMN IF NOT EXISTS `base_policy_id`        INT UNSIGNED NULL COMMENT 'FK to base policy (for super top-up)' AFTER `tpa_contact`,
  ADD COLUMN IF NOT EXISTS `restore_benefit`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Restoration benefit available' AFTER `base_policy_id`,
  ADD COLUMN IF NOT EXISTS `daycare_covered`        TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Day-care procedures covered' AFTER `restore_benefit`,
  ADD COLUMN IF NOT EXISTS `maternity_covered`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Maternity benefit' AFTER `daycare_covered`,
  ADD COLUMN IF NOT EXISTS `maternity_waiting`     SMALLINT UNSIGNED NULL DEFAULT 730 COMMENT 'Maternity waiting period days' AFTER `maternity_covered`,
  ADD COLUMN IF NOT EXISTS `portability_done`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Policy ported from another insurer' AFTER `maternity_waiting`,
  ADD COLUMN IF NOT EXISTS `portability_from`      VARCHAR(100) NULL AFTER `portability_done`;

-- Step 2: Family members covered under the policy
CREATE TABLE IF NOT EXISTS `health_insurance_members` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `policy_id`     INT UNSIGNED    NOT NULL,
  `member_name`   VARCHAR(100)    NOT NULL,
  `relation`      ENUM('self','spouse','son','daughter','father','mother','father_in_law','mother_in_law','other') NOT NULL DEFAULT 'self',
  `dob`           DATE            NULL,
  `age`           TINYINT UNSIGNED NULL,
  `gender`        ENUM('male','female','other') NULL,
  `pre_existing`  TEXT            NULL COMMENT 'Pre-existing conditions (comma-sep)',
  `sum_insured`   DECIMAL(12,2)   NULL COMMENT 'Individual SI if not floater',
  `notes`         TEXT            NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_him_policy`  (`policy_id`),
  CONSTRAINT `fk_him_policy` FOREIGN KEY (`policy_id`) REFERENCES `insurance_policies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Members covered under health insurance policies (t460)';

-- Step 3: Claims tracker
CREATE TABLE IF NOT EXISTS `health_insurance_claims` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `policy_id`       INT UNSIGNED    NOT NULL,
  `member_id`       INT UNSIGNED    NULL COMMENT 'FK to health_insurance_members',
  `claim_number`    VARCHAR(60)     NULL,
  `claim_type`      ENUM('cashless','reimbursement') NOT NULL DEFAULT 'reimbursement',
  `claim_date`      DATE            NOT NULL,
  `hospital_name`   VARCHAR(150)    NULL,
  `diagnosis`       VARCHAR(200)    NULL,
  `admission_date`  DATE            NULL,
  `discharge_date`  DATE            NULL,
  `claimed_amount`  DECIMAL(14,2)   NOT NULL DEFAULT 0,
  `approved_amount` DECIMAL(14,2)   NULL,
  `settled_amount`  DECIMAL(14,2)   NULL,
  `deducted_amount` DECIMAL(14,2)   NULL COMMENT 'Copay + deductible applied',
  `status`          ENUM('submitted','under_review','approved','partially_approved','rejected','settled','withdrawn') NOT NULL DEFAULT 'submitted',
  `settlement_date` DATE            NULL,
  `rejection_reason`VARCHAR(300)    NULL,
  `documents_submitted` TEXT        NULL COMMENT 'Documents list',
  `notes`           TEXT            NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hic_policy`  (`policy_id`),
  KEY `idx_hic_member`  (`member_id`),
  KEY `idx_hic_status`  (`status`),
  KEY `idx_hic_date`    (`claim_date`),
  CONSTRAINT `fk_hic_policy` FOREIGN KEY (`policy_id`) REFERENCES `insurance_policies`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hic_member` FOREIGN KEY (`member_id`) REFERENCES `health_insurance_members`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Health insurance claims tracker (t460)';

-- Step 4: Indexes
ALTER TABLE `insurance_policies`
  ADD INDEX IF NOT EXISTS `idx_ins_health_type` (`health_type`);
