-- WealthDash — t417: Migration Log Table
-- Tracks which SQL migration files have been executed

CREATE TABLE IF NOT EXISTS `migration_log` (
  `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `filename`     VARCHAR(255)    NOT NULL COMMENT 'SQL file name (relative path)',
  `checksum`     VARCHAR(64)     NOT NULL COMMENT 'SHA-256 of file contents at run time',
  `batch`        SMALLINT        NOT NULL DEFAULT 1 COMMENT 'Batch number — increments per run',
  `executed_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `duration_ms`  INT             NULL     COMMENT 'Execution time in milliseconds',
  `notes`        TEXT            NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
