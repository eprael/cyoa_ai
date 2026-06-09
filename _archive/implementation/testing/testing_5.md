# Phase 5 — AI Scene Generation

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup — Use the Same Shadow Draft from Phase 4

These tests build on the same shadow draft setup from Phase 4. You need **DRAFT_STORY_ID** and **DRAFT_SCENE_ID** from that setup. If you discarded the shadow draft after Phase 4, create a new one now (same steps as Phase 4 Setup).

Additionally, for the **previous_scenes context test** you need a scene that has at least one *incoming* choice from another scene — meaning some other scene has a choice whose destination is DRAFT_SCENE_ID. Check in phpMyAdmin:

```sql
-- Does any choice point TO your draft scene?
SELECT c.choiceID, c.sceneID AS source_scene, c.choiceText, c.destinationID
FROM cyoa_ai_choices c
WHERE c.destinationID = DRAFT_SCENE_ID;
```

If no rows are returned, pick a different scene for the previous_scenes test (one that is reachable from another scene via a choice), or wire up a choice manually in the editor.

> To look up your draft IDs at any time:
> ```sql
> SELECT storyID, title, status, published_story_id
> FROM cyoa_ai_stories
> WHERE status = 'draft' AND published_story_id IS NOT NULL
> ORDER BY storyID DESC LIMIT 5;
> ```

---

### AI Scene Panel — Visibility

- [ ] Open the edit-scene page for DRAFT_SCENE_ID
- [ ] Scroll below the scene save form — confirm a **"Generate Scene Content with AI"** panel is visible **above** the image generation panel
- [ ] The panel contains: a **Scene Direction** textarea, **Mode** select, **Tone** select, a **Number of Choices** select, a **Generate image** checkbox, and a **Generate Scene** button
- [ ] The **Ending Type** select is hidden by default (mode starts as "Continue story")
- [ ] Change **Mode** to **Ending scene** — confirm **Ending Type** select appears and **Number of Choices** select disappears
- [ ] Switch **Mode** back to **Continue story** — confirm **Number of Choices** reappears and **Ending Type** hides again

---

### Story Map Context

- [ ] On the same edit-scene page, find the **Story map** collapsible above the Scene Direction field
- [ ] The summary line shows the number of scenes in the story (e.g. "Story map — 4 scenes in this story")
- [ ] Click to expand — the scene titles from DRAFT_STORY_ID are listed
- [ ] Each title matches what you see in the story overview (verify by opening `editor.php?storyID=DRAFT_STORY_ID` in another tab)

---

### Submit a Job via the Panel

- [ ] Return to the edit-scene page for DRAFT_SCENE_ID
- [ ] Enter a direction in the textarea, e.g.:
  `The player pushes open a heavy stone door and discovers a chamber filled with ancient relics`
- [ ] Leave Mode as **Continue story**, Tone as **Suspenseful**, Choices as **2**
- [ ] Leave **Generate image** checked
- [ ] Click **Generate Scene**
- [ ] Button disables and status text shows `Queueing job…`
- [ ] Within a second, status changes to `✓ Job queued! Check Job Queue for status. Refresh this page when complete to see the generated content.` (with a link to `job_queue.php`)
- [ ] The direction textarea clears after success
- [ ] In phpMyAdmin, confirm a new row in `cyoa_ai_jobs`:
  - `job_type = 'scene'`
  - `status = 'pending'`
  - `story_id` matches **DRAFT_STORY_ID** and `scene_id` matches **DRAFT_SCENE_ID**
- [ ] Note the `job_id` of this row — you'll need it for the end-to-end test below

---

### Server-side input_json Augmentation

The client only sends the user-authored parameters. The server is responsible for adding story context before saving the job. Verify the stored `input_json` contains both:

```sql
SELECT
  job_id,
  JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.direction'))    AS direction,
  JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.story_title'))  AS story_title,
  JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.story_theme'))  AS story_theme,
  JSON_EXTRACT(input_json,              '$.previous_scenes') AS previous_scenes
FROM cyoa_ai_jobs
WHERE job_type = 'scene'
ORDER BY job_id DESC LIMIT 3;
```

- [ ] `direction` matches the text you entered in the panel
- [ ] `story_title` is the actual title of DRAFT_STORY_ID (not empty)
- [ ] `story_theme` is the story's theme (e.g. `egyptian`)
- [ ] `previous_scenes` is a JSON array — empty `[]` if DRAFT_SCENE_ID has no incoming choices, or a populated array if it does

---

### Previous Scenes Context

For this test you need DRAFT_SCENE_ID to be reachable via a choice from another scene (see Setup above). If it is:

- [ ] Submit another scene job for DRAFT_SCENE_ID (or use the one from the previous test if still pending)
- [ ] Run the query above and inspect the `previous_scenes` array
- [ ] It should contain one entry per ancestor scene, ordered root → most recent
- [ ] Each entry has `title`, `description` (first 200 characters), and `choice_taken` (the choice text that led forward)
- [ ] The array does **not** include DRAFT_SCENE_ID itself — only its ancestors

