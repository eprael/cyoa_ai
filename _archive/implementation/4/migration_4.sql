-- Migration 4
-- Run each phase block in order.

-- ============================================================
-- Phase 24: Soft Delete & Trash System
-- ============================================================
ALTER TABLE cyoa_ai_stories
    MODIFY COLUMN status ENUM('published','draft','deleted') NOT NULL DEFAULT 'draft',
    ADD COLUMN date_deleted DATETIME NULL DEFAULT NULL AFTER status;

-- ============================================================
-- Phase 25: Gallery, Genre & Summary Page Improvements
-- ============================================================
ALTER TABLE cyoa_ai_stories
    ADD COLUMN genre VARCHAR(50) NULL DEFAULT NULL AFTER description;

-- ============================================================
-- Phase 28: DB-Driven Settings — multi-genre + per-story AI image settings
-- ============================================================
-- Step 1: convert existing single-genre strings to JSON arrays
UPDATE cyoa_ai_stories
    SET genre = CONCAT('["', genre, '"]')
    WHERE genre IS NOT NULL AND genre <> '' AND genre NOT LIKE '[%';

-- Step 2: widen the column to TEXT so it can hold JSON arrays
ALTER TABLE cyoa_ai_stories
    MODIFY COLUMN genre TEXT NULL DEFAULT NULL;

-- Step 3: add per-story AI image settings columns
ALTER TABLE cyoa_ai_stories
    ADD COLUMN ai_image_category VARCHAR(50)  NULL DEFAULT NULL AFTER genre,
    ADD COLUMN ai_image_style    VARCHAR(100) NULL DEFAULT NULL AFTER ai_image_category,
    ADD COLUMN ai_image_mood     VARCHAR(100) NULL DEFAULT NULL AFTER ai_image_style,
    ADD COLUMN ai_image_quality  VARCHAR(10)  NULL DEFAULT NULL AFTER ai_image_mood;

-- Step 4: seed content settings (image styles, moods, genres, output format)
INSERT INTO cyoa_ai_settings (setting_key, setting_value) VALUES
  ('image_styles', '{"Photographic":["Photo-realistic","Cinematic / Film still","Portrait / Studio lighting","Golden hour / Natural light","Black & white / Noir","Polaroid / Vintage film","Aerial / Drone shot"],"Illustration":["Anime / Manga","Cartoon / Saturday morning cartoon","Comic book / Marvel-DC style","Caricature","Children''s book illustration","Flat design / Vector","Sticker art","Pixel art / 8-bit / 16-bit"],"Drawing & Painting":["Sketch / Pencil drawing","Line drawing / Ink","Charcoal","Watercolor","Oil painting","Acrylic","Gouache","Impressionist","Pointillism","Expressionist"],"Art Movement / Era":["Art Deco","Pop Art (Warhol-style)","Surrealist","Renaissance / Baroque","Ukiyo-e (Japanese woodblock)","Victorian / Edwardian"],"Digital & Concept":["Concept art / Game art","Sci-fi / Futuristic","Fantasy illustration","Dark fantasy / Gothic","Cyberpunk","Steampunk","Low poly / 3D render","Vaporwave / Synthwave"],"Craft & Texture":["Stained glass","Mosaic / Tile art","Graffiti / Street art","Linocut / Woodcut print","Embroidery / Needlework","Claymation / Stop-motion look","LEGO / Toy style"]}'),
  ('image_moods', '["Dramatic lighting / Chiaroscuro","Neon / Glowing","Soft pastel","High contrast / Monochrome","Ethereal / Dreamy","Gritty / Textured"]'),
  ('story_genres', '["Adventure","Fantasy","Sci-Fi","Mystery","Horror","Romance","Comedy","Historical","Educational","Other"]'),
  ('openai_image_format', 'jpeg')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================
-- Phase 29: AI Guardrails
-- ============================================================
-- Admin-configurable content guardrails injected into AI generation.
-- guardrails_text holds one restricted topic per line (\n converted to a
-- real newline by MariaDB; the textarea editor splits on newlines).
INSERT INTO cyoa_ai_settings (setting_key, setting_value) VALUES
  ('guardrails_enabled', '1'),
  ('guardrails_text',
   'Child Abuse\nSuicide\nExplicit sexual content or nudity\nExtreme graphic violence or gore\nDeeply nihilistic or hopeless themes\nDrug/alcohol use')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================
-- Phase 30: Admin Maintenance Section
-- ============================================================
-- Retention windows for the trash purge and log cleanup performed by
-- cron/maintenance.php. Accepted values: '1day', '1week', '1month'.
INSERT INTO cyoa_ai_settings (setting_key, setting_value) VALUES
  ('trash_retention', '1week'),
  ('log_retention',   '1month')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
