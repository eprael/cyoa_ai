# Implementation Plan 7 — Story Image Gallery

Per-story image gallery view (analogous to the existing Tree View). Global/site-wide
gallery is **deferred** (needs more thought on navigation + image volume); the data
layer is built behind a reusable seam so a global mode can be added later without a
rewrite.

## Decisions (confirmed with user)

- **Scope:** per-story only for now. Architect for an eventual global mode.
- **Contents:** story **cover image first**, then every **scene that has an image**.
  Scenes without an image are skipped (it's an *image* gallery).
- **Settings UI:** preset dropdowns (Small/Medium/Large), matching the existing
  Scene Thumbnail Size setting — not freeform px.
- **Access:** owner/admin only, from the Story Editor and the Summary page.
- **Theme:** standalone page using the app theme (light/dark/ocean/forest/ember);
  `header.php` already sets `data-theme` on `<html>` from `localStorage`.

## Architecture

- **Data seam — `get_gallery_items(int $storyID): array`** (db_functions.php).
  Returns an ordered list of `{type, id, title, src}` items (cover first, then
  scenes with images), resolving shadow-draft image folders the same way
  summary.php / api_tree.php do. This single function is the extension point: a
  future global gallery aggregates across stories by calling/queries alongside it.
- **`gallery.php`** — standalone page. Auth = owner/admin (mirrors api_tree.php).
  Renders the tile grid server-side from `get_gallery_items()` (finite per-story
  set, no AJAX needed) and embeds the same items as JSON for the JS lightbox.
  - Breadcrumb driven by `?from=` (`editor` default, or `summary`):
    - summary: Home › My Stories › {title→summary} › Gallery
    - editor:  Home › My Stories › {title→summary} › Editor › Gallery
  - "← Back to Editor/Summary" link.
- **`styles/gallery.css`** — grid with CSS vars for tile size + gap (set inline
  from settings); raised tiles w/ drop shadow; single-line ellipsis caption +
  full-title tooltip; lightbox with edge chevrons + bottom filmstrip. Theme-aware
  via the global app theme variables.
- **`gallery.js`** — lightbox open/close, prev/next with wrap-around, keyboard
  (←/→/Esc), filmstrip thumbnails (click to jump, active highlighted + scrolled
  into view), scene title shown top-left.

## Settings (Site Settings → new "Gallery" block)

| Key | Presets | Default |
|-----|---------|---------|
| `gallery_tile_size`      | Small 160 / Medium 220 / Large 300 | 220 |
| `gallery_filmstrip_size` | Small 56 / Medium 72 / Large 96    | 72  |
| `gallery_tile_spacing`   | Tight 8 / Normal 16 / Roomy 28     | 16  |

Preset maps live in `config.php` (like `SCENE_THUMB_SIZES`); defaults in
`settings.php`; persisted via `settings_site.php` `$intKeys`.

## Entry points

- **editor.php** — "Gallery" button beside the Tree View button in the Scenes
  header (shown when the story has any image). Links
  `gallery.php?storyID=N&from=editor`.
- **summary.php** — "Gallery" button in `.summary-actions-right` (owner/admin,
  beside Tree View). Links `gallery.php?storyID=N&from=summary`.

## Files

New: `gallery.php`, `gallery.js`, `styles/gallery.css`.
Edit: `config.php`, `settings.php`, `settings_site.php`, `db_functions.php`,
`editor.php`, `summary.php`.