---

### Job Queue Page — Job Appears

- [ ] Visit `job_queue.php`
- [ ] The new scene job appears in the list with status **Pending** (yellow badge)
- [ ] Row shows the story title, scene title, type **Scene**, and a **Cancel** button
- [ ] No notification badge fires for pending jobs

---

### Guest Rejection

Log out, then in DevTools Console (replace the IDs with your actual values):

```js
fetch('/projects/cyoa_ai/api_jobs.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
  body: 'action=create&job_type=scene&story_id=DRAFT_STORY_ID&scene_id=DRAFT_SCENE_ID&input_json={"direction":"test","mode":"continue","tone":"neutral","num_choices":2,"generate_image":false}'
}).then(r => r.json()).then(console.log)
```

- [ ] Returns HTTP 401 and `{"success":false,"error":"Login required."}`

---

### Missing Fields Rejection

Log back in. Test missing `scene_id` (replace DRAFT_STORY_ID with your value):

```js
fetch('/projects/cyoa_ai/api_jobs.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
  body: 'action=create&job_type=scene&story_id=DRAFT_STORY_ID&input_json={"direction":"test","mode":"continue","tone":"neutral","num_choices":2,"generate_image":false}'
}).then(r => r.json()).then(console.log)
```

- [ ] Returns an error saying `story_id and scene_id are required for scene jobs.`

---

### Dispatcher + Worker — End-to-End Apply

Make sure there is exactly one pending scene job in the table for DRAFT_SCENE_ID (cancel others if needed). Also note the current state of that scene before running:

```sql
-- Record the current state of the target scene
SELECT sceneID, title, description, hint FROM cyoa_ai_scenes WHERE sceneID = DRAFT_SCENE_ID;

-- Record any existing choices on the scene
SELECT choiceID, choiceText, destinationID FROM cyoa_ai_choices WHERE sceneID = DRAFT_SCENE_ID;

-- Count existing scenes in the draft story
SELECT COUNT(*) AS scene_count FROM cyoa_ai_scenes WHERE storyID = DRAFT_STORY_ID;
```

Then run the dispatcher:

- [ ] Run: `php cron/ai_dispatcher.php`
- [ ] Dispatcher output shows the job being claimed and a worker spawned
- [ ] Open `cron/logs/job_N.log` (replace N with your job_id) — it should show the worker started and completed with no errors
- [ ] Wait 15–30 seconds for the Claude API call to complete, then check phpMyAdmin:

**Job row:**
- [ ] `status` is now `'completed'`
- [ ] `result_json` contains keys `title`, `description`, `hint`, `image_prompt`, and `choices` (array)
- [ ] `choices` array has the number of entries you requested (2 by default)

**Target scene (DRAFT_SCENE_ID):**
- [ ] `title` has been replaced with the AI-generated title (no longer blank or the old value)
- [ ] `description` contains multiple paragraphs of story text (HTML allowed: `<p>`, `<em>`, `<strong>`)
- [ ] `hint` is either a short sentence or empty string

```sql
SELECT sceneID, title, LEFT(description, 120) AS description_preview, hint
FROM cyoa_ai_scenes WHERE sceneID = DRAFT_SCENE_ID;
```

**Stub scenes created:**
- [ ] The scene count for DRAFT_STORY_ID increased by the number of choices generated

```sql
-- Shows the newly created stub scenes
SELECT sceneID, title, description FROM cyoa_ai_scenes
WHERE storyID = DRAFT_STORY_ID
ORDER BY sceneID DESC
LIMIT 5;
```

- [ ] Each stub scene's title matches a choice text from `result_json`
- [ ] Each stub scene's `description` is empty (it is a placeholder for future generation)

**Choices created:**
- [ ] DRAFT_SCENE_ID now has choices pointing to the new stub scenes

```sql
SELECT c.choiceID, c.choiceText, c.destinationID, s.title AS dest_title
FROM cyoa_ai_choices c
JOIN cyoa_ai_scenes s ON s.sceneID = c.destinationID
WHERE c.sceneID = DRAFT_SCENE_ID;
```

- [ ] One choice row per stub, `choiceText` matches the choice text from the AI result
- [ ] `destinationID` points to a real scene in DRAFT_STORY_ID

---

### Generate Image Option — Image Job Queued

Since **Generate image** was checked when you submitted the scene job, a second `image` job should have been queued automatically when the scene job was applied:

```sql
SELECT job_id, job_type, status,
  JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.prompt')) AS image_prompt,
  JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.theme'))  AS theme
FROM cyoa_ai_jobs
WHERE story_id = DRAFT_STORY_ID AND job_type = 'image'
ORDER BY job_id DESC LIMIT 3;
```

