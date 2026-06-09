# Phase 43 (revised) — Documentation Update (runs last)

> Supersedes the Phase 43 section in `_offline/implementation/6/implementation-plan-6.md`.
> That version was written mid-6 and is now stale: it predates the **7 gallery**, the
> **`_dev` → `_offline`** folder rename, and a run of small "vibe" changes made directly in
> code without a progress entry. This revision re-grounds the phase in the **actual current
> file inventory** and corrects two errors in the old plan (see "Corrections" below).

Bring the `.claude/` steering docs and `CLAUDE.md` current with everything that has actually
shipped (3–7 + the untracked changes). **No app code changes in this phase** — documentation only.
Document only what exists in the code *right now*; when a doc claims something the code no longer does,
fix the doc to match the code (not the reverse).

## Corrections to the old Phase 43 plan
- **There is no `.claude/database-schema.md`.** The schema lives in **`.claude/cyoa_ai_db_schema.sql`**
  (a phpMyAdmin dump that lags the live DB). The schema task is therefore "re-export the SQL dump from
  the current database" + capture the column/settings changes in prose in `architecture.md`.
- **`.claude/ai-prompts.md` exists and was omitted** from the old scope. Prompt construction changed
  materially (theme removed from image *and* scene prompts; genre line added; premise-seed system), so
  this doc needs a pass too.

## Current `.claude/` doc set (what we're updating)
| File | Status going in |
|---|---|
| `.claude/architecture.md` | Lags from ~3; missing 4–7 + vibe changes |
| `.claude/api-endpoints.md` | Missing several endpoints |
| `.claude/ai-prompts.md` | Prompt logic changed (theme removal, genre, seeds) |
| `.claude/cyoa_ai_db_schema.sql` | Stale dump — re-export from live DB |
| `CLAUDE.md` | Key Files table + steering-doc paths out of date |

---

## What shipped since the 6 plan was written (the documentation backlog)

### 7 — Per-story image gallery (`_offline/implementation/7/implementation-plan-7.md`)
- New files: **`gallery.php`**, **`gallery.js`**, **`styles/gallery.css`**.
- Data seam **`get_gallery_items($storyID)`** in `db_functions.php` (cover-first, then scenes with
  images; shadow-draft-folder aware). Architected so a future global gallery reuses it.
- Settings block (`gallery_tile_size`, `gallery_filmstrip_size`, `gallery_tile_spacing`) — preset
  maps in `config.php`, defaults in `settings.php`, persisted via `settings_site.php`.
- Entry points: "Gallery" button in `editor.php` (Scenes header) and `summary.php`.
- The gallery lightbox is **also reused** in `editor.php` to enlarge cover/scene thumbnails.

### Untracked "vibe" changes (no progress entry — confirm against code)
- **AI story creation — theme is now strictly visual.** Theme (colours/fonts) was removed from *both*
  story-text and image generation. `{THEME_LINE}` removed from `prompts/image_system.txt` /
  `cover_image_system.txt`; scene prompt drops `Theme:` and adds `Genre:`.
- **Auto-publish on AI create** — `publish` checkbox in the create form; story auto-publishes only if
  the parent job **and all image children** succeed, and only when images were included
  (`maybe_publish_created_story()` in `db_functions.php`, applied via `cron/ai_apply.php`).
- **"Any" defaults + in-code randomization** — Genre/Tone/Audience dropdowns default to "Any"; the
  server resolves these to concrete random values (`ai_resolve_story_params()`, `ai_pick_seed()`,
  `ai_random_image_style()`). Premise seeds in **`data/premises.json`**; blank premise → seed.
- **Single combined image-style dropdown** — category + sub-style merged into one grouped (`optgroup`)
  select everywhere; category is now only a fallback, sent empty for manual picks.
- **Randomizer restrictions** — quality ≠ high; tone ≠ dark for picture-book/early-readers; scene
  length 50 for those audiences; endings synced to scene count.
- **Dropdown alphabetical sorting** (this session) — genre filter (Other pinned last), Add Genre,
  image styles within category, moods, theme presets, fonts, tone. Display-only sorts in
  `index.php` / `editor.php`.
- **AI title/description override fix** (this session) — `api_create_story_ai.php` now seeds the story
  with the user's own title/description when AI isn't generating those fields.
- **Multi-pass dispatcher** — `cron/ai_dispatcher.php` runs several passes per invocation
  (`$DISPATCH_PASSES` / `$DISPATCH_INTERVAL`) to react faster under once-a-minute host cron.
- **Story export/import CLI** (this session) — `cli/export_story.php` + `cli/import_story.php` move a
  story (rows + scenes + choices + images, with ID remap) between instances.

