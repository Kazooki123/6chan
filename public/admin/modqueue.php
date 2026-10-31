<?php
session_start();
if (!($_SESSION['admin'] ?? false)) {
    header('Location: login.php');
    exit;
}

require_once '../../config/db.php';
require_once '../../config/pg_types.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__ . '/../../.env'));
$dotenv->load();

$db = get_db();

$bucket = $_ENV['S3_UPLOAD'];
$s3 = new S3Client([
    'version'                 => 'latest',
    'region'                  => 'us-east-1',
    'endpoint'                => $_ENV['S3_ENDPOINT'] ?? 'http://localhost:9000',
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key'    => $_ENV['S3_KEY'],
        'secret' => $_ENV['S3_SECRET'],
    ],
]);

// AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action     = $_POST['action'];
    $owner_type = $_POST['owner_type'] ?? '';
    $owner_id   = (int)($_POST['owner_id'] ?? 0);

    if (!in_array($owner_type, ['thread', 'post'], true) || $owner_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid target.']);
        exit;
    }

    $table = $owner_type === 'thread' ? 'threads' : 'posts';

    if ($action === 'dismiss') {
        $db->prepare("UPDATE {$table} SET automod_flag = NULL WHERE id = ?")->execute([$owner_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$owner_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'tag_nsfw') {
        $stmt = $db->prepare("SELECT img_path FROM {$table} WHERE id = ?");
        $stmt->execute([$owner_id]);
        $img_path = $stmt->fetchColumn();

        if (!$img_path) {
            http_response_code(400);
            echo json_encode(['error' => 'This post has no image to tag.']);
            exit;
        }

        $has_preview = 0;

        try {
            $tmp_src = tempnam(sys_get_temp_dir(), 'modq_src_');
            $s3->getObject(['Bucket' => $bucket, 'Key' => $img_path, 'SaveAs' => $tmp_src]);

            if (class_exists('Imagick')) {
                $tmp_blur = tempnam(sys_get_temp_dir(), 'modq_blur_');

                $img = new Imagick($tmp_src);
                $img->blurImage(0, 18);
                $img->thumbnailImage(300, 300, true);
                $img->stripImage();
                $img->writeImage($tmp_blur);
                $mime = $img->getImageMimeType();
                $img->destroy();

                $blur_key = 'preview/blur_' . basename($img_path);
                $s3->putObject([
                    'Bucket'      => $bucket,
                    'Key'         => $blur_key,
                    'SourceFile'  => $tmp_blur,
                    'ContentType' => $mime,
                ]);

                unlink($tmp_blur);
                $has_preview = 1;
            }

            unlink($tmp_src);
        } catch (AwsException | \ImagickException $e) {
            error_log('[modqueue] tag_nsfw blur generation failed: ' . $e->getMessage());
        }

        $db->prepare("UPDATE {$table} SET nsfw = TRUE, has_preview = ?, automod_flag = NULL WHERE id = ?")
           ->execute([$has_preview, $owner_id]);

        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action.']);
    exit;
}

$threads = $db->query(
    "SELECT t.id, t.board_id, t.subject, t.body, t.img_path, t.nsfw, t.automod_flag, t.created_at, b.slug AS board_slug
     FROM threads t JOIN boards b ON b.id = t.board_id
     WHERE t.automod_flag IS NOT NULL
     ORDER BY t.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$posts = $db->query(
    "SELECT p.id, p.thread_id, p.body, p.img_path, p.nsfw, p.automod_flag, p.created_at, b.slug AS board_slug
     FROM posts p
     JOIN threads t ON t.id = p.thread_id
     JOIN boards b ON b.id = t.board_id
     WHERE p.automod_flag IS NOT NULL
     ORDER BY p.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$queue = [];
foreach ($threads as $t) { $t['owner_type'] = 'thread'; $queue[] = $t; }
foreach ($posts as $p)   { $p['owner_type'] = 'post';   $queue[] = $p; }
usort($queue, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Queue - @6chan</title>
<link rel="icon" type="image/x-icon" href="../assets/favicon.svg">
<link rel="stylesheet" href="../style/style.css">
<style>
.panel-box { max-width: 720px; margin: 50px auto; background: var(--surface); border: 1px solid var(--orange); padding: 20px 24px; }
.panel-box h2 { color: var(--orange); margin-bottom: 4px; font-size: 15px; }
.panel-box .hint { color: var(--grey); font-size: 12px; margin-bottom: 16px; }

.mq-item { border: 1px solid var(--border); padding: 10px 12px; margin-bottom: 10px; display: flex; gap: 10px; }
.mq-item.gone { opacity: 0.4; pointer-events: none; }
.mq-thumb { width: 60px; height: 60px; object-fit: cover; border: 1px solid var(--border); flex-shrink: 0; }
.mq-main { flex: 1; min-width: 0; }
.mq-meta { font-size: 11px; color: var(--grey); margin-bottom: 4px; }
.mq-reason { color: var(--orange); font-size: 12px; margin-bottom: 4px; }
.mq-body { font-size: 12px; color: var(--white); margin-bottom: 8px; overflow-wrap: break-word; max-height: 60px; overflow: hidden; }
.mq-actions { display: flex; gap: 6px; }
.mq-actions button {
    background: var(--orange); border: none; color: #000; cursor: pointer;
    font: bold 11px 'MapleMono', monospace; padding: 4px 10px;
}
.mq-actions button:hover { background: var(--orange2); }
.mq-actions .mq-delete { background: #ff4444; }
.mq-actions .mq-dismiss { background: transparent; color: var(--grey); border: 1px solid var(--border); }
.mq-empty { color: var(--grey); font-size: 13px; }
</style>
</head>
<body>
<div id="topbar">
    <span class="site-name">@6chan</span>
    <a href="panel.php">[Panel]</a>
    <a href="robots.php">[R9K]</a>
    <a href="../index.php">[Home]</a>
</div>

<div class="panel-box">
    <h2>⚑ Mod Queue</h2>
    <div class="hint">
        Posts flagged by automod - text rules or the AI NSFW image
        classifier. Review and act on each.
    </div>

    <?php if (!$queue): ?>
        <div class="mq-empty">Nothing flagged right now.</div>
    <?php endif; ?>

    <?php foreach ($queue as $item): ?>
        <?php
            $item_id = $item['owner_type'] . '-' . $item['id'];
            $has_img = !empty($item['img_path']);
            $body_preview = mb_strimwidth(trim($item['body'] ?? ''), 0, 200, '...');
        ?>
        <div class="mq-item" id="mq-<?= htmlspecialchars($item_id) ?>">
            <?php if ($has_img): ?>
                <?php $thumb_src = rtrim($_ENV['S3_ENDPOINT'], '/') . '/' . $bucket . '/' . $item['img_path']; ?>
                <img class="mq-thumb" src="<?= htmlspecialchars($thumb_src) ?>" alt="">
            <?php endif; ?>
            <div class="mq-main">
                <div class="mq-meta">
                    /<?= htmlspecialchars($item['board_slug']) ?>/
                    — <?= $item['owner_type'] === 'thread' ? 'Thread' : 'Reply' ?> #<?= (int)$item['id'] ?>
                    — <?= htmlspecialchars($item['created_at']) ?>
                    <?= pg_bool($item['nsfw'] ?? false) ? ' — already NSFW' : '' ?>
                </div>
                <div class="mq-reason"><?= htmlspecialchars($item['automod_flag']) ?></div>
                <div class="mq-body"><?= htmlspecialchars($body_preview) ?></div>
                <div class="mq-actions">
                    <button class="mq-delete" onclick="mqAction('<?= $item['owner_type'] ?>', <?= (int)$item['id'] ?>, 'delete', '<?= htmlspecialchars($item_id, ENT_QUOTES) ?>')">Delete</button>
                    <?php if ($has_img): ?>
                        <button onclick="mqAction('<?= $item['owner_type'] ?>', <?= (int)$item['id'] ?>, 'tag_nsfw', '<?= htmlspecialchars($item_id, ENT_QUOTES) ?>')">Tag NSFW</button>
                    <?php endif; ?>
                    <button class="mq-dismiss" onclick="mqAction('<?= $item['owner_type'] ?>', <?= (int)$item['id'] ?>, 'dismiss', '<?= htmlspecialchars($item_id, ENT_QUOTES) ?>')">Dismiss</button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
async function mqAction(ownerType, ownerId, action, domId) {
    const el = document.getElementById('mq-' + domId);
    if (!confirm(action === 'delete' ? 'Delete this permanently?' : 'Confirm: ' + action) ) return;

    try {
        const res  = await fetch('modqueue.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=' + encodeURIComponent(action)
                + '&owner_type=' + encodeURIComponent(ownerType)
                + '&owner_id=' + encodeURIComponent(ownerId),
        });
        const data = await res.json();

        if (!data.ok) {
            alert(data.error || 'Action failed.');
            return;
        }

        el.classList.add('gone');
        setTimeout(() => el.remove(), 300);
    } catch (e) {
        alert('Network error, try again.');
    }
}
</script>
</body>
</html>
