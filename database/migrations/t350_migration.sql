-- WealthDash — t350: Font Size Preference Migration
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS font_size ENUM('small','medium','large','xlarge') NOT NULL DEFAULT 'medium' AFTER theme;
