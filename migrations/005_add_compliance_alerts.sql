-- Migration 005: compliance alert tracking table
-- Used by cron/compliance_alerts.php for spam-prevention cooldown.

CREATE TABLE IF NOT EXISTS `compliance_alerts` (
  `alert_id`     int(11) NOT NULL AUTO_INCREMENT,
  `student_id`   varchar(50) NOT NULL,
  `progress_pct` int(11) NOT NULL,
  `sent_at`      datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`alert_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_sent` (`sent_at`),
  CONSTRAINT `fk_compliance_alerts_student`
    FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
