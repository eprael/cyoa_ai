---
title: "The Implementation Plan"
pagetitle: "Appendix A — The Implementation Plan"
subtitle: >
  The project was built as a series of numbered **implementation plans**, each broken
  into **phases**. Every plan was written *before* a round of work — laying out its
  phases, the files each would touch, and the decisions made up front. This appendix
  walks the whole history: under each plan, every phase with its goal and full task list,
  plus each plan's context sections (overviews, conventions, and design notes). Phase
  numbers run continuously across the project, so a higher number simply means later in
  the build; the documentation phase — deferred and renumbered several times — appears
  once, at the very end.
---

<style>
  /* bold the level-1 (Plan) entries in the table of contents */
  nav#TOC > ul > li > a { font-weight: 700; }
  /* right-aligned "back to top" arrow on each plan / phase heading */
  .page h2 .uptop, .page h3 .uptop {
    float: right;
    font-size: 0.6em;
    font-weight: 600;
    line-height: 2.2;
    color: var(--accent-dk);
    text-decoration: none;
  }
  .page h2 .uptop:hover, .page h3 .uptop:hover { color: var(--blue); }
</style>

## Plan 1 — Foundation & Setup

Phased rollout with dependencies mapped. Each phase builds on the previous one.


### Phase 0 — Foundation (Database + Config)

Get all database changes in place and configure AI service credentials.

**Tasks:**

- **0.1** — Write and run the migration SQL script
- **0.2** — Rename `cyoa_ai_storypoints` → `cyoa_ai_scenes`; rename `storypointID` → `sceneID` column
- **0.3** — Rename all PHP variables, function names, and UI text from "storypoint" → "scene"
- **0.4** — Add `claude_api_key` and `openai_api_key` columns to `users` table
- **0.5** — Add optional API Keys section to sign-up form (`register.php`)
- **0.6** — Add API Keys section to account settings page (`account.php`)
- **0.13** — Update admin user table view to show "own key" checkmark columns
- **0.8** — Add `status` and `published_story_id` columns to stories table
- **0.9** — Set all existing stories to `published`
- **0.10** — Create `ai_jobs` table
- **0.11** — Create `ratings`, `favorites`, `comments`, `views` tables
- **0.12** — Add AI config constants to `config.php`


### Phase 1 — Draft/Published System (WordPress-style)

Stories have a status. Only published stories are publicly visible. The editor always shows a single version — authors never see "shadow draft" as a concept. Editing a published story creates a shadow draft behind the scenes, but the editor simply presents the story as being in Draft state with Revert / Publish options.

**Tasks:**

- **1.1** — Update `get_all_stories()` to filter by status — show `published` to all, standalone drafts (`published_story_id IS NULL`) to owner, hide shadow drafts from gallery entirely
- **1.2** — Add `publish_story()` and `set_story_draft()` functions for standalone (new) stories
- **1.3** — Add shadow draft functions: `get_edit_draft()`, `create_edit_draft()`, `publish_draft()`, `discard_draft()`, `is_edit_draft()`
- **1.4** — Add `get_story_image_dir()` helper — returns `images/{published_story_id}/` for shadow drafts, `images/{storyID}/` otherwise
- **1.5** — Update editor routing: clicking "Edit" on a published story → look up or create shadow draft → redirect to draft. The editor renders the draft transparently — the author just sees their story in Draft state.
- **1.6** — Show **Revert to Original** and **Publish** buttons when a shadow draft is active. No banner or mention of "shadow draft" — the editor just shows Draft state. (The published version remains live to other users in the background.)
- **1.7** — Show **Publish** + **Delete** buttons for new stories and standalone drafts. Show **Edit** + **Unpublish** buttons for published stories with no active shadow draft. Unpublish is never shown when a draft is active — the draft state actions (1.6) take over instead.
- **1.8** — Update image upload/save to use `get_story_image_dir()` for reading, `images/{storyID}/` for writing new images on drafts
- **1.9** — Implement `publish_draft()` logic: transaction to replace original scenes/choices/metadata, move changed images, delete draft
- **1.10** — Update gallery (`index.php`) to show draft badge on owner's standalone drafts
- **1.11** — In `play.php` and `summary.php`, return a 404 if the requested story is a draft and the current user is not the owner. Owners are redirected to the editor instead.


### Phase 2 — Story Summary Page + Social Features

New summary page between gallery and play. Ratings, favourites, comments, views all working.

**Tasks:**

- **2.1** — Create `summary.php` — layout with cover image, stats, description, play/edit buttons
- **2.2** — Wire gallery card links to `summary.php` instead of `play.php`
- **2.3** — Implement view tracking — record a view each time summary page loads
- **2.4** — Create `api_social.php` with rate, toggle_favorite, comment, delete_comment endpoints
- **2.5** — Build star rating widget (JS + CSS) on summary page
- **2.6** — Build favourite toggle button on summary page
- **2.7** — Build comments section on summary page (post, reply, delete)
- **2.8** — Update gallery cards to show view count, average rating, favourite count
- **2.9** — Add "My Favourites" section to user profile/account page


### Phase 3 — AI Job Queue Infrastructure

The job queue works end to end. A job can be created, auto-applied by cron, and the user is notified via the header badge.

**Tasks:**

- **3.1** — Create `api_jobs.php` — create, unseen_count, cancel, retry, list, apply (admin) endpoints
- **3.2** — Create `cron/ai_dispatcher.php` — claims pending jobs atomically, spawns worker processes, exits
- **3.3** — Create `cron/ai_worker.php` — single-job processor: calls API handler, calls apply, marks completed/failed
- **3.4** — Implement stale job timeout in dispatcher (running > 5 min → mark failed)
- **3.5** — Create `cron/ai_apply.php` — functions to apply completed results to story/scene tables (called by worker, not browser)
- **3.6** — Add header badge polling — `header.php` JS polls `api_jobs.php?action=unseen_count` every 5 seconds and updates the Job Queue link badge
- **3.7** — Build `job_queue.php` — user's job list with status, "go to scene/story" links, retry/cancel actions
- **3.8** — Mark jobs seen on `job_queue.php` load (stamp `seen_at`, clears badge)
- **3.9** — Admin view on `job_queue.php` — shows all users' jobs, adds Username column
- **3.10** — Set up cron schedule (every 30 seconds) for `ai_dispatcher.php` on server


### Phase 4 — Level 1: AI Image Generation

Authors can generate images for scenes using AI.

**Tasks:**

