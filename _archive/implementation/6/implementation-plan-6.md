# Implementation Plan 6 — Backend Cleanups & Theme Engine

Backend/AI cleanups plus the larger nice-to-haves parked after 5. **All 6 items are now specified
as phases below** — `notes-6.md` has been closed out (everything promoted here). `notes-6.txt`
remains the owner's raw scratchpad.

Phases continue from the 5 sequence (5 ran Phases 33–38). 6 starts at **Phase 39**.
The **Documentation Update stays last** so it can capture everything that ships.

## Conventions for 6

- **No migration file.** Schema/data changes are applied **directly to the live DB**
  (`192.168.1.184`, db `evan`, prefix `cyoa_ai_`) during implementation. Record any schema change
  in the phase's progress notes; the `.claude/` schema/doc sync happens in the Documentation phase.
- Keep PHP procedural; vanilla JS (no framework); read runtime config via `app_setting()`.
- `config.php` holds **developer-tuned reference data** (constants/arrays like `AI_IMAGE_PRICING`,
  `SCENE_THUMB_SIZES`); admin-tunable runtime config goes in the `cyoa_ai_settings` table via
  `app_setting()`. UI copy says "the AI", not provider names, except provider-specific settings.
- Smoke-test each phase before moving on; create/keep an `implementation-plan-6-progress.md`
  updated per phase (created when implementation actually starts, per the 5 pattern).

## Phase index

| Phase | Title | Spec |
|---|---|---|
| 39 | Audience Instruction Lookup (prompt refactor) | **this doc** |
| 40 | Pagination — gallery + job history | **this doc** |
| 41 | Curated Mood-Tagged Font Allow-List | **this doc** |
| 42 | Data-Driven Theme Engine | **this doc** |
| 43 | Documentation Update (**last**) | **this doc** |

> All phases (39–43) are fully specified below. **Phase 41 (font allow-list) is a data foundation that
> gates Phase 42's AI font selection, so it lands first; Phase 43 (Documentation) runs last** so it can
> capture everything that ships. Order/numbers for 41–42 may still shift.

---

## Phase 39 — Audience Instruction Lookup (prompt refactor)

Stop making the AI re-derive the audience complexity from an inline table; resolve it server-side
and pass the specific instruction into the prompt.

### Why
- The full-story prompts hand the model the audience **key** (e.g. `middle_grade`) **plus the full
  five-row complexity table**, and ask it to do its own lookup. Only one row is ever relevant, so
  every call wastes tokens and adds a needless reasoning step.
- That table is **duplicated** in both `prompts/story_plan_system.txt` and
  `prompts/story_scene_writer_system.txt`, and the audience **keys** are independently hardcoded in
  `api_create_story_ai.php` (`$allowedAudiences`) and `editor.php` (the dropdown + JS `AUDIENCES`
  array) — five places, no single source of truth.
- Not a correctness bug (a capable model resolves it fine) — this is efficiency + maintainability.

### Design
- Add a reference map to **`config.php`** (dev-tuned data, same justification as `SCENE_THUMB_SIZES`):
  ```php
  const STORY_AUDIENCES = [
      'picture_book'  => ['label' => 'Picture book',         'complexity' => 'Very simple words, 1-2 sentences per scene, concrete imagery only'],
      'early_readers' => ['label' => 'Early readers (6-9)',  'complexity' => 'Simple vocabulary, short sentences, relatable characters'],
      'middle_grade'  => ['label' => 'Middle grade (9-12)',  'complexity' => 'Moderate complexity, relatable themes'],
      'young_adults'  => ['label' => 'Young adults (13-18)', 'complexity' => 'Nuanced themes, more complex choices'],
      'adults'        => ['label' => 'Adults',               'complexity' => 'Full complexity; mature themes acceptable'],
  ];
  ```
- Resolve the audience server-side (small helper, e.g. `resolve_audience(string $key): array` in
  `ai_story_handler.php` or a shared include), **falling back to `middle_grade`** for an unknown key
  (matches the existing default).
- The prompt templates collapse from the table to:
  ```
  Audience: {AUDIENCE_LABEL}
  Adjust language, vocabulary, and complexity to match: {AUDIENCE_COMPLEXITY}
  ```

### Task breakdown
- **39.1** — Add `STORY_AUDIENCES` to `config.php`.
- **39.2** — Add `resolve_audience()` (key → `['label', 'complexity']`, fallback `middle_grade`).
  Update `build_scene_writer_system_prompt()` and `build_plan_system_prompt()` to pass
  `audience_label` + `audience_complexity` placeholders (drop the raw `audience` token where the
  table was).
