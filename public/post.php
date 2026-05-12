<?php
require_once '../config/db.php';
$db     = get_db();

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
      <div style='max-width:400px;margin:60px auto;background:#1e1e1e;
                  border:1px solid #ff4444;padding:20px;font-family;Courier New;'>
           <div style='color:#ff4444;font-size:16px;font-weight:bold;margin-bottom:10px;'>
               You are BANNED.
           </div>
           <div style='color:#aaa;font-size:13px;'>Reason: $reason</div>
           <div style='color:#aaa;font-size:13px;'>Expires: $expiry</div>
      </div>
    ");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed!');
}

$action = $_POST['action'] ?? '';
$slug   = $_POST['board_slug'] ?? '';

if (!$action || $slug) {
    die('Missing action on board..');
}

$board = $db->prepare("SELECT * FROM boards WHERE slug = ?");
$board->execute([$slug]);
$board = $board->fetch(PDO::FETCH_ASSOC);
if (!$board) die('Invalid board.');

function handle_upload(): ?string {
    if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $f    = $_FILES['image'];
    $err  = $f['error'];

    if ($err !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE     => 'File too large (server limit).',
            UPLOAD_ERR_FORM_SIZE    => 'File too large (form limit).',
            UPLOAD_ERR_PARTIAL      => 'Upload was cutoff, try again!',
            UPLOAD_ERR_NO_TMP_DIR   => 'Server misconfiguration: no temp dir.',
            UPLOAD_ERR_CANT_WRITE   => 'Server could not write file to disk.',
            UPLOAD_ERR_EXTENSION    => 'Upload blocked by server extension.',
        ];
        die($msgs[$err] ?? "Upload error code $err.");
    }

    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($f['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        die("File type not allowed: $mime");
    }

    if ($f['size'] > 4 * 1024 * 1024) {
        die("Image too large (max 4MB).");
    }

    $ext  = $allowed[$mime];
    $name = uniqid('img_', true) . '.' . $ext;
    $dest = __DIR__ . '/uploads/' . $name;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        die('Could not move uploaded file. Check uploads/ permissions.');
    }
    
    chmod($dest, 0644);
    return $name;
}

if ($action === 'new_thread') {

    $body    = trim($_POST['body'] ?? '');
    $subject = trim($_POST['subject'] ?? '');

    if ($body === '') {
        die('Post body is required.');
    }

    $img = handle_upload();

    $s = $db->prepare(
        "INSERT INTO threads (board_id, subject, body, img_path, ip)
         VALUES (?, ?, ?, ?, ?) RETURNING id"
    );
    $s->execute([
        $board['id'],
        $subject !== '' ? $subject : null,
        $body,
        $img,
        $ip
    ]);
    $tid = $s->fetchColumn();
    header("Location: thread.php?id=$tid");
    exit;

} elseif ($action === 'reply') {

    $tid  = (int)($_POST['thread_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');

    if ($tid <= 0) die('Invalid thread.');
    if ($body === '') die('Reply body is required.');

    // Verify thread exists
    $tc = $db->prepare("SELECT id FROM threads WHERE id = ?");
    $tc->execute([$tid]);
    if (!$tc->fetch()) die('Thread not found.');

    $img = handle_upload();

    $s = $db->prepare(
        "INSERT INTO posts (thread_id, body, img_path, ip)
         VALUES (?, ?, ?, ?)"
    );
    $s->execute([$tid, $body, $img, $ip]);

    $db->prepare("UPDATE threads SET bumped_at = NOW() WHERE id = ?")
       ->execute([$tid]);

    header("Location: thread.php?id=$tid");
    exit;

} else {
    die('Unknown action: ' . htmlspecialchars($action));
}
