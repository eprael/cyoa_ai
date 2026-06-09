# Phase 3 — AI Job Queue Infrastructure

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Test Data Lookup

Before inserting any test rows, find the IDs you'll use. Run these in phpMyAdmin:

```sql
-- Your user account (Evan)
SELECT userID, firstName, lastName FROM cyoa_ai_users WHERE firstName = 'Evan' LIMIT 5;

-- A second user to test admin view (someone other than yourself)
SELECT userID, firstName, lastName FROM cyoa_ai_users WHERE userID != 1 LIMIT 5;

-- A published story you own, and one of its scenes
SELECT s.storyID, s.title, sc.sceneID, sc.title AS sceneTitle
FROM cyoa_ai_stories s
JOIN cyoa_ai_scenes sc ON sc.storyID = s.storyID
WHERE s.userID = 1 AND s.status = 'published'
LIMIT 5;
```

The SQL blocks below use `user_id = 1` (Evan), `story_id = 1` (Escape the Pyramid), `scene_id = 1` (The Burial Chamber). Swap in your actual IDs if they differ.

---

### Header Badge — No Jobs

- [ ] Log in as a regular user with no jobs
- [ ] Visit any page — the Job Queue link in the nav should have **no badge**
- [ ] Open DevTools → Network tab → filter by `api_jobs`
- [ ] Confirm `api_jobs.php?action=unseen_count` fires every ~5 seconds and returns `{"count":0}`

---

### Header Badge — Badge Fires on Completed Job

The badge counts **completed and failed** jobs where `seen_at IS NULL` — it does **not** fire for pending jobs (those are expected background work, not notifications).

Insert a completed job with `seen_at` left NULL:

```sql
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, input_json, result_json)
VALUES (1, 1, 1, 'image', 'completed',
    '{"prompt": "a glowing golden treasure chest"}',
    '{"filename": "test_badge.png", "prompt_used": "a glowing golden treasure chest"}');
```

- [ ] Within 5 seconds the Job Queue badge appears in the nav showing **1**
- [ ] In phpMyAdmin: `seen_at` for this row is still `NULL`
- [ ] Navigate to `job_queue.php` — the row appears with status **Completed**
- [ ] Navigate away, then back to any other page — badge is now gone (the page load stamped `seen_at`)
- [ ] In phpMyAdmin confirm `seen_at` is now set (no longer NULL) on that row

---

### Job Queue Page — Pending Job Display

Insert a pending job:

```sql
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, input_json)
VALUES (1, 1, 1, 'image', 'pending',
    '{"prompt": "a dark stone corridor with flickering torches"}');
```

- [ ] Visit `job_queue.php` — the pending job appears in the list
- [ ] Row shows: type **Image** (with icon), status **Pending** (yellow badge), story title **Escape the Pyramid**, scene title **The Burial Chamber**, and created timestamp
- [ ] No **Cancel** button is shown for the completed job; a **Cancel** button IS shown for the pending one
- [ ] No badge fires — pending jobs are not notifications

---

### Job Queue Page — Failed Job + Retry

Insert a failed job:

```sql
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, input_json, error_message, seen_at)
VALUES (1, 1, 1, 'image', 'failed',
    '{"prompt": "a dark cavern with ancient bones"}',
    'OpenAI API rate limit exceeded',
    NOW());
-- seen_at = NOW() so the badge does NOT fire for this one (already "seen")
```

- [ ] Job appears in the list with status **Failed** (red badge)
- [ ] The error message "OpenAI API rate limit exceeded" is visible in the row
- [ ] A **Retry** button is shown; no Cancel button
- [ ] Click **Retry** → confirm the prompt → page reloads
- [ ] Status changes back to **Pending** and the Retry button is replaced by a Cancel button
- [ ] In phpMyAdmin confirm `status = 'pending'` and `error_message` is now NULL on that row
- [ ] Badge does NOT reappear (retried jobs start unseen but are pending, not completed/failed)

---

### Cancel a Pending Job

- [ ] On `job_queue.php`, find a pending job and click **Cancel** → confirm the prompt → page reloads
- [ ] Status changes to **Cancelled** (grey badge); Cancel button disappears; no Retry button
- [ ] In phpMyAdmin confirm `status = 'cancelled'` on that row

---

### API Endpoints — Smoke Test

These tests call `api_jobs.php` directly in the browser while logged in. Open each URL in a new tab.

**`unseen_count` (GET)**
```
http://localhost/projects/cyoa_ai/api_jobs.php?action=unseen_count
```
- [ ] Returns `{"count": N}` — N should match the current badge number

**`status` — single job poll (GET)**
Replace `N` with a real job_id from your table:
```
http://localhost/projects/cyoa_ai/api_jobs.php?action=status&job_id=N
```
- [ ] Returns a JSON object with `job_id`, `status`, `job_type`, `created_at`
- [ ] For a completed job also includes a `result` key; for a failed job also includes an `error` key
- [ ] Try with a fake job_id (e.g. `99999`) — should return HTTP 404 and `{"success":false,"error":"Job not found."}`

