# CYOA AI Upgrade — Session Progress

_Last updated: 2026-05-29_

---

## Phase 0 — Foundation (Database + Config)

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 0.1 | Migration SQL script | ✅ Done | `_installation/migration_ai_upgrade.sql` |
| 0.2 | Rename storypoints → scenes in DB | ✅ Done | In migration script |
| 0.3 | Rename storypoint → scene in all PHP code | ✅ Done | No "storypoint" references remain |
| 0.4 | Add `claude_api_key` / `openai_api_key` to users table | ✅ Done | In migration script |
| 0.5 | API Keys section on `register.php` | ✅ Done | |
| 0.6 | API Keys section on `account.php` (own keys) | ✅ Done | |
| 0.8 | Add `status` + `published_story_id` to stories table | ✅ Done | In migration script |
| 0.9 | Set all existing stories to `published` | ✅ Done | In migration script |
| 0.10 | Create `ai_jobs` table | ✅ Done | In migration script |
| 0.11 | Create `ratings`, `favorites`, `comments`, `views` tables | ✅ Done | In migration script |
| 0.12 | AI config constants in `config.php` | ✅ Done | `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `ANTHROPIC_MODEL` |
| 0.13 | DB functions — AI job functions | ✅ Done | `create_ai_job`, `get_ai_job`, `claim_pending_jobs`, `complete_ai_job`, `fail_ai_job`, `timeout_stale_jobs`, `cancel_ai_job`, `count_pending_jobs_by_user`, `get_jobs_by_user`, `get_all_jobs` |
| 0.13 | DB functions — story functions for `status`/`published_story_id` | ✅ Done | `get_all_stories()`, `get_stories_by_user()`, `get_story()`, `create_story()` updated |
| 0.13 | DB functions — `get_all_users()` API key columns | ✅ Done | Now returns `claude_api_key`, `openai_api_key` |
| 0.13 | DB functions — ratings, favorites, comments, views CRUD | ✅ Done | Full function groups added to `db_functions.php` |
| 0.13 | DB functions — publish/draft story helpers | ✅ Done | `publish_story()`, `set_story_draft()`, `is_edit_draft()`, `get_edit_draft()`, `create_edit_draft()`, `publish_draft()`, `discard_draft()`, `get_story_image_dir()` |
| 0.13 | Admin user table — "own key" checkmark columns | ✅ Done | `account.php` admin table now shows ✓ / — for Claude Key and OpenAI Key |

---

## Phase 1 — Draft/Published System

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1.1 | Filter `get_all_stories()` by status | ✅ Done | Published to all; owner's standalone drafts to owner; shadow drafts never shown. Added `$currentUserID` param. |
| 1.2 | `publish_story()` + `set_story_draft()` | ✅ Done | Added in Phase 0 |
| 1.3 | Shadow draft functions | ✅ Done | Added in Phase 0 |
| 1.4 | `get_story_image_dir()` | ✅ Done | Added in Phase 0 |
| 1.5 | Editor routing for published stories | ✅ Done | Visiting `editor.php?storyID=publishedID` auto-redirects to existing shadow draft if one exists |
| 1.6 | Revert to Original + Publish buttons for shadow drafts | ✅ Done | `editor.php` `view_story` section |
| 1.7 | Publish+Delete for drafts; Edit+Unpublish for published | ✅ Done | `editor.php` state-aware buttons (`$storyState`) |
| 1.8 | Image paths use `editor_img_url()` | ✅ Done | Draft folder checked first, falls back to published folder |
| 1.9 | `publish_draft()` transaction logic | ✅ Done | Added in Phase 0 |
| 1.10 | Gallery draft badge on standalone drafts | ✅ Done | `index.php` + `styles/cards.css` |
| 1.11 | `play.php` blocks drafts for non-owners | ✅ Done | Returns 404 if draft and visitor is not owner/admin |

---

## Phase 2 — Story Summary Page + Social Features

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 2.1 | Create `summary.php` | ✅ Done | Cover image, stats, play/edit buttons, view tracking, rating, fave, comments |
| 2.2 | Wire gallery card links to `summary.php` | ✅ Done | Published card images + "View" button → `summary.php`; draft cards still go to editor |
| 2.3 | View tracking on summary page load | ✅ Done | `record_view()` called on each `summary.php` load (published stories only) |
| 2.4 | `api_social.php` endpoints | ✅ Done | `rate`, `toggle_favorite`, `add_comment`, `delete_comment` — all return JSON |
| 2.5 | Star rating widget | ✅ Done | 5-star hover/click widget in `summary.php`; persists via `api_social.php` |
| 2.6 | Favourite toggle button | ✅ Done | Heart button on summary page; live count update via JS |
| 2.7 | Comments section | ✅ Done | Post, reply (one level), delete (own or admin); all via JS without page reload |
| 2.8 | Gallery cards show stats | ✅ Done | Views, avg rating, fave count shown on published cards; `get_story_stats()` added to `db_functions.php` |
| 2.9 | My Favourites on account page | ✅ Done | List with thumbnail, stats, Play/View buttons; CSS in `account.css` |

---

## Phase 3 — AI Job Queue Infrastructure

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 3.1 | `api_jobs.php` — create, status, unseen_count, list, cancel, retry, apply endpoints | ✅ Done | |
| 3.2 | `cron/ai_dispatcher.php` — claims jobs, spawns workers | ✅ Done | Already existed from prior session |
| 3.3 | `cron/ai_worker.php` — single-job processor | ✅ Done | Already existed; handler stubs replaced in Phases 4–6 |
| 3.4 | Stale job timeout in dispatcher | ✅ Done | `timeout_stale_jobs()` called on every dispatcher run |
| 3.5 | `cron/ai_apply.php` — apply functions (stubs for now) | ✅ Done | Real implementations added in Phases 4–6 |
| 3.6 | Header badge polling — JS polls `api_jobs.php?action=unseen_count` every 5s | ✅ Done | `header.php` updated |
| 3.7 | `job_queue.php` — user job list with status, links, cancel/retry | ✅ Done | |
| 3.8 | Mark jobs seen on `job_queue.php` load | ✅ Done | `mark_jobs_seen()` called on page load |
| 3.9 | Admin view — all users' jobs with Username column | ✅ Done | `job_queue.php` isAdmin branch |
| 3.10 | Cron schedule documented | ✅ Done | `cron/cron_setup.md` — Windows Task Scheduler (1 min) + Linux dual-entry (30s) |

**Also added in this phase:**
- `config.php`: `AI_ENABLED`, `AI_JOB_TIMEOUT_SECONDS`, `AI_MAX_PENDING_PER_USER` constants
- `db_functions.php`: `get_unseen_job_count()`, `mark_jobs_seen()`, `retry_ai_job()`; updated `get_jobs_by_user()` and `get_all_jobs()` to join story/scene titles

---

## Phase 4 — Level 1: AI Image Generation

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 4.1 | `cron/ai_image_handler.php` — DALL-E 3 API call + image download | ✅ Done | Builds full prompt with theme-style context; BYOK support; validates size < 5 MB; saves to `images/{storyID}/ai_{timestamp}_{random}.png` |
| 4.2 | Wire image handler into `ai_worker.php` for `job_type = 'image'` | ✅ Done | `require_once` added; `process_image_job` stub removed; `apply_ai_job()` called after `complete_ai_job()` |
| 4.3 | Image apply logic in `cron/ai_apply.php` | ✅ Done | `apply_image_result()` verifies scene exists, calls `update_scene_image()` |
| 4.3 | `update_scene_image()` in `db_functions.php` | ✅ Done | Targeted UPDATE of `image` and `image_gen` columns only |
| 4.4 | AI image generation panel in scene editor UI | ✅ Done | Panel shown on both new and existing scenes when `AI_ENABLED`; prompt textarea + Generate button |
| 4.5 | Wire Generate button → `api_jobs.php?action=create` → inline status | ✅ Done | JS fetch; shows ✓ success or error message inline; clears prompt on success |
| 4.6 | "Add Scene" auto-creates blank DB row so sceneID exists immediately | ✅ Done | `create_blank_scene` POST action creates scene row and redirects to edit form with `?is_new=1`; Discard button deletes the blank row if user cancels; `is_new` flag preserved through validation errors |

---

## Phase 5 — Level 2: AI Scene Generation

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 5.1 | `cron/ai_scene_handler.php` — Claude API call | ✅ Done | `process_scene_job()`, `claude_api_call()`, `validate_and_sanitize_scene()`; Claude helpers reusable by Phase 6 |
| 5.2 | Wire scene handler into `ai_worker.php` | ✅ Done | `require_once` uncommented; stub removed |
| 5.3 | Scene apply logic in `cron/ai_apply.php` | ✅ Done | `apply_scene_result()`: updates scene content, creates stub scenes + choices, optionally queues image job |
| 5.3 | `update_scene_content()` in `db_functions.php` | ✅ Done | Updates title/description/hint only |
| 5.3 | `get_scene_path_to_root()` in `db_functions.php` | ✅ Done | Backward walk from scene to root; returns ordered previous_scenes array |
| 5.4 | AI Scene Generator panel in editor | ✅ Done | Direction, mode, ending type, tone, num choices, generate image checkbox |
| 5.5 | Story map context on generation form | ✅ Done | Collapsible scene list shown above form |
| 5.6 | Wire "Generate Scene" button → `api_jobs.php` | ✅ Done | JS fetch; success shows link to Job Queue |

**Also added in this phase:**
- `api_jobs.php`: scene jobs now require `scene_id`; `input_json` augmented server-side with `story_title`, `story_theme`, `story_description`, and `previous_scenes` (backward path walk)

---

## Phase 6 — Level 3: AI Full Story Generation

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 6.1 | `cron/ai_story_handler.php` — two-phase plan + write pipeline | ✅ Done | Phase 1: `generate_story_plan()` + `validate_story_plan()`; Phase 2: `write_scene_content()` loop; reuses `claude_api_call()` from scene handler |
| 6.2 | Wire story handler into `ai_worker.php` for `job_type = 'full_story'` | ✅ Done | `require_once` uncommented; stub function removed |
| 6.3 | Full story apply logic in `cron/ai_apply.php` | ✅ Done | `apply_full_story_result()`: creates story record, two-pass scene+choice creation with temp_id→sceneID map, optional image job queue |
| 6.3 | `update_ai_job_story_id()` in `db_functions.php` | ✅ Done | Stamps `story_id` on the job row after story is created — enables "Go to story" link in job_queue.php |
| 6.4 | Build `generate_story.php` — Full Story Generator page | ✅ Done | Premise textarea, genre/tone/length/endings selects, include-images checkbox; fetches `api_jobs.php` |
| 6.5 | Configuration form (premise, genre, tone, length, endings) | ✅ Done | Part of `generate_story.php` |
| 6.6 | Wire "Generate Story" button → `api_jobs.php?action=create` | ✅ Done | JS fetch; inline success/error message; clears premise on success |
| 6.7 | Queue image jobs for each scene after story is generated | ✅ Done | `apply_full_story_result()` queues one image job per scene when `include_images` is set in input |
| 6.8 | Add "Generate" nav link to `header.php` | ✅ Done | Shown for logged-in users when `AI_ENABLED`; links to `generate_story.php` |

---

## Phase 7 — UI Redesign

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 7.1 | 5-theme CSS variable system | ✅ Done | `light` (default), `dark`, `ocean`, `forest`, `ember` — all via `[data-theme]` + CSS custom properties in `styles/styles.css` |
| 7.2 | 64px single-row app-bar nav | ✅ Done | `header.php` rewritten: logo-left, center nav links with active state, icon/avatar buttons on right |
| 7.3 | Job queue bell icon + badge | ✅ Done | SVG bell replaces text link; badge poles every 5s as before |
| 7.4 | Admin dropdown in nav | ✅ Done | Shield icon button → dropdown (admin users only) |
| 7.5 | User avatar dropdown | ✅ Done | Avatar button → dropdown with user name, Account link, theme swatches, logout |
| 7.6 | Theme switcher swatches | ✅ Done | 5 colored circles in avatar dropdown; writes to `localStorage`; applied before first paint via inline script to prevent flash |
| 7.7 | Dark-mode inputs | ✅ Done | `styles/forms.css` and `styles/editor.css`: `background: #fff` → `background: var(--input-bg, #fff)` |
| 7.8 | Active nav link detection | ✅ Done | PHP `basename()` + `$_GET['filter']` drives `.active` class on Explore / My Stories / Generate |

