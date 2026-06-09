<?php
/**
 * AI Image Handler — OpenAI image generation (gpt-image-1 / gpt-image-2)
 *
 * Called by ai_worker.php for job_type = 'image'.
 *
 * @param array $job   Full job row from the database
 * @param array $input Decoded input_json
 * @return array       ['filename' => string, 'prompt_used' => string]
 * @throws Exception   On any API or file-system failure
 */
function process_image_job(array $job, array $input): array {
    if (!(bool)(int) app_setting('ai_enabled')) {
        throw new Exception('AI features are disabled.');
    }

    $prompt = trim($input['prompt'] ?? '');
    if ($prompt === '') {
        throw new Exception('Image prompt is empty.');
    }

    // Sanitize: strip HTML tags and cap length before sending to API
    $prompt = strip_tags(substr($prompt, 0, 1000));

    // Resolve API key: user's personal key overrides the site-wide constant
    $user    = get_user_by_id((int)$job['user_id']);
    $apiKey  = ($user && !empty($user['openai_api_key']))
        ? $user['openai_api_key']
        : app_setting('openai_api_key');
    $allowedQualities = ['low', 'medium', 'high', 'auto'];
    $quality = in_array($input['quality'] ?? '', $allowedQualities, true)
        ? $input['quality']
        : app_setting('openai_image_quality');

    if (empty($apiKey)) {
        throw new Exception('No OpenAI API key configured. Add one in Account Settings or ask the site admin to set a site-wide key.');
    }

    // The story's visual theme (colours/fonts) is intentionally NOT used here — it's
    // a presentation label for the web page, not an image directive. Image content is
    // driven only by the prompt plus the user's explicit style/mood selections below.
    $isCover    = (($input['target'] ?? '') === 'story_cover');
    $systemCtx  = load_prompt($isCover ? 'cover_image_system' : 'image_system');

    // Phase 28 — compose chosen style + mood modifiers onto the subject prompt
    $category  = trim($input['image_category'] ?? '');
    $style     = trim($input['image_style']    ?? '');
    $mood      = trim($input['image_mood']     ?? '');
    $stylePart = $style ? " in {$style} style" : ($category ? " in {$category} style" : '');
    $moodPart  = $mood  ? ", {$mood}" : '';
    $fullPrompt = $systemCtx . "\n\n" . $prompt . $stylePart . $moodPart;

    // Phase 29 — prepend the guardrail "Do not depict" prefix when guardrails are enabled
    $guardrailClause = get_guardrail_clause();
    if ($guardrailClause !== '') {
        $fullPrompt = "Do not depict: {$guardrailClause}. " . $fullPrompt;
    }

    // Cover images use a landscape size (3:2) that crops cleanly to 16:9 gallery thumbnails
    $size = $isCover ? '1536x1024' : '1024x1024';

    $model       = app_setting('openai_image_model');
    $imageFormat = app_setting('openai_image_format') ?: 'png';
    $requestBody = json_encode([
        'model'         => $model,
        'prompt'        => $fullPrompt,
        'size'          => $size,
        'quality'       => $quality,
        'output_format' => $imageFormat,
        'n'             => 1,
    ]);

    if (function_exists('worker_log')) {
        worker_log("Image request: model=$model quality=$quality size=$size isCover=" . ($isCover ? 'yes' : 'no'));
        worker_log("Prompt (" . strlen($fullPrompt) . " chars): " . mb_substr($fullPrompt, 0, 200) . (strlen($fullPrompt) > 200 ? '…' : ''));
    }

    // Stamp started_at to right now so "time to complete" measures the OpenAI
    // round-trip only, not queue wait or PHP bootstrap time.
    db_update_job_started_at((int)$job['job_id']);

    // Per-image request timeout (admin-tunable). gpt-image can be slow, especially
    // at high quality, so this is separate from the overall job-timeout watchdog.
    $reqTimeout = max(30, (int) app_setting('ai_image_request_timeout'));

    // Build a fresh cURL handle per attempt (curl_reset would wipe these options,
    // so the retry helper invokes this builder on each try).
    $buildHandle = function () use ($requestBody, $apiKey, $reqTimeout) {
        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $requestBody,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $reqTimeout,
        ]);
        return $ch;
    };

    if (function_exists('worker_log')) {
        worker_log("Calling OpenAI image API (retries on rate limit)…");
    }
    $callStart = microtime(true);
    $result    = api_call_with_retry($buildHandle, 5, 20, 'OpenAI image');
    $response  = $result['body'];
    $httpCode  = $result['http_code'];
    $totalTime = round(microtime(true) - $callStart, 1);

    if (function_exists('worker_log')) {
        worker_log("OpenAI image API result: HTTP $httpCode in {$totalTime}s"
            . ($result['curl_error'] ? " | curl error #{$result['curl_errno']}: {$result['curl_error']}" : ''));
    }

    if ($result['curl_errno']) {
        throw new Exception("OpenAI image API curl error #{$result['curl_errno']} after {$totalTime}s: {$result['curl_error']}");
    }

    $data = json_decode($response, true);

    if ($httpCode === 429) {
        $errMsg = $data['error']['message'] ?? '(no message)';
        throw new Exception("OpenAI rate limit exceeded after 5 retries: $errMsg");
    }
    if ($httpCode !== 200) {
        $errorMsg = $data['error']['message'] ?? "HTTP $httpCode";
        if (function_exists('worker_log')) {
            worker_log("API error response body: " . mb_substr($response ?? '', 0, 500));
        }
        if ($httpCode === 400 && stripos($errorMsg, 'safety') !== false) {
            throw new Exception('Content could not be generated — try a different prompt.');
        }
        throw new Exception("OpenAI image API error (HTTP $httpCode): $errorMsg");
    }

    $b64 = $data['data'][0]['b64_json'] ?? '';
    if (empty($b64)) {
        if (function_exists('worker_log')) {
            worker_log("Unexpected response body: " . mb_substr($response ?? '', 0, 500));
        }
        throw new Exception('OpenAI image API returned no image data.');
    }

    if (function_exists('worker_log')) {
        worker_log("Image data received (" . strlen($b64) . " base64 chars), decoding…");
    }

    $imageData = base64_decode($b64, true);
    if ($imageData === false) {
        throw new Exception('Failed to decode image data from OpenAI response.');
    }

    $imageBytes = strlen($imageData);
    if ($imageBytes > 5 * 1024 * 1024) {
        throw new Exception("Generated image exceeds 5 MB size limit ($imageBytes bytes).");
    }

    // Save to images/stories/{storyID}/
    $storyID  = (int)$job['story_id'];
    $imageDir = __DIR__ . '/../images/stories/' . $storyID . '/';
    if (!is_dir($imageDir)) {
        if (!mkdir($imageDir, 0755, true)) {
            throw new Exception("Failed to create image directory: $imageDir");
        }
    }

    $ext      = ($imageFormat === 'jpeg') ? 'jpg' : $imageFormat;
    $filename = 'ai_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $savePath = $imageDir . $filename;
    if (file_put_contents($savePath, $imageData) === false) {
        throw new Exception("Failed to save generated image to: $savePath");
    }

    if (function_exists('worker_log')) {
        worker_log("Image saved: $filename (" . round($imageBytes / 1024) . " KB)");
    }

    return [
        'filename'    => $filename,
        'prompt_used' => $fullPrompt,
    ];
}
