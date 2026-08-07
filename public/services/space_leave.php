<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/space.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') space_error('POST only', 405);
$db  = get_db();
$sid = $_POST['sid'] ?? '';
if (!space_valid_session($sid)) space_error('Missing or malformed session id');
$space_id = (int)($_POST['space_id'] ?? 0);
space_require_participant($db, $space_id, $sid);

$db->prepare("UPDATE space_participants SET left_at = NOW() WHERE space_id = ? AND session_id = ?")->execute([$space_id, $sid]);
$db->prepare("INSERT INTO space_signals (space_id, from_session, type, payload) VALUES (?, ?, 'leave', '{}')")->execute([$space_id, $sid]);

/**if ($me['role'] === 'host') {
    $db->prepare("UPDATE spaces SET status = 'ended', ended_at = NOW() WHERE id = ?")->execute([$space_id]);
    $db->prepare("INSERT INTO space_signals (space_id, from_session, type, payload) VALUES (?, ?, 'ended', '{}')")
       ->execute([$space_id, $sid]);
} else {
    $db->prepare("INSERT INTO space_signals (space_id, from_session, type, payload) VALUES (?, ?, 'leave', '{}')")
       ->execute([$space_id, $sid]);
}*/

space_notify($db, $space_id);
space_json(['ok' => true]);
