<?php
require_once '../config/db.php';
require_once '../config/reactions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$post_type = trim($_POST['post_type'] ?? '');
$post_id   = (int)($_POST['post_id'] ?? 0);
$emoji     = trim($_POST['emoji']    ?? '');
$ip        = $_SERVER['REMOTE_ADDR'];

if (!$post_type || !$post_id || !$emoji) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing fields.']);
    exit;
}

$db = get_db();
$ban = $db->prepare(
    "SELECT id FROM bans
     WHERE ip=? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1"
);
$ban->execute([$ip]);
if ($ban->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'You are banned.']);
    exit;
}

$result = toggle_reaction($post_type, $post_id, $emoji, $ip, $db);
echo json_encode($result);
