<?php
/**
 * Play Page - Renders a scene for a given story
 * Loads the story's theme CSS dynamically from the database
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';
require_once 'fonts.php';
require_once 'theme.php';

// Get story ID from URL, default to 1
$storyID = isset($_GET['storyID']) ? (int)$_GET['storyID'] : 1;

// Get scene ID from URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Load story info (for theme, title, author)
$story = get_story($storyID);
if ($story === null) {
    http_response_code(404);
    die("Error: Story not found.");
}

// Draft stories are only accessible to their owner (and admins).
if ($story['status'] === 'draft') {
    $visitorID  = $_SESSION['userID'] ?? null;
    $isAdmin    = !empty($_SESSION['isAdmin']);
    if (!$visitorID || ($visitorID != $story['userID'] && !$isAdmin)) {
        http_response_code(404);
        die("Error: Story not found.");
    }
}

// Owner or admin may manage the story (edit buttons + scene-tree view).
$canManage = isset($_SESSION['userID'])
    && ($_SESSION['userID'] == $story['userID'] || !empty($_SESSION['isAdmin']));

// If no specific scene ID, get the first scene in this story
if ($id === 0) {
    $scenes = get_scenes_by_story($storyID);
    if (empty($scenes)) {
        die("Error: This story has no scenes yet.");
    }
    $id = (int)$scenes[0]['sceneID'];
}

// Get the current scene
$scene = get_scene($id, $storyID);
if ($scene === null) {
    // Fallback: try the first scene
    $scenes = get_scenes_by_story($storyID);
    if (!empty($scenes)) {
        $id = (int)$scenes[0]['sceneID'];
        $scene = get_scene($id, $storyID);
    }
    if ($scene === null) {
        die("Error: Story point not found.");
    }
}

// Theme (Phase 42): a story with a valid theme_json renders via the data-driven
// engine (styles/play_theme.css + injected :root vars + dynamic font <link>);
// otherwise it falls back to its legacy per-file theme CSS (unchanged behaviour).
$engineTheme = theme_resolve_engine($story);

// Legacy per-file theme (used when there is no engine theme_json)
$theme = !empty($story['theme']) ? $story['theme'] : 'egyptian';
$themeFile = "themes/" . basename($theme) . "_theme.css";
if (!file_exists($themeFile)) {
    $themeFile = "themes/egyptian_theme.css";
}

// Determine layout
$layout = !empty($story['layout']) ? $story['layout'] : 'image_left';

// Resolve image path: shadow drafts share the published story's image folder,
// but fall back to the draft folder first in case new images were uploaded there.
$publishedStoryID = isset($story['published_story_id']) ? (int)$story['published_story_id'] : null;
function play_img_url(int $storyID, ?int $publishedStoryID, string $filename): string {
    if ($filename === '') return '';
    if ($publishedStoryID !== null) {
        if (file_exists('images/stories/' . $storyID . '/' . $filename)) {
            return 'images/stories/' . $storyID . '/' . $filename;
        }
        return 'images/stories/' . $publishedStoryID . '/' . $filename;
    }
    return 'images/stories/' . $storyID . '/' . $filename;
}

// Check if this is the first scene (for showing/hiding "Go Back" and "Start Over")
$allScenes = get_scenes_by_story($storyID);
$firstSceneID = !empty($allScenes) ? (int)$allScenes[0]['sceneID'] : $id;
$isFirst = ($id === $firstSceneID);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars($story['title']); ?>: <?php echo htmlspecialchars($scene['title']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($engineTheme): ?>
        <?php echo theme_font_links($engineTheme); ?>
        <link rel="stylesheet" href="styles/play_theme.css">
        <style><?php echo theme_css_vars($engineTheme); ?></style>
    <?php else: ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($themeFile); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="styles/play_layout.css">
    <?php if ($canManage): ?>
    <link rel="stylesheet" href="styles/modal.css">
    <link rel="stylesheet" href="styles/tree-view.css">
    <?php endif; ?>
    <style>
        /* Frozen "Story Gallery" link — top-left corner, mirrors the edit buttons. */
        .play-gallery-link {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 999;
            background: rgba(0,0,0,0.55);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.35);
            border-radius: 4px;
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
            text-decoration: none;
            backdrop-filter: blur(4px);
        }
        .play-gallery-link:hover { background: rgba(0,0,0,0.8); }
    </style>
    <?php if ($canManage): ?>
    <style>
        .play-edit-actions {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 999;
            display: flex;
            gap: 0.5rem;
        }
        .play-edit-btn {
            background: rgba(0,0,0,0.55);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.35);
            border-radius: 4px;
            padding: 0.35rem 0.75rem;
            font: inherit;
            font-size: 0.85rem;
            line-height: normal;
            cursor: pointer;
            text-decoration: none;
            backdrop-filter: blur(4px);
        }
        .play-edit-btn:hover { background: rgba(0,0,0,0.8); }
    </style>
    <?php endif; ?>
