-- WealthDash — t330: AI Chatbot Migration
CREATE TABLE IF NOT EXISTS ai_chat_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED    NOT NULL,
  role        ENUM('user','assistant') NOT NULL,
  message     TEXT            NOT NULL,
  context_id  VARCHAR(40)     NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_ctx     (user_id, context_id),
  KEY idx_user_created (user_id, created_at),
  CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
