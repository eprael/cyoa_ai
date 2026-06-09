# Implementation Plan 4 — UI Polish, Social Enhancements & AI Improvements

## Overview

This plan continues from 3 (Phases 17–22). Phase numbering runs 23–33.
Phase 22 (documentation) was deferred from 3 and is rolled into Phase 33 here.

Broad areas covered:

1. **UI / UX polish** — global modal system, gallery card overhaul, soft delete & trash, story tree view, job queue enhancements
2. **Story management** — multi-genre stories, clone relocation, editor tweaks, summary page draft access
3. **AI enhancements** — rate-limit retry with backoff, audience targeting, two-level image style selector, mood/lighting modifiers, per-story image settings, guardrails
4. **Admin / automation** — DB-driven genre + image style lists with chip editors, guardrails section, maintenance cron, log & trash retention settings

All phases assume 3 is complete and deployed.

---

## Phase 23 — Global Modal System

Replace all `alert()`, `confirm()`, and `prompt()` JavaScript calls site-wide with a shared
modal component. This phase must come before all others that open modals (tree view, delete
confirmations, clone confirms, etc.).

### Design notes

- One reusable modal: a single `<div id="modal-overlay">` injected by `modal.js` on
  `DOMContentLoaded` — not duplicated per page
- Public API: `Modal.alert(msg)`, `Modal.confirm(msg, onConfirm)`,
  `Modal.open({title, body, buttons})`
- CSS in `styles/modal.css` — uses existing CSS variables (`--bg`, `--text`, `--border`,
  `--accent`) so it is automatically theme-aware
- Both files included on every page via `header.php`

### Task breakdown

#### 23.1 — `styles/modal.css`

New file. Key rules:
- `.modal-overlay` — `position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000`
- `.modal-box` — centred card, `max-width: 480px`, white/theme background
- `.modal-title`, `.modal-body`, `.modal-actions` — layout regions
- Fade transition via `opacity` + `visibility` toggle (no `hidden` attribute — CSS handles visibility)

#### 23.2 — `modal.js`

New file. Injects overlay markup once, exposes `Modal` object:

```js
const Modal = (() => {
    // Inject DOM on first call / DOMContentLoaded
    // Modal.alert(msg, onClose?)
    // Modal.confirm(msg, onConfirm, onCancel?)
    // Modal.open({ title, body, buttons: [{label, className, action}] })
    // Modal.close()
})();
```

Behaviour:
- `Escape` key closes the modal
- Click on overlay backdrop closes the modal (for alert/confirm; not for custom modals unless
  specified)
- `Enter` triggers the primary (first) button

#### 23.3 — Sweep: replace `alert()` / `confirm()`

Grep all `.php`, `.js`, and inline `<script>` blocks for `alert(`, `confirm(`, `prompt(`.

Replace each:

| Old call | New call |
|---|---|
| `alert('msg')` | `Modal.alert('msg')` |
| `if (confirm('msg')) { ... }` | `Modal.confirm('msg', () => { ... })` |
| `if (!confirm('msg')) return;` | `Modal.confirm('msg', () => { /* rest of function */ }); return;` |

For redirect-after-confirm patterns, move the redirect inside the `onConfirm` callback
or submit the form programmatically from within it.

#### 23.4 — Include in `header.php`

```html
<link rel="stylesheet" href="styles/modal.css">
<!-- before </body>: -->
<script src="modal.js"></script>
```

### Files to change

| File | Change |
|---|---|
| `styles/modal.css` | **New** — modal component styles |
| `modal.js` | **New** — Modal API |
| `header.php` | Include modal.css and modal.js |
| All pages with `alert()`/`confirm()` | Replace calls with Modal equivalents |

---

## Phase 24 — Soft Delete & Trash System

Introduces a `deleted` story status so that deleting a story moves it to a trash bin rather
than removing it permanently. Owners can restore; admins can restore or permanently purge.
Permanent deletion is handled automatically by the maintenance cron (Phase 29).

### Design notes

- Status values: `published`, `draft`, **`deleted`** (add to existing ENUM if applicable)
- `date_deleted` column serves dual purpose: marks the record as deleted and timestamps it
  for the maintenance cron's age-based purge
- All existing gallery / search / play / editor queries must exclude deleted stories
- The new `trash.php` page is the only place deleted stories are visible
- Hard-delete from trash ("Delete Forever") is immediate and also removes image files
- Bell icon → queue icon change is bundled here since it is the only other header change

### Task breakdown

#### 24.1 — Schema

Add to `.claude/migration_4.sql` (new file):

```sql
-- Phase 24: soft delete
ALTER TABLE cyoa_ai_stories
    MODIFY COLUMN status ENUM('published','draft','deleted') NOT NULL DEFAULT 'draft',
    ADD COLUMN date_deleted DATETIME NULL DEFAULT NULL AFTER status;
```

> If `status` is already a VARCHAR rather than an ENUM, omit the MODIFY line; the column
> already accepts the new value.

#### 24.2 — DB functions

Add to `db_functions.php` under an `// === Story Soft Delete ===` comment header:

- **`db_soft_delete_story(int $storyId): void`**
  `UPDATE … SET status='deleted', date_deleted=NOW() WHERE story_id=?`

- **`db_restore_story(int $storyId): void`**
  `UPDATE … SET status='draft', date_deleted=NULL WHERE story_id=?`

- **`db_get_deleted_stories(int $userId, bool $isAdmin): array`**
  SELECT all columns + owner username (JOIN users) WHERE `status='deleted'`,
  filtered to `user_id=?` unless `$isAdmin`; ORDER BY `date_deleted DESC`

#### 24.3 — Exclude deleted stories from existing queries

Grep `db_functions.php` for all SELECT queries that return story lists (gallery, search,
"my stories", play.php access check, editor permission check). Add `AND status != 'deleted'`
to each WHERE clause.

> Point-lookup queries (fetch by story_id) are unchanged — they need to see deleted records
> so that trash.php can restore them.

#### 24.4 — Delete action in `editor.php` and `summary.php`

Replace any hard-delete SQL with the soft-delete call:

```php
// POST handler: action=delete
db_soft_delete_story((int)$_POST['storyID']);
$_SESSION['flash_message'] = 'Story moved to Trash.';
$_SESSION['flash_type']    = 'success';
header('Location: index.php');
exit;
```

#### 24.5 — New `trash.php`

Auth-gated page (redirect to login if unauthenticated).

**Layout:** compact table — no full story cards.

```
Thumbnail | Story Title | Date Deleted | [Restore] [Delete Forever*]
```

`*` "Delete Forever" button visible to admins only.

- Shows current user's deleted stories; admins see all
- "Date Deleted" formatted as a readable date string
- If no deleted stories: show "Your trash is empty." (or "The trash is empty." for admins)
- **Restore** → `POST trash.php` `action=restore` → `db_restore_story()` → PRG redirect
- **Delete Forever** → `POST trash.php` `action=delete_forever` → hard DELETE story record
  + delete `images/stories/{storyID}/` folder → PRG redirect
- Both destructive actions confirmed via `Modal.confirm()` (requires Phase 23)

#### 24.6 — Header changes in `header.php`

1. **Queue icon:** Replace the bell icon with a queue/list icon (e.g. a list or clock icon
   from whatever icon set is in use). The dot/badge alert logic is unchanged — only the
   icon glyph changes.

2. **"View Trash" in account dropdown:** Add a menu item linking to `trash.php`, placed
   below the existing account-related links and above Sign Out.

### Files to change

| File | Change |
|---|---|
| `.claude/migration_4.sql` | **New file** — ALTER TABLE for status ENUM + date_deleted |
| `db_functions.php` | Add soft-delete functions; update gallery/search queries |
| `editor.php` | Replace hard-delete with `db_soft_delete_story()` |
| `summary.php` | Same delete action update |
| `trash.php` | **New page** |
| `header.php` | Queue icon + "View Trash" dropdown item |

---

## Phase 25 — Gallery, Genre & Summary Page Improvements

Adds a `genre` column to stories, exposes it in creation/edit forms, and overhauls the
gallery cards and filtering bar. The clone button moves from the gallery card to `summary.php`.
`summary.php` is also fixed to stop auto-redirecting owners/admins to the editor so that
draft stories have a proper landing page. The social section on `summary.php` is redesigned
into a unified horizontal strip with larger icons and numbers, and the "Favorites" feature
is renamed to "Likes" throughout the UI.

