<?php
/**
 * Story Image Gallery (v7)
 *
 * A standalone, owner/admin-only gallery of a story's images: the cover first,
 * then every scene that has an image. Tiles open a lightbox with prev/next
 * navigation and a filmstrip. Sizing is admin-tunable in Site Settings → Gallery.
 *
 * GET gallery.php?storyID=N[&from=editor|summary]
 *   from  — drives the breadcrumb + back link (defaults to 'editor').
 *
 * The per-story image list comes from get_gallery_items() (db_functions.php),
 * the single data seam shared with any future global gallery.
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

$storyID = isset($_GET['storyID']) ? (int)$_GET['storyID'] : 0;
$story   = $storyID ? get_story($storyID) : null;

$currentUserID = isset($_SESSION['userID']) ? (int)$_SESSION['userID'] : null;
$isAdmin       = !empty($_SESSION['isAdmin']);
$isOwner       = $currentUserID && $story && (int)$story['userID'] === $currentUserID;

// Owner/admin only (mirrors api_tree.php). Missing/deleted stories 404.
if (!$story || $story['status'] === 'deleted') {
    http_response_code(404);
    include 'header.php';
    echo '<main class="container"><div style="text-align:center;padding:4rem;"><h2>Story Not Found</h2>'
       . '<p>This story does not exist.</p>'
       . '<a href="index.php" class="btn btn-primary" style="margin-top:1.5rem;">Back to Gallery</a></div></main>';
    echo '<footer><p>&copy; ' . date('Y') . ' Choose Your Own Adventure Maker</p></footer></body></html>';
    exit;
}
if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    include 'header.php';
    echo '<main class="container"><div style="text-align:center;padding:4rem;"><h2>Not Authorized</h2>'
       . '<p>Only the story owner or an admin can view its gallery.</p>'
       . '<a href="summary.php?storyID=' . $storyID . '" class="btn btn-primary" style="margin-top:1.5rem;">Back to Story</a></div></main>';
    echo '<footer><p>&copy; ' . date('Y') . ' Choose Your Own Adventure Maker</p></footer></body></html>';
    exit;
}

// Where the visitor came from drives the breadcrumb + back link.
$from = ($_GET['from'] ?? 'editor') === 'summary' ? 'summary' : 'editor';
$backHref  = $from === 'summary'
    ? 'summary.php?storyID=' . $storyID
    : 'editor.php?storyID=' . $storyID;
$backLabel = $from === 'summary' ? 'Summary' : 'Editor';

$items = get_gallery_items($storyID);

// Admin-tunable sizing (px). Clamped to sane floors in case a setting is blank.
$tileSize  = max(80, (int) app_setting('gallery_tile_size'));
$stripSize = max(40, (int) app_setting('gallery_filmstrip_size'));
$spacing   = max(0,  (int) app_setting('gallery_tile_spacing'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery — <?php echo htmlspecialchars($story['title']); ?> — CYOA Maker</title>
    <link rel="stylesheet" href="styles/styles.css">
    <link rel="stylesheet" href="styles/cards.css">
    <link rel="stylesheet" href="styles/gallery.css">
    <style>
        .gallery-grid {
            --gallery-tile: <?php echo $tileSize; ?>px;
            --gallery-gap: <?php echo $spacing; ?>px;
        }
        .gallery-lightbox { --gallery-strip: <?php echo $stripSize; ?>px; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="container">
    <div class="breadcrumb">
        <a href="index.php">Home</a>
        <a href="index.php?filter=mine">My Stories</a>
        <a href="summary.php?storyID=<?php echo $storyID; ?>"><?php echo htmlspecialchars($story['title']); ?></a>
        <?php if ($from === 'editor'): ?>
            <a href="editor.php?storyID=<?php echo $storyID; ?>">Editor</a>
        <?php endif; ?>
        <span>Gallery</span>
    </div>

    <div class="gallery-head">
        <h1>Image Gallery</h1>
        <a href="<?php echo htmlspecialchars($backHref); ?>" class="btn btn-secondary btn-sm">&larr; Back to <?php echo $backLabel; ?></a>
    </div>

    <?php if (empty($items)): ?>
        <div class="gallery-empty">
            <p>This story has no images yet.</p>
            <p class="gallery-empty-sub">Add a cover image or generate scene images, and they'll appear here.</p>
        </div>
    <?php else: ?>
        <div class="gallery-grid" id="gallery-grid">
            <?php foreach ($items as $i => $it): ?>
                <button type="button" class="gallery-tile" data-index="<?php echo $i; ?>"
                        title="<?php echo htmlspecialchars($it['title']); ?>">
                    <span class="gallery-tile-imgwrap">
                        <img src="<?php echo htmlspecialchars($it['src']); ?>"
                             alt="<?php echo htmlspecialchars($it['title']); ?>" loading="lazy">
                    </span>
                    <span class="gallery-tile-caption"><?php echo htmlspecialchars($it['title']); ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Lightbox -->
        <div class="gallery-lightbox" id="gallery-lightbox" hidden>
            <div class="gallery-lightbox-title" id="gallery-lightbox-title"></div>
            <button type="button" class="gallery-lightbox-close" aria-label="Close">&times;</button>
            <button type="button" class="gallery-nav gallery-nav-prev" aria-label="Previous">&#10094;</button>
            <div class="gallery-lightbox-stage">
                <img id="gallery-lightbox-img" src="" alt="">
            </div>
            <button type="button" class="gallery-nav gallery-nav-next" aria-label="Next">&#10095;</button>
            <div class="gallery-filmstrip" id="gallery-filmstrip"></div>
        </div>

        <script id="gallery-data" type="application/json"><?php
            echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?></script>
        <script src="gallery.js"></script>
    <?php endif; ?>
</main>

<footer><p>&copy; <?php echo date('Y'); ?> Choose Your Own Adventure Maker</p></footer>
</body>
</html>
