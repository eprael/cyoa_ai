<?php
/**
 * AI Story Handler — Claude API full story generation
 *
 * Two-phase pipeline:
 *   Phase 1: Generate story plan (structure + scene list + choice graph)
 *   Phase 2: Write full content for each scene individually
 *
 * Called by ai_worker.php for job_type = 'full_story'.
 * Reuses claude_api_call() defined in ai_scene_handler.php.
 *
 * @param array $job   Full job row from the database
 * @param array $input Decoded input_json
 * @return array       {story:{title,description,theme}, scenes:[{temp_id,title,description,hint,image_prompt,choices:[{text,dest_temp_id}]}]}
 * @throws Exception   On any API or validation failure
 */
// Phase 42 — theme engine (font allow-list + theme sanitization) for plan validation.
require_once __DIR__ . '/../fonts.php';
require_once __DIR__ . '/../theme.php';

function process_full_story_job(array $job, array $input): array {
    if (!(bool)(int) app_setting('ai_enabled')) {
        throw new Exception('AI features are disabled.');
    }

    $user   = get_user_by_id((int)$job['user_id']);
    $apiKey = ($user && !empty($user['claude_api_key']))
        ? $user['claude_api_key']
        : app_setting('anthropic_api_key');

    if (empty($apiKey)) {
        throw new Exception('No Anthropic API key configured. Add one in Account Settings or ask the site admin to set a site-wide key.');
    }

    $resolved = resolve_story_premise($input);
    $premise  = $resolved['premise'];
    $genre    = $resolved['genre'];
    $tone     = $resolved['tone'];
    $premiseMeta = story_premise_meta($resolved);
    $targetScenes = max(6, min(20, (int)($input['target_scenes'] ?? 12)));
    $numEndings   = max(1, min(5,  (int)($input['num_endings']   ?? 2)));
    $wordLength   = max(50, min(300, (int)($input['word_length'] ?? 100)));
    $audience     = $input['audience'] ?? 'middle_grade';

    // Phase 1: Generate the story plan
    if (function_exists('worker_log')) {
        worker_log("Full story Phase 1: generating story plan...");
    }
    $usageAccum = [];
    $plan = generate_story_plan($apiKey, $premise, $genre, $tone, $targetScenes, $numEndings, $audience, (int)$job['job_id'], $usageAccum);

    // Phase 2: Write full content for each scene
    $sceneCount    = count($plan['scenes']);
    $writtenScenes = [];
    foreach ($plan['scenes'] as $i => $planScene) {
        if (function_exists('worker_log')) {
            worker_log("Full story Phase 2: writing scene " . ($i + 1) . "/{$sceneCount} ({$planScene['temp_id']})...");
        }
        $content         = write_scene_content($apiKey, $plan, $planScene, $tone, $wordLength, $audience, (int)$job['job_id'], $usageAccum);
        $writtenScenes[] = array_merge($planScene, $content);
    }

    $inputTokens  = $usageAccum['input_tokens']  ?? 0;
    $outputTokens = $usageAccum['output_tokens'] ?? 0;
    $costUsd = (($inputTokens  / 1_000_000) * AI_COST_INPUT_PER_M)
             + (($outputTokens / 1_000_000) * AI_COST_OUTPUT_PER_M);
    db_update_job_cost((int)$job['job_id'], $inputTokens, $outputTokens, 0, $costUsd);

    return [
        'story'  => [
            'title'       => $plan['title'],
            'description' => $plan['description'],
            'theme'       => $plan['suggested_theme'],
            'theme_json'  => $plan['theme_json'] ?? null,
        ],
        'scenes' => $writtenScenes,
        'meta'   => $premiseMeta,
    ];
}

// ------------------------------------------------------------------
// process_create_story_job — used by 'create_story' job type
// Story record already exists; this fills/replaces its content.
// ------------------------------------------------------------------

/**
 * Process a 'create_story' job.
 *
 * The story record was created as a draft by api_create_story_ai.php
 * before this job was queued.  This handler:
 *   Phase 1: Generate the branching story plan (includes AI title + description)
 *   Phase 2: Write full scene content for each planned scene
 *
 * The apply phase (apply_create_story_result) then writes everything to DB.
 *
 * @param array $job   Full job row from the database (story_id is already set)
 * @param array $input Decoded input_json: premise, genre, tone, target_scenes,
 *                     num_endings, word_length, gen_title, gen_description,
 *                     context_theme, context_layout
 * @return array       {story:{title,description,theme,layout,gen_title,gen_description},
 *                      scenes:[...same as full_story...]}
 * @throws Exception   On any API or validation failure
 */
