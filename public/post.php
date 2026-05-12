<?php
require_once '../config/db.php';
$db     = get_db();
$action = $_POST['action'] ?? '';
$slug   = $_POST['board_slug'] ?? '';

$board = $db->prepare("SELECT * FROM boards WHERE slug = ?");
$board->execute([$slug]);
$board = $board->fetch(PDO::FETCH_ASSOC);
if (!$board) die('Invalid board.');

$ip        = $_SERVER['REMOTE_ADDR'];
$ban_check = $db->prepare(
    "SELECT * FROM bans WHERE ip = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1"
);
$ban_check->execute([$ip]);
$ban = $ban_check->fetch(PDO::FETCH_ASSOC);

if ($ban) {
    http_response_code(403);
    $reason = $ban['reason'] ? htmlspecialchars($ban['reason']): 'No reason given.';
    $expiry = $ban['expires_at'] ? date('Y-m-d H:i', strtotime($ban['expires_at'])): 'Permanent';
    die("
      <link rel='stylesheet' href='style.css'>
    ");
}

function handle_upload(): ?string {
    if (empty($_FILES['image']['tmp_name'])) return null;
    $f    = $_FILES['image'];
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $ok   = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $ok)) die('Invalid image type.');
    if ($f['size'] > 4 * 1024 * 1024) die('Image too large (max 4 MB).');
    $name = uniqid('img_', true) . '.' . $ext;
    $dest = __DIR__ . '/../uploads/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) die('Upload failed.');
    return $name;
}

if ($action === 'new_thread') {
    $body    = trim($_POST['body'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    if (!$body) die('Body required.');
    $img = handle_upload();
    $s = $db->prepare(
        "INSERT INTO threads (board_id, subject, body, img_path) VALUES (?,?,?,?) RETURNING id"
    );
    $s->execute([$board['id'], $subject ?: null, $body, $img]);
    $tid = $s->fetchColumn();
    header("Location: thread.php?id=$tid");

} elseif ($action === 'reply') {
    $tid  = (int)($_POST['thread_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    if (!$body) die('Body required.');
    $img = handle_upload();
    $s = $db->prepare(
        "INSERT INTO posts (thread_id, body, img_path) VALUES (?,?,?)"
    );
    $s->execute([$tid, $body, $img]);
    
    // bump thread
    $db->prepare("UPDATE threads SET bumped_at=NOW() WHERE id=?")->execute([$tid]);
    header("Location: thread.php?id=$tid");

} else {
    die('Unknown action!!');
}
