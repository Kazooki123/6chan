<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/space.php';
require_once __DIR__ . '/../../config/tripcode.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') space_error('POST only', 405);

$db = get_db();
$sid = $_POST['sid'] ?? '';
if (!space_valid_session($sid)) space_error('Missing or  malformed session id!');

$slug     = trim($_POST['board_slug'] ?? '');
$title    = trim($_POST['title'] ?? '');
$raw_name = $_POST['author'] ?? '';


if ($title === '' || mb_strlen($title) > 200) space_error('Title required (max 200 chars)');

[$author, $trip] = parse_tripcode($raw_name);

$board = $db->prepare("SELECT id FROM boards WHERE slug = ?");
$board->execute([$slug]);
$board_id = $board->fetchColumn();
if (!$board_id) space_error('Unknown board', 404);

$existing = $db->prepare("SELECT id FROM spaces WHERE host_session = ? AND status = 'live'");
$existing->execute([$sid]);
if ($existing->fetchColumn()) space_error('You already have a live Space open!');

$db->beginTransaction();
try {
    $ins = $db->prepare(
        "INSERT INTO spaces (board_id, title, host_session, host_author, host_trip)
         VALUES (?, ?, ?, ?, ?) RETURNING id"
    );
    $ins->execute([$board_id, $title, $sid, $author, $trip]);
    $space_id = $ins->fetchColumn();

    $db->prepare(
        "INSERT INTO space_participants (space_id, session_id, author, tripcode, role)
         VALUES (?, ?, ?, ?, 'host')"
    )->execute([$space_id, $sid, $author, $trip]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[space_create] ' . $e->getMessage());
    space_error('Could not create Space', 500);
}

space_json(['ok' => true, 'space_id' => (int)$space_id, 'turn' => turn_credentials($sid)]);
