<?php
/**
 * Play-font allow-list accessors (Phase 41) — read-only helpers over the curated
 * font data in data/play_fonts.json. This is the contract consumed by the Phase 42
 * theme engine: a role-aware list (body vs heading), a moods→families view for
 * the AI prompt, a membership check + per-mood default for server-side validation,
 * and the Google Fonts css2 URL builder used by play.php's runtime <link>.
 *
 * Font data lives in data/play_fonts.json (loaded via data.php).
 */

require_once __DIR__ . '/data.php';

/** All allow-list entries (from data/play_fonts.json). */
function play_fonts(): array {
    return load_data_json('play_fonts')['fonts'] ?? [];
}

/** Default body/heading family when no mood match is found. */
function play_font_default(string $role): string {
    $defaults = load_data_json('play_fonts')['defaults'] ?? [];
    if ($role === 'heading') return $defaults['heading'] ?? 'Playfair Display';
    return $defaults['body'] ?? 'Lora';
}

/** All family names on the list. */
function play_font_families(): array {
    return array_map(fn($f) => $f['family'], play_fonts());
}

/** Lookup an entry by family name (case-insensitive). Null if not on the list. */
function play_font_meta(?string $family): ?array {
    if ($family === null || $family === '') return null;
    $needle = mb_strtolower(trim($family));
    foreach (play_fonts() as $f) {
        if (mb_strtolower($f['family']) === $needle) return $f;
    }
    return null;
}

/** Whether a family is on the allow-list (the hard validation guard). */
function play_font_is_allowed(?string $family): bool {
    return play_font_meta($family) !== null;
}

/** Canonical (exact-cased) family name for an allow-list entry, or null. */
function play_font_canonical(?string $family): ?string {
    $meta = play_font_meta($family);
    return $meta['family'] ?? null;
}

/** Entries restricted to a role: 'body' or 'heading'. */
function play_fonts_for_role(string $role): array {
    return array_values(array_filter(play_fonts(), fn($f) => $f['role'] === $role));
}

/** Entries tagged with a given mood (case-insensitive); optionally limited to a role. */
function play_fonts_by_mood(string $mood, ?string $role = null): array {
    $m = mb_strtolower(trim($mood));
    return array_values(array_filter(play_fonts(), function ($f) use ($m, $role) {
        if ($role !== null && $f['role'] !== $role) return false;
        foreach ($f['moods'] as $tag) {
            if (mb_strtolower($tag) === $m) return true;
        }
        return false;
    }));
}

/**
 * A sensible default family for a mood + role, used as the server-side fallback
 * when the AI returns an off-list font. Prefers a mood match *within the same
 * role* (never crosses roles — a body fallback stays readable, a heading stays
 * expressive), then the global per-role default.
 */
function play_font_default_for_mood(string $mood, string $role = 'body'): string {
    $byBoth = play_fonts_by_mood($mood, $role);
    if (!empty($byBoth)) return $byBoth[0]['family'];

    return play_font_default($role);
}

/** The full CSS fallback stack for a family ("'Cinzel', serif"). */
function play_font_stack(string $family): string {
    $meta = play_font_meta($family);
    $fallback = $meta['fallback'] ?? 'serif';
    return "'" . str_replace("'", '', $family) . "', " . $fallback;
}

/**
 * Build a Google Fonts css2 URL for one family using its listed weights.
 * Off-list families resolve to weight 400 only (defensive — callers should
 * validate against the allow-list first).
 */
function play_font_css2_url(string $family): string {
    $meta    = play_font_meta($family);
    $weights = $meta['weights'] ?? '400';
    $fam     = str_replace(' ', '+', trim($family));
    $spec    = $fam . ($weights !== '' ? ':wght@' . $weights : '');
    return 'https://fonts.googleapis.com/css2?family=' . $spec . '&display=swap';
}

/**
 * A compact moods → families map for the AI prompt (so the model picks a real,
 * on-list family per mood). Optionally limited to a role.
 */
function play_fonts_mood_map(?string $role = null): array {
    $map = [];
    foreach (play_fonts() as $f) {
        if ($role !== null && $f['role'] !== $role) continue;
        foreach ($f['moods'] as $mood) {
            $map[$mood][] = $f['family'];
        }
    }
    ksort($map);
    return $map;
}
