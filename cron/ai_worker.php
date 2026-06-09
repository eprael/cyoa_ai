<?php
/**
 * AI Worker — Single Job Processor
 *
 * Spawned by ai_dispatcher.php to handle one AI job.
 * Receives the job_id as a CLI argument.
 *
 * Usage:
 *   php ai_worker.php <job_id>
 */

// Ensure CLI-only execution
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

// Validate job_id argument
if (!isset($argv[1]) || !is_numeric($argv[1])) {
    fwrite(STDERR, "Usage: php ai_worker.php <job_id>\n");
    exit(1);
}

$jobID = (int)$argv[1];

// Log to a per-job file so output is visible regardless of how the process was spawned
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/job_' . $jobID . '.log';
function worker_log(string $msg): void {
    global $logFile;
    $line = date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_functions.php';
require_once __DIR__ . '/../settings.php';

date_default_timezone_set(APP_TIMEZONE);

// Load handler and apply files
require_once __DIR__ . '/ai_helpers.php';
require_once __DIR__ . '/ai_image_handler.php';
require_once __DIR__ . '/ai_scene_handler.php';
require_once __DIR__ . '/ai_story_handler.php';
require_once __DIR__ . '/ai_apply.php';

// Fetch the job (should already be marked 'running' by dispatcher)
$job = get_ai_job($jobID);

if (!$job) {
    worker_log("ERROR: Job #$jobID not found");
    exit(1);
}

if ($job['status'] !== 'running') {
    worker_log("ERROR: Job #$jobID is not in 'running' status (current: {$job['status']})");
    exit(1);
}

$input = json_decode($job['input_json'], true);
if ($input === null) {
    fail_ai_job($jobID, 'Invalid input_json: ' . json_last_error_msg());
    worker_log("ERROR: Invalid input_json for job #$jobID");
    exit(1);
}

worker_log("Worker started for job #$jobID (type: {$job['job_type']})");
$workerStart = microtime(true);

try {
    switch ($job['job_type']) {
        case 'image':
            $result = process_image_job($job, $input);
            break;

        case 'scene':
            $result = process_scene_job($job, $input);
            break;

        case 'full_story':
            $result = process_full_story_job($job, $input);
            break;

        case 'story':
            $result = process_create_story_job($job, $input);
            break;

        default:
            throw new Exception("Unknown job type: {$job['job_type']}");
    }

    // Apply first so child jobs are in the DB before the parent is marked complete;
    // this prevents the UI from briefly showing the parent as 'completed' while
    // image children are still pending.
    $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE);
    $job['result_json'] = $resultJson;
    apply_ai_job($job);

    // Mark parent complete after children have been queued
    complete_ai_job($jobID, $resultJson);

    $elapsed = round(microtime(true) - $workerStart, 1);
    worker_log("Job #$jobID completed successfully in {$elapsed}s");

    // If this is a child job, check whether all siblings are now done (this also
    // triggers auto-publish once the whole image family has succeeded). Note: a
    // create-story job with images disabled has no children and is intentionally
    // never auto-published — an image-less story isn't suited to the gallery/player
    // presentation yet, so it stays a draft.
    if (!empty($job['parent_job_id'])) {
        check_and_finalize_parent((int)$job['parent_job_id']);
    }

} catch (\Throwable $e) {
    // Catch Throwable (not just Exception) so PHP errors (TypeError, etc.) also mark
    // the job failed with a reason, instead of crashing the worker and leaving the
    // job stuck in 'running' until the timeout sweep.
    $elapsed = round(microtime(true) - $workerStart, 1);
    fail_ai_job($jobID, $e->getMessage());
    worker_log("ERROR: Job #$jobID failed after {$elapsed}s: " . $e->getMessage());

    // If this is a child job, check whether all siblings are now done
    if (!empty($job['parent_job_id'])) {
        check_and_finalize_parent((int)$job['parent_job_id']);
    }
    exit(1);
}


// ==========================================
// JOB HANDLER STUBS — replaced as phases are built
// process_image_job() lives in ai_image_handler.php (Phase 4 ✓)
// process_scene_job() lives in ai_scene_handler.php (Phase 5 ✓)
// ==========================================

// process_full_story_job() lives in ai_story_handler.php (Phase 6 ✓)
