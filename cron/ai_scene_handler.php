<?php
/**
 * AI Scene Handler — Claude API scene generation
 *
 * Called by ai_worker.php for job_type = 'scene'.
 *
 * @param array $job   Full job row from the database
 * @param array $input Decoded input_json (already augmented with story context by api_jobs.php)
 * @return array       {title, description, hint, image_prompt, choices[]}
 * @throws Exception   On any API or validation failure
 */
function process_scene_job(array $job, array $input): array {
    if (!(bool)(int) app_setting('ai_enabled')) {
        throw new Exception('AI features are disabled.');
    }

    // Resolve API key: user's personal Claude key overrides site-wide constant
    $user   = get_user_by_id((int)$job['user_id']);
    $apiKey = ($user && !empty($user['claude_api_key']))
        ? $user['claude_api_key']
        : app_setting('anthropic_api_key');

    if (empty($apiKey)) {
        throw new Exception('No Anthropic API key configured. Add one in Account Settings or ask the site admin to set a site-wide key.');
    }

    $direction = trim($input['direction'] ?? '');
    if (empty($direction)) {
        throw new Exception('Scene direction is required.');
    }

    $mode       = $input['mode']        ?? 'continue';
    $tone       = $input['tone']        ?? 'neutral';
    $numChoices = max(1, min(4, (int)($input['num_choices'] ?? 2)));
    $endingType = $input['ending_type'] ?? '';

    $systemPrompt = build_scene_system_prompt();
    $userPrompt   = build_scene_user_prompt($input, $direction, $mode, $tone, $numChoices, $endingType);

    $usageAccum = [];
    $result     = claude_scene_request($apiKey, $systemPrompt, $userPrompt, (int)$job['job_id'], $usageAccum);

    $inputTokens  = $usageAccum['input_tokens']  ?? 0;
    $outputTokens = $usageAccum['output_tokens'] ?? 0;
    $costUsd = (($inputTokens  / 1_000_000) * AI_COST_INPUT_PER_M)
             + (($outputTokens / 1_000_000) * AI_COST_OUTPUT_PER_M);
    db_update_job_cost((int)$job['job_id'], $inputTokens, $outputTokens, 0, $costUsd);

    return $result;
}

// ------------------------------------------------------------------
// Prompt builders
// ------------------------------------------------------------------

function build_scene_system_prompt(): string {
    // Phase 29 — append content guardrails when enabled (no-op string otherwise)
    return load_prompt('scene_system') . guardrail_prompt_suffix();
}

function build_scene_user_prompt(array $input, string $direction, string $mode, string $tone, int $numChoices, string $endingType): string {
    $lines = [];

    $lines[] = 'Story: "' . ($input['story_title'] ?? '') . '"';
    // Genre drives the narrative; the visual theme (colours/fonts) is deliberately
    // omitted so a presentation label like "noir" or "forest" can't steer content.
    if (!empty($input['story_genre'])) {
        $lines[] = 'Genre: ' . $input['story_genre'];
    }
    if (!empty($input['story_description'])) {
        $lines[] = 'Story description: "' . $input['story_description'] . '"';
    }
    $lines[] = '';

    $previousScenes = $input['previous_scenes'] ?? [];
    if (!empty($previousScenes)) {
        $lines[] = "The player's path through the story so far (in order):";
        foreach ($previousScenes as $ps) {
            $desc = mb_substr($ps['description'] ?? '', 0, 200);
            $lines[] = '- "' . $ps['title'] . '": ' . $desc . '...';
            if (!empty($ps['choice_taken'])) {
                $lines[] = '  Player chose: "' . $ps['choice_taken'] . '"';
            }
        }
        $lines[] = '';
    }

    $lines[] = 'Direction for this scene: "' . $direction . '"';
    $lines[] = 'Mode: ' . $mode;
    if ($mode === 'ending' && !empty($endingType)) {
        $lines[] = 'Ending type: ' . $endingType;
    }
    $lines[] = 'Tone: ' . $tone;
    if ($mode === 'continue') {
        $lines[] = 'Number of choices to generate: ' . $numChoices;
    }

    return implode("\n", $lines);
}

// ------------------------------------------------------------------
// Claude API helpers  (reused by ai_story_handler.php in Phase 6)
// ------------------------------------------------------------------

/**
 * Call Claude, parse JSON response, retry once on invalid JSON.
 * Accumulates token usage into $usageAccum across initial + retry calls.
 *
 * @throws Exception
 */
