<?php
/**
 * Data-driven theme engine (Phase 42).
 *
 * A play theme is a small set of *values* — a body font + heading font (both from
 * the Phase 41 PLAY_FONTS allow-list) and bg/text/accent hex colours — stored as
 * JSON on the story (`stories.theme_json`). play.php injects these as a `:root`
 * variable block and styles/play_theme.css derives every bespoke shade from them
 * via color-mix(). This file holds the resolution, the hard sanitization (the
 * CSS-injection guard), and the small render helpers.
 *
 * SECURITY: never inject raw AI/user strings into CSS. theme_sanitize() is the
 * single gate — colours must match a strict hex regex, fonts must be on the
 * allow-list, sizes are clamped — so theme_css_vars()/theme_font_links() only
 * ever emit validated values.
 *
 * Theme presets live in data/themes.json (loaded via data.php); the font
 * allow-list lives in data/play_fonts.json (fonts.php). Both are required below.
 */

require_once __DIR__ . '/data.php';
if (!function_exists('play_font_is_allowed')) {
    require_once __DIR__ . '/fonts.php';
}

// ── Colour helpers ──────────────────────────────────────────────

/** Strict #RRGGBB hex check (the colour-injection guard). */
function theme_is_hex($v): bool {
    return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1;
}

function theme_hex_to_rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** WCAG relative luminance of an #RRGGBB colour. */
function theme_relative_luminance(string $hex): float {
    [$r, $g, $b] = theme_hex_to_rgb($hex);
    $lin = function ($c) {
        $c /= 255;
        return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
}

/** WCAG contrast ratio (1–21) between two #RRGGBB colours. */
function theme_contrast_ratio(string $a, string $b): float {
    $la = theme_relative_luminance($a);
    $lb = theme_relative_luminance($b);
    $hi = max($la, $lb);
    $lo = min($la, $lb);
    return ($hi + 0.05) / ($lo + 0.05);
}

// ── Presets ─────────────────────────────────────────────────────

function theme_presets(): array {
    return load_data_json('themes')['presets'] ?? [];
}

/** Default preset key (from data/themes.json). */
function theme_default_key(): string {
    return load_data_json('themes')['default'] ?? 'forest';
}

function theme_preset(string $key): array {
    $presets = theme_presets();
    if (isset($presets[$key])) return $presets[$key];
    $defKey = theme_default_key();
    return $presets[$defKey] ?? (reset($presets) ?: ['name' => 'Default', 'font' => 'Lora', 'font_heading' => 'Playfair Display', 'bg' => '#0d1a0d', 'text' => '#b8d4b8', 'accent' => '#4caf50']);
}

/** Loose genre → preset-key mapping, used only as an AI/user fallback seed. */
function theme_preset_for_genre(string $genre): string {
    $g = mb_strtolower(trim($genre));
    $map = [
        'adventure'       => 'forest',
        'fantasy'         => 'forest',
        'fairy tale'      => 'royal',
        'mythology'       => 'egyptian',
        'sci-fi'          => 'scifi',
        'scifi'           => 'scifi',
        'science fiction' => 'scifi',
        'dystopian'       => 'cyberpunk',
        'steampunk'       => 'parchment',
        'mystery'         => 'ocean',
        'thriller'        => 'noir',
        'spy'             => 'noir',
        'horror'          => 'crimson',
        'western'         => 'desert',
        'superhero'       => 'arcade',
        'survival'        => 'desert',
        'romance'         => 'rose',
        'comedy'          => 'sunny',
        'slice of life'   => 'frost',
        'historical'      => 'parchment',
        'educational'     => 'frost',
    ];
    return $map[$g] ?? theme_default_key();
}

// ── Sanitization (the single injection gate) ────────────────────

/**
 * Validate/repair a raw theme (array or JSON string) into a complete, safe value
 * set. Invalid colours/fonts fall back per-field to $fallbackKey's preset; a
 * text-vs-bg contrast below WCAG AA falls back both colours together; the base
 * size is clamped. Always returns a usable theme.
 *
 * @return array{font:string,font_heading:string,bg:string,text:string,accent:string,base_size:int}
 */
function theme_sanitize($raw, string $fallbackKey = 'forest'): array {
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) $raw = [];
    $p = theme_preset($fallbackKey);

    $bg     = theme_is_hex($raw['bg']     ?? null) ? strtolower($raw['bg'])     : $p['bg'];
    $text   = theme_is_hex($raw['text']   ?? null) ? strtolower($raw['text'])   : $p['text'];
    $accent = theme_is_hex($raw['accent'] ?? null) ? strtolower($raw['accent']) : $p['accent'];

    // Readability: text must contrast the background (WCAG AA ~4.5:1).
    if (theme_contrast_ratio($text, $bg) < 4.5) {
        $bg   = $p['bg'];
        $text = $p['text'];
    }
    // Accent should be at least distinguishable from the background.
    if (theme_contrast_ratio($accent, $bg) < 1.6) {
        $accent = $p['accent'];
    }

    // Fonts must be on the allow-list; use canonical casing.
    $font    = play_font_is_allowed($raw['font'] ?? null)         ? play_font_canonical($raw['font'])         : $p['font'];
    $heading = play_font_is_allowed($raw['font_heading'] ?? null) ? play_font_canonical($raw['font_heading']) : $p['font_heading'];

    $size = isset($raw['base_size']) ? (int)$raw['base_size'] : 18;
    $size = max(14, min(22, $size));

    return [
        'font'         => $font,
        'font_heading' => $heading,
        'bg'           => $bg,
        'text'         => $text,
        'accent'       => $accent,
        'base_size'    => $size,
    ];
}