### Design notes

- Genre is a fixed dropdown list stored as VARCHAR; nullable (existing stories have no genre)
- Fixed list: Adventure, Fantasy, Sci-Fi, Mystery, Horror, Romance, Comedy, Historical,
  Educational, Other
- Gallery filtering and sorting use GET parameters (`?genre=Fantasy&sort=rating`) so URLs
  are bookmarkable; the dropdowns restore their value from `$_GET` on page load
- `summary.php` currently redirects owners/admins viewing a draft to `editor.php` (lines ~32–36).
  This redirect must be **removed** — the summary page should show both draft and published
  stories to owners/admins, with an "Edit Story" button for those who can edit

### Task breakdown

#### 25.1 — Schema

Add to `migration_4.sql`:

```sql
-- Phase 25: genre
ALTER TABLE cyoa_ai_stories
    ADD COLUMN genre VARCHAR(50) NULL DEFAULT NULL AFTER description;
```

#### 25.2 — DB: genre in story CRUD

- `db_create_story()` — add `genre` parameter, include in INSERT
- `db_update_story_properties()` — include `genre` in UPDATE SET clause
- Gallery query function — add optional `string $genre = ''` and `string $sort = 'latest'`
  parameters; apply `AND genre = ?` and ORDER BY clause based on sort value:
  - `latest` → `ORDER BY created_at DESC`
  - `rating` → `ORDER BY avg_rating DESC, created_at DESC`
  - `views` → `ORDER BY view_count DESC, created_at DESC`
  - `comments` → `ORDER BY comment_count DESC, created_at DESC`

  (JOIN or subquery for rating/views/comments as needed, or use columns already on the
  stories table if social counts are denormalised there.)

#### 25.3 — Remove owner-redirect from `summary.php`

Delete (or comment out) the block:
```php
// Owners viewing a draft get redirected to the editor
if ($story['status'] !== 'published' && ($isOwner || $isAdmin)) {
    header("Location: editor.php?storyID=$storyID");
    exit;
}
```

Replace with: show the page normally. Adjust the "Block drafts from non-owners" check above
it to correctly allow `deleted` stories to also 404 for everyone (they should only appear
in trash.php).

Add an "Edit Story" button on the summary page visible to owners/admins.
Do not record a view for draft stories (keep the `record_view()` call inside a
`if ($story['status'] === 'published')` guard).

#### 25.4 — Gallery card changes (`index.php`)

**Icon changes:**
- Add comment-bubble icon showing comment count (always visible, count may be 0)
- Show all social icons (views, ratings, comments, favourites) on every card, regardless
  of publish state or whether counts are zero

**Button order:** Play button comes **before** Edit button

**Thumbnail link:** for draft stories, link the thumbnail to `summary.php?storyID={id}`
instead of `editor.php?storyID={id}`

**Play button visibility:**
- Currently: Play only shown for published stories
- New: owners always see Play on their own stories; admins always see Play on all stories

**Clone button:** Remove from gallery cards entirely (moved to summary.php in 25.6)

#### 25.5 — Filter/sort bar in `index.php`

Add between the "My Favorites" button and the "Create New Story" button:

```
Filter By: [All Genres ▾]    Sort By: [Latest ▾]
```

- Genre dropdown: "All Genres" (empty value) + the 10 fixed genre values
- Sort dropdown: Latest, Highest Rated, Most Viewed, Most Commented
- Both submit as GET params; page reads `$_GET['genre']` and `$_GET['sort']`, passes to
  gallery query, and re-selects the correct option in each dropdown

#### 25.6 — Clone button on `summary.php`

Add a "Clone Story" button visible to logged-in users:

```html
<form method="POST" action="editor.php">
  <input type="hidden" name="action" value="clone">
  <input type="hidden" name="storyID" value="<?= $storyID ?>">
  <button type="submit" class="btn btn-secondary">Clone Story</button>
</form>
```

Confirm with `Modal.confirm()` before submitting.

Clone POST handler already exists in `editor.php` (or add if not present): clone the story
and all its scenes/choices → redirect to `editor.php?storyID={newStoryID}`.

#### 25.7 — Genre field in story forms

Add a genre `<select>` to:
- Create New Story form (in `index.php` or wherever that form lives)
- Story properties section in `editor.php`
- Story properties section in `summary.php` (for owners/admins)

#### 25.8 — Summary page social strip redesign

**Byline — add scene count and creation date:**

The author byline line changes from:
```
by mira_tales
```
to:
```
by mira_tales · 24 scenes · Added Mar 2, 2026
```

- Scene count: add `COUNT(s.sceneID)` via a LEFT JOIN to `cyoa_ai_scenes` in the story
  fetch query (or a separate `db_count_scenes(int $storyId): int` helper).
- Date format: `date('M j, Y', strtotime($story['date_created']))` → "Mar 2, 2026".

**Social strip — replace scattered social elements with a single horizontal bar:**

Remove any separately positioned view count, favourite button, and star rating widgets from
their current locations on the page. Replace with one `<div class="social-strip">` that
contains all four elements in order:

```
👁 1,204    ♥ 142  [Like]    ★ 4.7 / 5  (38 ratings)  [☆ ☆ ☆ ☆ ☆]
```

Element breakdown:

| Element | Content | Notes |
|---|---|---|
| Views | Eye icon + formatted count | Read-only; already tracked via `cyoa_ai_views` |
| Likes | Heart icon + count + [Like] / [Unlike] button | Drives `api_social.php?action=toggle_favorite`; button label reflects current user's state |
| Rating display | Star icon + average (1 decimal) + rating count | Read-only aggregate |
| Rate widget | 5 interactive stars | Existing star-rating interaction; drives `api_social.php?action=rate` |

**Styling notes:**
- Icons: at least 1.5× the current size (adjust with CSS font-size / SVG dimensions)
- Counts: larger font weight and size — `font-size: 1.3rem; font-weight: 600` or similar
- Strip layout: `display: flex; align-items: center; gap: 2rem; flex-wrap: wrap`
- Separator between groups: use `gap` rather than pipe characters

#### 25.9 — Rename "Favorites" to "Likes" (UI labels only)

Database table (`cyoa_ai_favorites`), DB function names (`db_toggle_favorite`, etc.), and
API action names (`toggle_favorite`) are **not** renamed — the rename is UI text only.

Pages and strings to update:

| Location | Old text | New text |
|---|---|---|
| `summary.php` social strip button | "Add to Favourites" / "Remove from Favourites" | "Like" / "Unlike" |
| `summary.php` social strip count label | "favourites" / "favorites" | "likes" |
| `index.php` gallery filter button | "My Favorites" | "My Likes" |
| `index.php` gallery card icon tooltip | "Favourites" / "favorites" | "Likes" |
| `header.php` or nav | Any "Favorites" link text | "Likes" |
| Flash messages | "Added to favourites" / "Removed from favourites" | "Added to likes" / "Removed from likes" |

Grep for `[Ff]a[vV]ou?rite` across all `.php` files to find all occurrences before making
changes.

### Files to change

| File | Change |
|---|---|
| `.claude/migration_4.sql` | Add genre ALTER TABLE |
| `db_functions.php` | Add genre to create/update; add genre+sort params to gallery query; add scene count to story fetch or as separate helper |
| `summary.php` | Remove owner-draft redirect; add Edit button; add clone button; add genre field; updated byline (scene count + date); social strip redesign; "Likes" labels |
| `index.php` | Card changes; filter/sort bar; remove clone button; genre field on create form; "My Likes" filter button; gallery card "Likes" tooltip |
| `editor.php` | Add genre field to story properties; update clone handler if needed |
| Flash messages (various `.php` files) | "favourites" → "likes" in user-facing strings |

---

## Phase 26 — Story Editor Tweaks

Small targeted changes to `editor.php`.

### Task breakdown

#### 26.1 — Show story owner

Add the story owner's display name (or username) to the editor page header, next to the
story title:

```
Editing: "The Dark Forest"    Owner: evan
```

Source: JOIN `cyoa_ai_users` in the story fetch query (or call the existing user-by-id
helper with `$story['userID']`). Visible to all users who can access the editor.

#### 26.2 — Back button

