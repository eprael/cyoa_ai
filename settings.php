<?php
global $SETTINGS;
$SETTINGS = db_get_all_settings();

const SETTING_DEFAULTS = [
    'anthropic_model'         => 'claude-sonnet-4-6',
    'openai_image_model'      => 'gpt-image-2',
    'openai_image_quality'    => 'medium',
    'scene_thumb_size'        => '200',
    'ai_enabled'              => '1',
    'ai_job_timeout_seconds'  => '600',
    'ai_max_pending_per_user'        => '5',
    'ai_max_concurrent_image_jobs'  => '2',
    // Per-image OpenAI request timeout (seconds) — the curl wait for one image,
    // distinct from ai_job_timeout_seconds (the overall stuck-job watchdog).
    'ai_image_request_timeout'      => '300',
    // Per-call Claude request timeout (seconds) — the curl wait for one Claude
    // plan/scene call. A full story makes many of these in sequence, so keep the
    // Job Timeout watchdog comfortably above this.
    'ai_claude_request_timeout'     => '180',
    'app_title'               => 'Choose Your Own Adventure!',
    // Phase 28 — content settings (admin-editable; defaults below so the app works pre-migration)
    'image_styles'            => '{"Photographic":["Photo-realistic","Cinematic / Film still","Portrait / Studio lighting","Golden hour / Natural light","Black & white / Noir","Polaroid / Vintage film","Aerial / Drone shot"],"Illustration":["Anime / Manga","Cartoon / Saturday morning cartoon","Comic book / Marvel-DC style","Caricature","Children\'s book illustration","Flat design / Vector","Sticker art","Pixel art / 8-bit / 16-bit"],"Drawing & Painting":["Sketch / Pencil drawing","Line drawing / Ink","Charcoal","Watercolor","Oil painting","Acrylic","Gouache","Impressionist","Pointillism","Expressionist"],"Art Movement / Era":["Art Deco","Pop Art (Warhol-style)","Surrealist","Renaissance / Baroque","Ukiyo-e (Japanese woodblock)","Victorian / Edwardian"],"Digital & Concept":["Concept art / Game art","Sci-fi / Futuristic","Fantasy illustration","Dark fantasy / Gothic","Cyberpunk","Steampunk","Low poly / 3D render","Vaporwave / Synthwave"],"Craft & Texture":["Stained glass","Mosaic / Tile art","Graffiti / Street art","Linocut / Woodcut print","Embroidery / Needlework","Claymation / Stop-motion look","LEGO / Toy style"]}',
    'image_moods'             => '["Dramatic lighting / Chiaroscuro","Neon / Glowing","Soft pastel","High contrast / Monochrome","Ethereal / Dreamy","Gritty / Textured"]',
    'story_genres'            => '["Adventure","Fantasy","Fairy Tale","Mythology","Sci-Fi","Dystopian","Steampunk","Mystery","Thriller","Spy","Horror","Western","Superhero","Survival","Romance","Comedy","Slice of Life","Historical","Educational","Other"]',
    'openai_image_format'     => 'jpeg',
    // Phase 29 — AI content guardrails (one restricted topic per line)
    'guardrails_enabled'      => '1',
    'guardrails_text'         => "Child Abuse\nSuicide\nExplicit sexual content or nudity\n"
                              . "Extreme graphic violence or gore\nDeeply nihilistic or hopeless themes\n"
                              . "Drug/alcohol use",
    // Phase 30 — maintenance retention windows ('1day' | '1week' | '1month')
    'trash_retention'         => '1week',
    'log_retention'           => '1month',
    // Phase 40 — pagination page sizes (gallery grid, job history table)
    'gallery_page_size'       => '12',
    'jobs_history_page_size'  => '25',
    // v7 — per-story image gallery sizing (px; presets in config.php GALLERY_* maps)
    'gallery_tile_size'       => '220',
    'gallery_filmstrip_size'  => '72',
    'gallery_tile_spacing'    => '16',
    // anthropic_api_key and openai_api_key intentionally omitted — null if not configured
];

function app_setting(string $key): ?string {
    global $SETTINGS;
    return $SETTINGS[$key] ?? SETTING_DEFAULTS[$key] ?? null;
}

/**
 * Is a usable API key available for the given AI provider?
 *
 * Mirrors the precedence the cron handlers use: a user's own (BYOK) key takes
 * priority over the site-wide key, but for *availability* we only care that at
 * least one of them is present.
 *
 *   - 'claude' (Anthropic) → drives text: full-story + scene generation
 *   - 'openai'             → drives images: cover + scene images
 *
 * @param string     $provider 'claude' or 'openai'
 * @param array|null $user     user row with claude_api_key / openai_api_key (or null)
 */
function ai_provider_available(string $provider, ?array $user = null): bool {
    if ($provider === 'claude') {
        if ($user && !empty($user['claude_api_key'])) return true;
        return !empty(app_setting('anthropic_api_key'));
    }
    if ($provider === 'openai') {
        if ($user && !empty($user['openai_api_key'])) return true;
        return !empty(app_setting('openai_api_key'));
    }
    return false;
}

/**
 * Placeholder for an API-key field: masked dots ending in the saved key's last 4
 * chars, so a user/admin can verify which key is set without the full secret
 * reaching the browser. Falls back to the example hint when no key is stored.
 * Shared by the admin Site/AI settings and the per-user BYOK account page.
 */
function api_key_placeholder(?string $key, string $hint): string {
    if (!$key) return $hint;
    $last = strlen($key) > 4 ? substr($key, -4) : $key;
    return str_repeat('•', 12) . $last;
}

/**
 * Phase 29 — Build the comma-separated guardrail topic list for prompt injection.
 *
 * Returns an empty string when guardrails are disabled or the restriction list is
 * empty; callers skip all guardrail injection / red_flag handling when it is empty.
 */
function get_guardrail_clause(): string {
    if (!(bool)(int) app_setting('guardrails_enabled')) return '';
    $lines = array_filter(array_map('trim',
        explode("\n", app_setting('guardrails_text') ?? '')
    ));
    if (empty($lines)) return '';
    return implode(', ', $lines);
}

/**
 * Phase 30 — Map a retention setting value to a MySQL INTERVAL expression.
 * Used by maintenance cleanup queries. Defaults to 30 days for unknown values.
 */
function retention_to_interval(string $val): string {
    return match ($val) {
        '1day'  => 'INTERVAL 1 DAY',
        '1week' => 'INTERVAL 7 DAY',
        default => 'INTERVAL 30 DAY',  // 1month
    };
}
