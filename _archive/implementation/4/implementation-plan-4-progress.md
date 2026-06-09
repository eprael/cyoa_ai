# CYOA AI Upgrade — Session Progress

_Last updated: 2026-05-31_

---

## Phase 23 — Global Modal System ✅ Complete

Replaced all native `alert()` and `confirm()` JavaScript calls site-wide with a shared
reusable modal component.

### Completed tasks

- **23.1** — `styles/modal.css` created: `.modal-overlay`, `.modal-box`, `.modal-title`,
  `.modal-body`, `.modal-actions` with fade transition via opacity/visibility; uses existing
  CSS variables for automatic theme support.
- **23.2** — `modal.js` created: injects overlay markup once on `DOMContentLoaded`; exposes
  `Modal.alert()`, `Modal.confirm()`, `Modal.open()`, `Modal.close()`; supports Escape key,
  backdrop click, and Enter-for-primary-button.
- **23.3** — Sweep complete: all `alert()` / `confirm()` calls in `.php` and `.js` files
  replaced with `Modal.alert()` / `Modal.confirm()` equivalents; redirect-after-confirm
  patterns moved into `onConfirm` callbacks.
- **23.4** — `header.php` updated to include `styles/modal.css` (in `<head>`) and
  `modal.js` (before `</body>`) on every page.

### Files changed

| File | Change |
|---|---|
| `styles/modal.css` | **New** — modal component styles |
| `modal.js` | **New** — Modal API |
| `header.php` | Include modal.css and modal.js |
| All pages with `alert()`/`confirm()` | Replaced calls with Modal equivalents |

---

## Phase 24 — Soft Delete & Trash System ✅ Complete

Replaced hard-delete with a soft-delete pattern. Deleted stories move to a trash bin;
owners can restore, admins can restore or permanently purge.

### Completed tasks

- **24.1** — Schema migration: `cyoa_ai_stories.status` ENUM extended to include `'deleted'`;
  `date_deleted DATETIME NULL` column added. Migration recorded in `.claude/migration_4.sql`.
- **24.2** — Three new DB functions added to `db_functions.php` under `// === Story Soft Delete ===`:
  `db_soft_delete_story()`, `db_restore_story()`, `db_get_deleted_stories()`.
- **24.3** — Existing list queries updated to exclude deleted stories:
  `get_stories_by_user()` (`AND status != 'deleted'`),
  `get_favorites_by_user()` (`AND s.status != 'deleted'`).
  `get_all_stories()` and `search_stories()` already excluded deleted via explicit status checks.
  Point-lookup functions (`get_story()`, etc.) left unchanged so `trash.php` can access them.
- **24.4** — `editor.php` `delete_story` action replaced with `db_soft_delete_story()`; flash
  message updated to "Story moved to Trash."; admins can now also soft-delete any story.
- **24.5** — `trash.php` created: compact table with thumbnail, title, date deleted, Restore
  button (owner/admin) and Delete Forever button (admin only). Both actions confirmed via
  `Modal.confirm()`. Empty-trash message varies by role.
- **24.6** — `header.php`: bell icon replaced with a bulleted-list queue icon; "View Trash"
  link added to the user dropdown above the theme divider.

### Files changed

| File | Change |
|---|---|
| `.claude/migration_4.sql` | **New** — Phase 24 ALTER TABLE |
| `db_functions.php` | New soft-delete functions; updated gallery/favourites queries |
| `editor.php` | Replaced hard-delete with `db_soft_delete_story()` |
| `trash.php` | **New page** |
| `header.php` | Queue icon + "View Trash" dropdown item |

---

---

## Phase 25 — Gallery, Genre & Summary Page Improvements ✅ Complete

Added a `genre` column to stories, overhauled gallery cards and filtering, redesigned the
summary page social strip, and renamed "Favourites" to "Likes" in the UI.

### Completed tasks

- **25.1** — Schema: `ALTER TABLE cyoa_ai_stories ADD COLUMN genre VARCHAR(50) NULL AFTER description`.
  Recorded in `.claude/migration_4.sql`.