```php
// Old
<a href="index.php" class="btn btn-secondary">Back to My Stories</a>

// New
<a href="summary.php?storyID=<?= $storyID ?>" class="btn btn-secondary">Back to Landing Page</a>
```

#### 26.3 — "Set to Draft" button label

Rename the "Unpublish" button's visible label to "Set to Draft". No logic change — label
only.

#### 26.4 — Admin delete override

The Delete Story button/action should be available to admins regardless of story status
(published or draft) and regardless of who owns the story.

```php
$canDelete = ($story['userID'] === $currentUserID) || $isAdmin;
```

Replace any conditional that hides the delete button from admins on published stories.

### Files to change

| File | Change |
|---|---|
| `editor.php` | Owner display; back button link + text; "Set to Draft" label; admin delete logic |
| `db_functions.php` | Add username to story fetch JOIN if not already present |

---

## Phase 27 — API Rate-Limit Retry

Adds a consistent retry-with-backoff wrapper around all AI API calls across the three cron
handlers. Currently a 429 rate-limit response immediately fails the job; this phase gives
each call up to 5 retries with a 20-second delay between attempts before giving up.

### Design notes

- One shared helper function handles the retry loop so the logic is not duplicated across handlers
- Each retry attempt is logged to a daily log file (`logs/api_retry_YYYYMMDD.log`)
- The 20-second sleep per retry can consume up to ~100 seconds; the `ai_job_timeout_seconds`
  setting (default 600 s) must be larger than `5 × 20 s = 100 s` — it is, so no conflict
- The helper wraps the raw `curl_exec()` (or equivalent HTTP call) and re-throws or returns
  the final error after all retries are exhausted
- Non-429 errors (500, network failure, etc.) are **not** retried — only 429

### Task breakdown

#### 27.1 — `api_call_with_retry()` helper

Add to a shared cron include (e.g. `cron/ai_helpers.php`, required by all three handlers):

```php
/**
 * Execute a cURL request with retry-on-429 logic.
 *
 * @param resource $ch        Prepared cURL handle (not yet executed)
 * @param int      $maxRetries
 * @param int      $delaySeconds
 * @return array{body: string, http_code: int}
 */
function api_call_with_retry($ch, int $maxRetries = 5, int $delaySeconds = 20): array {
    $attempt = 0;
    while (true) {
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 429 || $attempt >= $maxRetries) {
            return ['body' => $body, 'http_code' => $httpCode];
        }

        $attempt++;
        $logFile = __DIR__ . '/../logs/api_retry_' . date('Ymd') . '.log';
        $line    = date('Y-m-d H:i:s')
                 . " attempt=$attempt http=429 sleeping={$delaySeconds}s\n";
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        sleep($delaySeconds);

        // Reset the cURL handle for reuse
        curl_reset($ch);
        // Re-set options lost by curl_reset (caller must re-apply if needed)
        // Better: accept a callable that builds the handle, call it fresh each time
    }
}
```

> **Implementation note:** `curl_reset()` clears all options, so the cleanest pattern is
> to accept a `callable $buildHandle` instead of a pre-built `$ch`, and call it fresh on
> each attempt. Adjust to match how the existing handlers construct their cURL requests.

#### 27.2 — Update `cron/ai_image_handler.php`

Replace the direct `curl_exec()` call for the OpenAI image API with `api_call_with_retry()`.
Check the returned `http_code` after the call; if still 429 after all retries, mark the job
`failed` with message "Rate limit exceeded after 5 retries."

#### 27.3 — Update `cron/ai_scene_handler.php`

Same pattern — wrap the Claude API `curl_exec()` with `api_call_with_retry()`.

#### 27.4 — Update `cron/ai_story_handler.php`

Wrap each Claude API call (properties phase, plan phase, each scene-write call) with
`api_call_with_retry()`. A 429 on any phase retries that phase independently; if it
ultimately fails, the job is marked `failed` with a clear message indicating which phase failed.

#### 27.5 — Ensure `logs/` directory exists

Add `logs/.gitkeep` if not already present (also required by Phase 29).

### Files to change

| File | Change |
|---|---|
| `cron/ai_helpers.php` | **New** — shared helper include; add `api_call_with_retry()` |
| `cron/ai_image_handler.php` | Wrap OpenAI curl call with retry helper |
| `cron/ai_scene_handler.php` | Wrap Claude curl call with retry helper |
| `cron/ai_story_handler.php` | Wrap each Claude curl call with retry helper |
| `logs/.gitkeep` | **New** (if not already present) |

---

## Phase 28 — DB-Driven Settings: Image Styles, Genres & AI Creator Overhaul

Extends the AI creator with audience targeting, a rich two-level image style selector, and
mood/lighting modifiers. Moves image style options and the story genre list from hardcoded PHP
arrays into admin-editable DB settings with chip-list editors. Upgrades stories from single-genre
to multi-genre (JSON array). Stores chosen image settings on each story so inline cover and scene
image panels can offer a “Use story settings” shortcut.

### Review clarifications (resolved before implementation)

Decisions made during the pre-implementation review of this phase:

1. **Edit-form image settings** — The image-settings row (category / sub-style / mood / quality)
   from 28.9 is added to the **edit** story-properties form as well, not just the create-new AI
   panel, so owners can change a story's default style after creation. 28.10's `save_story`
   handler reads these fields from that form.
2. **Generation-genre list unified to `story_genres`** — The AI panel's generation-genre
   dropdown (`ai-genre`) is driven from `app_setting('story_genres')`, not its own hardcoded
   list. Consequence: `api_create_story_ai.php`'s `$allowedGenres` whitelist must be replaced
   with validation against the `story_genres` setting, and the generation hint sent to Claude
   uses the display-cased values (e.g. "Sci-Fi").
3. **AI genre auto-fills stored genres** — The genre(s) chosen in the AI generation panel are
   written into the new story's stored multi-genre array on creation (via `ai_apply.php`), so
   AI-created stories show a genre chip without manual tagging.

Implementation gaps found during review (handled within the tasks below, no design change):

- **`input_data` is really `input_json`.** Tasks 28.10/28.13/28.15 say `input_data` / `$inputData`;
  the actual job column is `input_json`, decoded to `$input`. Use the real names.
- **Image jobs must carry style/mood/category.** 28.15 reads `image_category`/`image_style`/
  `image_mood` from the *image* job's input, but `ai_apply.php` currently builds image-job input
  with only `prompt`/`theme`/`quality`/`target`. `apply_create_story_result()` (and the cover /
  scene image dispatch) must propagate the three style fields into each image job's `input_json`.
- **`create_story()` / `update_story()` signature.** `genre` is already a scalar 8th param
  (Phase 25). Rather than expand these to ~12 positional params, change genre handling to accept
  an array and add a dedicated `update_story_image_settings()` setter for the four `ai_image_*`
  columns. Update existing call sites (`editor.php`, `ai_apply.php`, `api_create_story_ai.php`,
  `cron/create_stories.php`), which currently pass a scalar genre.
- **`include_images` vs the existing `image_quality !== 'none'` gate.** The "Include Images"
  checkbox replaces the old "none" quality option. When unchecked, send `image_quality = 'none'`
  so the existing image-dispatch gate in `ai_apply.php` keeps working unchanged.
- **Image output format.** `ai_image_handler.php` hardcodes a `.png` filename and sends no
  `output_format`. Wire `app_setting('openai_image_format')` through to the API request and use
  a matching file extension.

### Design notes

**Settings storage**

Four new entries in `cyoa_ai_settings`:

| key | value type | description |
|---|---|---|
| `image_styles` | nested JSON object | keys = category names; values = arrays of sub-style strings |
| `image_moods` | flat JSON array | mood/lighting modifier strings; apply to any style |
| `story_genres` | flat JSON array | replaces the hardcoded `$genreList` array used across the app |
| `openai_image_format` | string | `jpeg` / `png` / `webp`; controls image output format |

