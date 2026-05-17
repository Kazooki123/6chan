<?php
require_once '../config/db.php';
$db = get_db();

$type      = $_GET['type'] ?? '';
$post_id   = (int)($_GET['id'] ?? 0);
$thread_id = (int)($_GET['tid'] ?? 0);
$slug      = $_GET['b'] ?? '';

if (!in_array($type, ['thread', 'post']) || $post_id <= 0) {
    http_response_code(400);
    die('Invalid report target.');
}

$submitted = false;
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $select = trim($_POST['reason'] ?? '');
    $extra  = trim($_POST['extra'] ?? '');
    $reason = $extra !== '' ? "$select - $extra" : $select;
    $ip     = $_SERVER['REMOTE_ADDR'];

    if ($reason === '') {
        $error = 'Please provide a reason.';
    } elseif (mb_strlen($reason) > 200) {
        $error = 'Reason too long (max 200 chars).';
    } else {
        // Anti-spam so skids can't do shit
        // Max 3 reports per IP per hour also
        $spam = $db->prepare(
            "SELECT COUNT(*) FROM reports
             WHERE ip = ? AND created_at > NOW() - INTERVAL '1 hour'"
        );
        $spam->execute([$ip]);
        if ((int)$spam->fetchColumn() >= 3) {
            $error = 'You are reporting too fast.. Please wait before reporting again!';
        } else {
            $db->prepare(
                "INSERT INTO reports (post_type, post_id, thread_id, board_slug, reason, ip)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $type,
                $post_id,
                $thread_id ?: null,
                $slug ?: null,
                $reason,
                $ip
            ]);
            $submitted = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Report — @6chan</title>
<link rel="icon" type="image/x-icon" href="assets/favicon.ico">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div id="topbar">
    <span class="site-name">@6chan</span>
    <a href="index.php">Home</a>
    <a href="rules.php">[Rules]</a>
</div>

<div class="wrap">
    <div class="page-header">
        <h1>Report <?= $type === 'thread' ? 'Thread' : 'Post' ?> #<?= $post_id ?></h1>
        <p>Reports are reviewed by the mod team. Abuse of the report system may result in a ban.</p>
    </div>

    <?php if ($submitted): ?>
    <div class="rules-block">
        <p style="color:#6f6;">🟢 Report submitted. The mod team will review it shortly.</p>
        <p style="margin-top:8px;">
            <?php if ($thread_id): ?>
                <a href="thread.php?id=<?= $thread_id ?>">[Back to thread]</a>
            <?php else: ?>
                <a href="index.php">[Back to home]</a>
            <?php endif; ?>
        </p>
    </div>

    <?php else: ?>
    <div class="postform">
        <?php if ($error): ?>
            <div style="color:#ff4444;font-size:12px;margin-bottom:8px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <h3>Why are you reporting this <?= $type ?>?</h3>
        <form method="post">
            <table>
                <tr>
                    <td>Reason</td>
                    <td>
                        <select name="reason" style="background:#111;border:1px solid var(--border);
                               color:var(--white);font:13px 'MapleMono',monospace;padding:4px 6px;width:100%;">
                            <option value="">— Select a reason —</option>
                            <option>Spam or flooding</option>
                            <option>Illegal content</option>
                            <option>Doxxing / personal information</option>
                            <option>Harassment or threats</option>
                            <option>Off-topic / wrong board</option>
                            <option>Other</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Details</td>
                    <td><textarea type="text" name="extra" placeholder="Optional extra detail..." maxlength="200"
                                  style="min-height:60px;"></textarea></td>
                </tr>
                <tr><td></td><td><button type="submit">Submit Report</button></td></tr>
            </table>
        </form>
    </div>
    <?php endif; ?>
</div>

<footer>@6chan — <a href="index.php">Home</a></footer>
</body>
</html>
