<?php
// session_start();

require_once '../config/db.php';
require_once '../config/cache.php';
require_once '../config/captchav2.php';
require_once '../config/markdown.php';
require_once '../config/reactions.php';

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

$captcha = captchav2_generate();

$reaction_targets = [['type' => 'thread', 'id' => $thread['id']]];
foreach ($posts as $p) {
    $reaction_targets[] = ['type' => 'post', 'id' => $p['id']];
}
$reactions = get_reactions($reaction_targets, $_SERVER['REMOTE_ADDR'], $db);

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

function render_reactions(string $post_type, int $post_id, array $reactions): string {
    $key      = $post_type . ':' . $post_id;
    $existing = $reactions[$key] ?? [];
    $allowed  = ALLOWED_REACTIONS;

    $out = '<div class="reactions" data-type="' . $post_type
         . '" data-id="' . $post_id . '">';

    foreach ($existing as $emoji => $mine) {
        $cls  = 'reaction-btn' . ($mine ?  ' reacted' : '');
        $out .= '<button class="' . $cls . '" data-emoji="' . htmlspecialchars($emoji) . '">'
              . $emoji . '</button>';
    }

    $out .= '<div class="reaction-picker-wrap">';
    $out .= '<button class="reaction-add-btn" title="Add reaction">+</button>';
    $out .= '<div class="reaction-picker">';
    foreach ($allowed as $emoji) {
        if (!isset($existing[$emoji])) {
            $out .= '<button class="reaction-pick" data-emoji="'
                  . htmlspecialchars($emoji) . '">' . $emoji . '</button>';
        }
    }
    $out .= '</div></div>';
    $out .= '</div>';

    return $out;
}

function render_poll(int $thread_id, ?int $post_id, string $viewer_ip, PDO $db): string {
    if ($post_id !== null) {
        $stmt = $db->prepare("SELECT * FROM polls WHERE post_id=?");
        $stmt->execute([$post_id]);
    } else {
        $stmt = $db->prepare("SELECT * FROM polls WHERE thread_id=? AND post_id IS NULL");
        $stmt->execute([$thread_id]);
    }
    $poll = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$poll) return '';

    $ip_hash = hash_ip($viewer_ip);
    $closed  = $poll['closes_at'] && strtotime($poll['closes_at']) < time();

    $opts = $db->prepare(
        "SELECT po.id, po.label,
                COUNT(pv.id) AS votes,
                MAX(CASE WHEN pv.ip_hash=? THEN 1 ELSE 0 END) AS my_vote
         FROM poll_options po
         LEFT JOIN poll_votes pv ON pv.option_id=po.id
         WHERE po.poll_id=?
         GROUP BY po.id, po.label, po.position
         ORDER BY po.position"
    );
    $opts->execute([$ip_hash, $poll['id']]);
    $options = $opts->fetchAll(PDO::FETCH_ASSOC);
    $total   = max(1, array_sum(array_column($options, 'votes')));
    $voted   = in_array(1, array_column($options, 'my_vote'));

    $out  = '<div class="poll" id="poll-' . $poll['id'] . '" '
          . 'data-poll="' . $poll['id'] . '">';
    $out .= '<div class="poll-question">' . htmlspecialchars($poll['question']) . '</div>';

    if ($closed) {
        $out .= '<div class="poll-status closed">🔒 Poll closed</div>';
    } elseif ($poll['closes_at']) {
        $out .= '<div class="poll-status">'
              . 'Closes ' . date('Y-m-d H:i', strtotime($poll['closes_at']))
              . '</div>';
    }

    $out .= '<div class="poll-options">';
    foreach ($options as $opt) {
        $pct    = $total > 0 ? round($opt['votes'] / $total * 100) : 0;
        $mine   = (bool)$opt['my_vote'];
        $optcls = 'poll-option' . ($mine ? ' my-vote' : '') . ($closed ? ' closed' : '');

        $out .= '<div class="' . $optcls . '" '
              . ($closed ? '' : 'onclick="castVote(' . $poll['id'] . ',' . $opt['id'] . ')"')
              . '>';
        $out .= '<div class="poll-bar" style="width:' . $pct . '%"></div>';
        $out .= '<div class="poll-option-inner">';
        $out .= '<span class="poll-label">'
              . htmlspecialchars($opt['label'])
              . ($mine ? ' ✔' : '') . '</span>';
        $out .= '<span class="poll-pct">' . $pct . '% (' . $opt['votes'] . ')</span>';
        $out .= '</div>';
        $out .= '</div>';
    }
    $out .= '</div>';
    $out .= '<div class="poll-total">' . $total . ' vote' . ($total==1?'':'s') . '</div>';
    $out .= '</div>';
    return $out;
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
            <span class="postnum">No.<?= $thread['id'] ?></span>
            <span class="postdate"><?= date('Y-m-d H:i', strtotime($thread['created_at'])) ?></span>
            <a href="report.php?type=thread&id=<?= $thread['id'] ?>&tid=<?= $id ?>&b=<?= htmlspecialchars($thread['slug']) ?>"
               class="report-link">[Report]</a>
        </div>
        <div class="post-body"><?= render_body($thread['body'], $thread['id']) ?></div>
        <?= render_poll($thread['id'], null, $_SERVER['REMOTE_ADDR'], $db) ?>
        <?= render_reactions('thread', $thread['id'], $reactions) ?>
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
                <?php if (!empty($p['img_path']) && str_ends_with($p['img_path'], '.mp3')
                       || str_ends_with($p['img_path'] ?? '', '.flac')
                       || str_ends_with($p['img_path'] ?? '', '.ogg')): ?>
                    <form method="post" action="set_bgm.php" style="display:inline;">
                        <input type="hidden" name="thread_id" value="<?= $id ?>">
                        <input type="hidden" name="post_id"   value="<?= $p['id']?>">
                        <?php if ($thread['bgm_post_id'] === $p['id']): ?>
                            <input type="hidden" name="action" value="unset"> 
                            <button class="bgm-btn active" type="submit">🎶BGM</button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="set">
                            <button class="bgm-btn" type="submit">🎶Set BGM</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <div class="post-body"><?= render_body($p['body'], $id) ?></div>
            <?= render_poll($id, $p['id'], $_SERVER['REMOTE_ADDR'], $db) ?>
            <?= render_reactions('post', $p['id'], $reactions) ?>
        </div>
        <?php endforeach; ?>
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
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <img src="captcha_img.php?token=<?= htmlspecialchars($captcha['token']) ?>"
                                 alt="captcha"
                                 style="border:1px solid var(--border);height:60px;width:180px;
                                        image-rendering:pixelated;"
                                 id="captcha-img">
                            <button type="button"
                                    onclick="refreshCaptcha(this)"
                                    data-token="<?= htmlspecialchars($captcha['token']) ?>"
                                    style="background:none;border:1px solid var(--border);
                                           color:var(--grey);font:12px 'Courier New',monospace;
                                           padding:4px 8px;cursor:pointer;">
                                ↻ New
                            </button>
                        </div>
                        <input type="hidden" name="captcha_token"
                               value="<?= htmlspecialchars($captcha['token']) ?>"
                               id="captcha-token-input">
                        <input type="text" name="captcha_answer"
                               maxlength="6" placeholder="Enter the 6 characters"
                               autocomplete="off"
                               style="margin-top:6px;text-transform:uppercase;
                                      letter-spacing:4px;">
                        <div style="font-size:11px;color:var(--grey);margin-top:3px;">
                            Case insensitive — letters and numbers only.
                        </div>
                    </td>
                </tr>
                <tr>
                    <td>Media</td>
                    <td>
                        <input type="file" name="image"
                               accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,audio/mpeg,audio/flac,audio/ogg,audio/wav">
                    </td>
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
    <a href="index.php">Home</a> |
    <a href="settings/settings.php">[Settings]</a> |
    <a href="contact.php">[Contact]</a>
    <a href="support.php">[Support]</a>
