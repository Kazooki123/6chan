<?php
$config_path = __DIR__ . '/../config/db.php';
if (!file_exists($config_path)) {
    die("FATAL: config/db.php not found at: $config_path
         <br>__DIR__ is: " . __DIR__);
}

require_once '../config/db.php';
require_once '../config/automod.php';

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
                    border:1px solid #ff4444;padding:20px;font-family:MapleMono-Semibold;'>
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
        'image/jpeg' => ['ext' => 'jpg',  'max' => 4 * 1024 * 1024],
        'image/png'  => ['ext' => 'png',  'max' => 4 * 1024 * 1024],
        'image/gif'  => ['ext' => 'gif',  'max' => 8 * 1024 * 1024],   // 8MB (for hq gifs)
        'image/webp' => ['ext' => 'webp', 'max' => 4 * 1024 * 1024],
        'video/mp4'  => ['ext' => 'mp4',  'max' => 10 * 1024 * 1024],  // 10MB
    ];

    if (!isset($allowed[$mime])) {
        die("File type not allowed: $mime");
    }

    if ($f['size'] > $allowed[$mime]['max']) {
        $cap = $allowed[$mime]['max'] / (1024 * 1024);
        die("Image too large (max {$cap}MB for this type).");
    }

    $ext  = $allowed[$mime]['ext'];
    $name = uniqid('img_', true) . '.' . $ext;
    $dest = __DIR__ . '/uploads/' . $name;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        die('Could not save file. Check that uploads/ exists and is writable (chmod 775).');
    }

    chmod($dest, 0644);
    return $name;
}

function notify_automod(int $post_id, string $type, string $text): void {
    $payload = json_encode([
        'post_id'   => $post_id,
        'post_type' => $type,
        'text'      => $text,
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/json',
            'content' => $payload,
            'timeout' => 1,
        ]
    ]);

    @file_get_contents('http:/127.0.0.1:9000/analyze', false, $ctx);
}

if ($action === 'new_thread') {

    $body    = trim($_POST['body'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $author  = trim($_POST['author'] ?? '');
    $author  = ($author === '') ? 'Anon' : $author;

    if ($body === '') die('Post body is required.');

    $img = handle_upload();
    $check = automod_check($body, $db);

    if ($check['action'] === 'block') {
        http_response_code(403);
        die("
            <link rel='stylesheet' href='style.css'>
            <div style='max-width:420px;margin:60px auto;background:#1e1e1e;
                        border:1px solid #ff4444;padding:20px;font-family:MapleMono-Semibold;'>
                <div style='color:#ff4444;font-weight:bold;font-size:15px;margin-bottom:8px;'>
                    Post blocked by automod!!
                </div>
                <div style='color:#aaa;font-size:13px;'>
                    Your post contains content that violates 6chan's rules.<br>
                    If you believe this is a mistake, use the contact page <3
                </div>
                <div style='margin-top:12px;'>
                    <a href='javascript:history.back()' style='color:var(--orange);'>[Go Back]</a>
                </div>
            </div>
        ");
    }

    $automod_flag = $check['action'] === 'flag' ? $check['reason'] : null;
    
    $s = $db->prepare(
        "INSERT INTO threads (board_id, subject, body, img_path, ip, author, automod_flag)
         VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id"
    );
    $s->execute([
        $board['id'],
        $subject !== '' ? $subject : null,
        $body,
        $img,
        $ip,
        $author,
        $automod_flag
    ]);
    $tid = $s->fetchColumn();    
    notify_automod((int)$tid, 'thread', $body);
    header("Location: thread.php?id=$tid");
    exit;

} elseif ($action === 'reply') {

    $tid  = (int)($_POST['thread_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    $author  = trim($_POST['author'] ?? '');
    $author  = ($author === '') ? 'Anon' : $author;
    $options = strtolower(trim($_POST['options'] ?? ''));
    $is_sage = ($options === 'sage');
    
    if ($tid <= 0)    die('Invalid thread ID.');
    if ($body === '') die('Reply body is required.');

    $tc = $db->prepare("SELECT id, locked FROM threads WHERE id = ?");
    $tc->execute([$tid]);
    $row = $tc->fetch();
    if (!$row)          die('Thread not found.');
    if ($row['locked']) die('This thread is locked! No new replies.');
    
    $img = handle_upload();

    $s = $db->prepare(
        "INSERT INTO posts (thread_id, body, img_path, ip, author, options, automod_flag)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $s->execute([$tid, $body, $img, $ip, $author, $is_sage ? 'sage' : null, $automod_flag]);

    if (!$is_sage) {
        $db->prepare("UPDATE threads SET bumped_at = NOW() WHERE id = ?")
           ->execute([$tid]);
    }
    $new_post_id = (int)$db->lastInserId();
    notify_automod($new_post_id, 'post', $body);
       
    header("Location: thread.php?id=$tid");
    exit;

} else {
    die('Unknown action: ' . htmlspecialchars($action));
}
