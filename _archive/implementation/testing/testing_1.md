# Phase 1 — Draft/Published System

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### New Story Starts as Draft
- [ ] Create a new story via `editor.php?action=new_story`
- [ ] After saving, the editor should show a **Draft** badge in the story meta row
- [ ] The action buttons should be **Publish** and **Delete Story** only
- [ ] Go to `index.php?filter=mine` — the card should have a yellow **Draft** badge
- [ ] The card should have an **Edit** button but no **Play** or **Clone** buttons

### Publishing a Standalone Draft
- [ ] In the editor, click **Publish**
- [ ] Badge changes to **Published**; buttons change to **Edit** and **Unpublish**
- [ ] In the gallery, the Draft badge is gone
- [ ] **Play** and **Clone** buttons appear on the card
- [ ] Story is visible in the **All Stories** view (`index.php`)

### Unpublish
- [ ] In the editor for a published story, click **Unpublish** → confirm the prompt
- [ ] Story becomes a standalone draft; badge shows **Draft**; buttons show **Publish** and **Delete Story**
- [ ] Story disappears from the **All Stories** gallery but remains in **My Stories** with a Draft badge
- [ ] Re-publish it before continuing

### Shadow Draft — Start Editing a Published Story
- [ ] In the editor for a published story, click **Edit**
- [ ] The URL should change to a **different storyID** (the shadow draft)
- [ ] The badge should say **Draft**; buttons should be **Publish** and **Revert to Original**
- [ ] The original story is still visible and playable by other users during this time

### Shadow Draft — Revert to Original
- [ ] While in the shadow draft, edit the story title (Story Properties → change the title → Save Changes)
- [ ] Confirm the draft shows the new title
- [ ] Click **Revert to Original** → confirm the prompt
- [ ] Should redirect back to the published story's editor
- [ ] The published story should have the **original** title — change was discarded ✓
- [ ] The shadow draft storyID should no longer exist (verify in phpMyAdmin if needed)

### Shadow Draft — Publish Changes
- [ ] Click **Edit** again on the published story to create a new shadow draft
- [ ] Change the title to something distinct (e.g. "v2 Title")
- [ ] Click **Publish** → confirm the prompt
- [ ] Should redirect back to the same published storyID
- [ ] The published story now shows the new title ✓
- [ ] The shadow draft storyID no longer exists (only the original, now updated)
- [ ] Play the story — content reflects the changes

### Shadow Draft — Only One Draft at a Time
- [ ] Visit `editor.php?storyID=X` (where X is a published story that already has an active shadow draft)
- [ ] Should be immediately redirected to the existing draft (not create a second one)

### Draft Access Protection
- [ ] Note the storyID of a draft story
- [ ] Log out (or open an incognito window)
- [ ] Visit `play.php?storyID=<draft_id>` → should return a 404 error
- [ ] Visit it while logged in as the owner → should work

### Regression — Existing Functionality
- [ ] Published stories still play correctly
- [ ] Adding/editing/deleting scenes works on a draft story
- [ ] Clone still works on a published story (clone should start as a draft)
- [ ] Admin can edit any story
