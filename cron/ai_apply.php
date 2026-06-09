<?php
/**
 * AI Result Apply Functions
 *
 * Called by ai_worker.php after a successful AI API call to write the
 * generated content back to the database.  Also callable via the
 * api_jobs.php ?action=apply endpoint for manual re-applies (admin only).
 *
 * Each handler receives the full $job row and the decoded $result array
 * and throws an Exception on failure so the caller can mark the job failed.
 *
 * Phase 3: stubs only — real implementations are added in Phases 4–6.
 */

if (!defined('DB_PREFIX')) {
    // Loaded standalone by ai_worker.php (CLI) — bring in config + db
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../db_functions.php';
require_once __DIR__ . '/../settings.php';
}

// Phase 42 — theme engine helpers (theme_to_json / theme_preset) for storing
// the per-story theme_json on apply.
require_once __DIR__ . '/../fonts.php';
require_once __DIR__ . '/../theme.php';

/**
 * Dispatch apply logic based on job_type.
 *
 * @param  array $job  Full job row from the database (keys: job_id, job_type, story_id, scene_id, result_json, …)
 * @return array       Context returned to the caller: keys vary by job_type
 * @throws Exception   On any apply failure
 */
function apply_ai_job(array $job): array {
    $result = json_decode($job['result_json'] ?? '{}', true);
    if ($result === null) {
        throw new Exception('result_json is missing or not valid JSON');
    }

    switch ($job['job_type']) {
        case 'image':
            return apply_image_result($job, $result);

        case 'scene':
            return apply_scene_result($job, $result);

        case 'full_story':
            return apply_full_story_result($job, $result);

        case 'story':
            return apply_create_story_result($job, $result);

        default:
            throw new Exception("Unknown job_type: {$job['job_type']}");
    }
}

// ------------------------------------------------------------------
// Image apply  (implemented in Phase 4 — task 4.3)
// ------------------------------------------------------------------

/**
 * Apply a completed image generation job.
 *
 * Expected $result keys:
 *   filename     — saved image filename (e.g. "ai_1710512345_abc123.png")
 *   prompt_used  — the exact prompt sent to DALL-E
 *
 * Effect: updates the target scene's `image` and `image_gen` columns.
 *
 * @throws Exception (stub — not yet implemented)
 */
function apply_image_result(array $job, array $result): array {
    $sceneID = (int)($job['scene_id'] ?? 0);
    $storyID = (int)($job['story_id'] ?? 0);

    $filename   = $result['filename']    ?? '';
    $promptUsed = $result['prompt_used'] ?? '';

    if (empty($filename)) {
        throw new Exception('Image result is missing filename.');
    }

    $input  = json_decode($job['input_json'] ?? '{}', true);
    $target = $input['target'] ?? 'scene';

    // Log image cost (per-image lookup from AI_IMAGE_PRICING)
    $model    = app_setting('openai_image_model') ?? 'gpt-image-2';
    $quality  = $input['quality'] ?? app_setting('openai_image_quality') ?? 'medium';
    $costUsd  = AI_IMAGE_PRICING[$model][$quality] ?? AI_IMAGE_PRICING['gpt-image-2']['medium'];
    db_update_job_cost((int)$job['job_id'], 0, 0, 1, (float)$costUsd);

    if ($target === 'story_cover') {
        if (!$storyID) {
            throw new Exception('Story cover image job is missing story_id.');
        }
        update_story_image($storyID, $filename);
        return ['story_id' => $storyID, 'scene_id' => null];
    }

    if (!$sceneID) {
        throw new Exception('Image job is missing scene_id.');
    }

    // Verify the target scene still exists before applying
    $scene = get_scene($sceneID);
    if (!$scene) {
        throw new Exception("Scene #$sceneID no longer exists — cannot apply generated image.");
    }

    update_scene_image($sceneID, $filename, $promptUsed);

    return ['story_id' => $storyID, 'scene_id' => $sceneID];
}

// ------------------------------------------------------------------
// Scene apply  (implemented in Phase 5 — task 5.3)
// ------------------------------------------------------------------

/**
 * Apply a completed scene generation job.
 *
 * Expected $result keys:
 *   title, description, hint  — scene content fields
 *   image_prompt              — optional; triggers an image job if present
 *   choices[]                 — array of {text} objects; a stub scene is
 *                               created for each choice destination
 *
 * Effect: updates the target scene; creates stub scenes + choice rows.
 *
 * @throws Exception (stub — not yet implemented)
 */
