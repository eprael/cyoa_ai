<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_functions.php';

header('Content-Type: text/plain; charset=utf-8');

$conn = db_connect();

$source = PHP_SAPI === 'cli' ? 'cli' : 'web';
$note = 'Cron test run';

$stmt = $conn->prepare(
    'INSERT INTO ' . DB_PREFIX . 'cron_test_runs (run_source, run_note, created_at) VALUES (?, ?, NOW())'
);

if (!$stmt) {
    http_response_code(500);
    echo 'Prepare failed: ' . $conn->error;
    $conn->close();
    exit;
}

$stmt->bind_param('ss', $source, $note);
$success = $stmt->execute();

if (!$success) {
    http_response_code(500);
    echo 'Insert failed: ' . $stmt->error;
    $stmt->close();
    $conn->close();
    exit;
}

$insertId = $conn->insert_id;
$stmt->close();
$conn->close();

echo 'Inserted cron test row #' . $insertId . ' via ' . $source . ' at ' . date('Y-m-d H:i:s') . PHP_EOL;
