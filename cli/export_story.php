<?php
/**
 * Story Exporter — CLI utility
 *
 * Bundles one story (story row + all scenes + all choices) and its image folder
 * into a portable directory you can move to another instance and re-create with
 * cli/import_story.php. IDs are NOT preserved: each scene is written with a
 * temp_id (its original sceneID) and every choice's destination is recorded as a
 * temp_id, so the importer can remap to fresh IDs on the target. This mirrors the
 * scene/choice remap logic in clone_story().
 *
 * USAGE
 *   php cli/export_story.php <storyID> [--out=DIR]
 *
 * EXAMPLES
 *   php cli/export_story.php 123
 *   php cli/export_story.php 123 --out=exports/finch_manor
 *
 * OUTPUT (a directory)
 *   <out>/story.json        — story + scenes + choices (this is the bundle manifest)
 *   <out>/images/*          — copy of images/stories/<storyID>/ (cover + scene images)
 *
 * The default --out is ./story_export_<storyID>_<timestamp> next to this script's
 * working directory. Move the whole directory to the target instance, then run
 * cli/import_story.php on it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_functions.php';
require_once __DIR__ . '/../settings.php';

const BUNDLE_VERSION = 1;
$APP_ROOT = dirname(__DIR__);   // project root (one level up from /cli)

function out(string $msg): void { echo $msg . PHP_EOL; }
function die_err(string $msg): void { fwrite(STDERR, "ERROR: $msg" . PHP_EOL); exit(1); }

// ── Parse args ───────────────────────────────────────────────────────────────
$storyID = 0;
$outDir  = '';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $outDir = $m[1];
    } elseif (ctype_digit(ltrim($arg, '-')) && $storyID === 0) {
        $storyID = (int)$arg;
    } else {
        die_err("Unrecognized argument: $arg");
    }
}

if ($storyID <= 0) {
    die_err("A numeric <storyID> is required.\nUsage: php cli/export_story.php <storyID> [--out=DIR]");
}

// ── Load the story ───────────────────────────────────────────────────────────
$story = get_story($storyID);
if (!$story) {
    die_err("Story #$storyID not found.");
}

$scenes = get_scenes_with_choices_by_story($storyID);

// ── Resolve the source image folder (shadow-draft aware) ─────────────────────
// A shadow draft can still reference the published story's image folder, so the
// images for THIS storyID may live under its published parent's folder.
$pubStoryID = !empty($story['published_story_id']) ? (int)$story['published_story_id'] : 0;
$ownFolder  = $APP_ROOT . '/images/stories/' . $storyID;
$pubFolder  = $pubStoryID ? $APP_ROOT . '/images/stories/' . $pubStoryID : '';

/** Find an image file in the draft's own folder first, then the published parent's. */
function locate_image(string $filename, string $ownFolder, string $pubFolder): ?string {
    if ($filename === '') return null;
    if (is_file($ownFolder . '/' . $filename)) return $ownFolder . '/' . $filename;
    if ($pubFolder !== '' && is_file($pubFolder . '/' . $filename)) return $pubFolder . '/' . $filename;
    return null;
}

// ── Build the bundle manifest ────────────────────────────────────────────────
$bundleScenes = [];
foreach ($scenes as $sp) {
    $bundleScenes[] = [
        'temp_id'             => (int)$sp['sceneID'],   // original ID = temp id for remap
        'title'               => $sp['title'],
        'description'         => $sp['description'],
        'image'               => $sp['image'] ?? '',
        'image_gen'           => $sp['image_gen'] ?? '',
        'hint'                => $sp['hint'] ?? '',
        'enable_autoBack_nav' => isset($sp['enable_autoBack_nav']) ? (int)$sp['enable_autoBack_nav'] : 1,
        'choices'             => array_map(function ($c) {
            // dest is the original destination sceneID (or 0 for an ending).
            return ['text' => $c['text'], 'dest' => (int)$c['dest']];
        }, $sp['choices'] ?? []),
    ];
}

$bundle = [
    'bundle_version' => BUNDLE_VERSION,
    'exported_at'    => date('c'),
    'source_app_url' => defined('APP_URL') ? APP_URL : '',
    'source_story_id'=> $storyID,
    'story' => [
        'title'             => $story['title'],
        'description'       => $story['description'],
        'genre'             => $story['genre'] ?? [],      // already decoded to an array
        'theme'             => $story['theme'],
        'theme_json'        => $story['theme_json'] ?? null,
        'layout'            => $story['layout'],
        'image'             => $story['image'] ?? '',
        'ai_image_category' => $story['ai_image_category'] ?? '',
        'ai_image_style'    => $story['ai_image_style'] ?? '',
        'ai_image_mood'     => $story['ai_image_mood'] ?? '',
        'ai_image_quality'  => $story['ai_image_quality'] ?? '',
        'created_by'        => $story['created_by'] ?? '',
        'date_created'      => $story['date_created'] ?? '',
    ],
    'scenes' => $bundleScenes,
];

// ── Prepare the output directory ─────────────────────────────────────────────
if ($outDir === '') {
    $outDir = getcwd() . '/story_export_' . $storyID . '_' . date('Ymd_His');
}
$imagesOut = $outDir . '/images';
if (!is_dir($imagesOut) && !mkdir($imagesOut, 0755, true) && !is_dir($imagesOut)) {
    die_err("Could not create output directory: $imagesOut");
}

// ── Copy image files (cover + every scene image we can locate) ───────────────
$copied = 0;
$missing = [];
$imageNames = [];
if (!empty($bundle['story']['image'])) $imageNames[] = $bundle['story']['image'];
foreach ($bundleScenes as $bs) {
    if (!empty($bs['image'])) $imageNames[] = $bs['image'];
}
foreach (array_unique($imageNames) as $name) {
    $src = locate_image($name, $ownFolder, $pubFolder);
    if ($src === null) { $missing[] = $name; continue; }
    if (copy($src, $imagesOut . '/' . $name)) {
        $copied++;
    } else {
        $missing[] = $name;
    }
}

// ── Write the manifest ───────────────────────────────────────────────────────
$jsonPath = $outDir . '/story.json';
$json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($jsonPath, $json) === false) {
    die_err("Failed to write manifest: $jsonPath");
}

// ── Summary ──────────────────────────────────────────────────────────────────
out("Exported story #$storyID — \"" . $story['title'] . "\"");
out("  Scenes:  " . count($bundleScenes));
out("  Images:  $copied copied" . (empty($missing) ? '' : ', ' . count($missing) . ' missing (' . implode(', ', $missing) . ')'));
out("  Bundle:  $outDir");
out("");
out("Move that directory to the target instance, then run:");
out("  php cli/import_story.php " . escapeshellarg($outDir) . " --owner-email=<login@example.com>");