Default `image_styles` value:
```json
{
  "Photographic":       ["Photo-realistic", "Cinematic / Film still", "Portrait / Studio lighting", "Golden hour / Natural light", "Black & white / Noir", "Polaroid / Vintage film", "Aerial / Drone shot"],
  "Illustration":       ["Anime / Manga", "Cartoon / Saturday morning cartoon", "Comic book / Marvel-DC style", "Caricature", "Children's book illustration", "Flat design / Vector", "Sticker art", "Pixel art / 8-bit / 16-bit"],
  "Drawing & Painting": ["Sketch / Pencil drawing", "Line drawing / Ink", "Charcoal", "Watercolor", "Oil painting", "Acrylic", "Gouache", "Impressionist", "Pointillism", "Expressionist"],
  "Art Movement / Era": ["Art Deco", "Pop Art (Warhol-style)", "Surrealist", "Renaissance / Baroque", "Ukiyo-e (Japanese woodblock)", "Victorian / Edwardian"],
  "Digital & Concept":  ["Concept art / Game art", "Sci-fi / Futuristic", "Fantasy illustration", "Dark fantasy / Gothic", "Cyberpunk", "Steampunk", "Low poly / 3D render", "Vaporwave / Synthwave"],
  "Craft & Texture":    ["Stained glass", "Mosaic / Tile art", "Graffiti / Street art", "Linocut / Woodcut print", "Embroidery / Needlework", "Claymation / Stop-motion look", "LEGO / Toy style"]
}
```

Default `image_moods` value:
```json
["Dramatic lighting / Chiaroscuro", "Neon / Glowing", "Soft pastel", "High contrast / Monochrome", "Ethereal / Dreamy", "Gritty / Textured"]
```

Default `story_genres` value:
```json
["Adventure", "Fantasy", "Sci-Fi", "Mystery", "Horror", "Romance", "Comedy", "Historical", "Educational", "Other"]
```

**Story independence**

Per-story image settings (`ai_image_category`, `ai_image_style`, `ai_image_mood`,
`ai_image_quality`) are stored as plain strings at the time of creation. If an admin later
removes a category or style from the settings, existing stories are unaffected — their stored
values display as-is in the UI. The settings only govern what options appear for new creations.

**Two linked selects for image style**

The image style picker uses two `<select>` elements side by side:

1. **Category select** — populated from the keys of the `image_styles` JSON
2. **Sub-style select** — populated dynamically via JS when the category changes

A true cascading hover menu was considered but rejected: hover menus are unreliable on touch
devices, require complex JS for diagonal-movement hit detection, and feel out of place next to
native form controls. Two linked selects give the same two-step selection with ~20 lines of JS
and full mobile/keyboard support.

**Multi-genre**

The `genre` column changes from `VARCHAR(50)` to `TEXT` (storing a JSON array). Stories store
genres as a JSON array, e.g. `["Adventure", "Fantasy"]`. Gallery filtering uses
`JSON_CONTAINS()`. The genre list in all PHP files comes from `app_setting('story_genres')` —
the hardcoded `$genreList` arrays are replaced.

**Admin UI for list editors**

*Story genres* — flat chip list:
```
[Adventure ×] [Fantasy ×] [Sci-Fi ×] [Mystery ×] ...
┌────────────────────────┐
│ Add genre...           │  [Enter to add]
└────────────────────────┘
[Save Genres]
```

*Image styles* — one accordion section per category, chip list inside each:
```
Photographic                                [× Remove Category]
  [Photo-realistic ×] [Cinematic ×] [Golden hour ×] ...
  ┌───────────────────────┐
  │ Add sub-style...      │  [Enter to add]
  └───────────────────────┘

Illustration                                [× Remove Category]
  [Anime / Manga ×] [Cartoon ×] ...

┌────────────────────────┐
│ New category name...  │  [+ Add Category]
└────────────────────────┘
[Save Image Styles]
```

*Mood modifiers* — flat chip list (same pattern as genres):
```
[Dramatic lighting ×] [Neon / Glowing ×] [Soft pastel ×] ...
┌────────────────────────┐
│ Add modifier...        │  [Enter to add]
└────────────────────────┘
[Save Mood Modifiers]
```

Category names are add/delete only — not renameable — because renaming would orphan existing
story records that reference the old category name by string.

All three chip list editors share a small JS helper (inline or `chip-list.js`). On save, POST
to `account.php` `action=save_settings` with serialised JSON — same pattern as existing saves.

### Task breakdown

#### 28.1 — Schema migrations

Add to `.claude/migration_4.sql`:

```sql
-- Phase 28: per-story AI image settings + multi-genre
-- Step 1: convert existing single-genre strings to JSON arrays
UPDATE cyoa_ai_stories
    SET genre = CONCAT('["'  , genre, '"]')
    WHERE genre IS NOT NULL AND genre NOT LIKE '[%';

-- Step 2: widen column to TEXT to hold JSON arrays
ALTER TABLE cyoa_ai_stories
    MODIFY COLUMN genre TEXT NULL;

-- Step 3: add per-story AI image columns
ALTER TABLE cyoa_ai_stories
    ADD COLUMN ai_image_category VARCHAR(50)  NULL DEFAULT NULL AFTER genre,
    ADD COLUMN ai_image_style    VARCHAR(100) NULL DEFAULT NULL AFTER ai_image_category,
    ADD COLUMN ai_image_mood     VARCHAR(100) NULL DEFAULT NULL AFTER ai_image_style,
    ADD COLUMN ai_image_quality  VARCHAR(10)  NULL DEFAULT NULL AFTER ai_image_mood;
```

> The CONCAT approach is safe for the existing fixed genre list (none contain quotes). For a
> more robust migration use `JSON_ARRAY(genre)` on MySQL 5.7+ / MariaDB 10.2+.

#### 28.2 — DB settings: seed defaults

Add to `.claude/migration_4.sql`:

```sql
-- Phase 28: content settings
INSERT INTO cyoa_ai_settings (setting_key, setting_value) VALUES
  ('image_styles',        '<full nested JSON from Design Notes above>'),
  ('image_moods',         '["Dramatic lighting / Chiaroscuro","Neon / Glowing","Soft pastel","High contrast / Monochrome","Ethereal / Dreamy","Gritty / Textured"]'),
  ('story_genres',        '["Adventure","Fantasy","Sci-Fi","Mystery","Horror","Romance","Comedy","Historical","Educational","Other"]'),
  ('openai_image_format', 'jpeg');
```

Update `settings.php` SETTING_DEFAULTS to include all four keys so the app works before the
SQL is run. Add the Image Output Format dropdown to the AI Generation section of `account.php`.

#### 28.3 — Admin UI: list editors in `account.php`

Add a **Content Settings** section to the admin panel with three subsections.

**Subsection 1 — Story Genres** (flat chip list)
- Decode `story_genres` JSON; render each value as a chip with `×` delete button
- Text input + Enter/Add button appends a new chip
- Save button POSTs serialised chip values as JSON to `action=save_settings`

**Subsection 2 — Image Styles & Moods** (accordion + chip lists)
- Decode `image_styles`; render one accordion panel per category key
- Each panel: category label, `× Remove Category` button, chip list of sub-styles, add input
- `+ Add Category` text input + button appends a new accordion panel
- Save button serialises the entire nested object back to JSON

**Subsection 3 — Mood Modifiers** (flat chip list, same as genres)
- Backed by `image_moods` setting

All subsections POST to the same `action=save_settings` handler. Use a shared JS function for
chip add/remove (small inline script or `chip-list.js`).

#### 28.4 — Replace hardcoded `$genreList` throughout the app

Grep for `$genreList` in all `.php` files. Replace every occurrence with:

```php
$genreList = json_decode(app_setting('story_genres') ?? '[]', true) ?: [];
```

Files typically affected: `index.php`, `editor.php`, `summary.php`.

#### 28.5 — DB function updates for multi-genre

**`get_all_stories()`** — update genre filter clause:
```php
// Old: AND genre = ?
// New: matches stories containing the selected genre in the JSON array
AND JSON_CONTAINS(genre, JSON_QUOTE(?))
```

**`get_story()`** — JSON-decode genre after fetch:
```php
$row['genre'] = !empty($row['genre']) ? (json_decode($row['genre'], true) ?: []) : [];
```

**`create_story()` / `update_story()`** — accept `array $genres`, JSON-encode for storage:
```php
$genreJson = !empty($genres) ? json_encode(array_values($genres)) : null;
```

**`get_stories_by_user()`, `get_favorites_by_user()`, `search_stories()`** — add the same
genre JSON-decode after each fetch.

#### 28.6 — Multi-genre in `editor.php`

Replace the single genre `<select>` with a chip-based multi-select:

