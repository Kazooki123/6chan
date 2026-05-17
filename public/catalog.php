<?php
require_once '../config/db.php';
$db   = get_db();
$slug = $_GET['b'] ?? '';

$board = $db->prepare("SELECT * FROM boards WHERE slug = ?");
$board->execute([$slug]);
$board = $board->fetch(PDO::FETCH_ASSOC);
if (!$board) { http_response_code(404); header('Location: /404.php'); }

$threads = $db->prepare(
    "SELECT t.*, (SELECT COUNT(*) FROM posts WHERE thread_id=t.id) AS reply_count
     FROM threads t WHERE t.board_id = ?
     ORDER BY t.bumped_at DESC LIMIT 150"
);
$threads->execute([$board['id']]);
$threads = $threads->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Catalog — /<?= htmlspecialchars($board['slug']) ?>/</title>
<link rel="icon" type="image/x-icon" href="assets/favicon.ico">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div id="topbar">
  <span class="site-name">@6chan</span>
  <a href="index.php">Home</a>
  <a href="board.php?b=<?= htmlspecialchars($board['slug']) ?>">/<?= htmlspecialchars($board['slug']) ?>/</a>
  <a href="catalog.php?b=<?= htmlspecialchars($board['slug']) ?>">[Catalog]</a>
  <a href="contact.php">[Contact]</a>
  <a href="support.php">[Support]</a>
</div>

<div class="wrap">
  <div class="page-header">
    <h1>Catalog — /<?= htmlspecialchars($board['slug']) ?>/</h1>
    <p><?= htmlspecialchars($board['descript']) ?></p>
  </div>

  <div class="catalog-grid">
    <?php foreach ($threads as $t): ?>
    <a href="thread.php?id=<?= $t['id'] ?>" class="catalog-card">
      <?php $is_video = str_ends_with($t['img_path'] ?? '', '.mp4'); ?>
      <?php if (!empty($t['img_path'])): ?>
        <?php if ($is_video): ?>
        <video src="uploads/<?= htmlspecialchars($t['img_path']) ?>" alt="" preload="none" loading="lazy"></video>
        <?php else: ?>
            <?php if ($t['nsfw']): ?>
                <div style="position:absolute;top:4px;right:4px;background:#ff6688;color:#000;
                            font-size:10px;font-weight:bold;padding:1px 5px;border-radius:2px;">
                    NSFW
                </div>
            <?php endif; ?>
        <img src="uploads/<?= htmlspecialchars($t['img_path']) ?>" alt="" loading="lazy">
        <?php endif; ?>
      <?php else: ?>
        <div class="catalog-noimg">No Image</div>
      <?php endif; ?>
      <div class="catalog-subject">
        <?= htmlspecialchars(mb_substr($t['subject'] ?: 'No subject', 0, 40)) ?>
      </div>
      <div class="catalog-meta"><?= $t['reply_count'] ?> repl<?= $t['reply_count'] == 1 ? 'y' : 'ies' ?></div>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<footer>@6chan — /<?= htmlspecialchars($board['slug']) ?>/ — <a href="index.php">Home</a> <a href="contact.php">[Contact]</a> <a href="support.php">[Support]</a></footer>
</body>
</html>
