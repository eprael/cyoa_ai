<?php
/**
 * Story Tree API (Phase 31)
 *
 * GET api_tree.php?storyID=N
 *   → { scenes:  [{id, title, thumbnail, is_start}],
 *       choices: [{from_scene_id, to_scene_id, label}] }
 *
 * Auth: the requester must own the story or be an admin.
 * The first scene by creation order (sceneID ASC) is flagged is_start, matching
 * how play.php picks the starting scene.
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

header('Content-Type: application/json');

$isLoggedIn = isset($_SESSION['userID']);
$userID     = $isLoggedIn ? (int)$_SESSION['userID'] : 0;
$isAdmin    = $isLoggedIn && !empty($_SESSION['isAdmin']);

if (!$isLoggedIn) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Login required.']);
    exit;
}

$storyID = isset($_GET['storyID']) ? (int)$_GET['storyID'] : 0;
$story   = $storyID ? get_story($storyID) : null;

if (!$story) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Story not found.']);
    exit;
}

// Owner or admin only
if (!$isAdmin && (int)$story['userID'] !== $userID) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized.']);
    exit;
}

/** Resolve a scene image filename to a web path (mirrors editor_img_url). */
function tree_img_url(int $storyID, $publishedStoryID, string $filename): ?string {
    if ($filename === '') return null;
    if (!empty($publishedStoryID)) {
        $own = 'images/stories/' . $storyID . '/' . $filename;
        return file_exists($own) ? $own : 'images/stories/' . (int)$publishedStoryID . '/' . $filename;
    }
    return 'images/stories/' . $storyID . '/' . $filename;
}

$publishedStoryID = $story['published_story_id'] ?? null;
$rawScenes        = get_scenes_with_choices_by_story($storyID); // sceneID ASC

$scenes  = [];
$choices = [];
$first   = true;
foreach ($rawScenes as $s) {
    $sid = (int)$s['sceneID'];
    $scenes[] = [
        'id'        => $sid,
        'title'     => (string)$s['title'],
        'thumbnail' => tree_img_url($storyID, $publishedStoryID, (string)($s['image'] ?? '')),
        'is_start'  => $first,
    ];
    $first = false;
    foreach (($s['choices'] ?? []) as $c) {
        $choices[] = [
            'from_scene_id' => $sid,
            'to_scene_id'   => (int)$c['dest'],
            'label'         => (string)$c['text'],
        ];
    }
}

echo json_encode([
    'scenes'   => $scenes,
    'choices'  => $choices,
    // Drives node-link target in tree-view.js: drafts → scene editor, otherwise → player.
    'is_draft' => ($story['status'] === 'draft'),
], JSON_UNESCAPED_UNICODE);