- **39.3** — Edit `prompts/story_scene_writer_system.txt` and `prompts/story_plan_system.txt`:
  replace the inline 5-row table + `{AUDIENCE}` with the two-line `{AUDIENCE_LABEL}` /
  `{AUDIENCE_COMPLEXITY}` form.
- **39.4** — Verify the **single-scene** path: `cron/ai_scene_handler.php` loads `scene_system`
  (a *different* prompt). Check whether `scene_system.txt` embeds the same audience table; if so,
  fold it into this refactor, otherwise leave it.
- **39.5 (optional follow-up)** — Make `api_create_story_ai.php` `$allowedAudiences` and the
  `editor.php` dropdown/`AUDIENCES` array **derive from `STORY_AUDIENCES`** (keys = `array_keys`,
  labels = the map), so adding/renaming an audience is a one-place edit. Can be deferred.

### Smoke test
- Queue a full-story job and a scene; confirm the rendered system prompt contains the resolved
  `label` + `complexity` line, **no leftover `{AUDIENCE*}` tokens**, and **no 5-row table**.
- Confirm an unknown/blank audience falls back to middle grade (no fatal, sensible output).

### Files
| File | Change |
|---|---|
| `config.php` | `STORY_AUDIENCES` reference map |
| `cron/ai_story_handler.php` | `resolve_audience()`; pass `audience_label`/`audience_complexity` in the two builders |
| `prompts/story_scene_writer_system.txt` | Replace audience table with `{AUDIENCE_LABEL}` / `{AUDIENCE_COMPLEXITY}` |
| `prompts/story_plan_system.txt` | Same replacement |
| `prompts/scene_system.txt` *(verify)* | Fold in only if it has the same table (39.4) |
| `api_create_story_ai.php`, `editor.php` *(optional, 39.5)* | Derive audience list/labels from the map |

> **Prompt scan result (informs 39.4):** a sweep of all `prompts/*.txt` confirmed the audience table
> is the only strong "AI does its own lookup" case, and it lives **only** in `story_plan_system.txt`
> and `story_scene_writer_system.txt`. `scene_system.txt` has **no** audience table (so 39.4 is a
> no-op for audience). `ending_type` (success/death) in `scene_system.txt` is the same shape but only
> 2 values, single file, entangled with the JSON rules — **considered & rejected** (not worth it).
> `suggested_theme`'s hardcoded enum is owned by **Phase 42** (theme engine), not here.

---

## Phase 40 — Pagination (gallery + job history)

Three long lists need paging; do the two that matter now, leave users for later. Decisions are locked
with the owner.

### Decisions
| Surface | Treatment |
|---|---|
| Main gallery (`index.php`) | Classic **numbered pagination**, rendered **both above and below** the grid |
| Job queue (`job_queue.php`) | **No pager**; show **today's jobs only**; a **"Past Jobs"** button below the table links to a **new `job_history.php`** with numbered pagination |
| User list (`settings_users.php`) | **Unchanged** for now (out of scope) |

### 40.1 — Gallery numbered pager (top + bottom)
- Numbered pager mirrored **above and below** the grid so a long page is navigable without scrolling back up.
- **Preserve active query params** (`genre`, `sort`, `filter` all/mine/likes, `q`); add `&page=N`; clamp out-of-range.
- Page size from a `cyoa_ai_settings` row `gallery_page_size` (default ~12, lands on clean grid rows) via `app_setting()`.
- Push `LIMIT ?, ?` **+ a matching COUNT** into the story-list functions (`get_all_stories` and the mine/likes/search variants). Array-slice fallback only if reworking all queries proves fiddly. (The COUNT is still needed for total-pages math even without a count line.)
- **No "Showing X–Y of N" line** — the numbered pagers (top + bottom) already convey position, and the search view already shows its own result count. Keep the gallery header clean.

### 40.2 — Job queue split + Job History page
- `job_queue.php`: filter the listing to **jobs created today** in `APP_TIMEZONE` (compare on the created timestamp, not UTC). Keep polling/retry/cancel for today's rows.
- Add a **"Past Jobs"** button below the table that **navigates** to the new page (not an inline unhide).
- **New `job_history.php`:** all jobs **before today**, newest-first, classic numbered pagination. **Drift acceptable** (a job crossing midnight shifting pages is fine — owner confirmed), so offset `LIMIT ?, ?` + COUNT is fine; no keyset needed. Reuse the jobs-list DB function with a date predicate + LIMIT rather than duplicating query logic.
- Fold in the owner's `notes-6.txt` item: rename the **"Time To Complete"** column → **"Completed"** on the job views while here.