```html
<label>Genres</label>
<div class="genre-chip-editor" id="genre-chip-editor">
    <!-- JS renders current genres as removable chips -->
</div>
<select id="genre-add-select" onchange="addGenreChip(this)">
    <option value="">+ Add genre</option>
    <?php foreach ($genreList as $g): ?>
        <option value="<?= htmlspecialchars($g) ?>"
            <?php if (in_array($g, $storyGenres)) echo 'disabled'; ?>>
            <?= htmlspecialchars($g) ?>
        </option>
    <?php endforeach; ?>
</select>
<input type="hidden" name="genres" id="genres-hidden"
       value="<?= htmlspecialchars(json_encode($storyGenres)) ?>">
```

JS: `addGenreChip(select)` adds a chip + disables that option. `×` on chip removes the chip +
re-enables the option. On form submit, serialise chip values to JSON in `genres-hidden`.

#### 28.7 — Multi-genre display (`index.php` + `summary.php`)

`$story['genre']` is now an array after DB fetch. Loop to render chips:

**`index.php` cards:**
```php
foreach ($story['genre'] as $g):
    echo '<span class="card-genre-chip">' . htmlspecialchars($g) . '</span>';
endforeach;
```

**`summary.php` meta chips:**
```php
foreach ($story['genre'] as $g):
    echo '<span class="meta-chip">&#128218; ' . htmlspecialchars($g) . '</span>';
endforeach;
```

#### 28.8 — Audience dropdown in `editor.php` AI panel

Replace the “Scene Images” `<select>` with an “Audience” `<select>`:

```html
<div class="form-group">
    <label for="ai-audience">Audience</label>
    <select id="ai-audience" name="ai-audience">
        <option value="picture_book">Picture Book</option>
        <option value="early_readers">Early Readers</option>
        <option value="middle_grade" selected>Middle Grade</option>
        <option value="young_adults">Young Adults</option>
        <option value="adults">Adults</option>
    </select>
</div>
```

Include `ai-audience` in the Randomize function's random-pick logic.

#### 28.9 — Image options row in `editor.php` AI panel

Add a second row of image controls. Load settings at the top of the editor PHP block:

```php
$imageStyles = json_decode(app_setting('image_styles') ?? '{}', true) ?: [];
$imageMoods  = json_decode(app_setting('image_moods')  ?? '[]', true) ?: [];
```

HTML for the image row:
```html
<div class="ai-image-row">
    <label class="checkbox-label">
        <input type="checkbox" id="ai-include-images"> Include Images
    </label>
    <div class="ai-image-controls" id="ai-image-controls">
        <select id="ai-image-category" name="ai-image-category" disabled>
            <option value="">Style category…</option>
            <?php foreach (array_keys($imageStyles) as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="ai-image-style" name="ai-image-style" disabled>
            <option value="">Sub-style…</option>
        </select>
        <select id="ai-image-mood" name="ai-image-mood" disabled>
            <option value="">(no mood modifier)</option>
            <?php foreach ($imageMoods as $mood): ?>
                <option value="<?= htmlspecialchars($mood) ?>"><?= htmlspecialchars($mood) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="ai-image-quality" name="ai-image-quality" disabled>
            <option value="low">Low quality</option>
            <option value="medium" selected>Medium quality</option>
            <option value="high">High quality</option>
        </select>
    </div>
</div>
```

**JS — two linked selects:**
```js
const imageStylesData = <?php echo json_encode($imageStyles); ?>;

document.getElementById('ai-image-category').addEventListener('change', function () {
    const subSel = document.getElementById('ai-image-style');
    const subs   = imageStylesData[this.value] || [];
    subSel.innerHTML = '<option value="">Sub-style…</option>'
        + subs.map(s => `<option value="${s}">${s}</option>`).join('');
    subSel.disabled = subs.length === 0 || !document.getElementById('ai-include-images').checked;
});
```

**JS — enable/disable on checkbox change:**
```js
document.getElementById('ai-include-images').addEventListener('change', function () {
    document.getElementById('ai-image-controls').querySelectorAll('select').forEach(s => {
        s.disabled = !this.checked;
    });
    if (!this.checked) return;
    const cat = document.getElementById('ai-image-category').value;
    document.getElementById('ai-image-style').disabled = !cat;
});
// Initialise: all disabled on load
document.getElementById('ai-image-controls').querySelectorAll('select')
    .forEach(s => { s.disabled = true; });
```

#### 28.10 — Per-story image settings storage

**`editor.php` save_story POST handler:** read and pass the new columns:
```php
$aiImageCategory = trim($_POST['ai_image_category'] ?? '');
$aiImageStyle    = trim($_POST['ai_image_style']    ?? '');
$aiImageMood     = trim($_POST['ai_image_mood']     ?? '');
$aiImageQuality  = trim($_POST['ai_image_quality']  ?? '');
```

**`db_functions.php` `create_story()` / `update_story()`:** add the four new columns to
INSERT/UPDATE.

**`cron/ai_apply.php`:** when applying a completed full-story job, also write `ai_image_*`
from `input_data` to the story record.

#### 28.11 — “Use story settings” checkbox in inline image panels

Extend the inline “Use AI” panels for cover and scene image generation:

```html
<?php if (!empty($story['ai_image_category'])): ?>
<label class="checkbox-label">
    <input type="checkbox" class="use-story-style" id="use-story-style-<?= $panelId ?>" checked>
    Use story's image settings
    <span class="use-story-style-hint">
        (<?= htmlspecialchars($story['ai_image_category']); ?>
        — <?= htmlspecialchars($story['ai_image_style']); ?>)
    </span>
</label>
<?php endif; ?>

<div class="inline-image-style-controls" id="inline-style-controls-<?= $panelId ?>">
    <!-- Same two-linked-select + mood + quality pattern as 28.9 -->
    <!-- Pre-filled with story's stored values when first revealed -->
</div>
```

**JS behaviour:**
- Checkbox checked: submit story's stored values via hidden inputs; hide manual controls
- Checkbox unchecked: reveal manual controls pre-filled with story's stored values
- No stored story settings: skip the checkbox, show manual controls directly
- If stored values are no longer in current settings: still show them as a selected `<option>`

#### 28.12 — Randomize function update

When “Include Images” is checked, Randomize also picks:
- Random category key from `imageStylesData`
- Random sub-style from `imageStylesData[category]`
- Random mood from the moods array (or `""` for no modifier, ~20% probability)
- Random quality (low / medium / high)

Then updates all four controls to reflect the random picks.

#### 28.13 — Job input_data and cron handler updates

**`api_create_story_ai.php`:** add to job `input_data` JSON:
```php
'audience'       => $_POST['ai-audience']        ?? 'middle_grade',
'include_images' => !empty($_POST['ai-include-images']),
'image_category' => $_POST['ai-image-category']  ?? '',
'image_style'    => $_POST['ai-image-style']     ?? '',
'image_mood'     => $_POST['ai-image-mood']      ?? '',
'image_quality'  => $_POST['ai-image-quality']   ?? 'medium',
```

**`cron/ai_story_handler.php`:** read these fields from `input_data`; pass `audience` to
prompt builder; pass image settings through to image job dispatch.

#### 28.14 — Claude prompt updates for audience

Add `{AUDIENCE}` section to `prompts/story_plan_system.txt` and
`prompts/story_scene_writer_system.txt`:

```
Audience: {AUDIENCE}
Adjust language, vocabulary, and complexity to match:
- picture_book:  Very simple words, 1–2 sentences per scene, concrete imagery only
- early_readers: Simple vocabulary, short sentences, relatable characters (ages 6–9)
- middle_grade:  Moderate complexity, relatable themes (ages 9–12)
- young_adults:  Nuanced themes, more complex choices (ages 13–18)
- adults:        Full complexity; mature themes acceptable
```

#### 28.15 — Image prompt composition update

In `cron/ai_image_handler.php`:

```php
$category = $inputData['image_category'] ?? '';
$style    = $inputData['image_style']    ?? '';
$mood     = $inputData['image_mood']     ?? '';

$stylePart = $style    ? " in {$style} style"     : ($category ? " in {$category} style" : '');
$moodPart  = $mood     ? ", {$mood}"               : '';
$fullPrompt = $subjectPrompt . $stylePart . $moodPart;
```

