# Implementation Plan 1 — Foundation & Setup

Phased rollout with dependencies mapped. Each phase builds on the previous one.

---

## Phase 0 — Foundation (Database + Config)

> **Goal:** Get all database changes in place and configure AI service credentials.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 0.1 | Write and run the migration SQL script | `_installation/migration_ai_upgrade.sql` | — |
| 0.2 | Rename `cyoa_ai_storypoints` → `cyoa_ai_scenes`; rename `storypointID` → `sceneID` column | migration script, `db_functions.php`, all PHP files | — |
| 0.3 | Rename all PHP variables, function names, and UI text from "storypoint" → "scene" | `db_functions.php`, `editor.php`, `play.php`, `header.php`, all templates | 0.2 |
| 0.4 | Add `claude_api_key` and `openai_api_key` columns to `users` table | migration script | — |
| 0.5 | Add optional API Keys section to sign-up form (`register.php`) | `register.php` | 0.4 |
| 0.6 | Add API Keys section to account settings page (`account.php`) | `account.php` | 0.4 |
| 0.13 | Update admin user table view to show "own key" checkmark columns | `account.php` | 0.4 |
| 0.8 | Add `status` and `published_story_id` columns to stories table | migration script | — |
| 0.9 | Set all existing stories to `published` | migration script | 0.8 |
| 0.10 | Create `ai_jobs` table | migration script | — |
| 0.11 | Create `ratings`, `favorites`, `comments`, `views` tables | migration script | — |
| 0.12 | Add AI config constants to `config.php` | `config.php` | — |
| 0.13 | Add new db functions for all new tables **and** update existing functions for modified tables to `db_functions.php` — specifically: (a) new function groups for `ai_jobs`, `ratings`, `favorites`, `comments`, `views`, and story status/shadow drafts; (b) update `get_user_by_id()` and `get_all_users()` to include `claude_api_key`/`openai_api_key`; (c) update `get_all_stories()`, `get_stories_by_user()`, `get_story()`, `create_story()` to include `status`/`published_story_id`; (d) update `delete_story()` to use the renamed `scenes` table (see also 0.2/0.3) | `db_functions.php` | 0.1–0.11 |

**Deliverable:** All tables exist and use the new "scene" terminology, BYOK columns are in place, all CRUD functions are available, config is ready.

---

## Phase 1 — Draft/Published System (WordPress-style)

> **Goal:** Stories have a status. Only published stories are publicly visible. The editor always shows a single version — authors never see "shadow draft" as a concept. Editing a published story creates a shadow draft behind the scenes, but the editor simply presents the story as being in Draft state with Revert / Publish options.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 1.1 | Update `get_all_stories()` to filter by status — show `published` to all, standalone drafts (`published_story_id IS NULL`) to owner, hide shadow drafts from gallery entirely | `db_functions.php` | 0.13 |
| 1.2 | Add `publish_story()` and `set_story_draft()` functions for standalone (new) stories | `db_functions.php` | 0.13 |
| 1.3 | Add shadow draft functions: `get_edit_draft()`, `create_edit_draft()`, `publish_draft()`, `discard_draft()`, `is_edit_draft()` | `db_functions.php` | 0.13 |
| 1.4 | Add `get_story_image_dir()` helper — returns `images/{published_story_id}/` for shadow drafts, `images/{storyID}/` otherwise | `db_functions.php` | 1.3 |
| 1.5 | Update editor routing: clicking "Edit" on a published story → look up or create shadow draft → redirect to draft. The editor renders the draft transparently — the author just sees their story in Draft state. | `editor.php` | 1.3 |
| 1.6 | Show **Revert to Original** and **Publish** buttons when a shadow draft is active. No banner or mention of "shadow draft" — the editor just shows Draft state. (The published version remains live to other users in the background.) | `editor.php` | 1.3, 1.5 |
| 1.7 | Show **Publish** + **Delete** buttons for new stories and standalone drafts. Show **Edit** + **Unpublish** buttons for published stories with no active shadow draft. Unpublish is never shown when a draft is active — the draft state actions (1.6) take over instead. | `editor.php` | 1.2, 1.3 |
| 1.8 | Update image upload/save to use `get_story_image_dir()` for reading, `images/{storyID}/` for writing new images on drafts | `editor.php`, image handling code | 1.4 |
| 1.9 | Implement `publish_draft()` logic: transaction to replace original scenes/choices/metadata, move changed images, delete draft | `db_functions.php` | 1.3, 1.4 |
| 1.10 | Update gallery (`index.php`) to show draft badge on owner's standalone drafts | `index.php`, `styles/cards.css` | 1.1 |
| 1.11 | In `play.php` and `summary.php`, return a 404 if the requested story is a draft and the current user is not the owner. Owners are redirected to the editor instead. | `play.php`, `summary.php` | 1.1 |

**Editor state summary:**