### 40.3 — User list (unchanged)
- Keep all-rows render + client-side search/filter. Revisit (server-side filter + offset pager) only if the user count grows.

### Shared / implementation
- One reusable **`render_pager($currentPage, $totalPages, $baseParams)`** helper used by the gallery and Job History; merges/preserves `$baseParams` (genre/sort/filter/q) into each link; truncates long ranges (`« 1 … 4 5 [6] 7 8 … 20 »`).
- New `.pager` style block (vanilla CSS, theme-variable driven).
- **Accessibility:** `<nav aria-label="Pagination">`, current page `aria-current="page"`, disabled Prev/Next at the ends as non-focusable.
- Paging is GET navigation (PRG holds).

### Task breakdown
- **40.1** Gallery pager (top+bottom) + story-list `LIMIT`/`COUNT` + `gallery_page_size` setting.
- **40.2** `job_queue.php` today-filter + "Past Jobs" button + "Completed" rename; new `job_history.php`; jobs-list date predicate + `LIMIT`/`COUNT`.
- **40.3** No-op (users unchanged) — recorded for completeness.
- **40.4** Shared `render_pager()` helper + `.pager` styles.

### Files
| File | Change |
|---|---|
| `index.php` | Gallery pager (top + bottom) |
| `db_functions.php` | Story-list + jobs-list `LIMIT`/`OFFSET`/`COUNT`; `render_pager()` helper (or shared include) |
| `job_queue.php` | Today-only filter; "Past Jobs" button; "Completed" rename |
| `job_history.php` | **New** — paginated history |
| `header.php` | Optional Job History link |
| `styles/` | `.pager` component |
| `cyoa_ai_settings` (DB) | `gallery_page_size` (+ optional `jobs_history_page_size`) |

---

## Phase 41 — Curated Mood-Tagged Font Allow-List

A data foundation for Phase 42 (the theme engine): a vetted set of Google fonts the AI can pick from,
each tagged by mood and **guaranteed to load**. Built once; reused by the engine's AI step, the
play-page font `<link>`, and the editor's font picker. (Like the Phase 33 genre backfill, this is a
small but distinct data-foundation phase — it gates Phase 42's AI font selection, so it lands first.)

### Why its own phase
- **Prerequisite** — nothing font-related in Phase 42 (AI font choice, play-page font link, editor font dropdown) works until this exists.
- **Distinct deliverable + skillset** — data curation + a validation tool, separable from the engine's CSS/DB/UI code.
- **Independently testable** — every family+weight resolves before any engine code depends on it.

### The constraint that shapes it
For the runtime `<link>` to load, the **family name** and **requested weights** must match Google's
catalog *exactly* — a typo silently falls back to a system font. So the process is: source
guaranteed-valid families+weights first, then layer editorial mood/role tags on top.

### Task breakdown
- **41.1 — Pull the catalog.** Fetch the Google Fonts Developer API once (`webfonts/v1/webfonts`, free key) → authoritative families with `category`, available `variants` (weights), and subsets. (Or hand-browse fonts.google.com; the API just makes it scriptable.) This is the definitive source of valid names — eliminates hallucinated families up front.
- **41.2 — Shortlist ~30–50 across moods.** Editorial pick spanning the target moods/genres (horror, fantasy, sci-fi, elegant, playful, retro, handwritten, neutral/literary, …). Claude may *propose* candidates per mood to speed this up, but **every proposed family is checked against the 41.1 catalog and rejected if it isn't real** (the hallucination guard, applied at curation time).
- **41.3 — Tag each entry.** Per font: `family`, `category`, `weights` to load (lean — usually `400;700`), `moods[]`, a **`role`** (body-safe vs heading/display-only), and a CSS `fallback` stack. Shape:
  ```php
  ['family'=>'Cinzel','category'=>'serif','weights'=>'400;700','moods'=>['fantasy','elegant','historical'],'role'=>'heading','fallback'=>'serif'],
  ['family'=>'Lora','category'=>'serif','weights'=>'400;700','moods'=>['neutral','literary'],'role'=>'body','fallback'=>'serif'],
  ```
- **41.4 — Store as data.** A `config.php` array (or `data/play_fonts.json`), per the dev-reference-data convention (`SCENE_THUMB_SIZES`, `STORY_AUDIENCES`). An admin editor / DB table is the later upgrade if non-devs need to extend it.
- **41.5 — Validate before ship.** A one-off check script: for each entry, HEAD the actual CSS URL (`https://fonts.googleapis.com/css2?family=Family:wght@…`) and flag any that don't resolve — catches typo'd families / unavailable weights before a story can render broken.