function apply_scene_result(array $job, array $result): array {
    $sceneID = (int)($job['scene_id'] ?? 0);
    $storyID = (int)($job['story_id'] ?? 0);

    if (!$sceneID) {
        throw new Exception('Scene job is missing scene_id.');
    }

    $scene = get_scene($sceneID);
    if (!$scene) {
        throw new Exception("Scene #$sceneID no longer exists — cannot apply generated content.");
    }

    // Write generated content into the target scene
    update_scene_content($sceneID, $result['title'], $result['description'], $result['hint'] ?? '');

    // Create one stub scene per choice, then save all choices at once
    $newChoices = [];
    foreach ($result['choices'] as $choice) {
        $stubTitle = mb_substr($choice['text'], 0, 255);
        $stubID    = create_scene($storyID, $stubTitle, '', null, null, null, 1);
        if ($stubID) {
            $newChoices[] = ['text' => $choice['text'], 'dest' => (int)$stubID];
        }
    }
    save_choices($sceneID, $newChoices);

    // If the user requested an image and the AI returned an image_prompt, queue an image job.
    // Carry through any image-style overrides chosen in the scene-AI modal.
    $input = json_decode($job['input_json'] ?? '{}', true);
    if (!empty($input['generate_image']) && !empty($result['image_prompt'])) {
        $imageInput = json_encode([
            'prompt'         => $result['image_prompt'],
            'image_category' => $input['image_category'] ?? '',
            'image_style'    => $input['image_style']    ?? '',
            'image_mood'     => $input['image_mood']     ?? '',
        ]);
        create_ai_job((int)$job['user_id'], $storyID, $sceneID, 'image', $imageInput, (int)$job['job_id']);
    }

    return ['story_id' => $storyID, 'scene_id' => $sceneID];
}

// ------------------------------------------------------------------
// Full story apply  (implemented in Phase 6 — task 6.3)
// ------------------------------------------------------------------

/**
 * Apply a completed full story generation job.
 *
 * Expected $result keys:
 *   story    — {title, description, theme}
 *   scenes[] — [{temp_id, title, description, hint, is_ending, choices[{text, dest_temp_id}]}]
 *
 * Effect: creates the story record, all scenes, and all choices in a
 *         single transaction.  Story starts as a draft owned by the
 *         job's user.
 *
 * @throws Exception (stub — not yet implemented)
 */
