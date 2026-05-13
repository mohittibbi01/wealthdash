-- ============================================================
-- WealthDash — Migration t381: AI Chat Assistant
-- Task: Portfolio Q&A conversational AI
-- Run ONCE — idempotent
-- ============================================================

-- 1. Chat sessions
CREATE TABLE IF NOT EXISTS `ai_chat_sessions` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED DEFAULT NULL,
  `session_key`  char(36)         NOT NULL COMMENT 'UUID',
  `title`        varchar(200)     DEFAULT NULL COMMENT 'Auto-generated from first message',
  `context_snap` mediumtext       DEFAULT NULL COMMENT 'Portfolio snapshot JSON at session start',
  `message_count` int UNSIGNED    NOT NULL DEFAULT 0,
  `last_activity` datetime        NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at`   datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_key` (`session_key`),
  KEY `idx_acs_user`      (`user_id`),
  KEY `idx_acs_portfolio` (`portfolio_id`),
  KEY `idx_acs_activity`  (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Individual messages in each session
CREATE TABLE IF NOT EXISTS `ai_chat_messages` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`   int(10) UNSIGNED NOT NULL,
  `role`         enum('user','assistant') NOT NULL,
  `content`      mediumtext       NOT NULL,
  `tokens_used`  int UNSIGNED     DEFAULT NULL,
  `created_at`   datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_acm_session` (`session_id`),
  KEY `idx_acm_created` (`created_at`),
  CONSTRAINT `fk_acm_session` FOREIGN KEY (`session_id`) REFERENCES `ai_chat_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Rate limiting + usage tracking per user per day
CREATE TABLE IF NOT EXISTS `ai_usage_log` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      int(10) UNSIGNED NOT NULL,
  `feature`      enum('chat','review','optimizer') NOT NULL DEFAULT 'chat',
  `usage_date`   date             NOT NULL,
  `request_count` smallint UNSIGNED NOT NULL DEFAULT 0,
  `tokens_total` int UNSIGNED     NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usage_user_feature_date` (`user_id`, `feature`, `usage_date`),
  KEY `idx_aul_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't381 AI Chat Assistant migration complete ✅' AS status;
