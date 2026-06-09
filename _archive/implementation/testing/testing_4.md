# Phase 4 — AI Image Generation

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup — Create a Shadow Draft and Note Your IDs

Because editing a published story always creates a shadow draft (a cloned copy with new IDs), you need to do that first and record the draft's storyID and sceneID before running any of the tests below. You'll use these IDs throughout Phase 4.

- [ ] Log in and go to `index.php?filter=mine` — find a published story you own
- [ ] Click the story's card to open the summary page, then click **Edit** (or go directly to `editor.php?storyID=X` where X is the published storyID)
- [ ] In the editor, click the **Edit** button — the page reloads and the URL changes to a **new storyID** (the shadow draft). Note this as **DRAFT_STORY_ID**.
- [ ] In the scene list, click **Edit** on any existing scene — the URL now shows a sceneID. Note this as **DRAFT_SCENE_ID**.

> **Keep this tab open.** You'll return to this edit-scene URL for the panel tests below.
>
> To look up your draft IDs in phpMyAdmin at any time:
> ```sql
> SELECT storyID, title, status, published_story_id
> FROM cyoa_ai_stories
> WHERE status = 'draft' AND published_story_id IS NOT NULL
> ORDER BY storyID DESC LIMIT 5;
> ```

---

### AI Panel — Visibility

- [ ] On the edit-scene page you opened above (the draft scene, not the published one), scroll below the scene save form — confirm a **"Generate Scene Image with AI"** panel is visible
- [ ] Go back to the draft story overview and click **+ Add Scene**
- [ ] Confirm you are redirected immediately to a scene edit form with a sceneID already in the URL (no separate "new scene" step)
- [ ] Confirm the page heading says **Create New Scene** and the save button says **Create Scene**
- [ ] Confirm a **Discard** button is shown instead of Cancel
- [ ] Confirm the **"Generate Scene Image with AI"** panel is visible on this new-scene form

---

### Discard a New Scene

- [ ] While on the **Create New Scene** form (from the step above, or click **+ Add Scene** again), click **Discard** and confirm the prompt
- [ ] Confirm you are redirected back to the story overview with a "New scene discarded." flash message
- [ ] Confirm the blank scene does **not** appear in the scene list
- [ ] In phpMyAdmin confirm no row with an empty title was left behind in `cyoa_ai_scenes` for this story

---

### Submit a Job via the Panel

- [ ] Return to the edit-scene page for the existing draft scene (with DRAFT_SCENE_ID in the URL)
- [ ] Enter a description in the AI panel textarea, e.g.:
  `A dark stone corridor lit by flickering torches, ancient hieroglyphs carved into the walls`
- [ ] Click **Generate Image**
- [ ] Button disables and status text shows `Queueing job…`
- [ ] Within a second, status changes to `✓ Job queued! The image will appear here once generated. Check the Job Queue for status.`
- [ ] The textarea clears after success
- [ ] In phpMyAdmin, confirm a new row in `cyoa_ai_jobs`:
  - `job_type = 'image'`
  - `status = 'pending'`
  - `story_id` matches **DRAFT_STORY_ID** and `scene_id` matches **DRAFT_SCENE_ID**
  - `input_json` contains your prompt text and the story's theme
- [ ] Note the `job_id` of this row — you'll need it for the end-to-end test below

---

### Job Queue Page — Job Appears

- [ ] Visit `job_queue.php`
- [ ] The new image job appears in the list with status **Pending** (yellow badge)
- [ ] Row shows the story title, scene title, type **Image**, and a **Cancel** button
- [ ] No notification badge fires for pending jobs

---

### Guest Rejection

Log out, then in DevTools Console (replace the IDs with your actual DRAFT_STORY_ID and DRAFT_SCENE_ID):

```js
fetch('/projects/cyoa_ai/api_jobs.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
  body: 'action=create&job_type=image&story_id=DRAFT_STORY_ID&scene_id=DRAFT_SCENE_ID&input_json={"prompt":"test","theme":"forest"}'
}).then(r => r.json()).then(console.log)
```

- [ ] Returns HTTP 401 and `{"success":false,"error":"Login required."}`

