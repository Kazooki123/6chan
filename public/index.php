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
    <title>6chan - Home</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="stylesheet" href="style.css"/>
    <script src="settingsloader.js"></script>
    <script src="shortcuts.js"></script>
</head>
<body>
    <div id="topbar">
        <span class="site-name">@6chan</span>
        <a href="index.php">Home</a>
        <a href="rules.php">[Rules]</a>
        <a href="search.php">[Search]</a>
        <a href="contact.php">[Contact]</a>
        <a href="support.php">[Support]</a>
        <a href="feed.php">[RSS]</a>
        <a href="https://codeberg.org/Kazooki123/6chan">[⭐ Source]</a>
    </div>

    <div class="wrap">
        <div class="welcome-block">
            <div class="welcome-title">Welcome to 6chan !!</div>
            <p>
                A simple, no-frills imageboard. Pick a board, start a thread, keep it real.
                This is a community built on honest and thoughtful discussion - read the rules, respect the boards, and don't be an IDIOT.
            </p>
            <div class="welcome-links">
                <a href="rules.php">[rules]</a>
                <a href="board.php?b=r">[/r/ - random]</a>
                <a href="board.php?b=t">[/t/ - tech]</a>
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
    <footer>@6chan v0.5 - aGPL v3.0 - All boards: <a href="index.php">Home</a> | <a href="settings/settings.php">Settings</a> | <a href="contact.php">[Contact]</a> <a href="etc/license.html">[License]</a> <a href="support.php">[Support]</a> <a href="etc/news.php">[News]</a> <a href="feed.php">[RSS]</a> </footer>
    
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
                        // Bump animation when value changes
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
        // Reloads every 30 seconds
        setInterval(loadStats, 30000);
    })();
    </script>
</body>
</html>