Pass `app_setting('openai_image_format')` as `output_format` to the OpenAI API. Save with the
correct file extension.

### Files to change

| File | Change |
|---|---|
| `.claude/migration_4.sql` | ALTER TABLE (genre TEXT + ai_image_* columns); data migration UPDATE; INSERT new settings rows |
| `settings.php` | Add image_styles, image_moods, story_genres, openai_image_format to SETTING_DEFAULTS |
| `account.php` | Add Content Settings section (genres chip list, image styles accordion, moods chip list); Image Output Format dropdown |
| `db_functions.php` | Multi-genre: JSON_CONTAINS filter, JSON decode after fetch, JSON encode on write; add ai_image_* to create/update |
| `index.php` | Replace hardcoded `$genreList`; multi-genre display on cards |
| `editor.php` | Replace hardcoded `$genreList`; multi-genre chip editor; audience dropdown; image options row with two linked selects + JS; inline panel “Use story settings”; save ai_image_* fields |
| `summary.php` | Replace hardcoded `$genreList`; multi-genre meta chips |
| `api_create_story_ai.php` | Add audience + image fields to input_data |
| `cron/ai_story_handler.php` | Read audience + image settings from input_data; pass to handlers |
| `cron/ai_apply.php` | Write ai_image_* from input_data to story record on completion |
| `cron/ai_image_handler.php` | Compose style/mood into prompt; pass output_format to API; save with correct extension |
| `prompts/story_plan_system.txt` | Add {AUDIENCE} section |
| `prompts/story_scene_writer_system.txt` | Add {AUDIENCE} section |

---


## Phase 29 — AI Guardrails

Adds admin-configurable content guardrails to all AI generation. Claude is instructed to
return a `red_flag` field when generated content breaches a guardrail topic; image prompts
receive a "Do not depict" prefix. Breaches set the job to error and are logged.

### Design notes

- Two new admin settings: `guardrails_enabled` (bool) and `guardrails_text` (multi-line,
  one rule per line)
- Seeds with the six topics from the notes
- Claude prompt injection: appended to system prompts when guardrails are enabled
- OpenAI image injection: prepend "Do not depict: {topics}." to the final image prompt
- On red_flag detection: job status → `error`, error_message contains the breached topic,
  event logged to `cron/logs/guardrails_YYYYMMDD.log`
- If `guardrails_enabled` is false: no injection, no red_flag checks

### Task breakdown

#### 29.1 — New admin settings

Add to `migration_4.sql`:
```sql
INSERT INTO cyoa_ai_settings (setting_key, setting_value) VALUES
    ('guardrails_enabled', '1'),
    ('guardrails_text',
     'Child Abuse\nSuicide\nExplicit sexual content or nudity\nExtreme graphic violence or gore\nDeeply nihilistic or hopeless themes\nDrug/alcohol use');
```

Add to `SETTING_DEFAULTS` in `settings.php`:
```php
'guardrails_enabled' => '1',
'guardrails_text'    => "Child Abuse\nSuicide\nExplicit sexual content or nudity\n"
                      . "Extreme graphic violence or gore\nDeeply nihilistic or hopeless themes\n"
                      . "Drug/alcohol use",
```

#### 29.2 — Admin panel: Guardrails section

Add a "Guardrails" section to `account.php` (admin only), placed before the Maintenance
section (Phase 29):

```
[x] Enable Guardrails

Content Restrictions (one per line):
[textarea — 6 rows]
```

POST handler: save `guardrails_enabled` and `guardrails_text` via `db_set_setting()`.

#### 29.3 — Helper: `get_guardrail_clause()`

Add to `settings.php`:

```php
function get_guardrail_clause(): string {
    if (!(bool)(int) app_setting('guardrails_enabled')) return '';
    $lines = array_filter(array_map('trim',
        explode("\n", app_setting('guardrails_text') ?? '')
    ));
    if (empty($lines)) return '';
    return implode(', ', $lines);
}
```

Returns an empty string when guardrails are disabled or the list is empty; callers skip
injection entirely when it returns empty.

#### 29.4 — Inject into Claude system prompts

In each cron handler that calls the Claude API, after building the system prompt with
`load_prompt()`, conditionally append:

```php
$guardrailClause = get_guardrail_clause();
if ($guardrailClause !== '') {
    $systemPrompt .= "\n\nContent guardrails: Never generate content involving: "
        . "$guardrailClause. If any part of your response would involve these topics, "
        . 'include a "red_flag" field in your JSON response with the name of the '
        . "breached topic as its string value.";
}
```

Handlers to update: `cron/ai_story_handler.php`, `cron/ai_scene_handler.php`.

#### 29.5 — Inject into OpenAI image prompts

In `cron/ai_image_handler.php`, before assembling the final prompt string:

```php
$guardrailClause = get_guardrail_clause();
if ($guardrailClause !== '') {
    $fullPrompt = "Do not depict: $guardrailClause. " . $fullPrompt;
}
```

#### 29.6 — Red flag detection in Claude handlers

After receiving and JSON-decoding a Claude response, check for the `red_flag` field:

```php
if (!empty($responseData['red_flag'])) {
    $breached = (string)$responseData['red_flag'];
    db_update_job_status($jobId, 'error',
        'Inappropriate Content Detected: ' . $breached);
    log_guardrail_breach($jobId, $breached);
    return; // abort further processing of this job
}
```

#### 29.7 — `log_guardrail_breach()` helper

Add to the shared cron include `cron/ai_helpers.php` (created in Phase 27). It is called only
from the cron handlers, and placing it there makes `__DIR__ . '/logs'` resolve to `cron/logs/`
— the same directory the worker, dispatcher, and Phase 27 retry log already use:

```php
function log_guardrail_breach(int $jobId, string $topic): void {
    $logDir  = __DIR__ . '/logs';   // cron/logs/ (ai_helpers.php lives in cron/)
    $logFile = $logDir . '/guardrails_' . date('Ymd') . '.log';
    $line    = date('Y-m-d H:i:s') . " job_id=$jobId breached=\"$topic\"\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
```

The `cron/logs/` directory already exists; `ai_helpers.php` also creates it defensively.

#### 29.8 — Apply red_flag check to all Claude handlers

- `cron/ai_story_handler.php` — check after each Claude phase response (properties,
  plan, each scene write)
- `cron/ai_scene_handler.php` — check after scene generation response

### Files to change

| File | Change |
|---|---|
| `.claude/migration_4.sql` | INSERT guardrails settings rows |
| `settings.php` | Add defaults; add `get_guardrail_clause()` |
| `cron/ai_helpers.php` | Add `log_guardrail_breach()` (writes to `cron/logs/`) |
| `account.php` | Add Guardrails admin section |
| `cron/ai_story_handler.php` | Append guardrail clause; add red_flag check |
| `cron/ai_scene_handler.php` | Append guardrail clause; add red_flag check |
| `cron/ai_image_handler.php` | Prepend guardrail "Do not depict" to image prompt |

---

## Phase 30 — Admin Maintenance Section

Adds a Maintenance section to the admin panel for controlling automatic cleanup of deleted
stories and log files. A new `cron/maintenance.php` CLI script performs the actual work,
invoked daily by the AI dispatcher.

### Design notes

- Two new settings: `trash_retention` and `log_retention`, both accepting `1day`, `1week`,
  `1month`
- The dispatcher checks whether today's maintenance log already exists; if not, it launches
  `maintenance.php` as a background process
- `maintenance.php` itself has a secondary hour-based guard (skip if log file is < 1 hour old)
  to prevent double-runs if the dispatcher fires multiple times
- "Empty Now" and "Delete Logs Now" admin buttons pass `--force` to bypass the age guard

### Task breakdown

#### 30.1 — New admin settings

Add to `migration_4.sql`:
```sql
INSERT INTO cyoa_ai_settings (setting_key, setting_value) VALUES
    ('trash_retention', '1week'),
    ('log_retention',   '1month');
```

Add to `SETTING_DEFAULTS` in `settings.php`:
```php
'trash_retention' => '1week',
'log_retention'   => '1month',
```

#### 30.2 — Admin panel: Maintenance section

Add to `account.php` (admin only), after the Guardrails section:

```
Keep stories in trash for: [1 day | 1 week | 1 month ▾]   [Empty Now]
Keep logs for:             [1 day | 1 week | 1 month ▾]   [Delete Logs Now]
```

