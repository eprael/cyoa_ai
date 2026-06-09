<?php
/**
 * Story Importer — CLI utility
 *
 * Re-creates a story exported by cli/export_story.php on THIS instance, with
 * fresh IDs. Mirrors clone_story()'s two-pass remap: create every scene first to
 * build a temp_id -> new sceneID map, then write choices with remapped
 * destinations. Image files from the bundle are copied into the new story's
 * images/stories/<newID>/ folder. The story is always imported as a DRAFT, owned
 * by the user you specify (the source userID is meaningless on a different
 * instance), so review and publish it from the app afterward.
 *
 * USAGE
 *   php cli/import_story.php <bundleDir|story.json> [--owner-email=LOGIN | --owner=ID] [--title="New Title"]
 *
 * OWNER (one of, in priority order)
 *   --owner=ID               Numeric user ID on this instance
 *   --owner-email=LOGIN      Login email of the target owner
 *   (default)                The MAIN_ADMIN account from config.php
 *
 * EXAMPLES
 *   php cli/import_story.php story_export_123_20260603_101500 --owner-email=teacher@example.com
 *   php cli/import_story.php exports/finch_manor/story.json --owner=4 --title="Finch Manor (imported)"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_functions.php';
require_once __DIR__ . '/../settings.php';

$APP_ROOT = dirname(__DIR__);

function out(string $msg): void { echo $msg . PHP_EOL; }
function die_err(string $msg): void { fwrite(STDERR, "ERROR: $msg" . PHP_EOL); exit(1); }

// ── Parse args ───────────────────────────────────────────────────────────────
$bundlePath  = '';
$ownerId     = 0;
$ownerEmail  = '';
$titleOverride = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--owner=(\d+)$/', $arg, $m)) {
        $ownerId = (int)$m[1];
    } elseif (preg_match('/^--owner-email=(.+)$/', $arg, $m)) {
        $ownerEmail = trim($m[1]);
    } elseif (preg_match('/^--title=(.*)$/', $arg, $m)) {
        $titleOverride = $m[1];
    } elseif ($arg[0] !== '-' && $bundlePath === '') {
        $bundlePath = $arg;
    } else {
        die_err("Unrecognized argument: $arg");
    }
}

if ($bundlePath === '') {
    die_err("A <bundleDir> or path to story.json is required.\nUsage: php cli/import_story.php <bundleDir|story.json> [--owner-email=LOGIN | --owner=ID]");
}

// ── Resolve the manifest + image source dir ──────────────────────────────────
if (is_dir($bundlePath)) {
    $manifestPath = rtrim($bundlePath, '/\\') . '/story.json';
    $imagesDir    = rtrim($bundlePath, '/\\') . '/images';
} else {
    $manifestPath = $bundlePath;
    $imagesDir    = dirname($bundlePath) . '/images';
}
if (!is_file($manifestPath)) {
    die_err("Manifest not found: $manifestPath");
}
$bundle = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($bundle) || empty($bundle['story']) || !isset($bundle['scenes'])) {
    die_err("Manifest is not a valid story bundle: $manifestPath");
}

// ── Resolve the owner ────────────────────────────────────────────────────────
$owner = null;
if ($ownerId > 0) {
    $owner = get_user_by_id($ownerId);
    if (!$owner) die_err("No user with ID $ownerId on this instance.");
} elseif ($ownerEmail !== '') {
    $owner = get_user_by_email($ownerEmail);
    if (!$owner) die_err("No user with email '$ownerEmail' on this instance.");
} else {
    $adminEmail = defined('MAIN_ADMIN') ? MAIN_ADMIN : '';
    $owner = $adminEmail ? get_user_by_email($adminEmail) : null;
    if (!$owner) {
        die_err("No owner specified and MAIN_ADMIN account not found.\nPass --owner-email=LOGIN or --owner=ID.");
    }
}
$ownerId   = (int)$owner['userID'];
$ownerName = trim(($owner['firstName'] ?? '') . ' ' . ($owner['lastName'] ?? ''));

// ── Create the story shell ───────────────────────────────────────────────────
$s        = $bundle['story'];
$title    = $titleOverride !== null ? $titleOverride : ($s['title'] ?? 'Imported Story');
// Keep the original author's display name when present (mirrors clone_story);
// fall back to the new owner's name.
$createdBy = ($s['created_by'] ?? '') !== '' ? $s['created_by'] : $ownerName;
$genres    = is_array($s['genre'] ?? null) ? $s['genre'] : (empty($s['genre']) ? [] : [$s['genre']]);

$newStoryID = create_story(
    $title,
    $s['description'] ?? '',
    '',                       // image set after files are copied
    $s['theme'] ?? 'forest',
    $ownerId,
    $createdBy,
    $s['layout'] ?? 'image_left',
    $genres
);
if (!$newStoryID) {
    die_err("Failed to create the story record.");
}
$newStoryID = (int)$newStoryID;

update_story_image_settings(
    $newStoryID,
    $s['ai_image_category'] ?? null,
    $s['ai_image_style']    ?? null,
    $s['ai_image_mood']     ?? null,
    $s['ai_image_quality']  ?? null
);
if (!empty($s['theme_json'])) {
    update_story_theme_json($newStoryID, $s['theme_json']);
}

// ── Prepare the new image folder ─────────────────────────────────────────────
$newDir = $APP_ROOT . '/images/stories/' . $newStoryID;
if (!is_dir($newDir) && !mkdir($newDir, 0755, true) && !is_dir($newDir)) {
    die_err("Could not create image folder: $newDir");
}

/**
 * Copy a bundled image into the new story folder. Returns the filename to store
 * on success, or '' when the source is missing/failed — so we never leave a
 * dangling image reference pointing at a file that isn't there.
 */
