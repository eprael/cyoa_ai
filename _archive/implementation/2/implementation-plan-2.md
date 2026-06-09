# Implementation Plan 2 — UI & Feature Enhancements

Continues from 1 (Phases 0–7). All phases below build on the completed foundation.

## Guest User Convention

Any interactive element that requires a login (favouriting, rating, commenting) must still be **visible** to guests but **non-functional**. On click, redirect to `login.php`. Never silently fail or hide the element — guests should understand the feature exists and that logging in unlocks it. Read-only features (search, browse, play, view counts, average ratings) work for everyone.

---

## Phase 8 — Cosmetic Fixes & Quick Wins

> **Goal:** Scattered low-effort improvements that can be verified in isolation.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 8.1 | Add a favicon (create/source a small icon file, link in `<head>`) | `header.php`, `images/` or root | — |
| 8.2 | Header: widen container and increase logo size | `styles/styles.css`, `header.php` | — |
| 8.3 | Gallery: change heading from "Choose a story to Play" to "Choose a story to Explore" | `index.php` | — |
| 8.4 | Story editor: render scene descriptions as HTML instead of showing raw tags (use `innerHTML` in JS or strip-and-render server-side) | `editor.php` | — |
| 8.5 | Story editor: add visible gap/divider between scene cards in the scene list | `editor.php`, `styles/editor.css` | — |
| 8.6 | Job queue: collapse jobs older than 24 hours behind a "Past jobs" toggle link; recent jobs shown by default | `job_queue.php`, CSS | — |

**Deliverable:** All six items are independent one-shot fixes. No regressions to the core flow.

---

## Phase 9 — Navigation & Account Restructuring

> **Goal:** Streamline the header, move misplaced sections, and fix the live-polling bug.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 9.1 | Badge: only render/show the red dot when unseen count > 0; hide it entirely when count is 0 (currently an empty dot is visible) | `header.php`, CSS | — |
| 9.2 | Fix live job-status polling: `job_queue.php` job rows do not update without a full page refresh. Add a JS interval that re-fetches `api_jobs.php?action=list` every 5 s and updates status badges in-place while any job is `pending` or `running`; stops polling when all visible jobs are in a terminal state | `job_queue.php` | — |
| 9.3 | Remove **Generate** link from the main nav (it moves to the Create Story tab in Phase 14) | `header.php` | — |
| 9.4 | Remove **My Stories** from the main nav; add it as the first item in the account dropdown (below the user name, above Account) | `header.php` | — |
| 9.5 | Add **My Favourites** to the account dropdown as a link to `index.php?filter=favourites` | `header.php` | 10.1 |
| 9.6 | Remove the **My Favourites** section from `account.php` (it now lives as a gallery filter) | `account.php`, `styles/account.css` | 10.1 |
| 9.7 | Remove the **Image Generation Quality** select and info button from `account.php`; update `update_user_api_keys()` and `get_user_by_id()` to drop the `openai_image_quality` column. Quality will be a per-request choice (Phases 13 & 14). During the interim, `ai_image_handler.php` falls back to the `OPENAI_IMAGE_QUALITY` site constant. | `account.php`, `db_functions.php`, `cron/ai_image_handler.php` | — |
| 9.8 | Remove the redundant **Edit** button on the published-story editor view. Editing any scene already auto-creates a shadow draft; the standalone Edit button serves no additional purpose. | `editor.php` | — |

> **Note on 9.5 / 9.6:** The dropdown link can be added in the same commit as the gallery filter tab (10.1). Task 9.6 (removing the account section) should land in the same PR so users are never left without a way to find their favourites.

**Deliverable:** Header has four items (Explore · My Stories gone · Create · bell). Account dropdown contains My Stories, My Favourites, Account, theme swatches, Logout. Account page no longer has the two removed sections.

---

## Phase 10 — Gallery Enhancements

> **Goal:** My Favourites as a first-class filter, story search, and interactive heart icons on cards.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 10.1 | Add **My Favourites** filter tab alongside All Stories / My Stories; wire to `get_user_favorites()` already in `db_functions.php` | `index.php` | — |
| 10.2 | Add a search bar between the filter tabs and the Create New Story button; search filters by story title and description via a `?q=` GET parameter; add a `search_stories()` function to `db_functions.php` | `index.php`, `db_functions.php` | — |
| 10.3 | Cards: double the size of the eye (views) icon | `styles/cards.css` | — |
| 10.4 | Cards: show the heart icon as hollow/outlined by default; fill it red if the logged-in user has favourited the story. Pass a `$userFavouriteIDs` set from the server so no extra queries per card. Guests always see hollow hearts. | `index.php`, `styles/cards.css` | — |
| 10.5 | Cards: make the heart icon clickable — toggle favourite via `api_social.php?action=toggle_favorite` and update the icon and count in-place without a page reload. Guests clicking the heart are redirected to `login.php`. | `index.php` (JS), `api_social.php` | 10.4 |