---

### Missing Fields Rejection

While logged back in, try submitting without `scene_id` (replace DRAFT_STORY_ID with your value):

```js
fetch('/projects/cyoa_ai/api_jobs.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
  body: 'action=create&job_type=image&story_id=DRAFT_STORY_ID&input_json={"prompt":"test","theme":"forest"}'
}).then(r => r.json()).then(console.log)
```

- [ ] Returns an error saying `story_id and scene_id are required for image jobs.`

---

### Dispatcher + Worker — End-to-End Apply

Make sure there is exactly one pending image job in the table (cancel others if needed), then:

- [ ] Confirm the pending job's `story_id` and `scene_id` match DRAFT_STORY_ID and DRAFT_SCENE_ID
- [ ] In phpMyAdmin, note the current `image` value for that scene in `cyoa_ai_scenes` — it may be NULL or a previous filename
- [ ] Run the dispatcher: `php cron/ai_dispatcher.php`
- [ ] The dispatcher output should show the job being claimed and a worker being spawned
- [ ] Wait a few seconds (DALL-E takes 5–15 seconds), then check phpMyAdmin:
  - `status` on the job row is now `'completed'`
  - `result_json` contains `filename` and `prompt_used` keys
  - The scene row for DRAFT_SCENE_ID in `cyoa_ai_scenes` now has the new AI-generated filename in the `image` column
  - `image_gen` on that scene row matches the `prompt_used` from `result_json`
- [ ] Return to the edit-scene page for DRAFT_SCENE_ID in the browser
- [ ] The scene image preview at the top of the form now shows the AI-generated image
- [ ] Publish the draft (click **Publish** in the editor), then play the story and navigate to that scene — the generated image appears in the player

---

### Job Queue — Completed State

- [ ] Visit `job_queue.php`
- [ ] The job row now shows status **Completed** (green badge)
- [ ] No **Cancel** or **Retry** button appears on a completed job
- [ ] The notification badge appeared in the nav (if you were on another page when it completed)
- [ ] Opening `job_queue.php` clears the badge (stamps `seen_at`)

---

### Failed Job — Bad Prompt (Content Filter)

For this test you need a draft story with a valid scene. Create a new shadow draft of any published story (same steps as the Setup section above) and record its DRAFT_STORY_ID and DRAFT_SCENE_ID, then insert:

```sql
-- Replace DRAFT_STORY_ID and DRAFT_SCENE_ID with your real values
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, input_json)
VALUES (1, DRAFT_STORY_ID, DRAFT_SCENE_ID, 'image', 'pending',
    '{"prompt": "explicit violent gore", "theme": "forest"}');
```

- [ ] Run `php cron/ai_dispatcher.php`
- [ ] In phpMyAdmin: `status` changes to `'failed'`
- [ ] `error_message` contains "Content could not be generated" or a DALL-E error description
- [ ] `image` column on DRAFT_SCENE_ID is **unchanged** (no bad data was written)
- [ ] Job appears as **Failed** (red badge) in `job_queue.php`
- [ ] A **Retry** button is shown on the failed row

---

### BYOK — User's Own OpenAI Key

- [ ] In `account.php`, save a valid OpenAI API key for your account
- [ ] Create another shadow draft of a published story (same Setup steps), navigate to a scene, and submit an image job via the AI panel
- [ ] Run the dispatcher
- [ ] Job completes successfully using your personal key
- [ ] In phpMyAdmin confirm the job completed and the image was saved to the draft scene (same behaviour as the site-wide key)

---

### Cleanup — Remove Test Rows

After all Phase 4 tests:

```sql
-- Remove any manually inserted bad-prompt test jobs
DELETE FROM cyoa_ai_jobs
WHERE job_type = 'image'
  AND JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.prompt')) = 'explicit violent gore';
```

To reset a draft scene's image back to NULL (replace DRAFT_SCENE_ID with your value):

```sql
UPDATE cyoa_ai_scenes SET image = NULL, image_gen = NULL WHERE sceneID = DRAFT_SCENE_ID;
```

Discard any shadow drafts created purely for testing (open them in the editor and click **Revert to Original**).
