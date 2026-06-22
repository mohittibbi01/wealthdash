-- ============================================================
-- GA-55A SYSTEM — FILE 001
-- Database + Users table
-- Run: phpMyAdmin me jaao → SQL tab → yeh paste karo → Go
-- ============================================================

CREATE DATABASE IF NOT EXISTS ga55_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ga55_system;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100)        NOT NULL,
    username      VARCHAR(50)         NOT NULL UNIQUE,
    password_hash VARCHAR(255)        NOT NULL,
    role          ENUM('admin','user') NOT NULL DEFAULT 'user',
    is_active     TINYINT(1)          NOT NULL DEFAULT 1,
    created_at    TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin user
-- Password: Admin@123  (change this after first login)
INSERT INTO users (name, username, password_hash, role) VALUES
('Administrator', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
