# Phase 6 — AI Full Story Generation

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

No pre-existing story required for Phase 6. You will be creating a brand-new story from scratch via the generator. Make sure the dispatcher cron is configured and functional (tested in earlier phases).

Note the job IDs as you create them (visible in the URL after submitting, or check phpMyAdmin).

---

### Generate Story Page — Navigation & Visibility

- [ ] Log in and confirm a **Generate** link is visible in the navigation bar
- [ ] Click **Generate** — confirm you land on `generate_story.php`
- [ ] Page title is "Generate a Story with AI"
- [ ] The form contains: **Story Premise** textarea, **Genre** select, **Tone** select, **Story Length** select, **Number of Endings** select, **Also generate images** checkbox, and **Generate Story** button
- [ ] Guest test: log out, visit `generate_story.php` directly — confirm redirect to `login.php`
- [ ] Log back in

---

### Generate Story Page — Guest Rejection via JS

- [ ] Log in, open the browser developer console (F12)
- [ ] Submit the following fetch from the console (simulates a logged-out POST):
```javascript
fetch('api_jobs.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=create&job_type=full_story&input_json=' + encodeURIComponent(JSON.stringify({premise:'test',genre:'fantasy',tone:'dark',target_scenes:8,num_endings:2}))
}).then(r => r.json()).then(console.log)
```
- [ ] While logged in this should succeed (`success: true`); log out first to confirm it returns `401`

---

### Submit a Job via the Form

- [ ] On `generate_story.php`, enter a clear premise (e.g. *"You are a space explorer who discovers a derelict station orbiting a gas giant — and something inside is still alive."*)
- [ ] Set Genre = **Sci-Fi**, Tone = **Suspenseful**, Length = **Short (~8 scenes)**, Endings = **2**
- [ ] Leave **Include images** unchecked for now
- [ ] Click **Generate Story**
- [ ] Confirm the button is disabled during the request
- [ ] Confirm a success message appears: "✓ Story job queued! Check the Job Queue for progress…"
- [ ] Confirm the premise textarea is cleared after success
- [ ] Confirm the **Job Queue** link badge appears (or already shows a count) in the header within 5 seconds

---

### Missing Premise Rejection

- [ ] Leave the premise textarea empty and click **Generate Story**
- [ ] Confirm an inline error message appears: "Please enter a story premise first."
- [ ] Confirm no job was submitted (check phpMyAdmin — no new `full_story` row)

---

### Job Queue Page — Job Appears

