<?php
/**
 * AI Job Queue API — AJAX endpoints for the job queue system.
 * Returns JSON for all actions.
 *
 * Actions (GET):
 *   unseen_count              — badge count; returns {"count":0} for guests
 *   status&job_id=N           — poll a single job's status
 *   list                      — user's job list (admin sees all)
 *
 * Actions (POST):
 *   create                    — submit a new AI job
 *   cancel&job_id=N           — cancel a pending job (owner or admin)
 *   retry&job_id=N            — reset a failed job to pending (owner or admin)
 *   apply&job_id=N            — re-apply a completed job's result (admin only)
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';
require_once __DIR__ . '/cron/ai_apply.php';

header('Content-Type: application/json');

$isLoggedIn = isset($_SESSION['userID']);
$userID     = $isLoggedIn ? (int)$_SESSION['userID'] : 0;
$isAdmin    = $isLoggedIn && !empty($_SESSION['isAdmin']);

// Route by method + action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ------------------------------------------------------------------
// unseen_count — no auth required (guests always get 0)
// ------------------------------------------------------------------
if ($action === 'unseen_count') {
    if (!$isLoggedIn) {
        echo json_encode(['count' => 0]);
        exit;
    }
    echo json_encode(['count' => get_unseen_job_count($userID)]);
    exit;
}

// All remaining actions require login
if (!$isLoggedIn) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Login required.']);
    exit;
}

// ------------------------------------------------------------------
// GET actions
// ------------------------------------------------------------------
if ($method === 'GET') {

    switch ($action) {

        // status — poll a single job
        case 'status':
            $jobID = (int)($_GET['job_id'] ?? 0);
            if ($jobID <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing job_id.']);
                exit;
            }
            $job = get_ai_job($jobID);
            if (!$job) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Job not found.']);
                exit;
            }
            if ((int)$job['user_id'] !== $userID && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Not authorized.']);
                exit;
            }
            $resp = [
                'job_id'     => (int)$job['job_id'],
                'status'     => $job['status'],
                'job_type'   => $job['job_type'],
                'created_at' => $job['created_at'],
            ];
            if ($job['status'] === 'completed') {
                $resp['result'] = json_decode($job['result_json'], true);
            } elseif ($job['status'] === 'failed') {
                $resp['error'] = $job['error_message'];
            }
            echo json_encode($resp);
            exit;

        // list — job history
        case 'list':
            $limit   = max(1, min(100, (int)($_GET['limit'] ?? 20)));
            $jobs    = $isAdmin ? get_all_jobs($limit) : get_jobs_by_user($userID, $limit);

            // Optional filters
            if (!empty($_GET['status'])) {
                $filterStatus = $_GET['status'];
                $jobs = array_values(array_filter($jobs, fn($j) => $j['status'] === $filterStatus));
            }
            if (!empty($_GET['story_id'])) {
                $filterStory = (int)$_GET['story_id'];
                $jobs = array_values(array_filter($jobs, fn($j) => (int)$j['story_id'] === $filterStory));
            }
            echo json_encode(['jobs' => $jobs]);
            exit;

        // detail — full job record for the detail modal (Phase 32)
        case 'detail':
            $jobID = (int)($_GET['job_id'] ?? 0);
            if ($jobID <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing job_id.']);
                exit;
            }
            $job = get_job_with_context($jobID);
            if (!$job) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Job not found.']);
                exit;
            }
            if ((int)$job['user_id'] !== $userID && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Not authorized.']);
                exit;
            }
            $userName = trim(($job['firstName'] ?? '') . ' ' . ($job['lastName'] ?? ''));
            echo json_encode([
                'job_id'        => (int)$job['job_id'],
                'job_type'      => $job['job_type'],
                'status'        => $job['status'],
                'user_name'     => $userName !== '' ? $userName : ($job['email'] ?? ''),
                'story_title'   => $job['story_title'] ?? null,
                'scene_title'   => $job['scene_title'] ?? null,
                'created_at'    => $job['created_at'],
                'started_at'    => $job['started_at'],
                'updated_at'    => $job['updated_at'] ?? null,
                'cost_usd'      => $job['cost_usd'] !== null ? (float)$job['cost_usd'] : null,
                'error_message' => $job['error_message'],
                'input_json'    => json_decode($job['input_json'] ?? 'null', true),
                'result_json'   => json_decode($job['result_json'] ?? 'null', true),
            ], JSON_UNESCAPED_UNICODE);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
            exit;
    }
}

// ------------------------------------------------------------------
// POST actions
// ------------------------------------------------------------------
if ($method === 'POST') {

    // job_id helper (used by cancel / retry / apply)
    $jobID = (int)($_POST['job_id'] ?? $_GET['job_id'] ?? 0);

    switch ($action) {

        // create — submit a new AI job
        case 'create':
            if (!(bool)(int) app_setting('ai_enabled')) {
                http_response_code(503);
                echo json_encode(['success' => false, 'error' => 'AI features are currently disabled.']);
                exit;
            }

            $jobType   = $_POST['job_type']   ?? '';
            $storyID   = isset($_POST['story_id'])  && $_POST['story_id']  !== '' ? (int)$_POST['story_id']  : null;
            $sceneID   = isset($_POST['scene_id'])  && $_POST['scene_id']  !== '' ? (int)$_POST['scene_id']  : null;
            $inputJson = $_POST['input_json'] ?? '';

            // Validate job_type
            $validTypes = ['image', 'scene', 'full_story'];
            if (!in_array($jobType, $validTypes, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid job_type.']);
                exit;
            }

            // job_type-specific required fields
            $decoded = json_decode($inputJson, true);
            $isCoverImage = ($jobType === 'image' && is_array($decoded) && ($decoded['target'] ?? '') === 'story_cover');
            if ($jobType === 'image' && !$isCoverImage && ($storyID === null || $sceneID === null)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'story_id and scene_id are required for image jobs.']);
                exit;
            }
            if ($jobType === 'image' && $isCoverImage && $storyID === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'story_id is required for story cover image jobs.']);
                exit;
            }
            if ($jobType === 'scene' && ($storyID === null || $sceneID === null)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'story_id and scene_id are required for scene jobs.']);
                exit;
            }

            // Validate input_json
            if (empty($inputJson)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'input_json is required.']);
                exit;
            }
            if (strlen($inputJson) > 65535) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'input_json is too large.']);
                exit;
            }
            $decoded = json_decode($inputJson, true);
            if ($decoded === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'input_json is not valid JSON.']);
                exit;
            }

            // Verify story ownership (if a story is involved)
            if ($storyID !== null) {
                $story = get_story($storyID);
                if (!$story) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Story not found.']);
                    exit;
                }
                if ((int)$story['userID'] !== $userID && !$isAdmin) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'You do not own this story.']);
                    exit;
                }
            }

            // For scene jobs, augment input_json server-side with story context
            // and the backward path walk (previous_scenes).
            // The client sends only user-authored params (direction, mode, tone, etc.).
            if ($jobType === 'scene') {
                $decoded['story_title']       = $story['title']       ?? '';
                // Genre is the story's semantic category — it guides the scene's
                // narrative content (see build_scene_user_prompt). The visual theme
                // (colours/fonts) is deliberately not sent anywhere in generation.
                $decoded['story_genre']       = !empty($story['genre']) ? implode(', ', (array)$story['genre']) : '';
                $decoded['story_description'] = $story['description'] ?? '';
                $decoded['previous_scenes']   = get_scene_path_to_root($sceneID);
                $inputJson = json_encode($decoded);
            }

            // Provider-key availability (user BYOK overrides the site key). Scene
            // text needs a Claude key; images (cover/scene, or a scene's optional
            // image) need an OpenAI key. Mirrors the editor's badge gating so a
            // doomed job can't be queued via a crafted request.
            $jobUser = get_user_by_id($userID);
            if ($jobType === 'scene') {
                if (!ai_provider_available('claude', $jobUser)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Scene generation needs a Claude (Anthropic) API key. Add one in your account settings.']);
                    exit;
                }
                if (!empty($decoded['generate_image']) && !ai_provider_available('openai', $jobUser)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Generating a scene image needs an OpenAI API key. Add one in your account settings, or turn off the image option.']);
                    exit;
                }
            } elseif ($jobType === 'image') {
                if (!ai_provider_available('openai', $jobUser)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Image generation needs an OpenAI API key. Add one in your account settings.']);
                    exit;
                }
            } elseif ($jobType === 'full_story') {
                if (!ai_provider_available('claude', $jobUser)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Story generation needs a Claude (Anthropic) API key. Add one in your account settings.']);
                    exit;
                }
            }

            // Rate limit — max pending+running jobs per user
            $maxPending = (int) app_setting('ai_max_pending_per_user');
            if (count_pending_jobs_by_user($userID) >= $maxPending) {
                http_response_code(429);
                echo json_encode(['success' => false, 'error' => "You already have $maxPending active jobs. Wait for some to finish before submitting more."]);
                exit;
            }

            $newJobID = create_ai_job($userID, $storyID, $sceneID, $jobType, $inputJson);
            if ($newJobID === false) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to create job.']);
                exit;
            }
            echo json_encode(['success' => true, 'job_id' => $newJobID]);
            exit;

        // cancel — cancel a pending job (owner or admin)
        case 'cancel':
            if ($jobID <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing job_id.']);
                exit;
            }
            $job = get_ai_job($jobID);
            if (!$job) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Job not found.']);
                exit;
            }
            if ((int)$job['user_id'] !== $userID && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Not authorized.']);
                exit;
            }
            if ($job['status'] !== 'pending') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Job cannot be cancelled — it is already ' . $job['status'] . '.']);
                exit;
            }
            cancel_ai_job($jobID);
            echo json_encode(['success' => true]);
            exit;

        // retry — reset a failed job to pending (owner or admin)
        case 'retry':
            if ($jobID <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing job_id.']);
                exit;
            }
            $job = get_ai_job($jobID);
            if (!$job) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Job not found.']);
                exit;
            }
            if ((int)$job['user_id'] !== $userID && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Not authorized.']);
                exit;
            }
            if ($job['status'] !== 'failed') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Only failed jobs can be retried.']);
                exit;
            }
            retry_ai_job($jobID);
            echo json_encode(['success' => true]);
            exit;

        // apply — re-apply a completed job's result without re-calling the AI (admin only)
        case 'apply':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Admin only.']);
                exit;
            }
            if ($jobID <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing job_id.']);
                exit;
            }
            $job = get_ai_job($jobID);
            if (!$job) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Job not found.']);
                exit;
            }
            if ($job['status'] !== 'completed') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Only completed jobs can be re-applied.']);
                exit;
            }
            try {
                $applyResult = apply_ai_job($job);
                echo json_encode(array_merge(['success' => true], $applyResult));
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
            exit;
    }
}

// Method not allowed
http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
