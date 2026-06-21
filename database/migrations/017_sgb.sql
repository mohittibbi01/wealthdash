-- WealthDash Migration 017: SGB Holdings (t113)
-- Run ONCE — idempotent via IF NOT EXISTS

CREATE TABLE IF NOT EXISTS `sgb_holdings` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `portfolio_id`     INT UNSIGNED NOT NULL,
    `series_name`      VARCHAR(100) NOT NULL COMMENT 'e.g. SGB 2019-20 Series II',
    `tranche`          VARCHAR(50)  DEFAULT NULL,
    `units`            DECIMAL(10,4) NOT NULL DEFAULT 0,
    `issue_price`      DECIMAL(12,4) NOT NULL COMMENT 'Price per gram at issue',
    `issue_date`       DATE NOT NULL,
    `maturity_date`    DATE NOT NULL COMMENT '8 years from issue',
    `current_gold_price` DECIMAL(12,4) DEFAULT NULL,
    `face_value`       DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'units * issue_price',
    `current_value`    DECIMAL(14,4) DEFAULT NULL,
    `interest_rate`    DECIMAL(5,4)  NOT NULL DEFAULT 0.025 COMMENT '2.5% p.a. on face value',
    `next_interest_date` DATE DEFAULT NULL,
    `isin`             VARCHAR(20) DEFAULT NULL,
    `tax_exemption`    TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'LTCG exempt if held to maturity',
    `notes`            TEXT DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sgb_portfolio` (`portfolio_id`),
    CONSTRAINT `fk_sgb_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgb_interest_log` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sgb_id`       INT UNSIGNED NOT NULL,
    `portfolio_id` INT UNSIGNED NOT NULL,
    `period`       VARCHAR(20) NOT NULL COMMENT 'HY1/HY2 YYYY',
    `amount`       DECIMAL(12,4) NOT NULL,
    `paid_on`      DATE NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sgb_interest_sgb` (`sgb_id`),
    CONSTRAINT `fk_sgb_interest_sgb` FOREIGN KEY (`sgb_id`) REFERENCES `sgb_holdings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
