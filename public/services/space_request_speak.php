<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/space.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') space_error('POST only', 405);
$db  = get_db();
$sid = $_POST['sid'] ?? '';
if (!space_valid_session($sid)) space_error('Missing or malformed session id');
$space_id = (int)($_POST['space_id'] ?? 0);
space_require_live($db, $space_id);
$me = space_require_participant($db, $space_id, $sid);
if ($me['role'] !== 'listener') space_error('Only listeners can raise a hand');

$db->prepare(
    "INSERT INTO space_speaker_requests (space_id, session_id, author, tripcode)
     VALUES (?, ?, ?, ?)
     ON CONFLICT (space_id, session_id) DO UPDATE SET status = 'pending'"
)->execute([$space_id, $sid, $me['author'], $me['tripcode']]);

$db->prepare("INSERT INTO space_signals (space_id, from_session, type, payload) VALUES (?, ?, 'hand_raise', ?)")->execute([$space_id, $sid, json_encode(['author' => $me['author']])]);
space_notify($db, $space_id);
space_json(['ok' => true]);