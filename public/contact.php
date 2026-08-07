<?php
$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$name || !$email || !$subject || !$message) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        // NOTE: Temporary for now, just a placeholder, 6chan will have its own email one day ^0^
        $to      = 'admin@6chan.local';
        $headers = "From: $name <$email>\r\nReply-To: $email\r\n";
        $body    = "Name: $name\nEmail: $email\nSubject: $subject\n\n$message";
        mail($to, "[6chan Contact] $subject", $body, $headers);
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Contact — @6chan</title>
<link rel="icon" type="image/x-icon" href="assets/favicon.ico">
<link rel="stylesheet" href="style.css">
<script src="settingsloader.js"></script></head>
<body>
<div id="topbar">
    <span class="site-name">@6chan</span>
    <a href="index.php">Home</a>
    <a href="rules.html">[Rules]</a>
    <a href="support.html">[Support]</a>
</div>

<div class="wrap">
    <div class="page-header">
        <h1>Contact</h1>
        <p>Get in touch with the @6chan team.</p>
    </div>

    <div class="page-header">
        <h1>Other Contacts (Owner)</h1>
        <p>
            <b style="color:var(--link);">Gmail:</b> <p>mgamerdinge146@gmail.com</p><br>
            <b style="color:var(--pink);">Bluesky:</b> <p>starloexoliz123.bsky.social</p><br>
            <b style="color:var(--accent);">Discord:</b> <p>starloexoliz11</p><br>
        </p>
    </div>

    <?php if ($sent): ?>
    <div class="rules-block">
        <p style="color:#6f6;">✔ Message sent. We'll get back to you at the email you provided.</p>
    </div>

    <?php else: ?>
    <div class="rules-block" style="margin-bottom:14px;">
        <h2>Before you write</h2>
        <p>
            For ban appeals, include your IP and the board.<br>
            For bug reports, describe the steps to reproduce.<br>
            For general inquiries, we'll try to respond within a few days.
        </p>
    </div>

    <div class="postform">
        <?php if ($error): ?>
            <div style="color:#ff4444;font-size:12px;margin-bottom:8px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <table>
                <tr>
                    <td>Name</td>
                    <td><input type="text" name="name" maxlength="100"
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"></td>
                </tr>
                <tr>
                    <td>Email</td>
                    <td><input type="text" name="email" maxlength="200"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"></td>
                </tr>
                <tr>
                    <td>Subject</td>
                    <td><input type="text" name="subject" maxlength="200"
                               value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"></td>
                </tr>
                <tr>
                    <td>Message</td>
                    <td><textarea name="message" required
                                  style="min-height:120px;"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea></td>
                </tr>
                <tr><td></td><td><button type="submit">Send</button></td></tr>
            </table>
        </form>
    </div>
    <?php endif; ?>
</div>

<footer>@6chan — <a href="index.php">Home</a> — <a href="support.html">Support</a></footer>
</body>
</html>