</head>
<body class="layout-<?php echo htmlspecialchars($layout); ?>">
    <a class="play-gallery-link" href="index.php">&larr; Story Gallery</a>
    <?php if ($canManage): ?>
        <div class="play-edit-actions">
            <button type="button" class="play-edit-btn" id="play-tree-btn">&#9095; Tree View</button>
            <a class="play-edit-btn" href="editor.php?storyID=<?php echo $storyID; ?>">&#9998; Edit Story</a>
            <a class="play-edit-btn" href="editor.php?action=edit_scene&storyID=<?php echo $storyID; ?>&sceneID=<?php echo $id; ?>">&#9998; Edit Scene</a>
        </div>
    <?php endif; ?>
    <!-- Sticky layout zones -->
    <div class="play-layout">
        <!-- Banner spans full viewport width for all layouts -->
        <div class="banner">
            <h2><?php echo htmlspecialchars($story['title']); ?></h2>
            by <?php echo htmlspecialchars($story['created_by']); ?>
        </div>
        <div class="play-body">
        <div class="play-sticky">
            <!-- Scene image -->
            <header>
                <?php if (!empty($scene['image'])): ?>
                    <img src="<?php echo htmlspecialchars(play_img_url($storyID, $publishedStoryID, $scene['image'] ?? '')); ?>" alt="<?php echo htmlspecialchars($scene['title']); ?>">
                    <?php if (!empty($scene['image_gen'])): ?>
                    <div class="prompt-info">
                        <span class="prompt-icon">&#8801;</span>
                        <span class="prompt-text">
                            Image prompt:<br/><br/>
                            <i><?php echo htmlspecialchars($scene['image_gen']); ?></i>
                        </span>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </header>
        </div>

        <!-- Scrollable content zone -->
        <div class="play-scroll">
            <div class="content">
                <h1><?php echo htmlspecialchars($scene['title']); ?></h1>
                <main>
                    <br/>
                    <!-- Story description (allows HTML for formatting) -->
                    <p><?php echo $scene['description']; ?></p>
                    <br/>

                    <?php if (!empty($scene['choices'])): ?>
                        <!-- Choice buttons -->
                        <div class="choices">
                            <?php foreach ($scene['choices'] as $choice): ?>
                                <div class="choice">
                                    <a href="?id=<?php echo (int)$choice['dest']; ?>&storyID=<?php echo $storyID; ?>"><?php echo htmlspecialchars($choice['text']); ?></a>
                                </div>
                            <?php endforeach; ?>

                            <?php if (!$isFirst && !empty($scene['enable_autoBack_nav'])): ?>
                                <div class="choice">
                                    <a href="javascript:history.back()">Go Back</a>
                                </div>
                            <?php endif; ?>

                            <br/>

                            <?php if (!empty($scene['hint'])): ?>
                            <div class="hint">
                                <span class="hint-icon">?</span>
                                <span class="hint-text"><?php echo htmlspecialchars($scene['hint']); ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if (!$isFirst): ?>
                                <br/>
                                <a href="play.php?storyID=<?php echo $storyID; ?>" style="color:#ffcc15; font-weight:bold; font-size:14px">Start Over</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- End of story - no choices available -->
                        <div class="choices">
                            <br/>
                            <div class="choice">
                                <a href="play.php?storyID=<?php echo $storyID; ?>">Start Over</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </main>
            </div>

            <!-- Footer -->
            <footer>
                <p>&copy; <?php echo date("Y"); ?> <?php echo htmlspecialchars($story['created_by']); ?></p>
            </footer>
        </div>
        </div><!-- /.play-body -->
    </div>

    <script>
    /* Image-top layout: keep the banner (title + author) pinned above the sticky
       image. Feed the banner's measured height into --banner-h so the image's
       sticky top offset matches it exactly (the title may wrap on narrow screens). */
    (function () {
        if (!document.body.classList.contains('layout-image_top')) return;
        var banner = document.querySelector('.banner');
        if (!banner) return;
        function setBannerH() {
            document.documentElement.style.setProperty('--banner-h', banner.offsetHeight + 'px');
        }
        setBannerH();
        window.addEventListener('resize', setBannerH);
        if (window.ResizeObserver) new ResizeObserver(setBannerH).observe(banner);
    })();
    </script>
    <?php if ($canManage): ?>
    <script src="modal.js"></script>
    <script src="tree-view.js"></script>
    <script>
    /* Open the scene tree for this story, highlighting the scene being played. */
    (function () {
        // Apply the user's saved UI theme so the modal chrome matches the rest of
        // the app (light/dark/etc). The page itself keeps the story theme — no
        // app stylesheet is loaded here, so this only drives the modal's scoped
        // .modal-overlay theme rules in modal.css.
        var uiTheme = localStorage.getItem('cyoa-theme');
        if (uiTheme) document.documentElement.setAttribute('data-theme', uiTheme);

        var btn = document.getElementById('play-tree-btn');
        if (!btn) return;
        btn.addEventListener('click', function () {
            btn.disabled = true;
            TreeView.openModal(<?php echo (int)$storyID; ?>, { currentId: <?php echo (int)$id; ?> })
                .finally(function () { btn.disabled = false; });
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