function claude_scene_request(string $apiKey, string $systemPrompt, string $userPrompt, int $jobId, array &$usageAccum = []): array {
    $messages = [['role' => 'user', 'content' => $userPrompt]];

    $raw    = claude_api_call($apiKey, app_setting('anthropic_model'), $systemPrompt, $messages, 1500, 0.8, $usageAccum);
    $parsed = json_decode($raw, true);

    if ($parsed === null) {
        // Retry with a JSON reminder
        $messages[] = ['role' => 'assistant', 'content' => $raw];
        $messages[] = ['role' => 'user',      'content' => 'Your response was not valid JSON. Please return ONLY the JSON object — no explanation, no markdown code fences.'];
        $raw    = claude_api_call($apiKey, app_setting('anthropic_model'), $systemPrompt, $messages, 1500, 0.8, $usageAccum);
        $parsed = json_decode($raw, true);
        if ($parsed === null) {
            throw new Exception('Claude returned invalid JSON after retry: ' . mb_substr($raw, 0, 200));
        }
    }

    // Phase 29 — abort (job → error) if the model flagged a guardrail breach
    guardrail_check_response($parsed, $jobId);

    return validate_and_sanitize_scene($parsed);
}

/**
 * Make one HTTP request to the Anthropic messages API; return the text content.
 * If $usageAccum is passed by reference, input_tokens and output_tokens from the
 * response are added to it so callers can accumulate usage across multiple calls.
 *
 * @throws Exception on curl error, HTTP error, or empty response
 */
/**
 * Whether a Claude model accepts the `temperature` parameter. Newer Opus models
 * (4.7+) deprecate it and reject the request with "'temperature' is deprecated for
 * this model" if it's sent, so we omit it for those. Add new model substrings here
 * as Anthropic ships them.
 */
function claude_model_supports_temperature(string $model): bool {
    static $deprecated = ['opus-4-7', 'opus-4-8'];
    foreach ($deprecated as $m) {
        if (strpos($model, $m) !== false) return false;
    }
    return true;
}

function claude_api_call(string $apiKey, string $model, string $systemPrompt, array $messages, int $maxTokens, float $temperature = 0.8, array &$usageAccum = []): string {
    $payload = [
        'model'      => $model,
        'max_tokens' => $maxTokens,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ];
    // Only send `temperature` to models that still accept it (omitting it uses the
    // model default of 1.0).
    if (claude_model_supports_temperature($model)) {
        $payload['temperature'] = $temperature;
    }
    $body = json_encode($payload);

    // Per-request timeout (admin-tunable). Opus plan/scene calls with large
    // max_tokens can run long, so this is separate from the overall job-timeout
    // watchdog. A single operation-timeout (#28) is retried once by the helper.
    $reqTimeout = max(30, (int) app_setting('ai_claude_request_timeout'));

    // Build a fresh cURL handle per attempt so the retry helper can re-issue the
    // request on a 429 (curl_reset would clear these options).
    $buildHandle = function () use ($body, $apiKey, $reqTimeout) {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $reqTimeout,
        ]);
        return $ch;
    };

    $result    = api_call_with_retry($buildHandle, 5, 20, 'Claude', 1);
    $response  = $result['body'];
    $httpCode  = $result['http_code'];
    $curlError = $result['curl_error'];

    if ($curlError) {
        throw new Exception('Claude API request failed: ' . $curlError);
    }

    $data = json_decode($response, true);

    if ($httpCode === 429) {
        throw new Exception('Anthropic API rate limit exceeded after 5 retries. Please try again later.');
    }
    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? "HTTP $httpCode";
        throw new Exception('Claude API error: ' . $errMsg);
    }

    $text = $data['content'][0]['text'] ?? '';
    if (empty($text)) {
        throw new Exception('Claude returned an empty response.');
    }

    // Accumulate token usage from this call
    $usageAccum['input_tokens']  = ($usageAccum['input_tokens']  ?? 0) + (int)($data['usage']['input_tokens']  ?? 0);
    $usageAccum['output_tokens'] = ($usageAccum['output_tokens'] ?? 0) + (int)($data['usage']['output_tokens'] ?? 0);

    // Strip markdown code fences if Claude wrapped the JSON anyway
    $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/i', '', $text);

    return trim($text);
}

/**
 * Validate required fields and sanitize content in the parsed scene array.
 *
 * @throws Exception if required fields are missing or malformed
 */
function validate_and_sanitize_scene(array $parsed): array {
    foreach (['title', 'description', 'choices'] as $required) {
        if (!isset($parsed[$required])) {
            throw new Exception("Claude response missing required field: '$required'");
        }
    }
    if (!is_array($parsed['choices'])) {
        throw new Exception("Claude response 'choices' field is not an array.");
    }

    $allowedTags = '<p><em><strong><br>';

    $choices = [];
    foreach ($parsed['choices'] as $c) {
        if (!is_array($c) || !isset($c['text']) || trim((string)$c['text']) === '') continue;
        $choices[] = ['text' => mb_substr(strip_tags((string)$c['text']), 0, 80)];
    }

    return [
        'title'        => mb_substr(strip_tags((string)$parsed['title']), 0, 60),
        'description'  => strip_tags((string)$parsed['description'], $allowedTags),
        'hint'         => mb_substr(strip_tags((string)($parsed['hint'] ?? '')), 0, 255),
        'image_prompt' => mb_substr(strip_tags((string)($parsed['image_prompt'] ?? '')), 0, 500),
        'choices'      => $choices,
    ];
}
