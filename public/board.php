<?php
// require_once __DIR__ . '/../vendor/autoload.php';
// session_start();

require_once '../config/db.php';
require_once '../config/cache.php';
require_once '../config/captchav2.php';
require_once '../config/markdown.php';
require_once '../config/reactions.php';

$db   = get_db();
$slug = $_GET['b'] ?? '';
$id = (int)($_GET['id'] ?? 0);

$board = $db->prepare("SELECT * FROM boards WHERE slug = ?");
$board->execute([$slug]);
$board = $board->fetch(PDO::FETCH_ASSOC);
if (!$board) {
    http_response_code(404);
    header('Location: /404.php');
}

$page     = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$threads = $db->prepare(
    "SELECT * FROM threads WHERE board_id = ?
     ORDER BY pinned, bumped_at DESC
     LIMIT ? OFFSET ?"
);

$total_stmt = $db->prepare("SELECT COUNT(*) FROM threads WHERE board_id = ?");
$total_stmt->execute([$board['id']]);
$total_threads = (int)$total_stmt->fetchColumn();
$total_pages   = max(1, (int)ceil($total_threads / $per_page));

$cache_key = "board:{$board['id']}:p{$page}";
$cached    = cache_get($cache_key, $db);

if ($cached) {
    $threads = $cached;
} else {
    $threads->execute([$board['id'], $per_page, $offset]);
    $threads = $threads->fetchAll(PDO::FETCH_ASSOC);
    cache_set($cache_key, $threads, $db, 120);
}

$captcha = captchav2_generate();

