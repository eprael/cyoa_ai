# CYOA Maker — AI Upgrade

## Project Overview

A PHP web app where users build, share, and play branching choose-your-own-adventure stories.
Features social interaction (ratings, comments, favourites, views) and three tiers of
AI-powered content generation (images, single scenes, full stories).

## Tech Stack

- **Backend:** PHP 8.3 (procedural, no framework), MySQL/MariaDB via `mysqli`
- **Frontend:** Vanilla HTML/CSS/JS (no framework)
- **Email:** PHPMailer (SMTP via Gmail)
- **Hosting:** Shared hosting (evan.today) + local XAMPP for dev
- **AI Services:** Claude API (text generation), OpenAI API — `gpt-image-2` (image generation, model configurable)

## Architecture

- **Routing:** File-based — each page is a standalone `.php` file
- **Database layer:** All queries go through `db_functions.php` using prepared statements
- **Config:** `config.php` defines DB credentials, SMTP settings, `APP_URL`, `MAIN_ADMIN`,
  `APP_TIMEZONE`, `AI_IMAGE_PRICING` array, and Claude cost-rate constants.
  All runtime AI settings (API keys, model selection, quality, job limits, site title) live in
  the `cyoa_ai_settings` DB table and are accessed via `app_setting('key')` from `settings.php`.
- **Auth:** Session-based. Key session variables: `$_SESSION['userID']`, `$_SESSION['isAdmin']`,
  `$_SESSION['firstName']`, `$_SESSION['profileImage']`. Admin flag via `isAdmin` column on users.
- **Images:** Stored in `images/stories/{storyID}/` for story/scene images, `images/profiles/` for users
- **Cron:** PHP CLI scripts in `cron/`, scheduled via Virtualmin (Linux) or Task Scheduler (Windows)
- **Prompts:** AI prompt templates stored as `.txt` files in `prompts/`, loaded via `load_prompt()`

## Key Files

| File | Purpose |
|------|---------|
| `config.php` | DB credentials, SMTP, APP_URL, MAIN_ADMIN, APP_TIMEZONE, AI cost reference data |
| `settings.php` | Loads `cyoa_ai_settings` into `$SETTINGS`; provides `app_setting()` helper |
| `db_functions.php` | All CRUD operations (users, stories, scenes, choices, jobs, social, settings) |
| `header.php` | Shared navigation bar, job-queue badge polling (included in all pages) |
| `index.php` | Homepage / story gallery |
| `summary.php` | Story landing page — cover, stats, comments, play/edit buttons |
| `editor.php` | Story & scene editor (create, edit, delete, clone, publish) |
| `play.php` | Read-only story player |
| `account.php` | User's own settings (profile, BYOK API keys, quality override) |
| `settings_site.php` | Admin — site settings (models, limits, timeouts, gallery sizes) |
| `settings_content.php` | Admin — content settings (genres, image styles, moods, guardrails) |
| `settings_users.php` | Admin — user management |
| `trash.php` | Soft-deleted stories — restore / permanently delete / empty trash |
| `gallery.php` | Per-story image gallery (cover + scene images) with lightbox |
| `job_queue.php` | Job queue page — AI job list, retry/cancel, detail modal |
| `job_history.php` | Paginated historical job list |
| `job_render.php` | Shared job-row rendering helpers |
| `pagination.php` | Shared pager rendering |
| `theme.php` | Theme engine — presets, sanitization, theme_json (Phase 42) |
| `fonts.php` | Play-font allow-list accessors (Phase 41) |
| `data.php` | Cached loader for `data/*.json` content files |
| `api_jobs.php` | AJAX for job queue (unseen_count, status, list, detail; create, cancel, retry, apply) |
| `api_create_story_ai.php` | Accepts full-story AI creation form; creates draft + job, returns JSON |
| `api_social.php` | AJAX endpoints for ratings, favourites, comments |
| `api_tree.php` | JSON scene-graph data for the editor Tree View |
| `api_content.php` | Admin AJAX — immediate persistence for content-settings chip editors |
| `cron/ai_dispatcher.php` | Cron entry point — multi-pass claim, spawns workers, daily maintenance |
| `cron/ai_worker.php` | Single-job processor — receives job_id via CLI, routes to handler |
| `cron/ai_story_handler.php` | Multi-phase Claude handler for `story` jobs (+ premise-seed resolution) |
| `cron/ai_scene_handler.php` | Claude handler for single `scene` jobs |
| `cron/ai_image_handler.php` | OpenAI image handler for `image` jobs |
| `cron/ai_apply.php` | Apply completed AI results to the DB; auto-publish gate |
| `cron/ai_helpers.php` | Shared cron helpers (HTTP, logging, prompt loading) |
| `cron/maintenance.php` | Daily cleanup — purge expired trash + old logs |
| `cli/create_stories.php` | Batch story-creation jobs (sample input: `cli/sample_stories.json`) |
| `cli/export_story.php` / `cli/import_story.php` | Move a story (rows + scenes + choices + images) between instances |
| `cli/validate_play_fonts.php` | Verify every play-font family/weight resolves on Google Fonts |
| `data/*.json` | Data-driven content — `premises`, `audiences`, `themes`, `play_fonts` |