- **4.1** — Create `cron/ai_image_handler.php` — DALL-E API call + image download
- **4.2** — Wire image handler into `ai_worker.php` for `job_type = 'image'`
- **4.3** — Add image apply logic — save file, update scene `image` and `image_gen`; add `update_scene_image()` to `db_functions.php`
- **4.4** — Add AI image generation panel to the scene editor UI — prompt input + "Generate" button
- **4.5** — Wire "Generate" button → `api_jobs.php?action=create` → inline status message (not a flash — JS updates text in the panel)
- **4.6** — Change "Add Scene" to auto-create a blank DB row immediately and redirect to the edit form — required so a `sceneID` exists before the panel renders and the user can submit a job. Add a **Discard** button that deletes the blank row if the user cancels. Preserve the `is_new` flag through validation errors.


### Phase 5 — Level 2: AI Scene Generation

Authors can generate a complete scene (title, description, hint, choices) with AI.

**Tasks:**

- **5.1** — Create `cron/ai_scene_handler.php` — Claude API call with scene prompt
- **5.2** — Wire scene handler into `ai_worker.php` for `job_type = 'scene'`
- **5.3** — Add scene apply logic — create/update scene + choices
- **5.4** — Build the AI Scene Generator form in the editor
- **5.5** — Show story map context (existing scenes) on the generation form
- **5.6** — Wire "Generate Scene" button → `api_jobs.php?action=create` → flash "Job queued" message


### Phase 6 — Level 3: AI Full Story Generation

Authors can generate a complete branching story from a premise.

**Tasks:**

- **6.1** — Create `cron/ai_story_handler.php` — two-phase plan + write pipeline
- **6.2** — Wire story handler into `ai_worker.php` for `job_type = 'full_story'`
- **6.3** — Add full story apply logic — create story + all scenes + all choices
- **6.4** — Build the Full Story Generator page
- **6.5** — Add configuration form (premise, genre, tone, length, endings)
- **6.6** — Wire "Generate Story" button → `api_jobs.php?action=create` → flash "Job queued" message
- **6.7** — Optional: queue image jobs for each scene after story is generated


### Phase 7 — UI Redesign

Updated navigation bar and polished UI across all pages.

**Tasks:**

- **7.1** — Redesign `header.php` — my stories, create, explore, job queue link (with badge), user dropdown
- **7.2** — Add admin menu dropdown (gold button) for admin users
- **7.3** — Polish gallery cards with new stat icons and draft badges
- **7.4** — Polish summary page layout
- **7.5** — Polish editor pages for AI panels


#### Suggested Build Order

Phase 7 tasks can be interleaved at any point — they're cosmetic and don't block other work.

---


#### Testing Checkpoints

After each phase, verify:

- [ ] All new database functions work (manual test via PHP script or phpMyAdmin)
- [ ] No regressions — existing story creation, editing, playing, cloning all still work
- [ ] New UI elements render correctly on both desktop and mobile
- [ ] For AI phases: test with a real API call, verify the full submit → cron → poll → apply cycle
- [ ] Admin panel shows correct data for the new features


## Plan 2 — UI & Feature Enhancements

Continues from 1 (Phases 0–7). All phases below build on the completed foundation.


#### Guest User Convention

Any interactive element that requires a login (favouriting, rating, commenting) must still be **visible** to guests but **non-functional**. On click, redirect to `login.php`. Never silently fail or hide the element — guests should understand the feature exists and that logging in unlocks it. Read-only features (search, browse, play, view counts, average ratings) work for everyone.

---


### Phase 8 — Cosmetic Fixes & Quick Wins

Scattered low-effort improvements that can be verified in isolation.

**Tasks:**

- **8.1** — Add a favicon (create/source a small icon file, link in `<head>`)
- **8.2** — Header: widen container and increase logo size
- **8.3** — Gallery: change heading from "Choose a story to Play" to "Choose a story to Explore"
- **8.4** — Story editor: render scene descriptions as HTML instead of showing raw tags (use `innerHTML` in JS or strip-and-render server-side)
- **8.5** — Story editor: add visible gap/divider between scene cards in the scene list
- **8.6** — Job queue: collapse jobs older than 24 hours behind a "Past jobs" toggle link; recent jobs shown by default


### Phase 9 — Navigation & Account Restructuring

Streamline the header, move misplaced sections, and fix the live-polling bug.

**Tasks:**

- **9.1** — Badge: only render/show the red dot when unseen count > 0; hide it entirely when count is 0 (currently an empty dot is visible)
- **9.2** — Fix live job-status polling: `job_queue.php` job rows do not update without a full page refresh. Add a JS interval that re-fetches `api_jobs.php?action=list` every 5 s and updates status badges in-place while any job is `pending` or `running`; stops polling when all visible jobs are in a terminal state
- **9.3** — Remove **Generate** link from the main nav (it moves to the Create Story tab in Phase 14)
- **9.4** — Remove **My Stories** from the main nav; add it as the first item in the account dropdown (below the user name, above Account)
- **9.5** — Add **My Favourites** to the account dropdown as a link to `index.php?filter=favourites`
- **9.6** — Remove the **My Favourites** section from `account.php` (it now lives as a gallery filter)
- **9.7** — Remove the **Image Generation Quality** select and info button from `account.php`; update `update_user_api_keys()` and `get_user_by_id()` to drop the `openai_image_quality` column. Quality will be a per-request choice (Phases 13 & 14). During the interim, `ai_image_handler.php` falls back to the `OPENAI_IMAGE_QUALITY` site constant.
- **9.8** — Remove the redundant **Edit** button on the published-story editor view. Editing any scene already auto-creates a shadow draft; the standalone Edit button serves no additional purpose.


### Phase 10 — Gallery Enhancements

My Favourites as a first-class filter, story search, and interactive heart icons on cards.

**Tasks:**

- **10.1** — Add **My Favourites** filter tab alongside All Stories / My Stories; wire to `get_user_favorites()` already in `db_functions.php`
- **10.2** — Add a search bar between the filter tabs and the Create New Story button; search filters by story title and description via a `?q=` GET parameter; add a `search_stories()` function to `db_functions.php`
- **10.3** — Cards: double the size of the eye (views) icon
- **10.4** — Cards: show the heart icon as hollow/outlined by default; fill it red if the logged-in user has favourited the story. Pass a `$userFavouriteIDs` set from the server so no extra queries per card. Guests always see hollow hearts.
- **10.5** — Cards: make the heart icon clickable — toggle favourite via `api_social.php?action=toggle_favorite` and update the icon and count in-place without a page reload. Guests clicking the heart are redirected to `login.php`.


