# Phase 17 — Admin Settings Table & Config Migration

**Environment:** Local XAMPP at `http://localhost/projects/cyoa_ai`

---

### Setup

Before testing, confirm the migration has been applied:
- Run the SQL in `.claude/migration_v3.sql` against your local database (the CREATE TABLE and INSERT for `cyoa_ai_settings`)
- Confirm `settings.php` exists in the project root
- Have a browser session where you are logged in as **admin**
- Have a second browser session (or incognito) where you are logged in as a **regular user** (non-admin)

---

### 17.1 — Database Schema

#### Table exists with correct structure

- [ ] Open phpMyAdmin (or run a query tool) and confirm the table `cyoa_ai_settings` exists
- [ ] Confirm it has exactly three columns: `setting_key VARCHAR(64)`, `setting_value TEXT NULL`, `updated_at DATETIME`
- [ ] Confirm `setting_key` is the PRIMARY KEY
- [ ] Confirm `updated_at` auto-updates on row change (`ON UPDATE CURRENT_TIMESTAMP`)

#### Seed data is present

- [ ] Run `SELECT * FROM cyoa_ai_settings ORDER BY setting_key;` and confirm all 10 rows exist:
  - `ai_enabled` = `1`
  - `ai_job_timeout_seconds` = `300`
  - `ai_max_pending_per_user` = `5`
  - `anthropic_api_key` = *(your key)*
  - `anthropic_model` = `claude-sonnet-4-6`
  - `app_title` = `Choose Your Own Adventure!`
  - `openai_api_key` = *(your key)*
  - `openai_image_model` = `gpt-image-2`
  - `openai_image_quality` = `medium`
  - `scene_thumb_size` = `200`
- [ ] Confirm there are no extra rows beyond these 10

---

### 17.2 — DB Helper Functions

#### db_get_all_settings()

- [ ] Add a temporary `var_dump(db_get_all_settings());` line to any page (e.g. `index.php` before the first `echo`) and load it in a browser
- [ ] Confirm the output is an associative array with all 10 keys
- [ ] Confirm each value is a string (not null, not array)
- [ ] Remove the temporary debug line

#### db_set_setting()

- [ ] In phpMyAdmin, note the current value of `setting_key = 'app_title'` (`Choose Your Own Adventure!`)
- [ ] Call `db_set_setting('app_title', 'Test Title')` via a temporary one-line script or the admin panel (task 17.5)
- [ ] Run `SELECT setting_value FROM cyoa_ai_settings WHERE setting_key = 'app_title';` — confirm it now reads `Test Title`
- [ ] Confirm `updated_at` on that row changed to the current timestamp
- [ ] Call `db_set_setting('app_title', 'Choose Your Own Adventure!')` to restore the original value
- [ ] Confirm the row is updated (not duplicated) — still exactly 10 rows total

---

### 17.3 — Settings Bootstrap (settings.php)

#### app_setting() returns DB value

- [ ] Add temporary debug output: `echo app_setting('app_title');` on any page after `require_once 'settings.php'`
- [ ] Load the page — confirm it prints `Choose Your Own Adventure!`
- [ ] Directly update the DB row: `UPDATE cyoa_ai_settings SET setting_value = 'TEMP' WHERE setting_key = 'app_title';`
- [ ] Reload the page — confirm it now prints `TEMP` (fresh DB value, not stale)
- [ ] Restore: `UPDATE cyoa_ai_settings SET setting_value = 'Choose Your Own Adventure!' WHERE setting_key = 'app_title';`
- [ ] Remove the debug output

#### app_setting() falls back to SETTING_DEFAULTS

- [ ] Temporarily delete a non-critical row: `DELETE FROM cyoa_ai_settings WHERE setting_key = 'scene_thumb_size';`
- [ ] Add debug: `var_dump(app_setting('scene_thumb_size'));`
- [ ] Confirm it returns `string(3) "200"` (the default from `SETTING_DEFAULTS`, not `null`)
- [ ] Restore the row: `INSERT INTO cyoa_ai_settings (setting_key, setting_value) VALUES ('scene_thumb_size', '200');`
- [ ] Remove debug output

#### app_setting() returns null for unknown key with no default

- [ ] Add debug: `var_dump(app_setting('nonexistent_key'));`
- [ ] Confirm output is `NULL`
- [ ] Remove debug output

#### app_setting() returns null for API keys when not in SETTING_DEFAULTS

- [ ] Temporarily set `anthropic_api_key` to empty string in the DB
- [ ] Confirm `app_setting('anthropic_api_key')` returns `''` (empty string from DB), not null — the key exists in DB, so the DB value takes precedence
- [ ] Restore the key value via the admin panel (task 17.5) or direct SQL

---

### 17.3a — Code Migration Sweep

#### No old constants remain in active code files

- [ ] Search the codebase for `ANTHROPIC_API_KEY` — confirm zero occurrences in `.php` files (other than `config.php` where it no longer exists)
- [ ] Search for `OPENAI_API_KEY` — confirm zero occurrences
- [ ] Search for `ANTHROPIC_MODEL` — confirm zero occurrences
- [ ] Search for `OPENAI_IMAGE_MODEL` — confirm zero occurrences
- [ ] Search for `OPENAI_IMAGE_QUALITY` — confirm zero occurrences
- [ ] Search for `SCENE_THUMB_SIZE` — confirm zero occurrences
- [ ] Search for `AI_ENABLED` — confirm zero occurrences (not counting any CSS class names that happen to contain these letters)
- [ ] Search for `AI_JOB_TIMEOUT_SECONDS` — confirm zero occurrences
- [ ] Search for `AI_MAX_PENDING_PER_USER` — confirm zero occurrences

