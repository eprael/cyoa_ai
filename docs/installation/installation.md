# CYOA Maker — Installation Guide

How to stand up the app from scratch on a fresh server (local XAMPP or shared hosting).

---

## 1. Requirements

| Need | Notes |
|---|---|
| **PHP 8.x** (8.3 recommended) | Extensions: `mysqli`, `curl`, `mbstring`, `json`, `openssl` (for SMTP TLS). No GD needed — images are saved, not processed. |
| **MySQL / MariaDB** | Any recent version. You'll create one database for the app. |
| **Web server** | Apache (XAMPP locally) or your host's stack, serving the project folder. |
| **Cron / Task Scheduler** | Required for AI jobs — a scheduled task runs the dispatcher (Step 8). |
| **SMTP account** | For password-reset email (e.g. a Gmail account with an App Password). |
| **AI API keys** *(optional)* | Anthropic (text) + OpenAI (images), if you want AI generation. Set later in the admin UI. |

---

## 2. Get the files in place

Put the project folder under your web root so it's served, e.g.
`…/public_html/projects/cyoa_ai`. Confirm you can browse to it (you'll see a login/redirect
before configuration — that's expected).

---

## 3. Create the database + import the schema

1. Create an empty database (any name; you'll put it in `config.php`):
   ```sql
   CREATE DATABASE cyoa_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```
2. Import the table structure. The canonical schema lives at
   **`docs/architecture/cyoa_ai_db_schema.sql`** (structure only — 12 `cyoa_ai_*` tables, indexes,
   foreign keys). Import it via phpMyAdmin (Import tab) or the CLI:
   ```bash
   mysql -u <user> -p <dbname> < docs/architecture/cyoa_ai_db_schema.sql
   ```

> No seed data is required: runtime settings fall back to sensible defaults
> (`SETTING_DEFAULTS` in `settings.php`), so the `cyoa_ai_settings` table can start empty — it fills
> in as you save settings in the admin UI. The only thing a fresh DB lacks is a **user** (Step 7).

---

## 4. Configure `config.php`

Copy the template and fill in your values:
```bash
cp config.sample.php config.php
```
Edit `config.php`:
- **Database:** `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` (leave `DB_PREFIX` as `cyoa_ai_`).
- **APP_URL:** the public base URL, no trailing slash (e.g. `https://yoursite.com/cyoa_ai`).
- **SMTP:** `MAIL_*` (for Gmail, enable 2FA and use an **App Password**, not your normal password).
- **MAIN_ADMIN:** an email BCC'd on system mail / used as the CLI-import default owner.
  *(This does not grant admin rights — see Step 7.)*
- **APP_TIMEZONE:** your timezone (affects log timestamps).

> 🔒 `config.php` holds secrets — keep it **out of version control**. The included `.gitignore`
> already excludes it; commit `config.sample.php` instead.

---

## 5. Make the upload/log folders writable

These directories must be writable by the web server (and by the cron user):
```
images/stories/     images/profiles/     cron/logs/
```
On Linux hosting: `chmod 755` (or as your host requires) and ensure correct ownership. On XAMPP
(Windows) this is usually fine by default. The app creates per-story subfolders under
`images/stories/` automatically.

---

## 6. Configure email (optional but recommended)

PHPMailer is bundled (`phpmailer/`) — no Composer needed. Password resets and notifications use the
`MAIL_*` values from Step 4. Test by using "Forgot password" after you have an account.

---

## 7. Create your first admin

A fresh database has **no users**, and `MAIN_ADMIN` does **not** grant admin rights (admin is the
`users.isAdmin` column). Use the bootstrap script:

```bash
# Create a new admin account:
php cli/create_admin.php --email=you@example.com --password="ChangeMe123!" --first=Admin --last=User
```

Or, if you already registered an account through the web UI (`register.php`), promote it:
```bash
php cli/create_admin.php --promote --email=you@example.com
```

Then log in at `APP_URL/login.php` and **change the password** from your account page.

---

## 8. Schedule the AI dispatcher (cron)

AI generation runs as background jobs. A scheduler must run **`cron/ai_dispatcher.php`** on an
interval. It self-throttles (multiple passes per run) and exits quickly, so once a minute is ideal.

**Linux (crontab):**
```cron
* * * * * /usr/bin/php /full/path/to/cyoa_ai/cron/ai_dispatcher.php >> /full/path/to/cyoa_ai/cron/logs/dispatcher.log 2>&1
```
(Use your host's PHP CLI path — `which php`. On Virtualmin/cPanel, add it as a Cron Job.)

**Windows (Task Scheduler, for XAMPP):** create a task that runs every minute:
- Program: `C:\xampp\php\php.exe`
- Arguments: `"C:\…\cyoa_ai\cron\ai_dispatcher.php"`

> If jobs sit at **pending** forever, the dispatcher isn't running — this is the #1 setup issue.
> Check `cron/logs/dispatcher.log` for activity.

---

## 9. Turn on AI + enter API keys (admin UI)

Log in as your admin and open **Site Settings**:
- Enter the **Anthropic** and **OpenAI** API keys (stored in `cyoa_ai_settings`, server-side only).
- Confirm **AI enabled** is on, pick the text/image models, set limits as desired.

*(Users can optionally bring their own keys — BYOK — on their account page.)*

---

## 10. Verify the install

1. Register a normal user (or use your admin) and create a story manually — confirms DB + uploads.
2. Create a story **with AI** and watch the **Job Queue** badge; the job should move
   pending → running → completed. Check `cron/logs/job_<id>.log` if it fails.
3. Test "Forgot password" to confirm SMTP.

---

## 11. Security & housekeeping

- **Never commit `config.php`** — it contains DB + SMTP secrets. (Covered by `.gitignore`.)
- If a real secret has ever been committed or shared (e.g. a Gmail App Password), **rotate it**.
- `docs/` and `_archive/` sit inside the web root, so they're URL-reachable if deployed. They're not
  needed at runtime — exclude them from host uploads, or block them at the web-server level.
- Keep `.claude/settings.local.json` out of version control (also in `.gitignore`).

---

## Quick reference — setup file map

| File | Role |
|---|---|
| `config.sample.php` → `config.php` | Site config (DB, SMTP, URLs); copy + fill in |
| `docs/architecture/cyoa_ai_db_schema.sql` | Table structure to import |
| `cli/create_admin.php` | Create/promote the first admin |
| `cron/ai_dispatcher.php` | The script your scheduler runs |
| `settings.php` (`SETTING_DEFAULTS`) | Defaults so the settings table can start empty |
| `.gitignore` | Keeps secrets/runtime data out of version control |

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| White page / "DB connection failed" | Wrong `DB_*` in `config.php`, or DB not created / schema not imported. |
| Login works but no admin panel | Account isn't admin — run `cli/create_admin.php --promote --email=…`. |
| AI jobs stuck **pending** | Dispatcher not scheduled / not running — see Step 8 and `cron/logs/dispatcher.log`. |
| AI job **failed** immediately | Missing/invalid API key, or AI disabled — check Site Settings + `cron/logs/job_<id>.log`. |
| Images don't save | `images/stories/` not writable by the web/cron user. |
| No reset emails | SMTP creds wrong, or host blocks port 587/465 — verify `MAIL_*`. |
| Settings page shows blanks | Normal on a fresh DB — values come from defaults until you save them. |
