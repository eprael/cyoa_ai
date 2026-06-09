# Phase 18 — Story & Scene AI Integration Redesign

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

- Phase 17 must be complete (settings table present, `settings.php` loaded on all pages)
- Log in as a user who has at least one existing story with scenes
- Have the cron worker running (`php cron/ai_dispatcher.php` in a terminal, or confirm it is scheduled and running)
- Have valid Anthropic and OpenAI API keys saved in the admin Site Settings panel

---

### 18.1 — Form Field Reorder

#### Create New Story form

- [ ] Go to `editor.php?action=new_story`
- [ ] Confirm the form fields appear in this exact order from top to bottom:
  1. Theme
  2. Layout
  3. Story Title
  4. Description
  5. Story Thumbnail

#### Edit Story Properties form

- [ ] Open an existing story in the editor, then click the "Story Properties" / edit button
- [ ] Confirm the same field order: Theme → Layout → Story Title → Description → Story Thumbnail

---

### 18.2 — Create New Story: AI Toggle + Settings Section

#### Toggle button appearance

- [ ] Go to `editor.php?action=new_story`
- [ ] Confirm a `✨ AI` toggle button appears to the right of the "Create New Story" heading
- [ ] Confirm the button has a purple background with yellow or white text
- [ ] Confirm the AI settings section (`#story-ai-section`) is **hidden** by default

#### Toggling on

- [ ] Click the `✨ AI` button
- [ ] Confirm the `#story-ai-section` panel becomes visible
- [ ] Confirm the button changes to a **lighter shade** to indicate "on" state
- [ ] Confirm the panel contains these dropdowns: Genre, Tone, Number of Scenes, Number of Endings, Scene Word Length, Scene Image Quality
- [ ] Confirm a `🎲` dice button is visible at the top of the panel with tooltip `Randomize`

#### Toggling off

- [ ] Click the `✨ AI` button again
- [ ] Confirm `#story-ai-section` is hidden
- [ ] Confirm the button returns to its original darker shade

#### localStorage persistence

- [ ] Toggle AI **on**, then reload the page
- [ ] Confirm the AI section is still **open** after reload (state persisted to `localStorage`)
- [ ] Toggle AI **off**, reload
- [ ] Confirm the AI section is **closed** after reload

#### Randomize button

- [ ] Toggle AI on
- [ ] Note the current values in all AI dropdowns (Genre, Tone, etc.) and the form's Theme and Layout dropdowns
- [ ] Click the `🎲` dice button
- [ ] Confirm all AI dropdowns have been set to new random values
- [ ] Confirm the main form's **Theme** dropdown has also been randomized
- [ ] Confirm the main form's **Layout** dropdown has also been randomized
- [ ] Confirm **no API call was made** and **no job was queued** (just client-side value changes)
- [ ] Click `🎲` several more times — confirm values change each click

---

### 18.3 — Create New Story: "Use AI" Checkboxes

#### Checkboxes appear when AI is on

- [ ] Toggle AI on
- [ ] Confirm a `☐ Use AI` select-all checkbox appears above the first form field
- [ ] Confirm individual checkboxes appear in front of: Theme, Layout, Story Title, Description, Story Thumbnail
- [ ] Toggle AI off — confirm **all checkboxes disappear** and all fields become editable

#### Checking individual fields

- [ ] Toggle AI on
- [ ] Check the **Story Title** checkbox
- [ ] Confirm the Story Title input becomes **read-only** and its value is cleared
- [ ] Confirm a placeholder like `generated` appears in the input
- [ ] Uncheck the Story Title checkbox — confirm the field returns to editable with no placeholder

#### Select-all checkbox

- [ ] Toggle AI on
- [ ] Click the `Use AI` select-all checkbox
- [ ] Confirm **all five** individual checkboxes become checked
- [ ] Confirm **all five** form fields become read-only with `generated` placeholder
- [ ] Click the `Use AI` checkbox again (deselect all)
- [ ] Confirm all checkboxes are unchecked and all fields are editable again

#### Thumbnail checkbox with no existing image

- [ ] Ensure the Story Thumbnail field is empty (no file chosen)
- [ ] Check the Story Thumbnail checkbox
- [ ] Confirm no confirm dialog appears (no existing image to warn about)
- [ ] Confirm the thumbnail filename field is grayed out / read-only
- [ ] Confirm the `✨ AI` thumbnail button is disabled

#### Thumbnail checkbox with existing image

