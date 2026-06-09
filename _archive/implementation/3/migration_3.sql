-- =============================================================================
-- Migration 3 — Phases 17–21
-- Run these statements against the live database in order.
-- cyoa_ai_db_schema.sql is the read-only reference of the full schema.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Phase 17 — Admin Settings Table
-- ---------------------------------------------------------------------------

CREATE TABLE cyoa_ai_settings (
    setting_key   VARCHAR(64)  NOT NULL,
    setting_value TEXT         NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO cyoa_ai_settings (setting_key, setting_value) VALUES
    ('anthropic_api_key',        ''),
    ('openai_api_key',           ''),
    ('anthropic_model',          'claude-sonnet-4-6'),
    ('openai_image_model',       'gpt-image-2'),
    ('openai_image_quality',     'medium'),
    ('scene_thumb_size',         '200'),
    ('ai_enabled',               '1'),
    ('ai_job_timeout_seconds',   '600'),
    ('ai_max_pending_per_user',  '5'),
    ('app_title',                'Choose Your Own Adventure!');

-- ---------------------------------------------------------------------------
-- Phase 20 — AI Job Cost Tracking
-- ---------------------------------------------------------------------------

ALTER TABLE cyoa_ai_jobs
    ADD COLUMN input_tokens  INT            NULL AFTER parent_job_id,
    ADD COLUMN output_tokens INT            NULL AFTER input_tokens,
    ADD COLUMN image_count   INT            NULL AFTER output_tokens,
    ADD COLUMN cost_usd      DECIMAL(10,6)  NULL AFTER image_count;

-- ---------------------------------------------------------------------------
-- Add ai_max_concurrent_image_jobs setting
INSERT INTO cyoa_ai_settings (setting_key, setting_value)
VALUES ('ai_max_concurrent_image_jobs', '2')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Phase 18 fix — replace full_story with create_story in job_type ENUM
-- ---------------------------------------------------------------------------

ALTER TABLE cyoa_ai_jobs
    MODIFY COLUMN job_type ENUM('image','scene','story') NOT NULL;