function import_copy_image(string $filename, string $imagesDir, string $newDir): string {
    if ($filename === '') return '';
    $src = $imagesDir . '/' . $filename;
    if (is_file($src) && copy($src, $newDir . '/' . $filename)) return $filename;
    return '';
}

$imgCopied = 0;
$imgMissing = [];

// Cover image
$coverName = import_copy_image($s['image'] ?? '', $imagesDir, $newDir);
if ($coverName !== '') {
    $imgCopied++;
    // Persist the cover filename (preserve genre so update_story doesn't null it).
    update_story($newStoryID, $title, $s['description'] ?? '', $coverName, $s['theme'] ?? 'forest', $s['layout'] ?? 'image_left', $genres);
} elseif (!empty($s['image'])) {
    $imgMissing[] = $s['image'];
}

// ── First pass: create scenes, build temp_id -> new sceneID map ──────────────
$idMap = [];
foreach ($bundle['scenes'] as $sc) {
    $tempId   = (int)($sc['temp_id'] ?? 0);
    $sceneImg = import_copy_image($sc['image'] ?? '', $imagesDir, $newDir);
    if ($sceneImg !== '') { $imgCopied++; }
    elseif (!empty($sc['image'])) { $imgMissing[] = $sc['image']; }

    $newSceneID = create_scene(
        $newStoryID,
        $sc['title']       ?? 'Untitled Scene',
        $sc['description'] ?? '',
        $sceneImg !== '' ? $sceneImg : null,
        // Drop the stored image prompt if the image itself didn't come across.
        $sceneImg !== '' ? ($sc['image_gen'] ?? null) : null,
        $sc['hint']        ?? null,
        isset($sc['enable_autoBack_nav']) ? (int)$sc['enable_autoBack_nav'] : 1
    );
    if (!$newSceneID) {
        die_err("Failed to create a scene (temp_id $tempId).");
    }
    if ($tempId) $idMap[$tempId] = (int)$newSceneID;
}

// ── Second pass: write choices with remapped destinations ────────────────────
$danglingChoices = 0;
foreach ($bundle['scenes'] as $sc) {
    $tempId = (int)($sc['temp_id'] ?? 0);
    if (!$tempId || empty($sc['choices'])) continue;
    $newChoices = [];
    foreach ($sc['choices'] as $choice) {
        $oldDest = (int)($choice['dest'] ?? 0);
        // 0 = ending (no destination). A non-zero dest must map to a known scene;
        // if not, point it at 0 rather than a wrong scene.
        $newDest = ($oldDest && isset($idMap[$oldDest])) ? $idMap[$oldDest] : 0;
        if ($oldDest && $newDest === 0) $danglingChoices++;
        $newChoices[] = ['text' => $choice['text'], 'dest' => $newDest];
    }
    save_choices($idMap[$tempId], $newChoices);
}

// ── Summary ──────────────────────────────────────────────────────────────────
out("Imported as story #$newStoryID — \"$title\" (DRAFT)");
out("  Owner:   $ownerName (#$ownerId)");
out("  Scenes:  " . count($idMap));
out("  Images:  $imgCopied copied" . (empty($imgMissing) ? '' : ', ' . count($imgMissing) . ' missing (' . implode(', ', array_unique($imgMissing)) . ')'));
if ($danglingChoices > 0) {
    out("  Note:    $danglingChoices choice(s) had an unresolved destination and were set to 'ending'.");
}
if (defined('APP_URL') && APP_URL) {
    out("  Edit:    " . rtrim(APP_URL, '/') . "/editor.php?storyID=$newStoryID");
}