- [ ] A new `image` job exists for DRAFT_STORY_ID with `status = 'pending'`
- [ ] `image_prompt` is a non-empty visual description (taken from `result_json.image_prompt`)
- [ ] `theme` matches the story's theme
- [ ] Run the dispatcher again — this image job completes and the scene's `image` column is populated (same as Phase 4 end-to-end flow)

To skip running the image job during this test, cancel it in phpMyAdmin or via `job_queue.php`.

---

### Ending Scene Mode

- [ ] In the editor, click **+ Add Scene** to create a new blank scene — note its sceneID as **ENDING_SCENE_ID**
- [ ] In the AI scene panel, set **Mode** to **Ending scene**, **Ending Type** to **Death / failure**, Tone to **Dark**
- [ ] Enter a direction such as: `The player triggers a trap and cannot escape`
- [ ] Uncheck **Generate image**
- [ ] Click **Generate Scene** — job queues successfully
- [ ] Run the dispatcher and wait for the job to complete
- [ ] Check phpMyAdmin:
  - `result_json.choices` is an empty array `[]`
  - ENDING_SCENE_ID has a title and description filled in
  - ENDING_SCENE_ID has **no choices** in `cyoa_ai_choices`
  - **No new stub scenes** were created (scene count for DRAFT_STORY_ID unchanged from before this test)
  - **No image job** was queued for this scene

```sql
SELECT choiceID FROM cyoa_ai_choices WHERE sceneID = ENDING_SCENE_ID;
-- Should return 0 rows
```

---

### Job Queue — Completed State

- [ ] Visit `job_queue.php`
- [ ] The scene job row shows status **Completed** (green badge)
- [ ] No **Cancel** or **Retry** button appears on the completed row
- [ ] The notification badge appeared in the nav when the job completed (if you were on another page)
- [ ] Opening `job_queue.php` clears the badge (stamps `seen_at`)

---

### Failed Job — Empty Direction

This test verifies that the worker marks a job failed gracefully when required input is missing. Insert a scene job where `direction` is blank — the worker will reject it immediately without calling the Claude API:

```sql
-- Replace DRAFT_STORY_ID and DRAFT_SCENE_ID with your real values
INSERT INTO cyoa_ai_jobs (user_id, story_id, scene_id, job_type, status, input_json)
VALUES (1, DRAFT_STORY_ID, DRAFT_SCENE_ID, 'scene', 'pending',
  '{"direction":"","mode":"continue","tone":"suspenseful","num_choices":2,"generate_image":false,"story_title":"Test","story_theme":"forest","story_description":"","previous_scenes":[]}');
```

- [ ] Run `php cron/ai_dispatcher.php`
- [ ] Check `cron/logs/job_N.log` — should contain `ERROR: Job #N failed: Scene direction is required.`
- [ ] In phpMyAdmin: `status` is `'failed'`, `error_message` is `Scene direction is required.`
- [ ] `title`, `description`, and `hint` on DRAFT_SCENE_ID are **unchanged** (no partial data written)
- [ ] Job appears as **Failed** (red badge) in `job_queue.php`
- [ ] A **Retry** button is shown on the failed row

---

### BYOK — User's Own Claude Key

- [ ] In `account.php`, save a valid Anthropic API key for your account under **Claude API Key**
- [ ] Create a new shadow draft of any published story (same Setup steps), navigate to a scene
- [ ] Submit a scene generation job via the AI panel with a clear direction and Mode = Continue story
- [ ] Run the dispatcher
- [ ] Job completes successfully using your personal key (no difference in behaviour from the site-wide key)
- [ ] In phpMyAdmin confirm the job completed, the scene content was updated, and stub scenes were created

---

### Navigate to a Stub and Generate Again

This tests the intended workflow: generating scenes outward one step at a time.

- [ ] After a successful scene generation, return to the story overview (`editor.php?storyID=DRAFT_STORY_ID`)
- [ ] The stub scenes created appear in the scene list with titles matching the choice texts
- [ ] Click **Edit** on one of the stub scenes
- [ ] Confirm the edit form is blank (title and description empty) — it is a placeholder
- [ ] Confirm the AI scene panel is visible on this stub's edit form
- [ ] Optionally: submit another scene generation job for the stub to grow the story one more level

---

### Cleanup — Remove Test Rows

After all Phase 5 tests:

```sql
-- Remove the empty-direction failed job
DELETE FROM cyoa_ai_jobs
WHERE job_type = 'scene'
  AND JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.direction')) = '';
```

To reset a scene's AI-generated content back to blank (replace DRAFT_SCENE_ID):

```sql
UPDATE cyoa_ai_scenes SET title = '', description = '', hint = NULL WHERE sceneID = DRAFT_SCENE_ID;
DELETE FROM cyoa_ai_choices WHERE sceneID = DRAFT_SCENE_ID;
```

Discard any shadow drafts created purely for testing (open them in the editor and click **Revert to Original**). This also removes all stub scenes created during the tests.