function apply_full_story_result(array $job, array $result): array {
    if (empty($result['story']) || empty($result['scenes'])) {
        throw new Exception('Full story result is missing required story or scenes data.');
    }

    $storyData = $result['story'];
    $scenes    = $result['scenes'];

    // User-authored overrides take precedence over the LLM-generated plan values
    $input = json_decode($job['input_json'] ?? '{}', true);
    $title       = !empty($input['input_title'])       ? $input['input_title']       : $storyData['title'];
    $description = !empty($input['input_description']) ? $input['input_description'] : $storyData['description'];
    $theme       = !empty($input['input_theme'])       ? $input['input_theme']       : ($storyData['theme'] ?? 'forest');
    $layout      = !empty($input['input_layout'])      ? $input['input_layout']      : 'image_left';

    // Resolve user display name for created_by field
    $user      = get_user_by_id((int)$job['user_id']);
    $createdBy = $user ? trim($user['firstName'] . ' ' . $user['lastName']) : '';

    // Create the story record (create_story defaults to status='draft')
    $storyID = create_story(
        $title,
        $description,
        '',
        $theme,
        (int)$job['user_id'],
        $createdBy,
        $layout
    );
    if (!$storyID) {
        throw new Exception('Failed to create story record in database.');
    }

    // Stamp the job row so job_queue.php can show a "Go to story" link
    update_ai_job_story_id((int)$job['job_id'], (int)$storyID);

    // Phase 42 — persist the engine theme_json so the story renders via the
    // data-driven theme. A user-chosen legacy theme override maps to that preset.
    $themeJson = $storyData['theme_json'] ?? null;
    if (!empty($input['input_theme'])) {
        $themeJson = theme_to_json(theme_preset($theme), $theme);
    }
    if (!empty($themeJson)) {
        update_story_theme_json((int)$storyID, $themeJson);
    }

    // First pass: create all scenes, build temp_id → real sceneID map
    $idMap = [];
    foreach ($scenes as $scene) {
        $tempId  = $scene['temp_id'] ?? '';
        $sceneID = create_scene(
            $storyID,
            $scene['title']       ?? 'Untitled Scene',
            $scene['description'] ?? '',
            null,
            null,
            $scene['hint']        ?? ''
        );
        if (!$sceneID) {
            throw new Exception("Failed to create scene for temp_id '{$tempId}'.");
        }
        if ($tempId) {
            $idMap[$tempId] = (int)$sceneID;
        }
    }

    // Second pass: create all choices with temp_id destinations remapped to real sceneIDs
    foreach ($scenes as $scene) {
        $tempId = $scene['temp_id'] ?? '';
        if (empty($scene['choices']) || !isset($idMap[$tempId])) continue;

        $newChoices = [];
        foreach ($scene['choices'] as $choice) {
            $destTempId = $choice['dest_temp_id'] ?? '';
            if (empty($destTempId) || !isset($idMap[$destTempId])) continue;
            $newChoices[] = [
                'text' => $choice['text'],
                'dest' => $idMap[$destTempId],
            ];
        }
        if (!empty($newChoices)) {
            save_choices($idMap[$tempId], $newChoices);
        }
    }

    // Optional: queue an image job for each scene if image_quality is not 'none'
    $imageQuality = $input['image_quality'] ?? 'none';
    if ($imageQuality !== 'none') {
        foreach ($scenes as $scene) {
            $tempId = $scene['temp_id'] ?? '';
            if (empty($scene['image_prompt']) || !isset($idMap[$tempId])) continue;
            $imageInput = json_encode([
                'prompt'  => $scene['image_prompt'],
                'quality' => $imageQuality,
            ]);
            create_ai_job((int)$job['user_id'], $storyID, $idMap[$tempId], 'image', $imageInput, (int)$job['job_id']);
        }
    }

    return ['story_id' => $storyID];
}

// ------------------------------------------------------------------
// Create story apply  (Phase 18)
// ------------------------------------------------------------------

/**
 * Apply a completed 'create_story' job.
 *
 * The story record already exists (created by api_create_story_ai.php).
 * This function:
 *   - Updates the story's title/description/theme/layout (using AI values
 *     where gen_title/gen_description flags were set, else keeps originals)
 *   - Creates all scene rows for the generated scenes
 *   - Wires up choice rows between scenes
 *   - Queues child image jobs for each scene that has an image_prompt
 *
 * Expected $result keys (from process_create_story_job):
 *   story   — {title|null, description|null, theme, layout, gen_title, gen_description}
 *   scenes  — same array as full_story handler
 *
 * @throws Exception on any failure
 */
