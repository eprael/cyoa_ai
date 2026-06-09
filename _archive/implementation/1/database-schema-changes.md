# Database Schema — New & Modified Tables

All tables use the `cyoa_ai_` prefix (via `DB_PREFIX` constant).

---

## Renamed Table: `storypoints` → `scenes`

The existing `cyoa_ai_storypoints` table and its primary key column are renamed to match the new "scene" terminology used throughout the UI and code.

```sql
-- Rename the table
RENAME TABLE cyoa_ai_storypoints TO cyoa_ai_scenes;

-- Rename the primary key column
ALTER TABLE cyoa_ai_scenes
  CHANGE storypointID sceneID INT NOT NULL AUTO_INCREMENT;
```

> **Note:** Any foreign key columns in other tables named `storypointID` or `storypoint_id` must also be renamed. Run this after the table rename above:
> ```sql
> -- In ai_jobs (created as part of this upgrade — use the new name from the start)
> -- No ALTER needed for ai_jobs since it is a new table; just use scene_id in the CREATE TABLE.
> ```
> Existing FKs in `choices` (if any reference scenes) should be checked before running.

---

## Modified Table: `users`

Add two nullable columns for user-supplied API keys (Bring Your Own Keys):

```sql
ALTER TABLE cyoa_ai_users
  ADD COLUMN claude_api_key VARCHAR(255) DEFAULT NULL AFTER email,
  ADD COLUMN openai_api_key VARCHAR(255) DEFAULT NULL AFTER claude_api_key;
```

- `claude_api_key` — user's personal Anthropic API key; overrides the site-wide `ANTHROPIC_API_KEY` constant when set
- `openai_api_key` — user's personal OpenAI API key; overrides the site-wide `OPENAI_API_KEY` constant when set

Both columns are optional — users who do not supply their own keys fall back to the site-wide keys.

---

## Modified Table: `stories`

Add two columns:

```sql
ALTER TABLE cyoa_ai_stories
  ADD COLUMN status ENUM('draft', 'published') NOT NULL DEFAULT 'draft'
  AFTER date_created,
  ADD COLUMN published_story_id INT DEFAULT NULL
  AFTER status;

ALTER TABLE cyoa_ai_stories
  ADD INDEX idx_published_story_id (published_story_id),
  ADD FOREIGN KEY (published_story_id) REFERENCES cyoa_ai_stories(storyID) ON DELETE CASCADE;
```

- `status` — whether the story is publicly visible (`published`) or a work-in-progress (`draft`)
- `published_story_id` — if this row is a shadow draft (an editing copy of a published story), this points to the original story's `storyID`. `NULL` means it is either a standalone draft or an already-published story.

The `ON DELETE CASCADE` ensures that if the original published story is deleted, its shadow draft is automatically removed too.

> **Migration note:** Set all existing stories to `published` so they remain visible after the upgrade:
> ```sql
> UPDATE cyoa_ai_stories SET status = 'published';
> ```

---

## New Table: `ai_jobs`

The core job queue table for all AI generation requests.

