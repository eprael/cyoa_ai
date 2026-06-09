<?php
/**
 * Story Summary Page
 * Shows cover image, stats, description, rating widget, like toggle, comments.
 * Records a view on each load for published stories.
 * Owners/admins see draft stories here too (no auto-redirect to editor).
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

$storyID = isset($_GET['storyID']) ? (int)$_GET['storyID'] : 0;
if (!$storyID) {
    header('Location: index.php');
    exit;
}

$story = get_story($storyID);
$currentUserID = isset($_SESSION['userID']) ? (int)$_SESSION['userID'] : null;
$isAdmin = !empty($_SESSION['isAdmin']);
$isOwner = $currentUserID && ($story && (int)$story['userID'] === $currentUserID);

// Block deleted stories from everyone (only visible in trash.php)
// Block drafts from non-owners/non-admins
if (!$story
    || $story['status'] === 'deleted'
    || ($story['status'] !== 'published' && !$isOwner && !$isAdmin)) {
    http_response_code(404);
    include 'header.php';
    echo '<main class="container"><div style="text-align:center;padding:4rem;"><h2>Story Not Found</h2><p>This story does not exist or is not publicly available.</p><a href="index.php" class="btn btn-primary" style="margin-top:1.5rem;">Back to Gallery</a></div></main>';
    echo '<footer><p>&copy; ' . date('Y') . ' Choose Your Own Adventure Maker</p></footer></body></html>';
    exit;
}

// Record a view for published stories only
if ($story['status'] === 'published') {
    record_view($storyID, $currentUserID);
}

// Load social data
$stats      = get_story_stats($storyID);
$comments   = get_comments_by_story($storyID);
$userRating = $currentUserID ? get_user_rating($currentUserID, $storyID) : null;
$isFav      = $currentUserID ? is_favorited($currentUserID, $storyID) : false;
$sceneCount = db_count_scenes($storyID);

// Image URL helper — resolve the cover, accounting for shadow drafts whose image
// may live under the published story's folder (mirrors play.php / editor.php).
$coverImage = null;
if (!empty($story['image'])) {
    $pubStoryID = isset($story['published_story_id']) ? (int)$story['published_story_id'] : null;
    $imgDir = ($pubStoryID && !file_exists('images/stories/' . $storyID . '/' . $story['image']))
        ? $pubStoryID
        : $storyID;
    $coverImage = htmlspecialchars('images/stories/' . $imgDir . '/' . $story['image']);
}

// Rating display values (Phase 36: compact "[avg] ★★★☆☆ (count)" format)
$avgRating   = $stats['avg_rating'];                                  // null or decimal
$ratingCount = (int)$stats['rating_count'];
$avgRounded  = $avgRating !== null ? (int)round($avgRating) : 0;      // stars filled
$avgDisplay  = $avgRating !== null ? $avgRating : 0;                  // number shown

// Genre list (admin-managed via the story_genres setting)
$genreList = json_decode(app_setting('story_genres') ?? '[]', true) ?: [];

// Build a flat comment map: comment_id → comment, for rendering threaded replies
$topLevel = [];
$replies   = [];
foreach ($comments as $c) {
    if ($c['reply_to_comment_id'] === null) {
        $topLevel[] = $c;
    } else {
        $replies[(int)$c['reply_to_comment_id']][] = $c;
    }
}

// Date added
$dateAdded = !empty($story['date_created']) ? date('M j, Y', strtotime($story['date_created'])) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($story['title']); ?> — CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/cards.css">
    <link rel="stylesheet" href="styles/forms.css">
    <link rel="stylesheet" href="styles/summary.css">
    <link rel="stylesheet" href="styles/tree-view.css">
</head>
<body>
<?php include 'header.php'; ?>

<main class="container">
    <div class="breadcrumb">
        <a href="index.php">Home</a>
        <span><?php echo htmlspecialchars($story['title']); ?></span>
    </div>

    <!-- ══════════════════════════════════════════
         STORY HERO
    ══════════════════════════════════════════ -->
    <div class="summary-hero">
        <div class="summary-cover">
            <?php if ($coverImage): ?>
                <button type="button" class="cover-zoom-btn" data-img="<?php echo $coverImage; ?>"
                        title="View full image" aria-label="View full image">
                    <img src="<?php echo $coverImage; ?>" alt="<?php echo htmlspecialchars($story['title']); ?>">
                </button>
            <?php else: ?>
                <div class="placeholder-image">&#128214;</div>
            <?php endif; ?>
        </div>
        <div class="summary-info">
            <span class="status-tag status-<?php echo htmlspecialchars($story['status']); ?>">
                <?php echo $story['status'] === 'published' ? 'Published' : 'Draft'; ?>
            </span>
            <h1><?php echo htmlspecialchars($story['title']); ?></h1>
            <p class="summary-author">
                By <?php echo htmlspecialchars($story['created_by']); ?>
                &nbsp;&middot;&nbsp; <?php echo $sceneCount; ?> scene<?php echo $sceneCount !== 1 ? 's' : ''; ?>
                &nbsp;&middot;&nbsp; Added <?php echo $dateAdded; ?>
            </p>
            <p class="summary-description"><?php echo nl2br(htmlspecialchars($story['description'])); ?></p>

            <?php if (!empty($story['genre'])): ?>
            <div class="summary-meta">
                <?php foreach ($story['genre'] as $g): ?>
                    <span class="meta-chip">&#128218; <?php echo htmlspecialchars($g); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Social strip -->
            <div class="social-strip">
                <!-- Views -->
                <div class="social-item">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true" style="pointer-events:none">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <span class="social-count"><?php echo number_format($stats['views']); ?></span>
                    <span class="social-sub">views</span>
                </div>

                <div class="social-divider"></div>

                <!-- Likes — only the heart icon toggles; the word is a static label -->
                <div class="social-item social-like-item">
                    <button class="social-like-btn<?php echo $isFav ? ' is-liked' : ''; ?>"
                            id="fav-chip"
                            data-story-id="<?php echo $storyID; ?>"
                            data-logged-in="<?php echo $currentUserID ? '1' : '0'; ?>"
                            aria-label="<?php echo $currentUserID ? ($isFav ? 'Unlike' : 'Like') : 'Log in to like'; ?>"
                            title="<?php echo $currentUserID ? ($isFav ? 'Unlike' : 'Like') : 'Log in to like'; ?>">
                        <svg class="fav-heart-svg" viewBox="0 0 24 24" width="20" height="20"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             style="pointer-events:none">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                    </button>
                    <span class="social-count" id="fav-count"><?php echo number_format($stats['fave_count']); ?></span>
                    <span class="social-sub" id="fav-label"><?php echo (int)$stats['fave_count'] === 1 ? 'like' : 'likes'; ?></span>
                </div>

                <div class="social-divider"></div>

                <!-- Star rating (interactive) — compact "[avg] ★★★☆☆ (count)" -->
                <div class="social-item social-rating-item">
                    <div class="star-rating-display">
                        <span class="star-avg" id="star-avg"><?php echo $avgDisplay; ?></span>
                        <div class="star-widget<?php echo !$currentUserID ? ' star-widget-guest' : ''; ?>"
                             data-story-id="<?php echo $storyID; ?>"
                             data-user-rating="<?php echo $userRating ?? 0; ?>"
                             data-avg-rounded="<?php echo $avgRounded; ?>"
                             data-logged-in="<?php echo $currentUserID ? '1' : '0'; ?>">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <button class="star-btn<?php echo ($i <= $avgRounded) ? ' star-filled' : ''; ?>"
                                        data-value="<?php echo $i; ?>"
                                        title="<?php echo $i; ?> star<?php echo $i > 1 ? 's' : ''; ?>">&#9733;</button>
                            <?php endfor; ?>
                        </div>
                        <span class="star-count" id="star-count">(<?php echo number_format($ratingCount); ?>)</span>
                    </div>
                </div>

                <div class="social-divider"></div>

                <!-- Comments count -->
                <div class="social-item">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true" style="pointer-events:none">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    <span class="social-count"><?php echo number_format($stats['comment_count']); ?></span>
                    <span class="social-sub">comments</span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="summary-actions">
                <a href="play.php?storyID=<?php echo $storyID; ?>" class="btn btn-play">&#9654; Play Story</a>
                <div class="summary-actions-right">
                    <?php if (($isOwner || $isAdmin) && $sceneCount > 0): ?>
                        <button type="button" id="summary-tree-btn" class="btn btn-primary">Tree View</button>
                    <?php endif; ?>
                    <?php if ($isOwner || $isAdmin): ?>
                        <a href="gallery.php?storyID=<?php echo $storyID; ?>&from=summary" class="btn btn-primary">Gallery</a>
                    <?php endif; ?>
                    <?php if ($isOwner || $isAdmin): ?>
                        <a href="editor.php?storyID=<?php echo $storyID; ?>" class="btn btn-edit">Edit Story</a>
                    <?php endif; ?>
                    <?php if ($currentUserID): ?>
                        <form method="POST" action="editor.php" id="clone-form" style="display:inline">
                            <input type="hidden" name="action" value="clone_story">
                            <input type="hidden" name="storyID" value="<?php echo $storyID; ?>">
                            <input type="hidden" name="clone_title" value="<?php echo htmlspecialchars($story['title'] . ' (Copy)'); ?>">
                            <button type="button" class="btn btn-primary"
                                    onclick="Modal.confirm('Clone this story to your account?', () => document.getElementById('clone-form').submit())">Clone Story</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         COMMENTS
    ══════════════════════════════════════════ -->
    <div class="summary-section" id="comments-section">
        <h2>Comments <span class="comment-count-badge"><?php echo count($comments); ?></span></h2>

        <?php if ($currentUserID): ?>
        <form class="comment-form" id="comment-form" data-story-id="<?php echo $storyID; ?>">
            <textarea id="comment-input" name="comment" rows="3"
                      maxlength="1000" placeholder="Share your thoughts..."></textarea>
            <input type="hidden" id="reply-to-id" name="reply_to_comment_id" value="">
            <div id="reply-banner" class="reply-banner" style="display:none;">
                Replying to a comment &mdash; <button type="button" id="cancel-reply-btn">Cancel</button>
            </div>
            <div class="comment-form-actions">
                <button type="submit" class="btn btn-primary btn-sm">Post Comment</button>
            </div>
        </form>
        <?php else: ?>
        <p style="margin-bottom:1.5rem; color:var(--text-light);">
            <a href="login.php">Log in</a> to leave a comment.
        </p>
        <?php endif; ?>

        <div id="comments-list">
            <?php if (empty($topLevel)): ?>
                <p class="no-comments">No comments yet. Be the first!</p>
            <?php else: ?>
                <?php foreach ($topLevel as $c): ?>
                    <?php echo render_comment_html($c, $replies, $currentUserID, $isAdmin); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <!-- Full-size cover image modal -->
    <?php if ($coverImage): ?>
    <div id="cover-img-modal" class="scene-img-modal" hidden>
        <button type="button" class="scene-img-modal-close" aria-label="Close">&times;</button>
        <img id="cover-img-modal-img" src="" alt="">
    </div>
    <?php endif; ?>
</main>

<footer>
    <p>&copy; <?php echo date('Y'); ?> Choose Your Own Adventure Maker</p>
</footer>

<?php
/**
 * Render a single comment (and its replies) as HTML.
 */