</footer>

<?php

// Background Music
$bgm = null;
if (!empty($thread['bgm_post_id'])) {
    $bgm_stmt = $db->prepare("SELECT img_path FROM posts WHERE id=?");
    $bgm_stmt->execute([$thread['bgm_post_id']]);
    $bgm_post = $bgm_stmt->fetch(PDO::FETCH_ASSOC);
    if ($bgm_post && !empty($bgm_post['img_path'])) {
        $bgm_path   = $bgm_post['img_path'];
        $bgm_meta_f = __DIR__ . '/uploads/' . $bgm_path . '.json';
        $bgm_meta   = file_exists($bgm_meta_f)
                        ? json_decode(file_get_contents($bgm_meta_f), true)
                        : [];
        $bgm = [
            'path'   => $bgm_path,
            'title'  => $bgm_meta['title']  ?? 'Unknown',
            'artist' => $bgm_meta['artist'] ?? 'Unknown',
            'cover'  => !empty($bgm_meta['cover_file'])
                            ? 'uploads/' . $bgm_meta['cover_file']
                            : null,
        ];
    }
}
?>

<?php if ($bgm): ?>
<div id="bgm-bar">
    <audio id="bgm-audio" loop preload="none">
        <source src="uploads/<?= htmlspecialchars($bgm['path']) ?>">
    </audio>
    <?php if ($bgm['cover']): ?>
    <img src="<?= htmlspecialchars($bgm['cover']) ?>"
         alt="cover" class="bgm-cover">
    <?php endif; ?>
    <div class="bgm-info">
        <span class="bgm-title"><?= htmlspecialchars($bgm['title']) ?></span>
        <span class="bgm-artist"><?= htmlspecialchars($bgm['artist']) ?></span>
    </div>
    <div class="bgm-controls">
        <button class="bgm-play" onclick="bgmToggle()" title="Play/Pause">▶</button>
        <div class="bgm-progress-wrap" onclick="bgmSeek(event)">
            <div class="bgm-progress-bar" id="bgm-bar-fill"></div>
        </div>
        <span class="bgm-time" id="bgm-time">0:00</span>
        <input type="range" class="bgm-vol" id="bgm-vol"
               min="0" max="1" step="0.05" value="0.5"
               oninput="bgmVol(this.value)"
               title="Volume">
        <button class="bgm-close" onclick="bgmClose()" title="Close">✕</button>
    </div>
    <div class="bgm-label">♪ Thread BGM</div>
