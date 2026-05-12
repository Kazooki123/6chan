<?php
require_once '../config/db.php';
$db = get_db();
$boards = $db->query("SELECT slug, name, descript FROM boards ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>6chan - boards</title>
    <link rel="stylesheet" href="style.css"/>
</head>
<body>
    <div id="topbar">
        <span class="site-name">@6chan</span>
        <a href="index.php">Home</a>
        <?php foreach ($boards as $b): ?>
            <a href="board.php?b=<?= htmlspecialchars($b['slug']) ?>">/<?= htmlspecialchars($b['slug']) ?>/</a>
        <?php endforeach; ?>
    </div>

    <div class="wrap">
        <div class="welcome-block">
            <div class="welcome-title">Welcome to @6chan</div>
            <p>
                A simple, no-frills imageboard. Pick a board, start a thread, keep it real.
                This is a community built on honest and thoughtful discussion - read the rules, respect the boards, and don't be an IDIOT.
            </p>
            <div class="welcome-links">
                <a href="rules.php">[Rules]</a>
                <a href="board.php?b=r">[/r/ - random]</a>
                <a href="rules.php?b=t">[/t/ - tech]</a>
            </div>
        </div>
        
        <div class="board-list">
            <h2>Boards!</h2>
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
    <footer>@6chan v0.01 - aGPL v3.0 - all boards: <a href="index.php">Home</a></footer>
</body>
</html>