### Output / contract consumed by Phase 42
- A **`role`-aware** list so the engine uses an expressive pick for `--font-heading` and a readable pick for `--font` (body) — many display fonts (Creepster, …) are unreadable as body text.
- A `moods → families` view for the AI prompt, plus a membership check + per-mood default for server-side validation.

### Decisions captured
- Font freedom: **curated mood-tagged allow-list** (confirmed) — open-ended *feel* for the AI, but every option is a real, vetted family.

### Files
| File | Change |
|---|---|
| `config.php` (or `data/play_fonts.json`) | **New** curated font allow-list data (family / category / weights / moods / role / fallback) |
| one-off build/validate script (e.g. `_dev/`) | **New** — catalog-pull helper + Google Fonts URL validator |

---

## Phase 42 — Data-Driven Theme Engine

Replace the per-file play themes with a single CSS-variable template driven by stored values — enabling
unlimited looks, AI-generated per-story palettes, and full user override. **Builds on the Phase 41 font
allow-list.**

### Why
- Per-file themes (`themes/egyptian_theme.css`, …) don't scale: every new look = a new file, and naming gets awkward with many palettes. Today the theme is also *semantic* — `ai_story_handler.php` asks the AI for a `suggested_theme` validated against a fixed 5-word list, capping the AI at 5 looks.
- Make a theme a small set of **values** (font + a few colours) → unlimited looks, AI palettes, user override; the naming problem disappears (presets have names; custom/AI themes don't need one).

### Architecture
- **One template** `styles/play_theme.css` written entirely against CSS custom properties: `--bg`, `--text`, `--accent`, `--font` (+ optional `--font-heading`, `--base-size`). Everything bespoke in today's files — hover glow, hint/tooltip surface, scrollbar, button-text contrast — is **derived** from those few values with `color-mix()` (surface = mix(bg,text); glow = accent low-alpha; button text = contrast pick). Small data, still looks hand-tuned.
- `play.php` injects the resolved values as an inline `:root { --bg:…; --accent:… }` block and builds the Google Fonts `<link>` from the chosen family (a **Phase 41 list entry** → exact family + weights). Use the entry's `role` so an expressive font drives `--font-heading` while body keeps a readable `--font`.
- **Data model:** store the resolved value set as `theme_json` on the story (nullable → default preset). A `cyoa_ai_themes` presets table seeds the picker only; the story holds the final values regardless of source (preset / AI / user tweak) → trivial to render, no join at play time.
- **Override UI:** a theme editor in the story editor — font dropdown (sourced from the **Phase 41 list**, with preview) + colour pickers (text/bg/accent) + live preview, pre-filled from a preset or the AI's choices, saved to `theme_json`.

### AI integration
- The story-plan response returns a **theme object** instead of a `suggested_theme` word, e.g. `"theme": { "font":"<family>", "bg":"#0a0a0f", "text":"#e0d6c0", "accent":"#b71c1c" }` (horror → gothic font + blood-red accent on near-black; comedy → playful font + bright accent; etc.).
- The AI picks `font` **from the Phase 41 allow-list** (the prompt gives it the mood-tagged options). Server-side, validate the returned family is in the list; if not, fall back to that mood's default.

### Must-get-right (risks)
1. **Font reliability** — solved by the **Phase 41** allow-list; reject off-list, fall back to a default per mood.
2. **Readability/contrast** — validate a minimum text-vs-bg contrast ratio (WCAG-ish); auto-darken/lighten or fall back to a safe default.
3. **CSS/style injection** — values are injected into `<style>`/inline CSS = an injection surface. Hard-validate: **hex colours only** (regex), **font from the allow-list only**, numeric sizes clamped. Never pass raw AI/user strings into CSS.
4. **Loss of bespoke flourishes** — a variable template won't perfectly reproduce every hand-tuned detail in the 5 files; it captures the essence + derived shades (good trade). Migrate the 5 existing themes into presets; existing `story.theme='forest'` rows map to the Forest preset.

### Task breakdown
- **42.1** New `cyoa_ai_themes` presets table (seed from the 5 existing themes).
- **42.2** Story theme storage: add `theme_json` (keep/repurpose the legacy `theme` string for mapping).
- **42.3** `styles/play_theme.css` variable template + `color-mix()` derivations; `play.php` injection + dynamic font `<link>` (role-aware: heading vs body, per Phase 41).
- **42.4** AI prompt change (`prompts/story_plan_system.txt`: theme **object** instead of the `suggested_theme` enum) + server-side validation/sanitization in `ai_story_handler.php` / `ai_apply.php` (hex / font-from-Phase-41-list / contrast). *Subsumes the `suggested_theme` enum cleanup flagged in the Phase 39 prompt scan.*
- **42.5** Story-editor theme editor UI (font dropdown from the Phase 41 list + colour pickers + live preview, with picker swatches).
- **42.6** Migrate the 5 existing themes → presets; retire per-file theme CSS once parity is acceptable.

### Files
| File | Change |
|---|---|
| `cyoa_ai_themes` (DB) | **New** presets table (seed from the 5 themes) |
| `cyoa_ai_stories` (DB) | `theme_json` column (legacy `theme` kept for mapping) |
| `styles/play_theme.css` | **New** variable template (derivations via `color-mix()`) |
| `play.php` | Inject `:root` vars + dynamic font `<link>` (role-aware); render via the template |
| `prompts/story_plan_system.txt` | Theme **object** instead of the `suggested_theme` enum |
| `cron/ai_story_handler.php`, `cron/ai_apply.php` | Theme validation/sanitization (hex / font-from-allow-list / contrast) + apply `theme_json` |
| `editor.php` | Theme editor UI (font + colours + live preview) |
| `themes/*_theme.css` | Migrate → presets; retire after parity |

### Decisions captured
- Font source: the **Phase 41 curated allow-list** (the font-freedom decision lives there).
- Sequencing: **6**; no theme changes in 5; runs **after Phase 41**.

---

## Phase 43 — Documentation Update (runs last)

Bring the `.claude/` steering docs and `CLAUDE.md` current with all 3 + 4 + 5 + 6 work. **No app
code changes.** This absorbs the never-done 4 Phase 33 and the 5 documentation deferral. It runs
**last** so it captures whatever 6 features actually shipped — only document what landed.

### Scope by document
- **Apply the deferred 3 Phase 22 doc items** first: image paths, job types, result application,
  DB-settings migration.
- **`.claude/architecture.md`:** soft delete/trash + maintenance; genres; modal system; admin settings
  (incl. content-restrictions/guardrails, retention); guardrail injection + `red_flag`; tree view
  (+ fixed-width modal, tile-size slider); the **5 settings split** (account vs
  settings_site/content/users) + cog dropdown; the **5 simplified maintenance** (inline Empty-Trash
  button vs periodic dispatcher run); **audience lookup** (Phase 39); **pagination + `job_history.php`**
  (Phase 40, if shipped); **font allow-list + theme engine** (Phases 41–42, if shipped).
- **`.claude/database-schema.md`:** `date_deleted`, `genre` (TEXT/JSON), `ai_image_*` columns; settings
  rows (`openai_image_format`, `guardrails_*`, `trash_retention`, `log_retention`, `gallery_page_size`);
  5 genre backfill; any 6 theme tables/columns (`cyoa_ai_themes`, story `theme_json`) if shipped.
- **`.claude/api-endpoints.md`:** `api_tree.php`, `api_jobs.php?action=detail`, the content-settings
  AJAX endpoint (`api_content.php`); verify the rest.
- **`CLAUDE.md`:** Key Files table — `trash.php`, `api_tree.php`, `cron/maintenance.php`, `modal.js`,
  `tree-view.js`, the new `settings_site.php` / `settings_content.php` / `settings_users.php`,
  `api_content.php`, and `job_history.php` (if shipped); note the account/settings split; the
  `config.php` reference-data constants (`SCENE_THUMB_SIZES`, `STORY_AUDIENCES`, and `PLAY_FONTS` if
  shipped); `logs/` + `prompts/` directory notes.

### Task breakdown
- **43.1** Apply the deferred 3 Phase 22 doc items.
- **43.2** Update `architecture.md` (per the scope above; include only shipped 6 features).
- **43.3** Update `database-schema.md`.
- **43.4** Update `api-endpoints.md`.
- **43.5** Update `CLAUDE.md` (Key Files, conventions, reference-data constants).
- Cross-check the steering docs against the actual code one final pass — they tend to lag migrations.

### Files
| File | Change |
|---|---|
| `.claude/architecture.md` | Bring current (3–6) |
| `.claude/database-schema.md` | Columns, settings rows, 6 tables (if shipped) |
| `.claude/api-endpoints.md` | New endpoints; verify the rest |
| `CLAUDE.md` | Key Files table, conventions, reference-data constants |
