<?php
require_once '../config/db.php';
require_once '../config/cache.php';
require_once '../config/captcha.php';

$db = get_db();
$id = (int)($_GET['id'] ?? 0);

$thread = $db->prepare("SELECT t.*, b.slug, b.name AS bname FROM threads t JOIN boards b ON b.id=t.board_id WHERE t.id=?");
$thread->execute([$id]);
$thread = $thread->fetch(PDO::FETCH_ASSOC);
if (!$thread) { http_response_code(404); header('Location: /404.php'); exit; }

$is_locked = (bool)$thread['locked'];
$is_pinned = (bool)$thread['pinned'];

$cache_key = "thread:{$id}";
$cached    = cache_get($cache_key, $db);

if ($cached) {
    $posts = $cached['posts'];
} else {
    $posts_stmt = $db->prepare("SELECT * FROM posts WHERE thread_id = ? ORDER BY created_at ASC");
    $posts_stmt->execute([$id]);
    $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
    cache_set($cache_key, ['posts' => $posts], $db, 60);
}

$captcha = captcha_generate();

function render_body(string $text, int $thread_id): string {
    $lines = explode("\n", htmlspecialchars($text, ENT_QUOTES));
    $out   = [];
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

function render_media(string $path, bool $nsfw, int $thread_id): string {
    $is_video  = str_ends_with($path, '.mp4');
    $orig_path = 'uploads/' . $path;
    $has_blur  = $nsfw && !$is_video && file_exists(__DIR__ . '/uploads/blur_' . $path);
    $blur_path = 'uploads/blur_' . $path;
    $uid       = 'media_' . md5($path . $thread_id);

    if ($is_video) {
        $nsfw_wrap = $nsfw
            ? 'class="nsfw-video" onclick="this.classList.remove(\'nsfw-video\')"
               title="Click to show"'
            : '';
        return "
            <div class='post-img'>
                <video src='{$orig_path}' controls loop playsinline
                       style='max-width:250px;max-height:250px;border:1px solid var(--border);'
                       preload='none' {$nsfw_wrap}>
                </video>
                " . ($nsfw ? "<div class='nsfw-label'>NSFW — click to show</div>" : "") . "
            </div>";
    }

    if ($has_blur) {
        return "
            <div class='post-img'>
                <img id='{$uid}'
                     src='{$blur_path}'
                     data-orig='{$orig_path}'
                     alt='NSFW image'
                     loading='lazy'
                     class='nsfw-blur'
                     onclick='revealNsfw(this)'
                     title='NSFW — click to reveal'>
                <div class='nsfw-label'>NSFW — click to reveal</div>
            </div>";
    }

    return "
        <div class='post-img'>
            <img src='{$orig_path}' alt='image' loading='lazy'"
            . ($nsfw ? " class='nsfw-blur' onclick='revealNsfw(this)' title='NSFW — click to reveal'" : "")
            . ">
        </div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>6chan — <?= htmlspecialchars($thread['subject'] ?: 'Thread #'.$id) ?></title>
<link rel="icon" type="image/x-icon" href="assets/favicon.ico">
<link rel="stylesheet" href="style.css">
<script src="settingsloader.js"></script>
</head>
<body>

<div id="topbar">
    <span class="site-name">@6chan</span>
    <a href="index.php">Home</a>
    <a href="board.php?b=<?= htmlspecialchars($thread['slug']) ?>">/<?= htmlspecialchars($thread['slug']) ?>/</a>
    <a href="catalog.php?b=<?= htmlspecialchars($thread['slug']) ?>">[Catalog]</a>
    <a href="rules.php">[Rules]</a>
    <a href="search.php">[Search]</a>
    <a href="contact.php">[Contact]</a>
    <a href="support.php">[Support]</a>
    <a href="feed.php">[RSS]</a>
</div>

<div class="wrap">
    <div class="page-header">
        <h1>
            <?php if ($is_pinned): ?><span class="thread-badge pin">📌</span><?php endif; ?>
            <?php if ($is_locked): ?><span class="thread-badge lock">🔒</span><?php endif; ?>
            <?= htmlspecialchars($thread['subject'] ?: 'Thread #'.$id) ?>
        </h1>
        <p>/<?= htmlspecialchars($thread['slug']) ?>/ — <?= htmlspecialchars($thread['bname']) ?></p>
    </div>

    <div class="thread">
        <div class="thread-op" id="p<?= $thread['id'] ?>">
            <?php if (!empty($thread['img_path'])): ?>
                <?= render_media($thread['img_path'], (bool)$thread['nsfw'], $thread['id']) ?>
            <?php endif; ?>
            <div class="post-header">
                <?php if ($thread['subject']): ?>
                    <span class="subject"><?= htmlspecialchars($thread['subject']) ?></span>
                <?php endif; ?>
                <span class="author"><?= htmlspecialchars($thread['author'] ?? 'Anon') ?></span>
                <span class="postnum">No.<?= $thread['id'] ?></span>
                <span class="postdate"
                      data-ts="<?= date('Y-m-d H:i', strtotime($thread['created_at'])) ?>">
                    <?= date('Y-m-d H:i', strtotime($thread['created_at'])) ?>
                </span>
                <a href="report.php?type=thread&id=<?= $thread['id'] ?>&b=<?= htmlspecialchars($thread['slug']) ?>"
                   class="report-link">[Report]</a>
            </div>
            <div class="post-body"><?= render_body($thread['body'], $thread['id']) ?></div>
        </div>

        <div class="replies">
            <?php foreach ($posts as $p): ?>
            <div class="post" id="p<?= $p['id'] ?>">
                <div class="post-header">
                    <?php if (!empty($p['options']) && $p['options'] === 'sage'): ?>
                        <span style="color:var(--grey);font-size:11px;">[sage]</span>
                    <?php endif; ?>
                    <span class="author"><?= htmlspecialchars($p['author'] ?? 'Anon') ?></span>
                    <span class="postnum">No.<?= $p['id'] ?></span>
                    <span class="postdate"
                          data-ts="<?= date('Y-m-d H:i', strtotime($p['created_at'])) ?>">
                        <?= date('Y-m-d H:i', strtotime($p['created_at'])) ?>
                    </span>
                    <a href="report.php?type=post&id=<?= $p['id'] ?>&tid=<?= $id ?>&b=<?= htmlspecialchars($thread['slug']) ?>"
                       class="report-link">[Report]</a>
                </div>
                <?php if (!empty($p['img_path'])): ?>
                    <?= render_media($p['img_path'], (bool)$p['nsfw'], $id) ?>
                <?php endif; ?>
                <div class="post-body"><?= render_body($p['body'], $id) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($is_locked): ?>
    <div class="locked-notice">
        🔒 This thread is locked! No new replies can be posted.
    </div>
    <?php else: ?>
    <div class="postform">
        <h3>Post a reply</h3>
        <form method="post" action="post.php" enctype="multipart/form-data">
            <input type="hidden" name="thread_id"   value="<?= $id ?>">
            <input type="hidden" name="board_slug"  value="<?= htmlspecialchars($thread['slug']) ?>">
            <input type="hidden" name="action"      value="reply">
            <input type="hidden" name="captcha_token" value="<?= htmlspecialchars($captcha['token']) ?>">
            <table>
                <tr>
                    <td>Name</td>
                    <td><input type="text" name="author" maxlength="64" placeholder="Anon"></td>
                </tr>
                <tr>
                    <td>Options</td>
                    <td><input type="text" name="options" maxlength="64"
                               placeholder="sage to not bump thread"></td>
                </tr>
                <tr>
                    <td>Comment</td>
                    <td><textarea name="body" required></textarea></td>
                </tr>
                <tr>
                    <td>Image</td>
                    <td><input type="file" name="image"
                               accept="image/jpeg,image/png,image/gif,image/webp,video/mp4"></td>
                </tr>
                <tr>
                    <td>NSFW</td>
                    <td>
                        <label style="font-size:13px;color:var(--grey);cursor:pointer;">
                            <input type="checkbox" name="nsfw" value="1"
                                   style="accent-color:var(--orange);margin-right:4px;">
                            Mark as NSFW
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>Verify</td>
                    <td>
                        <span class="captcha-question"><?= $captcha['question'] ?></span>
                        <input type="text" name="captcha_answer" maxlength="30"
                               placeholder="Your answer" autocomplete="off">
                    </td>
                </tr>
                <tr><td></td><td><button type="submit">Reply</button></td></tr>
            </table>
        </form>
    </div>
    <?php endif; ?>
</div>

<footer>
    @6chan —
    <a href="board.php?b=<?= htmlspecialchars($thread['slug']) ?>">[back]</a> —
    <a href="index.php">Home</a>
    <a href="contact.php">[Contact]</a>
    <a href="support.php">[Support]</a>
</footer>

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
            const body   = target.querySelector('.post-body')?.innerHTML   ?? '';
            html = `<div class="post-header">${header}</div><div class="post-body">${body}</div>`;
        } else {
            html = `<div class="post-header">No.${postId}</div><div class="post-body">[post not in thread]</div>`;
        }

        popup.innerHTML    = html;
        popup.style.display = 'block';
        movePopup(e);
    });

    link.addEventListener('mousemove',  movePopup);
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

function revealNsfw(el) {
    const orig = el.dataset.orig;
    if (orig) el.src = orig;
    el.classList.remove('nsfw-blur');
    el.onclick = null;
    el.title   = '';
    const label = el.closest('.post-img')?.querySelector('.nsfw-label');
    if (label) label.style.display = 'none';
}
</script>

</body>
</html>