---

## Phase 8 — Cosmetic Fixes & Quick Wins

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 8.1 | Add favicon | ✅ Done | Injected via existing early-paint JS block in `header.php`; uses `logo_ai2.png` — covers all pages from one place |
| 8.2 | Header: wider container + larger logo | ✅ Done | Navbar height `64→72px`, padding `1.5→2.5rem`, logo `38→50px`, brand text `1.1→1.3rem` in `styles/styles.css` |
| 8.3 | Gallery: change heading to "Choose a story to Explore" | ✅ Done | `index.php` |
| 8.4 | Editor: render scene descriptions as HTML | ✅ Done | Card preview now uses `strip_tags()` before truncating — raw tags no longer shown; limit extended to 150 chars |
| 8.5 | Editor: gap/divider between scene cards | ✅ Done | Added missing `.scene-list` / `.scene-item` / `.scene-item-*` CSS to `editor.css` (PHP used these class names but they had no styles); gap `1.25rem` |
| 8.6 | Job queue: collapse jobs older than 24h behind "Past jobs" toggle | ✅ Done | `job_queue.php` splits jobs at 24h cutoff; past jobs hidden behind "Past jobs (N) ▸" toggle button |

---

## Phase 9 — Navigation & Account Restructuring

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 9.1 | Badge: hide red dot when count is 0 | ✅ Done | `.nav-badge[hidden] { display: none; }` added to `styles/styles.css` — overrides `display:flex` that was beating the HTML `hidden` attribute |
| 9.2 | Fix live job-status polling on `job_queue.php` | ✅ Done | JS polls `api_jobs.php?action=list` every 5s; updates `.job-status-cell` in each row; auto-stops when no pending/running jobs remain |
| 9.3 | Remove Generate link from header | ✅ Done | Removed from `nav-center` in `header.php` |
| 9.4 | Remove My Stories from header → add to account dropdown | ✅ Done | Removed from `nav-center`; added as first item in account dropdown (above Account) |
| 9.5 | Add My Favourites to account dropdown → `index.php?filter=favourites` | ✅ Done | Added to account dropdown below My Stories |
| 9.6 | Remove My Favourites section from `account.php` | ✅ Done | Removed section + `$myFavorites` fetch |
| 9.7 | Remove Image Generation Quality from `account.php`; update `db_functions.php` + handler | ✅ Done | Removed form group, POST handler var, info-bubble JS from `account.php`; `update_user_api_keys()` drops 4th param + column; `get_user_by_id()` drops column from SELECT; `ai_image_handler.php` falls back to `OPENAI_IMAGE_QUALITY` constant only |
| 9.8 | Remove redundant Edit button from published-story editor view | ✅ Done | Removed `start_edit` form from `$storyState === 'published'` block in `editor.php` — only Unpublish remains |