### Files that exist but are NOT in CLAUDE.md's Key Files table
`gallery.php`, `data.php`, `fonts.php`, `theme.php`, `trash.php`, `pagination.php`,
`job_history.php`, `job_render.php`, `api_content.php`, `api_tree.php`,
`settings_site.php`, `settings_content.php`, `settings_users.php`,
`cron/maintenance.php`, `cron/ai_helpers.php`, `mail_helper.php`,
`cli/export_story.php`, `cli/import_story.php`,
data files (`data/audiences.json`, `data/play_fonts.json`, `data/themes.json`, `data/premises.json`),
JS (`modal.js`, `tree-view.js`, `gallery.js`, `job_detail.js`).

---

## Scope by document

### 43.1 — `.claude/architecture.md`
Bring current for 3–7. Add/refresh sections for:
- **Social + trash:** soft delete (`date_deleted`) + `trash.php`; `cron/maintenance.php` daily cleanup.
- **Settings split:** `account.php` vs `settings_site.php` / `settings_content.php` /
  `settings_users.php`; `app_setting()` over the `cyoa_ai_settings` table; cog dropdown.
- **Data-driven layer (`data.php` + `data/*.json`):** premises (seeds), audiences, themes, play-fonts.
- **Theme engine (`theme.php`, `fonts.php`):** `theme_json`, presets, the font allow-list, sanitization
  gate, `styles/play_theme.css` `color-mix()` derivation. **State the theme = visual-only rule.**
- **AI pipeline as built:** job lifecycle (`jobs` table, dispatcher multi-pass → worker → handler →
  `ai_apply.php`), parent/child image jobs, `check_and_finalize_parent()`, **auto-publish** gate,
  guardrails/`red_flag`, cost tracking, tree view, **gallery** (`get_gallery_items`).
- **Cross-instance transfer:** the export/import bundle format + ID-remap approach.

### 43.2 — `.claude/cyoa_ai_db_schema.sql`
- **Re-export the dump from the live DB** (phpMyAdmin → Export, or `mysqldump`) so it reflects current
  columns: `stories.date_deleted`, `stories.genre` (JSON/TEXT), `stories.theme_json`,
  `stories.ai_image_*`, `stories.published_story_id`, and the full `cyoa_ai_settings` rows
  (guardrails_*, trash/log retention, `gallery_*`, image format, etc.).
- Add a dated header comment noting the export date so future drift is obvious.

### 43.3 — `.claude/api-endpoints.md`
- Add/verify: `api_create_story_ai.php`, `api_jobs.php` (status/unseen/cancel/retry/detail),
  `api_social.php`, `api_tree.php`, `api_content.php`. Note these are session-auth AJAX, POST-for-mutation.

### 43.4 — `.claude/ai-prompts.md`
- Update prompt construction: **theme removed** from image + scene prompts; **genre line added** to
  scene prompt; premise-seed resolution; the three text phases (plan → scene writer → apply); the
  image/cover system templates as they now read (`prompts/*.txt`).

### 43.5 — `CLAUDE.md`
- **Key Files table:** add every file in the "NOT in CLAUDE.md" list above, grouped sensibly.
- **Steering Documents section:** fix paths `_dev/implementation/` → **`_offline/implementation/`**;
  add the **7** plan and **this revised Phase 43**; correct `database-schema.md` reference to
  `cyoa_ai_db_schema.sql`; mention `ai-prompts.md`.
- **Conventions/AI notes:** theme = visual-only; "Any" + in-code randomization; auto-publish rule;
  data-driven `data/*.json`; dispatcher multi-pass; export/import CLI.
- Note the `logs/`, `prompts/`, `data/`, `themes/` directories.

---

## Task breakdown
1. **43.0 Reconcile pass** — because the vibe changes aren't tracked, walk the code once and confirm each
   bullet above against the source before writing (docs must match code, not this plan).
2. **43.1** architecture.md.
3. **43.2** re-export cyoa_ai_db_schema.sql + dated header.
4. **43.3** api-endpoints.md.
5. **43.4** ai-prompts.md.
6. **43.5** CLAUDE.md (Key Files, steering paths, conventions).
7. Final cross-check: open each `.claude/` doc and `CLAUDE.md`, grep for any remaining `_dev/`,
   `database-schema.md`, theme-in-prompt, or two-dropdown image-style references and fix.

## Files
| File | Change |
|---|---|
| `.claude/architecture.md` | Bring current (3–7 + vibe changes) |
| `.claude/cyoa_ai_db_schema.sql` | Re-export from live DB; dated header |
| `.claude/api-endpoints.md` | Add/verify all current endpoints |
| `.claude/ai-prompts.md` | Theme removal, genre line, seeds, phases |
| `CLAUDE.md` | Key Files table; `_offline/` paths; conventions |

## Out of scope
- No app code changes. If the reconcile pass uncovers a *bug* (not just a doc gap), log it separately —
  don't fix it inside the documentation phase.
- Global/site-wide gallery (still deferred per 7).
