<?php
require_once '../config/db.php';
$db = get_db();
$boards = $db->query("SELECT slug, name FROM boards ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staffs — @6chan</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div id="topbar">
        <span class="site-name">@6chan</span>
        <a href="index.php">Home</a>
        <a href="rules.php">[Rules]</a>
        <a href="contact.php">[Contact]</a>
        <a href="support.php">[Support]</a>
    </div>
    <div class="wrap">
        <div class="page-header">
            <h1>Staffs of 6chan</h1>
            <p>They're the key reasons why this Community is in good shape!</p>
        </div>
    </div>
</body>
</html>