---

## Phase 10 — Gallery Enhancements

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 10.1 | Add My Favourites filter tab to gallery | ✅ Done | Third tab added to filter bar (logged-in only); uses existing `get_favorites_by_user()`; empty state shows helpful message |
| 10.2 | Add search bar (title + description) with `?q=` param; add `search_stories()` to `db_functions.php` | ✅ Done | Search bar shown to all users (including guests); `search_stories()` added to `db_functions.php`; results label shows count; Clear button appears when a search is active |
| 10.3 | Cards: double eye icon size | ✅ Done | Eye emoji wrapped in `<span class="stat-eye-icon">` with `font-size: 2em` in `cards.css` |
| 10.4 | Cards: hollow/filled heart based on user's favourites | ✅ Done | SVG heart replaces emoji; `get_user_favorite_ids()` added to `db_functions.php`; `$userFavSet` built server-side; `.is-faved` CSS fills heart red |
| 10.5 | Cards: clickable heart toggles favourite via AJAX | ✅ Done | JS on all `.card-fav-btn` calls `api_social.php?action=toggle_favorite`; updates icon + count in-place; guests redirected to `login.php` |

---

## Phase 11 — Summary Page Adjustments

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 11.1 | Favourites count badge becomes the sole toggle button (remove separate Favourite button) | ✅ Done | `#fav-btn` removed from `.summary-actions`; heart `<span class="meta-chip">` replaced with `<button class="meta-chip fav-chip">` using SVG heart; guests redirected to `login.php` on click; JS updated to target `#fav-chip` |
| 11.2 | Move star rating below Play Story button; add "You rated this X / 5" line | ✅ Done | Removed separate "Rate This Story" section; added `.summary-rating-block` inside `.summary-info`; stars + avg badge on line 1, "You rated this X/5" / "Log in to rate" on line 2; guests see stars with `cursor:default` but clicking redirects to `login.php` |