**Deliverable:** Gallery has three filter tabs, a working search bar, and interactive heart icons that update live.

---

## Phase 11 — Summary Page Adjustments

> **Goal:** Consolidate the favourite button and reposition the star rating.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 11.1 | Remove the separate **Favourite** button next to Edit Story; the existing favourites count badge (heart + count) becomes the sole toggle button. Guests see the badge but clicking redirects to `login.php`. | `summary.php`, CSS | — |
| 11.2 | Move the star-rating widget to below the **Play Story** button. Layout: stars on one line, average rating in brackets; line below reads "You rated this X / 5" for logged-in users, "Log in to rate this story" for guests. Guest stars are non-interactive. | `summary.php`, CSS | — |

**Deliverable:** Summary page has a cleaner action area; favouriting and rating are both accessible without redundancy.

---

## Phase 12 — Story Editor — Scene Card Redesign

> **Goal:** Scene cards show a thumbnail, metadata, and choices instead of just a title row.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 12.1 | Redesign each scene card: thumbnail on the left (target ~300 × 300 px, configurable via a `SCENE_THUMB_SIZE` constant in `config.php`), scene title + first ~150 chars of description + choice list to the right | `editor.php`, `styles/editor.css` | — |
| 12.2 | Display the scene's choices on its card (choice text only, no edit controls) so the story structure is visible at a glance | `editor.php` | 12.1 |
| 12.3 | Make the thumbnail clickable: clicking opens a modal overlay showing the full-size image | `editor.php` (JS + HTML), CSS | 12.1 |

**Deliverable:** The scene list in the editor reads like a storyboard — thumbnail, summary, and branches visible without opening the scene.

---

## Phase 13 — Scene Editor Enhancements

> **Goal:** Rich text editing for scene descriptions; inline AI image generation with quality control.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 13.1 | Integrate **Quill** rich-text editor (loaded from CDN) for the Scene Description field; on save, store the HTML output; on load, initialise Quill with the stored HTML | `editor.php`, `styles/editor.css` | 8.4 |
| 13.2 | Replace the existing "Generate Scene Image with AI" panel's separate prompt area with an inline **Generate with AI** button placed directly beside the file-chooser button under Scene Image | `editor.php`, CSS | — |
| 13.3 | Add an image-quality dropdown (Low / Medium / High) with the pricing info button next to the Generate button. Pass the selected quality in `input_json` when creating the image job. Update `ai_image_handler.php` to read `$input['quality']` first, falling back to `OPENAI_IMAGE_QUALITY`. | `editor.php`, `cron/ai_image_handler.php` | 9.7 |

**Deliverable:** Scene descriptions are authored with rich formatting. Image generation is a single button beside the file input, with quality selectable per generation.

---

## Phase 14 — Create Story — Tabbed AI Generator

> **Goal:** New-story and edit-story-properties pages gain a two-step AI generation flow; generate_story.php is retired.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 14.1 | Split the Create New Story / Edit Story Properties form into two tabs: **Create by Hand** (existing fields) and **Generate with AI** | `editor.php`, CSS | — |
| 14.2 | **Generate Story Properties** button (Tab 2): synchronous call to a new `api_ai_properties.php` endpoint that calls Claude and returns `{title, description, theme}` JSON; the form fields auto-fill with the result. User can click repeatedly to regenerate before committing. | `editor.php` (JS), `api_ai_properties.php` | — |
| 14.3 | **Generate Scenes** button (Tab 2): submits an async `full_story` job using the current form values (title, description, theme from step 1 or hand-typed). Existing `generate_story.php` job-creation logic moves here; `generate_story.php` is removed and its nav link (already removed in Phase 9) is gone. | `editor.php`, `api_jobs.php`, `generate_story.php` (delete) | 14.2 |
| 14.4 | Add **average scene word length** dropdown (50 / 100 / 200 words) to the Generate tab; pass value in `input_json`; update `ai_story_handler.php` prompt to respect the target word count | `editor.php`, `cron/ai_story_handler.php` | 14.3 |
| 14.5 | Replace the "also generate images" checkbox with a quality dropdown: Don't generate / Low / Medium / High. Pass selection in `input_json`. Update `ai_apply.php` to read quality when queuing child image jobs. | `editor.php`, `cron/ai_apply.php` | 13.3, 14.3 |
| 14.6 | Add a **"Generate cover image"** dropdown (same Low/Medium/High options + info button) beside the Story Thumbnail file chooser on both tabs. Submits an image job with `scene_id = NULL` and a new `target = 'story_cover'` field; apply logic saves to `images/{storyID}/cover_ai_{ts}.png` and updates the story's `image` column. | `editor.php`, `api_jobs.php`, `cron/ai_apply.php`, `db_functions.php` | 13.3 |
| 14.7 | Add a **"Generate everything"** button (Tab 2) that chains 14.2 (properties) then immediately submits a scenes job — skipping the manual review step. Triggered with a single click; shows a spinner while properties are fetched synchronously, then queues the scenes job. | `editor.php` (JS) | 14.2, 14.3 |
| 14.8 | Update job queue "Go to story" link for `full_story` jobs to point to `editor.php?storyID=X&action=view_story` (the Story Properties / overview page) instead of the scene list | `job_queue.php` | — |

