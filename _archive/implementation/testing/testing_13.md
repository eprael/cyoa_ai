# Phase 13 — Scene Editor Enhancements

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

You need a draft story with at least one scene. Open the scene editor for that scene. Note its **DRAFT_STORY_ID** and **DRAFT_SCENE_ID**.

```sql
SELECT storyID, title, status FROM cyoa_ai_stories
WHERE status = 'draft' ORDER BY storyID DESC LIMIT 5;
```

---

### 13.1 — Quill WYSIWYG Editor

#### Editor loads

- [ ] Open the scene editor for DRAFT_SCENE_ID
- [ ] Confirm the **Scene Description** field is rendered as a **Quill rich-text editor** — it has a formatting toolbar above an editable area (not a plain `<textarea>`)
- [ ] Confirm the toolbar includes at minimum: **Bold**, **Italic**, heading styles, and bullet list controls
- [ ] Confirm the editor area is visually distinct from other form fields

#### Editing and formatting

- [ ] Click into the editor and type some text
- [ ] Select a word and click **Bold** — confirm the word becomes bold
- [ ] Select a word and click **Italic** — confirm it becomes italic
- [ ] Add a line and apply a **heading** style — confirm it renders as a heading in the editor
- [ ] Create a **bullet list** — confirm each item is indented and marked

#### Saving and reloading

- [ ] Type a description with mixed formatting (e.g. a bold title line, a paragraph, a list) and click **Save Scene**
- [ ] Confirm a success flash message appears
- [ ] Reload the scene editor for the same scene
- [ ] Confirm the Quill editor is pre-populated with the saved content, **formatted correctly** (bold text is bold, list items are listed — not raw HTML tags)

#### Play page renders correctly

- [ ] After saving, open `play.php` and navigate to DRAFT_SCENE_ID (publish the draft first if needed)
- [ ] Confirm the scene description renders the formatted HTML correctly on the play page
- [ ] Confirm no raw `<p>` or `<strong>` tags are visible to the player

#### Existing plain-text descriptions

- [ ] Open a scene that has an existing plain-text description (no HTML)
- [ ] Confirm Quill loads with the plain text displayed correctly (no garbled output)

---

### 13.2 — Inline Generate with AI Button (Scene Image)

#### Button placement

- [ ] Open the scene editor for DRAFT_SCENE_ID
- [ ] Scroll to the **Scene Image** section
- [ ] Confirm a **Generate with AI** button is placed directly beside (or below) the file chooser button
- [ ] Confirm there is no longer a separate "Generate Scene Image with AI" panel lower on the page — the panel has been replaced by this inline button

#### Submitting a generation job

- [ ] Click **Generate with AI**
- [ ] Confirm a prompt input or modal appears asking for a description/prompt for the image
- [ ] Enter a prompt, e.g.: `A dark forest clearing under moonlight, ancient ruins visible through the trees`
- [ ] Submit the generation request
- [ ] Confirm a success message appears: job queued, with a link to the Job Queue
- [ ] In phpMyAdmin confirm a new `image` job exists for DRAFT_SCENE_ID with `status = 'pending'`

#### Run the job

- [ ] Run: `php cron/ai_dispatcher.php`
- [ ] After completion, reload the scene editor — confirm the image preview shows the newly generated image

---

### 13.3 — Image Quality Dropdown + Info Button

#### Dropdown visibility

- [ ] Open the scene editor for DRAFT_SCENE_ID
- [ ] Confirm an **image quality dropdown** is visible near the Generate with AI button (not on the account page)
- [ ] Confirm the dropdown options are: **Low — faster, lower cost**, **Medium**, **High — best quality, higher cost**

#### Info button

- [ ] Confirm an **ⓘ** button is visible next to the quality dropdown
- [ ] Click the **ⓘ** button — confirm a pricing popup appears showing the three quality tiers and their per-image costs
- [ ] Click anywhere outside the popup — confirm it closes
- [ ] Click **ⓘ** again — confirm it re-opens

#### Quality is passed to the job

After submitting an image job with a specific quality selected, verify the value is stored:

```sql
SELECT job_id,
       JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.quality')) AS quality,
       JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.prompt'))  AS prompt
FROM cyoa_ai_jobs
WHERE scene_id = DRAFT_SCENE_ID AND job_type = 'image'
ORDER BY job_id DESC LIMIT 3;
```

- [ ] Select **Low** quality, submit a job — `quality` in `input_json` is `'low'`
- [ ] Select **High** quality, submit a job — `quality` in `input_json` is `'high'`
- [ ] Run the dispatcher — confirm jobs complete and images are saved (quality differences may be subtle visually)

#### Fallback when no quality stored on account

- [ ] Confirm that since quality was removed from the account page (Phase 9.7), omitting a per-request quality selection falls back to the site-wide `OPENAI_IMAGE_QUALITY` constant from `config.php`

---

### Regression — Scene Save Still Works

- [ ] Edit a scene title, description, and hint, then save — confirm all fields persist correctly
- [ ] Upload an image manually via the file chooser — confirm it saves and displays correctly alongside the Generate with AI option
- [ ] Confirm the AI scene generation panel (for scene content, not image) is still present and functional
