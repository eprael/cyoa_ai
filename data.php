<?php
/**
 * JSON reference-data loader.
 *
 * Editorial/content reference data (audiences, the play-font allow-list, theme
 * presets) lives in data/*.json rather than as config.php constants, so it can be
 * edited without touching PHP. This is the single, request-cached reader; the
 * domain accessors — story_audiences() (db_functions.php), play_fonts() (fonts.php),
 * theme_presets() (theme.php) — wrap it.
 *
 * Each file is read and decoded once per request. A missing or malformed file
 * yields [] so callers degrade gracefully (they apply their own defaults).
 */
function load_data_json(string $name): array {
    static $cache = [];
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    $path = __DIR__ . '/data/' . basename($name) . '.json';
    $data = [];
    if (is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    return $cache[$name] = $data;
}
