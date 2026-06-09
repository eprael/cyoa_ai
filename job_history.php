<?php
/**
 * Job History (Phase 40) — paginated list of AI jobs created before today.
 * Admins see all users' jobs; regular users see only their own. Reuses the
 * shared job-table renderer (job_render.php) and the numbered pager
 * (pagination.php). The job queue (job_queue.php) shows today's jobs.
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';
require_once 'job_render.php';
require_once 'pagination.php';

// Must be logged in
if (!isset($_SESSION['userID'])) {
    header('Location: login.php');
    exit;
}

// Align PHP's "today" with the configured app timezone, so the history cutoff
// (everything before local midnight) lines up with job_queue.php's today filter.
date_default_timezone_set(APP_TIMEZONE);

$userID  = (int)$_SESSION['userID'];
$isAdmin = !empty($_SESSION['isAdmin']);

$beforeDate = date('Y-m-d 00:00:00');           // today's local midnight

// Pagination
$perPage = (int) app_setting('jobs_history_page_size');
if ($perPage < 1) $perPage = 25;
$totalJobs  = db_count_history_jobs($isAdmin ? null : $userID, $isAdmin, $beforeDate);
$totalPages = max(1, (int) ceil($totalJobs / $perPage));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1)           $page = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// Page of top-level parents + their child jobs (image chains)
$parents = db_get_history_jobs($isAdmin ? null : $userID, $isAdmin, $beforeDate, $perPage, $offset);
$parentIds = array_map(fn($j) => (int)$j['job_id'], $parents);
$children  = db_get_child_jobs($parentIds, $isAdmin);

$childrenByParent = [];
foreach ($children as $child) {
    $childrenByParent[(int)$child['parent_job_id']][] = $child;
}

$pagerHtml = render_pager($page, $totalPages, [], 'job_history.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job History — CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
    <link rel="stylesheet" href="styles/job_queue.css">
    <link rel="stylesheet" href="styles/pager.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <main class="container">

        <div class="queue-header">
            <h1>Job History</h1>
            <?php if ($isAdmin): ?>
                <span style="color:#888;font-size:0.9rem;">Showing all users' jobs (admin view)</span>
            <?php endif; ?>
        </div>

        <div class="queue-subnav">
            <a href="job_queue.php" class="btn-past-jobs">&larr; Back to Job Queue</a>
        </div>

        <?php if (empty($parents)): ?>
            <div class="queue-empty">
                <p>No past jobs.</p>
                <p style="font-size:0.9rem;color:#aaa;">Jobs older than today appear here once you've generated content on a previous day.</p>
            </div>
        <?php else: ?>
            <?php echo $pagerHtml; ?>
            <?php render_job_table($parents, $isAdmin, $userID, $childrenByParent); ?>
            <?php echo $pagerHtml; ?>
        <?php endif; ?>

    </main>

    <!-- Shared job-table interactions: child toggle, cancel/retry, detail modal -->
    <script src="job_detail.js"></script>

</body>
</html>
