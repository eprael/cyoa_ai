<?php
/**
 * Job Queue — lists the current user's AI jobs (admins see all users' jobs).
 * Marks all unseen completed/failed jobs as seen on load (clears the header badge).
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';
require_once 'job_render.php';

// Align PHP's "today" with the configured app timezone (Phase 40 today/past split).
date_default_timezone_set(APP_TIMEZONE);

// Must be logged in
if (!isset($_SESSION['userID'])) {
    header('Location: login.php');
    exit;
}

$userID  = (int)$_SESSION['userID'];
$isAdmin = !empty($_SESSION['isAdmin']);

// ── Clear Completed (Phase 32) — hard-delete terminal jobs, then PRG redirect ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_completed') {
    $cleared = db_clear_completed_jobs($userID, $isAdmin);
    $_SESSION['flash_message'] = "Cleared $cleared finished job" . ($cleared === 1 ? '' : 's') . '.';
    $qs = trim($_POST['return_qs'] ?? '');
    header('Location: job_queue.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// ── Filter parameters (Phase 32) ──
// Phase 40: this page shows today's jobs only, so the period filter was dropped
// (older jobs live on job_history.php).
$fStatus = $_GET['status'] ?? '';
$fType   = $_GET['type']   ?? '';
$fQuery  = trim($_GET['q'] ?? '');
$filtersActive = ($fStatus !== '' || $fType !== '' || $fQuery !== '');

// Query string for preserving filters across POST/redirects.
$currentQs = http_build_query(array_filter([
    'q'      => $fQuery,
    'status' => $fStatus,
    'type'   => $fType,
], fn($v) => $v !== ''));

// Flash messages
$message = '';
$error   = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Mark all unseen jobs seen — clears the header badge
mark_jobs_seen($userID);

// Fetch all jobs then build parent/child structure
$allJobs = $isAdmin ? get_all_jobs(200) : get_jobs_by_user($userID, 200);

$childrenByParent = [];
foreach ($allJobs as $job) {
    if (!empty($job['parent_job_id'])) {
        $childrenByParent[(int)$job['parent_job_id']][] = $job;
    }
}

// Top-level jobs only (no parent_job_id set)
$topLevelJobs = array_values(array_filter($allJobs, fn($j) => empty($j['parent_job_id'])));
$hasAnyJobs   = !empty($topLevelJobs);

// Apply the filter bar selections to the top-level jobs (Phase 32).
if ($filtersActive) {
    $topLevelJobs = array_values(array_filter($topLevelJobs, function ($j) use ($fStatus, $fType, $fQuery, $isAdmin) {
        if ($fStatus !== '') {
            if ($fStatus === 'completed') {
                if (!in_array($j['status'], ['completed', 'completed_with_errors'], true)) return false;
            } elseif ($j['status'] !== $fStatus) {
                return false;
            }
        }
        if ($fType !== '' && $j['job_type'] !== $fType) return false;
        if ($fQuery !== '') {
            $hay = mb_strtolower(($j['story_title'] ?? '') . ' ' . ($j['scene_title'] ?? ''));
            if ($isAdmin) {
                $hay .= ' ' . mb_strtolower(($j['firstName'] ?? '') . ' ' . ($j['lastName'] ?? '') . ' ' . ($j['email'] ?? ''));
            }
            if (mb_strpos($hay, mb_strtolower($fQuery)) === false) return false;
        }
        return true;
    }));
}

// Phase 40 — this page shows TODAY's jobs only (local midnight cutoff). Older
// jobs live on job_history.php (linked via the "Past Jobs" button below the table).
$todayStart = strtotime('today');
$todayJobs  = array_values(array_filter($topLevelJobs, fn($j) => strtotime($j['created_at']) >= $todayStart));

// Job-table rendering helpers (job_type_label, status_badge, render_job_table, …)
// live in job_render.php, shared with job_history.php.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Queue — CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
    <link rel="stylesheet" href="styles/job_queue.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <main class="container">

        <div class="queue-header">
            <h1>Job Queue</h1>
            <?php if ($isAdmin && !empty($allJobs)): ?>
                <span style="color:#888;font-size:0.9rem;">Showing all users' jobs (admin view)</span>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!$hasAnyJobs): ?>
            <div class="queue-empty">
                <p>No AI jobs yet.</p>
                <p style="font-size:0.9rem;color:#aaa;">
                    Jobs appear here when you generate images, scenes, or full stories using AI.
                </p>
            </div>
        <?php else: ?>

            <?php /* Admin stat cards (Phase 32) */ if ($isAdmin): $jobStats = db_get_job_stats(); ?>
            <div class="job-stats">
                <div class="stat-card stat-pending"><div class="stat-val"><?php echo $jobStats['pending']; ?></div><div class="stat-label">Pending</div></div>
                <div class="stat-card stat-running"><div class="stat-val"><?php echo $jobStats['running']; ?></div><div class="stat-label">Running</div></div>
                <div class="stat-card stat-completed"><div class="stat-val"><?php echo $jobStats['completed_today']; ?></div><div class="stat-label">Completed Today</div></div>
                <div class="stat-card stat-failed"><div class="stat-val"><?php echo $jobStats['failed_today']; ?></div><div class="stat-label">Failed Today</div></div>
                <div class="stat-card stat-total"><div class="stat-val"><?php echo $jobStats['total_today']; ?></div><div class="stat-label">Total Today</div></div>
                <div class="stat-card stat-spent"><div class="stat-val">$<?php echo number_format($jobStats['spent_today'], 4); ?></div><div class="stat-label">Spent Today</div></div>
            </div>
            <?php endif; ?>

            <?php /* Filter bar + Clear Completed (Phase 32) */ ?>
            <div class="queue-toolbar">
                <form method="get" class="queue-filters">
                    <input type="search" name="q" value="<?php echo htmlspecialchars($fQuery); ?>"
                           placeholder="<?php echo $isAdmin ? 'Search story or user…' : 'Search story…'; ?>" class="filter-search">
                    <select name="status" onchange="this.form.submit()">
                        <?php foreach (['' => 'All Statuses', 'pending' => 'Pending', 'running' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed', 'cancelled' => 'Cancelled'] as $v => $lbl): ?>
                            <option value="<?php echo $v; ?>" <?php echo $fStatus === $v ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="type" onchange="this.form.submit()">
                        <?php foreach (['' => 'All Types', 'image' => 'Image', 'scene' => 'Scene', 'story' => 'Create Story'] as $v => $lbl): ?>
                            <option value="<?php echo $v; ?>" <?php echo $fType === $v ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($filtersActive): ?>
                        <a href="job_queue.php" class="filter-clear-link">Reset</a>
                    <?php endif; ?>
                </form>
                <form method="post" id="clear-completed-form" class="clear-form">
                    <input type="hidden" name="action" value="clear_completed">
                    <input type="hidden" name="return_qs" value="<?php echo htmlspecialchars($currentQs); ?>">
                    <button type="button" class="btn-clear-completed" onclick="confirmClear()">Clear Completed</button>
                </form>
            </div>



            <?php if (empty($todayJobs)): ?>
                <div class="queue-empty"><p><?php echo $filtersActive ? 'No jobs match your filters today.' : 'No AI jobs today.'; ?></p></div>
            <?php else: ?>
                <?php render_job_table($todayJobs, $isAdmin, $userID, $childrenByParent); ?>
            <?php endif; ?>

            <div class="queue-subnav">
                <a href="job_history.php" class="btn-past-jobs">View Past Jobs &rarr;</a>
            </div>

        <?php endif; ?>
    </main>

    <script>
    /* ── Live status polling ── */
    (function () {
        var statusMap = {
            pending:               '<span class="badge badge-pending">Pending</span>',
            running:               '<span class="badge badge-running">Running</span><span class="spinner"></span>',
            completed:             '<span class="badge badge-completed">Completed</span>',
            failed:                '<span class="badge badge-failed">Failed</span>',
            cancelled:             '<span class="badge badge-cancelled">Cancelled</span>',
            completed_with_errors: '<span class="badge badge-warn">Done &#9888;</span>'
        };

        function pollStatuses() {
            fetch('api_jobs.php?action=list&limit=200')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.jobs) return;
                    var hasActive = false;
                    data.jobs.forEach(function (job) {
                        var row = document.getElementById('job-row-' + job.job_id);
                        if (!row) return;
                        var statusCell = row.querySelector('.job-status-cell');
                        if (statusCell) {
                            var html = statusMap[job.status] || job.status;
                            if (statusCell.innerHTML !== html) statusCell.innerHTML = html;
                        }
                        if (job.status === 'pending' || job.status === 'running') hasActive = true;
                    });
                    // Keep parent badge as 'running' while any child is still active,
                    // even though the parent's DB status may already be 'completed'.
                    document.querySelectorAll('[data-toggle-children]').forEach(function (btn) {
                        var parentId  = btn.dataset.toggleChildren;
                        var parentRow = document.getElementById('job-row-' + parentId);
                        if (!parentRow) return;
                        var childRows = document.querySelectorAll('[data-child-of="' + parentId + '"]');
                        var anyChildActive = false;
                        childRows.forEach(function (cr) {
                            if (cr.querySelector('.badge-pending, .badge-running')) anyChildActive = true;
                        });
                        if (anyChildActive) {
                            var pCell = parentRow.querySelector('.job-status-cell');
                            if (pCell) pCell.innerHTML = statusMap['running'];
                        }
                    });
                    if (hasActive) {
                        setTimeout(pollStatuses, 5000);
                    } else {
                        // All jobs finished — reload to show final titles, costs, and durations
                        window.location.reload();
                    }
                })
                .catch(function () { setTimeout(pollStatuses, 10000); });
        }

        /* Only poll if there are active jobs on the page */
        var hasActive = document.querySelector('.badge-pending, .badge-running');
        if (hasActive) setTimeout(pollStatuses, 5000);
    })();
    </script>

    <!-- Shared job-table interactions: child toggle, cancel/retry, detail modal -->
    <script src="job_detail.js"></script>
    <script>
    function confirmClear() {
        Modal.confirmDanger({
            heading: 'Clear Job History?',
            message: 'All completed, failed, and cancelled jobs will be removed. This cannot be undone.',
            confirmLabel: 'Clear',
            onConfirm: function () { document.getElementById('clear-completed-form').submit(); }
        });
    }
    </script>

</body>
</html>