---

## Phase 12 — Story Editor — Scene Card Redesign

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 12.1 | Scene cards: thumbnail + title + partial description layout; add `SCENE_THUMB_SIZE` to `config.php` | ✅ Done | `SCENE_THUMB_SIZE = 200` added to `config.php`; inline `<style>` block in `editor.php` applies it to `.scene-item-thumb`; `get_scenes_with_choices_by_story()` added to `db_functions.php` (2-query approach, no N+1) |
| 12.2 | Display choices on scene cards | ✅ Done | Choices fetched with scenes; displayed as `↳ Choice text` list under description; "No choices — ending scene" shown when empty |
| 12.3 | Clickable thumbnail opens full-size image modal | ✅ Done | Thumb wrapped in `.scene-thumb-btn` (zoom-in cursor, hover scale); click opens `#scene-img-modal` overlay; close via ×, click-outside, or Escape |
 
---

## Phase 13 — Scene Editor Enhancements

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 13.1 | Integrate Quill WYSIWYG editor for scene description field | ✅ Done | Quill 1.3.7 Snow loaded from CDN (CSS + JS in `<head>`, conditional on `scene_form`); `<textarea>` replaced with hidden `<input>` + `#sp-description-quill` container; existing HTML loaded via `dangerouslyPasteHTML`; synced to hidden input on form submit and in `saveAndPlay()`; `scenes.description` widened to `TEXT` in migration SQL + schema |
| 13.2 | Inline Generate with AI button beside file chooser for scene image | ✅ Done | Removed separate "Generate Scene Image with AI" `editor-form` panel; added `✦ Generate with AI` toggle button below file input; `#ai-image-inline` panel (hidden by default) expands with prompt textarea + controls; only rendered when `$editScene && AI_ENABLED` |
| 13.3 | Inline image quality dropdown; pass quality in `input_json`; update handler | ✅ Done | Low / Medium / High `<select id="ai-image-quality">` in inline panel; `submitImageJob()` includes `quality` in `input_json`; `ai_image_handler.php` reads `$input['quality']` (whitelist-validated), falls back to `OPENAI_IMAGE_QUALITY` |

