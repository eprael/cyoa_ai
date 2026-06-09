<?php
/**
 * Create Story with AI — AJAX endpoint
 *
 * Creates a draft story record immediately, then queues a 'create_story' AI job.
 * The job handler fills title/description/theme and generates all scenes.
 *
 * POST params:
 *   premise        string  required  Story concept / premise
 *   genre          string  optional  fantasy|scifi|mystery|horror|romance|adventure
 *   tone           string  optional  suspenseful|hopeful|dark|humorous|neutral
 *   target_scenes  int     optional  6–20
 *   num_endings    int     optional  1–5
 *   word_length    int     optional  50–300 words per scene
 *   gen_title      bool    1/0  AI generates title (else use premise as placeholder)
 *   gen_description bool   1/0  AI generates description
 *   theme          string  optional  Context value when AI generates theme
 *   layout         string  optional  Context value for layout
 *
 * Returns JSON: {ok:true, storyID:N, jobID:N} or {ok:false, error:"..."}
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';
require_once 'fonts.php';   // Phase 41 — play-font allow-list
require_once 'theme.php';   // Phase 42 — theme engine (theme_to_json, presets)

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required.']);
    exit;
}

if (!(bool)(int) app_setting('ai_enabled')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'AI features are disabled.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

$userID    = (int)$_SESSION['userID'];
$userRow   = get_user_by_id($userID);
$createdBy = $userRow ? trim($userRow['firstName'] . ' ' . $userRow['lastName']) : '';

// Rate limit
$maxPending = (int) app_setting('ai_max_pending_per_user');
if (count_pending_jobs_by_user($userID) >= $maxPending) {
    echo json_encode(['ok' => false, 'error' => "You already have $maxPending active jobs. Wait for some to finish before submitting more."]);
    exit;
}

$premise = trim($_POST['premise'] ?? '');

// User-supplied title/description (present only when the matching "use AI" box is
// OFF — an AI-checked field is disabled client-side and thus not submitted). These
// are the user's own values to keep when the AI isn't generating that field.
$userTitle       = trim($_POST['title'] ?? '');
$userDescription = trim($_POST['description'] ?? '');

// Allow-lists for validation. genre/tone/audience (incl. "Any" → random) are
// resolved by ai_resolve_story_params below; layout/quality are validated inline.
$allowedGenres         = json_decode(app_setting('story_genres') ?? '[]', true) ?: [];
$allowedLayouts        = ['image_left', 'image_right', 'image_top'];
$allowedImageQualities = ['none', 'low', 'medium', 'high'];

$targetScenes  = max(6, min(20, (int)($_POST['target_scenes'] ?? 12)));
$numEndings    = max(1, min(5,  (int)($_POST['num_endings']   ?? 2)));
$wordLength    = max(50, min(300, (int)($_POST['word_length'] ?? 150)));
$imageQuality  = in_array($_POST['image_quality'] ?? '', $allowedImageQualities, true) ? $_POST['image_quality'] : 'medium';
$includeImages = !empty($_POST['ai-include-images']);
$imageCategory = trim($_POST['ai-image-category'] ?? '');
$imageStyle    = trim($_POST['ai-image-style']    ?? '');
$imageMood     = trim($_POST['ai-image-mood']     ?? '');
// "Any" image style (blank) → pick one random style for the whole story, in code,
// so every image shares a consistent look. "Skip" mood stays blank (no modifier).
if ($includeImages && $imageStyle === '') {
    $imageStyle = ai_random_image_style();
}

// Provider-key availability (user BYOK overrides the site key). Story text needs a
// Claude key; images additionally need an OpenAI key. Mirrors the editor's badge
// gating so a doomed job can't be queued via a crafted request.
if (!ai_provider_available('claude', $userRow)) {
    echo json_encode(['ok' => false, 'error' => 'Story generation needs a Claude (Anthropic) API key. Add one in your account settings.']);
    exit;
}
if ($includeImages && !ai_provider_available('openai', $userRow)) {
    echo json_encode(['ok' => false, 'error' => 'Image generation needs an OpenAI API key. Add one in your account settings, or turn off “Include images”.']);
    exit;
}
$genTitle       = !empty($_POST['gen_title']);
$genDescription = !empty($_POST['gen_description']);
$genTheme       = !empty($_POST['gen_theme']);
// Auto-publish when the AI finishes (checkbox; checked by default in the form).
$publish        = !empty($_POST['publish']);
// Theme (manual / "use AI" off): accept any preset slug ('custom' too); the legacy
// `theme` column gets a valid preset key, and a sanitized theme_json is built from
// the editor's font/colour fields so the user's exact choice is applied (Phase 42).
$themeSlug     = $_POST['theme'] ?? '';
$contextTheme  = isset(theme_presets()[$themeSlug]) ? $themeSlug : theme_default_key();
$contextThemeJson = theme_to_json([
    'font'         => $_POST['theme_font']         ?? '',
    'font_heading' => $_POST['theme_font_heading'] ?? '',
    'bg'           => $_POST['theme_bg']           ?? '',
    'text'         => $_POST['theme_text']         ?? '',
    'accent'       => $_POST['theme_accent']       ?? '',
], $contextTheme);
$contextLayout = in_array($_POST['layout'] ?? '', $allowedLayouts, true)       ? $_POST['layout']         : 'image_left';

// Genre / tone / audience / premise resolution. "Any" (a blank value) becomes a
// concrete random value in code (ai_resolve_story_params). A blank premise is
// filled from a seed; an "Any" genre draws from the WHOLE premise list and takes
// the genre from the seed.
// When "use AI for genres" is OFF and the user picked genre chips, the primary chip
// is the genre hint and all chips are stored; otherwise the AI-bar Genre dropdown
// drives it ('' = Any).
$genGenres    = !empty($_POST['gen_genres']);
$manualGenres = json_decode($_POST['genres'] ?? '[]', true);
if (!is_array($manualGenres)) $manualGenres = [];
$manualGenres = array_values(array_filter($manualGenres, fn($g) => in_array($g, $allowedGenres, true)));
$genreInput   = (!$genGenres && !empty($manualGenres)) ? $manualGenres[0] : ($_POST['genre'] ?? '');

$resolved = ai_resolve_story_params([
    'premise'  => $premise,
    'genre'    => $genreInput,
    'tone'     => $_POST['tone']        ?? '',
    'audience' => $_POST['ai-audience'] ?? '',
]);
$premise  = $resolved['premise'];
$genre    = $resolved['genre'];
$tone     = $resolved['tone'];
$audience = $resolved['audience'];

$storedGenres = (!$genGenres && !empty($manualGenres)) ? $manualGenres : [$genre];

// Create a draft story placeholder so we have a storyID for the job.
// When the AI isn't generating the title/description, seed the story with the
// user's own values (the apply phase keeps these when gen_title/gen_description
// are off). Fall back to the premise only if the user left the title blank.
$placeholderTitle = $genTitle
    ? '(AI generating title…)'
    : mb_substr($userTitle !== '' ? $userTitle : $premise, 0, 255);
$initialDescription = $genDescription ? '' : $userDescription;
$storyID = create_story(
    $placeholderTitle,
    $initialDescription,
    '',
    $contextTheme,
    $userID,
    $createdBy,
    $contextLayout,
    $storedGenres   // manual genre chips, or the AI-settings genre (Phase 28 / genres "use AI")
);

if (!$storyID) {
    echo json_encode(['ok' => false, 'error' => 'Failed to create story record.']);
    exit;
}

$inputJson = json_encode([
    'premise'         => $premise,
    'genre'           => $genre,
    'tone'            => $tone,
    'target_scenes'   => $targetScenes,
    'num_endings'     => $numEndings,
    'word_length'     => $wordLength,
    'image_quality'   => $imageQuality,
    'audience'        => $audience,
    'include_images'  => $includeImages,
    'image_category'  => $imageCategory,
    'image_style'     => $imageStyle,
    'image_mood'      => $imageMood,
    'gen_title'       => $genTitle,
    'gen_description' => $genDescription,
    'gen_theme'         => $genTheme,
    'context_theme'     => $contextTheme,
    'context_theme_json'=> $contextThemeJson,
    'context_layout'    => $contextLayout,
    'publish'           => $publish,
]);

$jobID = create_ai_job($userID, $storyID, null, 'story', $inputJson);

if (!$jobID) {
    // Roll back the story we just created so there's no orphan
    delete_story($storyID);
    echo json_encode(['ok' => false, 'error' => 'Failed to queue AI job.']);
    exit;
}

echo json_encode(['ok' => true, 'storyID' => $storyID, 'jobID' => $jobID]);