### Phase 11 — Summary Page Adjustments

Consolidate the favourite button and reposition the star rating.

**Tasks:**

- **11.1** — Remove the separate **Favourite** button next to Edit Story; the existing favourites count badge (heart + count) becomes the sole toggle button. Guests see the badge but clicking redirects to `login.php`.
- **11.2** — Move the star-rating widget to below the **Play Story** button. Layout: stars on one line, average rating in brackets; line below reads "You rated this X / 5" for logged-in users, "Log in to rate this story" for guests. Guest stars are non-interactive.


### Phase 12 — Story Editor — Scene Card Redesign

Scene cards show a thumbnail, metadata, and choices instead of just a title row.

**Tasks:**

- **12.1** — Redesign each scene card: thumbnail on the left (target ~300 × 300 px, configurable via a `SCENE_THUMB_SIZE` constant in `config.php`), scene title + first ~150 chars of description + choice list to the right
- **12.2** — Display the scene's choices on its card (choice text only, no edit controls) so the story structure is visible at a glance
- **12.3** — Make the thumbnail clickable: clicking opens a modal overlay showing the full-size image


### Phase 13 — Scene Editor Enhancements

Rich text editing for scene descriptions; inline AI image generation with quality control.

**Tasks:**

- **13.1** — Integrate **Quill** rich-text editor (loaded from CDN) for the Scene Description field; on save, store the HTML output; on load, initialise Quill with the stored HTML
- **13.2** — Replace the existing "Generate Scene Image with AI" panel's separate prompt area with an inline **Generate with AI** button placed directly beside the file-chooser button under Scene Image
- **13.3** — Add an image-quality dropdown (Low / Medium / High) with the pricing info button next to the Generate button. Pass the selected quality in `input_json` when creating the image job. Update `ai_image_handler.php` to read `$input['quality']` first, falling back to `OPENAI_IMAGE_QUALITY`.


### Phase 14 — Create Story — Tabbed AI Generator

New-story and edit-story-properties pages gain a two-step AI generation flow; generate_story.php is retired.

**Tasks:**

- **14.1** — Split the Create New Story / Edit Story Properties form into two tabs: **Create by Hand** (existing fields) and **Generate with AI**
- **14.2** — **Generate Story Properties** button (Tab 2): synchronous call to a new `api_ai_properties.php` endpoint that calls Claude and returns `{title, description, theme}` JSON; the form fields auto-fill with the result. User can click repeatedly to regenerate before committing.
- **14.3** — **Generate Scenes** button (Tab 2): submits an async `full_story` job using the current form values (title, description, theme from step 1 or hand-typed). Existing `generate_story.php` job-creation logic moves here; `generate_story.php` is removed and its nav link (already removed in Phase 9) is gone.
- **14.4** — Add **average scene word length** dropdown (50 / 100 / 200 words) to the Generate tab; pass value in `input_json`; update `ai_story_handler.php` prompt to respect the target word count
- **14.5** — Replace the "also generate images" checkbox with a quality dropdown: Don't generate / Low / Medium / High. Pass selection in `input_json`. Update `ai_apply.php` to read quality when queuing child image jobs.
- **14.6** — Add a **"Generate cover image"** dropdown (same Low/Medium/High options + info button) beside the Story Thumbnail file chooser on both tabs. Submits an image job with `scene_id = NULL` and a new `target = 'story_cover'` field; apply logic saves to `images/{storyID}/cover_ai_{ts}.png` and updates the story's `image` column.
- **14.7** — Add a **"Generate everything"** button (Tab 2) that chains 14.2 (properties) then immediately submits a scenes job — skipping the manual review step. Triggered with a single click; shows a spinner while properties are fetched synchronously, then queues the scenes job.
- **14.8** — Update job queue "Go to story" link for `full_story` jobs to point to `editor.php?storyID=X&action=view_story` (the Story Properties / overview page) instead of the scene list


### Phase 15 — Job Notification Grouping

Users get one notification when a story or scene is fully done — not one per child image job.

**Tasks:**

- **15.1** — Add `parent_job_id INT DEFAULT NULL` column to `ai_jobs` (migration SQL); add FK to same table; add `get_child_jobs()` and `update_parent_job_status()` helpers to `db_functions.php`
- **15.2** — When `ai_apply.php` queues child image jobs (for scene or full_story), set `parent_job_id` on each child to the originating job's `job_id`
- **15.3** — Badge (`api_jobs.php?action=unseen_count`): exclude jobs where `parent_job_id IS NOT NULL` — child image jobs never count toward the notification badge
- **15.4** — When a child image job completes, check if all siblings are done. If any sibling failed, set the parent's status to a new `completed_with_errors` value (shown as ⚠ in the job queue). If all succeeded, leave the parent as `completed`.
- **15.5** — Update `ai_jobs` `status` ENUM to include `'completed_with_errors'`; update `job_queue.php` to render this state as a yellow ⚠ badge with tooltip "Story generated — some images failed"
- **15.6** — On `job_queue.php`, show child image job rows nested/indented under their parent (collapsed by default), so users can inspect individual image failures without the failures polluting the top-level list


### Phase 16 — Play Page — Sticky Layout

Title and scene image remain on screen while long descriptions and choices scroll.

**Tasks:**

- **16.1** — Refactor `play.php` layout into two regions: a sticky header zone (title always centred at top + scene image in its configured position — left / right / top) and a scrollable content zone (description text + choice buttons)
- **16.2** — Implement the three sticky image positions using CSS: **top** (image below title, full width, content scrolls below); **left** / **right** (image fixed to its side, content scrolls in the remaining column). All three keep the title pinned at the very top.
- **16.3** — Ensure sticky layout degrades gracefully on mobile: on narrow screens, always stack title → image → scrollable content regardless of the configured layout


#### Suggested Build Order

Phase 16 has no dependencies beyond the base app and can be slotted in anywhere after Phase 8.


## Plan 3 — AI Integration Redesign


#### Overview

This plan replaces the earlier two-tab story properties redesign with a broader rework of
how AI is integrated across all editor pages. Key design principles:

- AI is opt-in and never blocks the manual workflow
- Story creation with AI is fully async (background job); the user is redirected immediately
- Scene AI uses a modal; scene thumbnail AI uses an inline expandable section
- Edit Story Properties is simplified — the only AI available is cover image regeneration
- The old tab structure, Generate Everything chain, and synchronous `api_ai_properties.php`
  flow are retired entirely

---


### Phase 17 — Admin Settings Table & Config Migration

