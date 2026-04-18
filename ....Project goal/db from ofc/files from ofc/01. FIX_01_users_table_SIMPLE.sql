-- ============================================================
-- WealthDash — FIX_01: users table fix (XAMPP compatible)
-- INFORMATION_SCHEMA use nahi karta — direct ALTER TABLE hai
--
-- ⚠️  IMPORTANT: Neeche ke 5 blocks EK EK KARKE run karo
--     Agar "Duplicate column name" error aaye → ignore karo
--     (matlab column already hai — koi problem nahi)
-- ============================================================


-- ══ BLOCK 1: mobile column ══════════════════════════════════
-- Pehle sirf ye paste karo aur Go karo:

ALTER TABLE `users`
  ADD COLUMN `mobile` VARCHAR(15) DEFAULT NULL AFTER `email`;


-- ══ BLOCK 2: mobile_verified column ═════════════════════════
-- Ab ye paste karo aur Go karo:

ALTER TABLE `users`
  ADD COLUMN `mobile_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `email_verified`;


-- ══ BLOCK 3: role ENUM fix ═══════════════════════════════════
-- 'member' add karo ENUM mein (register.php 'member' use karta hai):

ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('user','admin','member') NOT NULL DEFAULT 'user';


-- ══ BLOCK 4: last_login_at column ═══════════════════════════
-- register.php 'last_login_at' update karta hai lekin table mein
-- sirf 'last_login' tha:

ALTER TABLE `users`
  ADD COLUMN `last_login_at` DATETIME DEFAULT NULL AFTER `last_login`;


-- ══ BLOCK 5: login_count column ══════════════════════════════

ALTER TABLE `users`
  ADD COLUMN `login_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_login_at`;


-- ══ FINAL VERIFY ══════════════════════════════════════════════
-- Sab run hone ke baad ye paste karo — sab columns dikhne chahiye:

DESCRIBE `users`;
