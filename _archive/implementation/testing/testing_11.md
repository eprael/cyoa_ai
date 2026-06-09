# Phase 11 — Summary Page Adjustments

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

You need at least one published story with at least one rating and one favourite in the database. If needed:
- Rate a story via `summary.php` (or insert directly in phpMyAdmin)
- Favourite a story via the gallery heart icon or the summary page

---

### 11.1 — Favourites Badge as Sole Toggle Button

#### Badge is present, separate button is gone

- [ ] Open `summary.php?storyID=X` for a published story while logged in
- [ ] Confirm there is **no standalone "Favourite" or "♥ Favourite" button** next to the Edit Story button
- [ ] Confirm the heart icon + favourite count badge is visible (this is now the only favourite control)

#### Toggle via badge

- [ ] Click the heart badge on a story you have **not** favourited
- [ ] Confirm the heart fills red and the count increments immediately (no page reload)
- [ ] Click the heart badge again
- [ ] Confirm the heart returns to hollow and the count decrements immediately
- [ ] Reload the page — confirm the favourite state matches what was last set

#### Guest behaviour

- [ ] Log out and visit `summary.php?storyID=X`
- [ ] Confirm the heart badge is visible (showing total favourite count)
- [ ] Click the heart badge — confirm you are redirected to `login.php`
- [ ] Confirm there is no separate favourite button for guests either

---

### 11.2 — Star Rating: Position and Layout

#### Placement

- [ ] Log in and open `summary.php?storyID=X`
- [ ] Confirm the star rating widget appears **below the Play Story button** (not above it, not in the stats bar)
- [ ] Confirm the layout order in the action area is: **Play Story** button → star widget → (Edit Story button if owner)

#### Star widget layout

- [ ] Confirm the five stars are displayed on one line
- [ ] Confirm the average rating (e.g. **3.7**) is shown in brackets on the same line as the stars (e.g. ★★★★☆ (3.7))
- [ ] Confirm a second line below reads **"You rated this X / 5"** for a story you have already rated
- [ ] For a story you have **not** rated, confirm the second line reads **"You haven't rated this yet"** or similar

#### Rating interaction

- [ ] Click a star to submit a rating — confirm the average updates and the "You rated this" line reflects your choice
- [ ] Click a different star — confirm the rating updates in place without a page reload
- [ ] Reload the page — confirm your rating persists

#### Guest behaviour

- [ ] Log out and open `summary.php?storyID=X`
- [ ] Confirm the stars and average rating are **visible** (read-only display)
- [ ] Confirm the stars are **not interactive** — hovering produces no highlight, clicking does nothing
- [ ] Confirm the second line reads **"Log in to rate this story"** (not "You rated this")
- [ ] Clicking the star area redirects to `login.php`

---

### Regression — Summary Page Layout

- [ ] Confirm the overall summary page layout is intact: cover image, title, author, theme, stats bar all still present
- [ ] Confirm the comments section still appears below and functions correctly (post, reply, delete)
- [ ] Open the summary page on a story with no ratings yet — average shows 0 or "No ratings yet", not a PHP error
- [ ] Open the summary page on a story with no cover image — placeholder image renders, no layout break
