<?php
/**
 * Social API — AJAX endpoints for ratings, favourites, and comments.
 * All actions require POST. Returns JSON.
 *
 * Actions:
 *   rate              POST  storyID, rating (1-5)
 *   toggle_favorite   POST  storyID
 *   add_comment       POST  storyID, comment, reply_to_comment_id (optional)
 *   delete_comment    POST  comment_id
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

header('Content-Type: application/json');

// All social actions require login
if (!isset($_SESSION['userID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}

$userID  = (int)$_SESSION['userID'];
$isAdmin = !empty($_SESSION['isAdmin']);
$action  = $_POST['action'] ?? '';

/**
 * Whether the current user may rate/like/comment on this story.
 * Mirrors summary.php's view rule: published is open to everyone; a draft (or
 * any non-published, non-deleted story) is owner/admin only; deleted is blocked.
 */
function social_can_interact(?array $story, int $userID, bool $isAdmin): bool {
    if (!$story || $story['status'] === 'deleted') return false;
    if ($story['status'] === 'published')          return true;
    return $isAdmin || (int)$story['userID'] === $userID;
}

switch ($action) {

    // ------------------------------------------------------------------
    // rate — upsert a 1–5 star rating for a story
    // ------------------------------------------------------------------
    case 'rate':
        $storyID = (int)($_POST['storyID'] ?? 0);
        $rating  = (int)($_POST['rating']  ?? 0);

        if ($storyID <= 0 || $rating < 1 || $rating > 5) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid storyID or rating.']);
            exit;
        }

        $story = get_story($storyID);
        if (!social_can_interact($story, $userID, $isAdmin)) {
            http_response_code(404);
            echo json_encode(['error' => 'Story not found.']);
            exit;
        }

        rate_story($userID, $storyID, $rating);
        $stats = get_story_rating($storyID);
        echo json_encode([
            'success'      => true,
            'your_rating'  => $rating,
            'average'      => $stats['average'] !== null ? round($stats['average'], 1) : null,
            'count'        => $stats['count'],
        ]);
        break;

    // ------------------------------------------------------------------
    // toggle_favorite — add or remove a story from a user's favourites
    // ------------------------------------------------------------------
    case 'toggle_favorite':
        $storyID = (int)($_POST['storyID'] ?? 0);

        if ($storyID <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid storyID.']);
            exit;
        }

        $story = get_story($storyID);
        if (!social_can_interact($story, $userID, $isAdmin)) {
            http_response_code(404);
            echo json_encode(['error' => 'Story not found.']);
            exit;
        }

        $isFav = toggle_favorite($userID, $storyID);
        $count = get_favorite_count($storyID);
        echo json_encode([
            'success'      => true,
            'is_favorited' => $isFav,
            'count'        => $count,
        ]);
        break;

    // ------------------------------------------------------------------
    // add_comment — post a comment (or reply) on a story
    // ------------------------------------------------------------------
    case 'add_comment':
        $storyID           = (int)($_POST['storyID'] ?? 0);
        $comment           = trim($_POST['comment'] ?? '');
        $replyToCommentID  = isset($_POST['reply_to_comment_id']) && (int)$_POST['reply_to_comment_id'] > 0
                             ? (int)$_POST['reply_to_comment_id']
                             : null;

        if ($storyID <= 0 || $comment === '') {
            http_response_code(400);
            echo json_encode(['error' => 'storyID and comment are required.']);
            exit;
        }

        if (mb_strlen($comment) > 1000) {
            http_response_code(400);
            echo json_encode(['error' => 'Comment must be 1000 characters or fewer.']);
            exit;
        }

        $story = get_story($storyID);
        if (!social_can_interact($story, $userID, $isAdmin)) {
            http_response_code(404);
            echo json_encode(['error' => 'Story not found.']);
            exit;
        }

        $commentID = add_comment($userID, $storyID, $comment, $replyToCommentID);
        if (!$commentID) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save comment.']);
            exit;
        }

        $user = get_user_by_id($userID);
        echo json_encode([
            'success'             => true,
            'comment_id'          => $commentID,
            'comment'             => htmlspecialchars($comment),
            'reply_to_comment_id' => $replyToCommentID,
            'user_id'             => $userID,
            'author'              => htmlspecialchars($user['firstName'] . ' ' . $user['lastName']),
            'profile_image'       => htmlspecialchars($user['profileImage'] ?? ''),
            'created_at'          => date('Y-m-d H:i:s'),
            'is_owner'            => true,
            'is_admin'            => $isAdmin,
        ]);
        break;

    // ------------------------------------------------------------------
    // delete_comment — delete a comment (own comment or admin)
    // ------------------------------------------------------------------
    case 'delete_comment':
        $commentID = (int)($_POST['comment_id'] ?? 0);

        if ($commentID <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid comment_id.']);
            exit;
        }

        // Admins can delete any comment; others only their own
        $deleted = $isAdmin
            ? delete_comment($commentID, null)
            : delete_comment($commentID, $userID);

        if (!$deleted) {
            http_response_code(403);
            echo json_encode(['error' => 'Comment not found or permission denied.']);
            exit;
        }

        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
        break;
}