- **25.2** — DB functions updated:
  - `create_story()` / `update_story()` — accept new `$genre` parameter; included in INSERT/UPDATE.
  - `get_story()`, `get_stories_by_user()`, `get_favorites_by_user()`, `search_stories()` — SELECT now includes `genre`.
  - `get_all_stories()` — new `$genre` and `$sort` parameters; subquery sort columns (`view_count`,
    `comment_count`, `avg_rating`) for `latest`/`rating`/`views`/`comments` order modes.
  - `get_story_stats()` — added `comment_count` subquery; returns 5-key array.
  - `db_count_scenes()` — new helper; returns scene count for a story.
- **25.3** — `summary.php`: removed the owner/admin → editor redirect for draft stories.
  Deleted stories (status=`deleted`) now 404 for everyone. `record_view()` guarded to
  published stories only. Draft notice banner shown to owners/admins.
- **25.4** — `index.php` gallery cards:
  - Stats (views, rating, comment count, like count) shown on every card (drafts included).
  - Comment-bubble icon + count added.
  - Play button moved before Edit; both always visible to owners/admins.
  - Card thumbnail always links to `summary.php` (never `editor.php`).
  - Clone button removed from gallery cards.
- **25.5** — `index.php` filter bar: Genre dropdown (`All Genres` + 10 fixed values) and
  Sort dropdown (`Latest`, `Highest Rated`, `Most Viewed`, `Most Commented`) added between
  filter buttons and Create button; both submit as GET params and restore their value on page load.
- **25.6** — `summary.php`: "Clone Story" button added to action strip (visible to logged-in users);
  confirmed via `Modal.confirm()`; posts `clone_story` action to `editor.php`; redirects to
  new story's editor on success.
- **25.7** — Genre `<select>` added to story form in `editor.php` (new and edit modes). Genre
  chip displayed on `summary.php` meta line and on gallery cards.
- **25.8** — `summary.php` social strip redesigned:
  - Byline extended: "by X · N scenes · Added Mar 2, 2026".
  - Old scattered `.summary-meta` chips (views, rating, fav) and `.summary-rating-block` replaced
    with unified `.social-strip` (eye + count | heart button + count | star + avg/count |
    comment icon + count). Icons at 22 px; counts at `1.3rem font-weight:600`.
  - New CSS added to `styles/summary.css`.
- **25.9** — "Favourites" → "Likes" in all UI labels:
  - `index.php`: filter button `My Favourites` → `My Likes`; filter key `favourites` → `likes`;
    heart-button tooltip and `aria-label` updated.
  - `header.php`: dropdown link `My Favourites` → `My Likes`; filter param updated to `likes`.
  - `summary.php`: Like/Unlike labels throughout.
  - `editor.php`: clone handler updated to allow any logged-in user to clone published stories
    (not just the owner); redirects to the new story's editor on success.

### Files changed

| File | Change |
|---|---|
| `.claude/migration_4.sql` | Phase 25 ALTER TABLE |
| `db_functions.php` | genre in CRUD functions; genre+sort in get_all_stories; comment_count in stats; db_count_scenes helper |
| `summary.php` | Remove redirect; draft banner; new byline; social strip; Clone button; genre chip; Likes labels |
| `styles/summary.css` | Social strip + like-button CSS |
| `index.php` | Card overhaul; genre/sort filter bar; My Likes; remove Clone; stats on all cards |
| `styles/styles.css` | Filter-sort controls CSS |
| `styles/cards.css` | Genre chip CSS |
| `editor.php` | Genre field in story form; genre in save_story handler; clone handler updates |
| `header.php` | My Likes link + filter param |

---

## Phase 26 — Story Editor Tweaks ✅ Complete

Small targeted changes to `editor.php`.

### Completed tasks

- **26.1** — Story owner displayed in the editor header beneath "Story Editor": shows
  "Editing: &ldquo;[title]&rdquo; — Owner: [First Last]". Owner name fetched via
  `get_user_by_id($story['userID'])` after loading scenes; falls back to `created_by` if user
  not found.