</div>

<script>
const bgmAudio = document.getElementById('bgm-audio');
const bgmFill  = document.getElementById('bgm-bar-fill');
const bgmTime  = document.getElementById('bgm-time');
const bgmBtn   = document.querySelector('.bgm-play');
bgmAudio.volume = 0.5;

bgmAudio.addEventListener('timeupdate', () => {
    const pct = bgmAudio.duration
        ? (bgmAudio.currentTime / bgmAudio.duration * 100) : 0;
    bgmFill.style.width = pct + '%';
    const s = Math.floor(bgmAudio.currentTime);
    bgmTime.textContent = Math.floor(s/60)+':'+String(s%60).padStart(2,'0');
});

function bgmToggle() {
    if (bgmAudio.paused) { bgmAudio.play(); bgmBtn.textContent = '⏸'; }
    else                 { bgmAudio.pause(); bgmBtn.textContent = '▶'; }
}
function bgmSeek(e) {
    const wrap = e.currentTarget;
    const pct  = (e.clientX - wrap.getBoundingClientRect().left) / wrap.offsetWidth;
    bgmAudio.currentTime = pct * bgmAudio.duration;
}
function bgmVol(v) { bgmAudio.volume = v; }
function bgmClose() {
    bgmAudio.pause();
    document.getElementById('bgm-bar').style.display = 'none';
}
</script>
<?php endif; ?>

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

document.addEventListener('click', async e => {
    if (e.target.matches('.reaction-btn')) {
        await doReact(e.target);
        return;
    }

    if (e.target.matches('.reaction-pick')) {
        await doReact(e.target);
        return;
    }

    if (e.target.matches('.reaction-add-btn')) {
        const picker = e.target.nextElementSibling;
        const isOpen = picker.classList.contains('open');
        // Close all open pickers first
        document.querySelectorAll('.reaction-picker.open')
                .forEach(p => p.classList.remove('open'));
        if (!isOpen) picker.classList.add('open');
        return;
    }

    if (!e.target.closest('.reaction-picker-wrap')) {
        document.querySelectorAll('.reaction-picker.open')
                .forEach(p => p.classList.remove('open'));
    }
});

async function doReact(btn) {
    const wrap     = btn.closest('.reactions');
    const postType = wrap.dataset.type;
    const postId   = wrap.dataset.id;
    const emoji    = btn.dataset.emoji;

    const form = new FormData();
    form.append('post_type', postType);
    form.append('post_id',   postId);
    form.append('emoji',     emoji);

    try {
        const res  = await fetch('react.php', { method: 'POST', body: form });
        const data = await res.json();

        if (!data.ok) {
            console.warn('Reaction error:', data.error);
            return;
        }

        // Update UI without page reload
        if (data.action === 'added') {
            const existing = wrap.querySelector(
                `.reaction-btn[data-emoji="${emoji}"]`
            );
            if (existing) {
                existing.classList.add('reacted');
            } else {
                const newBtn = document.createElement('button');
                newBtn.className    = 'reaction-btn reacted';
                newBtn.dataset.emoji = emoji;
                newBtn.textContent  = emoji;
                wrap.insertBefore(newBtn, wrap.querySelector('.reaction-picker-wrap'));
            }
            btn.closest('.reaction-pick') && btn.remove();

        } else if (data.action === 'removed') {
            btn.classList.remove('reacted');
        }

        document.querySelectorAll('.reaction-picker.open')
                .forEach(p => p.classList.remove('open'));

    } catch(err) {
        console.error('Reaction fetch failed:', err);
    }
}
async function refreshCaptcha(btn) {
  const res  = await fetch('captcha_new.php');
  const data = await res.json();
  if (!data.token) return;

  document.getElementById('captcha-img').src =
      'captcha_img.php?token=' + data.token + '&t=' + Date.now();
  document.getElementById('captcha-token-input').value = data.token;
}

async function castVote(pollId, optionId) {
  const form = new FormData();
  form.append('option_id', optionId);

  try {
    const res  = await fetch('vote.php', { method: 'POST', body: form });
    const data = await res.json();
    if (!data.ok) { console.warn(data.error); return; }
    updatePollUI(pollId, data.results, data.total);
  } catch(e) { console.error(e); }
}

function updatePollUI(pollId, results, total) {
  const wrap = document.getElementById('poll-' + pollId);
  if (!wrap) return;

  results.forEach(opt => {
    const pct = total > 0 ? Math.round(opt.votes / total * 100) : 0;
    wrap.querySelectorAll('.poll-option').forEach((el, i) => {
      if (results[i]) {
        el.querySelector('.poll-bar').style.width = pct + '%';
        el.querySelector('.poll-pct').textContent = pct + '% (' +  results[i].votes + ')';
      }
    });
  });

  const tot = wrap.querySelector('poll-total');
  if (tot) tot.textContent = total + ' vote' + (total===1?'':'s');
}

</script>
</body>
</html>
