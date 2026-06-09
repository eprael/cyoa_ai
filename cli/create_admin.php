<?php
/**
 * Create / promote an admin user — CLI bootstrap
 *
 * A fresh database has no users, and MAIN_ADMIN (config.php) does NOT grant admin
 * rights — admin is the `isAdmin` column. Use this once after install to create
 * your first administrator (or to promote an account you registered via the UI).
 *
 * USAGE
 *   # Create a new admin:
 *   php cli/create_admin.php --email=you@example.com --password="ChangeMe123!" [--first=Admin --last=User]
 *
 *   # Promote an existing (already-registered) account to admin:
 *   php cli/create_admin.php --promote --email=you@example.com
 *
 * Passwords are stored as bcrypt hashes (password_hash), so the value you pass is
 * the login password. Change it in the app after first login.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_functions.php';

function out(string $m): void { echo $m . PHP_EOL; }
function die_err(string $m): void { fwrite(STDERR, "ERROR: $m" . PHP_EOL); exit(1); }

// ── Parse args ───────────────────────────────────────────────────────────────
$email = ''; $password = ''; $first = 'Admin'; $last = 'User'; $promote = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--promote')                            { $promote = true; }
    elseif (preg_match('/^--email=(.+)$/',    $arg, $m)) { $email    = trim($m[1]); }
    elseif (preg_match('/^--password=(.+)$/', $arg, $m)) { $password = $m[1]; }
    elseif (preg_match('/^--first=(.+)$/',    $arg, $m)) { $first    = trim($m[1]); }
    elseif (preg_match('/^--last=(.+)$/',     $arg, $m)) { $last     = trim($m[1]); }
    else { die_err("Unrecognized argument: $arg"); }
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die_err("A valid --email is required.\nUsage: php cli/create_admin.php --email=you@example.com --password=... [--first= --last=]");
}

// ── Promote an existing user ─────────────────────────────────────────────────
if ($promote) {
    $user = get_user_by_email($email);
    if (!$user) die_err("No user with email '$email'. Register first, or create one without --promote.");

    // Update only when not already admin, so affected_rows tells us which happened.
    $conn = db_connect();
    $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "users SET isAdmin = 1 WHERE email = ? AND isAdmin <> 1");
    $stmt->bind_param("s", $email);
    $ok      = $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    $conn->close();
    if (!$ok) die_err("Failed to promote '$email'.");
    out($changed > 0
        ? "Promoted '$email' (user #{$user['userID']}) to admin. Log in and you'll have the admin panel."
        : "'$email' (user #{$user['userID']}) is already an admin. Nothing to do.");
    exit(0);
}

// ── Create a brand-new admin ─────────────────────────────────────────────────
if ($password === '') {
    die_err("--password is required when creating a new admin (or use --promote for an existing account).");
}
if (strlen($password) < 8) {
    die_err("Choose a password of at least 8 characters.");
}

$result = admin_create_user($first, $last, $email, $password, 1);

if ($result === 'email_taken') {
    die_err("An account with '$email' already exists. To make it an admin instead, run:\n  php cli/create_admin.php --promote --email=" . escapeshellarg($email));
}
if ($result === false) {
    die_err("Failed to create the admin user (database error).");
}

out("Created admin '$first $last' <$email> (user #$result).");
out("Log in at " . (defined('APP_URL') ? APP_URL . '/login.php' : 'login.php') . " and change the password from your account page.");