**`list` (GET)**
```
http://localhost/projects/cyoa_ai/api_jobs.php?action=list
```
- [ ] Returns `{"jobs":[...]}` — array of your job rows with story/scene titles joined in
- [ ] Try `?action=list&status=pending` — only pending jobs in the array
- [ ] Log in as admin and visit the same URL — all users' jobs are returned

**`create` — guest rejection (POST)**
Log out first, then in DevTools Console:
```js
fetch('/projects/cyoa_ai/api_jobs.php', {
  method: 'POST',
  headers: {'Content-Type':'application/x-www-form-urlencoded'},
  body: 'action=create&job_type=image&story_id=1&scene_id=1&input_json={"prompt":"test"}'
}).then(r => r.json()).then(console.log)
```
- [ ] Returns HTTP 401 and `{"success":false,"error":"Login required."}`

---

### Pending Job Limit Enforcement

Insert enough pending jobs to reach the limit (default 5; adjust the batch if your limit differs):

```sql
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, input_json) VALUES
(1, 1, 1, 'image', 'pending', '{"prompt": "limit test 1"}'),
(1, 1, 1, 'image', 'pending', '{"prompt": "limit test 2"}'),
(1, 1, 1, 'image', 'pending', '{"prompt": "limit test 3"}'),
(1, 1, 1, 'image', 'pending', '{"prompt": "limit test 4"}'),
(1, 1, 1, 'image', 'pending', '{"prompt": "limit test 5"}');
```

Now try to create a 6th via the API (DevTools Console, logged in as that user):
```js
fetch('/projects/cyoa_ai/api_jobs.php', {
  method: 'POST',
  headers: {'Content-Type':'application/x-www-form-urlencoded'},
  body: 'action=create&job_type=image&story_id=1&scene_id=1&input_json={"prompt":"over the limit"}'
}).then(r => r.json()).then(console.log)
```
- [ ] Returns HTTP 429 and an error message containing "active jobs"
- [ ] Cancel all 5 limit-test rows in phpMyAdmin (or use the cleanup SQL below), then confirm the create call succeeds

---

### Admin View — All Users' Jobs

Insert a job for a second user (replace `10` with the other user's real `userID` from the lookup above):

```sql
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, input_json)
VALUES (10, 1, 1, 'scene', 'pending',
    '{"story_title": "Escape the Pyramid", "direction": "The player finds a hidden passage", "mode": "continue", "tone": "suspenseful", "num_choices": 2, "generate_image": false}');
```

- [ ] Log in as admin (Evan) and visit `job_queue.php`
- [ ] Both users' jobs are visible and a **User** column appears in the table
- [ ] The second user's row shows their name in the User column
- [ ] Log in as the second user — they see only their own jobs; no User column

---

### Stale Job Timeout

Insert a job stuck in `running` with `started_at` more than 5 minutes ago:

```sql
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, input_json, started_at)
VALUES (1, 1, 1, 'image', 'running',
    '{"prompt": "a mysterious sealed door covered in runes"}',
    DATE_SUB(NOW(), INTERVAL 10 MINUTE));
```

- [ ] Note the `job_id` of the row just inserted
- [ ] Run the dispatcher: `php cron/ai_dispatcher.php`
- [ ] In phpMyAdmin find that row — `status` should now be `'failed'`
- [ ] `error_message` should contain something like "Timed out" or "stale"
- [ ] `updated_at` should reflect the time you ran the dispatcher

---

### Dispatcher Cron — Runs Without Error

- [ ] Run: `php cron/ai_dispatcher.php`
- [ ] No PHP errors or warnings in the output (warnings appear as `PHP Warning:` lines)
- [ ] If pending jobs exist in the table: their `status` changes to `'running'` while the dispatcher is active, then to `'completed'` or `'failed'` once the worker finishes (since Phase 4–6 handlers are stubs, expect `'failed'` with a "not implemented" message)
- [ ] The dispatcher process exits cleanly (returns to the shell prompt)
- [ ] In phpMyAdmin: any job that was `'running'` before the dispatcher ran has been either completed, failed, or left running (if the worker is still in-progress)

---

### Cleanup — Remove Test Rows

After all Phase 3 tests, delete the manually inserted rows:

```sql
DELETE FROM cyoa_ai_jobs
WHERE input_json IN (
    '{"prompt": "a glowing golden treasure chest"}',
    '{"prompt": "a dark stone corridor with flickering torches"}',
    '{"prompt": "a dark cavern with ancient bones"}',
    '{"prompt": "a mysterious sealed door covered in runes"}',
    '{"prompt": "limit test 1"}',
    '{"prompt": "limit test 2"}',
    '{"prompt": "limit test 3"}',
    '{"prompt": "limit test 4"}',
    '{"prompt": "limit test 5"}',
    '{"prompt": "over the limit"}'
);

-- Also delete the second-user scene job
DELETE FROM cyoa_ai_jobs
WHERE job_type = 'scene'
  AND JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.direction')) = 'The player finds a hidden passage';
```
