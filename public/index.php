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
</head>
<body>
    <div id="topbar">
        <span class="site-name">@6chan</span>
        <a href="index.php">Home</a>
        <a href="rules.php">[Rules]</a>
        <!--
        <?php foreach ($boards as $b): ?>
            <a href="board.php?b=<?= htmlspecialchars($b['slug']) ?>">/<?= htmlspecialchars($b['slug']) ?>/</a>
        <?php endforeach; ?>
        -->
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
        <div>
    </div>
    <footer>@6chan v0.4 - MIT - All boards: <a href="index.php">Home</a> | <a href="settings/settings.php">Settings</a> | <a href="contact.php">[Contact]</a> <a href="etc/license.html">[License]</a> <a href="support.php">[Support]</a> <a href="etc/news.php">[News]</a> <a href="feed.php">[RSS]</a> </footer>
</body>
</html>
