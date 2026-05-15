-- WealthDash Migration t434: Corporate Actions — Bonus, Split, Dividend

CREATE TABLE IF NOT EXISTS `corporate_actions` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`        INT UNSIGNED NOT NULL,
    `stock_id`            INT UNSIGNED NOT NULL,
    `type`                ENUM('bonus','split','dividend','rights','buyback','merger','demerger') NOT NULL,
    `ex_date`             DATE         NOT NULL,
    `record_date`         DATE         DEFAULT NULL,
    `bonus_ratio`         VARCHAR(20)  DEFAULT NULL   COMMENT 'e.g. 1:1, 1:2',
    `split_old`           SMALLINT UNSIGNED DEFAULT NULL COMMENT 'old face value',
    `split_new`           SMALLINT UNSIGNED DEFAULT NULL COMMENT 'new face value',
    `dividend_per_share`  DECIMAL(10,4) DEFAULT NULL,
    `rights_ratio`        VARCHAR(20)  DEFAULT NULL,
    `rights_price`        DECIMAL(12,4) DEFAULT NULL,
    `is_applied`          TINYINT(1)   NOT NULL DEFAULT 0,
    `applied_at`          DATETIME     DEFAULT NULL,
    `notes`               TEXT         DEFAULT NULL,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ca_portfolio` (`portfolio_id`),
    INDEX `idx_ca_stock`     (`stock_id`),
    INDEX `idx_ca_date`      (`ex_date`),
    INDEX `idx_ca_pending`   (`is_applied`, `ex_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Also ensure stock_transactions table has a 'type' column for bonus/split
ALTER TABLE `stock_transactions`
    MODIFY COLUMN IF EXISTS `type`
        ENUM('buy','sell','bonus','split','rights','transfer_in','transfer_out')
        NOT NULL DEFAULT 'buy';

SELECT 't434_migration: Corporate actions table ready' AS status;