Foundation phase. All subsequent phases that reference configurable values (model selection, pricing, job settings, site title) read from this table instead of `config.php`. Implement this before any other phase.

**Tasks:**

- **17.1** — Schema
- **17.2** — DB helper functions
- **17.3** — Settings bootstrap (`settings.php`)
- **17.3a** — Code migration sweep
- **17.4** — config.php cleanup
- **17.5** — Admin panel: Site Settings section


### Phase 18 — Story & Scene AI Integration Redesign

**AI toggle off:** Checkboxes hidden, all fields editable normally. Form saves manually.


#### Flows

##### Create Story with AI



##### Scene AI Modal



---


#### Task breakdown

##### 18.1 — Form field reorder

Reorder the story properties form fields on **both** Create New Story and Edit Story Properties:

**New order:** Theme → Layout → Story Title → Description → Story Thumbnail

This puts Theme and Layout directly below the AI settings section (when visible) so randomized
values are immediately adjacent to the dropdowns that show them.

##### 18.2 — Create New Story: AI toggle + settings section

- Add `[✨ AI]` toggle button to the right of the `<h3>Create New Story</h3>` heading
- Button style: filled purple with yellow/white text; lighter shade when active
- `toggleStoryAI()` in JS: shows/hides `#story-ai-section`; updates button appearance;
  shows/hides the "Use AI" checkboxes; persists state to `localStorage` key `cyoa_story_ai_open`
- `#story-ai-section` contains:
  - Genre, Tone, Number of Scenes, Number of Endings, Scene Word Length, Scene Image Quality dropdowns
  - Dice `🎲` button (tooltip `Randomize`) calling `randomizeStoryAI()`
- `randomizeStoryAI()` — sets all AI dropdowns **and** the Theme + Layout dropdowns in the main form;
  no API calls, no jobs

**Randomization pools:**


##### 18.3 — Create New Story: "Use AI" checkboxes

When `#story-ai-section` is visible:

- Show a `☐ Use AI` select-all checkbox above the first form field
- Show individual checkboxes in front of: Theme, Layout, Story Title, Description, Story Thumbnail
- Checking a field: sets its input to `readonly`, clears its value, sets placeholder to `generated`
- Unchecking a field: restores editable state, clears placeholder
- Select-all: checks/unchecks all individual checkboxes

**Thumbnail checkbox special behaviour:**
- If an image filename is already present when the thumbnail checkbox is checked:
  show a confirm dialog `This will replace the existing image. Continue?`
  - Cancel: leaves checkbox unchecked
  - OK: collapse `#story-thumb-ai-expand` if open; set filename field to readonly + gray;
    disable `[✨ AI]` button; placeholder `generated`
- When thumbnail checkbox is unchecked: restore filename field and `[✨ AI]` button

When `#story-ai-section` is hidden (AI toggled off): all checkboxes hidden, all fields editable.

##### 18.4 — Story Thumbnail `[✨ AI]` button (Create + Edit Story pages)