function process_create_story_job(array $job, array $input): array {
    if (!(bool)(int) app_setting('ai_enabled')) {
        throw new Exception('AI features are disabled.');
    }

    $user   = get_user_by_id((int)$job['user_id']);
    $apiKey = ($user && !empty($user['claude_api_key']))
        ? $user['claude_api_key']
        : app_setting('anthropic_api_key');

    if (empty($apiKey)) {
        throw new Exception('No Anthropic API key configured. Add one in Account Settings or ask the site admin to set a site-wide key.');
    }

    $resolved = resolve_story_premise($input);
    $premise  = $resolved['premise'];
    $genre    = $resolved['genre'];
    $tone     = $resolved['tone'];
    $premiseMeta = story_premise_meta($resolved);
    $targetScenes = max(6, min(20, (int)($input['target_scenes'] ?? 12)));
    $numEndings   = max(1, min(5,  (int)($input['num_endings']   ?? 2)));
    $wordLength   = max(50, min(300, (int)($input['word_length'] ?? 150)));
    $audience       = $input['audience'] ?? 'middle_grade';
    $genTitle       = !empty($input['gen_title']);
    $genDescription = !empty($input['gen_description']);
    $contextTheme  = $input['context_theme']  ?? 'forest';
    $contextLayout = $input['context_layout'] ?? 'image_left';

    // Phase 1: Story plan (always runs — provides title + description even if not user-requested)
    if (function_exists('worker_log')) {
        worker_log("Create story Phase 1: generating story plan...");
    }
    $usageAccum = [];
    $plan = generate_story_plan($apiKey, $premise, $genre, $tone, $targetScenes, $numEndings, $audience, (int)$job['job_id'], $usageAccum);

    // Phase 2: Write scene content
    $sceneCount    = count($plan['scenes']);
    $writtenScenes = [];
    foreach ($plan['scenes'] as $i => $planScene) {
        if (function_exists('worker_log')) {
            worker_log("Create story Phase 2: writing scene " . ($i + 1) . "/{$sceneCount} ({$planScene['temp_id']})...");
        }
        $content         = write_scene_content($apiKey, $plan, $planScene, $tone, $wordLength, $audience, (int)$job['job_id'], $usageAccum);
        $writtenScenes[] = array_merge($planScene, $content);
    }

    $inputTokens  = $usageAccum['input_tokens']  ?? 0;
    $outputTokens = $usageAccum['output_tokens'] ?? 0;
    $costUsd = (($inputTokens  / 1_000_000) * AI_COST_INPUT_PER_M)
             + (($outputTokens / 1_000_000) * AI_COST_OUTPUT_PER_M);
    db_update_job_cost((int)$job['job_id'], $inputTokens, $outputTokens, 0, $costUsd);

    return [
        'story' => [
            'title'           => $genTitle       ? $plan['title']       : null,
            'description'     => $genDescription ? $plan['description'] : null,
            'theme'           => $plan['suggested_theme'],
            'theme_json'      => $plan['theme_json'] ?? null,
            'layout'          => $contextLayout,
            'context_theme'   => $contextTheme,
            'gen_title'       => $genTitle,
            'gen_description' => $genDescription,
        ],
        'scenes' => $writtenScenes,
        'meta'   => $premiseMeta,
    ];
}

// ------------------------------------------------------------------
// Premise seed loader
// ------------------------------------------------------------------

/**
 * Pick a random seed entry from data/premises.json.
 * If $genre is provided, only seeds whose `genres` array contains it are considered.
 * Falls back to the full list if no seeds match the genre.
 * Returns an array with keys: premise, genres (array), tone.
 * Returns [] if the file is missing or empty.
 */
function load_random_seed(string $genre = ''): array {
    $path = __DIR__ . '/../data/premises.json';
    if (!file_exists($path)) return [];
    $list = json_decode(file_get_contents($path), true);
    if (empty($list) || !is_array($list)) return [];
    if ($genre !== '') {
        $filtered = array_values(array_filter($list, fn($s) => in_array($genre, $s['genres'] ?? [], true)));
        if (!empty($filtered)) $list = $filtered;
    }
    return $list[array_rand($list)];
}

