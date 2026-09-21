<?php
session_start();
if ($_SESSION['admin'] ?? false) {
    header('Location: panel.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../../config/db.php';
    $db = get_db();
    $u  = trim($_POST['username'] ?? '');
    $p  = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$u]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($p, $admin['pwd_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin']    = true;
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_user'] = $admin['username'];
        header('Location: panel.php'); exit;
    }
    $error = 'Invalid credentials.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Login - @6chan</title>
<link rel="icon" type="image/x-icon" href="../assets/favicon.ico">
<link rel="stylesheet" href="../style.css">
<style>
.login-box {
  max-width: 320px; margin: 80px auto;
  background: var(--surface);
  border: 1px solid var(--orange);
  padding: 24px 28px;
}
.login-box h2 { color: var(--orange); margin-bottom: 16px; font-size: 15px; }
.login-box label { font-size: 12px; color: var(--grey); display: block; margin-bottom: 3px; }
.login-box input[type=text],
.login-box input[type=password] {
  width: 100%; background: #111; border: 1px solid var(--border);
  color: var(--white); font: 13px 'MapleMono', monospace;
  padding: 5px 8px; margin-bottom: 10px;
}
.login-box button {
  width: 100%; background: var(--orange); border: none; color: #000;
  font: bold 13px 'MapleMono', monospace; padding: 7px; cursor: pointer;
}
.login-box button:hover { background: var(--orange2); }
.error { color: #ff4444; font-size: 12px; margin-bottom: 8px; }
</style>
</head>
<body>
<div id="topbar"><span class="site-name">6chan Admin</span> <a href="../index.php">Home</a></div>
<div class="login-box">
  <h2>⬡ Admin Login</h2>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <label>Username</label>
    <input type="text" name="username" autocomplete="username" required>
    <label>Password</label>
    <input type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Login</button>
  </form>
</div>
</body>
</html>
