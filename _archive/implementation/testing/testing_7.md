# Phase 7 — UI Redesign

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### New Nav Bar — Layout

- [ ] Open any page while logged in — confirm the navbar is **64px tall** (single row, no stacking)
- [ ] Left side shows the logo image + **AIdventure** brand text
- [ ] Center shows navigation links: **Explore**, **My Stories**, **Generate**
- [ ] Right side shows: bell icon, shield icon (admin only), user avatar button
- [ ] Nav bar is **sticky** — scroll down a long page and confirm it stays at the top

---

### Active Link Detection

- [ ] On `index.php` (no filter) — **Explore** link is highlighted (`.active` class)
- [ ] On `index.php?filter=mine` — **My Stories** link is highlighted
- [ ] On `generate_story.php` — **Generate** link is highlighted
- [ ] On `editor.php` — no center link is highlighted

---

### Generate Link — Visibility

- [ ] Log in — confirm **Generate** appears in the center nav
- [ ] Log out — confirm **Generate** is gone (guest sees only **Explore**)

---

### Job Queue Bell

- [ ] Log in — bell icon is visible in the right nav actions
- [ ] Click the bell — confirm it navigates to `job_queue.php`
- [ ] With no unseen completed/failed jobs — no red badge on the bell
- [ ] Insert a completed job with `seen_at = NULL` in phpMyAdmin — within 5 seconds a red badge appears on the bell with the count

---

### Admin Shield (admin user only)

- [ ] Log in as admin — confirm the shield icon appears between the bell and the avatar
- [ ] Click the shield — confirm a dropdown opens with **Admin** header and **Admin Panel** link
- [ ] Click **Admin Panel** — confirm it navigates to `account.php`
- [ ] Log in as a non-admin user — confirm the shield icon is **not shown**

---

### User Avatar Dropdown

- [ ] Click the avatar button — confirm a dropdown opens
- [ ] Dropdown shows the user's full name at the top
- [ ] An **Account** link is present and navigates to `account.php`
- [ ] A **Logout** link is present in red and logs out
- [ ] Click anywhere outside the dropdown — confirm it closes
- [ ] Open both the admin dropdown and the avatar dropdown in sequence — confirm only one is open at a time

---

### Theme Switcher

- [ ] Open the avatar dropdown — confirm a **Theme** label and 5 colored circle swatches are visible
- [ ] The 5 swatches are: blue (Light), dark slate (Dark), sky blue (Ocean), green (Forest), orange-red (Ember)
- [ ] The currently active swatch has a border indicating selection
- [ ] Click **Dark** swatch — page immediately switches to dark color scheme; background becomes dark navy
- [ ] Click **Ocean** — page switches to sky blue palette
- [ ] Click **Forest** — page switches to green palette
- [ ] Click **Ember** — page switches to warm orange/red palette
- [ ] Click **Light** — page returns to the default blue scheme
- [ ] Reload the page after switching to Dark — confirm Dark theme is still applied (persisted via `localStorage`)
- [ ] Open a new tab to the same site — confirm it opens with the same saved theme

---

### Dark Mode — Inputs

- [ ] Switch to **Dark** theme
- [ ] Navigate to `login.php` — confirm input fields have a **dark background** (not white)
- [ ] Navigate to `editor.php` (any scene) — confirm choice text inputs and selects have a dark background
- [ ] Navigate to `generate_story.php` — confirm all form fields are dark-themed

---

### Guest Experience

- [ ] Log out
- [ ] Confirm the nav right side shows only a **Login / Sign Up** button (no bell, shield, avatar)
- [ ] Confirm the center nav shows only **Explore** (no My Stories, no Generate)
- [ ] Theme previously saved in `localStorage` still applies on page load (theme is set before first paint regardless of login state)

---

### No Layout Regressions

Quick smoke test of key pages:

- [ ] `index.php` — gallery cards display correctly; filter bar aligned
- [ ] `summary.php?storyID=X` — hero image, stats, star widget, comments all render
- [ ] `editor.php?storyID=X` — story overview and scene list render correctly
- [ ] `editor.php?storyID=X&sceneID=Y` (edit scene) — form and AI panels intact
- [ ] `job_queue.php` — table renders; status badges visible
- [ ] `account.php` — profile card and settings sections render
- [ ] `play.php?storyID=X&sceneID=Y` — player renders (play page uses its own theme styling, not affected)

---

### Mobile / Narrow Viewport

- [ ] Resize browser to ~375px wide
- [ ] Nav bar is still a single row (may overflow on very narrow screens — note any issues)
- [ ] Brand text may truncate — confirm no element overlaps the navbar buttons
