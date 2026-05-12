<?php
session_start();
if (!($_SESSION['admin'] ?? false)) { header('Location: login.php'); exit; }
require_once '../../config/db.php';
$db = get_db();


$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'delete_post') {
        $db->prepare("DELETE FROM posts WHERE id=?")->execute([(int)$_POST['pid']]);
        $msg = 'Post deleted.';
    } elseif ($act === 'delete_thread') {
        $db->prepare("DELETE FROM threads WHERE id=?")->execute([(int)$_POST['tid']]);
        $msg = 'Thread deleted.';
    } elseif ($act === 'ban_ip') {
        $ip  = trim($_POST['ip']);
        $rsn = trim($_POST['reason'] ?? '');
        $exp = $_POST['expires'] ? $_POST['expires'] : null;
        $db->prepare("INSERT INTO bans (ip, reason, expires_at) VALUES (?,?,?)")
           ->execute([$ip, $rsn, $exp]);
        $msg = "Banned $ip.";
    } elseif ($act === 'unban') {
        $db->prepare("DELETE FROM bans WHERE id=?")->execute([(int)$_POST['bid']]);
        $msg = 'Ban lifted.';
    }
}


$threads = $db->query(
  "SELECT t.id, t.subject, t.body, t.created_at, b.slug
   FROM threads t JOIN boards b ON b.id=t.board_id
   ORDER BY t.created_at DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);


$posts = $db->query(
  "SELECT p.id, p.body, p.created_at, p.thread_id
   FROM posts p ORDER BY p.created_at DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);


