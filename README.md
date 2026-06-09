<div align="center">

<img src="images/app/logo_square.png" alt="CYOA Maker logo" width="120">

# CYOA Maker — With AI

**Build, share, and play branching choose-your-own-adventure stories — with AI that can generate images, single scenes, or an entire story.**

</div>

---

CYOA Maker is a PHP web app for writing interactive, branching stories. Readers play through one
scene at a time, picking choices that lead to different paths and endings. Authors can write every
word themselves, or hand any part of the job to the AI — a cover image, one scene, or a complete
multi-scene story with artwork.

This repository is the **AI & social upgrade** of an earlier term-2 project, adding social features
(ratings, comments, favourites, view tracking), an asynchronous AI job queue, a data-driven theme
engine, and a full admin panel.

> 📖 **A full illustrated project report lives in [`docs/report/`](docs/report/index.html)** — start
> there for a screen-by-screen tour, the AI pipeline, and an explainer video.

## Features

- **Three tiers of AI generation** — images (OpenAI `gpt-image`), single scenes, and full multi-scene
  stories with cohesive art (Anthropic Claude).
- **Asynchronous job queue** — AI work runs through a cron-driven dispatcher/worker so the page never
  blocks; the header badge updates as jobs finish.
- **Social layer** — 1–5★ ratings, comments, favourites, and view counts on every story.
- **Story editor** — scene-by-scene editing, a branching Tree View, a per-story image Gallery, plus
  clone/publish and a soft-delete trash with restore.
- **Data-driven theme engine** — per-story colour palettes and curated Google-Font pairings; the
  visual theme is purely cosmetic and never sent to the AI (the semantic hint is the story's *genre*).
- **Accounts & BYOK** — bcrypt-hashed logins, profile management, and optional *bring-your-own-key*
  so heavy users bill AI usage to their own Claude/OpenAI keys.
- **Admin panel** — runtime AI settings (models, quality, limits), editable content lists, content
  guardrails, user management, and maintenance cleanup.
- **Email** — welcome and password-reset mail via PHPMailer over Gmail SMTP.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.3 (procedural, no framework) |
| Database | MySQL / MariaDB via `mysqli` (prepared statements) |
| Frontend | Vanilla HTML / CSS / JS (no framework) |
| Email | [PHPMailer](https://github.com/PHPMailer/PHPMailer) (SMTP) |
| AI — text | Anthropic Claude API |
| AI — images | OpenAI image API (`gpt-image`) |
| Scheduling | Cron (Linux/Virtualmin) or Task Scheduler (Windows) |

## Screenshots

| Story player | Scene editor |
|---|---|
| ![Playing a story](docs/report/screenshots/story%20-%20play%20screen.png) | ![The scene editor](docs/report/screenshots/story%20-%20scene%20editor.png) |

| Create with AI | AI job queue |
|---|---|
| ![Create a story with AI](docs/report/screenshots/create%20new%20story%20-%20with%20ai.png) | ![The job queue](docs/report/screenshots/job%20queue%20-%20job%20summary.png) |

## Getting Started

> Full walkthrough: **[`docs/installation/installation.md`](docs/installation/installation.md)**

**Requirements:** PHP 8.3+, MySQL/MariaDB, a web server (Apache via XAMPP works for local dev), and
the ability to run a recurring cron / scheduled task for the AI worker.

1. **Get the code** into your web root (e.g. `htdocs/projects/cyoa_ai`).
2. **Create a database**, then import the schema:
   [`docs/architecture/db/cyoa_ai_db_schema.sql`](docs/architecture/db/cyoa_ai_db_schema.sql).
3. **Configure** — copy `config.sample.php` to `config.php` and fill in your DB and SMTP values.
   `config.php` holds secrets and is git-ignored; never commit it.
   ```bash
   cp config.sample.php config.php
   ```
4. **Create your first admin** account:
   ```bash
   php cli/create_admin.php
   ```
5. **Add AI API keys** in the admin Site Settings page (stored in the DB, not in `config.php`).
6. **Schedule the AI worker** so queued jobs get processed — see
   [`cron/cron_setup.md`](cron/cron_setup.md).

## How the AI Pipeline Works

1. A user clicks **Generate**; PHP writes a row to `cyoa_ai_jobs` (status `pending`) and returns at once.
2. The **dispatcher** (`cron/ai_dispatcher.php`), run by cron, claims pending jobs and spawns a
   **worker** for each.
3. The **worker** (`cron/ai_worker.php`) routes the job to the right handler (image / scene / story),
   calls the API, applies the result, and marks the job `completed`.
4. The browser polls `api_jobs.php` and updates the header badge when work finishes.

Any AI-applied change sets the story's status to **draft**; AI-created stories auto-publish only when
the publish flag is set, images were included, and every job (parent + image children) succeeds.

## Repository Layout

```
cyoa_ai/
├── *.php                 # Pages (index, summary, editor, play, account, settings_*) + AJAX APIs
├── db_functions.php      # All database access (prepared statements)
├── config.sample.php     # Config template → copy to config.php (git-ignored)
├── cli/                  # Command-line tools (create_admin, import/export, batch create)
├── cron/                 # AI dispatcher, worker, handlers, and daily maintenance
├── data/                 # Data-driven content (premises, audiences, themes, fonts) as JSON
├── prompts/              # AI prompt templates (.txt)
├── styles/ · themes/     # CSS and theme presets
├── images/               # App art + generated story/profile images (contents git-ignored)
├── phpmailer/            # Bundled PHPMailer
└── docs/                 # Report, installation guide, architecture, API, and proposal docs
```

## Documentation

- **[Project report](docs/report/index.html)** — illustrated tour, AI process, reflections, appendices
- **[Installation guide](docs/installation/installation.md)** — full local/hosted setup
- **[Architecture](docs/architecture/architecture.md)** — technical design of the AI & social features
- **[AI prompts](docs/architecture/ai-prompts.md)** — prompt construction per generation level
- **[Cron setup](cron/cron_setup.md)** — scheduling the dispatcher on Linux or Windows
- **[Docs index](docs/README.md)** — map of everything under `docs/`

## Acknowledgements

Built with the help of **Claude Code** (Anthropic). In-app generation uses the **Claude API** and
**OpenAI Images**; the explainer video and prompt-assembly infographic were made with **NotebookLM**.
Also uses **PHPMailer**, **Google Fonts**, and **Font Awesome**.

## License

Released under the [MIT License](LICENSE).

---

*Grade 12A Web Technologies — Term 3 project, 2025–2026.*
