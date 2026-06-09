# Phase 19 — Header Redesign

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

- Phase 17 must be complete (settings table present, `app_title` seeded as `Choose Your Own Adventure!`)
- Have three browser sessions ready:
  - **Not logged in** (incognito)
  - **Logged in as a regular user**
  - **Logged in as admin**
- Have at least a few published stories in the DB so the gallery search can be tested

---

### 19.1 — Header Height

- [ ] Open `index.php` and measure / visually confirm the header is taller than before (increased by ~60px)
- [ ] Confirm the logo scales up proportionally — not pixelated or cropped
- [ ] Confirm the site title text is larger than before
- [ ] Confirm the profile / bell / admin icons are scaled up to match the new header height
- [ ] Confirm the header does not overlap page content below it

#### Consistent across pages

- [ ] Check that the header height is consistent on `index.php`, `editor.php`, `account.php`, and `play.php`

---

### 19.2 — Site Title from Settings

#### Default title

- [ ] Load `index.php`
- [ ] Confirm the header title reads **Choose Your Own Adventure!** (the default from `app_setting('app_title')`)

#### Title updates when setting changes

- [ ] Log in as admin and go to `account.php` → Site Settings
- [ ] Change Site Title to `My Epic Stories` and save
- [ ] Load `index.php` — confirm the header title now reads **My Epic Stories**
- [ ] Change Site Title back to `Choose Your Own Adventure!` and save
- [ ] Load `index.php` — confirm the title is restored

#### Title reflected in all pages

- [ ] After changing the title, confirm it appears correctly on `editor.php` and `account.php` headers (not hard-coded on any page)

---

### 19.3 — Remove Explore Button

- [ ] Load `index.php` while logged in
- [ ] Confirm there is **no** "Explore" button in the header navigation
- [ ] Inspect the page source and confirm no `Explore` button or link exists in `header.php`

---

### 19.4 — Search Bar in Header

#### Search bar placement

- [ ] Load `index.php`
- [ ] Confirm the search input and search button are in the **header**, not in the main page body
- [ ] Confirm the search bar is **horizontally centered** in the header
- [ ] Confirm the search bar is **vertically centered** within the header height
- [ ] Confirm the search input is approximately one-third the width of the header

#### Search bar removed from gallery body

- [ ] Scroll down on `index.php` and confirm the search bar does **not** appear in the main content area
- [ ] Inspect the source of `index.php` — confirm the old search bar markup has been removed from the page body

#### Search functionality works

- [ ] Type the title (or partial title) of a known story into the header search input and press Enter or click the search button
- [ ] Confirm the gallery filters to show matching stories
- [ ] Clear the search field — confirm all stories are shown again
- [ ] Search for a term that matches no stories — confirm a "no results" message or empty state is shown

#### Search bar on non-gallery pages

- [ ] Navigate to `editor.php` — confirm the search bar is visible in the header
- [ ] Navigate to `account.php` — confirm the search bar is visible in the header
- [ ] *(If search should only be functional on the gallery — confirm it is still present visually but does nothing or redirects to the gallery when submitted)*

---

### 19.5 — Username Below Profile Icon

#### Logged-in user sees their name

- [ ] Log in as a regular user and load any page
- [ ] Confirm the user's display name appears in the header, **below** the profile icon
- [ ] Confirm the name is in a fixed position (not inside the dropdown menu)

#### Name updates to reflect the logged-in user

- [ ] Log out and log in as a **different user**
- [ ] Confirm the header now shows the new user's name

#### Not logged in — name not shown

- [ ] Open an incognito window (not logged in)
- [ ] Confirm there is **no username** displayed below a profile icon
- [ ] Confirm a login button or guest profile icon is shown instead (whatever the pre-existing behaviour was)

#### Name is not duplicated inside the dropdown

- [ ] Log in as a regular user
- [ ] Click the profile icon to open the account dropdown
- [ ] Confirm the user's name is **not** repeated inside the dropdown (it was moved to the fixed header position)

---

### 19.6 — Admin Cog Icon

#### Cog replaces shield

- [ ] Log in as **admin** and load any page
- [ ] Confirm a **cog wheel** icon (⚙) is visible in the header where the shield used to be
- [ ] Confirm there is **no shield icon** anywhere in the header

#### No dropdown — direct link

- [ ] Click the cog icon
- [ ] Confirm you are **taken directly to `account.php`** (the admin panel)
- [ ] Confirm **no dropdown menu appears** when clicking the icon (the old "Admin Panel" single-item dropdown is gone)

#### Cog not shown for regular users

- [ ] Log in as a **regular user** and load any page
- [ ] Confirm the cog icon is **not visible** in the header

#### Cog not shown when not logged in

- [ ] Open an incognito window
- [ ] Confirm the cog icon is **not visible**

---

### Responsive / Layout Checks

- [ ] Resize the browser to approximately **768px wide** — confirm the header still looks reasonable (no overlap, no cropping)
- [ ] Resize to **375px wide** (mobile) — confirm the header does not break layout; confirm search bar and icons are usable
- [ ] Confirm the username does not overflow or wrap awkwardly on a narrow header

---

### Regression

- [ ] Load `index.php` — confirm the story gallery loads and displays correctly below the new header
- [ ] Confirm the existing account dropdown (profile, settings, logout) still works correctly
- [ ] Confirm clicking the site logo (if present) still navigates to the homepage
- [ ] Confirm all navigation links that remain (account, job queue, etc.) still work
- [ ] Play a story via `play.php` — confirm the play page is unaffected (it has its own layout, not using `header.php`)
- [ ] Confirm admin can still access the admin panel via the cog link and via `account.php` directly
