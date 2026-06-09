# Phase 9 — Navigation & Account Restructuring

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### 9.1 — Badge Hidden When Count is Zero

- [ ] Log in as a user with no pending or unseen completed/failed jobs
- [ ] Visit any page — confirm the bell icon shows **no red dot at all** (not an empty dot, not a "0" badge)
- [ ] Open DevTools → Network tab, filter by `api_jobs` — confirm `unseen_count` is still polling and returning `{"count":0}`
- [ ] Insert a completed job with `seen_at = NULL` in phpMyAdmin — confirm the badge appears within 5 seconds
- [ ] Navigate to `job_queue.php` — confirm the badge disappears after the page loads (seen_at stamped)
- [ ] Confirm the bell icon reverts to no dot

---

### 9.2 — Live Job Status Polling on job_queue.php

- [ ] Insert a pending job for your user in phpMyAdmin:
```sql
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, input_json)
VALUES (1, 1, 1, 'image', 'pending', '{"prompt":"live poll test","theme":"forest"}');
```
- [ ] Navigate to `job_queue.php` — confirm the job appears with a **Pending** (yellow) badge
- [ ] **Without refreshing the page**, manually update the job status in phpMyAdmin:
```sql
UPDATE cyoa_ai_jobs SET status = 'completed', seen_at = NULL
WHERE input_json = '{"prompt":"live poll test","theme":"forest"}';
```
- [ ] Within 5 seconds the row's status badge on `job_queue.php` updates to **Completed** (green) **without a page refresh**
- [ ] Confirm polling stops (no more network requests to `api_jobs.php?action=list`) once all visible jobs are in a terminal state (completed / failed / cancelled)
- [ ] Clean up: delete the test job from phpMyAdmin

---

### 9.3 — Generate Link Removed from Header

- [ ] Log in — confirm **Generate** no longer appears in the center nav links
- [ ] The center nav shows only **Explore** (and any remaining nav items)
- [ ] Confirm directly visiting `generate_story.php` still works (the page itself is not yet removed — that happens in Phase 14)
- [ ] Log out — confirm the guest nav is also unaffected

---

### 9.4 — My Stories Moved to Account Dropdown

- [ ] Log in — confirm **My Stories** no longer appears in the center nav
- [ ] Click the user avatar button to open the account dropdown
- [ ] Confirm **My Stories** appears in the dropdown, below the user's name
- [ ] Click **My Stories** in the dropdown — confirm it navigates to `index.php?filter=mine`
- [ ] Confirm the **Explore** link in the center nav still navigates to `index.php` (all stories)

---

### 9.5 & 9.6 — My Favourites: Dropdown Link + Gallery Filter

- [ ] Open the account dropdown — confirm **My Favourites** appears as a link
- [ ] Click **My Favourites** — confirm it navigates to `index.php?filter=favourites`
- [ ] On the gallery page with `filter=favourites`, confirm only stories you have favourited are shown
- [ ] Confirm the **My Favourites** filter tab is visible alongside All Stories and My Stories
- [ ] Switch between the three filter tabs — confirm each shows the correct set of stories
- [ ] Visit `index.php?filter=favourites` with no favourited stories — confirm an appropriate empty state is shown (not a blank page)

#### Guest — My Favourites tab

- [ ] Log out
- [ ] Visit `index.php` — confirm the **My Favourites** tab is **not visible** to guests (it requires a login to be meaningful)
- [ ] Visiting `index.php?filter=favourites` directly while logged out either redirects to login or falls back to showing all stories (note which behaviour is implemented)

---

### 9.6 — My Favourites Section Removed from Account Page

- [ ] Log in and visit `account.php`
- [ ] Confirm the **My Favourites** section (the list of favourited story cards) is **no longer present** on the account page
- [ ] Confirm the rest of the account page renders correctly with no layout gap where the section was

---

### 9.7 — Image Generation Quality Removed from Account Page

- [ ] Visit `account.php`
- [ ] Confirm the **Image Generation Quality** select and info button are **no longer present** in the AI API Keys section
- [ ] Confirm the Claude API Key and OpenAI API Key fields are still present and save correctly
- [ ] In phpMyAdmin, verify that saving the API keys form does not error even though `openai_image_quality` is no longer submitted

---

### 9.8 — Redundant Edit Button Removed from Story Editor

- [ ] Open the editor for a **published** story (`editor.php?storyID=X` where X is a published story)
- [ ] Confirm the action buttons show **Unpublish** only — there is no separate **Edit** button alongside it
- [ ] Click into a scene to edit it — confirm the story automatically transitions to a shadow draft (same behaviour as before, just without the explicit Edit button)
- [ ] Confirm the Revert to Original and Publish buttons appear as expected once in draft mode

---

### Regression — Nav Across All Pages

- [ ] Visit each of the following pages and confirm the nav renders correctly with all Phase 9 changes applied:
  - [ ] `index.php`
  - [ ] `summary.php?storyID=X`
  - [ ] `editor.php?storyID=X`
  - [ ] `job_queue.php`
  - [ ] `account.php`
  - [ ] `play.php?storyID=X&sceneID=Y`
