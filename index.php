<?php
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';
require_once 'pagination.php';

// Flash messages
$message = '';
$error = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$currentUserID = isset($_SESSION['userID']) ? (int)$_SESSION['userID'] : null;
$isAdmin       = !empty($_SESSION['isAdmin']);

// Filter
$filter = 'all';
if ($currentUserID && isset($_GET['filter'])) {
    if ($_GET['filter'] === 'mine')       $filter = 'mine';
    elseif ($_GET['filter'] === 'likes')  $filter = 'likes';
}

// Genre & sort
$genreFilter = trim($_GET['genre'] ?? '');
$sortFilter  = trim($_GET['sort'] ?? 'latest');
$allowedSorts = ['latest', 'rating', 'views', 'comments'];
if (!in_array($sortFilter, $allowedSorts)) $sortFilter = 'latest';

// Genre list (admin-managed via the story_genres setting)
$genreList = json_decode(app_setting('story_genres') ?? '[]', true) ?: [];
// Show alphabetically, but keep the catch-all "Other" pinned to the bottom.
usort($genreList, function ($a, $b) {
    $ao = strcasecmp($a, 'other') === 0;
    $bo = strcasecmp($b, 'other') === 0;
    if ($ao !== $bo) return $ao ? 1 : -1;
    return strcasecmp($a, $b);
});
if (!in_array($genreFilter, $genreList)) $genreFilter = '';

// Search
$search = trim($_GET['q'] ?? '');

// Fetch stories
if ($search !== '') {
    $stories = search_stories($search, $currentUserID, $isAdmin);
} elseif ($filter === 'mine') {
    $stories = get_stories_by_user($currentUserID);
} elseif ($filter === 'likes') {
    $stories = get_favorites_by_user($currentUserID);
} else {
    $stories = get_all_stories($currentUserID, $genreFilter, $sortFilter, $isAdmin);
}

// Build favourite-ID set for heart icons (O(1) lookup per card)
$userFavSet = [];
if ($currentUserID) {
    foreach (get_user_favorite_ids($currentUserID) as $fid) {
        $userFavSet[$fid] = true;
    }
}

// ── Pagination (Phase 40) ──
// Array-slice paging: the list query only selects story columns (cheap), and the
// expensive per-card stat lookups run only for the sliced page. Page size comes
// from the `gallery_page_size` setting; the active genre/sort/filter/q params are
// preserved across pages.
$perPage = (int) app_setting('gallery_page_size');
if ($perPage < 1) $perPage = 12;
$totalStories = count($stories);
$totalPages   = max(1, (int) ceil($totalStories / $perPage));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1)           $page = 1;
if ($page > $totalPages) $page = $totalPages;
$pagedStories = array_slice($stories, ($page - 1) * $perPage, $perPage);

