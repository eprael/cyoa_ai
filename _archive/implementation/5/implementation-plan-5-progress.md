# CYOA AI Upgrade — 5 Progress

_Tracks implementation of `implementation-plan-5.md` (Phases 33–38). Updated per sub-task so a
context compaction can resume cleanly. Ground truth = the code + DB; this file is the map._

_Last updated: 2026-05-31_

## Status
- **Phase 33 — Genre Backfill:** ✅ complete
- **Phase 34 — Account & Admin Settings Restructure:** ✅ complete (smoke-tested)
- **Phase 35 — Main Gallery Tweaks:** ✅ complete (DOM-verified)
- **Phase 36 — Summary Page Tweaks:** ✅ complete (DOM-verified)
- **Phase 37 — Story Editor & Tree View Tweaks:** ✅ complete (browser-verified bug fixes)
- **Phase 38 — Play Page Tweaks:** ✅ complete (lint-clean)
- **5 is feature-complete.** Phase 39 = none (docs moved to 6).

## Resume pointer
> **All 5 phases (33–38) implemented.** Remaining work lives in **6** (`notes-6.md`):
> documentation pass + data-driven theme engine. Before any further polish, re-read the target file
> and verify with `php -l` + DOM/DB checks (NOT screenshots for small tweaks — see the
> `screenshot-usage` memory). Admin browser session: evanprael@gmail.com / evan123; standard:
> bprael@hotmail.com / bprael.

### Standing prefs captured this session (memories)
- **screenshot-usage**: skip chrome-devtools screenshots for small inter-phase UI tweaks; use lint +
  DOM/DB checks.
- **ui-copy-ai-naming**: in UI copy say "the AI", not Claude/OpenAI, except provider-specific
  settings (API keys, model pickers).

---

## Phase 33 — Genre Backfill ✅
Backfilled `genre` (JSON array) for all 20 non-deleted stories that had none. 0 remain missing;
all JSON_VALID. Assignments (owner may retune — subjective):
- Adventure: 1, 24, 34, 35
- Adventure+Mystery: 6, 31, 54
- Sci-Fi+Mystery: 15, 67, 68
- Other (test stories): 16, 23, 30
- Fantasy+Adventure: 37, 58
- Fantasy: 39, 57
- Fantasy+Horror: 63, 64, 65

---

## Phase 34 — Account & Admin Settings Restructure ✅
Split the monolithic `account.php` into four pages; cog became a dropdown.

- **34.1** `account.php` slimmed to **My Profile** (avatar/name/email + image upload), **Change
  Password**, **BYOK — Bring Your Own API Keys** — now shown to **all** users (was non-admin only
  for profile/password). Handlers: `update_password`, `update_profile_image`, `update_api_keys`.
- **34.2** `header.php` admin cog → `.nav-dropdown` with **Site Settings / Content Settings /
  Users** (reuses existing dropdown JS/CSS).
- **34.3** `settings_site.php` (new): Site Settings form (API keys, AI Generation, Job Queue,
  Appearance) **+ Maintenance section folded into the same panel** (retention saved with the main
  POST; "Empty Trash Now" inline). Scene-thumb input → **dropdown** built from
  `config.php` `SCENE_THUMB_SIZES` (Small 140 / Medium 200 / Large 280; selected px stored in
  `scene_thumb_size`).
- **34.4** `settings_content.php` (new) + `api_content.php` (new AJAX): Content Settings chip
  editors now **persist immediately** (add/remove → `api_content.php`, no Save buttons); chips
  **light gray**; **no remove-category**; **Content Restrictions** (renamed Guardrails) — enable
  checkbox persists immediately via AJAX, textarea has an **"Update"** button (`save_guardrails`).
- **34.5** `settings_users.php` (new): User CRUD + modal moved here; title **"Users"**; **stats
  strip** (Total/Admins/BYOK, computed in PHP from `get_all_users()`); **search + 2 filter
  checkboxes** (is admin / has own keys), client-side over rows with data-attrs.
- **34.6** Added `class="form-control"` to previously-raw inputs (file inputs, key fields) across
  all four pages.

### Smoke test (all passed)
- account.php (admin): only My Profile + BYOK; cog dropdown shows 3 items.
- settings_site.php: thumb dropdown = Small/Medium/Large, **Medium (200px)** selected; sections
  incl. Maintenance; Empty Trash button present.
