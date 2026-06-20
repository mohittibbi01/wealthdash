-- WealthDash — th001: Daily Financial Journal Migration

CREATE TABLE IF NOT EXISTS journal_entries (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED  NOT NULL,
  entry_date      DATE          NOT NULL,
  title           VARCHAR(150)  NOT NULL DEFAULT 'Journal Entry',
  content         TEXT          NOT NULL,
  mood            ENUM('confident','optimistic','neutral','anxious','fearful','excited','regretful') NOT NULL DEFAULT 'neutral',
  tags            VARCHAR(255)  NULL COMMENT 'comma-separated',
  related_action  VARCHAR(40)   NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_date (user_id, entry_date),
  FULLTEXT KEY ft_content (title, content),
  CONSTRAINT fk_je_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
