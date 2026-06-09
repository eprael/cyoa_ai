<?php
/**
 * Site Settings (admin) — job queue, appearance, and maintenance. Each section is
 * its own panel; one Save persists them all. (API keys and AI generation models
 * live on the AI Settings page, settings_content.php.)
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

if (!isset($_SESSION['userID']) || empty($_SESSION['isAdmin'])) {
    header('Location: ' . (isset($_SESSION['userID']) ? 'index.php' : 'login.php'));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Save site settings (incl. maintenance retention windows) ──
    // Note: API keys and AI generation models moved to AI Settings (settings_content.php).
    if ($action === 'update_site_settings') {
        $stringKeys = ['app_title'];
        $intKeys    = ['scene_thumb_size', 'ai_job_timeout_seconds', 'ai_image_request_timeout', 'ai_claude_request_timeout', 'ai_max_pending_per_user', 'ai_max_concurrent_image_jobs', 'gallery_tile_size', 'gallery_filmstrip_size', 'gallery_tile_spacing'];

        foreach ($stringKeys as $key) {
            if (isset($_POST[$key])) db_set_setting($key, trim($_POST[$key]));
        }
        foreach ($intKeys as $key) {
            if (isset($_POST[$key])) db_set_setting($key, (string)(int)$_POST[$key]);
        }
        db_set_setting('ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');

        $retAllowed = ['1day', '1week', '1month'];
        foreach (['trash_retention', 'log_retention'] as $key) {
            if (isset($_POST[$key]) && in_array($_POST[$key], $retAllowed, true)) {
                db_set_setting($key, $_POST[$key]);
            }
        }

        global $SETTINGS;
        $SETTINGS = db_get_all_settings();
        $success = 'Site settings saved.';
    }

    // ── Empty trash now (inline; like the trash page "Delete Forever") ──
    if ($action === 'empty_trash_now') {
        $purged  = db_purge_deleted_stories(retention_to_interval(app_setting('trash_retention')));
        $n       = count($purged);
        $success = 'Emptied trash — purged ' . $n . ' stor' . ($n === 1 ? 'y' : 'ies') . '.';
    }
}

$retentionOpts  = ['1day' => '1 day', '1week' => '1 week', '1month' => '1 month'];
$trashRetention = app_setting('trash_retention');
$logRetention   = app_setting('log_retention');
$currentThumb   = (int) app_setting('scene_thumb_size');
$currentGTile   = (int) app_setting('gallery_tile_size');
$currentGStrip  = (int) app_setting('gallery_filmstrip_size');
$currentGSpace  = (int) app_setting('gallery_tile_spacing');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Settings - CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
    <link rel="stylesheet" href="styles/account.css">
    <style>
        /* Each section is an outlined block within the single page panel */
        .settings-block { background:var(--bg); border:1px solid var(--border, #e2e8f0); border-radius:8px; padding:1rem 1.25rem; margin-bottom:1.25rem; }
        .settings-block:last-child { margin-bottom:0; }
        .settings-block > h2 { margin-top:0; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <main class="container">

        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <div class="account-section">
        <h1 style="margin:0 0 0.4rem; font-size:1.5rem;">Site Settings</h1>
        <p style="color:var(--text-light); font-size:0.9rem; margin:0 0 1.25rem;">Site-wide configuration for the job queue, appearance, and automatic maintenance. Changes apply after you press Save Settings.</p>

        <form method="post" class="settings-form">
            <input type="hidden" name="action" value="update_site_settings">

            <!-- Appearance -->
            <div class="settings-block">
                <h2>Appearance</h2>
                <div class="form-group">
                    <label for="app_title">Site Title</label>
                    <input type="text" id="app_title" name="app_title" class="form-control"
                           value="<?php echo htmlspecialchars(app_setting('app_title') ?? ''); ?>" maxlength="120">
                </div>
                <div class="form-group">
                    <label for="scene_thumb_size">Scene Thumbnail Size</label>
                    <select id="scene_thumb_size" name="scene_thumb_size" class="form-control" style="max-width:220px;">
                        <?php foreach (SCENE_THUMB_SIZES as $label => $px): ?>
                            <option value="<?php echo (int)$px; ?>" <?php echo $currentThumb === (int)$px ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?> (<?php echo (int)$px; ?>px)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Gallery (v7) -->
            <div class="settings-block">
                <h2>Gallery</h2>
                <div class="form-group">
                    <label for="gallery_tile_size">Gallery Tile Size</label>
                    <select id="gallery_tile_size" name="gallery_tile_size" class="form-control" style="max-width:220px;">
                        <?php foreach (GALLERY_TILE_SIZES as $label => $px): ?>
                            <option value="<?php echo (int)$px; ?>" <?php echo $currentGTile === (int)$px ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?> (<?php echo (int)$px; ?>px)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="gallery_filmstrip_size">Filmstrip Thumbnail Size</label>
                    <select id="gallery_filmstrip_size" name="gallery_filmstrip_size" class="form-control" style="max-width:220px;">
                        <?php foreach (GALLERY_FILMSTRIP_SIZES as $label => $px): ?>
                            <option value="<?php echo (int)$px; ?>" <?php echo $currentGStrip === (int)$px ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?> (<?php echo (int)$px; ?>px)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="gallery_tile_spacing">Gallery Tile Spacing</label>
                    <select id="gallery_tile_spacing" name="gallery_tile_spacing" class="form-control" style="max-width:220px;">
                        <?php foreach (GALLERY_TILE_SPACING as $label => $px): ?>
                            <option value="<?php echo (int)$px; ?>" <?php echo $currentGSpace === (int)$px ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?> (<?php echo (int)$px; ?>px)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Job Queue -->
            <div class="settings-block">
                <h2>Job Queue</h2>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="ai_enabled" value="1" <?php echo (bool)(int) app_setting('ai_enabled') ? 'checked' : ''; ?>>
                        AI Processing Enabled
                    </label>
                </div>
                <div class="form-group">
                    <label for="ai_job_timeout_seconds">Job Timeout (seconds)</label>
                    <input type="number" id="ai_job_timeout_seconds" name="ai_job_timeout_seconds"
                           class="form-control" min="30" max="3600"
                           value="<?php echo (int) app_setting('ai_job_timeout_seconds'); ?>">
                    <small style="color:var(--text-light);">Watchdog: a job stuck longer than this is marked failed.</small>
                </div>
                <div class="form-group">
                    <label for="ai_image_request_timeout">Image Generation Timeout (seconds)</label>
                    <input type="number" id="ai_image_request_timeout" name="ai_image_request_timeout"
                           class="form-control" min="30" max="600"
                           value="<?php echo (int) app_setting('ai_image_request_timeout'); ?>">
                    <small style="color:var(--text-light);">How long to wait for a single OpenAI image. Raise if you see "Operation timed out" errors on slow/high-quality images. Keep below the Job Timeout above.</small>
                </div>
                <div class="form-group">
                    <label for="ai_claude_request_timeout">Story/Scene Generation Timeout (seconds)</label>
                    <input type="number" id="ai_claude_request_timeout" name="ai_claude_request_timeout"
                           class="form-control" min="30" max="600"
                           value="<?php echo (int) app_setting('ai_claude_request_timeout'); ?>">
                    <small style="color:var(--text-light);">How long to wait for a single AI text call (one plan or scene). A timed-out call is retried once. A full story makes many calls in sequence, so keep the Job Timeout well above this.</small>
                </div>
                <div class="form-group">
                    <label for="ai_max_pending_per_user">Max Pending Jobs Per User</label>
                    <input type="number" id="ai_max_pending_per_user" name="ai_max_pending_per_user"
                           class="form-control" min="1" max="50"
                           value="<?php echo (int) app_setting('ai_max_pending_per_user'); ?>">
                    <small style="color:var(--text-light);">Limits top-level submissions (a full story, a single scene, or a single image).</small>
                </div>
                <div class="form-group">
                    <label for="ai_max_concurrent_image_jobs">Max Concurrent Image Jobs</label>
                    <input type="number" id="ai_max_concurrent_image_jobs" name="ai_max_concurrent_image_jobs"
                           class="form-control" min="1" max="10"
                           value="<?php echo (int) app_setting('ai_max_concurrent_image_jobs'); ?>">
                    <small style="color:var(--text-light);">Limits simultaneous OpenAI image API calls to avoid rate limits. Lower if you see 429 errors; raise on higher-tier API plans.</small>
                </div>
            </div>

            <!-- Maintenance -->
            <div class="settings-block">
                <h2>Maintenance</h2>
                <p style="color:var(--text-light); font-size:0.9rem;">Automatic daily cleanup runs via the job dispatcher. Trashed stories and old logs are permanently removed once they exceed the retention windows below.</p>
                <div class="form-group">
                    <label for="trash_retention">Keep stories in trash for</label>
                    <select id="trash_retention" name="trash_retention" class="form-control" style="max-width:220px;">
                        <?php foreach ($retentionOpts as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $trashRetention === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="log_retention">Keep logs for</label>
                    <select id="log_retention" name="log_retention" class="form-control" style="max-width:220px;">
                        <?php foreach ($retentionOpts as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $logRetention === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- type=button so it doesn't submit the settings form; submits the separate form below -->
                <button type="button" class="btn btn-secondary btn-sm"
                        onclick="Modal.confirmDanger({heading:'Empty Trash Now?', message:'Trashed stories older than the retention window will be permanently deleted. This cannot be undone.', confirmLabel:'Empty Trash', onConfirm: () => document.getElementById('form-empty-trash').submit()})">
                    Empty Trash Now
                </button>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:1.5rem;">Save Settings</button>
        </form>

        <!-- Separate form for the Empty Trash action (kept outside the settings form to avoid nesting) -->
        <form method="post" id="form-empty-trash" style="display:none;">
            <input type="hidden" name="action" value="empty_trash_now">
        </form>
        </div>

    </main>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> Choose Your Own Adventure Maker</p>
    </footer>

</body>
</html>
