<?php
/**
 * Batch Story Creator — CLI utility
 *
 * Simulates api_create_story_ai.php from the command line: creates a draft
 * story placeholder and queues a 'story' AI job for each entry.  The
 * existing dispatcher/worker pipeline handles the rest.
 *
 * EXAMPLES
 *
 *   # One fully AI-generated story (everything randomized/AI):
 *   php cli/create_stories.php --email=author@example.com
 *
 *   # 5 stories, EVERYTHING randomized (like the UI "let the AI invent it all"):
 *   #   random genre/tone/theme per story, a genre-matched premise from the seed
 *   #   file, and AI-generated title, description, and a custom theme.
 *   php cli/create_stories.php --email=author@example.com --count=5
 *
 *   # 5 stories with SOME parts fixed and the rest randomized:
 *
 *   #   all Sci-Fi + dark tone + no images (premises/titles/themes still AI):
 *   php cli/create_stories.php --email=author@example.com --count=5 --genre=Sci-Fi --tone=dark --images=none
 *
 *   #   all for young adults, 8 scenes each, pinned "cyberpunk" theme (no AI theme):
 *   php cli/create_stories.php --email=author@example.com --count=5 --audience=young_adults --scenes=8 --theme=cyberpunk --no-gen-theme
 *
 *   # One story from a specific premise (genre fixed, everything else AI):
 *   php cli/create_stories.php --email=author@example.com --premise="A knight faces a choice" --genre=Fantasy
 *
 *   # Batch from a JSON file with per-story overrides (sample provided):
 *   php cli/create_stories.php --email=author@example.com --file=cli/sample_stories.json
 *
 * SEED DATA — data/premises.json
 *   Whenever a story's premise is blank (no --premise / no "premise" key), a random
 *   seed is pulled from data/premises.json. With an explicit --genre the pool is
 *   filtered to that genre; with genre "Any" (the default) the WHOLE list is in play
 *   and the genre is taken from the chosen seed. The seed also supplies a default
 *   tone. Within one run/file, seeds are picked WITHOUT replacement so a batch
 *   doesn't generate near-duplicate stories. Passing an explicit premise skips the
 *   seed file for that story.
 *
 * JSON file format — array of objects; EVERY key is optional, and any omitted
 * key is randomized/defaulted exactly as it would be on the command line.
 * Recognized keys (gen_* are booleans; the rest are strings/numbers):
 *   premise, genre, tone, audience, scenes, endings, words, images,
 *   image-category, image-style, image-mood, theme, layout,
 *   gen_title, gen_desc, gen_theme, publish
 *   [
 *     {},                                                  // fully AI / randomized
 *     {"genre":"Horror","tone":"dark","images":"high"},
 *     {"premise":"A lighthouse keeper hears knocking from below","gen_title":false}
 *   ]
 * See cli/sample_stories.json for a fuller example.
 *
 * TIP: run `php cli/create_stories.php --list` to print every valid genre,
 * theme, audience, image style, etc. Value matching is case-insensitive, so
 * --genre=sci-fi and --genre=Sci-Fi both work; a typo that matches nothing logs
 * a warning and falls back rather than failing silently.
 *
 * Options:
 *   --list            Print all valid option values (genres/themes/styles…) and exit
 *   --email=LOGIN     Login email of the user that owns the created stories
 *   --user-id=N       Numeric user ID (alternative to --email; one is required)
 *   --count=N         Number of stories to create (default: 1; ignored with --file)
 *   --premise=TEXT    Story concept; blank = AI invents everything (genre-matched seed)
 *   --genre=GENRE     One of the admin story_genres values (e.g. Fantasy, Sci-Fi).  (default: Any — seed drawn from the WHOLE premise list, genre taken from the seed)
 *   --tone=TONE       suspenseful|hopeful|dark|humorous|neutral        (default: Any — random, or the seed's tone for a blank premise)
 *   --audience=KEY    Audience key from data/audiences.json            (default: Any — random)
 *   --scenes=N        Target scene count 6–20                          (default: 12)
 *   --endings=N       Number of endings 1–5                            (default: 2)
 *   --words=N         Words per scene 50–300                           (default: 100)
 *   --images=QUALITY  none|low|medium|high                             (default: medium)
 *   --image-style=TEXT     Optional image sub-style passed to the image jobs (see --list)
 *   --image-mood=TEXT      Optional image mood passed to the image jobs (see --list)
 *   --image-category=TEXT  Rarely needed — only a fallback when no --image-style is
 *                          given; it's really just the UI's style grouping
 *   --theme=THEME     A theme preset key (see data/themes.json)        (default: random)
 *   --layout=LAYOUT   image_left|image_right|image_top               (default: image_left)
 *   --gen-title       Let AI generate the title  (default: yes)
 *   --no-gen-title    Use premise as title instead
 *   --gen-desc        Let AI generate the description (default: yes)
 *   --no-gen-desc     Keep description blank
 *   --gen-theme       Let AI design a custom theme (default: yes)
 *   --no-gen-theme    Use the --theme preset instead of an AI-designed theme
 *   --publish         Auto-publish each story when the AI finishes (default: yes)
 *   --no-publish      Leave each story as a draft for review
 */