- Retention dropdowns save with the main admin settings POST
- "Empty Now" and "Delete Logs Now" are separate small forms
  (e.g. `action=empty_trash_now` and `action=delete_logs_now`)
- Their POST handlers run `maintenance.php --force` via `exec()`, then PRG-redirect

#### 30.3 — Retention → SQL interval helper

Add to `settings.php`:

```php
function retention_to_interval(string $val): string {
    return match($val) {
        '1day'  => 'INTERVAL 1 DAY',
        '1week' => 'INTERVAL 7 DAY',
        default => 'INTERVAL 30 DAY',  // 1month
    };
}
```

#### 30.4 — DB: `db_purge_deleted_stories(string $interval): array`

Add to `db_functions.php`:

Returns the `story_id` values of stories that will be purged **before** deleting them
(so the caller can remove their image folders):

```php
function db_purge_deleted_stories(string $interval): array {
    // SELECT story_id WHERE status='deleted' AND date_deleted < NOW() - {$interval}
    // Then DELETE those rows
    // Return the collected story_id array
}
```

#### 30.5 — `cron/maintenance.php`

New CLI script:

```
php cron/maintenance.php [--force]
```

Logic:
1. Resolve log file path: `cron/logs/maintenance_YYYYMMDD.log` (i.e. `__DIR__ . '/logs/maintenance_' . date('Ymd') . '.log'`, since `maintenance.php` lives in `cron/`)
2. If log exists and `filemtime() > time() - 3600` and `--force` not passed → exit silently
3. `require_once '../config.php'`, `'../db_functions.php'`, `'../settings.php'`
4. **Trash cleanup:**
   - `$interval = retention_to_interval(app_setting('trash_retention'))`
   - `$ids = db_purge_deleted_stories($interval)` — deletes DB rows, returns IDs
   - For each ID, delete `images/stories/{id}/` folder if it exists
   - Log: `"Trash: purged N stories."`
5. **Log cleanup:**
   - `$interval = retention_to_interval(app_setting('log_retention'))`
   - `glob(__DIR__ . '/logs/*.log')` — delete files in `cron/logs/` whose `filemtime()` is older than the interval
   - Log: `"Logs: deleted N files."`
6. Write summary line to `cron/logs/maintenance_YYYYMMDD.log`

#### 30.6 — Update `cron/ai_dispatcher.php`

After the main job-processing loop, add:

```php
// Run maintenance once per calendar day
// ai_dispatcher.php lives in cron/, so __DIR__ . '/logs' is cron/logs/
$maintenanceLog = __DIR__ . '/logs/maintenance_' . date('Ymd') . '.log';
if (!file_exists($maintenanceLog)) {
    exec('php ' . escapeshellarg(__DIR__ . '/maintenance.php') . ' > /dev/null 2>&1 &');
}
```

### Files to change

| File | Change |
|---|---|
| `.claude/migration_4.sql` | INSERT trash_retention + log_retention settings |
| `settings.php` | Add defaults; add `retention_to_interval()` |
| `account.php` | Add Maintenance admin section |
| `db_functions.php` | Add `db_purge_deleted_stories()` |
| `cron/maintenance.php` | **New** CLI script |
| `cron/ai_dispatcher.php` | Launch maintenance.php once per day |

---

## Phase 31 — Story Tree View

Adds a "Tree View" button to the story editor that opens a modal containing an SVG-rendered,
navigable tree of all scenes in the story. Nodes are clickable links to the scene editor.

### Design notes

- Root node = scene flagged as `is_start = 1` (or the first scene by creation order if no
  flag exists — check schema before implementing)
- Edges = choices from one scene to another
- Layout: top-down tree; depth tiers correspond to story progression levels
- Cycles (a choice loops back to a previous scene): detect with a visited set; render a
  dashed edge back to the already-rendered node rather than recursing
- Thumbnails: use existing scene thumbnail image if present; fallback to a coloured circle
  with the scene number
- Nodes link to `editor.php?storyID={id}&scene={sceneID}`
- SVG is placed inside a scrollable, pannable container in the modal
- Node hover: CSS `drop-shadow` filter for a glow effect

### Task breakdown

#### 31.1 — Data endpoint `api_tree.php`

New GET endpoint:

```
GET api_tree.php?storyID=N
→ JSON {
    scenes:  [{id, title, thumbnail, is_start}],
    choices: [{from_scene_id, to_scene_id, label}]
  }
```

Auth: user must own the story or be admin; return 403 JSON otherwise.

Query: SELECT from `cyoa_ai_storypoints` and `cyoa_ai_choices` for the given story.

#### 31.2 — Tree layout and SVG renderer (`tree-view.js`)

New file `tree-view.js`. Core function: `buildTreeSVG(scenes, choices) → SVGElement`

**Algorithm:**

1. Build adjacency list from `choices`
2. Find root: `scenes.find(s => s.is_start)` or `scenes[0]`
3. BFS from root: assign each node a `{depth, siblingIndex}` — track visited set to
   detect and skip cycle edges (store them separately)
4. Calculate pixel positions:
   - `y = depth * NODE_SPACING_Y`
   - `x = siblingIndex * NODE_SPACING_X + depthOffset` (centre siblings within their tier)
5. Create SVG elements:
   - `<line>` or `<path>` for each forward edge (solid stroke)
   - `<line>` with `stroke-dasharray` for each cycle edge
   - For each node: `<a href="…">` wrapping a `<rect>` + `<image>` (thumbnail) +
     `<text>` (title truncated to ~20 chars)
6. Size the `<svg>` to fit all nodes plus padding
7. Return the `<svg>` element

**Pan/zoom:**
- Wrap the `<svg>` in a `<div class="tree-container">`
- Mouse wheel → `transform: scale()` on the SVG
- Mouse drag → `transform: translate()` on the SVG
- Keep both transforms combined via a single `transform` string

#### 31.3 — Modal trigger in `editor.php`

Add "Tree View" button to the right of the "Add Scene" button:

```html
<button type="button" id="btn-tree-view" class="btn btn-secondary">Tree View</button>
```

Inline script or separate block in `editor.php`:

```js
document.getElementById('btn-tree-view').addEventListener('click', () => {
    fetch('api_tree.php?storyID=' + storyID)
        .then(r => r.json())
        .then(data => {
            const svg = buildTreeSVG(data.scenes, data.choices);
            const container = document.createElement('div');
            container.className = 'tree-container';
            container.appendChild(svg);
            Modal.open({
                title: 'Story Tree',
                body: container,
                buttons: [{ label: 'Close', className: 'btn-secondary', action: Modal.close }]
            });
        });
});
```

Load `tree-view.js` on the editor page (add `<script src="tree-view.js">` in `editor.php`
or in `header.php` conditionally).

#### 31.4 — Styles

Add to `styles/editor.css` (or new `styles/tree-view.css`):

```css
.tree-container {
    overflow: auto;
    max-height: 70vh;
    cursor: grab;
    user-select: none;
}
.tree-container:active { cursor: grabbing; }

.tree-node { cursor: pointer; }
.tree-node rect { transition: filter 0.15s; }
.tree-node:hover rect,
.tree-node:focus rect {
    filter: drop-shadow(0 0 6px var(--accent));
}

.tree-edge       { stroke: var(--border); stroke-width: 1.5; fill: none; }
.tree-edge-cycle { stroke-dasharray: 5 3; stroke: var(--text-light); }
```

### Files to change

| File | Change |
|---|---|
| `api_tree.php` | **New** — scene + choice data endpoint |
| `tree-view.js` | **New** — layout algorithm + SVG renderer |
| `editor.php` | Add Tree View button; load tree-view.js; add fetch + modal call |
| `styles/editor.css` or `styles/tree-view.css` | Tree node, edge, and container styles |

---

## Phase 32 — Job Queue Enhancements

Upgrades `job_queue.php` with a stat dashboard (admin only), search/filter bar (all users),
a "Clear Completed" button, and a job detail modal showing raw input/result JSON with
syntax highlighting.

**Prerequisites:** Phase 23 (modal system) must be complete.

### Design notes

- Stat cards and "Clear Completed" are admin-only; the search/filter bar is visible to all
- Regular users always see only their own jobs; admins see all jobs regardless of filter
- Filtering is server-side via GET params (`?status=&type=&q=&period=`) so filters persist
  across page loads