| Story state | Editor shows | Actions |
|---|---|---|
| New / standalone draft | Draft | Publish, Delete |
| Shadow draft active | Draft (seamlessly — no "shadow draft" language) | Revert to Original, Publish |
| Published, no draft | Published | Edit (creates shadow draft), Unpublish |

**Deliverable:** Draft/published lifecycle works end to end. Authors see a clean Draft/Published toggle with no internal implementation details exposed. Published stories remain live to other users while the author edits. Publishing a draft seamlessly replaces the original content while preserving its storyID and all social data.

---

## Phase 2 — Story Summary Page + Social Features

> **Goal:** New summary page between gallery and play. Ratings, favourites, comments, views all working.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 2.1 | Create `summary.php` — layout with cover image, stats, description, play/edit buttons | `summary.php`, `styles/` | Phase 1 |
| 2.2 | Wire gallery card links to `summary.php` instead of `play.php` | `index.php` | 2.1 |
| 2.3 | Implement view tracking — record a view each time summary page loads | `summary.php`, `db_functions.php` | 0.13 |
| 2.4 | Create `api_social.php` with rate, toggle_favorite, comment, delete_comment endpoints | `api_social.php` | 0.13 |
| 2.5 | Build star rating widget (JS + CSS) on summary page | `summary.php`, JS, CSS | 2.4 |
| 2.6 | Build favourite toggle button on summary page | `summary.php`, JS | 2.4 |
| 2.7 | Build comments section on summary page (post, reply, delete) | `summary.php`, JS, CSS | 2.4 |
| 2.8 | Update gallery cards to show view count, average rating, favourite count | `index.php`, `styles/cards.css` | 2.3–2.6 |
| 2.9 | Add "My Favourites" section to user profile/account page | `account.php` | 2.6 |

**Deliverable:** Full social feature set is live. Gallery shows stats. Summary page is the new entry point for stories.

---

## Phase 3 — AI Job Queue Infrastructure

> **Goal:** The job queue works end to end. A job can be created, auto-applied by cron, and the user is notified via the header badge.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 3.1 | Create `api_jobs.php` — create, unseen_count, cancel, retry, list, apply (admin) endpoints | `api_jobs.php` | 0.13 |
| 3.2 | Create `cron/ai_dispatcher.php` — claims pending jobs atomically, spawns worker processes, exits | `cron/ai_dispatcher.php` | 0.13 |
| 3.3 | Create `cron/ai_worker.php` — single-job processor: calls API handler, calls apply, marks completed/failed | `cron/ai_worker.php` | 0.13 |
| 3.4 | Implement stale job timeout in dispatcher (running > 5 min → mark failed) | `cron/ai_dispatcher.php` | 3.2 |
| 3.5 | Create `cron/ai_apply.php` — functions to apply completed results to story/scene tables (called by worker, not browser) | `cron/ai_apply.php` | 0.13 |
| 3.6 | Add header badge polling — `header.php` JS polls `api_jobs.php?action=unseen_count` every 5 seconds and updates the Job Queue link badge | `header.php` | 3.1 |
| 3.7 | Build `job_queue.php` — user's job list with status, "go to scene/story" links, retry/cancel actions | `job_queue.php`, CSS | 3.1 |
| 3.8 | Mark jobs seen on `job_queue.php` load (stamp `seen_at`, clears badge) | `job_queue.php`, `db_functions.php` | 3.7 |
| 3.9 | Admin view on `job_queue.php` — shows all users' jobs, adds Username column | `job_queue.php` | 3.7 |
| 3.10 | Set up cron schedule (every 30 seconds) for `ai_dispatcher.php` on server | server config / docs | 3.2 |

**Implementation notes (discovered during Phase 4 testing):**
- Windows only: `popen`/`start /B` does **not** inherit mapped drive letters (e.g. `O:`). Replace with `proc_open` — pass `$descriptors` redirecting stdin to `NUL` and stdout/stderr to a per-job log file; do **not** call `proc_close` so the worker runs independently after the dispatcher exits.
- Add a `worker_log()` helper to `ai_worker.php` that writes timestamped lines to `cron/logs/job_{id}.log` via `file_put_contents(..., FILE_APPEND)` and also echoes to stdout. Call it instead of bare `echo`/`fwrite(STDERR, ...)` throughout the worker. This makes worker output visible regardless of how the process was spawned.
- `cron/logs/` directory is created by both the dispatcher and the worker (whichever runs first).

**Deliverable:** Full queue pipeline works. Jobs are auto-applied by cron. User sees badge in header and can review all jobs on the Job Queue page. Cron is scheduled.

---

## Phase 4 — Level 1: AI Image Generation