**Deliverable:** `generate_story.php` is gone. All AI story creation lives under the Create Story tab. Authors can hand-build, generate properties only, generate scenes from existing properties, or do it all in one click.

---

## Phase 15 — Job Notification Grouping

> **Goal:** Users get one notification when a story or scene is fully done — not one per child image job.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 15.1 | Add `parent_job_id INT DEFAULT NULL` column to `ai_jobs` (migration SQL); add FK to same table; add `get_child_jobs()` and `update_parent_job_status()` helpers to `db_functions.php` | `db_functions.php`, migration SQL | — |
| 15.2 | When `ai_apply.php` queues child image jobs (for scene or full_story), set `parent_job_id` on each child to the originating job's `job_id` | `cron/ai_apply.php` | 15.1 |
| 15.3 | Badge (`api_jobs.php?action=unseen_count`): exclude jobs where `parent_job_id IS NOT NULL` — child image jobs never count toward the notification badge | `api_jobs.php` | 15.1 |
| 15.4 | When a child image job completes, check if all siblings are done. If any sibling failed, set the parent's status to a new `completed_with_errors` value (shown as ⚠ in the job queue). If all succeeded, leave the parent as `completed`. | `cron/ai_worker.php`, `db_functions.php` | 15.1, 15.2 |
| 15.5 | Update `ai_jobs` `status` ENUM to include `'completed_with_errors'`; update `job_queue.php` to render this state as a yellow ⚠ badge with tooltip "Story generated — some images failed" | `db_functions.php`, migration SQL, `job_queue.php`, CSS | 15.4 |
| 15.6 | On `job_queue.php`, show child image job rows nested/indented under their parent (collapsed by default), so users can inspect individual image failures without the failures polluting the top-level list | `job_queue.php`, CSS | 15.1 |

**Deliverable:** A full-story generation with 10 images fires exactly one badge notification. If 2 images failed, the story job shows ⚠ and the user can expand to see which ones.

---

## Phase 16 — Play Page — Sticky Layout

> **Goal:** Title and scene image remain on screen while long descriptions and choices scroll.

| # | Task | Files Affected | Depends On |
|---|------|---------------|------------|
| 16.1 | Refactor `play.php` layout into two regions: a sticky header zone (title always centred at top + scene image in its configured position — left / right / top) and a scrollable content zone (description text + choice buttons) | `play.php`, CSS | — |
| 16.2 | Implement the three sticky image positions using CSS: **top** (image below title, full width, content scrolls below); **left** / **right** (image fixed to its side, content scrolls in the remaining column). All three keep the title pinned at the very top. | `play.php`, CSS | 16.1 |
| 16.3 | Ensure sticky layout degrades gracefully on mobile: on narrow screens, always stack title → image → scrollable content regardless of the configured layout | `play.php`, CSS | 16.2 |

**Deliverable:** No matter how long the scene text is, the player can always see the title and image while reading and choosing.

---

## Suggested Build Order

```
Phase 8  (Quick fixes)               ← Start here; isolated, fast
    ↓
Phase 9  (Nav restructure)           ← Cleans up the shell before inner pages change
    ↓
Phase 10 (Gallery)                   ← Depends on My Favourites link from Phase 9
    ↓
Phase 11 (Summary page)              ← Small; clean up while gallery is fresh
    ↓
Phase 12 (Editor scene cards)        ← Self-contained visual overhaul
    ↓
Phase 13 (Scene editor — Quill/AI)  ← Quill + inline image; quality logic needed by Phase 14
    ↓
Phase 14 (Create story tabs)         ← Biggest phase; depends on quality work from Phase 13
    ↓
Phase 15 (Notification grouping)     ← DB change; do after all job-creating paths are stable
    ↓
Phase 16 (Play page layout)          ← Self-contained; can be done any time after Phase 8
```

Phase 16 has no dependencies beyond the base app and can be slotted in anywhere after Phase 8.