/**
 * Resolve the premise/genre/tone a story job will actually run with.
 *
 * When the user left the premise blank, a random seed from data/premises.json
 * fills it in — and may realign the genre/tone to the seed (see load_random_seed).
 * Because that substitution happens here in the worker and is otherwise discarded,
 * callers log the result and stash it in the job's result_json for transparency
 * (the originally-submitted input_json still shows the empty premise).
 *
 * @return array{premise:string, genre:string, tone:string, source:string}
 *               source is 'user' when the premise was supplied, 'seed' when filled.
 */
function resolve_story_premise(array $input): array {
    $premise = trim($input['premise'] ?? '');
    $genre   = $input['genre'] ?? 'fantasy';
    $tone    = $input['tone']  ?? 'suspenseful';
    $source  = 'user';

    if ($premise === '') {
        $source     = 'seed';
        $seed       = load_random_seed($genre);
        $premise    = $seed['premise'] ?? '';
        $seedGenres = $seed['genres'] ?? [];
        // Keep the requested genre as the AI hint if the seed covers it; otherwise
        // (a fallback seed of a different genre) align the hint to the seed's primary.
        if (!empty($seedGenres) && !in_array($genre, $seedGenres, true)) {
            $genre = $seedGenres[0];
        }
        $tone = $seed['tone'] ?? $tone;
    }

    return ['premise' => $premise, 'genre' => $genre, 'tone' => $tone, 'source' => $source];
}

/**
 * Log the resolved premise to the per-job log (survives even when a later phase
 * throws, e.g. a guardrail breach) and return a compact meta array for result_json.
 */
function story_premise_meta(array $resolved): array {
    if (function_exists('worker_log')) {
        $p = $resolved['premise'];
        $shown = mb_substr($p, 0, 200) . (mb_strlen($p) > 200 ? '…' : '');
        worker_log("Premise ({$resolved['source']}): \"{$shown}\" | genre={$resolved['genre']} tone={$resolved['tone']}");
    }
    return [
        'premise'        => $resolved['premise'],
        'premise_source' => $resolved['source'],
        'genre'          => $resolved['genre'],
        'tone'           => $resolved['tone'],
    ];
}

// ------------------------------------------------------------------
// Phase 1 — Story Planning
// ------------------------------------------------------------------

function generate_story_plan(string $apiKey, string $premise, string $genre, string $tone, int $targetScenes, int $numEndings, string $audience = 'middle_grade', int $jobId = 0, array &$usageAccum = []): array {
    $systemPrompt = build_plan_system_prompt($targetScenes, $numEndings, $audience);
    $userPrompt   = "Premise: \"{$premise}\"\nGenre: {$genre}\nTone: {$tone}\nTarget number of scenes: {$targetScenes}\nNumber of endings: {$numEndings}";

    $messages = [['role' => 'user', 'content' => $userPrompt]];
    $raw      = claude_api_call($apiKey, app_setting('anthropic_model'), $systemPrompt, $messages, 4000, 1.0, $usageAccum);
    $parsed   = json_decode($raw, true);

    if ($parsed === null) {
        $messages[] = ['role' => 'assistant', 'content' => $raw];
        $messages[] = ['role' => 'user',      'content' => 'Your response was not valid JSON. Return ONLY the JSON object — no explanation, no markdown code fences.'];
        $raw    = claude_api_call($apiKey, app_setting('anthropic_model'), $systemPrompt, $messages, 4000, 1.0, $usageAccum);
        $parsed = json_decode($raw, true);
        if ($parsed === null) {
            throw new Exception('Story plan: invalid JSON after retry: ' . mb_substr($raw, 0, 200));
        }
    }

    // Phase 29 — abort (job → error) if the model flagged a guardrail breach
    guardrail_check_response($parsed, $jobId);

    return validate_story_plan($parsed, $genre);
}

/**
 * Resolve an audience key to its label + complexity instruction (Phase 39).
 * Lookup lives in data/audiences.json (via story_audiences()); unknown/blank keys
 * fall back to middle_grade, matching the existing default. Returns ['label', 'complexity'].
 */
