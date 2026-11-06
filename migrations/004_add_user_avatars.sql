-- ============================================================
-- ACES System — Migration 004
-- Adds avatar column to users table for profile pictures.
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `avatar` VARCHAR(255) NULL DEFAULT NULL AFTER `email`;