```sql
CREATE TABLE cyoa_ai_jobs (
    job_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    story_id      INT DEFAULT NULL,
    scene_id INT DEFAULT NULL,
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

    FOREIGN KEY (user_id)       REFERENCES cyoa_ai_users(userID) ON DELETE CASCADE,
    FOREIGN KEY (story_id)      REFERENCES cyoa_ai_stories(storyID) ON DELETE SET NULL,
    FOREIGN KEY (scene_id) REFERENCES cyoa_ai_scenes(sceneID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

- `seen_at` — set to `NOW()` when the user opens `job_queue.php` and the job's status is `completed` or `failed`. `NULL` means the user has not yet seen this result. Used to drive the notification badge count (`COUNT WHERE seen_at IS NULL AND status IN ('completed','failed')`).

### `input_json` structure by job type

**Image (`job_type = 'image'`):**
```json
{
    "prompt": "A dark medieval castle under a stormy sky",
    "theme": "forest",
    "style": "illustration"
}
```

**Scene (`job_type = 'scene'`):**

`previous_scenes` is built server-side by walking backward from the target scene to the opening scene (see ai-prompts.md for details).

```json
{
    "story_title": "The Lost Temple",
    "story_theme": "egyptian",
    "story_description": "An archaeologist discovers...",
    "previous_scenes": [
        {"title": "The Discovery", "description": "You find a map...", "choice_taken": "Follow the map to the temple"}
    ],
    "direction": "The player enters the temple's inner chamber",
    "mode": "continue",
    "tone": "suspenseful",
    "num_choices": 3,
    "generate_image": true
}
```

**Full Story (`job_type = 'full_story'`):**
```json
{
    "premise": "A mystery set in medieval Japan",
    "genre": "mystery",
    "tone": "dark",
    "target_scenes": 12,
    "num_endings": 3,
    "include_images": true
}
```

### `result_json` structure by job type

**Image:**
```json
{
    "filename": "ai_1710512345_a1b2c3d4.png",
    "prompt_used": "A dark medieval castle under a stormy sky, digital illustration style"
}
```

**Scene:**

The result contains the scene content plus choice texts. On apply, the target scene (from `scene_id` on the job) is updated with `title`/`description`/`hint`, and for each choice a stub scene is created and linked via a new choice row.

```json
{
    "title": "The Inner Chamber",
    "description": "The air grows thick with the scent of ancient incense...",
    "hint": "Look carefully at the hieroglyphs on the wall.",
    "image_prompt": "A vast ancient Egyptian chamber lit by flickering torches, golden sarcophagus in the center, hieroglyphs covering the walls",
    "choices": [
        {"text": "Examine the golden sarcophagus"},
        {"text": "Read the wall inscriptions"},
        {"text": "Turn back to the corridor"}
    ]
}
```

**Full Story:**
```json
{
    "story": {
        "title": "Shadows of Kyoto",
        "description": "A ronin investigates a series of mysterious disappearances...",
        "theme": "forest"
    },
    "scenes": [
        {
            "temp_id": "sp_1",
            "title": "The Assignment",
            "description": "A messenger arrives at dawn...",
            "hint": "Pay attention to the messenger's emblem.",
            "is_ending": false,
            "choices": [
                {"text": "Accept the mission", "dest_temp_id": "sp_2"},
                {"text": "Decline and investigate on your own", "dest_temp_id": "sp_3"}
            ]
        }
    ]
}
```

---

## New Table: `ratings`

```sql
CREATE TABLE cyoa_ai_ratings (
    rating_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    story_id   INT NOT NULL,
    rating     TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_story (user_id, story_id),

    FOREIGN KEY (user_id)  REFERENCES cyoa_ai_users(userID) ON DELETE CASCADE,
    FOREIGN KEY (story_id) REFERENCES cyoa_ai_stories(storyID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## New Table: `favorites`

```sql
CREATE TABLE cyoa_ai_favorites (
    favorite_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    story_id    INT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_story (user_id, story_id),

    FOREIGN KEY (user_id)  REFERENCES cyoa_ai_users(userID) ON DELETE CASCADE,
    FOREIGN KEY (story_id) REFERENCES cyoa_ai_stories(storyID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## New Table: `comments`

```sql
CREATE TABLE cyoa_ai_comments (
    comment_id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    story_id            INT NOT NULL,
    comment             TEXT NOT NULL,
    reply_to_comment_id INT DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_story_id (story_id),
    INDEX idx_reply_to (reply_to_comment_id),

    FOREIGN KEY (user_id)             REFERENCES cyoa_ai_users(userID) ON DELETE CASCADE,
    FOREIGN KEY (story_id)            REFERENCES cyoa_ai_stories(storyID) ON DELETE CASCADE,
    FOREIGN KEY (reply_to_comment_id) REFERENCES cyoa_ai_comments(comment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## New Table: `views`

```sql
CREATE TABLE cyoa_ai_views (
    view_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT DEFAULT NULL,
    story_id   INT NOT NULL,
    viewed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_story_id (story_id),

    FOREIGN KEY (user_id)  REFERENCES cyoa_ai_users(userID) ON DELETE SET NULL,
    FOREIGN KEY (story_id) REFERENCES cyoa_ai_stories(storyID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## Combined Migration Script

For convenience, a single SQL file can be created at `_installation/migration_ai_upgrade.sql` containing all of the above statements in order. Run it once on both the local dev database and the production server.

### Recommended execution order:
1. RENAME `storypoints` → `scenes`, rename `storypointID` → `sceneID`
2. ALTER `users` table (add `claude_api_key`, `openai_api_key` columns)
3. ALTER `stories` table (add `status` and `published_story_id` columns)
4. UPDATE existing stories to `published`
5. CREATE `ai_jobs`
6. CREATE `ratings`
7. CREATE `favorites`
8. CREATE `comments`
9. CREATE `views`

---

## db_functions.php Additions (Summary)

New function groups to add:

| Section | Functions |
|---------|-----------|
| **AI Jobs** | `create_ai_job()`, `get_ai_job()`, `get_pending_job()`, `update_job_status()`, `get_jobs_by_user()`, `get_all_jobs()`, `cancel_job()`, `count_pending_jobs_by_user()`, `get_unseen_job_count()`, `mark_jobs_seen()` |
| **Ratings** | `upsert_rating()`, `get_user_rating()`, `get_average_rating()` |
| **Favourites** | `toggle_favorite()`, `is_favorited()`, `get_user_favorites()`, `get_favorite_count()` |
| **Comments** | `create_comment()`, `get_comments_by_story()`, `delete_comment()` |
| **Views** | `record_view()`, `get_view_count()` |
| **Story Status / Shadow Drafts** | `publish_story()`, `set_story_draft()`, `get_edit_draft()`, `create_edit_draft()`, `publish_draft()`, `discard_draft()`, `is_edit_draft()`, `get_story_image_dir()` |