#### All pages load without PHP errors

- [ ] Load `index.php` — no PHP warnings or undefined constant errors
- [ ] Load `editor.php` — no errors
- [ ] Load `account.php` as admin — no errors
- [ ] Load `play.php` for any story — no errors
- [ ] Load `job_queue.php` — no errors
- [ ] Check the PHP error log (`xampp/apache/logs/error.log`) — no new entries since migration

---

### 17.4 — config.php Cleanup

#### Migrated constants are gone

- [ ] Open `config.php` and confirm none of these lines exist:
  - `define('ANTHROPIC_API_KEY', ...)`
  - `define('OPENAI_API_KEY', ...)`
  - `define('ANTHROPIC_MODEL', ...)`
  - `define('OPENAI_IMAGE_MODEL', ...)`
  - `define('OPENAI_IMAGE_QUALITY', ...)`
  - `define('SCENE_THUMB_SIZE', ...)`
  - `define('AI_ENABLED', ...)`
  - `define('AI_JOB_TIMEOUT_SECONDS', ...)`
  - `define('AI_MAX_PENDING_PER_USER', ...)`

#### Required constants are still present

- [ ] Confirm `config.php` still defines: `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `DB_PREFIX`
- [ ] Confirm SMTP / email settings are still present
- [ ] Confirm `APP_URL` is still defined
- [ ] Confirm `AI_IMAGE_PRICING` array is defined with all four models (`gpt-image-1-mini`, `gpt-image-1`, `gpt-image-1.5`, `gpt-image-2`) each with `low`, `medium`, and `high` keys
- [ ] Confirm `AI_COST_INPUT_PER_M` and `AI_COST_OUTPUT_PER_M` are defined

---

### 17.5 — Admin Panel: Site Settings

#### Section is visible to admin only

- [ ] Log in as **admin** and go to `account.php`
- [ ] Confirm a "Site Settings" section is visible on the page
- [ ] Log in as a **regular user** and go to `account.php`
- [ ] Confirm the "Site Settings" section is **not visible**

#### API Keys group

- [ ] As admin, locate the **API Keys** section
- [ ] Confirm the Anthropic API Key field is a password-style input (value is masked / not shown as plain text)
- [ ] Confirm the OpenAI API Key field is a password-style input
- [ ] Confirm both fields show a masked placeholder (e.g. `••••••••••••` or `sk-...xxxx`) rather than being blank
- [ ] Leave both fields empty and submit the form
- [ ] Confirm the API keys in the DB are **unchanged** (empty submission = no change)
- [ ] Enter a new test value in the Anthropic API Key field and submit
- [ ] Confirm the DB row for `anthropic_api_key` now holds the new value
- [ ] Restore the correct key value

#### AI Generation group

- [ ] Confirm a **Claude Model** dropdown with options: `claude-haiku-4-5`, `claude-sonnet-4-6`, `claude-opus-4-7`
- [ ] Confirm an **Image Model** dropdown with options: `gpt-image-1-mini`, `gpt-image-1`, `gpt-image-1.5`, `gpt-image-2`
- [ ] Confirm a **Default Image Quality** dropdown with options: `low`, `medium`, `high`
- [ ] Change Image Model to `gpt-image-1` and submit
- [ ] Confirm `SELECT setting_value FROM cyoa_ai_settings WHERE setting_key = 'openai_image_model';` returns `gpt-image-1`
- [ ] Reload `account.php` — confirm the Image Model dropdown still shows `gpt-image-1` (form reflects saved value)
- [ ] Change it back to `gpt-image-2` and save

#### Job Queue group

- [ ] Confirm **AI Processing Enabled** is a checkbox, currently checked
- [ ] Confirm **Job Timeout (seconds)** is a number input showing `300`
- [ ] Confirm **Max Pending Jobs Per User** is a number input showing `5`
- [ ] Uncheck AI Processing Enabled and submit
- [ ] Confirm `ai_enabled` in DB is now `0`
- [ ] Re-check and re-save — confirm DB is back to `1`

#### Appearance group

- [ ] Confirm **Site Title** text input shows `Choose Your Own Adventure!`
- [ ] Confirm **Scene Thumbnail Size (px)** number input shows `200`
- [ ] Change Site Title to `My Adventure App` and submit
- [ ] Confirm DB row updated
- [ ] Reload `account.php` — confirm text field still shows `My Adventure App`
- [ ] Restore to `Choose Your Own Adventure!` and save

#### Flash message on save

- [ ] Submit the Site Settings form with any change
- [ ] Confirm a success flash message appears after the redirect (e.g. "Settings saved")
- [ ] Confirm the page redirected back to `account.php` (POST-Redirect-GET pattern)

---

### Regression

- [ ] Queue a new AI job (scene or image) — confirm it runs correctly using settings from the DB (not the old constants)
- [ ] Confirm the existing job queue page loads and shows jobs correctly
- [ ] Confirm the play page loads and works for a published story
- [ ] Confirm login, logout, and registration pages load without errors
- [ ] Confirm the story gallery (`index.php`) loads without errors
- [ ] Confirm email features work (password reset sends an email if tested)
