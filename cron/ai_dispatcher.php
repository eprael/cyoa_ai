<?php
/**
 * AI Job Dispatcher — Cron Entry Point
 *
 * Runs several dispatch passes per invocation (see $DISPATCH_PASSES /
 * $DISPATCH_INTERVAL below), so jobs are still picked up quickly even when the
 * host's cron can only fire once a minute. Each pass:
 * 1. Times out any stale running jobs
 * 2. Claims pending jobs atomically (with image-concurrency limits)
 * 3. Spawns a separate ai_worker.php process for each claimed job
 * Workers run independently, so a pass never waits on them.
 *
 * Usage (cron / Task Scheduler):
 *   php /path/to/cron/ai_dispatcher.php
 */

// Ensure CLI-only execution
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_functions.php';
require_once __DIR__ . '/../settings.php';

date_default_timezone_set(APP_TIMEZONE);

// Log to a persistent file so Task Scheduler runs are visible
$dispatcherLog = __DIR__ . '/logs/dispatcher.log';
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}
function dlog(string $msg): void {
    global $dispatcherLog;
    $line = date('Y-m-d H:i:s') . '  ' . $msg . PHP_EOL;
    file_put_contents($dispatcherLog, $line, FILE_APPEND);
    echo $line;
}

// Check if AI is enabled
if (!(bool)(int) app_setting('ai_enabled')) {
    exit(0);
}

// ── Dispatch cadence ─────────────────────────────────────────────────────────
// Many shared hosts only run cron once a minute. To react sooner, do several
// dispatch passes per invocation, spaced a few seconds apart. Keep
// (DISPATCH_PASSES - 1) * DISPATCH_INTERVAL comfortably under your cron period
// (e.g. 3 passes × 20s = 40s of waiting, safely within a 60s cron window) so a
// run never bleeds into the next cron invocation.
$DISPATCH_PASSES   = 4;    // number of dispatch passes per cron run
$DISPATCH_INTERVAL = 15;   // seconds to wait between passes

$timeoutSeconds      = (int) app_setting('ai_job_timeout_seconds');
$maxConcurrentImages = max(1, (int) app_setting('ai_max_concurrent_image_jobs'));
$maxJobsPerRun       = 10;

$workerScript = __DIR__ . '/ai_worker.php';
$phpBinary    = PHP_BINARY;
$logDir       = __DIR__ . '/logs';

// Run daily maintenance once per calendar day (Phase 30) — once per invocation,
// before the dispatch passes so cleanup still runs on idle days. Launched
// asynchronously so a long cleanup never blocks dispatch. The per-day guard is
// the existence of today's maintenance log; maintenance.php has its own <1h age
// guard to absorb the race if the dispatcher fires twice before that log is written.
$maintenanceLog = __DIR__ . '/logs/maintenance_' . date('Ymd') . '.log';
if (!file_exists($maintenanceLog)) {
    $mcmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/maintenance.php');
    dlog("Launching daily maintenance — cmd: $mcmd");
    if (PHP_OS_FAMILY === 'Windows') {
        $mproc = proc_open($mcmd, [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $maintenanceLog, 'a'],
            2 => ['file', $maintenanceLog, 'a'],
        ], $mpipes);
        dlog($mproc === false
            ? "  ERROR: proc_open returned false for maintenance"
            : "  maintenance proc_open OK — PID: " . (proc_get_status($mproc)['pid'] ?? 'unknown'));
    } else {
        exec("$mcmd > " . escapeshellarg($maintenanceLog) . " 2>&1 &");
        dlog("  maintenance exec launched");
    }
}

// ── Dispatch passes ──────────────────────────────────────────────────────────
for ($pass = 1; $pass <= $DISPATCH_PASSES; $pass++) {
    if ($pass > 1) {
        sleep($DISPATCH_INTERVAL);
    }
    dlog("── Dispatch pass {$pass}/{$DISPATCH_PASSES} ──");

    // Step 1: Timeout stale jobs
    $timedOut = timeout_stale_jobs($timeoutSeconds);
    if ($timedOut > 0) {
        dlog("Timed out $timedOut stale job(s) (timeout=${timeoutSeconds}s)");
    }

    // Step 2: Claim pending jobs with per-type concurrency control.
    $runningImages = db_count_running_image_jobs();
    $imageSlots    = max(0, $maxConcurrentImages - $runningImages);

    dlog("Image slots: $imageSlots/$maxConcurrentImages available ($runningImages currently running)");

    $nonImageJobs = claim_pending_jobs_excluding_type($maxJobsPerRun, 'image');
    $imageJobs    = claim_pending_jobs_of_type($imageSlots, 'image');
    $jobs         = array_merge($nonImageJobs, $imageJobs);

    dlog("Claimed: " . count($nonImageJobs) . " non-image, " . count($imageJobs) . " image job(s)");

    if (empty($jobs)) {
        dlog("No jobs to dispatch this pass");
        continue;
    }

    // Step 3: Spawn a worker process for each job
    dlog("Dispatching " . count($jobs) . " job(s)");

    foreach ($jobs as $job) {
        $jobID   = (int)$job['job_id'];
        $logFile = $logDir . '/job_' . $jobID . '.log';
        $cmd     = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerScript) . ' ' . $jobID;

        dlog("  Spawning job #$jobID ({$job['job_type']}) — cmd: $cmd");

        if (PHP_OS_FAMILY === 'Windows') {
            // Use proc_open so the worker inherits the full environment (including mapped drives).
            $descriptors = [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logFile, 'w'],
                2 => ['file', $logFile, 'a'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes);
            if ($proc === false) {
                dlog("  ERROR: proc_open returned false for job #$jobID");
                fail_ai_job($jobID, 'Dispatcher failed to spawn worker process.');
                continue;
            }
            $procStatus = proc_get_status($proc);
            dlog("  proc_open OK for job #$jobID — PID: " . ($procStatus['pid'] ?? 'unknown'));
        } else {
            exec("$cmd > " . escapeshellarg($logFile) . " 2>&1 &");
            dlog("  exec launched for job #$jobID");
        }
    }
}

dlog("Dispatcher done");
