<?php
/**
 * Validator for the Phase 41 PLAY_FONTS allow-list (data/play_fonts.json).
 *
 * For each entry it requests the actual Google Fonts css2 URL (family + the
 * listed weights) and flags any that don't resolve — catching typo'd families
 * or unavailable weights before a story can render with a broken/fallback font.
 * Re-run whenever you add or edit a font in data/play_fonts.json.
 *
 * Run from the project root:  php cli/validate_play_fonts.php
 *
 * A "modern" User-Agent is sent so css2 returns @font-face rules (woff2). An
 * invalid family makes css2 respond HTTP 400 with "Could not find families".
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../fonts.php';

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

function fetch_css(string $url, string $ua): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$body, 'err' => $err];
    }
    $ctx  = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 20, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    return ['code' => $code, 'body' => (string)$body, 'err' => $body === false ? 'request failed' : ''];
}

$fonts = play_fonts();
echo "Validating " . count($fonts) . " PLAY_FONTS entries against Google Fonts css2…\n\n";

$fail = [];
$ok   = 0;
foreach ($fonts as $f) {
    $url = play_font_css2_url($f['family']);
    $r   = fetch_css($url, $ua);
    $good = ($r['code'] === 200)
        && stripos($r['body'], 'Could not find') === false
        && stripos($r['body'], '@font-face') !== false;
    if ($good) {
        $ok++;
        printf("  OK    %-22s [%s]\n", $f['family'], $f['weights']);
    } else {
        $fail[] = $f['family'];
        printf("  FAIL  %-22s [%s]  http=%d %s\n", $f['family'], $f['weights'], $r['code'], $r['err']);
    }
    usleep(150000); // be gentle
}

echo "\n----------------------------------------\n";
echo "OK: $ok / " . count($fonts) . "\n";
if ($fail) {
    echo "FAILED (" . count($fail) . "): " . implode(', ', $fail) . "\n";
    exit(1);
}
echo "All families + weights resolve. ✔\n";
