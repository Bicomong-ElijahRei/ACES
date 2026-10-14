-- ============================================================
-- Migration 003 — Make attendance.recorded_by nullable
-- Reason: Student self-registration creates attendance rows
--         before any staff member records attendance.
-- ============================================================

ALTER TABLE attendance 
MODIFY COLUMN recorded_by INT NULL DEFAULT NULL;