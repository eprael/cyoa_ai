<?php
/**
 * Maintenance — Daily Cleanup CLI (Phase 30)
 *
 * Purges trashed stories and old log files according to the admin-configured
 * retention windows (trash_retention, log_retention).
 *
 * Launched once per calendar day by ai_dispatcher.php, and on demand by the
 * admin "Empty Now" / "Delete Logs Now" buttons (which pass --force).
 *
 * Usage:
 *   php cron/maintenance.php [--force]
 *
 * Without --force, the run is skipped if today's log file was written less than
 * an hour ago (guards against the dispatcher firing it twice in quick succession).
 */

// CLI-only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$force = in_array('--force', $argv, true);

// Log path: cron/logs/maintenance_YYYYMMDD.log (this file lives in cron/)
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/maintenance_' . date('Ymd') . '.log';

// Age guard: skip if the log was touched within the last hour (unless forced).
if (!$force && file_exists($logFile) && filemtime($logFile) > time() - 3600) {
    exit(0);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_functions.php';
require_once __DIR__ . '/../settings.php';

date_default_timezone_set(APP_TIMEZONE);

function maint_log(string $msg): void {
    global $logFile;
    $line = date('Y-m-d H:i:s') . '  ' . $msg . PHP_EOL;
    if (LOG_FILE_ENABLED) file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// Establish the log file early so a near-simultaneous second launch trips the
// age guard above and exits instead of double-running.
maint_log('Maintenance run started' . ($force ? ' (forced)' : ''));

// ── Trash cleanup ──────────────────────────────────────────────
// db_purge_deleted_stories() also removes each story's image folder.
$trashInterval = retention_to_interval(app_setting('trash_retention'));
$purgedIds     = db_purge_deleted_stories($trashInterval);
maint_log('Trash: purged ' . count($purgedIds) . ' stor' . (count($purgedIds) === 1 ? 'y' : 'ies')
    . ' (retention=' . app_setting('trash_retention') . ')'
    . (empty($purgedIds) ? '' : ' [ids: ' . implode(', ', $purgedIds) . ']'));

// ── Log cleanup ────────────────────────────────────────────────
$logSeconds = match (app_setting('log_retention')) {
    '1day'  => 86400,
    '1week' => 604800,
    default => 2592000,  // 1month (30 days)
};
$cutoff  = time() - $logSeconds;
$deleted = 0;
foreach (glob($logDir . '/*.log') as $file) {
    // Never delete the log we're currently writing to.
    if ($file === $logFile) continue;
    if (is_file($file) && filemtime($file) < $cutoff) {
        if (@unlink($file)) $deleted++;
    }
}
maint_log('Logs: deleted ' . $deleted . ' file' . ($deleted === 1 ? '' : 's')
    . ' (retention=' . app_setting('log_retention') . ')');

maint_log('Maintenance run complete');
exit(0);
