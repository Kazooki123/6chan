<?php
require_once '../config/db.php';
$db = get_db();

if (isset($_GET['ac'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');

    if (strlen($q) < 2) {
        echo json_encode([]);
        exit;
    }

    $like = '%' . $q . '%';

    $stmt = $db->prepare(
        "SELECT DISTINCT ON (suggestion) suggestion, score FROM (
            SELECT
                coalesce(subject, left(body, 60)) AS suggestion,
                similarity(coalesce(subject, body), ?) AS score
            FROM threads
            WHERE subject ILIKE ? OR body ILIKE ?

            UNION ALL

            SELECT
                left(body, 60) AS suggestion,
                similarity(body, ?) AS score
            FROM posts
            WHERE body ILIKE ?
        ) sub
        WHERE score > 0.08
        ORDER BY suggestion, score DESC
        LIMIT 8"
    );
    $stmt->execute([$q, $like, $like, $q, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(array_values($rows));
    exit;
}

$q            = trim($_GET['q'] ?? '');
$board_filter = trim($_GET['board'] ?? '');
$thread_hits  = [];
$post_hits    = [];
$error        = '';

$boards = $db->query(
    "SELECT slug, name FROM boards ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

if ($q !== '') {
    if (strlen($q) < 2) {
        $error = 'Query too short — minimum 2 characters.';
    } else {
        $words    = array_filter(array_map('trim', explode(' ', $q)));
        $ts_parts = array_map(fn($w) => $w . ':*', $words); // :* = prefix matching
        $tsquery  = implode(' & ', $ts_parts);

        $board_join  = '';
        $board_where = '';
        $params_t    = [$tsquery, $q, $q];
        $params_p    = [$tsquery, $q];

        if ($board_filter !== '') {
            $board_where = 'AND b.slug = ?';
            $params_t[]  = $board_filter;
            $params_p[]  = $board_filter;
        }

        // Thread search
        $tr = $db->prepare(
            "SELECT
                t.id, t.subject, t.created_at, b.slug, b.name AS bname,
                ts_rank(t.search_vec, to_tsquery('english', ?)) AS fts_rank,
                similarity(coalesce(t.subject,'') || ' ' || t.body, ?) AS fuzzy_rank,
                ts_headline('english', t.body,
                    to_tsquery('english', ?),
                    'MaxWords=20, MinWords=8, ShortWord=3, HighlightAll=false'
                ) AS excerpt
             FROM threads t
             JOIN boards b ON b.id = t.board_id
             WHERE (
                 t.search_vec @@ to_tsquery('english', ?)
                 OR similarity(t.body, ?) > 0.12
             )
             $board_where
             ORDER BY fts_rank DESC, fuzzy_rank DESC
             LIMIT 25"
        );

        $tr_params = [$tsquery, $q, $tsquery, $tsquery, $q];
        if ($board_filter !== '') $tr_params[] = $board_filter;
        $tr->execute($tr_params);
        $thread_hits = $tr->fetchAll(PDO::FETCH_ASSOC);

        $pr = $db->prepare(
            "SELECT
                p.id, p.created_at, p.thread_id, b.slug,
                ts_rank(p.search_vec, to_tsquery('english', ?)) AS fts_rank,
                similarity(p.body, ?) AS fuzzy_rank,
                ts_headline('english', p.body,
                    to_tsquery('english', ?),
                    'MaxWords=20, MinWords=8, ShortWord=3'
                ) AS excerpt
             FROM posts p
             JOIN threads t ON t.id = p.thread_id
             JOIN boards b  ON b.id = t.board_id
             WHERE (
                 p.search_vec @@ to_tsquery('english', ?)
                 OR similarity(p.body, ?) > 0.12
             )
             $board_where
             ORDER BY fts_rank DESC, fuzzy_rank DESC
             LIMIT 25"
        );

        $pr_params = [$tsquery, $q, $tsquery, $tsquery, $q];
        if ($board_filter !== '') $pr_params[] = $board_filter;
        $pr->execute($pr_params);
        $post_hits = $pr->fetchAll(PDO::FETCH_ASSOC);
    }
}

$total = count($thread_hits) + count($post_hits);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Search — @6chan</title>
<link rel="stylesheet" href="style.css">
<script src="settingsloader.js"></script>
<style>
.search-form {
    display: flex;
    gap: 6px;
    align-items: flex-start;
    flex-wrap: wrap;
    margin-bottom: 14px;
}
.search-input-wrap {
    position: relative;
    flex: 1;
    min-width: 200px;
}
#search-input {
    width: 100%;
    background: #111;
    border: 1px solid var(--border);
    color: var(--white);
    font: 14px 'Courier New', monospace;
    padding: 6px 10px;
    box-sizing: border-box;
}
#search-input:focus {
    outline: none;
    border-color: var(--orange);
}
.ac-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--surface);
    border: 1px solid var(--orange);
    border-top: none;
    z-index: 100;
    display: none;
    max-height: 260px;
    overflow-y: auto;
}
.ac-item {
    padding: 6px 10px;
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--white);
    border-bottom: 1px solid var(--border);
}
.ac-item:last-child { border-bottom: none; }
.ac-item:hover,
.ac-item.active { background: #2a1a00; color: var(--orange); }

.search-btn {
    background: var(--orange);
    border: none;
    color: #000;
    font: bold 13px 'Courier New', monospace;
    padding: 6px 18px;
    cursor: pointer;
    white-space: nowrap;
}
.search-btn:hover { background: var(--orange2); }

.board-filter {
    background: #111;
    border: 1px solid var(--border);
    color: var(--white);
    font: 13px 'Courier New', monospace;
    padding: 6px 8px;
}

.result-section h2 {
    color: var(--orange);
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 4px;
    margin: 14px 0 8px;
}
.result-item {
    background: var(--postbg);
    border: 1px solid var(--border);
    padding: 8px 12px;
    margin-bottom: 6px;
    font-size: 13px;
}
.result-item:hover { border-color: var(--orange); }
.result-meta {
    font-size: 11px;
    color: var(--grey);
    margin-bottom: 4px;
}
.result-meta .board-tag {
    color: var(--orange);
    margin-right: 6px;
}
.result-excerpt {
    color: #bbb;
    font-size: 12px;
    line-height: 1.6;
    margin-top: 4px;
}

.result-excerpt b {
    color: var(--orange);
    font-weight: bold;
}
.result-link {
    font-size: 12px;
    color: var(--link);
    float: right;
}
.no-results {
    color: var(--grey);
    font-size: 13px;
    padding: 12px 0;
}
.result-count {
    color: var(--grey);
    font-size: 12px;
    margin-bottom: 10px;
}
.result-count strong { color: var(--orange); }
</style>
</head>
<body>

<div id="topbar">
    <span class="site-name">@6chan</span>
    <a href="index.php">Home</a>
    <a href="rules.php">[Rules]</a>
    <a href="search.php">[Search]</a>
    <a href="support.php">[Support]</a>
    <a href="contact.php">[Contact]</a>
    <a href="feed.php">[RSS]</a>
</div>

<div class="wrap">
    <div class="page-header">
        <h1>Search</h1>
        <p>Full-text search across all boards. Typos are forgiven.</p>
    </div>

    <form class="search-form" method="get" action="search.php"
          onsubmit="closeAc()">
        <div class="search-input-wrap">
            <input type="text"
                   id="search-input"
                   name="q"
                   value="<?= htmlspecialchars($q) ?>"
                   placeholder="Search threads and posts..."
                   autocomplete="off">
            <div class="ac-dropdown" id="ac-dropdown"></div>
        </div>

        <select name="board" class="board-filter">
            <option value="">All boards</option>
            <?php foreach ($boards as $b): ?>
            <option value="<?= htmlspecialchars($b['slug']) ?>"
                <?= $board_filter === $b['slug'] ? 'selected' : '' ?>>
                /<?= htmlspecialchars($b['slug']) ?>/ — <?= htmlspecialchars($b['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="search-btn">Search</button>
    </form>

    <?php if ($error): ?>
        <p class="no-results"><?= htmlspecialchars($error) ?></p>

    <?php elseif ($q !== ''): ?>
        <div class="result-count">
            <?php if ($total > 0): ?>
                Found <strong><?= $total ?></strong>
                result<?= $total === 1 ? '' : 's' ?> for
                <strong><?= htmlspecialchars($q) ?></strong>
                <?php if ($board_filter): ?>
                    in <strong>/<?= htmlspecialchars($board_filter) ?>/</strong>
                <?php endif; ?>
            <?php else: ?>
                No results for <strong><?= htmlspecialchars($q) ?></strong>.
                Try fewer or different words.
            <?php endif; ?>
        </div>

        <?php if ($thread_hits): ?>
        <div class="result-section">
            <h2>Threads (<?= count($thread_hits) ?>)</h2>
            <?php foreach ($thread_hits as $r): ?>
            <div class="result-item">
                <a href="thread.php?id=<?= $r['id'] ?>"
                   class="result-link">[View thread]</a>
                <div class="result-meta">
                    <span class="board-tag">/<?= htmlspecialchars($r['slug']) ?>/</span>
                    <?= htmlspecialchars($r['subject'] ?: 'No subject') ?>
                    <span style="margin-left:8px;">
                        <?= date('Y-m-d H:i', strtotime($r['created_at'])) ?>
                    </span>
                </div>
                <div class="result-excerpt">
                    <?= $r['excerpt'] ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($post_hits): ?>
        <div class="result-section">
            <h2>Posts (<?= count($post_hits) ?>)</h2>
            <?php foreach ($post_hits as $r): ?>
            <div class="result-item">
                <a href="thread.php?id=<?= $r['thread_id'] ?>#p<?= $r['id'] ?>"
                   class="result-link">[View post]</a>
                <div class="result-meta">
                    <span class="board-tag">/<?= htmlspecialchars($r['slug']) ?>/</span>
                    Post No.<?= $r['id'] ?>
                    <span style="margin-left:8px;">
                        <?= date('Y-m-d H:i', strtotime($r['created_at'])) ?>
                    </span>
                </div>
                <div class="result-excerpt">
                    <?= $r['excerpt'] ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<footer>
    @6chan — <a href="index.php">Home</a> — <a href="rules.php">Rules</a> <a href="contact.php">[Contact]</a> <a href="support.php">[Support]</a>
</footer>

<script>
const input    = document.getElementById('search-input');
const dropdown = document.getElementById('ac-dropdown');
let   items    = [];
let   activeIdx = -1;
let   debounce  = null;

input.addEventListener('input', () => {
    clearTimeout(debounce);
    const q = input.value.trim();
    if (q.length < 2) { closeAc(); return; }
    debounce = setTimeout(() => fetchSuggestions(q), 220);
});

input.addEventListener('keydown', e => {
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIdx = Math.min(activeIdx + 1, items.length - 1);
        render();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIdx = Math.max(activeIdx - 1, -1);
        render();
    } else if (e.key === 'Enter' && activeIdx >= 0) {
        e.preventDefault();
        input.value = items[activeIdx];
        closeAc();
        input.closest('form').submit();
    } else if (e.key === 'Escape') {
        closeAc();
    }
});

document.addEventListener('click', e => {
    if (!e.target.closest('.search-input-wrap')) closeAc();
});

async function fetchSuggestions(q) {
    try {
        const res  = await fetch(`search.php?ac=1&q=${encodeURIComponent(q)}`);
        items      = await res.json();
        activeIdx  = -1;
        render();
    } catch { closeAc(); }
}

function render() {
    if (!items.length) { closeAc(); return; }
    dropdown.innerHTML = items.map((item, i) =>
        `<div class="ac-item${i === activeIdx ? ' active' : ''}"
              data-i="${i}">${escHtml(item)}</div>`
    ).join('');
    dropdown.style.display = 'block';

    dropdown.querySelectorAll('.ac-item').forEach(el => {
        el.addEventListener('mousedown', e => {
            e.preventDefault();
            input.value = items[+el.dataset.i];
            closeAc();
            input.closest('form').submit();
        });
        el.addEventListener('mouseover', () => {
            activeIdx = +el.dataset.i;
            render();
        });
    });
}

function closeAc() {
    dropdown.style.display = 'none';
    items    = [];
    activeIdx = -1;
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

</body>
</html>
