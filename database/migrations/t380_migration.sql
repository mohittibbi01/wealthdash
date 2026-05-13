-- ============================================================
-- WealthDash — Migration t380: AI Portfolio Review
-- Task: Monthly AI-generated portfolio analysis
-- Run ONCE — idempotent
-- ============================================================

-- 1. Stores generated AI review reports
CREATE TABLE IF NOT EXISTS `ai_portfolio_reviews` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         int(10) UNSIGNED NOT NULL,
  `portfolio_id`    int(10) UNSIGNED NOT NULL,
  `review_month`    char(7)          NOT NULL COMMENT 'YYYY-MM',
  `review_type`     enum('monthly','quarterly','annual','on_demand') NOT NULL DEFAULT 'monthly',
  `status`          enum('pending','generating','done','error') NOT NULL DEFAULT 'pending',
  `prompt_snapshot` mediumtext       DEFAULT NULL COMMENT 'Portfolio data sent to AI (JSON)',
  `ai_response`     mediumtext       DEFAULT NULL COMMENT 'Raw AI text',
  `parsed_sections` mediumtext       DEFAULT NULL COMMENT 'JSON: {summary, strengths, concerns, actions, score}',
  `portfolio_score` tinyint UNSIGNED DEFAULT NULL COMMENT '0-100 health score',
  `tokens_used`     int UNSIGNED     DEFAULT NULL,
  `model_used`      varchar(50)      DEFAULT NULL,
  `error_message`   text             DEFAULT NULL,
  `generated_at`    datetime         DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_review_month` (`portfolio_id`, `review_month`, `review_type`),
  KEY `idx_apr_user`      (`user_id`),
  KEY `idx_apr_portfolio` (`portfolio_id`),
  KEY `idx_apr_month`     (`review_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. User preferences for AI review
CREATE TABLE IF NOT EXISTS `ai_review_prefs` (
  `id`                     int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`                int(10) UNSIGNED NOT NULL,
  `auto_generate_monthly`  tinyint(1) NOT NULL DEFAULT 0,
  `notify_on_ready`        tinyint(1) NOT NULL DEFAULT 1,
  `preferred_language`     varchar(10) NOT NULL DEFAULT 'hi-en' COMMENT 'hi-en=Hinglish, en=English',
  `risk_appetite`          enum('conservative','moderate','aggressive') NOT NULL DEFAULT 'moderate',
  `financial_goal`         varchar(255) DEFAULT NULL COMMENT 'Free text: what user wants to achieve',
  `updated_at`             datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_arp_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't380 AI Portfolio Review migration complete ✅' AS status;