function apply_create_story_result(array $job, array $result): array {
    if (empty($result['story']) || empty($result['scenes'])) {
        throw new Exception('create_story result is missing required story or scenes data.');
    }

    $storyID   = (int)($job['story_id'] ?? 0);
    if (!$storyID) {
        throw new Exception('create_story job is missing story_id.');
    }

    $storyData = $result['story'];
    $scenes    = $result['scenes'];
    $input     = json_decode($job['input_json'] ?? '{}', true);

    // Determine final values: AI-generated values replace user selections only when requested
    // gen_theme defaults to true when absent (batch scripts / legacy jobs let AI choose)
    $genTheme = $input['gen_theme'] ?? true;
    $theme  = $genTheme
        ? ($storyData['theme']  ?? ($input['context_theme']  ?? 'forest'))
        : ($input['context_theme']  ?? 'forest');
    $layout = $storyData['layout'] ?? ($input['context_layout'] ?? 'image_left');

    // Build the update: only override title/description when AI was asked to generate them
    $updateTitle       = !empty($storyData['gen_title'])       && $storyData['title']       !== null;
    $updateDescription = !empty($storyData['gen_description']) && $storyData['description'] !== null;

    $story = get_story($storyID);
    if (!$story) {
        throw new Exception("Story #$storyID no longer exists.");
    }

    $finalTitle       = $updateTitle       ? $storyData['title']       : $story['title'];
    $finalDescription = $updateDescription ? $storyData['description'] : $story['description'];

    // Preserve the story's auto-filled genre (set at creation) — passing it back
    // prevents update_story from nulling the genre column.
    update_story($storyID, $finalTitle, $finalDescription, null, $theme, $layout, $story['genre'] ?? null);

    // Phase 42 — persist the engine theme_json. When the user kept their own theme
    // (gen_theme off), use the exact theme they built in the editor (font + colours,
    // captured as context_theme_json); fall back to the chosen preset for older jobs.
    // Otherwise use the AI's sanitized theme.
    $themeJson = $storyData['theme_json'] ?? null;
    if (!$genTheme) {
        $themeJson = !empty($input['context_theme_json'])
            ? $input['context_theme_json']
            : theme_to_json(theme_preset($theme), $theme);
    }
    if (!empty($themeJson)) {
        update_story_theme_json($storyID, $themeJson);
    }

    // First pass: create all scene rows, build temp_id → real sceneID map
    $idMap = [];
    foreach ($scenes as $scene) {
        $tempId  = $scene['temp_id'] ?? '';
        $sceneID = create_scene(
            $storyID,
            $scene['title']       ?? 'Untitled Scene',
            $scene['description'] ?? '',
            null,
            null,
            $scene['hint']        ?? ''
        );
        if (!$sceneID) {
            throw new Exception("Failed to create scene for temp_id '{$tempId}'.");
        }
        if ($tempId) {
            $idMap[$tempId] = (int)$sceneID;
        }
    }

    // Second pass: create choices with remapped destination IDs
    foreach ($scenes as $scene) {
        $tempId = $scene['temp_id'] ?? '';
        if (empty($scene['choices']) || !isset($idMap[$tempId])) continue;

        $newChoices = [];
        foreach ($scene['choices'] as $choice) {
            $destTempId = $choice['dest_temp_id'] ?? '';
            if (empty($destTempId) || !isset($idMap[$destTempId])) continue;
            $newChoices[] = [
                'text' => $choice['text'],
                'dest' => $idMap[$destTempId],
            ];
        }
        if (!empty($newChoices)) {
            save_choices($idMap[$tempId], $newChoices);
        }
    }

    // Queue image jobs for scenes that have an image_prompt, plus a cover image
    $imageQuality  = $input['image_quality'] ?? app_setting('openai_image_quality') ?? 'medium';
    $imageCategory = $input['image_category'] ?? '';
    $imageStyle    = $input['image_style']    ?? '';
    $imageMood     = $input['image_mood']     ?? '';

    // Persist the chosen image settings on the story so inline cover/scene panels
    // can offer "Use story settings" (Phase 28). Quality 'none' stores no default.
    update_story_image_settings(
        $storyID,
        $imageCategory,
        $imageStyle,
        $imageMood,
        ($imageQuality !== 'none' ? $imageQuality : '')
    );

    if ($imageQuality !== 'none') {
        // Style/mood/category passed to each image job so the handler can compose them
        $styleFields = [
            'image_category' => $imageCategory,
            'image_style'    => $imageStyle,
            'image_mood'     => $imageMood,
        ];

        // Cover image — use AI-generated title + description as the prompt
        $coverParts  = array_filter([trim($finalTitle), trim($finalDescription)]);
        $coverPrompt = implode('. ', $coverParts);
        if (!empty($coverPrompt)) {
            create_ai_job((int)$job['user_id'], $storyID, null, 'image', json_encode(array_merge([
                'prompt'  => $coverPrompt,
                'quality' => $imageQuality,
                'target'  => 'story_cover',
            ], $styleFields)), (int)$job['job_id']);
        }

        // Scene images
        foreach ($scenes as $scene) {
            $tempId = $scene['temp_id'] ?? '';
            if (empty($scene['image_prompt']) || !isset($idMap[$tempId])) continue;
            create_ai_job((int)$job['user_id'], $storyID, $idMap[$tempId], 'image', json_encode(array_merge([
                'prompt'  => $scene['image_prompt'],
                'quality' => $imageQuality,
            ], $styleFields)), (int)$job['job_id']);
        }
    }

    // NOTE: auto-publish is NOT done here — the story text is ready, but its image
    // sub-jobs are only just being queued. Publishing waits until the whole family
    // (this job + every image child) has succeeded; that decision lives in
    // maybe_publish_created_story(), invoked from the worker / child finalizer.
    return ['story_id' => $storyID];
}