- [ ] Open a story that already has a thumbnail image, go to Edit Story Properties
- [ ] *(This scenario only applies to Create New Story if you upload a file first — upload a test image)*
- [ ] After uploading a test image, check the Story Thumbnail checkbox
- [ ] Confirm a confirm dialog appears: `This will replace the existing image. Continue?`
- [ ] Click **Cancel** — confirm the checkbox remains **unchecked** and the file field is still editable
- [ ] Check the checkbox again and click **OK**
- [ ] Confirm the `#story-thumb-ai-expand` section collapses (if it was open)
- [ ] Confirm the filename field is grayed out with placeholder `generated`
- [ ] Confirm the `✨ AI` button next to the thumbnail is disabled

---

### 18.4 — Story Thumbnail [✨ AI] Button (Create + Edit Story)

#### Button placement and tooltip

- [ ] On **Create New Story**, confirm a small `✨ AI` button appears immediately to the right of the Story Thumbnail filename input
- [ ] Hover over the button — confirm tooltip reads `Generate image with AI`
- [ ] On **Edit Story Properties**, confirm the same button is present

#### Expand / collapse

- [ ] Click the `✨ AI` thumbnail button
- [ ] Confirm a section (`#story-thumb-ai-expand`) appears below the file input
- [ ] Confirm the expanded section has a visually distinct background shade and border (separated from the rest of the form)
- [ ] Confirm the expanded section contains: a description textarea, an image quality dropdown, and a `Generate Image` button
- [ ] Confirm the description textarea shows placeholder text `use story description`
- [ ] Click the button again — confirm the section collapses

#### Submit generates an image job

- [ ] On **Edit Story Properties**, expand the thumbnail AI section
- [ ] Leave the description blank (to use the story description as the prompt)
- [ ] Select quality `medium`
- [ ] Click `Generate Image`
- [ ] Confirm a small inline status message appears (e.g. "Job queued" or spinner)
- [ ] Confirm you are **not redirected away** from the page
- [ ] Go to `job_queue.php` — confirm a new image job for this story appears with status `pending` or `running`
- [ ] Wait for the cron worker to process it — confirm the story's thumbnail image is updated
- [ ] Reload Edit Story Properties — confirm the new thumbnail is visible

---

### 18.5 — Create Button: Story Creation + Job Queuing

#### AI off — normal save

- [ ] Go to `editor.php?action=new_story` with AI toggle **off**
- [ ] Fill in Story Title, Description, Theme, Layout; skip Thumbnail
- [ ] Click `Create`
- [ ] Confirm you are redirected to the story editor for the new story (not to `index.php`)
- [ ] Confirm the story was saved with `status = published` (or whatever the default is for manual creation)
- [ ] Confirm no AI job was created in `cyoa_ai_jobs`

#### AI on — all fields checked

- [ ] Go to `editor.php?action=new_story` with AI toggle **on**
- [ ] Check all five field checkboxes (or use select-all)
- [ ] Set AI dropdowns: Genre = `fantasy`, Tone = `suspenseful`, # Scenes = `8`, # Endings = `2`, Word Length = `100`, Image Quality = `medium`
- [ ] Click `Create`
- [ ] Confirm a browser `alert()` appears with the submission message (e.g. `Story creation job submitted. You'll find it in your stories once it's ready.`)
- [ ] Click OK on the alert
- [ ] Confirm you are redirected to `index.php`
- [ ] Go to `job_queue.php` — confirm a new `create_story` job is in the queue
- [ ] Check the `cyoa_ai_stories` table — confirm a new story row exists with `status = draft` and all checked fields (title, description, theme) are NULL or empty
- [ ] Wait for the cron worker to process — confirm the story record is populated and scenes are created
- [ ] Confirm the story remains `draft` (not auto-published)

#### AI on — some fields checked, some filled manually

- [ ] Toggle AI on
- [ ] Check **Story Title** and **Description** (leave Theme, Layout, Thumbnail unchecked)
- [ ] Manually set Theme to `forest`, Layout to `image_left`
- [ ] Click `Create`
- [ ] Confirm the alert appears and you are redirected to `index.php`
- [ ] Check the DB — confirm the new story has `theme = 'forest'` and `layout = 'image_left'` saved immediately (manual values), while title and description are NULL
- [ ] After cron processes the job — confirm title and description are filled in by AI, while theme and layout remain `forest` and `image_left`

---

