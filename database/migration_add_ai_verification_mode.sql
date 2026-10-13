-- Migration: Add AI Verification Mode Setting
-- Date: 2026-10-13
-- Purpose: Add ai_verification_mode config to allow disabling or making AI verification advisory-only
-- This fixes the issue where AI was rejecting valid evidence files on the online server

-- Add the new config setting if it doesn't exist
INSERT INTO `system_config` (`config_key`, `config_value`, `updated_at`) 
VALUES ('ai_verification_mode', 'advisory', NOW())
ON DUPLICATE KEY UPDATE `config_key` = `config_key`;

-- Show current AI settings
SELECT config_key, config_value FROM system_config WHERE config_key IN ('gemini_api_key', 'ai_verification_mode');
