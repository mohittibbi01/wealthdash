-- WealthDash — t60: AI Anomaly Detection Base Migration
-- NO NEW TABLES — functionality already fully covered by:
--   t246_migration.sql (anomaly_log table, basic detection)
--   t384_migration.sql (anomaly_log extended, dedup index)
-- This task is satisfied by existing t246 + t384 work. See
-- api/ai/anomaly_base_redirect.php for action-name alias note.
SELECT 1;
