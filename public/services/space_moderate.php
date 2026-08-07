<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/space.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') space_error('POST only', 405);
$db  = get_db();
$sid = $_POST['sid'] ?? '';
if (!space_valid_session($sid)) space_error('Missing or malformed session id');

$space_id = (int)($_POST['space_id'] ?? 0);
$action   = $_POST['action'] ?? '';
$target   = $_POST['target'] ?? null;

space_require_live($db, $space_id);
space_require_host($db, $space_id, $sid);
if ($target !== null && !space_valid_session($target)) space_error('Malformed target session');

switch ($action) {
    case 'approve_speaker':
        $db->prepare("UPDATE space_speaker_requests SET status='approved' WHERE space_id=? AND session_id=?")
           ->execute([$space_id, $target]);
        $db->prepare("UPDATE space_participants SET role='speaker' WHERE space_id=? AND session_id=?")
           ->execute([$space_id, $target]);
        $type = 'promote'; break;

    case 'deny_speaker':
        $db->prepare("UPDATE space_speaker_requests SET status='denied' WHERE space_id=? AND session_id=?")
           ->execute([$space_id, $target]);
        $type = 'deny_speak'; break;

    case 'mute':
    case 'unmute':
        $db->prepare("UPDATE space_participants SET muted=? WHERE space_id=? AND session_id=?")
           ->execute([$action === 'mute' ? 1 : 0, $space_id, $target]);
        $type = $action; break;

    case 'demote':
        $db->prepare("UPDATE space_participants SET role='listener' WHERE space_id=? AND session_id=?")
           ->execute([$space_id, $target]);
        $type = 'demote'; break;

    case 'end':
        $db->prepare("UPDATE spaces SET status='ended', ended_at=NOW() WHERE id=?")->execute([$space_id]);
        $type = 'ended'; break;

    default: space_error('Unknown action');
}

$db->prepare(
    "INSERT INTO space_signals (space_id, from_session, to_session, type, payload)
     VALUES (?, ?, ?, ?, ?)"
)->execute([$space_id, $sid, $target, $type, json_encode(['target' => $target])]);
space_notify($db, $space_id);
space_json(['ok' => true]);