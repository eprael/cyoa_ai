<?php
/**
 * Content Settings API (admin) — immediate persistence for the chip editors and
 * the guardrails enable toggle. Returns JSON. POST only.
 *
 * Fields (POST `field` + `value`):
 *   genres             value = JSON array of strings        → story_genres
 *   moods              value = JSON array of strings        → image_moods
 *   styles             value = JSON object {cat:[subs]}     → image_styles
 *   guardrails_enabled value = "1" | "0"                    → guardrails_enabled
 */
session_start();
require_once 'config.php';
require_once 'db_functions.php';
require_once 'settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID']) || empty($_SESSION['isAdmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required.']);
    exit;
}

$field = $_POST['field'] ?? '';
$raw   = $_POST['value'] ?? '';

/** Clean a flat list: trimmed, non-empty, unique, re-indexed. */
function clean_flat_list($decoded): array {
    if (!is_array($decoded)) return [];
    return array_values(array_unique(array_filter(
        array_map(fn($v) => trim((string)$v), $decoded),
        fn($v) => $v !== ''
    )));
}

switch ($field) {
    case 'genres':
    case 'moods':
        $key   = $field === 'genres' ? 'story_genres' : 'image_moods';
        $clean = clean_flat_list(json_decode($raw, true));
        db_set_setting($key, json_encode($clean, JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true, 'value' => $clean]);
        break;

    case 'styles':
        $decoded = json_decode($raw, true);
        $clean = [];
        if (is_array($decoded)) {
            foreach ($decoded as $cat => $subs) {
                $cat = trim((string)$cat);
                if ($cat === '' || !is_array($subs)) continue;
                $clean[$cat] = clean_flat_list($subs);
            }
        }
        db_set_setting('image_styles', json_encode($clean, JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true, 'value' => $clean]);
        break;

    case 'guardrails_enabled':
        $val = ($raw === '1' || $raw === 1 || $raw === true) ? '1' : '0';
        db_set_setting('guardrails_enabled', $val);
        echo json_encode(['success' => true, 'value' => $val]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown field.']);
        break;
}