function render_comment_html($c, $replies, $currentUserID, $isAdmin, $isReply = false) {
    $cID       = (int)$c['comment_id'];
    $authorName = htmlspecialchars($c['firstName'] . ' ' . $c['lastName']);
    $avatar     = !empty($c['profileImage'])
        ? '<img src="images/profiles/' . htmlspecialchars($c['profileImage']) . '" alt="">'
        : '<span class="avatar-fallback">&#128100;</span>';
    $canDelete  = $currentUserID && ($currentUserID == $c['user_id'] || $isAdmin);
    $canReply   = $currentUserID && !$isReply; // only one level of nesting

    ob_start();
    ?>
    <div class="comment<?php echo $isReply ? ' comment-reply' : ''; ?>" id="comment-<?php echo $cID; ?>">
        <div class="comment-avatar"><?php echo $avatar; ?></div>
        <div class="comment-body">
            <div class="comment-meta">
                <strong><?php echo $authorName; ?></strong>
                <span class="comment-time"><?php echo htmlspecialchars(date('M j, Y g:i a', strtotime($c['created_at']))); ?></span>
            </div>
            <p class="comment-text"><?php echo nl2br(htmlspecialchars($c['comment'])); ?></p>
            <div class="comment-actions">
                <?php if ($canReply): ?>
                    <button class="btn-link reply-btn" data-comment-id="<?php echo $cID; ?>"
                            data-author="<?php echo $authorName; ?>">Reply</button>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <button class="btn-link delete-btn" data-comment-id="<?php echo $cID; ?>">Delete</button>
                <?php endif; ?>
            </div>
            <?php if (!empty($replies[$cID])): ?>
                <div class="replies">
                    <?php foreach ($replies[$cID] as $reply): ?>
                        <?php echo render_comment_html($reply, [], $currentUserID, $isAdmin, true); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>

<script src="tree-view.js"></script>
<script>
(function () {
    const STORY_ID = <?php echo $storyID; ?>;

    // ── Tree View (owners/admins) ───────────────────────────────────
    var treeBtn = document.getElementById('summary-tree-btn');
    if (treeBtn) {
        treeBtn.addEventListener('click', function () {
            treeBtn.disabled = true;
            TreeView.openModal(STORY_ID).finally(function () { treeBtn.disabled = false; });
        });
    }

    // ── Cover image zoom ────────────────────────────────────────────
    var coverBtn   = document.querySelector('.cover-zoom-btn');
    var coverModal = document.getElementById('cover-img-modal');
    if (coverBtn && coverModal) {
        var coverModalImg = document.getElementById('cover-img-modal-img');
        var coverClose    = coverModal.querySelector('.scene-img-modal-close');
        function closeCover() { coverModal.hidden = true; coverModalImg.src = ''; }
        coverBtn.addEventListener('click', function () {
            coverModalImg.src = coverBtn.dataset.img;
            coverModal.hidden = false;
        });
        coverClose.addEventListener('click', closeCover);
        coverModal.addEventListener('click', function (e) { if (e.target === coverModal) closeCover(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeCover(); });
    }

    // ── Like (favourite) toggle — only the heart icon is the toggle ──
    const favBtn = document.getElementById('fav-chip');
    if (favBtn) {
        const countEl  = document.getElementById('fav-count');
        const labelEl  = document.getElementById('fav-label');
        const heartSvg = favBtn.querySelector('.fav-heart-svg');

        function applyLike(liked, count) {
            favBtn.classList.toggle('is-liked', liked);
            favBtn.title = liked ? 'Unlike' : 'Like';
            favBtn.setAttribute('aria-label', liked ? 'Unlike' : 'Like');
            if (heartSvg) heartSvg.style.fill = liked ? 'currentColor' : 'none';
            if (countEl)  countEl.textContent = Number(count).toLocaleString();
            if (labelEl)  labelEl.textContent = Number(count) === 1 ? 'like' : 'likes';
        }

        favBtn.addEventListener('click', function () {
            if (favBtn.dataset.loggedIn !== '1') {
                window.location.href = 'login.php';
                return;
            }

            const wasLiked  = favBtn.classList.contains('is-liked');
            const newLiked  = !wasLiked;
            const prevCount = countEl ? parseInt(countEl.textContent.replace(/,/g, ''), 10) : 0;

            // Optimistic update for instant feedback
            applyLike(newLiked, newLiked ? prevCount + 1 : Math.max(0, prevCount - 1));

            favBtn.disabled = true;
            const fd = new FormData();
            fd.append('action', 'toggle_favorite');
            fd.append('storyID', STORY_ID);
            fetch('api_social.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { applyLike(wasLiked, prevCount); return; }
                    applyLike(data.is_favorited, data.count); // server-authoritative
                })
                .catch(() => applyLike(wasLiked, prevCount)) // revert on network error
                .finally(() => { favBtn.disabled = false; });
        });
    }

    // ── Star Rating ──────────────────────────────────────────────────
    const widget = document.querySelector('.star-widget');
    if (widget) {
        const isLoggedIn = widget.dataset.loggedIn === '1';
        const stars      = widget.querySelectorAll('.star-btn');
        const avgEl      = document.getElementById('star-avg');
        const countEl    = document.getElementById('star-count');
        let avgRounded   = parseInt(widget.dataset.avgRounded) || 0;

        function highlightStars(upTo) {
            stars.forEach((s, i) => s.classList.toggle('star-filled', i < upTo));
        }

        stars.forEach(star => {
            star.addEventListener('click', () => {
                if (!isLoggedIn) { window.location.href = 'login.php'; return; }
                const val = parseInt(star.dataset.value);

                const fd = new FormData();
                fd.append('action', 'rate');
                fd.append('storyID', STORY_ID);
                fd.append('rating', val);
                fetch('api_social.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.success) { highlightStars(avgRounded); return; }
                        if (data.average !== null) {
                            avgRounded = Math.round(data.average);
                            if (avgEl)   avgEl.textContent = data.average;
                            if (countEl) countEl.textContent = '(' + Number(data.count).toLocaleString() + ')';
                        }
                        highlightStars(avgRounded);
                    })
                    .catch(() => highlightStars(avgRounded));
            });
            if (isLoggedIn) {
                star.addEventListener('mouseenter', () => highlightStars(parseInt(star.dataset.value)));
                star.addEventListener('mouseleave', () => highlightStars(avgRounded));
            }
        });
    }

    // ── Comments ─────────────────────────────────────────────────────
    const commentForm  = document.getElementById('comment-form');
    const replyToInput = document.getElementById('reply-to-id');
    const replyBanner  = document.getElementById('reply-banner');
    const commentInput = document.getElementById('comment-input');
    const commentsList = document.getElementById('comments-list');
    const countBadge   = document.querySelector('.comment-count-badge');

    // Reply button click (delegated)
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('reply-btn')) {
            const cid    = e.target.dataset.commentId;
            const author = e.target.dataset.author;
            replyToInput.value = cid;
            replyBanner.style.display = '';
            replyBanner.firstChild.textContent = 'Replying to ' + author + ' \u2014 ';
            commentInput.focus();
        }
    });

    // Cancel reply
    const cancelReplyBtn = document.getElementById('cancel-reply-btn');
    if (cancelReplyBtn) {
        cancelReplyBtn.addEventListener('click', function () {
            replyToInput.value = '';
            replyBanner.style.display = 'none';
        });
    }

    // Post comment
    if (commentForm) {
        commentForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const text = commentInput.value.trim();
            if (!text) return;
            const replyTo = replyToInput.value || '';
            const fd = new FormData();
            fd.append('action', 'add_comment');
            fd.append('storyID', STORY_ID);
            fd.append('comment', text);
            if (replyTo) fd.append('reply_to_comment_id', replyTo);

            fetch('api_social.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { Modal.alert(data.error || 'Failed to post comment.'); return; }
                    commentInput.value = '';
                    replyToInput.value = '';
                    replyBanner.style.display = 'none';

                    const noMsg = commentsList.querySelector('.no-comments');
                    if (noMsg) noMsg.remove();

                    const html = buildCommentHTML(data, replyTo);
                    if (replyTo) {
                        const parent = document.getElementById('comment-' + replyTo);
                        if (parent) {
                            let repliesDiv = parent.querySelector('.replies');
                            if (!repliesDiv) {
                                repliesDiv = document.createElement('div');
                                repliesDiv.className = 'replies';
                                parent.querySelector('.comment-body').appendChild(repliesDiv);
                            }
                            repliesDiv.insertAdjacentHTML('beforeend', html);
                        }
                    } else {
                        commentsList.insertAdjacentHTML('beforeend', html);
                    }

                    if (countBadge) countBadge.textContent = parseInt(countBadge.textContent || '0') + 1;
                });
        });
    }

    // Delete comment (delegated)
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('delete-btn')) {
            const cid = e.target.dataset.commentId;
            Modal.confirmDanger({ heading: 'Delete Comment?', message: 'This comment will be permanently removed.', confirmLabel: 'Delete', onConfirm: function () {
                const fd = new FormData();
                fd.append('action', 'delete_comment');
                fd.append('comment_id', cid);
                fetch('api_social.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.success) { Modal.alert(data.error || 'Failed to delete comment.'); return; }
                        const el = document.getElementById('comment-' + cid);
                        if (el) el.remove();
                        if (countBadge) {
                            const n = Math.max(0, parseInt(countBadge.textContent || '1') - 1);
                            countBadge.textContent = n;
                        }
                    });
            } });
        }
    });

    function buildCommentHTML(data, replyTo) {
        const avatar = data.profile_image
            ? '<img src="images/profiles/' + escHtml(data.profile_image) + '" alt="">'
            : '<span class="avatar-fallback">&#128100;</span>';
        const now = new Date();
        const dateStr = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                      + ' ' + now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        const isReplyClass = replyTo ? ' comment-reply' : '';
        const replyBtn  = !replyTo ? '<button class="btn-link reply-btn" data-comment-id="' + data.comment_id + '" data-author="' + escHtml(data.author) + '">Reply</button>' : '';
        const deleteBtn = '<button class="btn-link delete-btn" data-comment-id="' + data.comment_id + '">Delete</button>';
        return '<div class="comment' + isReplyClass + '" id="comment-' + data.comment_id + '">'
             + '<div class="comment-avatar">' + avatar + '</div>'
             + '<div class="comment-body">'
             + '<div class="comment-meta"><strong>' + escHtml(data.author) + '</strong>'
             + '<span class="comment-time">' + dateStr + '</span></div>'
             + '<p class="comment-text">' + escHtml(data.comment).replace(/\n/g, '<br>') + '</p>'
             + '<div class="comment-actions">' + replyBtn + deleteBtn + '</div>'
             + '</div></div>';
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>
</body>
</html>
