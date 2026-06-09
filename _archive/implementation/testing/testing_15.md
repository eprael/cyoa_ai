# Phase 15 — Job Notification Grouping

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

You need a published story you own with at least one scene. Note your **USER_ID**, **STORY_ID**, and a **SCENE_ID**.

```sql
SELECT s.storyID, s.title, sc.sceneID, sc.title AS sceneTitle
FROM cyoa_ai_stories s
JOIN cyoa_ai_scenes sc ON sc.storyID = s.storyID
WHERE s.status = 'published' AND s.userID = 1
LIMIT 5;
```

---

### 15.1 — Database: parent_job_id Column Exists

- [ ] In phpMyAdmin, inspect the `cyoa_ai_jobs` table structure
- [ ] Confirm a `parent_job_id INT DEFAULT NULL` column exists
- [ ] Confirm it has a foreign key referencing `cyoa_ai_jobs(job_id)`

```sql
SHOW COLUMNS FROM cyoa_ai_jobs LIKE 'parent_job_id';
```

---

### 15.2 — Child Image Jobs Are Stamped with parent_job_id

#### Scene job with image

- [ ] Create a shadow draft of STORY_ID, note the DRAFT_STORY_ID and DRAFT_SCENE_ID
- [ ] Submit a scene generation job with **Generate image** checked
- [ ] Run: `php cron/ai_dispatcher.php`
- [ ] After the scene job completes, check phpMyAdmin:

```sql
-- Find the scene job
SELECT job_id, job_type, status, parent_job_id FROM cyoa_ai_jobs
WHERE story_id = DRAFT_STORY_ID ORDER BY job_id DESC LIMIT 5;
```

- [ ] The `scene` job has `parent_job_id = NULL` (it is the parent)
- [ ] The `image` job that was queued by apply has `parent_job_id` set to the scene job's `job_id`

#### Full story job with images

- [ ] Submit a full_story job with **Generate — Medium quality** selected
- [ ] Run the dispatcher, wait for completion
- [ ] Check phpMyAdmin: the `full_story` job has `parent_job_id = NULL`
- [ ] All `image` jobs for that story have `parent_job_id` set to the `full_story` job's `job_id`

```sql
SELECT job_id, job_type, status, parent_job_id FROM cyoa_ai_jobs
WHERE story_id = NEW_STORY_ID ORDER BY job_id;
```

---

### 15.3 — Child Jobs Do Not Trigger the Notification Badge

- [ ] Ensure you have no unseen jobs (visit `job_queue.php` to clear the badge)
- [ ] Submit a scene job with Generate image checked, run the dispatcher
- [ ] Monitor the header badge:
  - [ ] While the **scene** job is pending/running — no badge (pending jobs don't notify)
  - [ ] After the **scene** job completes — the badge appears with count **1** (the scene job)
  - [ ] The image child job completes — badge stays at **1** (child image does not add to the count)
- [ ] Visit `job_queue.php` — badge clears
- [ ] Confirm the badge does **not** reappear for the child image job

#### Via DevTools

- [ ] While the image child job is completing, watch the `api_jobs.php?action=unseen_count` responses in DevTools Network tab
- [ ] Confirm the count never includes the child image job (it should be 0 after clearing, then 1 for the scene, then 0 after visiting job_queue)

---

### 15.4 & 15.5 — completed_with_errors State

#### Setup: simulate a child image failure

Insert a completed scene job with one failed child image job:

```sql
-- Insert the parent scene job (completed)
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, input_json, result_json, seen_at)
VALUES (1, STORY_ID, SCENE_ID, 'scene', 'completed',
  '{"direction":"test","mode":"continue","tone":"neutral","num_choices":2,"generate_image":true}',
  '{"title":"Test Scene","description":"Test.","hint":"","image_prompt":"test","choices":[{"text":"Go left"},{"text":"Go right"}]}',
  NOW());

-- Note the job_id of the parent
SET @parent_id = LAST_INSERT_ID();

-- Insert a failed child image job pointing to the parent
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, parent_job_id, input_json, error_message, seen_at)
VALUES (1, STORY_ID, SCENE_ID, 'image', 'failed', @parent_id,
  '{"prompt":"test image","quality":"medium","theme":"forest"}',
  'Content could not be generated — try a different prompt.',
  NOW());
```

Now trigger the parent status update (run the dispatcher, or manually update):

```sql
UPDATE cyoa_ai_jobs SET status = 'completed_with_errors', seen_at = NULL
WHERE job_id = @parent_id;
```

#### Visual check in job_queue.php

- [ ] Visit `job_queue.php`
- [ ] The parent scene job shows a **yellow ⚠ badge** (not a green ✓ or red ✗)
- [ ] The badge tooltip or label reads something like "Completed — some images failed"
- [ ] The badge is distinct from both the green "Completed" and red "Failed" states

#### Badge notification

- [ ] Confirm the header badge fired for the parent job (it's `completed_with_errors` with `seen_at = NULL`)
- [ ] Visit `job_queue.php` — badge clears

---

### 15.6 — Nested Child Jobs in Job Queue

#### Child jobs collapsed by default

- [ ] On `job_queue.php`, find a parent job that has child image jobs
- [ ] Confirm the child jobs are **not shown** at the top level alongside parent jobs
- [ ] Confirm there is an expand control on the parent row (e.g. ▶ arrow, "Show images" link, or a count badge like "3 images")

#### Expand to see children

- [ ] Click the expand control on a parent row
- [ ] Confirm the child image jobs appear **indented** under the parent
- [ ] Each child row shows: type (Image), status badge, and the scene title it applies to
- [ ] Click the expand control again — child rows collapse

#### completed_with_errors — failed child is visible

- [ ] Find a parent job with `completed_with_errors` status
- [ ] Expand its child jobs
- [ ] Confirm the failed child image job is visible with a red **Failed** badge
- [ ] Confirm the error message is shown on the failed child row
- [ ] Confirm a **Retry** button is present on the failed child row
- [ ] Click **Retry** — confirm the child job is reset to `pending`
- [ ] Run the dispatcher — if the retry succeeds, confirm the parent status is updated back to `completed`

---

### Cleanup

```sql
-- Remove the manually inserted test jobs
DELETE FROM cyoa_ai_jobs
WHERE input_json LIKE '%"direction":"test"%'
   OR input_json LIKE '%"prompt":"test image"%';
```
