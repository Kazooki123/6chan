<?php
require_once '../config/db.php';
$db = get_db();
$q  = trim($_GET['q'] ?? '');
$results = [];

if (strlen($q) >= 3) {
    $like = '%' . $q . '%';

    $tr = $db->prepare(
        "SELECT t.id, t.subject, t.body, t.created_at, b.slug
         FROM threads t JOIN boards b ON b.id=t.board_id
         WHERE t.body ILIKE ? OR t.subject ILIKE ?
         ORDER BY t.created_at DESC LIMIT 30"
    );
    $tr->execute([$like, $like]);
    $thread_hits = $tr->fetchAll(PDO::FETCH_ASSOC);

    $pr = $db->prepare(
        "SELECT p.id, p.body, p.created_at, p.thread_id, b.slug
         FROM posts p
         JOIN threads t ON t.id = p.thread_id
         JOIN boards b ON b.id = t.board_id
         WHERE p.body ILIKE ?
         ORDER BY p.created_at DESC LIMIT 30"
    );
    $pr->execute([$like]);
    $post_hits = $pr->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Search — @6chan</title>
<link rel="icon" type="image/x-icon" href="assets/favicon.ico">
<link rel="stylesheet" href="style.css">
<script src="settingsloader.js"></script>
</head>
<body>
<div id="topbar">
  <span class="site-name">@6chan</span>
  <a href="index.php">Home</a>
  <a href="rules.php">[Rules]</a>
  <a href="contact.php">[Contact]</a>
  <a href="support.php">[Support]</a>
</div>

<div class="wrap">
  <div class="page-header">
    <h1>Search</h1>
    <p>Search posts and threads across all boards.</p>
  </div>

  <div class="postform" style="margin-bottom:16px;">
    <form method="get" style="display:flex;gap:8px;align-items:center;">
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
             placeholder="Search posts..." style="flex:1;background:#111;border:1px solid var(--border);
             color:var(--white);font:13px 'MapleMono',monospace;padding:5px 8px;">
      <button type="submit" style="background:var(--orange);border:none;color:#000;
              font:bold 13px 'MapleMono',monospace;padding:5px 16px;cursor:pointer;">Search</button>
    </form>
  </div>

  <?php if ($q !== '' && strlen($q) < 3): ?>
    <p style="color:var(--grey);font-size:13px;">Query too short — minimum 3 characters.</p>

  <?php elseif ($q !== ''): ?>
    <?php $total = count($thread_hits) + count($post_hits); ?>
    <p style="color:var(--grey);font-size:12px;margin-bottom:12px;">
      <?= $total ?> result<?= $total == 1 ? '' : 's' ?> for
      <strong style="color:var(--orange)"><?= htmlspecialchars($q) ?></strong>
    </p>

    <?php if ($thread_hits): ?>
    <div class="admin-section">
      <h2 style="color:var(--orange);font-size:13px;text-transform:uppercase;letter-spacing:1px;
                 border-bottom:1px solid var(--border);padding-bottom:4px;margin-bottom:8px;">
        Threads (<?= count($thread_hits) ?>)
      </h2>
      <?php foreach ($thread_hits as $r): ?>
      <div class="post" style="margin-bottom:6px;">
        <div class="post-header">
          <span class="subject"><?= htmlspecialchars($r['subject'] ?: 'No subject') ?></span>
          <span style="color:var(--grey);font-size:11px;">
            /<?= htmlspecialchars($r['slug']) ?>/ —
            <?= date('Y-m-d H:i', strtotime($r['created_at'])) ?>
          </span>
          <a href="thread.php?id=<?= $r['id'] ?>" style="margin-left:8px;font-size:12px;">[View]</a>
        </div>
        <div class="post-body" style="font-size:12px;color:var(--grey);">
          <?= htmlspecialchars(mb_substr($r['body'], 0, 120)) ?>…
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($post_hits): ?>
    <div class="admin-section">
      <h2 style="color:var(--orange);font-size:13px;text-transform:uppercase;letter-spacing:1px;
                 border-bottom:1px solid var(--border);padding-bottom:4px;margin-bottom:8px;">
        Posts (<?= count($post_hits) ?>)
      </h2>
      <?php foreach ($post_hits as $r): ?>
      <div class="post" style="margin-bottom:6px;">
        <div class="post-header">
          <span class="postnum"><?= $r['id'] ?></span>
          <span style="color:var(--grey);font-size:11px;margin-left:8px;">
            /<?= htmlspecialchars($r['slug']) ?>/ —
            <?= date('Y-m-d H:i', strtotime($r['created_at'])) ?>
          </span>
          <a href="thread.php?id=<?= $r['thread_id'] ?>#p<?= $r['id'] ?>"
             style="margin-left:8px;font-size:12px;">[View]</a>
        </div>
        <div class="post-body" style="font-size:12px;color:var(--grey);">
          <?= htmlspecialchars(mb_substr($r['body'], 0, 120)) ?>…
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($total === 0): ?>
      <p style="color:var(--grey);font-size:13px;">No results found.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<footer>@6chan — <a href="index.php">Home</a> — <a href="rules.php">Rules</a> <a href="contact.php">[Contact]</a>
 <a href="support.php">[Support]</a></footer>
</body>
</html>
