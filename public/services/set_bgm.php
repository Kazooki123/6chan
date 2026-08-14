<?php
session_start();
require_once '../../config/db.php';
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$thread_id = (int)($_POST['thread_id'] ?? 0);
$post_id   = (int)($_POST['post_id']   ?? 0);
$action    = $_POST['action'] ?? '';

session_start();
if (!($_SESSION['admin'] ?? false)) {
    http_response_code(403);
    die('Admin only for now!');
}

if ($action === 'set') {
    $check = $db->prepare("SELECT id FROM posts WHERE id=? AND thread_id=?");
    $check->execute([$post_id, $thread_id]);
    if (!$check->fetch()) die('Post not in thread!');

    $db->prepare("UPDATE threads SET bgm_post_id=? WHERE id=?")
       ->execute([$post_id, $thread_id]);
} elseif ($action === 'unset') {
    $db->prepare("UPDATE threads SET bgm_post_id=NULL WHERE id=?")
       ->execute([$thread_id]);
}

header("Location: ../thread.php?id=$thread_id");
exit;
