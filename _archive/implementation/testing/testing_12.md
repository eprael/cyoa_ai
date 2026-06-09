# Phase 12 — Story Editor — Scene Card Redesign

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

You need a draft story with at least three scenes. At least one scene should have:
- A cover image set
- A title and description
- At least one choice

Create or use an existing shadow draft. Note its **DRAFT_STORY_ID**.

```sql
-- Find a suitable draft story
SELECT storyID, title, status FROM cyoa_ai_stories
WHERE status = 'draft'
ORDER BY storyID DESC LIMIT 5;

-- Check its scenes
SELECT sceneID, title, LEFT(description, 60) AS desc_preview, image
FROM cyoa_ai_scenes WHERE storyID = DRAFT_STORY_ID;
```

---

### 12.1 — Scene Card Layout: Thumbnail + Metadata

#### Card structure

- [ ] Open the story overview for DRAFT_STORY_ID (`editor.php?storyID=DRAFT_STORY_ID`)
- [ ] Confirm each scene in the list is displayed as a card with a **thumbnail on the left** and metadata on the right
- [ ] The thumbnail is approximately **300 × 300 px** (or as configured by `SCENE_THUMB_SIZE`)
- [ ] The right side shows: scene **title**, first ~150 characters of the **description**, and the scene's **choices**

#### Scene with image

- [ ] Find a scene that has an image set
- [ ] Confirm the thumbnail shows the actual scene image (not a placeholder)
- [ ] Confirm the image is cropped/scaled to fill the thumbnail area without distortion

#### Scene without image

- [ ] Find a scene with no image set
- [ ] Confirm a placeholder graphic or icon fills the thumbnail area (no broken image or blank gap)

#### Long description

- [ ] Find or create a scene with a long description (200+ characters)
- [ ] Confirm the description is truncated at approximately 150 characters with an ellipsis — it does not overflow the card

---

### 12.2 — Choices Displayed on Scene Cards

- [ ] On the scene list, find a scene with multiple choices
- [ ] Confirm the choice texts are listed on the card (e.g. as a bulleted or numbered list)
- [ ] Confirm the choice list is read-only — no edit controls on the card itself
- [ ] Find a scene with **no choices** (e.g. an ending scene or a stub) — confirm the choices area is empty or shows "No choices" and the card does not error

---

### 12.3 — Click Thumbnail to Enlarge (Modal)

#### Opening the modal

- [ ] Click the thumbnail of a scene that has an image
- [ ] Confirm a **modal overlay** opens showing the full-size image
- [ ] Confirm the modal blocks interaction with the page behind it (a dark overlay covers the background)
- [ ] Confirm the full-size image is not cropped — the entire image is visible

#### Closing the modal

- [ ] Click the **close button** (✕ or similar) on the modal — confirm it closes
- [ ] Click anywhere on the dark overlay **outside** the image — confirm the modal closes
- [ ] Press the **Escape key** — confirm the modal closes
- [ ] After closing, confirm you are back on the story overview with no layout changes

#### Scene without image

- [ ] Click the placeholder thumbnail of a scene with no image
- [ ] Confirm no modal opens (or an appropriate message is shown — placeholder is not clickable)

#### Multiple opens

- [ ] Open the modal for scene A, close it, then open for scene B — confirm the correct image appears each time

---

### Regression — Scene Edit Still Works

- [ ] Click the **Edit** button on a scene card — confirm it opens the scene editor as before
- [ ] Edit the scene title and save — confirm the card on the overview updates to reflect the new title
- [ ] Add a new scene — confirm it appears in the list with the new card layout
- [ ] Delete a scene — confirm it is removed from the list