### 18.6 — Edit Story Properties: Simplified Form

- [ ] Open an existing story and go to Edit Story Properties
- [ ] Confirm there are **no tabs** (no "Create by Hand" / "Generate with AI" tab bar)
- [ ] Confirm there is **no AI toggle button** beside the heading
- [ ] Confirm there is **no premise textarea**
- [ ] Confirm there are **no Generate buttons** (Generate Story Properties, Generate Scenes, Generate Everything)
- [ ] Confirm the form contains only: Theme, Layout, Story Title, Description, Story Thumbnail (in that order)
- [ ] Confirm the Story Thumbnail `✨ AI` button is present (task 18.4)
- [ ] Fill in new values for Title and Description and click Save
- [ ] Confirm the changes are saved and you are redirected back to the story editor

---

### 18.7 — Scene Pages: AI Modal Button

#### Button placement and tooltip (Create New Scene)

- [ ] Go to `editor.php?action=new_scene&storyID=X`
- [ ] Confirm a `✨ AI` button appears to the right of the "Create New Scene" heading
- [ ] Hover — confirm tooltip reads `Generate With AI`

#### Button placement and tooltip (Edit Scene)

- [ ] Go to `editor.php?action=edit_scene&storyID=X&sceneID=Y`
- [ ] Confirm the `✨ AI` button appears to the right of "Edit Scene"
- [ ] Hover — confirm tooltip reads `Regenerate with AI`

#### Opening the modal — no existing content

- [ ] On a **new blank scene form** (all fields empty), click `✨ AI`
- [ ] Confirm the modal opens **immediately** (no warning dialog)
- [ ] Confirm the modal contains: Scene Direction textarea, Mode dropdown (Continue Story / End Story), Number of Choices dropdown, Tone dropdown, Include Image checkbox
- [ ] Confirm `[Generate]` and `[Cancel]` buttons are present

#### Opening the modal — existing content warning

- [ ] On an **edit scene page** (scene already has title, description, and choices), click `✨ AI`
- [ ] Confirm a warning dialog appears: `This will overwrite the current scene content. Continue?`
- [ ] Click **Cancel** — confirm the modal does **not** open and the form is unchanged
- [ ] Click `✨ AI` again and click **OK** in the warning
- [ ] Confirm the modal opens

#### Submitting the modal

- [ ] Fill in Scene Direction with `A dark forest path leads to a fork in the road`
- [ ] Set Mode to `Continue Story`, Choices to `3`, Tone to `mysterious`
- [ ] Check `Include Image`
- [ ] Click `[Generate]`
- [ ] Confirm you are redirected to `editor.php?action=edit_story&storyID=X` (the scene list for this story)
- [ ] Go to `job_queue.php` — confirm a new `scene` job is queued
- [ ] Wait for cron to process — confirm the scene's title, description, and choices are populated
- [ ] If Include Image was checked, confirm a child image job was also created

#### Cancelling the modal

- [ ] Open the modal and click `[Cancel]`
- [ ] Confirm the modal closes and the scene form is unchanged

---

### 18.8 — Scene Thumbnail [✨ AI] Button

- [ ] On a **Create New Scene** or **Edit Scene** page, confirm the `✨ AI` button appears to the right of the scene image filename input
- [ ] Hover — confirm tooltip reads `Generate image with AI`
- [ ] Click the button — confirm `#scene-thumb-ai-expand` section appears with a distinct background shade
- [ ] Confirm it contains: description textarea (placeholder `use scene description`), image quality dropdown, `Generate Image` button
- [ ] Click the button again — confirm the section collapses
- [ ] Expand it, leave description blank, choose quality `low`, click `Generate Image`
- [ ] Confirm an inline status message appears and **no redirect occurs**
- [ ] Confirm a new `image` job appears in `job_queue.php`
- [ ] After cron processes it — confirm the scene's image file is updated

---

### 18.9 — New Job Type: create_story

#### Job is routed and processed by cron

