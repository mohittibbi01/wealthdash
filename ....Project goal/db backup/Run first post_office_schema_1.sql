-- ================================================================
-- WealthDash — Post Office Schemes
-- Run this once to create the table
-- ================================================================

CREATE TABLE IF NOT EXISTS `po_schemes` (
  `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `portfolio_id`     INT UNSIGNED    NOT NULL,
  `scheme_type`      ENUM(
                       'savings_account',
                       'rd',
                       'td',
                       'mis',
                       'scss',
                       'ppf',
                       'ssy',
                       'nsc',
                       'kvp'
                     ) NOT NULL,
  `account_number`   VARCHAR(50)     DEFAULT NULL,
  `holder_name`      VARCHAR(100)    NOT NULL,
  `principal`        DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  `interest_rate`    DECIMAL(6,3)    NOT NULL,
  `open_date`        DATE            NOT NULL,
  `maturity_date`    DATE            DEFAULT NULL,
  `maturity_amount`  DECIMAL(14,2)   DEFAULT NULL,
  `current_value`    DECIMAL(14,2)   DEFAULT NULL COMMENT 'For PPF/SSY: running balance',
  `deposit_amount`   DECIMAL(14,2)   DEFAULT NULL COMMENT 'For RD: monthly instalment',
  `interest_freq`    ENUM('monthly','quarterly','half_yearly','yearly','cumulative','on_maturity') NOT NULL DEFAULT 'yearly',
  `compounding`      ENUM('simple','compound') NOT NULL DEFAULT 'compound',
  `status`           ENUM('active','matured','closed','partial_withdrawn') NOT NULL DEFAULT 'active',
  `is_joint`         TINYINT(1)      NOT NULL DEFAULT 0,
  `nominee`          VARCHAR(100)    DEFAULT NULL,
  `post_office`      VARCHAR(150)    DEFAULT NULL COMMENT 'Branch / post office name',
  `notes`            TEXT            DEFAULT NULL,
  `investment_fy`    VARCHAR(7)      DEFAULT NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_po_portfolio` (`portfolio_id`),
  KEY `idx_po_type`      (`scheme_type`),
  KEY `idx_po_status`    (`status`),
  CONSTRAINT `fk_po_portfolio`
    FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
