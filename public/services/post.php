<?php
session_start();
$config_path = __DIR__ . '/../../config/db.php';
if (!file_exists($config_path)) {
    die("FATAL: config/db.php not found at: $config_path <br>__DIR__ is: " . __DIR__);
}

require_once '../../config/db.php';
require_once '../../config/captchav2.php';
require_once '../../config/automod.php';
require_once '../../config/cache.php';

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Note that this is for the MinIO storage bucket (local testing)
 * For deployment, services might change depending on their prices today
 */
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Ramsey\Uuid\Uuid;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__ . '/../../.env'));
$dotenv->load();

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
                    border:1px solid #ff4444;padding:20px;font-family:MapleMono;'>
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

function handle_upload(bool $nsfw = false): ?array {
    if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $f   = $_FILES['image'];
    $err = $f['error'];

    $s3key    = $_ENV['S3_KEY'];
    $s3secret = $_ENV['S3_SECRET'];

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
        'image/jpeg' => ['ext' => 'jpg',  'max' => 4  * 1024 * 1024],
        'image/png'  => ['ext' => 'png',  'max' => 4  * 1024 * 1024],
        'image/gif'  => ['ext' => 'gif',  'max' => 8  * 1024 * 1024],  // 8MB (for hq gifs)
        'image/webp' => ['ext' => 'webp', 'max' => 4  * 1024 * 1024],
        'video/mp4'  => ['ext' => 'mp4',  'max' => 10 * 1024 * 1024],  // 10MB
        'audio/mpeg' => ['ext' => 'mp3',  'max' => 10 * 1024 * 1024],
        'audio/flac' => ['ext' => 'flac', 'max' => 20 * 1024 * 1024],
        'audio/ogg'  => ['ext' => 'ogg',  'max' => 10 * 1024 * 1024],
        'audio/wav'  => ['ext' => 'wav',  'max' => 30 * 1024 * 1024],
    ];

    if (!isset($allowed[$mime])) {
        die("File type not allowed: $mime");
    }

    if ($f['size'] > $allowed[$mime]['max']) {
        $cap = $allowed[$mime]['max'] / (1024 * 1024);
        die("File too large (max {$cap}MB for this type).");
    }

    $ext  = $allowed[$mime]['ext'];
    $name = 'img_' . Uuid::uuid4()->toString() . '.' . $ext;

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
    
    if (str_starts_with($mime, 'image/')) {
        $folder = 'images';
    } elseif (str_starts_with($mime, 'video/')) {
        $folder = 'videos';
    } elseif (str_starts_with($mime, 'audio/')) {
        $folder = 'audio';
    } else {
        $folder = 'misc';
    }

    $object_key = $folder . '/' . $name;

    try {
        $s3->putObject([
            'Bucket'      => $bucket,
            'Key'         => $object_key,
            'SourceFile'  => $f['tmp_name'],
            'ContentType' => $mime,
        ]);
    } catch (AwsException $e) {
        die('MinIO upload failed: ' . $e->getMessage());
    }
    
    $is_audio = str_starts_with($mime, 'audio/');

    if ($is_audio) {
        require_once '../../config/audio.php';
        $audio_meta = extract_audio_meta($f['tmp_name'], $name);

        $meta_json = json_encode(
            $audio_meta,
            JSON_UNESCAPED_UNICODE
        );

        try {
            $s3->putObject([
                'Bucket'      => $bucket,
                'Key'         => $object_key,
                'Body'        => $meta_json,
                'ContentType' => 'application/json',
            ]);
        } catch (AwsException $e) {
            error_log(
                'Audio metadata upload failed: ' . $e->getMessage()
            );
        }
    }

    if ($is_audio && isset($_FILES['lrc']) && $_FILES['lrc']['error'] === UPLOAD_ERR_OK) {
        $lrc_raw  = file_get_contents();
        $lrc_data = parse_lrc($lrc_raw);

        if ($lrc_data) {
            $lyric_json = json_encode([
                'lrc' => $lrc_data
            ], JSON_UNESCAPED_UNICODE);

            try {
                $s3->putObject([
                    'Bucket'      => $bucket,
                    'Key'         => $object_key,
                    'Body'        => $lyric_json,
                    'ContentType' => 'application/json',
                ]);
            } catch (AwsException $e) {
                error_log(
                    'Lyrics upload failed: ' . $e->getMessage()
                );
            }
        }
    }

    $preview_key = null;
    
    if ($nsfw && $mime !== 'video/mp4' && class_exists('Imagick')) {
        try {
            $tmp_preview = tempnam(sys_get_temp_dir(), 'blur_');

            $img = new Imagick($f['tmp_name']);
            $img->blurImage(0, 18);
            $img->thumbnailImage(300, 300, true);
            $img->writeImage($tmp_preview);
            $img->destroy();

            $preview_name = 'blur_' . $name;
            $preview_key  = 'preview/' . $preview_name;
            
            $s3->putObject([
                'Bucket'      => $bucket,
                'Key'         => $preview_key,
                'SourceFile'  => $tmp_preview,
                'ContentType' => $mime,
            ]);

            unlink($tmp_preview);
        } catch (ImagickException $e) {
            error_log("Imagick blur failed! " . $e->getMessage());
        }
    }

    // Public URL (local)
    $file_url = "http://localhost:9000/{$bucket}/{$object_key}";
    $preview_url = $preview_key ? "http://localhost:9000/{$bucket}/{$preview_key}" : null;

    return [
        'name'         => $name,
        'mime'         => $mime,
        'size'         => $f['size'],
        'bucket'       => $bucket,
        'object_key'   => $object_key,
        'url'          => $file_url,
        'nsfw_preview' => $preview_url,
    ];
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

    @file_get_contents('http://127.0.0.1:9000/analyze', false, $ctx);
}

