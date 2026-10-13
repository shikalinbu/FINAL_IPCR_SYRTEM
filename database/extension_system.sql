-- ============================================================
-- SUBMISSION EXTENSION SYSTEM
-- Allows admin to grant submission extensions to specific faculty
-- even after the period is closed
-- ============================================================

-- Create submission_extensions table
CREATE TABLE IF NOT EXISTS `submission_extensions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `extended_deadline` datetime NOT NULL,
  `reason` text DEFAULT NULL,
  `granted_by` int(11) NOT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_used` tinyint(1) DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `period_id` (`period_id`),
  KEY `user_id` (`user_id`),
  KEY `granted_by` (`granted_by`),
  CONSTRAINT `submission_extensions_ibfk_1` FOREIGN KEY (`period_id`) REFERENCES `rating_periods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `submission_extensions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `submission_extensions_ibfk_3` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index for quick lookups
CREATE INDEX idx_extension_lookup ON submission_extensions(period_id, user_id, is_used);

-- Add has_extension flag to ipcr_forms for easier tracking (optional)
ALTER TABLE `ipcr_forms` 
ADD COLUMN `has_extension` tinyint(1) DEFAULT 0 AFTER `is_late`,
ADD COLUMN `extension_used_at` datetime DEFAULT NULL AFTER `has_extension`;
