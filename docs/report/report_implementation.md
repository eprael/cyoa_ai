# Project Report — Implementation Plan

A plan for producing an **HTML report** for the CYOA Maker AI Upgrade project. The
report is a class deliverable. It explains what was built, walks through the running
app, demystifies the AI pipeline, and reflects on the development process. The big
reference docs (architecture + the seven implementation plans) ride along as
appendices.

---

## 1. Goal & Deliverable

- **A multi-page HTML report**, not one giant file. An **index page**
  (`docs/report/index.html`) links out to the report sections and the appendices.
- **Mixed build model:**
  - **Main narrative pages** (index, overview, walkthrough, AI process, dev reflections)
    are **hand-written HTML** — full control over screenshot layout, the `<video>` embed,
    and the lightbox.
  - **Appendix pages** (A–I) are existing `.md` files, **converted with pandoc**.
    (Appendix J is image-only, hand-written HTML like the main pages.)
- Reuses the project's existing screenshots (`docs/report/screenshots/`) and the
  prompt-assembly visualization assets in `docs/visualizations/ai-prompt-assembly/`.

### Writing style (applies to all pages)

- **Simple and light. Short sentences.** Write for a **grade-10 audience**.
- Plain words over jargon. When a technical term is needed, explain it in one line.
- Say "the AI" rather than naming providers in body copy (provider names are fine where
  they're specifically relevant, e.g. "Claude for text, OpenAI for images").

### Build pipeline

**Shared assets every page links (main HTML *and* pandoc appendices):**
- `report.css` — report theme (white-on-blue, Inter font, header-bar styling, `img`
  sizing). Lives in `docs/report/` (separate from the app's `theme.css` so we don't
  disturb app styles).
- `header.js` — injects the shared header bar (see below).
- `lightbox.css` + `lightbox.js` — click-to-enlarge modal.

**Appendix pages — pandoc**, using a small custom template so the converted HTML loads
those same assets and contains the header placeholder:

```
pandoc <page>.md -o <page>.html -s --template=report-template.html5 --toc --toc-depth=2
```

The `report-template.html5` is a trimmed template that, in `<head>`, pulls in the Inter
font + `report.css` + `lightbox.css`, and before `$body$` places the header placeholder
`<div id="report-header"></div>` and the `<script src>` tags for `header.js` /
`lightbox.js`. (Mermaid → SVG swap still happens in the `.md` first, per §4.)

**Main pages — hand-written HTML** that include the exact same `<head>` assets, the same
`<div id="report-header"></div>` placeholder, and the same scripts. Keeping the head/foot
boilerplate identical to the template is what makes every page look uniform.

### Look & feel — match `proposal.html`

- **Match the proposal's fonts:** **Inter** (Google Fonts), loaded the same way
  `docs/proposal/proposal.html` does, with the same `-apple-system` fallback stack.
- Carry over the proposal's page feel where it helps (~`860px` content column).
- **Theme: white-on-blue.** A solid blue header bar with white text (and white logo/title);
  pick a blue that matches the app/logo. Body stays light/readable below the bar.

#### Header bar (every page)

A blue bar across the top, **logo left-aligned**, title block beside it:

- **Logo (left):** `images/app/logo_square.png` — from `docs/report/` the relative path is
  `../../images/app/logo_square.png`.
- **Main title (large):** **CYOA Maker With AI**
- **Sub-title 1:** Webtech 10/11 / Term 3 Project
- **Sub-title 2:** By Evan Prael

#### Single-source header via `header.js`

The header is defined **once** in `header.js` and injected into every page — so editing
that one file updates all pages (main + appendix), **no rebuild needed**.

- Each page has an empty `<div id="report-header"></div>` placeholder near the top of
  `<body>`; `header.js` fills it on load.
- The header **markup is inline in `header.js`** (a JS string), *not* fetched from a
  separate file — `fetch()` of an HTML fragment fails under `file://` (how a report is
  usually opened from disk). Inline string injection works offline.
- Header **styling** lives in `report.css` (shared), so look + content are both
  single-source.
- The placeholder reserves height (min-height in CSS) to avoid layout flash before JS
  runs. Requires JS enabled — fine for a self-viewed report.

> **Note on pandoc + the placeholder.** Because the appendix pages use
> `report-template.html5`, the placeholder div + `header.js`/`lightbox.js` script tags
> are already in that template — so pandoc output gets the shared header automatically,
> the same as the hand-written pages.

---

## 2. Report Structure

The report is split into pages, reached from an index. Proposed pages:

### Page 0 — Index
- Title, course (WebTech10, Grade 12A 2025–2026), author, date.
- Short blurb on what the project is.
- Linked table of contents to the pages below + appendices.

### Page 1 — Project Overview
- What CYOA Maker is (build / share / play branching stories).
- **The upgrade story** — frame this as an upgrade of the *previous* (pre-AI) project.
  What existed before vs. what was added: three tiers of AI generation (images, single
  scenes, full stories) plus social features (ratings, comments, favourites, views).
- Tech stack at a glance, in plain terms (PHP, MySQL, vanilla JS; the AI uses Claude for
  text and OpenAI for images).

### Page 2 — Application Walkthrough
- Screenshot-driven tour, simple captions (see §3).

### Page 3 — The AI Process (simplified)
- **Prompt assembly** — how a prompt is built from templates + the user's choices +
  random fill-ins for "Any" options + the genre hint. Keep it conceptual.
  - **Embed the image:** `docs/visualizations/ai-prompt-assembly/AI_Prompt_Assembly_Line_Anatomy.png`.
  - **Embed the explainer video** (local, HTML5 `<video controls>` player — no YouTube):
    `docs/visualizations/ai-prompt-assembly/AI_Assembly_Line__UI_to_API.mp4` (~45 MB).
    Markdown can't make a video player, so this goes in as raw HTML in the page, or is
    added during the hand-polish pass after pandoc.
  - Source text to adapt: `docs/visualizations/ai-prompt-assembly/ai-prompt-assembly.md`.
  - The deeper, diagram-heavy version lives in the appendix (see §4, Appendix I).
- **Job queue** — the async pattern in plain English: you submit → a job row is created →
  a background worker picks it up → it calls the AI and saves the result → the page
  checks back and shows it. Reuse/condense the existing explainer
  `docs/report/cyoa_ai_architecture_explained.md` and its diagram.

### Page 4 — Development Reflections
- **Plans for big things, "vibe coding" for small things** — large features got a
  written plan (v1–v7) before any code; small tweaks were done ad hoc. Point to the
  appendix plans.
- **`progress.md` between sessions** — a running progress log carried context from one
  work session to the next (the v3–v6 folders each have a `*-progress.md`;
  `_archive/progress.txt` is the longer log). Explain why this helped when working with
  an AI assistant across many sittings.
- **The Claude Code setup** — how the assistant itself was configured to work well:
  - **`CLAUDE.md`** — the project instructions file that teaches the AI the codebase
    rules and conventions every session (it's at the project root).
  - **Permissions** — the allow/deny settings in `.claude/settings.json` /
    `settings.local.json` that control what the assistant can run without asking.
  - **Closed feedback loop** — how the assistant checked its own work (lint, DB/DOM
    checks, running the app) so problems were caught and fixed automatically instead of
    by hand.
- Optional: lessons learned / what we'd do differently.

### Appendices
- See §4.

---

## 3. Walkthrough Screenshot Plan

**Use only `docs/report/screenshots/`** (do **not** use the `docs/proposal/*.png` set).
The files are already named after what they show — **don't rename them**. Each gets a
1–3 sentence caption in the simple style. Tour order and shots:

| Beat | Screenshot |
|------|-----------|
| Browse the story gallery | `story gallery 0o5.png` |
| Story summary page | `story - summary screen.png` |
| Play a story | `story - play screen.png` |
| The scene editor | `story - scene editor.png` |
| Tree View | `story - tree view.png` |
| Gallery View | `story - image gallery.png` |
| Create a story (by hand) | `create new story - by hand.png` |
| Create a story with AI (image / scene / full story) | `create new story - with ai.png` |
| Job queue | `job queue - job summary.png`, `job queue - input output json.png` |
| Admin / settings | `admin - ai settings 1o2.png`, `admin - ai settings 2o2.png`, `admin - site settings 1o2.png`, `admin - site settings 2o2.png`, `admin - users.png`, `admin - users - byok.png` |

### Image embedding note

The walkthrough page is hand-written HTML, so screenshots use `<img>` tags with paths
relative to `docs/report/`, e.g. `<img src="screenshots/story - play screen.png">`.
`report.css` sets `img { max-width:100%; }` so wide screenshots scale down.

### Lightbox (applies to every image in the report)

**All images across the report must be enlargeable in a lightbox-style modal** (click to
view full size, click/Esc to close). This is the shared `lightbox.css` + `lightbox.js`
(vanilla JS) linked by every page — hand-written and pandoc alike. `lightbox.js` should
auto-wire **all content `<img>`s** on load (e.g. attach a click handler to each), so no
per-image markup is needed and pandoc-generated images are covered automatically.

---

## 4. Appendix Plan

Because the report is **multi-file**, appendices are simplest as **separate linked HTML
pages** — each big doc converts to its own `.html` and the index links to it. This keeps
each page light and the index TOC clean.

Inventory (note: the v2 plan lives in the **v1** folder, and v7's file is `plan-v7.md`):

| # | Appendix | Source file |
|---|----------|-------------|
| A | Architecture | `docs/architecture/architecture.md` (519 lines) |
| B | Implementation Plan v1 | `_archive/implementation/v1/implementation-plan-v1.md` |
| C | Implementation Plan v2 | `_archive/implementation/v1/implementation-plan-v2.md` |
| D | Implementation Plan v3 | `_archive/implementation/v3/implementation-plan-v3.md` |
| E | Implementation Plan v4 | `_archive/implementation/v4/implementation-plan-v4.md` |
| F | Implementation Plan v5 | `_archive/implementation/v5/implementation-plan-v5.md` |
| G | Implementation Plan v6 | `_archive/implementation/v6/implementation-plan-v6.md` |
| H | Implementation Plan v7 | `_archive/implementation/v7/plan-v7.md` |
| I | AI Prompt Assembly (deep dive) | `docs/architecture/ai-prompt-assembly/ai-prompt-assembly.md` |
| J | Over 50 Stories so far | `docs/report/screenshots/story gallery 1o5.png` … `5o5.png` |

**Approach:** copy each source `.md` into `docs/report/appendix/`, run pandoc on each with
`report-template.html5` (so they get the shared header + `report.css` + lightbox), and
link them all from the index. Each appendix page gets its own TOC. Copying into
`appendix/` (rather than converting in place) keeps image-relative paths predictable and
leaves the originals untouched — note Appendix I's diagrams must be copied alongside it.

> **Appendix I — Mermaid → SVG.** `ai-prompt-assembly.md` contains Mermaid code blocks
> that pandoc won't render. Before converting, swap each Mermaid block for the matching
> pre-rendered image in the same folder — **prefer the SVGs** (sharper scaling):
> `ai-prompt-assembly-flowchart.svg` and `ai-prompt-assembly-sequence.svg` (PNG versions
> exist as a fallback). **Do not reuse** the existing `ai-prompt-assembly.html` — the
> user is removing it; convert fresh from the `.md`.

> **Appendix J — Over 50 Stories so far.** One page titled **"Over 50 Stories so far"**
> holding the gallery screenshots `story gallery 1o5.png` through `5o5.png` (five shots —
> **exclude `0o5.png`**) on the same page, in order. Same lightbox behaviour as
> everywhere else.

### The explainer video

**Decision: local HTML5 embed, no YouTube.** Copy
`AI_Assembly_Line__UI_to_API.mp4` (~45 MB) next to the report (e.g. `docs/report/`
or a `media/` subfolder) and present it with a `<video controls>` player. The report
stays fully offline; it just adds ~45 MB to the deliverable.

---

## 5. Task Checklist

**Shared scaffold (build once):**
- [ ] `report.css` — white-on-blue theme, Inter font, header-bar styling, `img` sizing.
- [ ] `header.js` — single-source header (logo + title + sub-titles), inline markup,
      injects into `#report-header`.
- [ ] `lightbox.css` + `lightbox.js` — auto-wire every content `<img>` to a modal.
- [ ] `report-template.html5` — pandoc template that loads the shared assets + the
      `#report-header` placeholder + script tags.

**Main pages (hand-written HTML), simple grade-10 style:**
- [ ] `index.html` — header + intro blurb + linked TOC to all pages & appendices.
- [ ] Project Overview — upgrade-from-previous framing + tech stack.
- [ ] Walkthrough — screenshots from the §3 table with simple captions.
- [ ] AI Process — embed `AI_Prompt_Assembly_Line_Anatomy.png`; the local
      `<video controls>` player for the MP4; plain-English prompt-assembly + job-queue.
- [ ] Dev Reflections — plans-vs-vibe-coding, `progress.md`, **and** the Claude setup
      (CLAUDE.md, permissions, closed feedback loop).

**Appendices:**
- [ ] Copy the 9 source `.md`s into `docs/report/appendix/`; pandoc each with
      `report-template.html5` (+ `--toc --toc-depth=2`).
- [ ] Appendix I: copy the SVGs alongside it and swap the Mermaid blocks for the SVGs
      (convert fresh — don't reuse the old HTML).
- [ ] Appendix J: hand-written "Over 50 Stories so far" page, shots `1o5`–`5o5`.

**Wire-up & finish:**
- [ ] Copy the MP4 into the report folder (or `media/`) so the embed resolves.
- [ ] `build-report.cmd` — convert every appendix page in one run.
- [ ] Review every page in a browser (header injects, images/video resolve, lightbox
      works, TOCs clean, index links work).
- [ ] Final proofread for a grade-10 / teacher audience.

---

## 6. Resolved Decisions & Open Items

**Resolved:**
- Multi-page report; index file is **`index.html`**.
- **Build model:** main pages **hand-written HTML**; appendices **pandoc** (via
  `report-template.html5`). Shared `report.css` + `header.js` + lightbox across both.
- **Header is single-source** in `header.js` (inline markup, injected into every page;
  edit once, no rebuild).
- **Match `proposal.html` fonts** (Inter). **White-on-blue theme.**
- **Header bar** on every page: logo (`images/app/logo_square.png`, left-aligned) +
  title **"CYOA Maker With AI"** + sub-title 1 "Webtech 10/11 / Term 3 Project" +
  sub-title 2 "By Evan Prael".
- Explainer video: **local HTML5 `<video>` embed**, no YouTube.
- Appendix I diagrams: **SVG**; convert fresh (don't reuse the old HTML).
- Appendix J: gallery shots **`1o5`–`5o5`** only (exclude `0o5`).
- Every image is **lightbox-enlargeable**.

All inputs are confirmed — ready to build the pages.