- **26.2** — Back button in the story overview header changed from
  `Back to My Stories → index.php?filter=mine` to
  `Back to Landing Page → summary.php?storyID=X`.
- **26.3** — "Unpublish" button label renamed to "Set to Draft". Confirm message and POST
  action (`unpublish_story`) unchanged.
- **26.4** — Admin delete override: a "Delete Story" button is now rendered for admins when
  the story is in `shadow_draft` or `published` state (the `standalone_draft` state already
  showed delete to all users). The POST handler already accepted admin deletes; this only
  adds the missing UI.

### Files changed

| File | Change |
|---|---|
| `editor.php` | Owner fetch + display in header; back button to summary; "Set to Draft" label; admin delete button for published/shadow-draft |

---

## Phase 27 — API Rate-Limit Retry ✅ Complete

Centralised retry-with-backoff for all AI API calls into one shared helper, replacing the
image handler's bespoke loop and adding retry to the previously fail-fast Claude calls.

### Completed tasks

- **27.1** — `cron/ai_helpers.php` created with `api_call_with_retry()`. Per the plan's note,
  it takes a `callable $buildHandle` (returns a fresh cURL handle each attempt) rather than a
  pre-built handle, since `curl_reset()` would wipe the options. Defaults: 5 retries, 20 s
  delay. Only HTTP 429 is retried; success, cURL transport errors, and all other HTTP codes
  return immediately. Returns `['body','http_code','curl_errno','curl_error']`. A companion
  `api_retry_log()` writes each retry to `cron/logs/api_retry_YYYYMMDD.log` and mirrors it to
  the per-job `worker_log()` when available.
- **27.2** — `cron/ai_image_handler.php`: replaced the bespoke 3-attempt / 15–30 s loop with
  `api_call_with_retry()`. Existing curl-error and non-200 handling retained; the 429 message
  now reads "OpenAI rate limit exceeded after 5 retries".
- **27.3** — `cron/ai_scene_handler.php`: `claude_api_call()` now builds its handle via a
  closure and runs through `api_call_with_retry()`. 429 message updated to note the retries.
- **27.4** — `cron/ai_story_handler.php`: no direct change needed — every Claude call (plan
  phase + each scene write) routes through the shared `claude_api_call()`, so each phase now
  retries independently and automatically.
- **27.5** — `cron/ai_helpers.php` ensures its log directory exists (defensive `mkdir`).
  `cron/logs/` already exists and holds all other logs.
- `cron/ai_worker.php`: requires `ai_helpers.php` before the handler files.

### Deviation from plan (flagged for Phases 29 & 30)

The plan's sample code wrote retry logs to the project-root `logs/` directory
(`__DIR__ . '/../logs/'`). In the real codebase **all** logs live in **`cron/logs/`**
(dispatcher.log, job_*.log, and `worker_log()` output); the root `logs/` directory does not
exist. The retry log was therefore placed in `cron/logs/` to sit alongside the per-job logs.
**Phases 29 (guardrail log) and 30 (maintenance log cleanup) make the same root-`logs/`
assumption and should be reconciled to `cron/logs/` when implemented.**

### Files changed

| File | Change |
|---|---|
| `cron/ai_helpers.php` | **New** — `api_call_with_retry()` + `api_retry_log()` |
| `cron/ai_image_handler.php` | Replaced bespoke retry loop with shared helper |
| `cron/ai_scene_handler.php` | Wrapped `claude_api_call()` curl request with shared helper |
| `cron/ai_worker.php` | `require_once ai_helpers.php` before handlers |

---

## Phase 28 — DB-Driven Settings: Image Styles, Genres & AI Creator Overhaul ✅ Complete

Added audience targeting, a two-level image-style selector with mood modifiers, admin-editable
content lists (genres, image styles, moods), multi-genre stories (JSON array), per-story image
settings, and an `openai_image_format` setting.

### Pre-implementation decisions (from review)

1. **Edit-form image settings** — the per-story image-style controls appear on the **edit**
   story-properties form too, not only the create AI panel.