## Database Tables (prefix: `cyoa_ai_`)

**Core:** `users`, `stories`, `scenes`, `choices`, `password_resets`, `cron_test_runs`

**Social:** `ratings`, `views`, `comments`, `favorites`

**AI / System:** `jobs`, `settings`, `themes`

> Column naming: original tables use camelCase IDs (`storyID`, `userID`, `sceneID`);
> newer tables use snake_case (`user_id`, `story_id`, `comment_id`).
>
> Notable `stories` columns added since the base schema: `genre` (JSON array), `theme_json`
> (Phase 42 visual theme), `ai_image_category/style/mood/quality` (per-story image defaults),
> `published_story_id` (shadow drafts), `date_deleted` (soft delete). `stories.status` is
> `enum('published','draft','deleted')`.
>
> ⚠️ The `cyoa_ai_themes` table exists in the schema but is **currently unused** — the theme engine
> reads presets from `data/themes.json` (and the font allow-list from `data/play_fonts.json`), not
> from the DB. The table is a leftover from the Phase 42 design; treat it as dead schema unless wired
> up later.

## Conventions

- All new database functions go in `db_functions.php`, grouped by section with comment headers
- Use prepared statements for all queries — never interpolate user input into SQL
- Follow the existing pattern: `db_connect()` at start, `$conn->close()` at end of each function
- Table names always use `DB_PREFIX . "tablename"` (never hardcode the prefix)
- Use POST for mutations, GET for reads; redirect after POST (PRG pattern)
- Flash messages via `$_SESSION['flash_message']` and `$_SESSION['flash_type']`
- Image uploads validated against allowed extensions (`jpg`, `jpeg`, `png`, `gif`, `webp`)
- CSS files live in `styles/`; theme-specific CSS in `themes/`
- No JavaScript framework — keep it vanilla JS
- Keep PHP procedural (no classes/OOP) to match the existing codebase style
- Use `app_setting('key')` to read runtime config — never add new constants to `config.php`
- Editable content (genres, image styles, moods, premises, audiences, themes, fonts) is data-driven —
  it lives in the `cyoa_ai_settings` table or `data/*.json`, not hardcoded in PHP
- A story's **theme is visual-only** (colours + fonts via `theme.php` / `theme_json`); it is never
  sent to text or image generation — the semantic hint for generation is the **genre**
- Sort dropdown options for display where it aids scanning (genres A–Z with "Other" last, image
  styles within their category, etc.) — sort at render, not in the stored data

## AI Feature Notes

- AI jobs use a queue pattern: user submits → `cyoa_ai_jobs` row created → dispatcher (multi-pass per
  cron run) spawns a worker → handler calls the API, applies the result directly, marks the job done →
  browser polls `api_jobs.php?action=unseen_count` for badge updates
- Any AI-applied change sets the story's `status` to `draft`
- Three job types: `image` (OpenAI gpt-image-2), `scene` (Claude, single scene), `story` (Claude,
  multi-phase full story). Image children of a `story` job have `parent_job_id` set; top-level jobs
  have `parent_job_id IS NULL` (the per-user pending limit counts top-level only)
- "Any" genre/tone/audience and a blank premise are resolved to concrete random values **in code**
  before queueing (`ai_resolve_story_params()`, premise seeds from `data/premises.json`)
- AI-created stories **auto-publish only if** the publish flag is set, images were included, and every
  job (parent + all image children) succeeds; otherwise they stay drafts
- See `docs/architecture/architecture.md` for detailed technical design
- See `docs/architecture/ai-prompts.md` for prompt templates and construction logic

## Steering Documents

All documentation lives under `docs/`. The canonical reference set is in `docs/architecture/`:

- `architecture.md` — Technical architecture for AI features and social features
- `api-endpoints.md` — Internal API endpoints for job queue polling and AJAX actions
- `ai-prompts.md` — Prompt engineering for each AI generation level
- `cyoa_ai_db_schema.sql` — Full schema dump (exported from phpMyAdmin; may lag behind latest migrations)

> Frozen historical working docs (implementation plans, progress trackers, migrations, testing notes,
> scratch) live in the project-root **`_archive/`** folder (a sibling of `docs/`) and are **not
> maintained** — they reflect the state at their time of writing. Deliverables/reference live under
> `docs/proposal/`, `docs/presentation/`, `docs/report/`, and `docs/visualization/`. See
> `docs/README.md` for the full map. (Phases 1–43 cover AI integration, social features, UI polish,
> theme engine, and the gallery.)
>
> Note: `.claude/` now holds only Claude Code config (`settings.json`, `settings.local.json`), not docs.
