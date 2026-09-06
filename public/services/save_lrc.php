<?php
session_start();

require_once '../../config/db.php';
require_once '../../config/cache.php';
require_once '../../config/tripcode.php';
require_once '../../config/audio.php';

require_once __DIR__ . '/../../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__ . '/../../.env'));
$dotenv->load();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$db = get_db();

$owner_type  = $_POST['owner_type'] ?? '';
$owner_id    = (int)($_POST['owner_id'] ?? 0);
$credentials = trim($_POST['credentials'] ?? '');
$lrc_text    = $_POST['lrc'] ?? '';

if (!in_array($owner_type, ['thread', 'post'], true) || $owner_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid target.']);
    exit;
}

if (trim($lrc_text) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Paste some LRC text first.']);
    exit;
}

[, $submitted_trip] = parse_tripcode($credentials);

if ($submitted_trip === null) {
    http_response_code(403);
    echo json_encode(['error' => 'A password is required to edit lyrics (use name#password, or just #password if posting anonymously).']);
    exit;
}

$table = $owner_type === 'thread' ? 'threads' : 'posts';

$stmt = $db->prepare("SELECT id, tripcode, img_path FROM {$table} WHERE id = ?");
$stmt->execute([$owner_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Post not found.']);
    exit;
}

if (empty($row['img_path']) || !preg_match('/\.(mp3|flac|ogg|wav)$/i', $row['img_path'])) {
    http_response_code(400);
    echo json_encode(['error' => 'This post has no audio attached.']);
    exit;
}

if (empty($row['tripcode']) || $row['tripcode'] !== $submitted_trip) {
    http_response_code(403);
    echo json_encode(['error' => 'Password does not match this post.']);
    exit;
}

$lrc_data = parse_lrc($lrc_text);

if (!$lrc_data) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not find any timestamped lines in that LRC.']);
    exit;
}

$s3key    = $_ENV['S3_KEY'];
$s3secret = $_ENV['S3_SECRET'];

$s3 = new S3Client([
    'version'  => 'latest',
    'region'   => 'us-east-1',
    'endpoint' => 'http://localhost:9000', // Endpoint will change once deployed
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key'    => $s3key,
        'secret' => $s3secret,
    ],
]);

$bucket = '6chan';

$lrc_key = $row['img_path'] . '.lrc.json';

try {
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $lrc_key,
        'Body'        => json_encode(['lrc' => $lrc_data], JSON_UNESCAPED_UNICODE),
        'ContentType' => 'application/json',
    ]);
} catch (AwsException $e) {
    error_log('save_lrc.php: upload failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Storage error, try again.']);
    exit;
}

// Bust cached board/thread HTML so the new lyrics show up right away.
if ($owner_type === 'thread') {
    $b = $db->prepare("SELECT board_id FROM threads WHERE id = ?");
    $b->execute([$owner_id]);
    $board_id = $b->fetchColumn();
    if ($board_id) cache_delete("board:{$board_id}:p1", $db);
    cache_delete("thread:{$owner_id}", $db);
} else {
    $t = $db->prepare("SELECT thread_id FROM posts WHERE id = ?");
    $t->execute([$owner_id]);
    $thread_id = $t->fetchColumn();
    if ($thread_id) cache_delete("thread:{$thread_id}", $db);
}

echo json_encode(['ok' => true, 'lines' => count($lrc_data)]);
