<?php
require_once '../config/db.php';
require_once '../config/cache.php';
require_once '../config/captcha.php';
require_once '../config/markdown.php';
// require_once '../config/audio.php';

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
    $lines = explode("\n", $text);
    $processed = [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        
        if (preg_match('/^>>(\d+)/', $trimmed)) {
            $line = preg_replace('/^>>(\d+)/', '`%%QUOTE:$1%%`', $line);
        }

        $processed[] = $line;
    }

    $markdown_input = implode("\n", $processed);

    $html = render_markdown($markdown_input);

    $html = preg_replace_callback(
        '/<code>%%QUOTE:(\d+)%%<\/code>/',
        function($m) use ($thread_id) {
            $num = $m[1];
            return '<a class="quotelink" href="thread.php?id=' . $thread_id
                 . '#p' . $num . '" data-post="' . $num . '">&gt;&gt;' . $num . '</a>';
        },
        $html
    );
    
    return $html;
}

function render_media(string $path, bool $nsfw, int $thread_id): string {
    $is_video  = str_ends_with($path, '.mp4');
    $is_audio  = preg_match('/\.(mp3|flac|ogg|wav)$/i', $path);
    $blur_path = 'uploads/blur_' . $path;
    $orig_path = 'uploads/' . $path;
    $has_blur  = $nsfw && !$is_video && file_exists(__DIR__ . 'uploads/blur_' . $path);
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
                       preload='none' loading='lazy' {$nsfw_wrap}>
                </video>
                " . ($nsfw ? "<div class='nsfw-label'>Nsfw - click to show</div>" : "") . "
            </div>";
    }

    if ($is_audio) {
        $meta_path = __DIR__ . '/uploads/' . $path . '.json';
        $meta      = file_exists($meta_path)
                       ? json_decode(file_get_contents($meta_path), true)
                       : [];
                       
        $title    = htmlspecialchars($meta['title']    ?? 'Unknown');
        $artist   = htmlspecialchars($meta['artist']   ?? 'Unknown');
        $duration = htmlspecialchars($meta['duration'] ?? '-');
        $cover    = !empty($meta['cover_file'])
                        ? 'uploads/' . htmlspecialchars($meta['cover_file'])
                        : 'assets/no-cover.png';

        $pid = 'ap_' . md5($path);

        $lyrics_html = '';
        if (!empty($meta['lrc'])) {
            foreach ($meta['lrc'] as $i => $entry) {
                $t    = round($entry['time'], 2);
                $line = htmlspecialchars($entry['line']);
                $lyrics_html .= "<div class='lrc-line' data-time='{$t}' data-i='{$i}'>{$line}</div>"; 
            }
        } elseif (!empty($meta['lyrics'])) {
            $lyrics_html = '<div class="plain-lyrics">'
                         . nl2br(htmlspecialchars($meta['lyrics']))
                         . '</div>';
        } else {
            $lyrics_html = '<div class="no-lyrics">No lyrics available!</div>';
        }

        $has_lrc = !empty($meta['lrc']) ? 'true' : 'false';

        return "
        <div class='audio-post' id='{$pid}'>
            <div class='audio-left'>
                <img class='audio-cover' src='{$cover}' alt='Cover art'>
                <div class='audio-info'>
                    <div class='audio-title'>{$title}</div>
                    <div class='audio-artist'>{$artist}</div>
                    <div class='audio-duration' id='{$pid}-dur'>{$duration}</div>
                </div>
                <div class='audio-controls'>
                    <audio id='{$pid}-audio' preload='metadata' loop>
                        <source src='{$orig_path}'>
                    </audio>
                    <button class='audio-btn' onclick='apToggle(\"{$pid}\")'>▶</button>
                    <div class='audio-progress-wrap'
                         onclick='apSeek(event, \"{$pid}\")'>
                        <div class='audio-progress-bar' id='{$pid}-bar'></div>
                    </div>
                    <span class='audio-time' id='{$pid}-time'>0:00</span>
                </div>
            </div>
            <div class='audio-lyrics' id='{$pid}-lyrics'>
                {$lyrics_html}
            </div>
        </div>
        <script>
        (function(){
            const pid     = '{$pid}';
            const audio   = document.getElementById(pid+'-audio');
            const bar     = document.getElementById(pid+'-bar');
            const timeEl  = document.getElementById(pid+'-time');
            const btn     = document.querySelector('#'+pid+' .audio-btn');
            const hasLrc  = {$has_lrc};
            let   lastIdx = -1;
        
            audio.addEventListener('timeupdate', () => {
            // Progress bar
            const pct = audio.duration ? (audio.currentTime / audio.duration * 100) : 0;
            bar.style.width = pct + '%';
        
            // Elapsed time
            const s = Math.floor(audio.currentTime);
            timeEl.textContent = Math.floor(s/60) + ':' + String(s%60).padStart(2,'0');
        
            // LRC sync
            if (!hasLrc) return;
                const lines  = document.querySelectorAll('#'+pid+'-lyrics .lrc-line');
                const ct     = audio.currentTime;
                let   active = -1;
                lines.forEach((el, i) => {
                    if (parseFloat(el.dataset.time) <= ct) active = i;
                });
                if (active !== lastIdx) {
                    lines.forEach(el => el.classList.remove('lrc-active'));
                    if (active >= 0) {
                        const el = lines[active];
                        el.classList.add('lrc-active');
                        el.scrollIntoView({block:'center', behavior:'smooth'});
                    }
                    lastIdx = active;
                }
            });
        
            audio.addEventListener('ended', () => {
                btn.textContent = '▶';
                bar.style.width = '0%';
                lastIdx = -1;
            });
        })();
        
        function apToggle(pid) {
            const audio = document.getElementById(pid+'-audio');
            const btn   = document.querySelector('#'+pid+' .audio-btn');
            if (audio.paused) { audio.play(); btn.textContent = '⏸'; }
            else              { audio.pause(); btn.textContent = '▶'; }
        }
        
        function apSeek(e, pid) {
            const audio = document.getElementById(pid+'-audio');
            const wrap  = e.currentTarget;
            const rect  = wrap.getBoundingClientRect();
            const pct   = (e.clientX - rect.left) / rect.width;
            audio.currentTime = pct * audio.duration;
        }
        </script>
        ";
    }
    
    if ($has_blur) {
        return "
            <div class='post-img'>
                <img id='{$uid}'
                     src='{$blur_path}'
                     data-orig='{$orig_path}'
                     alt='nsfw image'
                     loading='lazy'
                     class='nsfw-blur'
                     onclick='revealNsfw(this)'
                     width='300'
                     height='200'
                     title='Nsfw - click to show'>
                <div class='nsfw-label'>Nsfw - click to reveal</div>
            </div>";
    }

    return "
        <div class='post-img'>
            <img src='{$orig_path}' alt='image' width='300' height='200' loading='lazy'"
            . ($nsfw ? " class='nsfw-blur' onclick='revealNsfw(this)' title='Nsfw - click to reveal'" : "")
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
      <?php if (!empty($thread['img_path'])): ?>
    <div class="thread-op" id="p<?= $thread['id'] ?>">
      <?= render_media($thread['img_path'], (bool)$thread['nsfw'], $thread['id']) ?>
      </div>
      <?php endif; ?>
      <div class="post-header">
        <?php if ($thread['subject']): ?>
          <span class="subject"><?= htmlspecialchars($thread['subject']) ?></span>
        <?php endif; ?>
        <span class="author"><?= htmlspecialchars($t['author'] ?? 'Anon') ?></span>
        <span class="postnum"><?= $thread['id'] ?></span>
        <span class="postdate"><?= date('Y-m-d H:i', strtotime($thread['created_at'])) ?></span>
        <a href="report.php?type=thread&id=<?= $thread['id'] ?>&tid=<?= $id ?>&b=<?= htmlspecialchars($thread['slug']) ?>"
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
                    <td></td>
                    <td>
                        <div class="markdown-hint">
                            <strong>Markdown Supported:</strong>
                            <code>**bold**</code> <code>*italic*</code>
                            <code>`code`</code> <code>~~strike~~</code>
                            <code>&gt;greentext</code> <code>&gt;&gt;123</code> |
                            <a href="docs/markdownguide.php" style="color:var(--orange);font-size:11px;">[Guide]</a>
                        </div>
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
                <tr>
                    <td>Media</td>
                    <td><input type="file" name="image"
                               accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,audio/mpeg,audio/flac,audio/ogg,audio/wav"></td>
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

// document.querySelector('input[name="image"]').addEventListener('change', function() {
//   const f   = this.files[0];
//   const row = document.getElementById('lrc-row');
//   const isAudio = f && f.type.startsWith('audio/');
//   row.style.display = isAudio ? '';
 // });

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