- Small `[✨ AI]` button placed immediately to the right of the filename input
- Tooltip: `Generate image with AI`
- Clicking toggles `#story-thumb-ai-expand` (inline expandable div)
- Expanded section contains:
  - Description textarea (placeholder `use story description`; when blank the job uses the
    story's saved description field as the image prompt)
  - Image quality dropdown
  - `[Generate Image]` submit button
- Expanded section has a distinct background shade (`var(--bg-light)` with a border)
- Submit queues an async `story_cover` image job; shows a small inline status message;
  does not navigate away

##### 18.5 — Create button: story creation + job queuing

On form submit when AI toggle is on:

1. Collect which checkboxes are checked (array of field names)
2. POST to a new `api_create_story_ai.php` endpoint:
   - All form field values (for context)
   - Checked fields list
   - AI settings (genre, tone, scene count, endings, word length, image quality)
3. Endpoint creates story record (`status = draft`), saves unchecked field values, leaves
   checked fields NULL, inserts `create_story` job row, returns `{ ok: true, storyID: X }`
4. JS shows `alert()` with submission message
5. On acknowledgement: `window.location = 'index.php'`

When AI toggle is off: form submits normally via POST (existing save_story handler); no change
to current create-story flow.

##### 18.6 — Edit Story Properties: simplify

- Remove all tab markup, AI dropdowns, premise textarea, and all Generate buttons
- Form contains only: Theme, Layout, Story Title, Description, Story Thumbnail (in that order)
- Thumbnail `[✨ AI]` button from task 18.4 is present
- Save button: unchanged, submits existing `save_story` POST handler

##### 18.7 — Scene pages: AI modal button

- Add `<button class="btn-ai-inline" id="btn-scene-ai">✨ AI</button>` to the right of the
  scene page `<h3>` heading
- Tooltip: `Generate With AI` (create) or `Regenerate with AI` (edit)
- Click handler `openSceneAIModal()`:
  1. Check if any scene field (title, description, choices, image) has non-default content
  2. If yes: show confirm dialog `This will overwrite the current scene content. Continue?`
     - Cancel: do nothing
     - OK: open modal
  3. If no existing content: open modal directly
- Modal (`#scene-ai-modal`) contains:
  - Scene Direction textarea
  - Mode dropdown (Continue Story / End Story)
  - Number of Choices dropdown
  - Tone dropdown
  - Include Image checkbox
  - `[Generate]` and `[Cancel]` buttons
- Generate: POST to `api_jobs.php` with scene generation parameters;
  on success redirect to `editor.php?action=edit_story&storyID=<?= $storyID ?>`
- AI replaces all scene content (title, description, choices, image) on completion

##### 18.8 — Scene Thumbnail `[✨ AI]` button (scene pages)

Same pattern as task 18.4 but for scene pages:

- `[✨ AI]` button to the right of the scene image filename selector
- Tooltip: `Generate image with AI`
- Expands `#scene-thumb-ai-expand` with: description (placeholder `use scene description`),
  quality dropdown, `[Generate Image]` button
- Submit queues an async scene image job; shows inline status; does not navigate away

##### 18.9 — New job type: `create_story`

New cron handler to process `create_story` jobs. Extend `ai_worker.php` to route this type.

**Job payload (stored in `jobs.input_json` JSON):**


**Handler phases (`cron/ai_story_handler.php` extended or new file):**

1. **Properties phase** (if `generate_fields` is non-empty):
   - Call Claude with genre + tone + context values → generate only the checked fields
   - Update story record with generated values

2. **Story plan phase:**
   - Call Claude with final title + description + AI settings → story outline (scene titles + summaries)

3. **Scene write phase:**
   - For each scene in the plan: write full scene content
   - Insert scene rows into DB

4. **Apply phase (`ai_apply.php` → `apply_create_story_result()`):**
   - Mark parent job complete
   - If `image_quality !== 'none'`: queue one `scene_image` child job per scene
   - If `generate_cover === true`: queue one `story_cover` child job

##### 18.10 — Remove old structure

Remove from `editor.php`:
- `.story-form-tabs` markup and the two tab wrapper divs
- `#ai-premise` textarea
- `Generate Story Properties`, `Generate Scenes`, `Generate Everything` buttons
- Any "how it works" explanation paragraph from the old Tab 2

Remove from editor JS:
- `switchStoryTab()`
- `generateProperties()`
- `generateScenes()`
- `generateEverything()`
- `randomizeAll()` → replaced by `randomizeStoryAI()` (task 18.2)

`api_ai_properties.php` — no longer called from the UI after this phase (new flow is async
via `api_create_story_ai.php`). Move to `retired/` — see task 18.12.

##### 18.11 — CSS updates

**Remove / deprecate:**
- `.story-form-tabs`, `.story-tab-btn` (keep in file but comment as deprecated)

**Add:**
- `.story-ai-section` — AI settings panel:
  `background: var(--bg-light); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 1.25rem; margin-bottom: 1.5rem;`
- `.story-ai-header` — flex row, space-between: label left, dice button right
- `.story-ai-dropdowns` — grid or flex-wrap of label+select pairs
- `.use-ai-checklist` — the checkbox column beside the form fields
- `.thumb-ai-expand` — expandable image AI section:
  `background: var(--bg-light); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 1rem; margin-top: 0.5rem;`
- `.btn-ai-inline` — the `[✨ AI]` pill button used beside headings and file inputs:
  small, outlined, purple accent
- `#scene-ai-modal` — modal overlay styles (fixed, dark backdrop, centered card)
- `.field-ai-checked` — applied to readonly AI-checked inputs: `opacity: 0.55; background: var(--bg-light);`

##### 18.12 — File retirement

Move the following files to a `retired/` subfolder at the project root. The folder acts as a
temporary holding area — files are kept for reference until all functionality has been confirmed,
then deleted.

| File | Reason |
|---|---|
| `api_ai_properties.php` | Replaced by async `api_create_story_ai.php` (task 18.5) |
| `generate_story.php` | Already superseded (redirects to `editor.php`); no longer needed |

Create the `retired/` folder if it does not exist. No code changes needed — these files are
simply moved, not modified.

---


#### What changes vs what stays the same

| Item | Status |
|---|---|
| `editor.php` story form — tab structure | **Removed** |
| `editor.php` — Create New Story form | **Reworked** (18.1–18.5) |
| `editor.php` — Edit Story Properties form | **Simplified** (18.6) |
| `editor.php` — Create/Edit Scene form | **Modal button added** (18.7–18.8) |
| `api_ai_properties.php` | **Retired** — moved to `retired/` (18.12) |
| `generate_story.php` | **Retired** — moved to `retired/` (18.12) |
| `api_jobs.php` | **Unchanged** (still used by scene modal) |
| `api_create_story_ai.php` | **New file** (18.5) |
| `cron/ai_worker.php` | **Minor: route new create_story type** |
| `cron/ai_story_handler.php` | **Extended: properties phase added** |
| `cron/ai_apply.php` | **Extended: apply_create_story_result()** |
| `generateProperties()` / `generateScenes()` / `generateEverything()` | **Removed** |
| `randomizeAll()` → `randomizeStoryAI()` | **Renamed + scoped to story create page** |
| `switchStoryTab()` | **Removed** |
| Scene generation job type + handlers | **Unchanged** |
| Cover image job type | **Unchanged** |
| Play page | **Unchanged** |
| `save_story` POST handler | **Unchanged** |

---


#### Files to change

| File | Change |
|---|---|
| `editor.php` | Rework story form (18.1–18.6), add scene AI modal + thumbnail buttons (18.7–18.8) |
| `styles/editor.css` | Remove tab styles, add AI panel + modal + thumbnail expand styles (18.11) |
| `api_create_story_ai.php` | **New** — create draft story record + queue create_story job |
| `cron/ai_worker.php` | Route `create_story` job type to new handler |
| `cron/ai_story_handler.php` | Add properties generation phase for `create_story` jobs |
| `cron/ai_apply.php` | Add `apply_create_story_result()` |

---


### Phase 19 — Header Redesign

Separate phase. No dependency on Phase 18. Reads `app_title` from `$SETTINGS` (set in Phase 17).


### Phase 20 — AI Job Cost Tracking

No dependency on Phase 18 or 19. Depends on Phase 17 (settings table provides active model and pricing reference). Phase 18's `create_story` job type picks up cost tracking automatically once its handlers call `db_update_job_cost()`.

**Tasks:**

- **20.1** — Schema
- **20.2** — Cost rate constants
- **20.3** — DB helper functions
- **20.4** — Log usage in cron workers
- **20.5** — Display in UI


### Phase 21 — Extract AI Prompts to External Files

Moves all system prompt text out of PHP handler files and into standalone `.txt` template files in a new `prompts/` directory. Prompts that contain dynamic values use `{PLACEHOLDER}` tokens that are substituted in PHP via `str_replace()`.


## Plan 4 — UI Polish, Social Enhancements & AI Improvements


#### Overview

This plan continues from 3 (Phases 17–22). Phase numbering runs 23–33.
Phase 22 (documentation) was deferred from 3 and is rolled into Phase 33 here.

Broad areas covered:

1. **UI / UX polish** — global modal system, gallery card overhaul, soft delete & trash, story tree view, job queue enhancements
2. **Story management** — multi-genre stories, clone relocation, editor tweaks, summary page draft access
3. **AI enhancements** — rate-limit retry with backoff, audience targeting, two-level image style selector, mood/lighting modifiers, per-story image settings, guardrails
4. **Admin / automation** — DB-driven genre + image style lists with chip editors, guardrails section, maintenance cron, log & trash retention settings

All phases assume 3 is complete and deployed.

---


### Phase 23 — Global Modal System

Replace all `alert()`, `confirm()`, and `prompt()` JavaScript calls site-wide with a shared modal component. This phase must come before all others that open modals (tree view, delete confirmations, clone confirms, etc.).

**Tasks:**

- **23.1** — `styles/modal.css`
- **23.2** — `modal.js`
- **23.3** — Sweep: replace `alert()` / `confirm()`
- **23.4** — Include in `header.php`


### Phase 24 — Soft Delete & Trash System

Introduces a `deleted` story status so that deleting a story moves it to a trash bin rather than removing it permanently. Owners can restore; admins can restore or permanently purge. Permanent deletion is handled automatically by the maintenance cron (Phase 29).

**Tasks:**

- **24.1** — Schema
- **24.2** — DB functions
- **24.3** — Exclude deleted stories from existing queries
- **24.4** — Delete action in `editor.php` and `summary.php`
- **24.5** — New `trash.php`
- **24.6** — Header changes in `header.php`


### Phase 25 — Gallery, Genre & Summary Page Improvements

Adds a `genre` column to stories, exposes it in creation/edit forms, and overhauls the gallery cards and filtering bar. The clone button moves from the gallery card to `summary.php`. `summary.php` is also fixed to stop auto-redirecting owners/admins to the editor so that draft stories have a proper landing page. The social section on `summary.php` is redesigned into a unified horizontal strip with larger icons and numbers, and the "Favorites" feature is renamed to "Likes" throughout the UI.

**Tasks:**

- **25.1** — Schema
- **25.2** — DB: genre in story CRUD
- **25.3** — Remove owner-redirect from `summary.php`
- **25.4** — Gallery card changes (`index.php`)
- **25.5** — Filter/sort bar in `index.php`
- **25.6** — Clone button on `summary.php`
- **25.7** — Genre field in story forms
- **25.8** — Summary page social strip redesign
- **25.9** — Rename "Favorites" to "Likes" (UI labels only)


### Phase 26 — Story Editor Tweaks

Small targeted changes to `editor.php`.

**Tasks:**

- **26.1** — Show story owner
- **26.2** — Back button
- **26.3** — "Set to Draft" button label
- **26.4** — Admin delete override


### Phase 27 — API Rate-Limit Retry

Adds a consistent retry-with-backoff wrapper around all AI API calls across the three cron handlers. Currently a 429 rate-limit response immediately fails the job; this phase gives each call up to 5 retries with a 20-second delay between attempts before giving up.

**Tasks:**

- **27.1** — `api_call_with_retry()` helper
- **27.2** — Update `cron/ai_image_handler.php`
- **27.3** — Update `cron/ai_scene_handler.php`
- **27.4** — Update `cron/ai_story_handler.php`
- **27.5** — Ensure `logs/` directory exists


### Phase 28 — DB-Driven Settings: Image Styles, Genres & AI Creator Overhaul

Extends the AI creator with audience targeting, a rich two-level image style selector, and mood/lighting modifiers. Moves image style options and the story genre list from hardcoded PHP arrays into admin-editable DB settings with chip-list editors. Upgrades stories from single-genre to multi-genre (JSON array). Stores chosen image settings on each story so inline cover and scene image panels can offer a “Use story settings” shortcut.

**Tasks:**

- **28.1** — Schema migrations
- **28.2** — DB settings: seed defaults
- **28.3** — Admin UI: list editors in `account.php`
- **28.4** — Replace hardcoded `$genreList` throughout the app
- **28.5** — DB function updates for multi-genre
- **28.6** — Multi-genre in `editor.php`
- **28.7** — Multi-genre display (`index.php` + `summary.php`)
- **28.8** — Audience dropdown in `editor.php` AI panel
- **28.9** — Image options row in `editor.php` AI panel
- **28.10** — Per-story image settings storage
- **28.11** — “Use story settings” checkbox in inline image panels
- **28.12** — Randomize function update
- **28.13** — Job input_data and cron handler updates
- **28.14** — Claude prompt updates for audience
- **28.15** — Image prompt composition update


### Phase 29 — AI Guardrails

Adds admin-configurable content guardrails to all AI generation. Claude is instructed to return a `red_flag` field when generated content breaches a guardrail topic; image prompts receive a "Do not depict" prefix. Breaches set the job to error and are logged.

**Tasks:**

- **29.1** — New admin settings
- **29.2** — Admin panel: Guardrails section
- **29.3** — Helper: `get_guardrail_clause()`
- **29.4** — Inject into Claude system prompts
- **29.5** — Inject into OpenAI image prompts
- **29.6** — Red flag detection in Claude handlers
- **29.7** — `log_guardrail_breach()` helper
- **29.8** — Apply red_flag check to all Claude handlers


### Phase 30 — Admin Maintenance Section

Adds a Maintenance section to the admin panel for controlling automatic cleanup of deleted stories and log files. A new `cron/maintenance.php` CLI script performs the actual work, invoked daily by the AI dispatcher.

**Tasks:**

- **30.1** — New admin settings
- **30.2** — Admin panel: Maintenance section
- **30.3** — Retention → SQL interval helper
- **30.4** — DB: `db_purge_deleted_stories(string $interval): array`
- **30.5** — `cron/maintenance.php`
- **30.6** — Update `cron/ai_dispatcher.php`


### Phase 31 — Story Tree View

Adds a "Tree View" button to the story editor that opens a modal containing an SVG-rendered, navigable tree of all scenes in the story. Nodes are clickable links to the scene editor.

**Tasks:**

- **31.1** — Data endpoint `api_tree.php`
- **31.2** — Tree layout and SVG renderer (`tree-view.js`)
- **31.3** — Modal trigger in `editor.php`
- **31.4** — Styles


### Phase 32 — Job Queue Enhancements

Upgrades `job_queue.php` with a stat dashboard (admin only), search/filter bar (all users), a "Clear Completed" button, and a job detail modal showing raw input/result JSON with syntax highlighting.

**Tasks:**

- **32.1** — Admin stat cards
- **32.2** — Search / filter bar
- **32.3** — "Clear Completed" button
- **32.4** — Job detail modal


## Plan 5 — Cosmetic Polish & Settings Rework

Cosmetic polish plus a re-work of the account/admin settings area. Source of truth:
`_dev/implementation/5/notes-5.txt`.

Phases continue from the 4 sequence. 5 runs **Phases 33–38** and contains **no documentation
phase** — the documentation update (which also absorbs 4's never-done Phase 33) now lives in
**6**, see `_dev/implementation/6/notes-6.md`.


#### Conventions for 5

- **No migration file.** Schema/data changes are applied **directly to the live DB**
  (`192.168.1.184`, db `evan`, prefix `cyoa_ai_`) during implementation. Record any schema change
  in the phase's progress notes; the `.claude/` schema/doc sync happens in the 6 documentation pass.
- Keep PHP procedural; vanilla JS (no framework); read runtime config via `app_setting()`.
- Smoke-test each phase in the browser before moving on; keep a `implementation-plan-5-progress`
  doc updated per phase (created when implementation starts).


### Phase 33 — Genre Backfill (data)

Populate `genre` for stories that currently have none, by analysing each story's title + description and assigning one or two genres from the managed `story_genres` list.

**Tasks:**

- **33.1** — Query the affected stories (`storyID, title, description`) from the DB.
- **33.2** — For each, pick 1–2 best-fit genres from `story_genres` based on its summary.
- **33.3** — `UPDATE cyoa_ai_stories SET genre = '<json>' WHERE storyID = ?` for each, writing a valid JSON array. Verify with a re-query (no rows left matching the "missing genre" predicate).


### Phase 34 — Account & Admin Settings Restructure

Split the monolithic `account.php` into focused pages and turn the header cog into a settings dropdown. This is the only non-cosmetic phase.

**Tasks:**

- **34.1** — Slim down `account.php` (all users)
- **34.2** — Header cog → settings dropdown (admin only)
- **34.3** — `settings_site.php` (admin)
- **34.4** — `settings_content.php` (admin)
- **34.5** — `settings_users.php` (admin)
- **34.6** — Input styling pass


### Phase 35 — Main Gallery Tweaks

`index.php` story cards + the "My Likes" label.

**Tasks:**

- **35.1** — Rename the **"My Likes"** filter button (and the matching item in the header user dropdown) to **"Favorites"**. Keep the `filter=likes` GET param unchanged (label only).
- **35.2** — Card: move the **star rating** to the **end** of the social strip (currently it sits between Views and Comments; it's shown dynamically, so order it last).
- **35.3** — Card: move the **genre tag(s)** onto the **same line as the social strip** — social strip **left-justified**, genre chip(s) **right-justified** (flex `justify-content: space-between`).
- **35.4** — Card: move the **"by {author}"** line to **directly below the title**, in a **smaller font** (it currently shares the meta line with the genre chips).


### Phase 36 — Summary Page Tweaks

`summary.php` social strip and header.

**Tasks:**

- **36.1** — Like label: show **"like"** when the count is 1, **"likes"** otherwise (update the static label set in Phase-prior work, and keep it in sync in the like JS).
- **36.2** — Replace the star widget's text with a compact display format: **`[avg] [solid stars][open stars] ([total ratings])`** — e.g. `3 ★★★☆☆ (1,222)`. - Stars render the **average** (filled up to rounded avg); total ratings shown with thousands commas. Stars remain clickable to submit a rating (hover preview retained); after rating, refresh avg + count from the server response. - **Remove** all rating status text: "Click to rate", "You rated n/5", "avg x (n)".
- **36.3** — Badges: **remove the theme badge**; show **genre badges only**.
- **36.4** — Remove the **draft notice line** between the breadcrumb and the summary card. Instead show a **published/draft tag above the title** — pastel **green** (published) / **red** (draft).


### Phase 37 — Story Editor & Tree View Tweaks

`editor.php` (story + scene views), the AI generation modals, and the tree-view modal width.

**Tasks:**

- **37.1** — Story editor: rename the **"Back to Landing Page"** button to **"Back to Story Summary"** (link target unchanged — `summary.php?storyID=…`).
- **37.2** — Scene editor: add a **Tree View** link/button next to the **"Back to story"** button (reuse the Phase-31 fetch → `TreeView.build` → `Modal.open` flow already wired in `editor.php`).
- **37.3** — Bug: the **AI scene-generation** modal shows **no image-style options** — it should surface the same image category / sub-style / mood controls used elsewhere. Investigate the inline image-style controls (`render_inline_image_style_controls()` / `window.inlineStyleParams`, Phase 28) and ensure they render in the scene-AI modal.
- **37.4** — Bug: the **AI image-generation** sub-style dropdown **does not populate** when a category is chosen (the category→sub-style cascade isn't wiring up). Fix the populate logic.
- **37.5** — Move the **"Generate Image"** button to **below** the image-style options in the image-generation modal.
- **37.6** — Tree View modal: the tree renders in only the **left half** of the near-full-width modal. Make the `.tree-container` fill the widened modal (currently capped at `min(86vw,1000px)` while `.modal-box:has(.tree-container)` is `92vw`) — align the container width to the modal.


### Phase 38 — Play Page Tweaks

`play.php` and `styles/play_layout.css`. **No theme files are touched in 5.**

**Tasks:**

- **38.1** — `layout-image_top` text alignment. Keep the **image and the text block centered on the page**, but **left-align the text inside the block**; the **choice buttons stay separate and centered**. - The block is already centered as a column (`.layout-image_top .play-layout { max-width:1400px; margin:0 auto }`). Constrain the text column so left-aligned text still reads centered under the image — e.g. give `.layout-image_top .content` a `max-width` (~70ch) + `margin: 0 auto`. - Set `.layout-image_top .content { text-align: left; }` for the scene title + body paragraphs. - Choices unchanged — they live in `.choices` (`justify-content: center`), separate from `.content`, and stay centered. - The current `text-align: center` is set in **two** places: `styles/play_layout.css` (line ~81) **and** each `themes/*_theme.css`. **Do the fix only in `play_layout.css`** — it's `<link>`-ed **after** the theme CSS in `play.php`, so an equal-specificity `text-align: left` there wins over the theme rule. This keeps 5 from touching any theme files (no theme changes in 5). - *(Detail to eyeball: whether the scene title looks better left or centered — start left per the note, easy to flip.)*
- **38.2** — `layout-image_top`: the image is vertically **centered** within its crop area; change the crop to anchor from the **top** (`object-position: top` / `object-fit: cover`). Done in `play_layout.css` only.
- **38.3** — Logged-in owner/admin: rename the top-right **"Edit"** button to **"Edit Scene"**.
- **38.4** — Add a second button to its **left**, **"Edit Story"**, linking to the story editor (`editor.php?storyID=…`).


#### Resolved decisions

1. **Profile Image panel** (34.1): **Keep** on `account.php` (Password + BYOK + Profile Image).
2. **Scene thumbnail sizes** (34.3): Small=140 / Medium=200 / Large=280 px, defined as a tunable
   reference array in **`config.php`**.
3. **Play themes**: **deferred to 6.** No theme changes in 5. The data-driven theme engine
   (one CSS-variable template + presets table + AI-generated per-story palettes, curated
   mood-tagged Google-font allow-list, user override) is captured in
   `_dev/implementation/6/notes-6.md`. The 5 image_top fixes are play-layout only.


## Plan 6 — Backend Cleanups & Theme Engine

Backend/AI cleanups plus the larger nice-to-haves parked after 5. **All 6 items are now specified
as phases below** — `notes-6.md` has been closed out (everything promoted here). `notes-6.txt`
remains the owner's raw scratchpad.

Phases continue from the 5 sequence (5 ran Phases 33–38). 6 starts at **Phase 39**.
The **Documentation Update stays last** so it can capture everything that ships.


#### Conventions for 6

- **No migration file.** Schema/data changes are applied **directly to the live DB**
  (`192.168.1.184`, db `evan`, prefix `cyoa_ai_`) during implementation. Record any schema change
  in the phase's progress notes; the `.claude/` schema/doc sync happens in the Documentation phase.
- Keep PHP procedural; vanilla JS (no framework); read runtime config via `app_setting()`.
- `config.php` holds **developer-tuned reference data** (constants/arrays like `AI_IMAGE_PRICING`,
  `SCENE_THUMB_SIZES`); admin-tunable runtime config goes in the `cyoa_ai_settings` table via
  `app_setting()`. UI copy says "the AI", not provider names, except provider-specific settings.
- Smoke-test each phase before moving on; create/keep an `implementation-plan-6-progress.md`
  updated per phase (created when implementation actually starts, per the 5 pattern).


### Phase 39 — Audience Instruction Lookup (prompt refactor)

Stop making the AI re-derive the audience complexity from an inline table; resolve it server-side and pass the specific instruction into the prompt.

**Tasks:**

- **39.1** — Add `STORY_AUDIENCES` to `config.php`.
- **39.2** — Add `resolve_audience()` (key → `['label', 'complexity']`, fallback `middle_grade`). Update `build_scene_writer_system_prompt()` and `build_plan_system_prompt()` to pass `audience_label` + `audience_complexity` placeholders (drop the raw `audience` token where the table was).
- **39.3** — Edit `prompts/story_scene_writer_system.txt` and `prompts/story_plan_system.txt`: replace the inline 5-row table + `{AUDIENCE}` with the two-line `{AUDIENCE_LABEL}` / `{AUDIENCE_COMPLEXITY}` form.
- **39.4** — Verify the **single-scene** path: `cron/ai_scene_handler.php` loads `scene_system` (a *different* prompt). Check whether `scene_system.txt` embeds the same audience table; if so, fold it into this refactor, otherwise leave it. - **39.5 (optional follow-up)** — Make `api_create_story_ai.php` `$allowedAudiences` and the `editor.php` dropdown/`AUDIENCES` array **derive from `STORY_AUDIENCES`** (keys = `array_keys`, labels = the map), so adding/renaming an audience is a one-place edit. Can be deferred.


### Phase 40 — Pagination (gallery + job history)

Three long lists need paging; do the two that matter now, leave users for later. Decisions are locked with the owner.


### Phase 41 — Curated Mood-Tagged Font Allow-List

A data foundation for Phase 42 (the theme engine): a vetted set of Google fonts the AI can pick from, each tagged by mood and **guaranteed to load**. Built once; reused by the engine's AI step, the play-page font `<link>`, and the editor's font picker. (Like the Phase 33 genre backfill, this is a small but distinct data-foundation phase — it gates Phase 42's AI font selection, so it lands first.)


### Phase 42 — Data-Driven Theme Engine

Replace the per-file play themes with a single CSS-variable template driven by stored values — enabling unlimited looks, AI-generated per-story palettes, and full user override. **Builds on the Phase 41 font allow-list.**

## Plan 7 — Story Image Gallery

### Phase 44 — Story Image Gallery

A per-story image gallery — a companion to the Tree View — that shows the cover image
first, then every scene that has artwork (scenes without an image are skipped). A
site-wide gallery was deferred, but the data layer is built behind a reusable seam so a
global mode can be added later without a rewrite.

**Tasks:**

- **44.1** — Data seam `get_gallery_items($storyID)` in `db_functions.php`: returns an
  ordered list (cover first, then scenes with images), resolving shadow-draft image
  folders the same way the summary and tree views do. This single function is the
  extension point a future global gallery would build on.
- **44.2** — Standalone `gallery.php` page (owner/admin only) that renders the tile grid
  server-side and embeds the items as JSON for the lightbox; breadcrumb and back link are
  driven by a `?from=editor|summary` parameter.
- **44.3** — `styles/gallery.css`: a theme-aware tile grid with preset Small/Medium/Large
  sizing, raised tiles, single-line ellipsis captions, and a lightbox with edge chevrons
  and a bottom filmstrip.
- **44.4** — `gallery.js`: lightbox open/close, prev/next with wrap-around, keyboard
  control (←/→/Esc), and a clickable filmstrip.
- **44.5** — Entry points from the Story Editor and the Summary page, plus a new
  preset-size "Gallery" block in Site Settings.

## Documentation

A final documentation pass kept the steering documents — the `.claude/` references and the
project's `CLAUDE.md` rule book — in sync with everything that shipped. It was scheduled
**last** in each round so it only ever described features that actually landed, and it
involved no application-code changes. Because it was deferred and renumbered several times
(first Phase 22, then Phase 33, finally Phase 43), it is shown here once.

**Tasks:**

- **D.1** — Apply the documentation items deferred from earlier rounds (image paths, job
  types, result application, and the DB-settings migration).
- **D.2** — Update the architecture document: soft delete / trash and maintenance, genres,
  the modal system, admin settings and guardrails, the tree view, the settings split and
  cog dropdown, audience lookup, pagination, and the font allow-list and theme engine.
- **D.3** — Update the database-schema document: new columns (`date_deleted`, `genre`,
  `ai_image_*`), the new settings rows, the genre backfill, and any theme tables/columns.
- **D.4** — Update the API-endpoints document (`api_tree.php`, the job-detail action, and
  the content-settings endpoint).
- **D.5** — Update `CLAUDE.md`: the Key Files table, the account/settings split, the
  reference-data constants, and the `logs/` and `prompts/` directory notes.

<script>
(function () {
  function addArrows() {
    document.querySelectorAll('.page h2, .page h3').forEach(function (h) {
      if (h.querySelector('.uptop')) return;
      var a = document.createElement('a');
      a.href = '#report-header';
      a.className = 'uptop';
      a.title = 'Back to top';
      a.setAttribute('aria-label', 'Back to top');
      a.innerHTML = '&uarr;';
      h.appendChild(a);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addArrows);
  else addArrows();
})();
</script>
