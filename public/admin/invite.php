<?php
session_start();
require_once '../../config/ircdb.php';
require_once '../../config/staff_auth.php';

$chat_db = get_chat_db();
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

// =================================================================
// Redeem - called by `yukiko redeem <code>`, NOT an admin session.
// This is precisely how a brand-new staffer bootstraps a token
// without ever having logged into the web panel from that machine.
// =================================================================
if ($action === 'redeem') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST only']);
        exit;
    }

    $code     = trim($_POST['code'] ?? '');
    $username = trim($_POST['username'] ?? '');

    if ($code === '' || $username === '') {
        http_response_code(400);
        echo json_encode(['error' => 'code and username are required']);
        exit;
    }

    $token = redeem_staff_invite($chat_db, $code, $username);
    if ($token === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid, expired, or already-used invite code']);
        exit;
    }

    echo json_encode([
        'ok'       => true,
        'username' => $username,
        'token'    => $token,
    ]);
    exit;
}

if (!($_SESSION['admin'] ?? false)) {
    header('Location: login.php');
    exit;
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    create_staff_invite($chat_db, $_SESSION['admin_user'] ?? 'admin');
}
if ($action === 'revoke' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    revoke_staff_token($chat_db, (int)($_POST['token_id'] ?? 0));
}

$invites = list_staff_invites($chat_db);
$tokens  = list_staff_tokens($chat_db);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Staff Invites — 6chan</title>
<link rel="icon" type="image/x-icon" href="../assets/favicon.ico">
<link rel="stylesheet" href="../style.css">
<style>
    body { background:#1a1a1a; color:#ddd; font-family: monospace; padding: 2rem; }
    h1, h2 { color: white; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 2rem; }
    th, td { border: 1px solid #444; padding: 0.4rem 0.6rem; text-align: left; }
    code { background:#000; padding: 0.15rem 0.4rem; }
    .redeemed { color: #666; }
    .expired { color: #a55; }
    button { cursor: pointer; }
</style>
</head>
<body>
    <p>Logged in as <strong style="color:white;"><?= htmlspecialchars($_SESSION['admin_user']) ?></strong></p>
    <h1>Staff Invites</h1>

    <form method="post" action="?action=create">
        <button type="submit">Generate new invite (expires in 24h)</button>
    </form>

    <h2>Pending / recent invites</h2>
    <table>
        <tr><th>Code</th><th>Created by</th><th>Expires</th><th>Status</th></tr>
        <?php foreach ($invites as $inv): ?>
        <tr>
            <td><code><?= htmlspecialchars($inv['code']) ?></code></td>
            <td><?= htmlspecialchars($inv['created_by'] ?? '') ?></td>
            <td><?= htmlspecialchars($inv['expires_at']) ?></td>
            <td>
                <?php if ($inv['redeemed_at']): ?>
                    <span class="redeemed">redeemed <?= htmlspecialchars($inv['redeemed_at']) ?></span>
                <?php elseif ($inv['expires_at'] < gmdate('Y-m-d\TH:i:s\Z')): ?>
                    <span class="expired">expired</span>
                <?php else: ?>
                    give this to the new staffer:
                    <code>yukiko redeem <?= htmlspecialchars($inv['code']) ?> --username &lt;name&gt;</code>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h2>Active tokens</h2>
    <table>
        <tr><th>Username</th><th>Created</th><th>Status</th><th></th></tr>
        <?php foreach ($tokens as $tok): ?>
        <tr>
            <td><?= htmlspecialchars($tok['username']) ?></td>
            <td><?= htmlspecialchars($tok['created_at']) ?></td>
            <td><?= $tok['revoked'] ? 'revoked' : 'active' ?></td>
            <td>
                <?php if (!$tok['revoked']): ?>
                <form method="post" action="?action=revoke" style="display:inline;">
                    <input type="hidden" name="token_id" value="<?= (int)$tok['id'] ?>">
                    <button type="submit">Revoke</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
