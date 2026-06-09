# Implementation Plan 6 — Progress

Tracks per-phase completion for 6 (Phases 39–43). See `implementation-plan-6.md` for specs.

| Phase | Title | Status |
|---|---|---|
| 39 | Audience Instruction Lookup (prompt refactor) | ✅ Complete |
| 40 | Pagination — gallery + job history | ✅ Complete |
| 41 | Curated Mood-Tagged Font Allow-List | ✅ Complete |
| 42 | Data-Driven Theme Engine | ✅ Complete |
| 43 | Documentation Update (last) | 🔲 Pending (out of scope for this run) |

---

## Phase 39 — Audience Instruction Lookup ✅

Resolved the audience complexity server-side instead of shipping the AI a 5-row table to look up itself.

**Done:**
- **39.1** Added `STORY_AUDIENCES` reference map to `config.php` (used `define()` to match the
  existing `SCENE_THUMB_SIZES`/`AI_IMAGE_PRICING` convention rather than the plan's `const` sketch).
  Single source of truth: key → `['label', 'complexity']`.
- **39.2** Added `resolve_audience(string $key): array` in `cron/ai_story_handler.php` (fallback
  `middle_grade` for unknown/blank keys). `build_plan_system_prompt()` and
  `build_scene_writer_system_prompt()` now pass `audience_label` + `audience_complexity` placeholders
  (dropped the raw `audience` token).
- **39.3** `prompts/story_plan_system.txt` and `prompts/story_scene_writer_system.txt`: replaced the
  inline 5-row table + `{AUDIENCE}` with the two-line `{AUDIENCE_LABEL}` / `{AUDIENCE_COMPLEXITY}` form.
- **39.4** Verified `prompts/scene_system.txt` has **no** audience table — no-op, as the plan predicted.
- **39.5** Derived the audience lists from the map: `api_create_story_ai.php` `$allowedAudiences =
  array_keys(STORY_AUDIENCES)`; `editor.php` dropdown now loops `STORY_AUDIENCES` (labels now carry age
  ranges, a minor UI improvement) and the JS `AUDIENCES` array is emitted via
  `json_encode(array_keys(STORY_AUDIENCES))` (mirrors the existing `MOODS` pattern).

**Smoke test:** isolated render test confirmed — no leftover `{AUDIENCE*}` tokens, no 5-row table,
`young_adults` resolves to its label+complexity, and unknown key `banana` falls back to middle grade.
All four touched PHP files lint clean.

**Judgement calls:**
- Used `define()` not `const` for `STORY_AUDIENCES` (matches the file's existing style).
- Did 39.5 (marked optional/deferrable) since it removes the remaining hardcoded duplicates and was low-risk.

---

## Phase 40 — Pagination (gallery + job history) ✅

**Shared infrastructure:**
- **New `pagination.php`** — `render_pager($current, $totalPages, $baseParams, $baseUrl)` + `pager_url()`
  helper. Windowed truncation (`« Prev 1 … 4 5 [6] 7 8 … 20 Next »`), preserves/merges query params,
  `aria-label="Pagination"` + `aria-current="page"` + non-focusable disabled ends. Returns `''` for a
  single page. (Kept as a standalone include rather than in `db_functions.php` — it's a view helper;
  the plan allowed "or shared include".)
- **New `styles/pager.css`** — theme-variable-driven `.pager` block.
- **Page-size settings** added to `settings.php` `SETTING_DEFAULTS` (`gallery_page_size`=12,
  `jobs_history_page_size`=25) so the app works pre-migration, and seeded as rows in the live
  `cyoa_ai_settings` table for admin tunability.

**40.1 — Gallery (`index.php`):** numbered pager rendered **above and below** the grid; preserves
filter/genre/sort/q and adds `&page=N` with out-of-range clamping. **Array-slice paging** (the
plan's sanctioned fallback): the list query selects only story columns (cheap) and the expensive
per-card stat lookups run only for the sliced page, so reworking all four story-list query variants
(genre clause, JSON_CONTAINS, dynamic ORDER BY, logged-in branches) was judged too fiddly/risky for
the benefit at this app's scale.

**40.2 — Job queue split + Job History:**
- `job_queue.php` now shows **today's jobs only** (local-midnight cutoff via `strtotime('today')`,
  with `date_default_timezone_set(APP_TIMEZONE)`); the redundant **period filter dropdown was removed**
  (the page is inherently "today"); the old inline "recent/past 24h" toggle was replaced by a **"View
  Past Jobs →"** button that navigates to the new page.
- **New `job_history.php`** — all jobs **before today**, newest-first, numbered pager top + bottom,
  page size from `jobs_history_page_size`. Paginates top-level parents via SQL `LIMIT/OFFSET` + a
  matching `COUNT`, then attaches their child (image-chain) rows. Complementary cutoff: both pages use
  the same PHP-derived `today 00:00:00` boundary passed as a bound param (`created_at < ?` vs `>=`),
  so they never overlap or gap regardless of MySQL session timezone (drift at the exact midnight
  boundary is acceptable per the plan).
- **New DB functions** (`db_functions.php`): `db_count_history_jobs()`, `db_get_history_jobs()`,
  `db_get_child_jobs()` — all prepared statements, admin (all users) vs user-scoped variants.

**Refactor to avoid duplication (both job pages render identical tables):**
- **New `job_render.php`** — extracted `job_type_label/icon`, `status_badge`, `format_duration`,
  `job_link`, and `render_job_table()` out of `job_queue.php`.
- **New `styles/job_queue.css`** — extracted job_queue.php's large inline `<style>` block; linked by
  both pages.
- **New `job_detail.js`** — extracted the child-row toggle, cancel/retry `jobAction`, and the
  job-detail modal (+ JSON highlighter); loaded by both pages. Live status polling stays inline in
  `job_queue.php` (history has no active jobs).

**40.3 — User list:** unchanged (out of scope), recorded for completeness.

**Smoke tests:** history SQL validated against the live DB (81 history parents → 4 pages of 25; the
child-join returned 24 children for a 5-parent page; 1 top-level job "today"). Pager HTML verified
(empty for a single page; correct ellipsis truncation; params escaped/preserved). All touched/new PHP
files lint clean.

**Judgement calls:**
- Array-slice gallery paging instead of SQL `LIMIT` (sanctioned fallback; query rework was fiddly).
- Removed the now-meaningless **period** filter from `job_queue.php` rather than leaving a dropdown
  that conflicts with the today-only model.
- Kept the **"Completed In"** column header (a prior session already renamed "Time To Complete" → this).
  The 6 plan said rename to "Completed", but "Completed In" is clearer for a *duration* column, so it
  was left as-is — the rename intent is satisfied.
- Did **not** add a Job History link to `header.php` (plan marked it optional); the "View Past Jobs"
  button on the queue is the single entry point, keeping the nav uncluttered.
- Extracted shared job rendering/CSS/JS into includes (`job_render.php`, `styles/job_queue.css`,
  `job_detail.js`) rather than duplicating ~240 lines of table markup across the two pages.

---

## Phase 41 — Curated Mood-Tagged Font Allow-List ✅

A vetted, guaranteed-to-load set of Google fonts the AI can pick from — the data foundation Phase 42's
font selection depends on.

**Done:**
- **41.1–41.3** Hand-curated **45 families** spanning the target moods (horror, fantasy, sci-fi,
  elegant, playful, retro, handwritten, neutral/literary, historical, romance, comedy, western, gothic,
  medieval, …). Each entry: `family`, `category`, `weights` (lean — mostly `400;700` or `400`),
  `moods[]`, a **`role`** (15 body-safe vs 30 heading/display-only), and a CSS `fallback` stack.
- **41.4** Stored as **`PLAY_FONTS`** in `config.php` (dev-reference-data convention, alongside
  `SCENE_THUMB_SIZES` / `STORY_AUDIENCES`), plus `PLAY_FONT_DEFAULT_BODY`/`_HEADING`. Chose the
  config.php constant over `data/play_fonts.json` to match the existing convention and keep it loaded
  everywhere config is.
- **New `fonts.php`** — read-only accessors (the contract Phase 42 consumes): `play_fonts()`,
  `play_font_families()`, `play_font_meta()`, `play_font_is_allowed()` (hard validation guard,
  case-insensitive), `play_fonts_for_role()`, `play_fonts_by_mood()`,
  `play_font_default_for_mood()` (role-strict fallback), `play_font_stack()`, `play_font_css2_url()`
  (runtime `<link>` builder), and `play_fonts_mood_map()` (moods→families for the AI prompt).
- **41.5** **New `_dev/validate_play_fonts.php`** — requests the real css2 URL for every entry and flags
  any that don't resolve. **Ran it: 45/45 OK** — every family + listed weight loads on Google Fonts
  (hallucination guard cleared).

**Judgement calls:**
- Hand-curated against known-real families + the live validator instead of pulling the Google Fonts
  Developer API (no API key needed; the validator is the authoritative real-name/real-weight check the
  plan asked for, and it passed clean).
- `PLAY_FONTS` constant in `config.php` over a JSON file (convention match).
- Made `play_font_default_for_mood()` **role-strict**: a body fallback never resolves to a display
  font (readability), and a heading fallback never resolves to a plain body serif. Moods with no
  same-role match fall back to the global per-role default (Lora body / Playfair Display heading).

---

## Phase 42 — Data-Driven Theme Engine ✅

Replaced the fixed 5-word theme with a small set of stored *values* (a body + heading font from the
Phase 41 allow-list, plus bg/text/accent hex) → unlimited looks, AI-generated palettes, and full user
override, all rendered through one variable-driven stylesheet.

**Strategy (judgement call — additive & safe):** the engine renders any story that has a `theme_json`
(every new AI story, plus any story a user opts in via the editor); stories without one keep their
existing per-file theme CSS, **unchanged**. This delivers all of the plan's capabilities without
force-re-rendering every existing story through an unverified template, and matches the plan's own
42.6 sequencing ("retire per-file CSS once parity is acceptable" — deferred).

**Done:**
- **42.1 / 42.2 (DB, applied to live `.184`):** added `cyoa_ai_stories.theme_json TEXT NULL`; created
  `cyoa_ai_themes` (theme_id, name, slug, theme_json, is_preset, sort_order) and **seeded the 5 presets**
  from the legacy themes (sanitized JSON). `get_story()` now selects `theme_json`;
  `update_story_theme_json()` added.
- **Reference data (`config.php`):** `THEME_PRESETS` (5 presets — name + body/heading font + bg/text/
  accent, keyed by the legacy slug so old stories map 1:1) + `THEME_DEFAULT_PRESET`.
- **New `theme.php` — the engine:** WCAG luminance/contrast helpers; `theme_sanitize()` (**the single
  CSS-injection gate** — strict `#RRGGBB` regex, font must be on the allow-list, size clamped, text↔bg
  contrast < 4.5 falls back both colours); `theme_resolve_engine()` (engine values or null→legacy);
  `theme_css_vars()` / `theme_font_links()` (only ever emit validated values); `theme_from_ai()` (maps
  the AI's single `font` choice → a readable body + expressive heading pair by role); `theme_to_json()`,
  `theme_preset()`, `theme_preset_for_genre()`.
- **42.3 New `styles/play_theme.css`:** one template driven by `--bg/--text/--accent/--font/
  --font-heading/--base-size`; every bespoke shade (surfaces, hover glow, button text, scrollbar,
  tooltips) **derived via `color-mix()`**. `play.php` injects the `:root` block + dynamic Google Fonts
  `<link>`s and links the template when a story has a theme_json; legacy stories unchanged.
- **42.4 AI:** `prompts/story_plan_system.txt` now asks for a `theme` **object** ({font, bg, text,
  accent}) with a `{FONT_OPTIONS}` mood→families list injected from the allow-list;
  `build_font_options_block()` builds it. `validate_story_plan($parsed, $genre)` sanitizes the theme
  object → `theme_json` (off-list font / bad hex / low contrast all fall back), keeps a legacy slug for
  the `theme` column, and handles the old `suggested_theme` string + a no-theme genre fallback.
  `ai_apply.php` persists `theme_json` on both apply paths (honouring a user's legacy-theme override).
- **42.5 Editor UI (`editor.php`):** a "Customise appearance" toggle reveals a theme editor — preset
  picker, heading-font + body-font dropdowns (sourced from `PLAY_FONTS`), bg/text/accent colour
  pickers, and a **live preview** that dynamically loads the chosen Google fonts. Saved to `theme_json`
  via `theme_to_json()` (sanitized server-side); unchecking clears it back to the legacy theme.
- **42.6 Migration:** legacy `theme` slugs map to presets at render time; the 5 per-file theme CSS files
  are **kept** as the fallback for non-engine stories. Retiring them is deferred pending a visual-parity
  pass (plan-sanctioned).

**Smoke tests (all passed):** engine unit test — valid AI theme resolves correctly; a malicious
font/colour **injection attempt was fully neutralized** (all fields rejected → preset fallback, zero
`<` in the emitted CSS); low-contrast text fell back; `theme_resolve_engine` returns null for legacy
and values for engine stories; font `<link>`s build correctly. `validate_story_plan` verified for the
theme-object, legacy-string, and genre-fallback cases. DB verified on `.184`: `theme_json` column +
`get_story` SELECT work, existing stories are `NULL` (legacy path), all 5 presets seeded. All touched
PHP lint clean.

**Judgement calls:**
- **Additive engine** (theme_json opt-in) rather than ripping out per-file themes — safe, and the
  highest-risk surface (the player) is unchanged for existing stories.
- `THEME_PRESETS` in `config.php` **and** seeded into `cyoa_ai_themes`: config is the fast, DB-free
  source for legacy mapping in play.php's hot path; the table backs the editor picker / future custom
  presets (minor, intentional duplication).
- AI returns a single `font`; the engine derives the body/heading pair by role so body stays readable.
- Kept the legacy per-file theme files (42.6 retire deferred) — visual parity of `play_theme.css`
  against the originals wants a manual browser pass, which is the recommended follow-up.

**Recommended manual follow-up:** load a story in the editor, enable "Customise appearance", pick a
preset/colours, save, and open the play page to confirm `play_theme.css` + the injected vars render as
intended (the one step not coverable by lint/DB checks).

---

## Post-6 refactor — reference data moved to data/*.json

Moved the three editorial/content reference structures out of `config.php` into JSON, matching the
existing `data/premises.json` precedent (config.php now keeps only dev-tuning/pricing constants —
`SCENE_THUMB_SIZES`, `AI_IMAGE_PRICING`, plus app/DB/SMTP config).

- **New data files** (generated from the old constants for exact fidelity, each with an `_about` note):
  `data/audiences.json` (Phase 39), `data/play_fonts.json` (Phase 41, with a `defaults` block),
  `data/themes.json` (Phase 42, with a `default` key).
- **New `data.php`** — `load_data_json($name)`: one request-cached, graceful (`[]` on missing/corrupt)
  JSON reader. The domain accessors wrap it.
- **Accessor rewiring (full swap — no back-compat constants):**
  - `db_functions.php`: new `story_audiences()`; `require_once data.php` at top.
  - `fonts.php`: `play_fonts()` and new `play_font_default($role)` read from JSON; dropped the
    `PLAY_FONT_DEFAULT_*` constant fallbacks.
  - `theme.php`: `theme_presets()` + new `theme_default_key()` read from JSON; replaced all
    `THEME_DEFAULT_PRESET` uses.
  - Call sites swapped: `cron/ai_story_handler.php` (`resolve_audience`), `api_create_story_ai.php`,
    `editor.php` (audience dropdown + JS array, theme preset loop + JS PRESETS + default key).
- **`config.php`:** removed `STORY_AUDIENCES`, `PLAY_FONTS` (+ `PLAY_FONT_DEFAULT_*`), `THEME_PRESETS`
  (+ `THEME_DEFAULT_PRESET`); left a pointer comment to the JSON files + accessors.

**Verified:** all three constants now undefined; `story_audiences()`/`play_fonts()`/`theme_presets()`
return identical data from JSON; the theme injection guard still neutralizes malicious input; the font
validator re-ran **45/45 OK** reading from `data/play_fonts.json`; 10 touched files lint clean.

**Decisions (per the user):** full swap (no thin back-compat constants); only the three editorial
structures moved (SCENE_THUMB_SIZES / AI_IMAGE_PRICING stay in config.php as dev/pricing constants).
