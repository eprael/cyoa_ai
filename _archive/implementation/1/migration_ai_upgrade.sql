-- =============================================================================
-- CYOA Maker — AI Upgrade Migration
-- Run once on both local dev (XAMPP) and production (evan.today)
-- Safe to inspect before running — nothing executes automatically.
-- =============================================================================
-- Execution order:
--   1. Rename storypoints → scenes
--   2. ALTER users  (add API key columns)
--   3. ALTER stories (add status + published_story_id columns)
--   4. SET all existing stories to published
--   5. CREATE ai_jobs
--   6. CREATE ratings
--   7. CREATE favorites
--   8. CREATE comments
--   9. CREATE views
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. Rename storypoints → scenes
-- -----------------------------------------------------------------------------

RENAME TABLE cyoa_ai_storypoints TO cyoa_ai_scenes;

ALTER TABLE cyoa_ai_scenes
  CHANGE storypointID sceneID INT NOT NULL AUTO_INCREMENT;

-- The choices table references storypointID — rename that FK column too.
ALTER TABLE cyoa_ai_choices
  CHANGE storypointID sceneID INT NOT NULL;


-- -----------------------------------------------------------------------------
-- 2. ALTER users — add Bring Your Own Key columns
-- -----------------------------------------------------------------------------

ALTER TABLE cyoa_ai_users
  ADD COLUMN claude_api_key VARCHAR(255) DEFAULT NULL AFTER email,
  ADD COLUMN openai_api_key VARCHAR(255) DEFAULT NULL AFTER claude_api_key;


-- -----------------------------------------------------------------------------
-- 3. ALTER stories — add status + shadow-draft columns
-- -----------------------------------------------------------------------------

ALTER TABLE cyoa_ai_stories
  ADD COLUMN status ENUM('draft', 'published') NOT NULL DEFAULT 'draft'
    AFTER date_created,
  ADD COLUMN published_story_id INT DEFAULT NULL
    AFTER status;

ALTER TABLE cyoa_ai_stories
  ADD INDEX idx_published_story_id (published_story_id),
  ADD FOREIGN KEY (published_story_id)
    REFERENCES cyoa_ai_stories(storyID) ON DELETE CASCADE;


-- -----------------------------------------------------------------------------
-- 4. Set all existing stories to published
-- -----------------------------------------------------------------------------

UPDATE cyoa_ai_stories SET status = 'published';


-- -----------------------------------------------------------------------------
-- 5. CREATE ai_jobs
-- -----------------------------------------------------------------------------

CREATE TABLE cyoa_ai_jobs (
    job_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    story_id      INT DEFAULT NULL,
    scene_id      INT DEFAULT NULL,
    job_type      ENUM('image', 'scene', 'full_story') NOT NULL,
    status        ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    input_json    JSON NOT NULL,
    result_json   JSON DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at    DATETIME DEFAULT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    seen_at       DATETIME DEFAULT NULL,

    INDEX idx_status_created (status, created_at),
    INDEX idx_user_id (user_id),
    INDEX idx_story_id (story_id),

    FOREIGN KEY (user_id)    REFERENCES cyoa_ai_users(userID)    ON DELETE CASCADE,
    FOREIGN KEY (story_id)   REFERENCES cyoa_ai_stories(storyID) ON DELETE SET NULL,
    FOREIGN KEY (scene_id)   REFERENCES cyoa_ai_scenes(sceneID)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 6. CREATE ratings
-- -----------------------------------------------------------------------------

CREATE TABLE cyoa_ai_ratings (
    rating_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    story_id   INT NOT NULL,
    rating     TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_story (user_id, story_id),

    FOREIGN KEY (user_id)  REFERENCES cyoa_ai_users(userID)    ON DELETE CASCADE,
    FOREIGN KEY (story_id) REFERENCES cyoa_ai_stories(storyID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 7. CREATE favorites
-- -----------------------------------------------------------------------------

CREATE TABLE cyoa_ai_favorites (
    favorite_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    story_id    INT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_story (user_id, story_id),

    FOREIGN KEY (user_id)  REFERENCES cyoa_ai_users(userID)    ON DELETE CASCADE,
    FOREIGN KEY (story_id) REFERENCES cyoa_ai_stories(storyID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 8. CREATE comments
-- -----------------------------------------------------------------------------

CREATE TABLE cyoa_ai_comments (
    comment_id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    story_id            INT NOT NULL,
    comment             TEXT NOT NULL,
    reply_to_comment_id INT DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_story_id (story_id),
    INDEX idx_reply_to (reply_to_comment_id),

    FOREIGN KEY (user_id)             REFERENCES cyoa_ai_users(userID)       ON DELETE CASCADE,
    FOREIGN KEY (story_id)            REFERENCES cyoa_ai_stories(storyID)    ON DELETE CASCADE,
    FOREIGN KEY (reply_to_comment_id) REFERENCES cyoa_ai_comments(comment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 9. CREATE views
-- -----------------------------------------------------------------------------

CREATE TABLE cyoa_ai_views (
    view_id   INT AUTO_INCREMENT PRIMARY KEY,
    user_id   INT DEFAULT NULL,
    story_id  INT NOT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_story_id (story_id),

    FOREIGN KEY (user_id)  REFERENCES cyoa_ai_users(userID)    ON DELETE SET NULL,
    FOREIGN KEY (story_id) REFERENCES cyoa_ai_stories(storyID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- Phase 13 — Widen scenes.description to TEXT for Quill HTML content
-- -----------------------------------------------------------------------------

ALTER TABLE cyoa_ai_scenes
  MODIFY COLUMN description TEXT NOT NULL;


-- -----------------------------------------------------------------------------
-- Phase 15 — Parent job tracking for notification grouping
-- -----------------------------------------------------------------------------

ALTER TABLE cyoa_ai_jobs
  ADD COLUMN parent_job_id INT(11) DEFAULT NULL AFTER seen_at,
  ADD INDEX idx_parent_job_id (parent_job_id);

ALTER TABLE cyoa_ai_jobs
  MODIFY COLUMN status ENUM('pending','running','completed','failed','cancelled','completed_with_errors') NOT NULL DEFAULT 'pending';

ALTER TABLE cyoa_ai_jobs
  ADD CONSTRAINT fk_cyoa_ai_jobs_parent
  FOREIGN KEY (parent_job_id) REFERENCES cyoa_ai_jobs(job_id) ON DELETE SET NULL;