- settings_content.php: gray chips (#e2e8f0); no remove-category; no old Save buttons; **added a
  genre → persisted to DB via AJAX; removed it → reverted** (back to 10); **guardrails checkbox
  toggle off→DB 0, on→DB 1**; "Update" button present.
- settings_users.php: title "Users"; stats **Total 4 / Admins 2 / BYOK 1**; filters (is admin→2,
  has keys→1) and search ("hewlett"→1) all work; create/edit modal intact.
- Non-admin (Jon): account = My Profile + BYOK, **no cog**; **redirected away** from
  settings_site/content.

### New/changed files
| File | Change |
|---|---|
| `config.php` | `SCENE_THUMB_SIZES` const |
| `account.php` | Slimmed; all-users Profile+Password+BYOK |
| `header.php` | Cog → settings dropdown |
| `settings_site.php` | **New** |
| `settings_content.php` | **New** |
| `settings_users.php` | **New** |
| `api_content.php` | **New** — immediate content/guardrail-toggle saves |

### Cosmetic refinements (post-review round 1)
- **settings_site.php:** removed both "Leave blank to keep the current key" hints; added a top-border
  separator + gap below each settings group title (`.settings-form h3`); first group has no border.
- **settings_content.php:** split into **separate panels per section** (Story Genres / Image Styles /
  Mood Modifiers / Content Restrictions). Per-section pastel chips — genres **blue** (#dbeafe),
  styles **green** (#dcfce7), moods **orange** (#ffedd5). Removed "+ Add Category". Image-style
  categories now render inside the **single** Image Styles panel as labelled groups (no per-category
  box). Verified via DOM (colors + 4 panels + 0 old per-cat panels + no add-category button).

### Cosmetic refinements (post-review round 2 — settings_content.php)
- Added a **"Content Settings"** page heading (h1) above the intro paragraph.
- **Add flows now use modals** (global `Modal.open`): each section shows a **single right-aligned
  Add button** on its heading line (reuses `.admin-header`). Removed all inline `.chip-add-row`s.
  - Genres / Moods → modal with heading + instructions + text input + Add.
  - Image Styles → modal with heading + instructions + **category dropdown + style textbox**.
  - Submit → add chip + AJAX save + modal closes + list refreshes. Chip × still removes immediately.
- Verified both modal flows persist to `cyoa_ai_settings` and clean up; screenshot confirms layout.

### Cosmetic refinements (post-review round 3 — settings_site.php)
- Restructured to match Content Settings: **"Site Settings" heading + intro outside** the panels,
  then **each section in its own panel** (API Keys / AI Generation / Job Queue / Appearance /
  Maintenance). Removed the old single-panel + h3-separator layout.
- All panels remain inside **one form** → a single **Save Settings** saves everything (verified:
  flash + retention/thumb values persisted). **Empty Trash Now** is a `type=button` that submits a
  **separate** hidden form (`#form-empty-trash`) to avoid nested forms.

### Cosmetic refinements (post-review round 4 — both settings pages)
- Both `settings_site.php` and `settings_content.php` now enclose **all content in a single
  `.account-section` page panel** (heading + intro inside it, like the Users page), and each
  section is an outlined **`.settings-block`** (1px border, rounded) within that panel.
- Verified: 1 outer panel each; inner blocks present; chip colors + modal-adds + single Save
  unchanged; no PHP errors.

### Cosmetic refinements (post-review round 5 — final settings polish)
- **AI Content Settings page** (`settings_content.php`): page title (h1 + browser title) and the cog
  dropdown entry renamed to **"AI Content Settings"**. Section headings: **Story Genres** (unchanged),
  **AI Image Styles**, **AI Image Modifiers** (was "Mood Modifiers"), **AI Content Restrictions**.
  Restriction intro: "Claude" → "The AI"; added gap above the enable checkbox. Header Add buttons
  switched to **btn-primary** (orange) to match "+ Add User".
- **"Mood" → "Image Modifier"** everywhere in UI: settings heading + add-modal title; editor.php
  dropdown placeholders `(no mood)` / `(no mood modifier)` → `(no modifier)`. Internal keys
  (`image_moods`, `ai_image_mood`, JS vars, POST names) left unchanged — UI-only relabel.
- **Dark-theme fix:** `.settings-block` background was hardcoded `#f3f4f6` (illegible in dark mode) →
  now `var(--bg)` on both settings pages (theme-aware: subtle shade in light themes, recessed dark
  block + light text in dark). Verified via computed styles.
- `header.php` cog dropdown reads: Site Settings / **AI Content Settings** / Users.

### Notes / possible follow-ups for review
- Retention windows now save with the main "Save Settings" button (per the "fold Maintenance into
  Site Settings" note) rather than a separate save.
- Old per-section save buttons in Content Settings are gone (immediate save) — intended.
- `db_get_user_admin_stats()` helper not added; stats computed inline in PHP (simpler, same result).

---

## Phase 35 — Main Gallery Tweaks ✅
`index.php`, `header.php`, `styles/cards.css`.
- **35.1** "My Likes" → **"Favorites"** (index filter button + header user dropdown). `filter=likes`
  param unchanged.
- **35.2/35.3** Card stats row restructured: `.story-card-stats` now `justify-content: space-between`
  with a left **`.card-social-strip`** (views → comments → like → **rating last**) and a right
  **`.card-genre-wrap`** (genre chips). Rating only renders when `avg_rating !== null`, always last.
- **35.4** Author moved to **`.story-card-author`** directly under the title (0.78rem muted); old
  `.story-card-meta` block removed.
- **Verified (DOM):** filter reads "Favorites"; content order H3 / author / P; stats children =
  social-strip + genre-wrap (space-between); a rated card's strip ends with Average rating.

## Phase 36 — Summary Page Tweaks ✅
`summary.php`, `styles/summary.css`.
- **36.1** Like label singular/plural: `like` when count == 1 else `likes` (PHP `#fav-label` + kept
  in sync in `applyLike()` JS).
- **36.2** Star widget replaced with compact **`[avg] ★★★☆☆ (count)`** (`.star-rating-display` =
  `#star-avg` + `.star-widget` + `#star-count`). Stars now reflect the **average** (filled to
  `round(avg)`), still clickable to rate w/ hover preview; on success refresh avg+count from server.
  Removed all status text (`#star-status` and its "Click to rate / You rated / avg x (n)" branches).
- **36.3** Removed the theme `meta-chip`; **genre badges only** (whole `.summary-meta` hidden when no
  genres). `$themes` map deleted.
- **36.4** Removed the draft `.alert-info` notice; added a pastel **`.status-tag`** above the title
  (`.status-published` green / `.status-draft` red).
- **Verified (DOM):** status tag "Draft"; only genre chips; no draft alert; `#star-avg`/`#star-count`
  present, `#star-status` gone; fav label "likes" at count 0.

## Phase 37 — Story Editor & Tree View Tweaks ✅
`editor.php`, `styles/tree-view.css`, `cron/ai_apply.php`.
- **37.1** Story-overview back button "Back to Landing Page" → **"Back to Story Summary"**.
- **37.2** Scene editor: added a **Tree View** button next to "Back to Story"; the Phase-31 tree
  script now also renders in `scene_form` (gating widened to `!empty($scenes) || !empty($allScenes)`,
  binds to `#btn-tree-view`).
- **37.3** Scene-AI modal now shows the **image-style controls** (`render_inline_image_style_controls('modal')`
  inside `#modal-ai-image-style`), toggled by the "Also generate a scene image" checkbox
  (`toggleSceneModalImageStyle()`). `submitSceneJob()` forwards `image_category/style/mood`; and
  **`cron/ai_apply.php` `apply_scene_result()`** now carries those through to the queued image job
  (previously only prompt+theme).
- **37.4 (root-cause fix)** The inline-style helpers (`inlineFillSub`/`inlineStyleParams`/
  `inlineToggleStyle`/`inlineImageStylesData`/`inlineStoryDefaults`) were defined **only inside the
  story_form script**, so in the **scene editor** the category→sub-style cascade silently did nothing
  (and `submitSceneImageJob` threw). Moved the definitions into
  `render_inline_image_style_controls()` itself (emitted **once per request** via a `static` guard),
  so they exist in any view that renders the controls (cover / scene / modal). Removed the duplicate
  block from the story_form script.
- **37.5** "Generate Image" button moved **below** the image-style options in both the cover panel
  and the scene-thumbnail panel.
- **37.6** Tree modal width — landed in stages (each masked by **CSS caching**; needs hard reload):
  1. `.tree-container` width `min(86vw,1000px)` → `100%` — only widened the *container*; the SVG draws
     at its own intrinsic width and stays pinned left, so the modal just gained an empty right band.
  2. Tried `.modal-box:has(.tree-container){ width: fit-content }` so the modal hugged the tree — snug
     & centered, **but** the wheel-zoom kept changing the SVG size, so the modal resized as you scrolled.
  3. **Final (owner-requested):** **fixed-width canvas** — `.modal-box:has(.tree-container){ width:
     min(1000px,92vw) }`, the SVG centered via `.tree-container > svg { display:block; margin:0 auto }`
     (auto margins collapse to 0 when it overflows, so it stays scrollable from the left). And the
     **mouse wheel scrolls instead of zooming** — removed the `wheel` zoom handler in `tree-view.js`
     (`wirePanZoom` → `wirePan`), keeping click-drag panning. Verified: stable 855px modal, tree
     centered, wheel no longer changes the SVG width.
  4. **Tile-size slider** (owner-requested): a range slider added to the modal **footer, centered,
     with Close pinned right** (`.modal-actions:has(.tree-zoom)` → relative + justify-center, Close
     absolute right). New `TreeView.setScale(container, scale)` scales the whole tree
     (tiles+spacing+text via the `.tree-root` group transform + svg width/height); injected in
     `editor.php` via `addTreeZoomSlider()` after `Modal.open`. Modal stays fixed-width (svg
     overflows + scrolls). Verified: slider centered (0px offset), Close at right edge, scale 1.5 →
     svg 584→876 + `scale(1.5)`. Also incidentally covers the font-scaling caveat (text scales too).
     NB: tree-view.js/css are cached — hard reload to see.
  5. **Slider polish (owner feedback):** (a) restyled the range input to an understated **thin gray
     track + round thumb** (`appearance:none` + WebKit/Firefox track/thumb pseudo-elements; no
     coloured progress fill). (b) Fixed a **scaling/scroll bug** — `setScale` had used both a width/height
     change *and* a `.tree-root` group `transform: scale()` while the viewBox stayed at base size, so
     content **double-scaled** and overflowed past the SVG box → the horizontal scrollbar "kicked in
     too late" (couldn't reach off-screen nodes). Now it resizes width/height only (viewBox fixed, no
     group transform) so the SVG box matches the rendered content. Verified at 2×: container
     `scrollWidth` == svg width, rightmost node reachable (not clipped).
  6. **Dark-theme fix (owner feedback):** the tree canvas was hardcoded light — `.tree-container`
     used `var(--bg-secondary, #f4f6f9)` but `--bg-secondary` **isn't a defined theme variable**, so it
     always took the light fallback. Switched the canvas to `var(--bg)` and node boxes from
     `var(--bg)` → `var(--card-bg)` (so nodes stay distinct from the canvas in every theme). Now fully
     theme-driven (`--bg`/`--card-bg`/`--border`/`--text`/`--accent`). Verified in dark: canvas #0f172a,
     nodes #1e293b, labels #e2e8f0.
- **Verified (browser, scene 790/story 67):** `inlineFillSub/StyleParams/ToggleStyle` all defined;
  Photographic → 7 sub-styles populate; `#modal-cat` + `#modal-ai-image-style` present and toggle
  with the checkbox; `#btn-tree-view` present + `TreeView` loaded; Generate-Image button after the
  style controls.

## Phase 38 — Play Page Tweaks ✅
`play.php`, `styles/play_layout.css` (no theme files — deferred to 6).
- **38.1** `.layout-image_top .content` → **`text-align:left; max-width:70ch; margin:0 auto`** (text
  reads left-aligned inside a centered column). Fix lives **only in `play_layout.css`** (loaded after
  theme CSS, equal specificity wins). Choices keep `justify-content:center`.
- **38.2** image_top header img → **`object-position: top`** (crop from the top instead of center).
- **38.3/38.4** Top-right "Edit" → **"Edit Scene"**, with a new **"Edit Story"** button to its left
  (`editor.php?storyID=…`); both wrapped in a fixed `.play-edit-actions` flex container (the
  `position:fixed` moved from `.play-edit-btn` to the container).

### Notes for the 6 documentation pass
- New file `api_content.php` (Phase 34) and the shared inline-style-helper relocation (Phase 37)
  should be reflected in `.claude/architecture.md` / `api-endpoints.md`.
- `cron/ai_apply.php` scene→image job now forwards image-style overrides — note in AI flow docs.
