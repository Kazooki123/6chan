<?php require_once '../config/db.php'; $db = get_db();
$boards = $db->query("SELECT slug, name FROM boards ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Rules — @6chan</title>
<link rel="icon" type="image/x-icon" href="assets/favicon.ico">
<link rel="stylesheet" href="style.css">
<script src="settingsloader.js"></script></head>
<body>
<div id="topbar">
  <span class="site-name">@6chan</span>
  <a href="index.php">Home</a>
  <!--
  <?php foreach ($boards as $b): ?>
    <a href="board.php?b=<?= htmlspecialchars($b['slug']) ?>">/<?= htmlspecialchars($b['slug']) ?>/</a>
  <?php endforeach; ?>
  -->
  <a href="contact.php">[Contact]</a>
  <a href="support.php">[Support]</a>
  <a href="etc/tsnclf.html">[Fallacies]</a>
</div>

<div class="wrap">
  <div class="page-header">
    <h1>Global Rules</h1>
    <p>Read before posting. Ignorance is not an excuse.</p>
  </div>

  <div class="rules-block">
    <ol>
      <li>Do not post, request, or link to ANY content that is <b style="color:var(--red);">illegal</b> in your jurisdiction.</li>
      <li>No spam, flooding, or repeated <b>low-effort posts.</b></li>
      <li>Stay on topic for the board you are posting in.</li>
      <li>No doxxing — do not share personal information of others without consent.</li>
      <li>Treat other users with basic respect. Targeted harassment is not allowed.</li>
      <li>NSFW content must be clearly marked and is only permitted on designated boards.</li>
      <li>Do not impersonate moderators, administrators, or other users.</li>
      <li>The moderation team has final say. Decisions may be appealed politely via the meta board.</li>
    </ol>

    <h2>Board-Specific Rules</h2>
    <p>Each board may have additional rules posted at the top of that board. Always read them.</p>

    <h2>Consequences</h2>
    <p>Violations may result in post deletion, a temporary ban, or a permanent ban depending on severity.
       Ban evasion results in an immediate permanent ban.</p>
  </div>
</div>
<footer>@6chan — <a href="index.php">home</a> — <a href="rules.php">Rules</a> <a href="contact.php">[Contact]</a>
 <a href="support.php">[Support]</a></footer>
</body>
</html>
