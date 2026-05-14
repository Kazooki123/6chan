<?php
$config_path = __DIR__ . '/../config/db.php';
if (!file_exists($config_path)) {
    die("FATAL: config/db.php not found at: $config_path
         <br>__DIR__ is: " . __DIR__);
}

require_once '../config/db.php';
$db = get_db();

$ip = $_SERVER['REMOTE_ADDR'];
$ban_check = $db->prepare(
    "SELECT * FROM bans WHERE ip = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1"
);
$ban_check->execute([$ip]);
$ban = $ban_check->fetch(PDO::FETCH_ASSOC);
if ($ban) {
    http_response_code(403);
    $reason = htmlspecialchars($ban['reason'] ?? 'No reason given.');
    $expiry = $ban['expires_at'] ? date('Y-m-d H:i', strtotime($ban['expires_at'])) : 'Permanent';
    die("
        <link rel='stylesheet' href='style.css'>
        <div style='max-width:400px;margin:60px auto;background:#1e1e1e;
                    border:1px solid #ff4444;padding:20px;font-family:Courier New;'>
            <div style='color:#ff4444;font-size:16px;font-weight:bold;margin-bottom:10px;'>
                You are banned.
            </div>
            <div style='color:#aaa;font-size:13px;'>Reason: $reason</div>
            <div style='color:#aaa;font-size:13px;'>Expires: $expiry</div>
        </div>
    ");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

$action = trim($_POST['action'] ?? '');
$slug   = trim($_POST['board_slug'] ?? '');

if (!$action || !$slug) {
    // Debug dump so you can see exactly what arrived
    http_response_code(400);
    die('Missing action or board. Received POST keys: '
        . implode(', ', array_keys($_POST))
        . ' | action="' . htmlspecialchars($action)
        . '" | board_slug="' . htmlspecialchars($slug) . '"');
}

$board_stmt = $db->prepare("SELECT * FROM boards WHERE slug = ?");
$board_stmt->execute([$slug]);
$board = $board_stmt->fetch(PDO::FETCH_ASSOC);
if (!$board) die('Invalid board: ' . htmlspecialchars($slug));

function handle_upload(): ?string {
    if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $f   = $_FILES['image'];
    $err = $f['error'];

    if ($err !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'File too large (server limit).',
            UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit).',
            UPLOAD_ERR_PARTIAL    => 'Upload was cut off, try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration: no temp dir.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
        ];
        die($msgs[$err] ?? "Upload error code: $err");
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
        die('Image too large (max 4 MB).');
    }

    $ext  = $allowed[$mime];
    $name = uniqid('img_', true) . '.' . $ext;
    $dest = __DIR__ . '/uploads/' . $name;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        die('Could not save file. Check that uploads/ exists and is writable (chmod 775).');
    }

    chmod($dest, 0644);
    return $name;
}

if ($action === 'new_thread') {

    $body    = trim($_POST['body'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $author  = trim($_POST['author'] ?? '');
    $author  = ($author === '') ? 'Anon' : $author;
    $options = strtolower(trim($_POST['options'] ?? ''));
    $is_sage = ($options === 'sage');

    if (!$is_sage) {
        $db->prepare("UPDATE threads SET bumped_at = NOW() WHERE id = ?")
           ->execute([$tid]);
    }
    
    if ($body === '') die('Post body is required.');

    $img = handle_upload();

    $s = $db->prepare(
        "INSERT INTO threads (board_id, subject, body, img_path, ip, author)
         VALUES (?, ?, ?, ?, ?, ?) RETURNING id"
    );
    $s->execute([
        $board['id'],
        $subject !== '' ? $subject : null,
        $body,
        $img,
        $ip,
        $author
    ]);
    $tid = $s->fetchColumn();
    header("Location: thread.php?id=$tid");
    exit;

} elseif ($action === 'reply') {

    $tid  = (int)($_POST['thread_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    $author  = trim($_POST['author'] ?? '');
    $author  = ($author === '') ? 'Anon' : $author;
    
    if ($tid <= 0)    die('Invalid thread ID.');
    if ($body === '') die('Reply body is required.');

    $tc = $db->prepare("SELECT id, locked FROM threads WHERE id = ?");
    $tc->execute([$tid]);
    $row = $tc->fetch();
    if (!$row)          die('Thread not found.');
    if ($row['locked']) die('This thread is locked! No new replies.');
    
    $img = handle_upload();

    $s = $db->prepare(
        "INSERT INTO posts (thread_id, body, img_path, ip, author, options)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $s->execute([$tid, $body, $img, $ip, $author, $is_sage ? 'sage' : null]);

    $db->prepare("UPDATE threads SET bumped_at = NOW() WHERE id = ?")
       ->execute([$tid]);

    header("Location: thread.php?id=$tid");
    exit;

} else {
    die('Unknown action: ' . htmlspecialchars($action));
}
