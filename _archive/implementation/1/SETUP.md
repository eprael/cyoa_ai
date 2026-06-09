# CYOA Maker — Deployment Guide

## Prerequisites

CYOA Maker requires a standard PHP web server environment:

- **PHP 7.4 or higher** — tested on PHP 8.x
- **MySQL or MariaDB** — any standard shared hosting or local XAMPP stack
- **mysqli extension** — enabled by default in most PHP installations
- **File write permissions** — the web server needs write access to the `images/` directory for uploaded photos

> For local development, **XAMPP** or **Laragon** work well out of the box — both include MySQL and phpMyAdmin pre-installed.

---

## Setting Up from a ZIP File

1. Extract the ZIP file into your web server's document root (e.g., `htdocs/cyoa_maker/` for XAMPP).
2. Open `config.php` and update the settings (see [Important Configuration](#important-configuration) below).
3. Ensure the `images/` folder is writable by the web server (`chmod 755` on Linux/macOS, or check folder permissions on Windows).
4. Create a MySQL database and import `_setup/cyoa_db_schema_and_data.sql` using phpMyAdmin (or the MySQL command line) to create all five tables.
5. Navigate to the app in your browser (e.g., `http://localhost/cyoa_maker/`).
6. Set the `MAIN_ADMIN` email in `config.php` before registering so that account is automatically treated as super-admin.

---

## Important Configuration

All key settings live in `config.php`:

| Setting | What It Does |
|---|---|
| `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | MySQL connection credentials for the database server |
| `APP_URL` | The base URL of the app (e.g., `http://localhost/cyoa_maker`) — used for email links |
| `MAIN_ADMIN` | Email address of the protected super-admin account (cannot be demoted or deleted) |
| SMTP settings | Gmail address and app password used for sending emails via PHPMailer |
