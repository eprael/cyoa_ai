# Phase 10 — Gallery Enhancements

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

Before testing, make sure you have:
- At least two published stories owned by your account
- At least one story favourited by your account (use `summary.php` to favourite one if needed)
- A second browser or incognito window available for guest tests

---

### 10.1 — My Favourites Filter Tab

- [ ] Log in and visit `index.php`
- [ ] Confirm three filter tabs are visible: **All Stories**, **My Stories**, **My Favourites**
- [ ] Click **My Favourites** — confirm only your favourited stories are shown
- [ ] Confirm the URL updates to `index.php?filter=favourites`
- [ ] Unfavourite all stories (via `summary.php`), then visit `index.php?filter=favourites` — confirm an empty-state message is shown (not a blank grid)
- [ ] Re-favourite a story, revisit — it reappears
- [ ] Log out — confirm the **My Favourites** tab is not shown to guests

---

### 10.2 — Search Bar

#### Layout

- [ ] Log in and visit `index.php`
- [ ] Confirm a search input is visible between the filter tabs and the **Create New Story** button
- [ ] The placeholder text indicates what can be searched (e.g. "Search by title or description…")

#### Search while on All Stories

- [ ] Type a word that appears in the title of one of your published stories — confirm only matching stories appear
- [ ] Type a word from a story's **description** (not title) — confirm that story also appears in results
- [ ] Type a string that matches nothing — confirm an empty-state message is shown
- [ ] Clear the search input — confirm all stories reappear

#### Search combined with filters

- [ ] Switch to **My Stories** filter, then type a search term — confirm results are scoped to your stories only
- [ ] Switch to **My Favourites** filter, then search — confirm results are scoped to your favourited stories only

#### URL and reload

- [ ] Enter a search term — confirm the URL updates to include `?q=your+term` (or equivalent)
- [ ] Reload the page with the `?q=` param in the URL — confirm the search field is pre-filled and results are shown
- [ ] Combine filter and search in the URL (e.g. `?filter=mine&q=dragon`) — confirm both are applied

#### Guest search

- [ ] Log out and visit `index.php`
- [ ] Confirm the search bar is visible and functional for guests (searches published stories only)

---

### 10.3 — Eye Icon Size

- [ ] Log in and view the story gallery
- [ ] Confirm the views (eye) icon on each published card is visibly larger than before — approximately double the previous size
- [ ] Confirm the icon and view count are still aligned and do not overflow the card stats row

---

### 10.4 — Heart Icon: Hollow / Filled State

- [ ] Log in and visit `index.php`
- [ ] Find a story you have **favourited** — confirm its heart icon is **filled red**
- [ ] Find a story you have **not favourited** — confirm its heart icon is **hollow/outlined** (not filled)
- [ ] Confirm the heart icon is present on all published story cards

#### Guest heart state

- [ ] Log out and visit `index.php`
- [ ] Confirm all heart icons are shown as **hollow** (guests have no favourites)
- [ ] Confirm the heart icon is still visible (not hidden for guests)

---

### 10.5 — Heart Icon: Click to Toggle Favourite

#### Logged-in toggle

- [ ] Log in and find a story you have **not** favourited (hollow heart)
- [ ] Click the heart icon on the card
- [ ] Confirm the heart **fills red immediately** (no page reload)
- [ ] Confirm the favourite count on the card increments by 1
- [ ] Confirm in phpMyAdmin that a new row was inserted in `cyoa_ai_favorites` for your user and that story
- [ ] Click the heart icon again
- [ ] Confirm the heart returns to **hollow** immediately
- [ ] Confirm the favourite count decrements by 1
- [ ] Confirm in phpMyAdmin the row was deleted from `cyoa_ai_favorites`

#### Persistence across page reload

- [ ] Favourite a story via the heart icon, then reload `index.php`
- [ ] Confirm the story's heart is still filled after reload (state persisted in the database)

#### Guest click → login redirect

- [ ] Log out
- [ ] Click any heart icon on a story card
- [ ] Confirm you are redirected to `login.php` (not silently ignored)
- [ ] After logging in, return to the gallery — hearts are in the correct filled/hollow state for your account

#### My Favourites tab consistency

- [ ] Log in, favourite a story via the heart icon on the **All Stories** view
- [ ] Switch to **My Favourites** tab — confirm the newly favourited story now appears there
- [ ] Unfavourite it via the heart icon — confirm it disappears from the **My Favourites** tab immediately (or on next tab visit)