// Query params to carry across pager links (skip 'mine'/'likes' default of 'all')
$pagerParams = array_filter([
    'filter' => ($filter !== 'all' ? $filter : ''),
    'genre'  => $genreFilter,
    'sort'   => ($sortFilter !== 'latest' ? $sortFilter : ''),
    'q'      => $search,
], fn($v) => $v !== '');
$pagerHtml = render_pager($page, $totalPages, $pagerParams, 'index.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Your Own Adventure Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/cards.css">
    <link rel="stylesheet" href="styles/forms.css">
    <link rel="stylesheet" href="styles/pager.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <main class="container">
        <div class="hero">
            <h1>Choose a story to Explore</h1>
            <p>Play interactive stories or create your own tales where every choice matters.</p>
        </div>

        <?php if ($currentUserID): ?>
        <div class="filter-bar">
            <div class="filter-buttons">
                <a href="index.php" class="filter-btn <?php echo ($filter === 'all' && $search === '') ? 'active' : ''; ?>">All Stories</a>
                <a href="index.php?filter=mine" class="filter-btn <?php echo $filter === 'mine' ? 'active' : ''; ?>">My Stories</a>
                <a href="index.php?filter=likes" class="filter-btn <?php echo $filter === 'likes' ? 'active' : ''; ?>">Favorites</a>
            </div>
            <div class="filter-sort-controls">
                <label class="filter-sort-label" for="genre-select">Filter By:</label>
                <select id="genre-select" class="filter-select" onchange="applyFilters()">
                    <option value="">All Genres</option>
                    <?php foreach ($genreList as $g): ?>
                        <option value="<?php echo htmlspecialchars($g); ?>"<?php echo ($genreFilter === $g) ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($g); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label class="filter-sort-label" for="sort-select">Sort By:</label>
                <select id="sort-select" class="filter-select" onchange="applyFilters()">
                    <option value="latest"<?php echo ($sortFilter === 'latest') ? ' selected' : ''; ?>>Latest</option>
                    <option value="rating"<?php echo ($sortFilter === 'rating') ? ' selected' : ''; ?>>Highest Rated</option>
                    <option value="views"<?php echo ($sortFilter === 'views') ? ' selected' : ''; ?>>Most Viewed</option>
                    <option value="comments"<?php echo ($sortFilter === 'comments') ? ' selected' : ''; ?>>Most Commented</option>
                </select>
            </div>
            <a href="editor.php?action=new_story" class="btn btn-primary">+ Create New Story</a>
        </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($search !== ''): ?>
            <p class="search-results-label">
                <?php echo count($stories); ?> result<?php echo count($stories) !== 1 ? 's' : ''; ?> for
                <strong><?php echo htmlspecialchars($search); ?></strong>
            </p>
        <?php endif; ?>

        <div class="story-gallery">
            <?php if (empty($stories)): ?>
                <div class="empty-message">
                    <?php if ($search !== ''): ?>
                        <p>No stories match your search.</p>
                    <?php elseif ($filter === 'likes'): ?>
                        <p>You haven't liked any stories yet. Browse <a href="index.php">All Stories</a> to find one you like!</p>
                    <?php else: ?>
                        <p>No stories available yet.
                        <?php if ($currentUserID): ?>
                            <a href="editor.php?action=new_story">Create the first one!</a>
                        <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($pagedStories as $story): ?>
                <?php
                $isOwner  = $currentUserID && ((int)$story['userID'] === $currentUserID || $isAdmin);
                $isDraft  = ($story['status'] === 'draft');
                // Thumbnail and card click always go to summary.php
                $cardLink = 'summary.php?storyID=' . (int)$story['storyID'];
                $stats    = get_story_stats((int)$story['storyID']);
                $isFaved  = isset($userFavSet[(int)$story['storyID']]);
                ?>
                <div class="story-card<?php echo $isDraft ? ' story-card-draft' : ''; ?>">
                    <?php if ($isDraft): ?>
                        <span class="draft-badge">Draft</span>
                    <?php endif; ?>
                    <a href="<?php echo $cardLink; ?>" class="story-card-image">
                        <?php if (!empty($story['image'])): ?>
                            <img src="images/stories/<?php echo (int)$story['storyID']; ?>/<?php echo htmlspecialchars($story['image']); ?>" alt="<?php echo htmlspecialchars($story['title']); ?>">
                        <?php else: ?>
                            <div class="placeholder-image">&#128214;</div>
                        <?php endif; ?>
                    </a>
                    <div class="story-card-content">
                        <h3><?php echo htmlspecialchars($story['title']); ?></h3>
                        <p class="story-card-author">By <?php echo htmlspecialchars($story['created_by']); ?></p>
                        <p><?php echo htmlspecialchars(substr($story['description'], 0, 150)); ?><?php echo strlen($story['description']) > 150 ? '...' : ''; ?></p>
                    </div>
                    <div class="story-card-stats">
                        <div class="card-social-strip">
                            <span class="card-stat" title="Views">
                                <span class="stat-eye-icon">&#128065;</span>
                                <?php echo number_format($stats['views']); ?>
                            </span>
                            <span class="card-stat" title="Comments">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;">
                                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                                </svg>
                                <?php echo number_format($stats['comment_count']); ?>
                            </span>
                            <button class="card-fav-btn<?php echo $isFaved ? ' is-faved' : ''; ?>"
                                    data-story-id="<?php echo (int)$story['storyID']; ?>"
                                    data-logged-in="<?php echo $currentUserID ? '1' : '0'; ?>"
                                    title="<?php echo $currentUserID ? ($isFaved ? 'Unlike' : 'Like') : 'Log in to like'; ?>"
                                    aria-label="Like">
                                <svg class="heart-svg" viewBox="0 0 24 24" width="16" height="16"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                                </svg>
                                <span class="card-fav-count"><?php echo number_format($stats['fave_count']); ?></span>
                            </button>
                            <?php if ($stats['avg_rating'] !== null): ?>
                                <span class="card-stat" title="Average rating">&#11088; <?php echo $stats['avg_rating']; ?> <span class="stat-rating-count">(<?php echo $stats['rating_count']; ?>)</span></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($story['genre'])): ?>
                            <div class="card-genre-wrap">
                                <?php foreach ($story['genre'] as $g): ?>
                                    <span class="card-genre-chip"><?php echo htmlspecialchars($g); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="story-card-actions">
                        <?php if ($isOwner): ?>
                            <div class="story-card-actions-left">
                                <a href="play.php?storyID=<?php echo (int)$story['storyID']; ?>" class="btn btn-play btn-sm">Play</a>
                                <a href="editor.php?storyID=<?php echo (int)$story['storyID']; ?>" class="btn btn-edit btn-sm">Edit</a>
                            </div>
                        <?php else: ?>
                            <a href="summary.php?storyID=<?php echo (int)$story['storyID']; ?>" class="btn btn-play btn-sm">View</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($stories)) echo $pagerHtml; ?>
    </main>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> Choose Your Own Adventure Maker</p>
    </footer>

<script>
/* ── Genre/sort filter controls ── */
function applyFilters() {
    var genre = document.getElementById('genre-select').value;
    var sort  = document.getElementById('sort-select').value;
    var url = 'index.php';
    var params = [];
    if (genre) params.push('genre=' + encodeURIComponent(genre));
    if (sort && sort !== 'latest') params.push('sort=' + encodeURIComponent(sort));
    if (params.length) url += '?' + params.join('&');
    window.location.href = url;
}

/* ── Heart / like toggle ── */
(function () {
    document.querySelectorAll('.card-fav-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();

            if (btn.dataset.loggedIn !== '1') {
                window.location.href = 'login.php';
                return;
            }

            var storyID = btn.dataset.storyId;
            btn.disabled = true;

            var body = new URLSearchParams({ action: 'toggle_favorite', storyID: storyID });
            fetch('api_social.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    btn.classList.toggle('is-faved', data.is_favorited);
                    btn.title = data.is_favorited ? 'Unlike' : 'Like';
                    var countEl = btn.querySelector('.card-fav-count');
                    if (countEl) countEl.textContent = data.count.toLocaleString();
                }
            })
            .catch(function () {})
            .finally(function () { btn.disabled = false; });
        });
    });
})();
</script>
</body>
</html>
