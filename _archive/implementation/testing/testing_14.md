# Phase 14 — Create Story — Tabbed AI Generator

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

Log in as a user with a valid Claude API key configured (either in `config.php` or in Account Settings). Confirm `AI_ENABLED = true` in `config.php`.

---

### 14.1 — Tabbed Interface

- [ ] Visit `editor.php?action=new_story` (Create New Story)
- [ ] Confirm two tabs are visible: **Create by Hand** and **Generate with AI**
- [ ] **Create by Hand** tab is active by default
- [ ] Confirm the existing story creation form (title, description, theme, image) is on the **Create by Hand** tab — it is unchanged from before
- [ ] Click **Generate with AI** — confirm the AI generation form appears
- [ ] Switch back to **Create by Hand** — confirm the manual form reappears

#### Edit Story Properties also has tabs

- [ ] Open an existing story in the editor and navigate to Story Properties
- [ ] Confirm the same two-tab layout is present
- [ ] **Create by Hand** tab shows the existing fields pre-filled with the story's current values

---

### 14.2 — Generate Story Properties (Synchronous)

- [ ] On the **Generate with AI** tab, confirm a **Generate Story Properties** button is present
- [ ] Optionally enter a brief premise hint in an input field (if provided), or leave it blank for a fully AI-chosen result
- [ ] Click **Generate Story Properties**
- [ ] Confirm the button shows a loading/spinner state while the request is in progress
- [ ] Within 10–20 seconds, confirm the **title**, **description**, and **theme** fields are auto-filled with AI-generated content (no page reload)
- [ ] Confirm the button re-enables after the response arrives
- [ ] Click **Generate Story Properties** again — confirm the fields are replaced with a new AI suggestion
- [ ] Click it a third time — confirm it works repeatedly without error

#### Validation

- [ ] Confirm the generated title is not empty
- [ ] Confirm the generated description is at least one sentence
- [ ] Confirm the generated theme is one of the valid values: `egyptian`, `forest`, `scifi`, `ocean`, `desert`
- [ ] If the API call fails (e.g. bad key), confirm an inline error message is shown (not a blank form)

---

### 14.3 — Generate Scenes Button (Async Job)

> **Note:** This test requires story properties to be filled in first (either from 14.2 or typed manually).

- [ ] On the **Generate with AI** tab, fill in or generate story properties (title, description, theme)
- [ ] Confirm a **Generate Scenes** button is present and distinct from the Generate Story Properties button
- [ ] Click **Generate Scenes**
- [ ] Confirm a success message appears: "✓ Story job queued! Check the Job Queue for progress…"
- [ ] Confirm the button is disabled during the request
- [ ] In phpMyAdmin confirm a new `full_story` job exists with `status = 'pending'`:
```sql
SELECT job_id, status, input_json FROM cyoa_ai_jobs
WHERE job_type = 'full_story' ORDER BY job_id DESC LIMIT 3;
```
- [ ] Confirm `input_json` contains the title, description, and theme from the form
- [ ] Navigate to `job_queue.php` — confirm the job appears
- [ ] Run: `php cron/ai_dispatcher.php`
- [ ] Wait for the job to complete, then confirm the story was created and appears in **My Stories**

#### generate_story.php retired

- [ ] Confirm that `generate_story.php` no longer exists (or redirects to the new flow)
- [ ] Confirm the **Generate** nav link that was removed in Phase 9.3 no longer points anywhere broken

---

### 14.4 — Word Length Dropdown

- [ ] On the **Generate with AI** tab, confirm a **Scene Word Length** dropdown is visible
- [ ] Dropdown options are: **Short (~50 words)**, **Medium (~100 words)**, **Long (~200 words)**
- [ ] Select **Short** and generate a story — after the job completes, check scene descriptions in phpMyAdmin:
```sql
SELECT sceneID, LENGTH(description) AS char_count, LEFT(description, 100) AS preview
FROM cyoa_ai_scenes WHERE storyID = NEW_STORY_ID;
```
- [ ] Scene descriptions are noticeably shorter than a Medium or Long run
- [ ] Select **Long** and run another story — confirm descriptions are noticeably longer

---

### 14.5 — Image Quality Dropdown (Replaces Checkbox)

- [ ] On the **Generate with AI** tab, confirm an **image generation dropdown** replaces the old "Also generate images" checkbox
- [ ] Dropdown options are: **Don't generate images**, **Generate — Low quality**, **Generate — Medium quality**, **Generate — High quality**
- [ ] Default selection is **Don't generate images**
- [ ] Confirm an **ⓘ** pricing info button is visible next to the dropdown; clicking it shows the pricing table

#### With images enabled

- [ ] Select **Generate — Medium quality**, generate a story
- [ ] After the full_story job completes, confirm image jobs were queued for each scene:
```sql
SELECT job_id, scene_id, status,
       JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.quality')) AS quality
FROM cyoa_ai_jobs
WHERE story_id = NEW_STORY_ID AND job_type = 'image';
```
- [ ] `quality` is `'medium'` on all image job rows
- [ ] Select **Generate — High quality**, generate another story — image jobs show `quality = 'high'`

#### Without images

- [ ] Select **Don't generate images**, generate a story — confirm no image jobs are created for that story

---

### 14.6 — Generate Cover Image

- [ ] On the **Create by Hand** tab (or the story properties section), confirm a **Generate cover image** dropdown is present beside the Story Thumbnail file chooser
- [ ] Dropdown options are: **Low quality**, **Medium quality**, **High quality** (and an info button)
- [ ] Select **Medium quality** and click the generate button
- [ ] Confirm an image job is queued for the story with `scene_id = NULL` and a `target = 'story_cover'` field in `input_json`:
```sql
SELECT job_id, scene_id, status, input_json
FROM cyoa_ai_jobs WHERE story_id = X AND job_type = 'image'
ORDER BY job_id DESC LIMIT 3;
```
- [ ] Run the dispatcher — confirm the job completes and the story's thumbnail is updated
- [ ] Reload the story editor — confirm the cover image preview shows the AI-generated image

---

### 14.7 — Generate Everything Button

- [ ] On the **Generate with AI** tab, confirm a **Generate Everything** button is present
- [ ] Click **Generate Everything** with all dropdowns set
- [ ] Confirm the button shows a loading spinner (properties are being fetched synchronously)
- [ ] Within 10–20 seconds, confirm the title/description/theme fields auto-fill, then a scenes job is immediately queued (the same as clicking both buttons in sequence)
- [ ] Confirm a success message appears after the scenes job is queued
- [ ] Confirm only **one** `full_story` job was created (not two)

---

### 14.8 — Job Queue Link → Story Properties Page

- [ ] After a `full_story` job completes, visit `job_queue.php`
- [ ] Confirm the **Go to story →** link in the job row links to the story's **properties/overview page** in the editor (`editor.php?storyID=X&action=view_story` or equivalent)
- [ ] Click the link — confirm you land on the story overview (not just the scene list)

---

### Guest Rejection

- [ ] Log out, then visit `editor.php?action=new_story` — confirm redirect to `login.php`
- [ ] Log out and submit a `full_story` job via the console:
```js
fetch('api_jobs.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=create&job_type=full_story&input_json=' + encodeURIComponent(JSON.stringify({premise:'test',genre:'fantasy',tone:'dark',target_scenes:8,num_endings:2}))
}).then(r => r.json()).then(console.log)
```
- [ ] Confirm response is HTTP 401: `{"success":false,"error":"Login required."}`
