-- ============================================================
-- ACES System — Migration 001
-- Adds password reset support to the users table.
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `reset_token` VARCHAR(64) NULL AFTER `verification_token`,
    ADD COLUMN IF NOT EXISTS `reset_expires` DATETIME NULL AFTER `reset_token`;
    
-- Add verification expiry
ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_expires DATETIME NULL AFTER verification_token;
