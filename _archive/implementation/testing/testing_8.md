# Phase 8 — Cosmetic Fixes & Quick Wins

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### 8.1 — Favicon

- [ ] Open any page in the browser
- [ ] Confirm a small icon appears in the **browser tab** next to the page title
- [ ] The favicon also appears in the browser bookmarks bar if you bookmark the page
- [ ] Open a second page (e.g. `login.php`, `index.php`) — confirm the favicon appears on all pages (it is linked in the shared `header.php`)

---

### 8.2 — Header: Wider Container + Larger Logo

- [ ] Open any page while logged in
- [ ] Confirm the navbar content stretches wider than before (less blank space on the left and right edges)
- [ ] Confirm the logo image and **AIdventure** brand text are visibly larger than before
- [ ] Confirm the logo still links to `index.php`
- [ ] Resize the browser to ~768px wide — confirm the header does not break or overflow

---

### 8.3 — Gallery Heading Text

- [ ] Visit `index.php`
- [ ] Confirm the page heading reads **"Choose a story to Explore"** (not "Choose a story to Play")
- [ ] Check both the All Stories and My Stories filter views — heading is correct in both

---

### 8.4 — Scene Descriptions Render as HTML

- [ ] Open any story in the editor (`editor.php?storyID=X`)
- [ ] Open a scene that has a description containing HTML tags (e.g. `<p>`, `<strong>`, `<em>`) — these were previously showing as raw tag text
- [ ] Confirm the description now renders the formatted text rather than showing the raw HTML
- [ ] Confirm no raw `&lt;p&gt;` or similar escaped entities are visible
- [ ] Open a scene with a plain-text description (no tags) — confirm it still displays correctly

---

### 8.5 — Gap Between Scene Cards

- [ ] Open the story overview for any story with multiple scenes (`editor.php?storyID=X`)
- [ ] Confirm there is a visible gap or divider between each scene card in the scene list
- [ ] Confirm cards are visually distinct and not running together
- [ ] Confirm the gap is consistent between all cards in the list

---

### 8.6 — Job Queue: Past Jobs Collapse

#### Recent jobs visible by default

- [ ] Visit `job_queue.php` with a mix of recent and old jobs in the table
- [ ] Jobs created within the last 24 hours are visible immediately
- [ ] Jobs older than 24 hours are **not** shown by default

#### Past Jobs toggle

- [ ] Confirm a **"Past jobs"** link or button is visible below the main job list
- [ ] Click **"Past jobs"** — the older jobs expand and appear below
- [ ] The section header or toggle text updates to indicate the section is now expanded (e.g. "Hide past jobs")
- [ ] Click again — the old jobs collapse and are hidden again

#### Edge cases

- [ ] If all jobs are within the last 24 hours — no "Past jobs" link is shown
- [ ] If all jobs are older than 24 hours — the main list is empty and "Past jobs" is shown; expanding it shows all jobs
- [ ] Visit `job_queue.php` as a user with no jobs at all — confirm the page shows an empty state message and no "Past jobs" link