function resolve_audience(string $key): array {
    $map = story_audiences();
    return $map[$key] ?? ($map['middle_grade'] ?? ['label' => 'Middle grade (9-12)', 'complexity' => 'Moderate complexity, relatable themes']);
}

function build_plan_system_prompt(int $targetScenes, int $numEndings, string $audience = 'middle_grade'): string {
    $aud = resolve_audience($audience);
    // Phase 29 — append content guardrails when enabled (no-op string otherwise)
    return load_prompt('story_plan_system', [
        'target_scenes'      => $targetScenes,
        'num_endings'        => $numEndings,
        'audience_label'     => $aud['label'],
        'audience_complexity'=> $aud['complexity'],
        'font_options'       => build_font_options_block(),
    ]) . guardrail_prompt_suffix();
}

/**
 * Phase 42 — Compact "mood: Family, Family" list of the allow-listed play fonts
 * for the story-plan prompt, so the AI's theme.font is always a real, on-list
 * family. Built from fonts.php (play_fonts_mood_map()).
 */
function build_font_options_block(): string {
    if (!function_exists('play_fonts_mood_map')) return '';
    $lines = [];
    foreach (play_fonts_mood_map() as $mood => $families) {
        $lines[] = '- ' . $mood . ': ' . implode(', ', $families);
    }
    return implode("\n", $lines);
}

function validate_story_plan(array $parsed, string $genre = ''): array {
    foreach (['title', 'description', 'scenes'] as $req) {
        if (empty($parsed[$req])) {
            throw new Exception("Story plan missing required field: '{$req}'");
        }
    }
    if (!is_array($parsed['scenes']) || count($parsed['scenes']) === 0) {
        throw new Exception("Story plan 'scenes' must be a non-empty array.");
    }

    // Phase 42 — resolve the theme. Prefer the AI's `theme` object (sanitized to
    // hex colours + an allow-list font); fall back to a legacy `suggested_theme`
    // slug, then to a genre-appropriate preset. Always produce a safe theme_json
    // for the engine plus a legacy slug for the stories.theme column.
    if (isset($parsed['theme']) && is_array($parsed['theme'])) {
        $engine    = theme_from_ai($parsed['theme'], $genre);
        $themeJson = json_encode($engine);
        $theme     = theme_preset_for_genre($genre);
    } elseif (!empty($parsed['suggested_theme']) && isset(theme_presets()[$parsed['suggested_theme']])) {
        $theme     = $parsed['suggested_theme'];
        $themeJson = theme_to_json(theme_preset($theme), $theme);
    } else {
        $theme     = theme_preset_for_genre($genre);
        $themeJson = theme_to_json(theme_preset($theme), $theme);
    }

    // Index valid temp_ids for choice destination validation
    $tempIdSet = array_flip(array_column($parsed['scenes'], 'temp_id'));

    $cleanScenes = [];
    foreach ($parsed['scenes'] as $s) {
        if (empty($s['temp_id']) || empty($s['title'])) continue;

        $choices = [];
        foreach (($s['choices'] ?? []) as $c) {
            if (empty($c['text']) || empty($c['dest_temp_id'])) continue;
            if (!isset($tempIdSet[$c['dest_temp_id']])) continue; // drop invalid destination refs
            $choices[] = [
                'text'         => mb_substr(strip_tags((string)$c['text']), 0, 80),
                'dest_temp_id' => (string)$c['dest_temp_id'],
            ];
        }

        $cleanScenes[] = [
            'temp_id'    => (string)$s['temp_id'],
            'title'      => mb_substr(strip_tags((string)$s['title']), 0, 60),
            'summary'    => mb_substr(strip_tags((string)($s['summary'] ?? '')), 0, 500),
            'scene_type' => in_array($s['scene_type'] ?? '', ['opening', 'mid_story', 'climax', 'ending'], true)
                                ? $s['scene_type'] : 'mid_story',
            'choices'    => $choices,
        ];
    }

    if (empty($cleanScenes)) {
        throw new Exception("Story plan contained no valid scenes after validation.");
    }

    return [
        'title'           => mb_substr(strip_tags((string)$parsed['title']), 0, 255),
        'description'     => mb_substr(strip_tags((string)$parsed['description']), 0, 2000),
        'suggested_theme' => $theme,
        'theme_json'      => $themeJson,
        'scenes'          => $cleanScenes,
    ];
}