- The detail modal uses `highlight.js` for JSON syntax highlighting (CDN or local copy);
  consistent with the library-allowed policy applied to the tree view
- "Clear Completed" hard-deletes `completed`, `failed`, and `cancelled` job rows.
  For regular users: only their own rows. For admins: all rows site-wide. Uses
  `Modal.confirm()` before proceeding.

### Task breakdown

#### 32.1 — Admin stat cards

Displayed above the filter bar for admins only. Fetched from a single DB query on page load.

**Cards (left to right):**

| Card | Value | Colour |
|---|---|---|
| Pending | COUNT WHERE `status='pending'` | neutral / blue |
| Running | COUNT WHERE `status='running'` | blue / active |
| Completed | COUNT WHERE `status='completed'` AND `DATE(created_at) = CURDATE()` | green |
| Failed | COUNT WHERE `status='failed'` AND `DATE(created_at) = CURDATE()` | red |
| Total Today | COUNT WHERE `DATE(created_at) = CURDATE()` | neutral |
| Spent Today | SUM(`cost_usd`) WHERE `DATE(created_at) = CURDATE()`, formatted as `$0.0000` | gold / amber |

All counts are system-wide (not filtered by user).

Add `db_get_job_stats(): array` to `db_functions.php` returning all six values in one query
(use conditional aggregates: `SUM(status = 'pending')` etc.).

#### 32.2 — Search / filter bar

Rendered for all users (above the table, below the stat cards).

```
[🔍 Search by story or user…]  [All Statuses ▾]  [All Job Types ▾]  [Today ▾]
```

- **Search text** (`?q=`) — matches against story title (and username for admins)
- **Status** (`?status=`) — All Statuses, Pending, Running, Completed, Failed, Cancelled
- **Job Type** (`?type=`) — All Types, Image, Scene, Story
- **Period** (`?period=`) — Today, This Week, All Time
- Dropdowns and search box are pre-filled from GET params on page load
- Form submits as GET; `<button type="submit">` is not needed — use JS `onchange` to
  auto-submit the form when a dropdown changes; search box submits on Enter

**Query building in `job_queue.php`:**

Apply filters to the existing gallery query. Regular users always have `AND user_id = ?`
appended regardless of filter values.

#### 32.3 — "Clear Completed" button

Place in the top-right area of the page (beside the page title or filter bar).
Visible to all logged-in users.

```html
<form method="POST" id="form-clear-completed">
  <input type="hidden" name="action" value="clear_completed">
  <button type="button" class="btn btn-danger-outline" onclick="confirmClear()">
    Clear Completed
  </button>
</form>
```

```js
function confirmClear() {
    Modal.confirm(
        'Remove all completed, failed, and cancelled jobs? This cannot be undone.',
        () => document.getElementById('form-clear-completed').submit()
    );
}
```

POST handler:
- Regular user: `DELETE FROM cyoa_ai_jobs WHERE user_id = ? AND status IN ('completed','failed','cancelled')`
- Admin: `DELETE FROM cyoa_ai_jobs WHERE status IN ('completed','failed','cancelled')`
- PRG redirect back to `job_queue.php` with current GET params preserved

Add `db_clear_completed_jobs(int $userId, bool $isAdmin): int` to `db_functions.php`
(returns count of deleted rows for the flash message).

#### 32.4 — Job detail modal

A "View" button on each job row opens a modal with full job details and syntax-highlighted JSON.

**Trigger:** Each table row gets a `<button class="btn btn-sm" onclick="showJobDetail(N)">View</button>`
where `N` is the job ID.

**Data source:** A new `GET api_jobs.php?action=detail&job_id=N` action returns:

```json
{
  "job_id": 1048,
  "job_type": "story",
  "status": "running",
  "user_name": "evan_w",
  "story_title": "Star Pirates (new)",
  "scene_title": null,
  "created_at": "2026-05-30 10:14:00",
  "started_at": "2026-05-30 10:14:05",
  "cost_usd": 0.0312,
  "error_message": null,
  "input_json": { ... },
  "result_json": { ... }
}
```

Auth: user must own the job or be admin.

**Modal content:**

```
Job #1048 — Full Story · evan_w · [Running badge]

INPUT JSON                    RESULT JSON
┌─────────────────────────┐   ┌─────────────────────────┐
│ { syntax highlighted    │   │ { syntax highlighted     │
│   JSON here ... }       │   │   JSON here ... }        │
└─────────────────────────┘   └─────────────────────────┘

Created: 2026-05-30 10:14    Cost: $0.0312    [Close]
```

Both JSON panels use a monospace font with `overflow: auto; max-height: 300px`.

**Syntax highlighting:** Include `highlight.js` (JSON language pack only) from CDN or
a local copy in `vendor/`. Call `hljs.highlightElement()` on each JSON `<pre>` block
after the modal opens.

```js
function showJobDetail(jobId) {
    fetch('api_jobs.php?action=detail&job_id=' + jobId)
        .then(r => r.json())
        .then(data => {
            // Build modal body HTML with pre-filled fields
            // Open via Modal.open(...)
            // After open: call hljs.highlightElement() on both <pre> blocks
        });
}
```

**Add to `api_jobs.php`:** `GET ?action=detail&job_id=N` — returns the JSON above.
Auth: user must own the job or be admin.

### Files to change

| File | Change |
|---|---|
| `job_queue.php` | Stat cards (admin); filter/search bar; auto-refresh JS; Clear Completed button; View buttons on rows |
| `api_jobs.php` | Add `GET ?action=detail&job_id=N` |
| `db_functions.php` | Add `db_get_job_stats()`, `db_clear_completed_jobs()` |
| `styles/job_queue.css` (or equivalent) | Stat card styles, filter bar layout, JSON panel styles |
| `vendor/highlight.min.js` or CDN link | highlight.js for JSON syntax highlighting |

---

## Phase 33 — Documentation Update

No code changes. Updates all `.claude/` steering documents to reflect the full 3 + 4
implementation. Includes all deferred Phase 22 (3) items.

### Task breakdown

#### 33.1 — Apply all Phase 22 (3) items first

Work through every item in 3 Phase 22 before adding 4 content:
- `architecture.md` sections 1.1, 2.2, 2.5, 2.6 (image paths, job types, result
  application, API config migration to DB settings)
- `api-endpoints.md` updates
- `database-schema.md` updates

#### 33.2 — `architecture.md`

Add or update sections for:
- **Soft delete:** deleted status, date_deleted, trash.php, maintenance purge
- **Genre:** new field, gallery filter/sort
- **Modal system:** modal.js/css, global inclusion
- **Admin settings:** add openai_image_format, guardrails_enabled, guardrails_text,
  trash_retention, log_retention to the settings table documentation
- **Guardrails:** injection pattern for Claude and OpenAI, red_flag handling, breach log
- **Maintenance:** maintenance.php, dispatcher integration, log directory
- **Tree view:** api_tree.php, client-side SVG renderer, pan/zoom

#### 33.3 — `database-schema.md`

Add:
- `date_deleted DATETIME NULL` on stories table
- `genre VARCHAR(50) NULL` on stories table
- New settings rows: openai_image_format, guardrails_enabled, guardrails_text,
  trash_retention, log_retention

#### 33.4 — `api-endpoints.md`

- Add `GET api_tree.php?storyID=N` documentation
- Verify all other endpoints are still current

#### 33.5 — `CLAUDE.md`

Add new entries to the Key Files table:
- `trash.php` — Trash bin page (restore / permanent-delete deleted stories)
- `api_tree.php` — Returns scene + choice tree data for a story
- `cron/maintenance.php` — Daily cleanup: purge old trash + old logs
- `modal.js` — Global modal component (alert, confirm, custom)
- `cascade-select.js` — Cascading grouped dropdown component
- `tree-view.js` — Story tree SVG renderer

Add directory notes for `logs/` and `prompts/`.

### Files to change

| File | Change |
|---|---|
| `.claude/architecture.md` | Multiple sections per tasks above |
| `.claude/database-schema.md` | Add new columns and settings |
| `.claude/api-endpoints.md` | Add tree endpoint; verify others |
| `CLAUDE.md` | Add new files to Key Files table; add logs/ and prompts/ directory notes |