if (PHP_SAPI !== 'cli') {
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_functions.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../fonts.php';   // Phase 41 — play-font allow-list
require_once __DIR__ . '/../theme.php';   // Phase 42 — theme engine (theme_presets, theme_to_json)

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function cli_log(string $msg): void {
    echo date('H:i:s') . '  ' . $msg . PHP_EOL;
}

function usage(): void {
    echo "Usage: php cli/create_stories.php (--email=LOGIN | --user-id=N) [options]\n";
    echo "       php cli/create_stories.php --email=LOGIN --file=stories.json\n";
    echo "       php cli/create_stories.php --list   (show valid genres/themes/styles)\n";
    echo "Run with just an owner to create one fully AI-generated story.\n";
    exit(1);
}

/**
 * Print every accepted option value, pulled live from the same sources the web
 * app uses (settings + data/*.json), so the spelling/casing is always exact.
 * Input matching is case-insensitive (see pick_value), but this shows the
 * canonical form that gets stored.
 */
function print_value_lists(): void {
    global $ALLOWED_GENRES, $ALLOWED_TONES, $ALLOWED_THEMES, $ALLOWED_LAYOUTS, $ALLOWED_IMAGES;

    echo "Valid option values (input is case-insensitive; canonical form shown):\n\n";

    echo "GENRES  (--genre)\n  " . implode("\n  ", $ALLOWED_GENRES) . "\n\n";
    echo "TONES   (--tone)\n  " . implode("\n  ", $ALLOWED_TONES) . "\n\n";

    echo "AUDIENCES  (--audience)  [use the key on the left]\n";
    foreach (story_audiences() as $key => $info) {
        echo "  " . str_pad($key, 16) . ' — ' . ($info['label'] ?? '') . "\n";
    }
    echo "\n";

    echo "THEMES  (--theme)\n  " . implode("\n  ", $ALLOWED_THEMES) . "\n\n";
    echo "LAYOUTS (--layout)\n  " . implode("\n  ", $ALLOWED_LAYOUTS) . "\n\n";
    echo "IMAGE QUALITY (--images)\n  " . implode(', ', $ALLOWED_IMAGES) . "\n\n";

    $moods = json_decode(app_setting('image_moods') ?? '[]', true) ?: [];
    echo "IMAGE MOOD (--image-mood, optional)\n  " . implode("\n  ", $moods) . "\n\n";

    $styles = json_decode(app_setting('image_styles') ?? '{}', true) ?: [];
    echo "IMAGE STYLE (--image-style, optional)\n";
    echo "  (--image-category is just the UI grouping below; you can ignore it)\n";
    foreach ($styles as $category => $list) {
        echo "  [$category]\n";
        foreach ($list as $style) echo "      $style\n";
    }
}

/**
 * Resolve a user-supplied value against an allow-list, case-insensitively, and
 * return the canonical casing. Blank input falls back silently; a non-blank value
 * that matches nothing logs a warning and falls back, so typos are visible rather
 * than silently swapped. $fallback may be a value or a callable (e.g. random pick).
 */
function pick_value(string $raw, array $allowed, $fallback, string $label): string {
    $raw = trim($raw);
    if ($raw !== '') {
        foreach ($allowed as $a) {
            if (strcasecmp($raw, $a) === 0) return $a;   // canonical casing
        }
        cli_log("  ! unknown $label '$raw' — falling back" . (is_callable($fallback) ? ' to a random value' : " to '$fallback'"));
    }
    return is_callable($fallback) ? $fallback() : $fallback;
}

// Allowed value sets are derived from the same sources the web app uses, so the
// CLI stays in sync as genres/themes/audiences change (no hardcoded drift).
$ALLOWED_GENRES = json_decode(app_setting('story_genres') ?? '[]', true) ?: [];
// "Other" is a catch-all with no seeds — exclude it from random selection so
// blank-premise batches still get a genre-matched seed.
$RANDOM_GENRES   = array_values(array_filter($ALLOWED_GENRES, fn($g) => strcasecmp($g, 'Other') !== 0));
$ALLOWED_TONES   = ['suspenseful','hopeful','dark','humorous','neutral'];
$ALLOWED_THEMES  = array_keys(theme_presets());
$ALLOWED_LAYOUTS = ['image_left','image_right','image_top']; // matches the editor UI
$ALLOWED_IMAGES  = ['none','low','medium','high'];
$ALLOWED_AUDIENCES = array_keys(story_audiences());

function random_pick(array $arr): string {
    return $arr[array_rand($arr)];
}
// Seed selection + "Any" resolution now live in db_functions.php
// (ai_pick_seed / ai_resolve_story_params), shared with the web endpoint.

// ---------------------------------------------------------------------------
// Parse CLI args
// ---------------------------------------------------------------------------

$opts = getopt('', [
    'user-id:', 'email:', 'count:', 'premise:', 'genre:', 'tone:', 'audience:',
    'scenes:', 'endings:', 'words:', 'images:', 'theme:', 'layout:',
    'image-category:', 'image-style:', 'image-mood:',
    'file:', 'gen-title', 'no-gen-title', 'gen-desc', 'no-gen-desc',
    'gen-theme', 'no-gen-theme', 'publish', 'no-publish', 'list',
]);

// --list: print every valid option value (exact casing) and exit. Handy for
// genres/themes/styles where the spelling/casing has to match.
if (isset($opts['list'])) {
    print_value_lists();
    exit(0);
}

// Identify the story owner by login email (--email) or numeric ID (--user-id).
if (!empty($opts['email'])) {
    $loginEmail = trim($opts['email']);
    $user       = get_user_by_email($loginEmail);
    if (!$user) {
        echo "Error: no user found with login email '$loginEmail'\n";
        exit(1);
    }
} elseif (!empty($opts['user-id'])) {
    $user = get_user_by_id((int)$opts['user-id']);
    if (!$user) {
        echo "Error: user #" . (int)$opts['user-id'] . " not found\n";
        exit(1);
    }
} else {
    echo "Error: provide --email=LOGIN or --user-id=N\n";
    usage();
}

$userID    = (int)$user['userID'];
$createdBy = trim($user['firstName'] . ' ' . $user['lastName']);

// ---------------------------------------------------------------------------
// Build job configs
// ---------------------------------------------------------------------------

if (!empty($opts['file'])) {
    $filePath = $opts['file'];
    if (!file_exists($filePath)) {
        echo "Error: file not found: $filePath\n";
        exit(1);
    }
    $configs = json_decode(file_get_contents($filePath), true);
    if (!is_array($configs)) {
        echo "Error: file must contain a JSON array\n";
        exit(1);
    }
} else {
    $count   = max(1, (int)($opts['count'] ?? 1));
    $configs = array_fill(0, $count, []);

    // Spread CLI overrides into every config slot
    $cliOverrides = [];
    foreach (['premise','genre','tone','audience','scenes','endings','words','images','theme','layout',
              'image-category','image-style','image-mood'] as $k) {
        if (isset($opts[$k])) $cliOverrides[$k] = $opts[$k];
    }
    if (isset($opts['gen-title']))    $cliOverrides['gen_title']  = true;
    if (isset($opts['no-gen-title'])) $cliOverrides['gen_title']  = false;
    if (isset($opts['gen-desc']))     $cliOverrides['gen_desc']   = true;
    if (isset($opts['no-gen-desc']))  $cliOverrides['gen_desc']   = false;
    if (isset($opts['gen-theme']))    $cliOverrides['gen_theme']  = true;
    if (isset($opts['no-gen-theme'])) $cliOverrides['gen_theme']  = false;
    if (isset($opts['publish']))      $cliOverrides['publish']    = true;
    if (isset($opts['no-publish']))   $cliOverrides['publish']    = false;

    $configs = array_map(fn($c) => array_merge($c, $cliOverrides), $configs);
}

// ---------------------------------------------------------------------------
// Resolve each config into validated job params
// ---------------------------------------------------------------------------

function resolve_config(array $cfg, array $sets, array &$usedPremises = []): array {
    // Canonicalize any explicitly-provided choice (case-insensitive); a blank or
    // unrecognized value becomes '' = "Any", which the resolver randomizes.
    $genreIn = pick_value($cfg['genre']    ?? '', $sets['genres'],    '', 'genre');
    $toneIn  = pick_value($cfg['tone']     ?? '', $sets['tones'],     '', 'tone');
    $audIn   = pick_value($cfg['audience'] ?? '', $sets['audiences'], '', 'audience');

    $theme        = pick_value($cfg['theme']  ?? '', $sets['themes'],  fn() => random_pick($sets['themes']), 'theme');
    $layout       = pick_value($cfg['layout'] ?? '', $sets['layouts'], 'image_left', 'layout');
    $imageQuality = pick_value($cfg['images'] ?? '', $sets['images'],  'medium',     'images');
    $targetScenes = max(6,  min(20,  (int)($cfg['scenes']  ?? 12)));
    $numEndings   = max(1,  min(5,   (int)($cfg['endings'] ?? 2)));
    $wordLength   = max(50, min(300, (int)($cfg['words']   ?? 100)));

    // "Any" → random in code; a blank premise is filled from a seed (the WHOLE
    // premise list when genre is Any). $usedPremises de-dupes seeds across the batch.
    $r = ai_resolve_story_params([
        'premise'  => $cfg['premise'] ?? '',
        'genre'    => $genreIn,
        'tone'     => $toneIn,
        'audience' => $audIn,
    ], $usedPremises);
    $premise  = $r['premise'];
    $genre    = $r['genre'];
    $tone     = $r['tone'];
    $audience = $r['audience'];
    if ($premise !== '') $usedPremises[] = $premise;

    $genTitle = $cfg['gen_title'] ?? true;
    $genDesc  = $cfg['gen_desc']  ?? true;
    $genTheme = $cfg['gen_theme'] ?? true;
    $publish  = $cfg['publish']   ?? true;   // auto-publish when done (matches the UI default)

    $imageCategory = trim($cfg['image-category'] ?? '');
    $imageStyle    = trim($cfg['image-style']    ?? '');
    $imageMood     = trim($cfg['image-mood']     ?? '');

    // Blank image style (with images enabled) → one random style for the whole story
    // so every image shares a consistent look (matches the UI's "Any" behaviour).
    if ($imageStyle === '' && $imageQuality !== 'none') {
        $imageStyle = ai_random_image_style();
    }

    return compact('premise','genre','tone','audience','theme','layout','imageQuality',
                   'imageCategory','imageStyle','imageMood',
                   'targetScenes','numEndings','wordLength','genTitle','genDesc','genTheme','publish');
}

// ---------------------------------------------------------------------------
// Create stories
// ---------------------------------------------------------------------------

$sets = [
    'genres'        => $ALLOWED_GENRES,
    'random_genres' => !empty($RANDOM_GENRES) ? $RANDOM_GENRES : ($ALLOWED_GENRES ?: ['Adventure']),
    'tones'         => $ALLOWED_TONES,
    'themes'        => $ALLOWED_THEMES,
    'layouts'       => $ALLOWED_LAYOUTS,
    'images'        => $ALLOWED_IMAGES,
    'audiences'     => $ALLOWED_AUDIENCES,
];

$total   = count($configs);
$created = 0;
$failed  = 0;

cli_log("Creating $total stor" . ($total === 1 ? 'y' : 'ies') . " for user #{$userID} ({$createdBy})...");

// Tracks seed premises used in this batch so the resolver avoids reusing them.
$usedPremises = [];

foreach ($configs as $i => $cfg) {
    $p = resolve_config($cfg, $sets, $usedPremises);

    $placeholderTitle = $p['genTitle']
        ? '(AI generating title…)'
        : (mb_substr($p['premise'], 0, 255) ?: '(untitled)');

    $storyID = create_story(
        $placeholderTitle,
        '',
        '',
        $p['theme'],
        $userID,
        $createdBy,
        $p['layout'],
        [$p['genre']]   // store the genre tag at creation (apply preserves it)
    );

    if (!$storyID) {
        cli_log("  [" . ($i + 1) . "/$total] FAILED to create story record");
        $failed++;
        continue;
    }

    // When the AI is not designing the theme, capture the chosen preset as a
    // sanitized theme_json so apply_create_story_result applies the exact preset.
    $contextThemeJson = $p['genTheme']
        ? null
        : theme_to_json(theme_preset($p['theme']), $p['theme']);

    $inputJson = json_encode([
        'premise'            => $p['premise'],
        'genre'              => $p['genre'],
        'tone'               => $p['tone'],
        'audience'           => $p['audience'],
        'target_scenes'      => $p['targetScenes'],
        'num_endings'        => $p['numEndings'],
        'word_length'        => $p['wordLength'],
        'image_quality'      => $p['imageQuality'],
        'include_images'     => ($p['imageQuality'] !== 'none'),
        'image_category'     => $p['imageCategory'],
        'image_style'        => $p['imageStyle'],
        'image_mood'         => $p['imageMood'],
        'gen_title'          => $p['genTitle'],
        'gen_description'    => $p['genDesc'],
        'gen_theme'          => $p['genTheme'],
        'context_theme'      => $p['theme'],
        'context_theme_json' => $contextThemeJson,
        'context_layout'     => $p['layout'],
        'publish'            => $p['publish'],
    ]);

    $jobID = create_ai_job($userID, $storyID, null, 'story', $inputJson);

    if (!$jobID) {
        delete_story($storyID);
        cli_log("  [" . ($i + 1) . "/$total] FAILED to queue job (story #$storyID deleted)");
        $failed++;
        continue;
    }

    $desc = $p['premise'] ? '"' . mb_substr($p['premise'], 0, 60) . '"' : '(AI premise)';
    cli_log("  [" . ($i + 1) . "/$total] story #$storyID → job #$jobID  {$p['genre']} / {$p['tone']}  $desc");
    $created++;
}

cli_log("Done. $created queued, $failed failed.");
if ($created > 0) {
    cli_log("Run the dispatcher to start processing: php cron/ai_dispatcher.php");
}