$reaction_targets = [['type' => 'thread', 'id' => $threads['id']]];
foreach ($threads as $t) {
    $reaction_targets[] = ['type' => 'thread', 'id' => $t['id']];
}
$reactions = get_reactions($reaction_targets, $_SERVER['REMOTE_ADDR'], $db);

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
    $lines = explode("\n", $text);
    $processed = [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        
        if (preg_match('/^>>(\d+)/', $trimmed)) {
            $line = preg_replace('/>>(\d+)/', '`%%QUOTE:$1%%`', $line);
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
                    <audio id='{$pid}-audio' preload='metadata'>
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
    <title>/<?= htmlspecialchars($board['slug']) ?>/ - <?= htmlspecialchars($board['name']) ?></title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="stylesheet" href="style.css">
    <script src="settingsloader.js"></script>
</head>
<body>
    <div id="topbar">
        <span class="site-name">@6chan</span>
        <a href="index.php">Home</a>
        <a href="board.php?b=<?= htmlspecialchars($board['slug']) ?>">/<?= htmlspecialchars($board['slug']) ?>/</a>
        <a href="catalog.php?b=<?= htmlspecialchars($board['slug']) ?>">[Catalog]</a>
        <a href="rules.php">[Rules]</a>
        <a href="search.php">[Search]</a>
        <a href="contact.php">[Contact]</a>
        <a href="support.php">[Support]</a>
        <a href="feed.php">[RSS]</a>
    </div>

    <div class="wrap">
        <div class="page-header">
            <h1>/<?= htmlspecialchars($board['slug']) ?>/ - <?= htmlspecialchars($board['name']) ?></h1>
            <p><?= htmlspecialchars($board['descript']) ?></p>
        </div>

        <div class="postform">
            <h3>Start a new thread!! &lt;3</h3>
            <form method="post" action="post.php" enctype="multipart/form-data">
                <input type="hidden" name="board_slug" value="<?= htmlspecialchars($board['slug']) ?>">
                <input type="hidden" name="action" value="new_thread">
                <table>
                    <tr><td>Name</td><td><input type="text" name="author" maxlength="64" placeholder="Anon"></td></tr>
                    <tr><td>Subject</td><td><input type="text" name="subject" maxlength="200"></td></tr>
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
                    <tr><td>Comment</td><td><textarea name="body" required></textarea></td></tr>
                    <tr>
                        <td>Polls</td>
                        <td>
                            <label style="font-size:12px;color:var(--grey);cursor:pointer;">
                                <input type="checkbox" id="poll-toggle" name="add_poll" value="1"
                                       style="accent-color:var(--orange);margin-right:4px;"
                                       onchange="document.getElementById('poll-fields')
                                                 .style.display=this.checked?'block':'none'">
                                Add a poll to this post
                            </label>
                            <div id="poll-fields" style="display:none;margin-top:8px;">
                                <input type="text" name="poll_question"
                                       placeholder="Poll question..."
                                       maxlength="200"
                                       style="width:100%;margin-bottom:6px;">
                                <div id="poll-options">
                                    <input type="text" name="poll_options[]"
                                           placeholder="Option 1" maxlength="100"
                                           style="width:100%;margin-bottom:4px;">
                                    <input type="text" name="poll_options[]"
                                           placeholder="Option 2" maxlength="100"
                                           style="width:100%;margin-bottom:4px;">
                                </div>
                                <button type="button" onclick="addPollOption()"
                                        style="background:none;border:1px dashed var(--border);
                                               color:var(--grey);font:12px 'MapleMono',monospace;
                                               padding:3px 10px;cursor:pointer;margin-bottom:6px;">
                                    + Add Option
                                </button>
                                <div style="font-size:11px;color:var(--grey);">
                                    Closes:
                                    <input type="datetime-local" name="poll_closes"
                                           style="background:#111;border:1px solid var(--border);
                                                  color:var(--white);font:12px 'MapleMono',monospace;
                                                  padding:3px 6px;">
                                    (leave blank = permanent)
                                </div>
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
                    <tr><td>Media</td><td><input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,audio/mpeg,audio/flac,audio/ogg,audio/wav"></td></tr>
                    <tr id="lrc-row" style="display:none;">
                        <td>Lyrics? (.lrc)</td>
                        <td>
                            <input type="file" name="lrc" accept=".lrc,.txt">
                            <div style="font-size:11px;color:var(--grey);margin-top:3px;">
                                Optional - only if the audio has no embedded lyrics to it.
                            </div>
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
                    <tr><td></td><td><button type="submit">Post</button></td></tr>
                </table>
            </form>
        </div>

        <?php foreach ($threads as $t): ?>
        <div class="thread">
            <div class="thread-op" id="p<?= $t['id'] ?>">
                    <?php if (!empty($t['img_path'])):
                        $imgpath = "uploads/" . htmlspecialchars($t['img_path']); ?>
                    <?= render_media($t['img_path'], (bool)$t['nsfw'], $t['id']) ?>
                    <a href="<?= $imgpath ?>">View original</a>
                    <?php endif; ?>
                <div class="post-header">
                    <?php if ($t['nsfw']): ?>
                        <span class="thread-badge" style="background:#2a0010;color:#ff6688;border-color:#ff6688;">
                            NSFW
                        </span>
                    <?php endif; ?>
                    <?php if ($t['subject']): ?>
                        <span class="subject"><?= htmlspecialchars($t['subject']) ?></span>
                    <?php endif; ?>
                    <span class="author"><?= htmlspecialchars($t['author'] ?? 'Anon') ?></span>
                    <span class="postnum">No.<?= $t['id'] ?></span>
                    <span class="postdate"><?= date('Y-m-d H:i', strtotime($t['created_at'])) ?></span>
                    <a href="thread.php?id=<?= $t['id'] ?>" class="reply-link">[Reply]</a>
                    <a href="report.php?type=thread&id=<?= $t['id'] ?>&b=<?= $t['slug'] ?? $slug ?>"
                       class="report-link">[Report]</a>
                    <?php if ($t['pinned']): ?>
                        <span class="thread-badge pin">📌</span>
                    <?php endif; ?>
                    <?php if ($t['locked']): ?>
                        <span class="thread-badge lock">🔒</span>
                    <?php endif; ?>
                </div>
                <div class="post-body"><?= render_body($t['body'], $t['id']) ?></div>
                <?= render_poll($t['id'], null, $_SERVER['REMOTE_ADDR'], $db) ?>
                <?= render_reactions('thread', $t['id'], $reactions) ?>
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
                       <?php if (!empty($p['options']) && $p['options'] === 'sage'): ?>
                         <span style="color:var(--grey);font-size:11px;margin-left:4px;">[sage]</span>
                       <?php endif; ?>
                       <span class="author"><?= htmlspecialchars($p['author'] ?? 'Anon') ?></span>
                       <span class="postnum">No.<?= $p['id'] ?></span>
                       <span class="postdate"
                             data-ts="<?= date('Y-m-d H:i', strtotime($p['created_at'])) ?>">
                           <?= date('Y-m-d H:i', strtotime($p['created_at'])) ?>
                       </span>
                       <a href="report.php?type=thread&id=<?= $p['id'] ?>&b=<?= $p['slug'] ?? $slug ?>"
                          class="report-link">[Report]</a>
                   </div>
                   <?php if (!empty($p['img_path'])):
                       $imgpath =
                           "uploads/" . htmlspecialchars($p['img_path']); ?>
                   <div class="post-img">
                       <?= render_media($p['img_path'], (bool)$p['nsfw'], $p['id']) ?>
                       <a href="<?= $imgpath ?>">View original</a>
                   </div>
                   <?php endif; ?>
                   <div class="post-body"><?= render_body($p['body'], $t['id']) ?></div>
                   <?= render_poll($id, $p['id'], $_SERVER['REMOTE_ADDR'], $db) ?>
                   <?= render_reactions('post', $p['id'], $reactions) ?>
               </div>
               <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Pagination :3 -->
        <div class="nav">
            <?php if ($page > 1): ?>
                <a href="board.php?b=<?= $slug ?>&p=<?= $page-1 ?>">[&laquo; Prev]</a>
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

    <footer>@6chan — /<?= htmlspecialchars($board['slug']) ?>/ — <a href="index.php">Home</a> | <a href="settings/settings.php">[Settings]</a> | <a href="contact.php">[Contact]</a> <a href="support.php">[Support]</a></footer>

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

    function addPollOption() {
      const wrap = document.getElementById('poll-options');
      const n    = wrap.querySelectorAll('input').length + 1;
      if (n > 10) return;
      const inp  = document.createElement('input');
      inp.type        = 'text';
      inp.name        = 'poll_options[]';
      inp.placeholder = 'Option ' + n;
      inp.maxLength   = 100;
      inp.style.cssText = 'width:100%;margin-bottom:4px;';
      wrap.appendChild(inp);
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
