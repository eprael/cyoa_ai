# Implementation Plan 5 — Cosmetic Polish & Settings Rework

Cosmetic polish plus a re-work of the account/admin settings area. Source of truth:
`_dev/implementation/5/notes-5.txt`.

Phases continue from the 4 sequence. 5 runs **Phases 33–38** and contains **no documentation
phase** — the documentation update (which also absorbs 4's never-done Phase 33) now lives in
**6**, see `_dev/implementation/6/notes-6.md`.

## Conventions for 5

- **No migration file.** Schema/data changes are applied **directly to the live DB**
  (`192.168.1.184`, db `evan`, prefix `cyoa_ai_`) during implementation. Record any schema change
  in the phase's progress notes; the `.claude/` schema/doc sync happens in the 6 documentation pass.
- Keep PHP procedural; vanilla JS (no framework); read runtime config via `app_setting()`.
- Smoke-test each phase in the browser before moving on; keep a `implementation-plan-5-progress`
  doc updated per phase (created when implementation starts).

## Phase index

| Phase | Title |
|---|---|
| 33 | Genre Backfill (data) |
| 34 | Account & Admin Settings Restructure |
| 35 | Main Gallery Tweaks |
| 36 | Summary Page Tweaks |
| 37 | Story Editor & Tree View Tweaks |
| 38 | Play Page Tweaks |

---

## Phase 33 — Genre Backfill (data)

Populate `genre` for stories that currently have none, by analysing each story's title +
description and assigning one or two genres from the managed `story_genres` list.

### Design notes
- ~20 non-deleted stories currently have an empty/null `genre`
  (`genre IS NULL OR genre='' OR genre='[]' OR genre='[""]'`).
- `genre` is stored as a JSON array of display-cased strings (e.g. `["Sci-Fi","Mystery"]`),
  matching the `story_genres` setting values: Adventure, Fantasy, Sci-Fi, Mystery, Horror,
  Romance, Comedy, Historical, Educational, Other.
- Only assign genres from that managed list; prefer 1, use 2 when clearly warranted; fall back to
  `["Other"]` if nothing fits.

### Task breakdown
- **33.1** — Query the affected stories (`storyID, title, description`) from the DB.
- **33.2** — For each, pick 1–2 best-fit genres from `story_genres` based on its summary.
- **33.3** — `UPDATE cyoa_ai_stories SET genre = '<json>' WHERE storyID = ?` for each, writing a
  valid JSON array. Verify with a re-query (no rows left matching the "missing genre" predicate).

### Files / data changed
| Target | Change |
|---|---|
| `cyoa_ai_stories` (DB) | Backfill `genre` JSON for ~20 stories |

---

## Phase 34 — Account & Admin Settings Restructure

Split the monolithic `account.php` into focused pages and turn the header cog into a settings
dropdown. This is the only non-cosmetic phase.

### Design notes
- Today `account.php` holds: Password, AI API Keys (BYOK), Profile Image, Site Settings, Content
  Settings, Guardrails, Maintenance, and User Management — gated inline by `$isAdmin`.
- The header cog (admin only) is a **direct link** to `account.php`; it becomes a **dropdown**.

### Task breakdown

#### 34.1 — Slim down `account.php` (all users)
- Keep only the **Password change** panel and the **BYOK** panel.
- Rename the "AI API Keys" panel heading to **"BYOK — Bring Your Own API Keys"**.
- Keep the **Profile Image** panel here too (confirmed). So `account.php` = Password + BYOK +
  Profile Image, identical for admins and non-admins.
- Keep the corresponding POST handlers (`update_password`, `update_api_keys`,
  `update_profile_image`); move all admin handlers out to the new pages.

#### 34.2 — Header cog → settings dropdown (admin only)
- Replace the cog `<a href="account.php">` with a `.nav-dropdown` (reuse existing dropdown JS/CSS).
- Menu items: **Site Settings** → `settings_site.php`, **Content Settings** → `settings_content.php`,
  **Users** → `settings_users.php`.
- Keep "Account" in the user-avatar dropdown (points at the slimmed `account.php`).

#### 34.3 — `settings_site.php` (admin)
- New page: session + admin guard + `header.php`.
- Contains the **Site Settings** panel (API keys, AI Generation, Job Queue, Appearance) **plus a
  new "Maintenance" section appended inside that same panel** (retention dropdowns + "Empty Trash
  Now" — not a separate `.account-section`).
- Move the `update_site_settings`, `save_maintenance`, and `empty_trash_now` handlers here.
- **Appearance:** replace the Scene Thumbnail Size number input with a **dropdown**:
  Small / Medium / Large. Define the px values in **`config.php`** as a reference array (tunable
  later), e.g. `const SCENE_THUMB_SIZES = ['small' => 140, 'medium' => 200, 'large' => 280];`
  (sits alongside existing reference data like `AI_IMAGE_PRICING`). The dropdown is built from that
  array; the **selected px value** is still stored in the DB `scene_thumb_size` setting. Medium
  (200) is today's default.

#### 34.4 — `settings_content.php` (admin)
- New page: session + admin guard + `header.php`.
- Contains the **Content Settings** editors (Story Genres, Image Styles, Mood Modifiers) and the
  renamed **"Content Restrictions"** panel (formerly Guardrails) — in the same page/panel grouping.
- Move `save_content_settings` and `save_guardrails` handlers here.
- Chip styling: change `.admin-chip` background to **light gray** (with dark text). Currently it's
  `background: var(--accent)` + white text, i.e. **white-on-orange** like the main buttons — switch
  it to light gray so chips read as data, not actions.
- **Remove** the "× Remove Category" controls (categories stay fixed for now).
- **Remove** the "Save Genres / Save Image Styles / Save Mood Modifiers" buttons — add/remove of a
  chip must **persist immediately** (AJAX), not on form submit.
  - Add small AJAX actions (e.g. `api_content.php` or extend an existing endpoint) for
    add/remove of a genre, mood, or image sub-style; on success update the in-memory list and
    re-render chips. Server re-validates and writes via `db_set_setting()`.
- Content Restrictions (formerly Guardrails):
  - The **Enable checkbox persists immediately** (AJAX on toggle) — no button for it.
  - Only the **textarea** keeps an action button; **rename "Save Guardrails" → "Update"**.

#### 34.5 — `settings_users.php` (admin)
- New page: session + admin guard + `header.php`.
- Move the **User Management** panel + its handlers (`admin_create_user`, `admin_update_user`,
  `admin_delete_user`) here. Title becomes **"Users"**.
- Add a row beneath the title: **stats strip (left)** + **search bar (right)**.
  - Stats: `Total Users: N   Admins: N   BYOK: N` (BYOK = users with a personal Claude or OpenAI
    key set). One DB helper, e.g. `db_get_user_admin_stats(): array`.
  - Search bar: text input + two filter checkboxes — **is admin**, **has own keys** — with a
    magnifying-glass icon. Client-side filter over the rendered user rows (rows carry
    `data-is-admin` / `data-has-keys`), or GET-param server filter. Client-side is simpler and
    fine for the current user count.

#### 34.6 — Input styling pass
- Ensure every input/select/textarea/checkbox across the four pages uses the shared form styling
  (`.form-control` etc.); replace any raw/unstyled fields. Add a small style only where a shared
  class doesn't already cover it.

### Files to change
| File | Change |
|---|---|
| `account.php` | Slim to Password + BYOK (+ Profile Image pending decision); rename panel; drop admin handlers |
| `settings_site.php` | **New** — Site Settings + Maintenance section; thumb-size dropdown |
| `settings_content.php` | **New** — Content Settings + Content Restrictions; immediate-save chips; gray chips; no remove-category |
| `settings_users.php` | **New** — Users panel + stats strip + search/filter bar |
| `api_content.php` (or existing) | **New/extended** — AJAX add/remove for genres/moods/styles |
| `header.php` | Cog becomes a settings dropdown (admin) |
| `db_functions.php` | `db_get_user_admin_stats()`; any AJAX content setters |
| `styles/account.css` / `forms.css` | Chip gray; input styling; stats/search bar |

---

## Phase 35 — Main Gallery Tweaks

`index.php` story cards + the "My Likes" label.

### Task breakdown
- **35.1** — Rename the **"My Likes"** filter button (and the matching item in the header user
  dropdown) to **"Favorites"**. Keep the `filter=likes` GET param unchanged (label only).
- **35.2** — Card: move the **star rating** to the **end** of the social strip (currently it sits
  between Views and Comments; it's shown dynamically, so order it last).
- **35.3** — Card: move the **genre tag(s)** onto the **same line as the social strip** — social
  strip **left-justified**, genre chip(s) **right-justified** (flex `justify-content: space-between`).
- **35.4** — Card: move the **"by {author}"** line to **directly below the title**, in a **smaller
  font** (it currently shares the meta line with the genre chips).

### Files to change
| File | Change |
|---|---|
| `index.php` | Card markup re-order: author under title; social strip + genre on one line; rating last; "Favorites" label |
| `header.php` | "My Likes" → "Favorites" in the user dropdown |
| `styles/cards.css` | Author small font; social-strip/genre flex row; chip alignment |

---

## Phase 36 — Summary Page Tweaks

`summary.php` social strip and header.

### Task breakdown
- **36.1** — Like label: show **"like"** when the count is 1, **"likes"** otherwise (update the
  static label set in Phase-prior work, and keep it in sync in the like JS).
- **36.2** — Replace the star widget's text with a compact display format:
  **`[avg] [solid stars][open stars] ([total ratings])`** — e.g. `3 ★★★☆☆ (1,222)`.
  - Stars render the **average** (filled up to rounded avg); total ratings shown with thousands
    commas. Stars remain clickable to submit a rating (hover preview retained); after rating,
    refresh avg + count from the server response.
  - **Remove** all rating status text: "Click to rate", "You rated n/5", "avg x (n)".
- **36.3** — Badges: **remove the theme badge**; show **genre badges only**.
- **36.4** — Remove the **draft notice line** between the breadcrumb and the summary card. Instead
  show a **published/draft tag above the title** — pastel **green** (published) / **red** (draft).

### Files to change
| File | Change |
|---|---|
| `summary.php` | like/likes label; new star display + remove text; genre-only badges; status tag above title; remove draft line |
| `styles/summary.css` | Star display layout; pastel status tag; badge tweaks |

---

## Phase 37 — Story Editor & Tree View Tweaks

`editor.php` (story + scene views), the AI generation modals, and the tree-view modal width.

### Task breakdown
- **37.1** — Story editor: rename the **"Back to Landing Page"** button to **"Back to Story
  Summary"** (link target unchanged — `summary.php?storyID=…`).
- **37.2** — Scene editor: add a **Tree View** link/button next to the **"Back to story"** button
  (reuse the Phase-31 fetch → `TreeView.build` → `Modal.open` flow already wired in `editor.php`).
- **37.3** — Bug: the **AI scene-generation** modal shows **no image-style options** — it should
  surface the same image category / sub-style / mood controls used elsewhere. Investigate the
  inline image-style controls (`render_inline_image_style_controls()` / `window.inlineStyleParams`,
  Phase 28) and ensure they render in the scene-AI modal.
- **37.4** — Bug: the **AI image-generation** sub-style dropdown **does not populate** when a
  category is chosen (the category→sub-style cascade isn't wiring up). Fix the populate logic.
- **37.5** — Move the **"Generate Image"** button to **below** the image-style options in the
  image-generation modal.
- **37.6** — Tree View modal: the tree renders in only the **left half** of the near-full-width
  modal. Make the `.tree-container` fill the widened modal (currently capped at `min(86vw,1000px)`
  while `.modal-box:has(.tree-container)` is `92vw`) — align the container width to the modal.

### Files to change
| File | Change |
|---|---|
| `editor.php` | "Back to Story Summary"; scene-editor Tree View link; scene-AI image-style controls; sub-style cascade fix; Generate-Image button position |
| `styles/tree-view.css` | `.tree-container` width fills the widened modal |
| `styles/editor.css` | Any modal/option layout tweaks |

---

## Phase 38 — Play Page Tweaks

`play.php` and `styles/play_layout.css`. **No theme files are touched in 5.**

### Task breakdown
- **38.1** — `layout-image_top` text alignment. Keep the **image and the text block centered on
  the page**, but **left-align the text inside the block**; the **choice buttons stay separate and
  centered**.
  - The block is already centered as a column (`.layout-image_top .play-layout { max-width:1400px;
    margin:0 auto }`). Constrain the text column so left-aligned text still reads centered under the
    image — e.g. give `.layout-image_top .content` a `max-width` (~70ch) + `margin: 0 auto`.
  - Set `.layout-image_top .content { text-align: left; }` for the scene title + body paragraphs.
  - Choices unchanged — they live in `.choices` (`justify-content: center`), separate from
    `.content`, and stay centered.
  - The current `text-align: center` is set in **two** places: `styles/play_layout.css` (line ~81)
    **and** each `themes/*_theme.css`. **Do the fix only in `play_layout.css`** — it's `<link>`-ed
    **after** the theme CSS in `play.php`, so an equal-specificity `text-align: left` there wins
    over the theme rule. This keeps 5 from touching any theme files (no theme changes in 5).
  - *(Detail to eyeball: whether the scene title looks better left or centered — start left per
    the note, easy to flip.)*
- **38.2** — `layout-image_top`: the image is vertically **centered** within its crop area; change
  the crop to anchor from the **top** (`object-position: top` / `object-fit: cover`). Done in
  `play_layout.css` only.
- **38.3** — Logged-in owner/admin: rename the top-right **"Edit"** button to **"Edit Scene"**.
- **38.4** — Add a second button to its **left**, **"Edit Story"**, linking to the story editor
  (`editor.php?storyID=…`).

> **No theme changes in 5.** Adding/changing themes (and the data-driven theme engine that
> supersedes per-file themes) is deferred to **6** — see
> `_dev/implementation/6/notes-6.md`. The image_top fixes above are play-page layout only and
> deliberately avoid editing `themes/*`.

### Files to change
| File | Change |
|---|---|
| `play.php` | "Edit" → "Edit Scene"; add "Edit Story" button |
| `styles/play_layout.css` | image_top text-block left-align (centered column); image crop from top |

---

## Resolved decisions
1. **Profile Image panel** (34.1): **Keep** on `account.php` (Password + BYOK + Profile Image).
2. **Scene thumbnail sizes** (34.3): Small=140 / Medium=200 / Large=280 px, defined as a tunable
   reference array in **`config.php`**.
3. **Play themes**: **deferred to 6.** No theme changes in 5. The data-driven theme engine
   (one CSS-variable template + presets table + AI-generated per-story palettes, curated
   mood-tagged Google-font allow-list, user override) is captured in
   `_dev/implementation/6/notes-6.md`. The 5 image_top fixes are play-layout only.
