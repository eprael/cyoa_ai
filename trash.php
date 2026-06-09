<?php
/**
 * Trash — soft-deleted stories.
 * Owners can restore their own stories.
 * Admins can restore or permanently delete any story.
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

if (!isset($_SESSION['userID'])) {
    header('Location: login.php');
    exit;
}

$userID  = (int)$_SESSION['userID'];
$isAdmin = !empty($_SESSION['isAdmin']);

// ── POST actions ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']  ?? '';
    $storyID = (int)($_POST['storyID'] ?? 0);

    if ($storyID) {
        $story = get_story($storyID);
        $isOwner = $story && ((int)$story['userID'] === $userID);

        if ($action === 'restore' && ($isOwner || $isAdmin)) {
            db_restore_story($storyID);
            $_SESSION['flash_message'] = 'Story restored to drafts.';
            $_SESSION['flash_type']    = 'success';

        } elseif ($action === 'delete_forever' && $isAdmin) {
            // Hard delete: remove DB record + image folder
            delete_story($storyID);
            $_SESSION['flash_message'] = 'Story permanently deleted.';
            $_SESSION['flash_type']    = 'success';

        } else {
            $_SESSION['flash_message'] = 'Action not permitted.';
            $_SESSION['flash_type']    = 'error';
        }
    }

    header('Location: trash.php');
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────

$deletedStories = db_get_deleted_stories($userID, $isAdmin);

$message = '';
$msgType = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash &mdash; CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/forms.css">
    <style>
        .trash-container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .trash-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        .trash-header h1 {
            font-size: 1.6rem;
            margin: 0;
        }
        .trash-note {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        .trash-table {
            width: 100%;
            border-collapse: collapse;
        }
        .trash-table th,
        .trash-table td {
            padding: 0.65rem 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .trash-table th {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-light);
            background: var(--bg-secondary, var(--bg));
        }
        .trash-thumb {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 4px;
            background: var(--border);
        }
        .trash-thumb-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 4px;
            background: var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }
        .trash-title {
            font-weight: 600;
        }
        .trash-owner {
            font-size: 0.85rem;
            color: var(--text-light);
        }
        .trash-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .trash-empty {
            text-align: center;
            padding: 4rem 1rem;
            color: var(--text-light);
        }
        .trash-empty svg {
            opacity: 0.3;
            margin-bottom: 1rem;
        }
        .flash-msg {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }
        .flash-success { background: var(--success-bg, #d4edda); color: var(--success-text, #155724); }
        .flash-error   { background: var(--error-bg,   #f8d7da); color: var(--error-text,   #721c24); }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="trash-container">
    <div class="trash-header">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/>
            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
        </svg>
        <h1>Trash</h1>
    </div>

    <?php if ($message): ?>
        <div class="flash-msg flash-<?php echo htmlspecialchars($msgType); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <p class="trash-note">
        <?php if ($isAdmin): ?>
            Showing all deleted stories. Use <strong>Restore</strong> to recover a story as a draft,
            or <strong>Delete Forever</strong> to permanently remove it.
        <?php else: ?>
            Deleted stories are listed below. Restore a story to bring it back as a draft.
        <?php endif; ?>
    </p>

    <?php if (empty($deletedStories)): ?>
        <div class="trash-empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
            <p><?php echo $isAdmin ? 'The trash is empty.' : 'Your trash is empty.'; ?></p>
        </div>
    <?php else: ?>
        <table class="trash-table">
            <thead>
                <tr>
                    <th></th>
                    <th>Story</th>
                    <?php if ($isAdmin): ?><th>Owner</th><?php endif; ?>
                    <th>Date Deleted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deletedStories as $s):
                    $thumbSrc = !empty($s['image'])
                        ? 'images/stories/' . (int)$s['storyID'] . '/' . htmlspecialchars($s['image'])
                        : null;
                    $isOwner = (int)$s['userID'] === $userID;
                    $dateDeleted = !empty($s['date_deleted'])
                        ? date('M j, Y', strtotime($s['date_deleted']))
                        : '—';
                ?>
                <tr>
                    <td>
                        <?php if ($thumbSrc): ?>
                            <img class="trash-thumb" src="<?php echo $thumbSrc; ?>"
                                 alt="<?php echo htmlspecialchars($s['title']); ?>">
                        <?php else: ?>
                            <div class="trash-thumb-placeholder">&#128214;</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="trash-title"><?php echo htmlspecialchars($s['title']); ?></div>
                    </td>
                    <?php if ($isAdmin): ?>
                    <td class="trash-owner">
                        <?php echo htmlspecialchars($s['firstName'] . ' ' . $s['lastName']); ?>
                    </td>
                    <?php endif; ?>
                    <td><?php echo $dateDeleted; ?></td>
                    <td>
                        <div class="trash-actions">
                            <?php if ($isOwner || $isAdmin): ?>
                            <form method="POST" class="trash-form-restore">
                                <input type="hidden" name="action"  value="restore">
                                <input type="hidden" name="storyID" value="<?php echo (int)$s['storyID']; ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Restore</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($isAdmin): ?>
                            <form method="POST" class="trash-form-forever">
                                <input type="hidden" name="action"  value="delete_forever">
                                <input type="hidden" name="storyID" value="<?php echo (int)$s['storyID']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete Forever</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>

<footer>
    <p>&copy; <?php echo date('Y'); ?> Choose Your Own Adventure Maker</p>
</footer>

<script>
(function () {
    document.querySelectorAll('.trash-form-restore').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            Modal.confirm('Restore this story to your drafts?', function () { form.submit(); });
        });
    });
    document.querySelectorAll('.trash-form-forever').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            Modal.confirmDanger({
                heading: 'Delete Permanently?',
                message: 'This story will be permanently deleted. This cannot be undone.',
                confirmLabel: 'Delete Forever',
                onConfirm: function () { form.submit(); }
            });
        });
    });
})();
</script>
</body>
</html>
