<?php 
require_once '../config/db.php';
$db = get_db();
$boards = $db->query("SELECT slug, name FROM boards ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support — @6chan</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="stylesheet" href="style.css">
    <script src="settingsloader.js"></script>
</head>
<body>
<div id="topbar">
    <span class="site-name">@6chan</span>
    <a href="index.php">Home</a>
    <a href="rules.php">[Rules]</a>
    <a href="contact.php">[Contact]</a>
</div>

<div class="wrap">
    <div class="page-header">
        <h1>Support @6chan</h1>
        <p>Keep the lights on. Every bit helps.</p>
    </div>

    <div class="rules-block">
        <h2>Why donate?</h2>
        <p>
            6chan runs on community support. Server costs, domain fees, and development
            time are all self-funded. If you enjoy the site and want to see it stay up
            and grow, consider throwing a few dollars our way.
        </p>

        <h2>What your support covers</h2>
        <ul style="padding-left:20px;line-height:2;">
            <li>Server hosting &amp; bandwidth</li>
            <li>Domain renewal</li>
            <li>Storage for uploads</li>
            <li>Future features &amp; improvements</li>
        </ul>

        <h2>How to donate</h2>
        <p>
            <!-- Replace these with your actual links -->
            <a href="https://ko-fi.com/" target="_blank">[☕ Ko-fi]</a>
            &nbsp;
            <a href="https://buymeacoffee.com/" target="_blank">[Buy Me a Coffee]</a>
            &nbsp;
            <a href="https://paypal.me/" target="_blank">[PayPal]</a>
            &nbsp;
            <a href="https://" target="_blank">[GCash]</a>
        </p>

        <h2>Crypto</h2>
        <p style="font-size:12px;color:var(--grey);">
            BTC: <code style="color:var(--orange);">[...]</code><br>
            ETH: <code style="color:var(--orange);">[...]</code>
        </p>

        <h2>Other ways to help</h2>
        <p style="color:var(--pink);">
            Share 6chan with friends. Report rule-breaking posts. Contribute to discussion.
            A healthy community is the best form of support! (*^▽^*)
        </p>
    </div>
</div>

<footer>@6chan — <a href="index.php">Home</a> — <a href="contact.php">Contact</a></footer>
</body>
</html>