- [ ] Trigger a story creation with AI on and at least one checked field (see 18.5)
- [ ] While the job is in `pending` state, confirm `job_type = 'create_story'` in the `cyoa_ai_jobs` table
- [ ] Wait for the cron worker to run
- [ ] Confirm the job status changes from `pending` → `running` → `completed`
- [ ] Confirm the story record (identified by `story_id` in the job's `input_json`) is populated with AI-generated content

#### Properties phase runs for checked fields

- [ ] After the job completes, confirm that fields which were **checked** (e.g. title, description) have AI-generated values
- [ ] Confirm fields that were **unchecked** (e.g. theme, layout) still hold the manually entered values from the form

#### Scenes are created

- [ ] Confirm scenes were inserted into `cyoa_ai_scenes` for the story
- [ ] Confirm the scenes have title, description, and choices populated
- [ ] Confirm the number of scenes matches the "Number of Scenes" AI setting you chose

#### Child jobs for images

- [ ] If Scene Image Quality was not `none`: confirm child `image` jobs were queued for each scene
- [ ] If Story Thumbnail checkbox was checked: confirm a `story_cover` child image job was queued
- [ ] After cron processes child jobs — confirm scene and/or cover images are attached

#### Job fails gracefully if API is unavailable

- [ ] Temporarily set `anthropic_api_key` to an invalid value in Site Settings
- [ ] Queue a new `create_story` job
- [ ] Confirm the job status eventually changes to `failed` (not stuck in `running` forever)
- [ ] Confirm the story record is not corrupted (partial data is acceptable; no PHP fatal errors)
- [ ] Restore the correct API key

---

### 18.10 — Old Structure Removed

- [ ] Open `editor.php` source (View Source in browser on the Create New Story page)
- [ ] Confirm there is **no** tab structure HTML (no `story-form-tabs` class, no tab buttons)
- [ ] Confirm there is **no** `#ai-premise` textarea
- [ ] Confirm there are **no** `Generate Story Properties`, `Generate Scenes`, or `Generate Everything` buttons
- [ ] Confirm `switchStoryTab()` is **not** present in the page's JavaScript
- [ ] Confirm `generateProperties()`, `generateScenes()`, `generateEverything()` are **not** present
- [ ] Confirm `randomizeAll()` is **not** present (replaced by `randomizeStoryAI()`)
- [ ] Confirm `randomizeStoryAI()` **is** present

---

### 18.11 — CSS Updates

#### Deprecated classes

- [ ] Open `styles/editor.css`
- [ ] Confirm `.story-form-tabs` and `.story-tab-btn` are commented out or marked deprecated (not deleted yet)

#### New classes present

- [ ] Confirm `.story-ai-section` is defined with background, border, border-radius, and padding
- [ ] Confirm `.story-ai-header` is defined
- [ ] Confirm `.story-ai-dropdowns` is defined
- [ ] Confirm `.use-ai-checklist` is defined
- [ ] Confirm `.thumb-ai-expand` is defined with background and border
- [ ] Confirm `.btn-ai-inline` is defined (for `✨ AI` buttons)
- [ ] Confirm `#scene-ai-modal` is defined (fixed overlay with dark backdrop)
- [ ] Confirm `.field-ai-checked` is defined with reduced opacity and light background

#### Visual check

- [ ] Toggle AI on the Create New Story page and confirm the AI settings section has a visually distinct panel appearance
- [ ] Check a field and confirm the grayed-out `.field-ai-checked` appearance is applied
- [ ] Expand any thumbnail AI section and confirm the expanded area has the distinct shaded background
- [ ] Open the scene AI modal and confirm it has a dark overlay backdrop with a centered card

---

### 18.12 — File Retirement

- [ ] Confirm a `retired/` folder exists in the project root
- [ ] Confirm `api_ai_properties.php` is **not** in the project root (has been moved)
- [ ] Confirm `retired/api_ai_properties.php` exists
- [ ] Confirm `generate_story.php` is **not** in the project root
- [ ] Confirm `retired/generate_story.php` exists
- [ ] Try navigating to `http://localhost/projects/cyoa_ai/generate_story.php` — confirm it returns a 404 or server error (file no longer at that path)
- [ ] Try navigating to `http://localhost/projects/cyoa_ai/api_ai_properties.php` — confirm it is no longer accessible from the web root

---

### Regression

- [ ] Create a story **without AI** (AI toggle off) — confirm it works as before and saves correctly
- [ ] Edit an existing story's properties manually — confirm save works and no AI job is created
- [ ] Edit an existing scene manually — confirm save works without triggering any modal or AI interaction
- [ ] Play a published story end-to-end — confirm no breakage
- [ ] Confirm the existing `scene` and `image` job types still work (queue a scene AI job via the modal and confirm cron processes it)
- [ ] Confirm `job_queue.php` loads and shows all job types correctly
- [ ] Confirm `index.php` (gallery) loads without errors
