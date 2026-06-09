<?php
/**
 * Application Configuration — SAMPLE / TEMPLATE
 *
 * Copy this file to `config.php` and fill in your own values.
 *   - config.php holds secrets (DB password, SMTP app password) → keep it OUT of version control.
 *   - config.sample.php (this file) is safe to commit — it has placeholders only.
 *
 * See docs/installation/installation.md for the full setup walkthrough.
 */

// ── MySQL database connection ────────────────────────────────────────────────
define('DB_HOST', 'localhost');        // e.g. 'localhost' or '127.0.0.1' (or a remote host/IP)
define('DB_USER', 'your_db_user');     // your MySQL username
define('DB_PASSWORD', 'your_db_password'); // your MySQL password
define('DB_NAME', 'your_db_name');     // the database you created for the app

// Table name prefix — every table is DB_PREFIX . "name" (e.g. cyoa_ai_users).
define('DB_PREFIX', 'cyoa_ai_');

// ── Email (SMTP) — used for password resets and admin notifications ──────────
// For Gmail, use an App Password (not your normal password) with 2FA enabled.
define('MAIL_HOST', 'smtp.gmail.com');         // SMTP server hostname
define('MAIL_PORT', 587);                       // 587 for TLS, 465 for SSL
define('MAIL_USERNAME', 'you@example.com');     // SMTP username / email address
define('MAIL_PASSWORD', 'your_app_password');   // SMTP password or app password
define('MAIL_FROM_ADDRESS', 'you@example.com'); // Sender email address
define('MAIL_FROM_NAME', 'CYOA Maker');         // Sender display name
define('MAIL_ENCRYPTION', 'tls');               // 'tls' or 'ssl'

// ── Application ──────────────────────────────────────────────────────────────
// Public base URL of the app (no trailing slash). Used to build reset links etc.
define('APP_URL', 'http://localhost/projects/cyoa_ai');

// Email address BCC'd on system mail + the default owner for CLI imports.
// NOTE: this does NOT grant admin rights — admin is the users.isAdmin column.
// Create your first admin with: php cli/create_admin.php (see the install guide).
define('MAIN_ADMIN', 'admin@example.com');

// Timezone for cron/dispatcher/worker log timestamps.
// Full list: https://www.php.net/manual/en/timezones.php
define('APP_TIMEZONE', 'America/Vancouver');

// ── AI cost reference data (not secret; tune to current provider pricing) ─────
// Per-image output cost at 1024×1024 (source: OpenAI pricing page).
define('AI_IMAGE_PRICING', [
    'gpt-image-1-mini' => ['low' => 0.005, 'medium' => 0.011, 'high' => 0.036],
    'gpt-image-1'      => ['low' => 0.011, 'medium' => 0.042, 'high' => 0.167],
    'gpt-image-1.5'    => ['low' => 0.009, 'medium' => 0.034, 'high' => 0.133],
    'gpt-image-2'      => ['low' => 0.006, 'medium' => 0.053, 'high' => 0.211],
]);

// Claude token rates (per 1M tokens) for the configured text model.
define('AI_COST_INPUT_PER_M',   3.00);
define('AI_COST_OUTPUT_PER_M', 15.00);

// ── Appearance presets (px) surfaced in Site Settings ────────────────────────
define('SCENE_THUMB_SIZES',     ['Small' => 140, 'Medium' => 200, 'Large' => 280]);
define('GALLERY_TILE_SIZES',    ['Small' => 160, 'Medium' => 220, 'Large' => 300]);
define('GALLERY_FILMSTRIP_SIZES', ['Small' => 56, 'Medium' => 72, 'Large' => 96]);
define('GALLERY_TILE_SPACING',  ['Tight' => 8,  'Normal' => 16, 'Roomy' => 28]);

// Editorial reference data (audiences, fonts, theme presets) lives in data/*.json,
// read via story_audiences() / play_fonts() / theme_presets() — not constants.
// AI API keys are NOT here — they are set in the admin UI (Site Settings) and
// stored in the cyoa_ai_settings table, or per-user (BYOK) on the account page.