if ($action === 'new_thread') {
    $body    = trim($_POST['body']    ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $author  = trim($_POST['author']  ?? '');
    $author  = ($author === '') ? 'Anon' : $author;

    $captcha_token  = $_POST['captcha_token']  ?? '';
    $captcha_answer = $_POST['captcha_answer'] ?? '';

    if (!captchav2_verify($captcha_token, $captcha_answer)) {
        http_response_code(400);
        die("
            <link rel='stylesheet' href='../style.css'>
            <div style='max-width:420px;margin:60px auto;background:#1e1e1e;
                        border:1px solid var(--orange);padding:20px;font-family:MapleMono;'>
                <div style='color:var(--orange);font-weight:bold;font-size:15px;margin-bottom:8px;'>
                    Wrong Captcha Answer.
                </div>
                <div style='color:#aaa;font-size:13px;margin-bottom:12px;'>
                    Hit back and try again.
                </div>
                <a href='javascript:history.back()' style='color:var(--orange);'>[Go Back]</a>
            </div>
        ");
    }

    if ($body === '') die('Post body is required.');

    $nsfw = isset($_POST['nsfw']) && $_POST['nsfw'] === '1' ? 1 : 0;
    $upload = handle_upload((bool)$nsfw);
    $img  = $upload['object_key'] ?? null;

    $check = automod_check($body, $db);

    if ($check['action'] === 'block') {
        http_response_code(403);
        die("
            <link rel='stylesheet' href='../style.css'>
            <div style='max-width:420px;margin:60px auto;background:#1e1e1e;
                        border:1px solid #ff4444;padding:20px;font-family:MapleMono;'>
                <div style='color:#ff4444;font-weight:bold;font-size:15px;margin-bottom:8px;'>
                    Post blocked by automod!!
                </div>
                <div style='color:#aaa;font-size:13px;'>
                    Your post contains content that violates 6chan's rules.<br>
                    If you believe this is a mistake, use the contact page &lt;3
                </div>
                <div style='margin-top:12px;'>
                    <a href='javascript:history.back()' style='color:var(--orange);'>[Go Back]</a>
                </div>
            </div>
        ");
    }

    $automod_flag = $check['action'] === 'flag' ? $check['reason'] : null;

    $s = $db->prepare(
        "INSERT INTO threads (board_id, subject, body, img_path, ip, author, automod_flag, nsfw)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id"
    );
    $s->execute([
        $board['id'],
        $subject !== '' ? $subject : null,
        $body,
        $img,
        $ip,
        $author,
        $automod_flag,
        $nsfw,
    ]);

    $tid = $s->fetchColumn();
    notify_automod((int)$tid, 'thread', $body);

    if (!empty($_POST['add_poll']) && !empty($_POST['poll_question'])) {
        $question = trim($_POST['poll_question']);
        $options  = array_filter(
            array_map('trim', $_POST['poll_options'] ?? []),
            fn($o) => $o !== ''
        );
        $closes   = !empty($_POST['poll_closes']) ? $_POST['poll_closes'] : null;

        if ($question && count($options) >= 2 && count($options) <= 10) {
            $is_thread = ($action === 'new_thread');
            $t_id      = $is_thread ? $tid : $tid;
            $p_id      = $is_thread ? null : $new_post_id;

            $ps = $db->prepare(
                "INSERT INTO polls (thread_id, post_id, question, closes_at)
                 VALUES (?,?,?,?) RETURNING id"
            );
            $ps->execute([$t_id, $p_id, $question, $closes]);
            $poll_id = $ps->fetchColumn();

            foreach (array_values($options) as $i => $label) {
                $db->prepare(
                    "INSERT INTO poll_options (poll_id, label, position) VALUES (?,?,?)"
                )->execute([$poll_id, $label, $i]);
            }  
        }
    }
    
    cache_delete("board:{$board['id']}:p1", $db);

    header("Location: ../thread.php?id=$tid");
    exit;

} elseif ($action === 'reply') {

    $tid    = (int)($_POST['thread_id'] ?? 0);
    $body   = trim($_POST['body']       ?? '');
    $author = trim($_POST['author']     ?? '');
    $author = ($author === '') ? 'Anon' : $author;
    $options = strtolower(trim($_POST['options'] ?? ''));
    $is_sage = ($options === 'sage');

    $captcha_token  = $_POST['captcha_token']  ?? '';
    $captcha_answer = $_POST['captcha_answer'] ?? '';

    if (!captchav2_verify($captcha_token, $captcha_answer)) {
        http_response_code(400);
        die("
            <link rel='stylesheet' href='../style.css'>
            <div style='max-width:420px;margin:60px auto;background:#1e1e1e;
                        border:1px solid var(--orange);padding:20px;font-family:MapleMono;'>
                <div style='color:var(--orange);font-weight:bold;font-size:15px;margin-bottom:8px;'>
                    Wrong Captcha Answer.
                </div>
                <div style='color:#aaa;font-size:13px;margin-bottom:12px;'>
                    Hit back and try again.
                </div>
                <a href='javascript:history.back()' style='color:var(--orange);'>[Go Back]</a>
            </div>
        ");
    }

    if ($tid <= 0)    die('Invalid thread ID.');
    if ($body === '') die('Reply body is required.');

    $tc = $db->prepare("SELECT id, locked FROM threads WHERE id = ?");
    $tc->execute([$tid]);
    $row = $tc->fetch();
    if (!$row)          die('Thread not found.');
    if ($row['locked']) die('This thread is locked! No new replies.');

    $nsfw = isset($_POST['nsfw']) && $_POST['nsfw'] === '1' ? 1 : 0;
    $upload = handle_upload((bool)$nsfw);
    $img  = $upload['object_key'] ?? null;
    
    $check        = automod_check($body, $db);
    $automod_flag = $check['action'] === 'flag' ? $check['reason'] : null;

    if ($check['action'] === 'block') {
        http_response_code(403);
        die("
            <link rel='stylesheet' href='../style.css'>
            <div style='max-width:420px;margin:60px auto;background:#1e1e1e;
                        border:1px solid #ff4444;padding:20px;font-family:MapleMono;'>
                <div style='color:#ff4444;font-weight:bold;font-size:15px;margin-bottom:8px;'>
                    Post blocked by automod!!
                </div>
                <div style='color:#aaa;font-size:13px;'>
                    Your post contains content that violates 6chan's rules.<br>
                    If you believe this is a mistake, use the contact page &lt;3
                </div>
                <div style='margin-top:12px;'>
                    <a href='javascript:history.back()' style='color:var(--orange);'>[Go Back]</a>
                </div>
            </div>
        ");
    }

    $check = automod_check($body, $db);

    if ($check['action'] === 'block') {
        http_response_code(403);
        die("
            <link rel='stylesheet' href='../style.css'>
            <div style='max-width:420px;margin:60px auto;background:#1e1e1e;
                        border:1px solid #ff4444;padding:20px;font-family:MapleMono;'>
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
        "INSERT INTO posts (thread_id, body, img_path, ip, author, options, automod_flag, nsfw)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $s->execute([
        $tid,
        $body,
        $img,
        $ip,
        $author,
        $is_sage ? 'sage' : null,
        $automod_flag,
        $nsfw,
    ]);

    if (!$is_sage) {
        $db->prepare("UPDATE threads SET bumped_at = NOW() WHERE id = ?")
           ->execute([$tid]);
    }

    $new_post_id = (int)$db->query("SELECT lastval()")->fetchColumn();

    notify_automod($new_post_id, 'post', $body);
    cache_delete("board:{$board['id']}:p1", $db);
    cache_delete("thread:{$tid}", $db);

    header("Location: ../thread.php?id=$tid");
    exit;
} else {
    die('Unknown action: ' . htmlspecialchars($action));
}
