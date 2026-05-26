-- ============================================================
-- Migration: Add password_resets table for auth system
-- Run this in phpMyAdmin → SQL tab on trading_journal DB
-- ============================================================

USE trading_journal;

CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(150) NOT NULL,
    token      VARCHAR(64)  NOT NULL UNIQUE,
    expires_at DATETIME     NOT NULL,
    used       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pr_email (email),
    INDEX idx_pr_token (token)
);

SELECT 'Migration complete: password_resets table created.' as result;