- [ ] Navigate to `job_queue.php`
- [ ] Confirm the job from the previous test appears with type **Full Story** (📚 icon)
- [ ] The **Story / Scene** column shows `—` (story doesn't exist yet at submit time)
- [ ] The status is **Pending** or **Running**

---

### Dispatcher + Worker — Plan + Write Pipeline

- [ ] Run: `php cron/ai_dispatcher.php`
- [ ] Dispatcher output shows the full_story job being claimed and a worker spawned
- [ ] Open `cron/logs/job_N.log` (replace N with your job_id)
- [ ] Log shows: `"Full story Phase 1: generating story plan..."`
- [ ] Then shows: `"Full story Phase 2: writing scene 1/N ..."` for each scene
- [ ] Job completes with `"Job #N completed successfully"`
- [ ] No exceptions or error lines in the log

If the worker fails here, check:
1. The API key in `config.php` is correct
2. The `ANTHROPIC_MODEL` constant is `claude-sonnet-4-6` (no date suffix)

---

### Database — Story and Scenes Created

After the worker completes, verify in phpMyAdmin:

**Story record:**
- [ ] A new row exists in `cyoa_ai_stories` with `status = 'draft'`
- [ ] The story is owned by your user ID
- [ ] `title` and `description` are populated with AI-generated content
- [ ] `theme` is one of: `egyptian`, `forest`, `scifi`, `ocean`, `desert`

```sql
SELECT storyID, title, LEFT(description, 80) AS desc_preview, theme, status, userID
FROM cyoa_ai_stories
ORDER BY storyID DESC LIMIT 3;
```

**Job row updated:**
- [ ] The job's `story_id` column is now filled in with the new story's ID (not NULL)
- [ ] `status` is `'completed'`
- [ ] `result_json` is not NULL and contains `story` and `scenes` keys

```sql
SELECT job_id, status, story_id, LEFT(result_json, 100) AS result_preview
FROM cyoa_ai_jobs WHERE job_type = 'full_story' ORDER BY job_id DESC LIMIT 3;
```

**Scenes created:**
- [ ] Multiple scenes exist for the new story (approximately the number requested, ±2)
- [ ] All scenes have `title` and `description` populated

```sql
SELECT sceneID, title, LEFT(description, 80) AS desc_preview
FROM cyoa_ai_scenes WHERE storyID = NEW_STORY_ID;
```

**Choices created:**
- [ ] Non-ending scenes have choices; each choice has a `destinationID` pointing to another scene in the same story

```sql
SELECT c.sceneID, s.title AS scene_title, c.choiceText, c.destinationID
FROM cyoa_ai_choices c
JOIN cyoa_ai_scenes s ON s.sceneID = c.sceneID
WHERE s.storyID = NEW_STORY_ID
ORDER BY c.sceneID;
```

---

### Job Queue — "Go to Story" Link

- [ ] Reload `job_queue.php`
- [ ] The full_story job now shows **Completed** status
- [ ] A **Go to story →** link appears in the Link column
- [ ] Click the link — confirm it opens `editor.php?storyID=NEW_STORY_ID`
- [ ] The story overview page shows the AI-generated title and description
- [ ] The scene list shows all generated scenes

---

### Story is a Draft — Not Publicly Visible

- [ ] On the editor overview, confirm the story shows **Draft** status
- [ ] Go to the gallery (`index.php`) — the new story does **not** appear under "All Stories"
- [ ] Switch to **My Stories** filter — the draft appears with a **Draft** badge
- [ ] Log in as a different user (or log out) — confirm the story is not visible in the gallery

---

### Story is Playable End-to-End

This is the most important test: verify the story's choice graph is complete and navigable.

- [ ] On the editor overview for NEW_STORY_ID, click the **opening scene** (first in the list)
- [ ] The scene has title, description, and choices
- [ ] Click **Play** (or open `play.php?storyID=NEW_STORY_ID&sceneID=OPENING_SCENE_ID` directly)
- [ ] Play through the story by clicking choices — navigate to at least 3 different scenes
- [ ] Reach at least one **ending scene** — confirm it has no choices (story ends)
- [ ] Backtrack and try a different path — confirm you can reach a second distinct ending

---

### Publish the Generated Story

- [ ] In the editor, open the story overview for NEW_STORY_ID
- [ ] Click **Publish**
- [ ] Confirm `status` changes to `'published'`
- [ ] The story now appears in the public gallery (`index.php` without filter)
- [ ] Visit `summary.php?storyID=NEW_STORY_ID` — confirm the cover image placeholder, title, and description are shown

---

### Rate Limiting — Max Pending Jobs

- [ ] Submit 5 full_story jobs in quick succession (each with a different premise — you can use short dummy premises)
- [ ] The 6th submission should fail with an error message about having too many active jobs
- [ ] Cancel one of the pending jobs from the Job Queue page
- [ ] Confirm you can now submit a new job successfully

To clean up after this test:
```sql
-- Cancel all pending full_story jobs for cleanup (check storyID is NULL before running)
UPDATE cyoa_ai_jobs SET status = 'cancelled'
WHERE job_type = 'full_story' AND status = 'pending' AND story_id IS NULL;
```

---

### BYOK — User's Own Claude Key

- [ ] In `account.php`, save a valid Anthropic API key for your account
- [ ] Submit a new full_story job
- [ ] Run the dispatcher
- [ ] Job completes successfully using your personal key
- [ ] In the worker log (`cron/logs/job_N.log`), confirm it ran and produced no errors (behaviour is identical to the site key)

---

### Failed Job — Empty Premise

- [ ] Using the browser console, submit a full_story job with an empty premise:
```javascript
fetch('api_jobs.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=create&job_type=full_story&input_json=' + encodeURIComponent(JSON.stringify({premise:'',genre:'fantasy',tone:'dark',target_scenes:8,num_endings:2}))
}).then(r => r.json()).then(console.log)
```
- [ ] Job is created (the API doesn't validate premise — that's the worker's job)
- [ ] Run the dispatcher
- [ ] Open `cron/logs/job_N.log` — confirm it shows `"ERROR: Job #N failed: Story premise is required."`
- [ ] In phpMyAdmin, job `status` = `'failed'`, `error_message` = `'Story premise is required.'`
- [ ] On `job_queue.php`, the job shows **Failed** with the error message

---

### Include Images Option

- [ ] Submit a new full_story job with **Include images** checked (set `include_images: true` in input_json, or use the form checkbox)
- [ ] Run the dispatcher — the full_story job runs first
- [ ] After the story job completes, check phpMyAdmin: additional `image` jobs should exist, one per scene with a non-empty `image_prompt`

```sql
SELECT job_id, job_type, scene_id, status,
       JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.prompt')) AS img_prompt
FROM cyoa_ai_jobs
WHERE story_id = NEW_STORY_ID
ORDER BY job_id;
```

- [ ] Run the dispatcher again to process the image jobs
- [ ] After image jobs complete, scenes have `image` filenames populated
- [ ] Images appear in the editor when viewing scene details

---

### Cleanup — Remove Test Stories

After all Phase 6 tests, delete any stories created purely for testing (not ones you want to keep):

```sql
-- Preview what will be deleted
SELECT storyID, title, status FROM cyoa_ai_stories
WHERE userID = YOUR_USER_ID AND status = 'draft'
ORDER BY storyID DESC;
```

To delete a test story cleanly (replace TEST_STORY_ID):
- Open it in `editor.php?storyID=TEST_STORY_ID` and use the **Delete** button
- This removes scenes and choices via the existing delete logic

Or manually via SQL:
```sql
DELETE c FROM cyoa_ai_choices c
JOIN cyoa_ai_scenes s ON s.sceneID = c.sceneID
WHERE s.storyID = TEST_STORY_ID;

DELETE FROM cyoa_ai_scenes WHERE storyID = TEST_STORY_ID;
DELETE FROM cyoa_ai_stories WHERE storyID = TEST_STORY_ID;
```
