# Phase 16 — Play Page — Sticky Layout

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

You need at least one published story for each of the three image layout settings (top, left, right). If you don't have all three, change the layout setting on a test story in the editor to test each case.

You also need a scene with a **long description** (enough text to require scrolling). If needed, temporarily paste several paragraphs of placeholder text into a scene description to trigger the overflow.

---

### 16.1 — Basic Sticky Behaviour

- [ ] Open `play.php` for a published story with a **long scene description** (one that overflows the viewport height)
- [ ] Confirm the **scene title** is visible at the top of the page
- [ ] Confirm the **scene image** is visible
- [ ] Scroll down through the description text
- [ ] Confirm the **title remains pinned** at the top — it does not scroll away
- [ ] Confirm the **scene image remains visible** — it does not scroll away
- [ ] Confirm the description text and choice buttons scroll independently beneath the sticky area
- [ ] The choices are still reachable by scrolling down — they do not disappear

#### Short scene (no scrolling needed)

- [ ] Open a scene with a short description that does not overflow the viewport
- [ ] Confirm the layout still looks correct — no large blank space or awkward gaps
- [ ] Confirm choices are visible without scrolling

---

### 16.2 — Three Image Position Layouts

#### Layout: Top

- [ ] Open a published story with image layout set to **Top**
- [ ] Confirm the scene image appears **below the title**, spanning the full width (or a large centered width)
- [ ] Confirm the description text and choices are below the image in a scrollable area
- [ ] Scroll down — confirm the title stays pinned at the very top, and the image also stays in place

#### Layout: Left

- [ ] Open a published story with image layout set to **Left**
- [ ] Confirm the scene image is in a **fixed left column**
- [ ] Confirm the description text and choices occupy the **right column** and scroll independently
- [ ] Confirm the title is centered across the full top of the page (spanning both columns)
- [ ] Scroll the right column — confirm the title and image remain stationary

#### Layout: Right

- [ ] Open a published story with image layout set to **Right**
- [ ] Confirm the scene image is in a **fixed right column**
- [ ] Confirm the description text and choices occupy the **left column** and scroll
- [ ] Confirm the title is centered across the full top
- [ ] Scroll — confirm title and image remain stationary

#### Navigating between scenes

- [ ] Click a choice to advance to the next scene
- [ ] Confirm the sticky header area updates to show the new scene's title and image
- [ ] Confirm scrollable content resets to the top for the new scene (not mid-scroll from the previous scene)
- [ ] Repeat for 3–4 scenes with different description lengths

---

### 16.3 — Mobile / Narrow Viewport

- [ ] Open `play.php` and resize the browser to approximately **375px wide** (or use DevTools device emulation)

#### Top layout on mobile

- [ ] Confirm the layout stacks: title → image (full width) → scrollable text → choices
- [ ] Confirm no horizontal overflow or side-scrolling is introduced
- [ ] Confirm the sticky title remains at the top while content scrolls

#### Left / Right layout on mobile

- [ ] Open a story with Left or Right image layout on a narrow screen
- [ ] Confirm the layout **switches to the stacked mobile layout** regardless of the desktop image position setting — image is not forced into a side column on a narrow screen
- [ ] Confirm the title → image → text → choices stack order

#### Touch scrolling

- [ ] On a touch device (or DevTools touch simulation), scroll through a long scene description
- [ ] Confirm the scroll is smooth and the sticky elements do not jump or flicker

---

### Regression — Core Play Functionality

- [ ] Play a story end-to-end (at least 3 scenes including one ending scene) — confirm choices work correctly
- [ ] Confirm the scene image updates correctly when navigating between scenes
- [ ] Confirm scenes with no image show the placeholder correctly without breaking the layout
- [ ] Confirm the play page still returns a 404 for draft stories when accessed by a non-owner