/** Sanitize and re-encode to JSON for storage (stories.theme_json). */
function theme_to_json($raw, string $fallbackKey = 'forest'): string {
    return json_encode(theme_sanitize($raw, $fallbackKey));
}

/**
 * Build a sanitized engine theme from the AI's plan `theme` object
 * ({font, bg, text, accent}) plus the story's genre. The AI picks a single
 * `font` from the allow-list; this derives the body/heading pair from it (using
 * the font's role) and fills the other slot with a genre-appropriate default, so
 * body stays readable and the heading stays expressive. Off-list fonts and bad
 * colours fall back via theme_sanitize() against the genre's preset.
 */
function theme_from_ai($aiTheme, string $genre): array {
    $aiTheme     = is_array($aiTheme) ? $aiTheme : [];
    $fallbackKey = theme_preset_for_genre($genre);

    $rawFont = $aiTheme['font'] ?? '';
    $canon   = play_font_is_allowed($rawFont) ? play_font_canonical($rawFont) : null;

    if ($canon !== null) {
        $meta = play_font_meta($canon);
        if (($meta['role'] ?? 'body') === 'heading') {
            $heading = $canon;
            $body    = play_font_default_for_mood($genre, 'body');
        } else {
            $body    = $canon;
            $heading = play_font_default_for_mood($genre, 'heading');
        }
    } else {
        $body    = play_font_default_for_mood($genre, 'body');
        $heading = play_font_default_for_mood($genre, 'heading');
    }

    return theme_sanitize([
        'font'         => $body,
        'font_heading' => $heading,
        'bg'           => $aiTheme['bg']     ?? null,
        'text'         => $aiTheme['text']   ?? null,
        'accent'       => $aiTheme['accent'] ?? null,
    ], $fallbackKey);
}

// ── Resolution for the play page ────────────────────────────────

/**
 * Engine theme values for a story, or null when the story has no usable
 * theme_json (the legacy per-file-theme path in play.php still applies).
 * The legacy `theme` slug, if a known preset, seeds the per-field fallback.
 */
function theme_resolve_engine(array $story): ?array {
    if (empty($story['theme_json'])) return null;
    $decoded = json_decode((string)$story['theme_json'], true);
    if (!is_array($decoded)) return null;
    $fallbackKey = (!empty($story['theme']) && isset(theme_presets()[$story['theme']]))
        ? $story['theme']
        : theme_default_key();
    return theme_sanitize($decoded, $fallbackKey);
}

// ── Render helpers (only ever receive validated values) ─────────

/** The inline `:root { … }` custom-property block for a sanitized theme. */
function theme_css_vars(array $t): string {
    $vars = [
        '--bg: '           . $t['bg'],
        '--text: '         . $t['text'],
        '--accent: '       . $t['accent'],
        '--font: '         . play_font_stack($t['font']),
        '--font-heading: ' . play_font_stack($t['font_heading']),
        '--base-size: '    . (int)$t['base_size'] . 'px',
    ];
    return ':root{' . implode('; ', $vars) . ';}';
}

/** Google Fonts <link> tags for a theme's body + heading families (deduped). */
function theme_font_links(array $t): string {
    $urls = [];
    $urls[play_font_css2_url($t['font'])]         = true;
    $urls[play_font_css2_url($t['font_heading'])] = true;
    $out  = '<link rel="preconnect" href="https://fonts.googleapis.com">'
          . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    foreach (array_keys($urls) as $u) {
        $out .= '<link rel="stylesheet" href="' . htmlspecialchars($u) . '">';
    }
    return $out;
}