2. **Generation-genre unified to `story_genres`** — the AI panel's genre dropdown is driven by
   the admin-managed `story_genres` setting; `api_create_story_ai.php` validates against it.
3. **AI genre auto-fills stored genres** — the generation genre is written into the new story's
   stored multi-genre array at creation.

### Completed tasks

- **28.1** — `.claude/migration_4.sql`: convert existing `genre` strings → JSON arrays, widen
  `genre` to TEXT, add `ai_image_category/style/mood/quality` columns, seed `image_styles`,
  `image_moods`, `story_genres`, `openai_image_format` settings (idempotent INSERT).
- **28.2** — `settings.php` `SETTING_DEFAULTS` gains the four new keys (so the app works
  pre-migration); `account.php` AI Generation section gains an **Image Output Format** dropdown.
- **28.3** — `account.php` **Content Settings** admin section: flat chip editors for Story Genres
  and Mood Modifiers, accordion + chip editor for Image Styles. Vanilla-JS `ContentSettings`
  helper; saves via a new `save_content_settings` POST action (separate from
  `update_site_settings` so it can't clobber the `ai_enabled` checkbox); server validates/normalises
  the JSON before storing.
- **28.4** — Hardcoded `$genreList` arrays in `index.php`, `summary.php`, `editor.php` replaced
  with `json_decode(app_setting('story_genres'))`.
- **28.5** — `db_functions.php`: `story_genres_to_json()` + `decode_story_genre()` helpers;
  `create_story()`/`update_story()` accept array genres; `get_story()` selects the new
  `ai_image_*` columns and decodes genre; `get_all_stories()` filter uses
  `JSON_CONTAINS(genre, JSON_QUOTE(?))`; genre decoded on every list fetch
  (`get_all_stories`, `get_stories_by_user`, `search_stories`, `get_favorites_by_user`); new
  `update_story_image_settings()` setter; `clone_story()` preserves genre + image settings.
- **28.6** — `editor.php`: single genre `<select>` replaced with a chip multi-select
  (`genre-add-select` + `genres` hidden JSON field + add/remove JS), in both create and edit modes.
- **28.7** — Multi-genre chips rendered on `index.php` cards and `summary.php` meta line.
- **28.8** — `editor.php` AI panel: the "Scene Images" quality select replaced with an
  **Audience** dropdown; included in Randomize.
- **28.9** — AI panel image-options row: "Include Images" checkbox + two linked selects
  (category → sub-style) + mood + quality, with enable/disable JS.
- **28.10** — Per-story image settings stored: `save_story` handler reads `genres` +
  `ai_image_*`; `update_story_image_settings()` called on save; `ai_apply.php` writes the
  settings to the story on AI completion. Edit-properties form has its own image-settings
  controls (decision 1).
- **28.11** — Inline cover/scene panels: reusable `render_inline_image_style_controls()` +
  `window.inlineStyleParams` helpers; "Use story's image settings" checkbox (default on when the
  story has saved settings) with revealable manual controls; `generateCoverImage()` and
  `submitSceneImageJob()` pass category/style/mood (and the story's quality) into the image job.
- **28.12** — `randomizeStoryAI()` uses the managed genre list, picks an audience, and (when
  Include Images is checked) randomises category/sub-style/mood (~20% no mood)/quality.
- **28.13** — `api_create_story_ai.php` adds `audience`, `include_images`, `image_category/style/mood`
  to `input_json` and auto-fills the story genre; `ai_story_handler.php` reads `audience` and
  threads it through the plan + scene-writer prompts; `ai_apply.php` propagates the style fields
  into each queued cover/scene image job. ("Include Images" unchecked → `image_quality='none'`,
  reusing the existing dispatch gate.)
- **28.14** — `prompts/story_plan_system.txt` and `prompts/story_scene_writer_system.txt` gained
  an `{AUDIENCE}` section with per-audience guidance.
- **28.15** — `ai_image_handler.php` composes ` in {style} style, {mood}` onto the prompt, sends
  `output_format` from `openai_image_format`, and saves with the matching extension
  (jpeg→`.jpg`).

### Notes / follow-ups

- `input_json` (not the plan's `input_data`) is the real job column — used throughout.
- On AI create, the story genre is auto-filled from the generation-genre dropdown; any chips set
  in the multi-genre editor at the same time are not used by the AI path (manual non-AI create
  still respects the chip editor via `save_story`).
- `load_random_seed()` filters `premises.json` by genre using the now display-cased value
  (e.g. "Sci-Fi"); if it doesn't match the lowercase seed genres it falls back to the full list
  (only affects random premise selection when the premise is left blank).
- Migration must be run against the live DB before deploy; defaults in `settings.php` keep the
  app working until then.

### Files changed

| File | Change |
|---|---|
| `.claude/migration_4.sql` | genre→TEXT + data migration; `ai_image_*` columns; seed content settings |
| `settings.php` | Defaults for `image_styles`, `image_moods`, `story_genres`, `openai_image_format` |
| `account.php` | Image Output Format dropdown; Content Settings editors + `save_content_settings` handler |
| `db_functions.php` | Genre helpers; array-genre create/update; JSON_CONTAINS filter; genre decode on fetch; `update_story_image_settings()`; clone preserves genre/settings |
| `index.php` | `story_genres` setting; multi-genre card chips |
| `summary.php` | `story_genres` setting; multi-genre meta chips |
| `editor.php` | Genre chip editor; audience dropdown; image-options row + JS; edit-form image settings; inline "Use story settings" panels; randomize + create-submit updates; save_story stores genres + image settings |
| `api_create_story_ai.php` | Validate genre vs `story_genres`; audience + image fields in input_json; auto-fill genre |
| `cron/ai_story_handler.php` | Read audience; thread through plan + scene-writer prompts |
| `cron/ai_apply.php` | Preserve genre; store image settings; propagate style fields to image jobs |
| `cron/ai_image_handler.php` | Compose style/mood; `output_format`; matching file extension |
| `prompts/story_plan_system.txt`, `prompts/story_scene_writer_system.txt` | `{AUDIENCE}` section |

---

## Phase 29 — AI Guardrails ✅ Complete

Admin-configurable content guardrails injected into every AI generation. Claude is asked to
self-report a `red_flag` field when its output would breach a restricted topic (which fails the
job); image prompts are prefixed with a "Do not depict" instruction. Breaches are logged.

### Completed tasks

- **29.1** — `.claude/migration_4.sql`: idempotent `INSERT ... ON DUPLICATE KEY UPDATE` seeds
  `guardrails_enabled` ('1') and `guardrails_text` (6 default topics, one per line; `\n` stored
  as real newlines by MariaDB). Rows applied to the live DB on `192.168.1.184`.
  `settings.php` `SETTING_DEFAULTS` gains both keys so the app works pre-migration.
- **29.3** — `get_guardrail_clause()` added to `settings.php`: returns the restriction list as a
  comma-joined string, or `''` when guardrails are disabled or the list is empty (callers skip
  all injection when empty).
- **29.7** — `cron/ai_helpers.php` gains three helpers:
  - `log_guardrail_breach($jobId, $topic)` — appends to `cron/logs/guardrails_YYYYMMDD.log`
    (reconciled to `cron/logs/`, per the Phase 27 deviation note) and mirrors to `worker_log()`.
  - `guardrail_prompt_suffix()` — the Claude system-prompt block (DRY; used by all 3 builders);
    empty string when disabled.
  - `guardrail_check_response($parsed, $jobId)` — on a non-empty `red_flag`, logs the breach and
    throws `Inappropriate Content Detected: {topic}`, which the worker's catch turns into the
    job's error_message (no separate `db_update_job_status` call needed — fits the existing
    exception → `fail_ai_job()` flow).
- **29.2** — `account.php`: admin-only **Guardrails** section (after Content Settings) with an
  "Enable Guardrails" checkbox and a 6-row "Content Restrictions" textarea. Dedicated
  `save_guardrails` POST action (separate from `update_site_settings`/`save_content_settings` so
  the checkbox can't clobber unrelated settings); handler normalises to LF, trims each line, and
  drops blank lines before saving.
- **29.4 / 29.6 / 29.8** — Claude handlers:
  - `ai_scene_handler.php` — `build_scene_system_prompt()` appends `guardrail_prompt_suffix()`;
    `claude_scene_request()` takes a `$jobId` and runs `guardrail_check_response()` after parsing.
  - `ai_story_handler.php` — both `build_plan_system_prompt()` and
    `build_scene_writer_system_prompt()` append the suffix; `generate_story_plan()` and
    `write_scene_content()` take a `$jobId` and check for `red_flag` after each phase's parse
    (covers the plan phase and every scene write for both `full_story` and `create_story` jobs).
- **29.5** — `ai_image_handler.php`: prepends `"Do not depict: {clause}. "` to the final image
  prompt when guardrails are enabled.

### Deviation / decisions

- The plan's 29.6 snippet calls `db_update_job_status(...)` directly; this codebase fails jobs by
  throwing (worker catch → `fail_ai_job()`), so `guardrail_check_response()` logs then throws to
  stay consistent and avoid a double status write. Same end state: job → error with the breached
  topic in `error_message`, and a `cron/logs/guardrails_*.log` entry.
- Guardrail log lives in `cron/logs/` (not the plan's root `logs/`), resolving the Phase 27 note.

### Smoke test

- CLI (self-contained, stubbed settings loader): clause = comma list; suffix = full red_flag
  instruction block; `guardrail_check_response` throws `Inappropriate Content Detected: Suicide`
  on a breach and passes a clean response; disabled → empty clause/suffix; breach written to
  `cron/logs/guardrails_*.log`. Temp script + test log removed afterward.
- Browser (admin): Guardrails section renders with checkbox checked + 6-line textarea; save
  round-trip normalised messy input (dropped a blank line, trimmed `"  Child Abuse  "` and
  `"   Self-harm imagery   "`) and persisted to the DB; original 6 topics restored; no PHP
  warnings on the page.

### Files changed

| File | Change |
|---|---|
| `.claude/migration_4.sql` | Phase 29 guardrails settings INSERT |
| `settings.php` | Defaults for `guardrails_enabled`/`guardrails_text`; `get_guardrail_clause()` |
| `cron/ai_helpers.php` | `log_guardrail_breach()`, `guardrail_prompt_suffix()`, `guardrail_check_response()` |
| `account.php` | Guardrails admin section + `save_guardrails` POST handler |
| `cron/ai_scene_handler.php` | Append suffix; `$jobId` + red_flag check in `claude_scene_request()` |
| `cron/ai_story_handler.php` | Append suffix to both builders; `$jobId` + red_flag check in plan & scene-writer |
| `cron/ai_image_handler.php` | Prepend "Do not depict" prefix to image prompt |

---

## Phase 30 — Admin Maintenance Section ✅ Complete

Daily cleanup of trashed stories and old logs, launched asynchronously by the dispatcher and
on demand from the admin panel.

### Completed tasks

- **30.1 / 30.3** — `migration_4.sql` + `SETTING_DEFAULTS` seed `trash_retention` ('1week') and
  `log_retention` ('1month') idempotently; rows applied to the live DB. `settings.php` gains
  `retention_to_interval()` (value → MySQL INTERVAL).
- **30.4** — `db_purge_deleted_stories($interval)` in `db_functions.php`: selects `status='deleted'`
  stories older than the interval, then hard-deletes choices → scenes → story **and each story's
  image folder** (absolute `__DIR__` path, so it works from web and CLI), returning the purged
  `storyID`s. Self-contained — the same scope as `delete_story()`. Uses the real `storyID` column
  (the plan's pseudocode said `story_id`).
- **30.5** — `cron/maintenance.php` CLI: `--force` flag; <1h age guard on today's log; purges trash
  (+ deletes `images/stories/{id}/` via absolute `__DIR__` paths) and old `cron/logs/*.log` by
  `filemtime`; writes `cron/logs/maintenance_YYYYMMDD.log`.
- **30.6** — `cron/ai_dispatcher.php` launches maintenance once per calendar day, **before** the
  no-jobs early-exit (so it runs on idle days), asynchronously via `proc_open` (Windows) /
  `exec &` (POSIX) — mirroring the existing worker-spawn pattern.
- **30.2** — `account.php` **Maintenance** admin section: retention dropdowns (`save_maintenance`
  action) and a single **"Empty Trash Now"** button. The button runs the purge **inline** in the
  request (just like the trash page's "Delete Forever"), then reports the real count in the flash
  (e.g. *"Emptied trash — purged 1 story."*). There is intentionally **no** "Delete Logs Now" UI
  button — log cleanup happens only via the periodic dispatcher path.

### Design note — manual vs periodic cleanup (simplified after review)

Two distinct paths, deliberately different:
- **Periodic (unattended):** the dispatcher launches `cron/maintenance.php` once/day **asynchronously**
  via `proc_open` — the exact same mechanism it already uses to spawn AI workers (CLI→CLI, no special
  handling), so a large unattended cleanup never blocks job dispatch.
- **Manual (admin button):** runs **inline/synchronously** in the web request. The purge is just DB
  deletes + file unlinks, so it's fast and lets the flash report the real count.

An earlier iteration had the button spawn `maintenance.php` from the web, which needed a
`php_cli_binary()` resolver (because `PHP_BINARY` is `httpd.exe` under mod_php) and a `proc_open`
workaround. That web→CLI spawn — and `php_cli_binary()` — was **removed**; the inline approach is
simpler and consistent with the existing "Delete Forever" button.

### Smoke test

Inline button end-to-end: backdated trashed test story (10 days old) + image folder → clicking
**Empty Trash Now** flashed *"Emptied trash — purged 1 story."*, and the story row + image folder
were gone (other data untouched). The periodic `maintenance.php` (trash purge + log cleanup) was
also exercised directly via the dispatcher's `proc_open` path. All fixtures cleaned up.

### Files changed

| File | Change |
|---|---|
| `.claude/migration_4.sql` | trash_retention + log_retention seed |
| `settings.php` | defaults; `retention_to_interval()` |
| `db_functions.php` | `db_purge_deleted_stories()` (DB rows + image folders) |
| `cron/maintenance.php` | **New** CLI cleanup script (trash purge + log cleanup) |
| `cron/ai_dispatcher.php` | daily async maintenance launch (before no-jobs exit) |
| `account.php` | Maintenance admin section + `save_maintenance` + inline `empty_trash_now` handler |

---

## Phase 31 — Story Tree View ✅ Complete

A "Tree View" button in the editor opens a modal with an SVG tree of all scenes; nodes link to
the scene editor.

### Completed tasks

- **31.1** — `api_tree.php`: `GET ?storyID=N` → `{scenes:[{id,title,thumbnail,is_start}],
  choices:[{from_scene_id,to_scene_id,label}]}`. Owner-or-admin auth (401/403/404). Uses the real
  tables **`cyoa_ai_scenes` + `cyoa_ai_choices`** (the plan's `cyoa_ai_storypoints` doesn't exist).
  No `is_start` column exists, so the first scene by `sceneID ASC` is flagged start (matches
  `play.php`). Thumbnail resolver mirrors `editor_img_url` (handles `published_story_id`).
- **31.2** — `tree-view.js`: `TreeView.build(scenes, choices, storyID)` → interactive
  `.tree-container`. BFS from the start scene assigns depth tiers; first-seen edges are solid,
  edges to already-placed scenes are dashed (cycle/loop). Unreachable scenes get a trailing tier.
  Nodes = rounded rect + thumbnail (or numbered colour circle) + truncated title + start badge,
  wrapped in an `<a>` to `editor.php?action=edit_scene&storyID=N&sceneID=M` (the real edit URL,
  not the plan's `?scene=`). Wheel-zoom (scales the `<g>` + svg) and drag-to-pan (container scroll,
  drag suppresses the click so a pan doesn't open a scene).
- **31.3 / 31.4** — `editor.php`: "Tree View" button beside "+ Add Scene" (shown when scenes
  exist); loads `tree-view.js`; click → fetch → `Modal.open` with the container. `styles/tree-view.css`
  styles nodes/edges/container and widens the modal for the tree via `.modal-box:has(.tree-container)`.

### Smoke test

Story 6 (32 scenes): endpoint returned 32 scenes / 47 choices / exactly one `is_start`; the modal
rendered 32 nodes, 31 forward + 16 dashed cycle edges, thumbnails, and the start badge; node link =
`editor.php?action=edit_scene&storyID=6&sceneID=37`; wheel-zoom scaled the SVG. Auth verified from
an isolated standard-user session: 403 on the admin's story, 200 on own story, 404 on a missing id.

### Files changed

| File | Change |
|---|---|
| `api_tree.php` | **New** — scene + choice tree data endpoint |
| `tree-view.js` | **New** — BFS layout + SVG renderer + pan/zoom |
| `styles/tree-view.css` | **New** — node/edge/container styles + wide-modal rule |
| `editor.php` | Tree View button; load tree-view.js/css; fetch + modal handler |

---

## Phase 32 — Job Queue Enhancements ✅ Complete

Admin stat cards, a search/filter bar for everyone, Clear Completed, and a job detail modal with
syntax-highlighted JSON.

### Completed tasks

- **32.1** — `db_get_job_stats()` (one query, conditional aggregates): pending, running,
  completed-today (incl. `completed_with_errors`), failed-today, total-today, spent-today.
  Admin-only stat cards above the toolbar.
- **32.2** — Filter bar (all users): search `q` (story/scene title; + user name/email for admins),
  `status`, `type`, `period` (All Time / Today / This Week). GET form, dropdowns auto-submit;
  applied in PHP to the top-level jobs before the recent/past split, preserving parent/child
  grouping and live polling. `completed` matches `completed_with_errors` too. Period defaults to
  **All Time** (preserves the existing recent/past view; the plan mockup showed "Today").
- **32.3** — `db_clear_completed_jobs($userId, $isAdmin)` hard-deletes terminal jobs (completed,
  failed, cancelled, completed_with_errors) — admins all rows, users only their own; returns the
  count. Clear Completed button → `Modal.confirm` → POST `clear_completed` → PRG redirect
  preserving filters.
- **32.4** — `api_jobs.php` `GET ?action=detail&job_id=N` (owner/admin) returns the job + joined
  story/scene/user + decoded input/result JSON (via new `get_job_with_context()`). A "View" button
  on every row opens a modal showing metadata + two JSON panels, highlighted by a **hand-rolled**
  (~15-line) JSON highlighter — no external/CDN dependency, honouring the vanilla-JS convention and
  the offline-LAN constraint (the plan's highlight.js CDN was declined, per discussion).

### Smoke test

Against 156 real jobs (81 top-level): stat cards matched the DB; filter counts matched the DB
exactly (image 64, story 8, failed 8, completed-ish 73; today/bogus-search → 0 + "No jobs match");
the detail modal for a story job rendered both JSON panels with 223 highlighted tokens; View
buttons present on all 156 rows. Clear Completed's confirm dialog verified and **cancelled** (no
real history touched); `db_clear_completed_jobs` validated on throwaway rows for a jobless user
(3 terminal deleted, pending kept, other users untouched), then cleaned up.

### Files changed

| File | Change |
|---|---|
| `db_functions.php` | `get_job_with_context()`, `db_get_job_stats()`, `db_clear_completed_jobs()` |
| `api_jobs.php` | `GET ?action=detail&job_id=N` |
| `job_queue.php` | stat cards; filter bar + filtering; Clear Completed; View buttons; detail modal JS + JSON highlighter; styles |

---

## Phase 33 — Pending

| Phase | Title | Status |
|---|---|---|
| 33 | Documentation Update | pending |