$bans = $db->query("SELECT * FROM bans ORDER BY banned_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Admin Panel — @6chan</title>
<link rel="stylesheet" href="../style.css">
<style>
.admin-nav { display:flex; gap:12px; margin-bottom:14px; font-size:13px; }
.admin-nav a { color:var(--orange); }
.admin-section { margin-bottom:24px; }
.admin-section h2 {
  color:var(--orange); font-size:13px; text-transform:uppercase;
  letter-spacing:1px; border-bottom:1px solid var(--border); padding-bottom:4px; margin-bottom:8px;
}
.admin-table { width:100%; border-collapse:collapse; font-size:12px; }
.admin-table th { background:var(--surface); color:var(--grey); padding:5px 8px; text-align:left; border:1px solid var(--border); }
.admin-table td { padding:5px 8px; border:1px solid var(--border); vertical-align:top; }
.admin-table tr:hover td { background:#1f1f1f; }
.del-btn {
  background:none; border:1px solid #aa2222; color:#ff4444;
  font:11px 'Courier New',monospace; padding:2px 7px; cursor:pointer;
}
.del-btn:hover { background:#aa2222; color:#fff; }
.ban-form input[type=text], .ban-form input[type=datetime-local] {
  background:#111; border:1px solid var(--border); color:var(--white);
  font:12px 'Courier New',monospace; padding:3px 6px; width:100%;
}
.ban-form button {
  background:var(--orange); border:none; color:#000;
  font:bold 12px 'Courier New',monospace; padding:4px 12px; cursor:pointer; margin-top:4px;
}
.msg { background:#1a3a1a; border:1px solid #3a6a3a; color:#6f6; padding:6px 12px; margin-bottom:12px; font-size:12px; }
.logout { float:right; font-size:12px; color:var(--grey); }
.logout a { color:#ff4444; }
</style>
</head>
<body>
<div id="topbar">
  <span class="site-name">@6chan</span>
  <a href="../index.php">Home</a>
  <span style="margin-left:auto;font-size:12px;color:#ccc;">
    Logged in as <strong style="color:white;"><?= htmlspecialchars($_SESSION['admin_user']) ?></strong>
    — <a href="logout.php" style="color:#ff4444;">[Logout]</a>
  </span>
</div>

<div class="wrap">
  <div class="page-header">
    <h1>Admin Panel</h1>
    <p>Moderate posts, threads, and bans.</p>
  </div>

  <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="admin-nav">
    <a href="#threads">[Threads]</a>
    <a href="#posts">[Posts]</a>
    <a href="#bans">[Bans]</a>
    <a href="#newban">[New Ban]</a>
  </div>

  <div class="admin-section" id="threads">
    <h2>Recent Threads</h2>
    <table class="admin-table">
      <tr><th>ID</th><th>Board</th><th>Subject</th><th>Preview</th><th>Date</th><th>Action</th><th>IP</th></tr>
      <?php foreach ($threads as $t): ?>
      <tr>
        <td><a href="../thread.php?id=<?= $t['id'] ?>"><?= $t['id'] ?></a></td>
        <td>/<?= htmlspecialchars($t['slug']) ?>/</td>
        <td><?= htmlspecialchars(mb_substr($t['subject'] ?? '—', 0, 30)) ?></td>
        <td><?= htmlspecialchars(mb_substr($t['body'], 0, 60)) ?>…</td>
        <td><?= date('m/d H:i', strtotime($t['created_at'])) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete entire thread?')">
            <input type="hidden" name="act" value="delete_thread">
            <input type="hidden" name="tid" value="<?= $t['id'] ?>">
            <button class="del-btn" type="submit">Delete</button>
          </form>
        </td>
        <td>
            <span class="ip-tag"><?= htmlspecialchars($t['ip'] ?? '-') ?></span>
            <?php if (!empty($t['ip'])): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="act"     value="ban_ip">
                <input type="hidden" name="ip"      value="<?= htmlspecialchars($t['ip']) ?>">
                <input type="hidden" name="reason"  value="Thread deletion">
                <input type="hidden" name="expires" value="">
                <button class="del-btn" style="border-color:#e67e00;color:#e67e00;"
                    onclick="return confirm('Permaban <?= htmlspecialchars($t['ip']) ?>?')">Ban</button>
            </form>
            <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="admin-section" id="posts">
    <h2>Recent Posts</h2>
    <table class="admin-table">
      <tr><th>ID</th><th>Thread</th><th>Preview</th><th>Date</th><th>Action</th><th>IP</th></tr>
      <?php foreach ($posts as $p): ?>
      <tr>
        <td><?= $p['id'] ?></td>
        <td><a href="../thread.php?id=<?= $p['thread_id'] ?>">#<?= $p['thread_id'] ?></a></td>
        <td><?= htmlspecialchars(mb_substr($p['body'], 0, 80)) ?>…</td>
        <td><?= date('m/d H:i', strtotime($p['created_at'])) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete post?')">
            <input type="hidden" name="act" value="delete_post">
            <input type="hidden" name="pid" value="<?= $p['id'] ?>">
            <button class="del-btn" type="submit">Delete</button>
          </form>
        </td>
        <td>
            <span class="ip-tag"><?= htmlspecialchars($p['ip'] ?? '-') ?></span>
            <?php if (!empty($p['ip'])): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="act"     value="ban_ip">
                <input type="hidden" name="ip"      value="<?= htmlspecialchars($p['ip']) ?>">
                <input type="hidden" name="reason"  value="Posts deletion">
                <input type="hidden" name="expires" value="">
                <button class="del-btn" style="border-color:#e67e00;color:#e67e00;"
                    onclick="return confirm('Permaban <?= htmlspecialchars($p['ip']) ?>?')">Ban</button>
            </form>
            <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="admin-section" id="bans">
    <h2>Active Bans</h2>
    <?php if (!$bans): ?>
      <p style="color:var(--grey);font-size:13px;">No active bans.</p>
    <?php else: ?>
    <table class="admin-table">
      <tr><th>ID</th><th>IP</th><th>Reason</th><th>Banned</th><th>Expires</th><th>Action</th></tr>
      <?php foreach ($bans as $ban): ?>
      <tr>
        <td><?= $ban['id'] ?></td>
        <td><?= htmlspecialchars($ban['ip']) ?></td>
        <td><?= htmlspecialchars($ban['reason'] ?? '—') ?></td>
        <td><?= date('m/d/Y H:i', strtotime($ban['banned_at'])) ?></td>
        <td><?= $ban['expires_at'] ? date('m/d/Y H:i', strtotime($ban['expires_at'])) : '♾ Permanent' ?></td>
        <td>
          <form method="post">
            <input type="hidden" name="act" value="unban">
            <input type="hidden" name="bid" value="<?= $ban['id'] ?>">
            <button class="del-btn" type="submit" style="border-color:#22aa22;color:#4f4;">Lift</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="admin-section" id="newban">
    <h2>New Ban</h2>
    <form method="post" class="ban-form" style="max-width:400px;display:grid;gap:6px;">
      <input type="hidden" name="act" value="ban_ip">
      <label style="font-size:12px;color:var(--grey);">IP Address</label>
      <input type="text" name="ip" placeholder="e.g. 192.168.1.1" required>
      <label style="font-size:12px;color:var(--grey);">Reason</label>
      <input type="text" name="reason" placeholder="Reason for ban">
      <label style="font-size:12px;color:var(--grey);">Expires (leave blank = permanent)</label>
      <input type="datetime-local" name="expires">
      <button type="submit">Issue Ban</button>
    </form>
  </div>
</div>

<footer>@6chan admin — <a href="../index.php">Back to Site</a></footer>
</body>
</html>
