<?php
require_once '../config/db.php';
$db   = get_db();
$slug = $_GET['b'] ?? '';

$board = $db->prepare("SELECT * FROM boards WHERE slug = ?");
$board->execute([$slug]);
$board = $board->fetch(PDO::FETCH_ASSOC);
if (!$board) {
    http_response_code(404);
    die('Board not found!');
}

$page     = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$total_stmt = $db->prepare("SELECT COUNT(*) FROM threads WHERE board_id = ?");
$total_stmt->execute([$board['id']]);
$total_threads = (int)$total_stmt->fetchColumn();
$total_pages   = max(1, (int)ceil($total_threads / $per_page));

$threads = $db->prepare(
    "SELECT * FROM threads WHERE board_id = ? ORDER BY bumped_at DESC LIMIT ? OFFSET ?"
);
$threads->execute([$board['id'], $per_page, $offset]);
$threads = $threads->fetchAll(PDO::FETCH_ASSOC);

foreach ($threads as &$t) {
    $r = $db->prepare(
        "SELECT * FROM posts WHERE thread_id = ? ORDER BY created_at DESC LIMIT 5"
    );
    $r->execute([$t['id']]);
    $t['replies'] = array_reverse($r->fetchAll(PDO::FETCH_ASSOC));

    $count = $db->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $count->execute([$t['id']]);
    $t['reply_count'] = (int)$count->fetchColumn();
}
unset($t);

function render_body(string $text, int $thread_id): string {
    $lines = explode("\n", htmlspecialchars($text, ENT_QUOTES));
    $out = [];
    foreach ($lines as $line) {
        $line = preg_replace_callback('/&gt;&gt;(\d+)/', function($m) use ($thread_id) {
            $num = $m[1];
            return '<a class="quotelink" href="thread.php"?id='.$thread_id.'#p'.$num.'" '
                 . 'data-post="'.$num.'">&gt;&gt;'.$num.'</a>';
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
    <title>/<?= htmlspecialchars($board['slug']) ?>/ - <?= htmlspecialchars($board['name']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div id="topbar">
        <span class="site-name">@6chan</span>
        <a href="index.php">Home</a>
        <a href="board.php?b=<?= htmlspecialchars($board['slug']) ?>">/<?= htmlspecialchars($board['slug']) ?>/</a>
    </div>

    <div class="wrap">
        <div class="page-header">
            <h1>/<?= htmlspecialchars($board['slug']) ?>/ - <?= htmlspecialchars($board['name']) ?></h1>
            <p><?= htmlspecialchars($board['descript']) ?></p>
        </div>

        <div class="postform">
            <h3>Start a new thread!! <3</h3>
            <form method="post" action="post.php" enctype="multipart/form-data">
                <input type="hidden" name="board_slug" value="<?= htmlspecialchars($board['slug']) ?>">
                <input type="hidden" name="action" value="new_thread">
                <table>
                    <tr><td>Subject</td><td><input type="text" name="subject" maxlength="200"></td></tr>
                    <tr><td>Comment</td><td><textarea name="body" required></textarea></td></tr>
                    <tr><td>Image</td><td><input type="fill" name="image" accept="image/*"></td></tr>
                    <tr><td></td><td><button type="submit">Post</button></td></tr>
                </table>
            </form>
        </div>

        <?php foreach ($threads as $t): ?>
        <div class="thread">
            <div class="thread-op">
                <?php if (!empty($t['image_path'])): ?>
                <div class="post-img">
                    <a href="thread.php?id=<?= $t['id'] ?>">
                        <img src="../uploads/<?= htmlspecialchars($t['image_path']) ?>" alt="image">
                    </a>
                </div>
                <?php endif; ?>
                <div class="post-header">
                    <?php if ($t['subject']): ?>
                        <span class="subject"><?= htmlspecialchars($t['subject']) ?></span>
                    <?php endif; ?>
                    <span class="postnum"><?= $t['id'] ?></span>
                    <span style="margin-left:8px;font-size:11px;"><?= date('Y-m-d H:i', strtotime($t['created_at'])) ?></span>
                    <a href="thread.php?id=<?= $t['id'] ?>" style="margin-left:10px;font-size:12px;color:var(--orange);">[Reply]</a>
                </div>
                <div class="post-body"><?= render_body($t['body'], $t['id']) ?></div>
            </div>

            <?php
              $shown  = count($t['replies']);
              $hidden = max(0, $t['reply_count'] - $shown);
            ?>
            <?php if ($hidden > 0): ?>
              <div class="omitted"><?= $hidden ?> repl<?= $hidden === 1 ? 'y' : 'ies' ?> omitted.
                <a href="thread.php?id=<?= $t['id'] ?>">View thread</a>
              </div>
            <?php endif; ?>
        
            <div class="replies"> 
               <?php foreach ($t['replies'] as $p): ?>
               <div class="post" id="p<?= $p['id'] ?>">
                   <div class="post-header">
                       <span class="postnum"><?= $p['id'] ?></span>
                       <span style="margin-left:8px;font-size:11px;"><?= date('Y-m-d H:i', strtotime($p['created_at'])) ?></span>
                   </div>
                   <?php if (!empty($p['image_path'])): ?>
                   <div class="post-img">
                       <img src="../uploads/<?= htmlspecialchars($p['image_path']) ?>" alt="image">
                   </div>
                   <?php endif; ?>
                   <div class="post-body"><?= render_body($p['body'], $t['id']) ?></div>
               </div>
               <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Pagination :3 -->
        <div class="nav">
            <?php if ($page > 1): ?>
                <a href="board.php?b=<?= $slug ?>$&p=<?= $page-1 ?>">[&loque; Prev]</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i === $page): ?>
                    <strong style="color:var(--orange)">[<?= $i ?>]</strong>
                <?php else: ?>
                    <a href="board.php?b=<?= $slug ?>&p=<?= $i ?>">[<?= $i ?>]</a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="board.php?b=<?= $slug ?>&p=<?= $page+1 ?>">[Next &raquo;]</a>
            <?php endif; ?>
        </div>
    </div>
        
    <footer>@6chan — /<?= htmlspecialchars($board['slug']) ?>/ — <a href="index.php">Home</a></footer>
</body>
</html>