// ------------------------------------------------------------------
// Phase 2 — Scene Writing
// ------------------------------------------------------------------

function write_scene_content(string $apiKey, array $plan, array $planScene, string $tone, int $wordLength = 100, string $audience = 'middle_grade', int $jobId = 0, array &$usageAccum = []): array {
    $systemPrompt = build_scene_writer_system_prompt($planScene['scene_type'], $tone, $wordLength, $audience);
    $userPrompt   = build_scene_writer_user_prompt($plan, $planScene);

    $messages = [['role' => 'user', 'content' => $userPrompt]];
    $raw      = claude_api_call($apiKey, app_setting('anthropic_model'), $systemPrompt, $messages, 1200, 0.8, $usageAccum);
    $parsed   = json_decode($raw, true);

    if ($parsed === null) {
        $messages[] = ['role' => 'assistant', 'content' => $raw];
        $messages[] = ['role' => 'user',      'content' => 'Your response was not valid JSON. Return ONLY the JSON object — no explanation, no markdown code fences.'];
        $raw    = claude_api_call($apiKey, app_setting('anthropic_model'), $systemPrompt, $messages, 1200, 0.8, $usageAccum);
        $parsed = json_decode($raw, true);
        if ($parsed === null) {
            throw new Exception("Scene {$planScene['temp_id']}: invalid JSON after retry: " . mb_substr($raw, 0, 200));
        }
    }

    // Phase 29 — abort (job → error) if the model flagged a guardrail breach
    guardrail_check_response($parsed, $jobId);

    return validate_written_scene($parsed, $planScene['temp_id']);
}

function build_scene_writer_system_prompt(string $sceneType, string $tone, int $wordLength = 100, string $audience = 'middle_grade'): string {
    $aud = resolve_audience($audience);
    // Phase 29 — append content guardrails when enabled (no-op string otherwise)
    return load_prompt('story_scene_writer_system', [
        'scene_type'         => $sceneType,
        'tone'               => $tone,
        'word_length'        => $wordLength,
        'audience_label'     => $aud['label'],
        'audience_complexity'=> $aud['complexity'],
    ]) . guardrail_prompt_suffix();
}

function build_scene_writer_user_prompt(array $plan, array $planScene): string {
    $lines   = [];
    $lines[] = "Story: \"{$plan['title']}\"";
    $lines[] = "Description: {$plan['description']}";
    $lines[] = '';
    $lines[] = 'Full story structure:';

    foreach ($plan['scenes'] as $s) {
        $choiceTexts = array_column($s['choices'], 'text');
        $choiceStr   = $choiceTexts ? ' → [' . implode(', ', $choiceTexts) . ']' : ' [ending]';
        $lines[]     = "  [{$s['temp_id']}] {$s['title']} ({$s['scene_type']}): {$s['summary']}{$choiceStr}";
    }

    $lines[] = '';
    $lines[] = "Now write scene [{$planScene['temp_id']}]: {$planScene['summary']}";
    $lines[] = "Scene type: {$planScene['scene_type']}";

    if (!empty($planScene['choices'])) {
        $lines[] = 'Choices leading out of this scene:';
        foreach ($planScene['choices'] as $c) {
            $destSummary = '';
            foreach ($plan['scenes'] as $s) {
                if ($s['temp_id'] === $c['dest_temp_id']) {
                    $destSummary = $s['summary'];
                    break;
                }
            }
            $lines[] = "  - \"{$c['text']}\" → [{$c['dest_temp_id']}] {$destSummary}";
        }
    } else {
        $lines[] = 'This is an ending scene — write a satisfying conclusion.';
    }

    return implode("\n", $lines);
}

function validate_written_scene(array $parsed, string $tempId): array {
    if (empty($parsed['description'])) {
        throw new Exception("Written scene {$tempId} is missing required 'description' field.");
    }

    $allowedTags = '<p><em><strong><br>';

    return [
        'title'        => mb_substr(strip_tags((string)($parsed['title']        ?? '')), 0, 60),
        'description'  => strip_tags((string)$parsed['description'], $allowedTags),
        'hint'         => mb_substr(strip_tags((string)($parsed['hint']         ?? '')), 0, 255),
        'image_prompt' => mb_substr(strip_tags((string)($parsed['image_prompt'] ?? '')), 0, 500),
    ];
}