---

## Phase 14 — Create Story — Tabbed AI Generator

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 14.1 | Split Create/Edit Story Properties into two tabs: Create by Hand / Generate with AI | ✅ | `editor.php` story_form replaced with two-tab structure; tab nav hidden when AI disabled |
| 14.2 | Generate Story Properties button — synchronous Claude call fills title, description, theme | ✅ | New `api_ai_properties.php`; `generateProperties()` fills Tab 1 form fields; retries on invalid JSON |
| 14.3 | Generate Scenes button — async job using current form values; retire `generate_story.php` | ✅ | `generateScenes()` submits `full_story` job to `api_jobs.php`; `generate_story.php` replaced with redirect |
| 14.4 | Word length dropdown (50 / 100 / 200 words); update story handler prompt | ✅ | `word_length` clamped 50–300 in `ai_story_handler.php`; `build_scene_writer_system_prompt()` uses dynamic value |
| 14.5 | Replace images checkbox with quality dropdown (Don't / Low / Medium / High) | ✅ | `ai_apply.php` `apply_full_story_result`: reads `image_quality` instead of `include_images`; passes quality to child image jobs |
| 14.6 | Generate cover image dropdown beside Story Thumbnail file chooser | ✅ | Cover image UI in Tab 1 (edit mode only); `api_jobs.php` allows `scene_id=null` when `target='story_cover'`; `ai_apply.php` routes to `update_story_image()` |
| 14.7 | Generate Everything button — chains properties + scenes in one click | ✅ | `generateEverything()` awaits `generateProperties()` then calls `generateScenes()`; single-click workflow |
| 14.8 | Job queue "Go to story" link → Edit Story Properties page | ✅ | Already handled by existing `job_link()` in `job_queue.php` — no change needed |

---

## Phase 15 — Job Notification Grouping

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 15.1 | Add `parent_job_id` column to `ai_jobs`; add helper functions to `db_functions.php` | ✅ | Migration + schema updated; `create_ai_job()` accepts optional `$parentJobID`; `mark_parent_completed_with_errors()` added; badge/seen queries updated |
| 15.2 | Stamp `parent_job_id` on child image jobs in `ai_apply.php` | ✅ | Both `apply_scene_result` and `apply_full_story_result` pass current `job_id` as parent when creating child image jobs |
| 15.3 | Exclude child jobs from badge count | ✅ | `get_unseen_job_count()` filters `parent_job_id IS NULL`; `completed_with_errors` added to counted statuses |
| 15.4 | Set parent to `completed_with_errors` if any child image fails | ✅ | `ai_worker.php` catch block calls `mark_parent_completed_with_errors()` when failed job has a `parent_job_id` |
| 15.5 | Add `completed_with_errors` status; render as ⚠ in job queue | ✅ | `status` ENUM extended; amber `badge-warn` badge with ⚠ icon; live poll map updated |
| 15.6 | Show child jobs nested/indented under parent in job queue (collapsed by default) | ✅ | `job_queue.php` builds `$childrenByParent` map; toggle button (▸ N images) on parent row; child rows hidden by default with indent + ↳ marker |

---

## Phase 16 — Play Page — Sticky Layout

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 16.1 | Refactor play page into sticky header zone (title + image) and scrollable content zone | ✅ | New `styles/play_layout.css` holds all structural rules; `play.php` restructured into `.play-layout > .play-sticky + .play-scroll` |
| 16.2 | Implement all three image positions (top / left / right) as sticky layouts | ✅ | Sidebar layouts: `height:100dvh` + `overflow:hidden` on body, `.play-sticky` fixed-width column, `.play-scroll` scrolls; image_right uses `flex-direction:row-reverse`; image_top: `.play-sticky` is `position:sticky; top:0` with `max-height:55vh` image |
| 16.3 | Mobile fallback: always stack title → image → content on narrow screens | ✅ | `@media (max-width:768px)` overrides body overflow, converts all layouts to sticky-top stacked column |

**Also changed in this phase:**
- All 5 theme CSS files (egyptian, forest, scifi, ocean, desert) stripped of structural CSS (`body` max-width/margin, `.container`, `header` flex rules, layout variant structural overrides); visual styles (colors, fonts, borders) preserved unchanged
- `play_layout.css` is the single source of truth for play page layout structure across all themes

---

## Phase 17 — Admin Settings Table & Config Migration

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 17.1 | `cyoa_ai_settings` table schema + seed INSERT | ✅ Done | In `.claude/migration_3.sql`; 10 settings seeded with defaults |
| 17.2 | `db_get_all_settings()` + `db_set_setting()` in `db_functions.php` | ✅ Done | Under `// === Settings ===` section header |
| 17.3 | `settings.php` — `SETTING_DEFAULTS` array + `app_setting()` helper | ✅ Done | New file; included after `db_functions.php` on all pages |
| 17.3a | Code migration sweep — replace old constants with `app_setting()` | ✅ Done | `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `ANTHROPIC_MODEL`, `AI_ENABLED`, etc. removed from all PHP files; `app_setting()` used throughout |
| 17.4 | `config.php` cleanup — remove migrated settings | ✅ Done | Old constants gone; kept: DB creds, SMTP, `APP_URL`, `AI_IMAGE_PRICING`, Claude cost rate constants |
| 17.5 | Admin panel: Site Settings section in `account.php` | ✅ Done | API Keys, AI Generation, Job Queue, Appearance groups; password-masked API key fields (blank = no change) |

---

## Phase 18 — Story & Scene AI Integration Redesign

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 18.1 | Form field reorder (Theme → Layout → Title → Description → Thumbnail) | ✅ Done | Applied to both Create New Story and Edit Story Properties in `editor.php` |
| 18.2 | Create New Story: AI toggle button + `#story-ai-section` with genre/tone/scene count dropdowns + dice button | ✅ Done | `toggleStoryAI()` persists state to `localStorage`; `randomizeStoryAI()` randomizes all AI and Theme/Layout dropdowns |
| 18.3 | Create New Story: "Use AI" checkboxes (select-all + per-field) | ✅ Done | `#use-ai-row` / `.use-ai-checklist`; checked fields go readonly + placeholder `generated`; thumbnail checkbox has confirm-overwrite dialog |
| 18.4 | Story Thumbnail `[✨ AI]` button + `#story-thumb-ai-expand` inline section | ✅ Done | On both Create and Edit Story pages; description field + quality dropdown + Generate Image button; queues async `story_cover` job |
| 18.5 | `api_create_story_ai.php` — create draft story record + queue `create_story` job | ✅ Done | New endpoint; returns `{ok:true, storyID, jobID}`; JS shows alert then redirects to `index.php` |
| 18.6 | Edit Story Properties: remove all tab/AI markup; simplify to plain form | ✅ Done | Tab structure, `#ai-premise`, Generate buttons all removed; only Theme/Layout/Title/Description/Thumbnail remain |
| 18.7 | Scene pages: `[✨ AI]` modal button + `#scene-ai-modal` | ✅ Done | `openSceneAIModal()` checks for existing content and shows overwrite warning; modal has direction, mode, choices, tone, include-image fields |
| 18.8 | Scene Thumbnail `[✨ AI]` button + `#scene-thumb-ai-expand` | ✅ Done | Same inline-expand pattern as story thumbnail; queues async scene image job |
| 18.9 | `create_story` job type routed in `cron/ai_worker.php` → `process_create_story_job()` | ✅ Done | Handler in `cron/ai_story_handler.php`; properties phase → plan phase → scene-write phase → apply phase |
| 18.10 | Remove old tab structure, `#ai-premise`, Generate/GenerateEverything buttons from `editor.php` | ✅ Done | `switchStoryTab()`, `generateProperties()`, `generateScenes()`, `generateEverything()` all removed |
| 18.11 | CSS updates in `styles/editor.css` | ✅ Done | Added `.story-ai-section`, `.thumb-ai-expand`, `.btn-ai-inline`, `.field-ai-checked`, `#scene-ai-modal`, `.scene-ai-modal-card` |
| 18.12 | Retire `api_ai_properties.php` + `generate_story.php` → `retired/` folder | ✅ Done | Both files moved to `retired/`; folder created |

---

## Phase 19 — Header Redesign

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 19.1 | Header height increased by 60px | ✅ Done | `height: 72px → 132px` in `.navbar`; logo scaled to 64px; brand text to 1.15rem; icon buttons to 44px |
| 19.2 | Site title reads from `app_setting('app_title')` | ✅ Done | `header.php` uses `function_exists('app_setting')` guard for safety; defaults to `Choose Your Own Adventure!` |
| 19.3 | Remove Explore button from nav | ✅ Done | `.nav-center` now contains only the search form |
| 19.4 | Search bar moved from `index.php` body into `header.php` | ✅ Done | `nav-search-form` / `nav-search-input` / `nav-search-btn` CSS added to `styles.css`; search form removed from `index.php`; header search always submits to `index.php?q=...` |
| 19.5 | Username displayed below profile icon | ✅ Done | `nav-user-trigger` (flex-column button) contains `nav-avatar-icon` + `nav-username`; name removed from dropdown header |
| 19.6 | Admin cog icon replaces shield dropdown | ✅ Done | Cog SVG in `<a href="account.php">` direct link; dropdown and "Admin Panel" menu item removed |

**Files changed:** `header.php` (full rewrite), `styles/styles.css` (navbar height/scale + new search/user CSS), `index.php` (search form removed from body)

---

## Phase 20 — AI Job Cost Tracking

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 20.1 | Schema: add cost columns to `cyoa_ai_jobs` | ✅ Done | `ALTER TABLE` added to `.claude/migration_3.sql`: `input_tokens`, `output_tokens`, `image_count`, `cost_usd DECIMAL(10,6)` |
| 20.2 | Cost rate constants | ✅ Done | `AI_IMAGE_PRICING`, `AI_COST_INPUT_PER_M`, `AI_COST_OUTPUT_PER_M` already in `config.php` from Phase 17 |
| 20.3 | `db_update_job_cost()` + `db_get_chain_cost()` | ✅ Done | Added to `db_functions.php` under `// === AI JOB COSTS ===` section; chain cost uses simple two-level self-join |
| 20.4a | `claude_api_call()` usage capture | ✅ Done | Added `array &$usageAccum = []` parameter; input_tokens + output_tokens accumulated from each response |
| 20.4b | `ai_scene_handler.php` cost logging | ✅ Done | `claude_scene_request()` accepts `&$usageAccum`; `process_scene_job()` computes cost and calls `db_update_job_cost()` |
| 20.4c | `ai_story_handler.php` cost logging | ✅ Done | `generate_story_plan()` + `write_scene_content()` both accept `&$usageAccum`; both top-level handlers accumulate and call `db_update_job_cost()` at end |
| 20.4d | `ai_apply.php` image cost logging | ✅ Done | `apply_image_result()` looks up cost from `AI_IMAGE_PRICING[model][quality]` and calls `db_update_job_cost()` |
| 20.5 | Job queue UI: Cost column | ✅ Done | `job_queue.php`: `create_story` type label added; `.job-cost` CSS added; Cost column rendered for root jobs; partial `…` suffix while chain is still running; child rows show `—` |

---

## Phase 21 — Extract AI Prompts to External Files

**Status: ✅ Complete**

| # | Task | Status | Notes |
|---|------|--------|-------|
| 21.1 | Create `prompts/` directory with 4 template files | ✅ Done | `image_system.txt`, `scene_system.txt`, `story_plan_system.txt`, `story_scene_writer_system.txt` — exact prompt text extracted from PHP; dynamic values use `{PLACEHOLDER}` tokens |
| 21.2 | `load_prompt()` helper | ✅ Done | Added to `db_functions.php` under `// === PROMPT LOADER ===`; reads `prompts/{name}.txt`, substitutes `{UPPER_KEY}` tokens via `str_replace()` |
| 21.3 | Update `cron/ai_scene_handler.php` | ✅ Done | `build_scene_system_prompt()` body replaced with `return load_prompt('scene_system');` |
| 21.4 | Update `cron/ai_story_handler.php` | ✅ Done | `build_plan_system_prompt()` → `load_prompt('story_plan_system', ['target_scenes', 'num_endings'])`; `build_scene_writer_system_prompt()` → `load_prompt('story_scene_writer_system', ['scene_type', 'tone', 'word_length'])` |
| 21.5 | Update `cron/ai_image_handler.php` | ✅ Done | Inline `$systemCtx` string concat replaced with `load_prompt('image_system', ['theme_line' => $themeLine])` |
