<?php
require_once '../config/db.php';
$db = get_db();
$boards = $db->query("SELECT slug, name, descript FROM boards ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="6chan the Open Source Imageboard">
    <title>6chan - Home</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico"/>
    <link rel="stylesheet" href="style.css"/>
    <script src="/settingsloader.js"></script>
    <script src="/shortcuts.js"></script>
    <script src="/oneko.js" defer></script>
</head>
<body class="home">
    <nav id="topbar">
        <span class="site-name">@6chan</span>
        <a href="index.php">Home</a>
        <a href="rules.html">[Rules]</a>
        <a href="search.php">[Search]</a>
        <a href="contact.php">[Contact]</a>
        <a href="support.html">[Support]</a>
        <a href="feed.php">[RSS]</a>
        <a href="https://codeberg.org/Kazooki123/6chan">[⭐ Source]</a>
    </nav>

    <div class="wrap">
        <div class="welcome-block">
            <div class="welcome-title">Welcome to 6chan !!</div>
            <p>
                A simple, no-frills imageboard. Pick a board, start a thread, keep it real.
                This is a community built on honest and thoughtful discussion - read the rules, respect the boards, and don't be an IDIOT.
            </p>
            <div class="welcome-links">
                <a href="rules.html">[rules]</a>
                <a href="board.php?b=r">[/r/ - random]</a>
                <a href="board.php?b=t">[/t/ - tech]</a>
                <a href="about.html">[about]</a>
                <a href="etc/copyright.html">[copyright]</a>
                <a href="etc/privacy.html">[privacy]</a>
                <a href="etc/fos.html">[freedom]</a>
                <a href="">[wiki]</a>
            </div>
        </div>
        
        <div class="board-list">
            <h2>Boards</h2>
            <?php foreach ($boards as $b): ?>
            <div class="board-item">
                <span class="slug">
                    <a href="board.php?b=<?= htmlspecialchars($b['slug']) ?>">/<?= htmlspecialchars($b['slug']) ?>/</a>
                </span>
                <span><a href="board.php?b=<?= htmlspecialchars($b['slug']) ?>">/<?= htmlspecialchars($b['name']) ?></a></span>
                <span class ="desc">- <?= htmlspecialchars($b['descript']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="trending-block" id="trending-block">
            <h2>Trending</h2>
            <div class="trending-list" id="trending-list">
                <div class="trending-empty" id="trending-empty">Loading...</div>
            </div>
        </div>

        <div class="stats-block" id="stats-block">
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-val" id="stat-threads">—</span>
                    <span class="stat-label">threads</span>
                </div>
                <div class="stat-item">
                    <span class="stat-val" id="stat-posts">—</span>
                    <span class="stat-label">posts</span>
                </div>
                <div class="stat-item">
                    <span class="stat-val" id="stat-boards">—</span>
                    <span class="stat-label">boards</span>
                </div>
                <div class="stat-item">
                    <span class="stat-val" id="stat-today">—</span>
                    <span class="stat-label">posts today</span>
                </div>
                <div class="stat-item">
                    <span class="stat-val" id="stat-hour">—</span>
                    <span class="stat-label">posts this hour</span>
                </div>
                <div class="stat-item">
                    <span class="stat-val" id="stat-posters">—</span>
                    <span class="stat-label">active posters</span>
                </div>
            </div>
            <div class="stats-footer">
                <span id="stats-updated" style="color:var(--grey);font-size:10px;">
                    Loading...
                </span>
                <span class="stats-dot" id="stats-dot" title="Live">●</span>
            </div>
        </div>
    </div>

    <footer>@6chan v0.6.1 - aGPL v3.0 - All boards: <a href="index.php">Home</a> | <a href="settings/settings.php">Settings</a> | <a href="contact.php">[Contact]</a> <a href="etc/license.html">[License]</a> <a href="support.html">[Support]</a> <a href="news/news.php">[News]</a> <a href="feed.php">[RSS]</a> | <a href="rfc/index.php">[::RFC]</a></footer>
    
    <script>
    (function() {
        const fields = {
            threads: 'stat-threads',
            posts:   'stat-posts',
            boards:  'stat-boards',
            posts_today:      'stat-today',
            posts_this_hour:  'stat-hour',
            active_posters:   'stat-posters',
        };
    
        function fmt(n) {
            if (n >= 1000000) return (n/1000000).toFixed(1) + 'M';
            if (n >= 1000)    return (n/1000).toFixed(1)    + 'K';
            return String(n);
        }
    
        async function loadStats() {
            try {
                const res  = await fetch('services/stats.php?_=' + Date.now());
                const data = await res.json();
                const dot  = document.getElementById('stats-dot');
                const upd  = document.getElementById('stats-updated');
    
                for (const [key, elId] of Object.entries(fields)) {
                    const el  = document.getElementById(elId);
                    if (!el) continue;
                    const val = fmt(data[key] ?? 0);
                    if (el.textContent !== val && el.textContent !== '—') {
                        el.classList.add('bump');
                        setTimeout(() => el.classList.remove('bump'), 350);
                    }
                    el.textContent = val;
                }
    
                dot.className  = 'stats-dot';
                upd.textContent = 'Updated at ' + new Date().toLocaleTimeString();
            } catch(e) {
                document.getElementById('stats-dot').className = 'stats-dot stale';
                document.getElementById('stats-updated').textContent = 'update failed';
            }
        }
    
        loadStats();
        setInterval(loadStats, 30000);
    })();

    (function() {
        const list  = document.getElementById('trending-list');
        const empty = document.getElementById('trending-empty');

        function esc(s) {
            const d = document.createElement('div');
            d.textContent = s ?? '';
            return d.innerHTML;
        }

        async function loadTrending() {
            try {
                const res  = await fetch('services/recommend.php?limit=8&_=' + Date.now());
                const data = await res.json();

                if (!Array.isArray(data) || data.length === 0) {
                    empty.textContent = 'Nothing trending yet.';
                    return;
                }

                list.innerHTML = data.map(t => `
                    <div class="trending-item${t.nsfw ? ' nsfw' : ''}">
                        <a href="board.php?b=${esc(t.board_slug)}#p${t.id}" class="trending-title">
                            /${esc(t.board_slug)}/ ${t.subject ? esc(t.subject) : esc(t.snippet)}
                        </a>
                        <span class="trending-meta">
                            ${t.reply_count} repl${t.reply_count === 1 ? 'y' : 'ies'} ·
                            ${t.reaction_count} reaction${t.reaction_count === 1 ? '' : 's'}
                            ${t.nsfw ? ' · <span class="nsfw-tag">NSFW</span>' : ''}
                        </span>
                    </div>
                `).join('');
            } catch (e) {
                empty.textContent = 'Could not load trending threads.';
            }
        }

        loadTrending();
        setInterval(loadTrending, 60000);
    })();
    </script>
</body>
</html>
