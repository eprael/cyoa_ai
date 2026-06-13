<?php
/**
 * Shared cron helpers for the AI handlers.
 *
 * Provides api_call_with_retry(): a single retry-on-429 wrapper around a cURL
 * request, used by the image, scene, and story handlers so the rate-limit
 * backoff logic lives in one place rather than being duplicated per handler.
 */

/**
 * Append a line to today's API-retry log (cron/logs/api_retry_YYYYMMDD.log).
 * Mirrors the line to the per-job worker log when worker_log() is available, so
 * retries are visible both in the daily summary and in the individual job log.
 */
function api_retry_log(string $msg): void {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/api_retry_' . date('Ymd') . '.log';
    $line    = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    if (LOG_FILE_ENABLED) file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    if (function_exists('worker_log')) {
        worker_log('[api-retry] ' . $msg);
    }
}

/**
 * Phase 29 — Append a guardrail-breach line to today's guardrails log
 * (cron/logs/guardrails_YYYYMMDD.log). Mirrors to the per-job worker log when
 * available so a breach is visible both in the daily summary and the job log.
 */
function log_guardrail_breach(int $jobId, string $topic): void {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/guardrails_' . date('Ymd') . '.log';
    $line    = date('Y-m-d H:i:s') . " job_id={$jobId} breached=\"{$topic}\"\n";
    if (LOG_FILE_ENABLED) file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    if (function_exists('worker_log')) {
        worker_log("[guardrail] breach job_id={$jobId} topic=\"{$topic}\"");
    }
}

/**
 * Phase 29 — Build the guardrail block appended to Claude system prompts.
 *
 * Returns an empty string when guardrails are disabled or the list is empty, so
 * handlers can append unconditionally without changing the prompt in that case.
 */
function guardrail_prompt_suffix(): string {
    $clause = get_guardrail_clause();
    if ($clause === '') return '';
    return "\n\nContent guardrails: Never generate content involving: {$clause}. "
         . 'If any part of your response would involve these topics, include a '
         . '"red_flag" field in your JSON response with the name of the breached '
         . 'topic as its string value.';
}

/**
 * Phase 29 — Inspect a decoded Claude JSON response for a guardrail breach.
 *
 * When the model flagged its own output with a non-empty "red_flag" field, log
 * the breach and throw so the worker marks the job as error (the thrown message
 * becomes the job's error_message).
 *
 * @throws Exception when a breach is detected
 */
function guardrail_check_response(array $parsed, int $jobId): void {
    if (!empty($parsed['red_flag'])) {
        $breached = (string)$parsed['red_flag'];
        log_guardrail_breach($jobId, $breached);
        throw new Exception('Inappropriate Content Detected: ' . $breached);
    }
}

/**
 * Execute a cURL request with retry-on-429 (rate limit) logic.
 *
 * Because curl_reset() clears all options, the caller passes a builder callable
 * that returns a freshly configured cURL handle; it is invoked once per attempt
 * and the handle is closed inside this function after each attempt.
 *
 * Transient failures are retried while attempts remain:
 *   - HTTP 429 (rate limit) — backs off the full $delaySeconds.
 *   - HTTP 5xx (server errors, e.g. a 500 from OpenAI) — short backoff.
 *   - Quick cURL transport blips: couldn't-connect (7), empty reply (52),
 *     recv failure (56) — short backoff.
 *   - Operation timeout (#28) — only when $maxTimeoutRetries > 0, and capped by
 *     its own separate budget because each retry costs another full timeout
 *     window. A stalled connection ("0 bytes received") often clears on a fresh
 *     try; a genuinely slow model will just time out again, so keep the budget
 *     small and prefer raising the per-request timeout for the real fix.
 * Everything else (success, 4xx) returns immediately so the caller handles it.
 *
 * @param callable $buildHandle       Returns a ready-to-exec cURL handle
 * @param int      $maxRetries        Retries after the first attempt (default 5)
 * @param int      $delaySeconds      Seconds to sleep between rate-limit attempts (default 20)
 * @param string   $label             Short label used in log lines (e.g. "Claude")
 * @param int      $maxTimeoutRetries Extra retries for operation-timeout #28 (default 0 = none)
 * @return array{body: string|false, http_code: int, curl_errno: int, curl_error: string}
 */
function api_call_with_retry(callable $buildHandle, int $maxRetries = 5, int $delaySeconds = 20, string $label = 'API', int $maxTimeoutRetries = 0): array {
    $attempt        = 0; // budget for 429 / 5xx / transient transport blips
    $timeoutAttempt = 0; // separate, smaller budget for operation timeouts (#28)
    while (true) {
        $ch        = $buildHandle();
        $body      = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $is429         = ($httpCode === 429);
        $is5xx         = ($httpCode >= 500 && $httpCode <= 599);
        $transientCurl = in_array($curlErrno, [7, 52, 56], true); // connect / empty reply / recv failure
        $isTimeout     = ($curlErrno === 28);                      // operation timed out

        // Operation timeouts get their own (small) budget, separate from the cheap
        // transient retries, since each retry costs another full timeout window.
        if ($isTimeout && $timeoutAttempt < $maxTimeoutRetries) {
            $timeoutAttempt++;
            $wait = min($delaySeconds, 5);
            api_retry_log("$label timeout-retry=$timeoutAttempt/$maxTimeoutRetries curl=28 sleeping={$wait}s");
            sleep($wait);
            continue;
        }

        $retryable = $is429 || $is5xx || $transientCurl;
        if (!$retryable || $attempt >= $maxRetries) {
            return [
                'body'       => $body,
                'http_code'  => $httpCode,
                'curl_errno' => $curlErrno,
                'curl_error' => $curlError,
            ];
        }

        $attempt++;
        // Rate limits need the configured backoff; server/network blips clear faster.
        $wait   = $is429 ? $delaySeconds : min($delaySeconds, 5);
        $reason = $is429 ? 'http=429' : ($is5xx ? "http={$httpCode}" : "curl={$curlErrno}");
        api_retry_log("$label attempt=$attempt/$maxRetries {$reason} sleeping={$wait}s");
        sleep($wait);
    }
}
