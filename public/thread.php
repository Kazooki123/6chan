<?php
require_once '../config/db.php';
$db = get_db();
$id = (int)($_GET['id'] ?? 0);

$thread = $db->prepare("SELECT t.*, b.slug, b.name AS bname FROM threads t JOIN boards b ON b.id=t.board_id WHERE t.id=?");
$thread->execute([$id]);
$thread = $thread->fetch(PDO::FETCH_ASSOC);
if (!$thread) { http_response_code(404); die('Thread not found.'); }

$posts = $db->prepare("SELECT * FROM posts WHERE thread_id = ? ORDER BY created_at ASC");
$posts->execute([$id]);
$posts = $posts->fetchAll(PDO::FETCH_ASSOC);

function render_body(string $text, int $thread_id): string {
  $lines = explode("\n", htmlspecialchars($text, ENT_QUOTES));
  $out = [];
  foreach ($lines as $line) {
    $line = preg_replace_callback('/&gt;&gt;(\d+)/', function($m) use ($thread_id) {
      $num = $m[1];
      return '<a class="quotelink" href="thread.php?id=' . $thread_id . '#p' . $num . '" '
           . 'data-post="' . $num . '">&gt;&gt;' . $num . '</a>';
    }, $line);

    if (preg_match('/^(\s*)&gt;(?!&gt;)/', $line))
      $out[] = '<span class="gt">' . $line . '</span>';
    else
      $out[] = $line;
  }
  return implode("\n", $out);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($thread['subject'] ?: 'Thread #'.$id) ?></title>
<link rel="icon" type="image/x-icon" href="assets/favicon.ico">
<link rel="stylesheet" href="style.css">
</head>
<body>

<div id="topbar">
  <span class="site-name">@6chan</span>
  <a href="index.php">Home</a>
  <a href="board.php?b=<?= htmlspecialchars($thread['slug']) ?>">/<?= htmlspecialchars($thread['slug']) ?>/</a>
  <a href="catalog.php?b=<?= htmlspecialchars($thread['slug']) ?>">[Catalog]</a>
  <a href="rules.php">[Rules]</a>
  <a href="search.php">[Search]</a>
</div>

<div class="wrap">
  <div class="page-header">
    <h1><?= htmlspecialchars($thread['subject'] ?: 'Thread #'.$id) ?></h1>
    <p>/<?= htmlspecialchars($thread['slug']) ?>/ — <?= htmlspecialchars($thread['bname']) ?></p>
  </div>

  <div class="thread">
    <div class="thread-op" id="p<?= $thread['id'] ?>">
      <?php if (!empty($thread['img_path'])): ?>
      <div class="post-img">
        <img src="uploads/<?= htmlspecialchars($thread['img_path']) ?>" alt="image">
      </div>
      <?php endif; ?>
      <div class="post-header">
        <?php if ($thread['subject']): ?>
          <span class="subject"><?= htmlspecialchars($thread['subject']) ?></span>
        <?php endif; ?>
        <span class="postnum"><?= $thread['id'] ?></span>
        <span style="margin-left:8px;font-size:11px;"><?= date('Y-m-d H:i', strtotime($thread['created_at'])) ?></span>
      </div>
      <div class="post-body"><?= render_body($thread['body'], $thread['id']) ?></div>
    </div>

    <div class="replies">
      <?php foreach ($posts as $p): ?>
      <div class="post" id="p<?= $p['id'] ?>">
        <div class="post-header">
          <span class="postnum"><?= $p['id'] ?></span>
          <span style="margin-left:8px;font-size:11px;"><?= date('Y-m-d H:i', strtotime($p['created_at'])) ?></span>
        </div>
        <?php if (!empty($p['img_path'])): ?>
        <div class="post-img">
          <img src="uploads/<?= htmlspecialchars($p['img_path']) ?>" alt="image">
        </div>
        <?php endif; ?>
        <div class="post-body"><?= render_body($p['body'], $id) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="postform">
    <h3>Post a reply</h3>
    <form method="post" action="post.php" enctype="multipart/form-data">
      <input type="hidden" name="thread_id" value="<?= $id ?>">
      <input type="hidden" name="board_slug" value="<?= htmlspecialchars($thread['slug']) ?>">
      <input type="hidden" name="action" value="reply">
      <table>
        <tr><td>Comment</td><td><textarea name="body" required></textarea></td></tr>
        <tr><td>Image</td><td><input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"></td></tr>
        <tr><td></td><td><button type="submit">Reply</button></td></tr>
      </table>
    </form>
  </div>
</div>

<footer>@6chan — <a href="board.php?b=<?= htmlspecialchars($thread['slug']) ?>">[back]</a> — <a href="index.php">Home</a></footer>

<div id="quote-popup"></div>

<script>
const popup = document.getElementById('quote-popup');

document.querySelectorAll('.quotelink').forEach(link => {
  link.addEventListener('mouseenter', function(e) {
    const postId = this.dataset.post;
    const target = document.getElementById('p' + postId);

    let html = '';
    if (target) {
      const header = target.querySelector('.post-header')?.innerHTML ?? '';
      const body   = target.querySelector('.post-body')?.innerHTML ?? '';
      html = `<div class="post-header">${header}</div><div class="post-body">${body}</div>`;
    } else {
      html = `<div class="post-header">No.${postId}</div><div class="post-body">[post not in thread]</div>`;
    }

    popup.innerHTML = html;
    popup.style.display = 'block';
    movePopup(e);
  });

  link.addEventListener('mousemove', movePopup);
  link.addEventListener('mouseleave', () => popup.style.display = 'none');
});

function movePopup(e) {
  const pad = 14;
  let x = e.clientX + pad;
  let y = e.clientY + pad;
  if (x + 440 > window.innerWidth)  x = e.clientX - 440 - pad;
  if (y + 200 > window.innerHeight) y = e.clientY - 200 - pad;
  popup.style.left = x + 'px';
  popup.style.top  = y + 'px';
}
</script>

</body>
</html>