> **Goal:** Authors can generate images for scenes using AI.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 4.1 | Create `cron/ai_image_handler.php` — DALL-E API call + image download | `cron/ai_image_handler.php` | Phase 3 |
| 4.2 | Wire image handler into `ai_worker.php` for `job_type = 'image'` | `cron/ai_worker.php` | 4.1 |
| 4.3 | Add image apply logic — save file, update scene `image` and `image_gen`; add `update_scene_image()` to `db_functions.php` | `cron/ai_apply.php`, `db_functions.php` | 4.1 |
| 4.4 | Add AI image generation panel to the scene editor UI — prompt input + "Generate" button | `editor.php`, CSS | 4.2 |
| 4.5 | Wire "Generate" button → `api_jobs.php?action=create` → inline status message (not a flash — JS updates text in the panel) | `editor.php`, JS | 3.6, 4.4 |
| 4.6 | Change "Add Scene" to auto-create a blank DB row immediately and redirect to the edit form — required so a `sceneID` exists before the panel renders and the user can submit a job. Add a **Discard** button that deletes the blank row if the user cancels. Preserve the `is_new` flag through validation errors. | `editor.php` | 4.4 |

**Deliverable:** Authors can type a prompt, submit the job, and see the generated image applied to the scene when they return to it (result applied automatically by cron).

---

## Phase 5 — Level 2: AI Scene Generation

> **Goal:** Authors can generate a complete scene (title, description, hint, choices) with AI.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 5.1 | Create `cron/ai_scene_handler.php` — Claude API call with scene prompt | `cron/ai_scene_handler.php` | Phase 3 |
| 5.2 | Wire scene handler into `ai_worker.php` for `job_type = 'scene'` | `cron/ai_worker.php` | 5.1 |
| 5.3 | Add scene apply logic — create/update scene + choices | `cron/ai_apply.php` | 5.1 |
| 5.4 | Build the AI Scene Generator form in the editor | `editor.php` (new action) or new page, CSS | 5.2 |
| 5.5 | Show story map context (existing scenes) on the generation form | `editor.php`, JS | 5.4 |
| 5.6 | Wire "Generate Scene" button → `api_jobs.php?action=create` → flash "Job queued" message | JS | 3.6, 5.4 |

**Deliverable:** Authors can fill out the scene generation form, submit the job, and find the generated scene and stub choices applied to the story when they return.

---

## Phase 6 — Level 3: AI Full Story Generation

> **Goal:** Authors can generate a complete branching story from a premise.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 6.1 | Create `cron/ai_story_handler.php` — two-phase plan + write pipeline | `cron/ai_story_handler.php` | Phase 3 |
| 6.2 | Wire story handler into `ai_worker.php` for `job_type = 'full_story'` | `cron/ai_worker.php` | 6.1 |
| 6.3 | Add full story apply logic — create story + all scenes + all choices | `cron/ai_apply.php` | 6.1 |
| 6.4 | Build the Full Story Generator page | New page or editor action, CSS | 6.2 |
| 6.5 | Add configuration form (premise, genre, tone, length, endings) | Generator page | 6.4 |
| 6.6 | Wire "Generate Story" button → `api_jobs.php?action=create` → flash "Job queued" message | JS | 3.6, 6.4 |
| 6.7 | Optional: queue image jobs for each scene after story is generated | `cron/ai_story_handler.php` | 4.1, 6.3 |

**Deliverable:** Authors can enter a premise, submit the job, and find the complete playable story created in their account when it finishes (link in job queue page).

---

## Phase 7 — UI Redesign

> **Goal:** Updated navigation bar and polished UI across all pages.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 7.1 | Redesign `header.php` — my stories, create, explore, job queue link (with badge), user dropdown | `header.php`, CSS | Phase 2, Phase 3 |
| 7.2 | Add admin menu dropdown (gold button) for admin users | `header.php`, CSS | 7.1 |
| 7.3 | Polish gallery cards with new stat icons and draft badges | `index.php`, `styles/cards.css` | Phase 2 |
| 7.4 | Polish summary page layout | `summary.php`, CSS | Phase 2 |
| 7.5 | Polish editor pages for AI panels | `editor.php`, CSS | Phases 4–6 |

**Deliverable:** App looks and feels like a real platform.

---

## Suggested Build Order

```
Phase 0 (Foundation)         ← Start here, do first
    ↓
Phase 1 (Draft/Published)   ← Small, quick win
    ↓
Phase 2 (Summary + Social)  ← Biggest non-AI feature set
    ↓
Phase 3 (Job Queue)          ← Required before any AI feature
    ↓
Phase 4 (AI Images)          ← Easiest AI level, proves the pipeline
    ↓
Phase 5 (AI Scenes)          ← Builds on proven pipeline
    ↓
Phase 6 (AI Full Story)      ← Most complex, do last
    ↓
Phase 7 (UI Polish)          ← Can be done incrementally alongside other phases
```

Phase 7 tasks can be interleaved at any point — they're cosmetic and don't block other work.

---

## Testing Checkpoints

After each phase, verify:

- [ ] All new database functions work (manual test via PHP script or phpMyAdmin)
- [ ] No regressions — existing story creation, editing, playing, cloning all still work
- [ ] New UI elements render correctly on both desktop and mobile
- [ ] For AI phases: test with a real API call, verify the full submit → cron → poll → apply cycle
- [ ] Admin panel shows correct data for the new features
