<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/space.php';
require_once __DIR__ . '/../../config/tripcode.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') space_error('POST only', 405);

$db = get_db();
$sid = $_POST['sid'] ?? '';
if (!space_valid_session($sid)) space_error('Missing or  malformed session id!');

$space_id = (int)($_POST['space_id'] ?? 0);
$space    = space_require_live($db, $space_id);
space_sweep_stale($db, $space_id);

[$author, $trip] = parse_tripcode($_POST['author'] ?? '');

$db->prepare(
    "INSERT INTO space_participants (space_id, session_id, author, tripcode, role, last_seen)
    VALUES (?, ?, ?, ?, 'listener', NOW())
    ON CONFLICT (space_id, session_id)
    DO UPDATE SET left_at = NULL, last_seen = NOW(), author = EXCLUDED.author, tripcode = EXCLUDED.tripcode"
)->execute([$space_id, $sid, $author, $trip]);

$stmt = $db->prepare(
    "SELECT session_id, author, tripcode, role, muted
     FROM space_participants WHERE space_id = ? AND left_at IS NULL"
);
$stmt->execute([$space_id]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$my_role = 'listener';
foreach ($participants as $p) {
    if ($p['session_id'] === $sid) { $my_role = $p['role']; break; }
}

$max_stmt = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM space_signals WHERE space_id = ?");
$max_stmt->execute([$space_id]);
$max_id = (int)$max_stmt->fetchColumn();

$db->prepare(
    "INSERT INTO space_signals (space_id, from_session, type, payload)
     VALUES (?, ?, 'join', ?)"
)->execute([$space_id, $sid, json_encode(['author' => $author, 'tripcode' => $trip, 'role' => $my_role])]);
space_notify($db, $space_id);

space_json([
    'ok'             => true,
    'role'           => $my_role,
    'title'          => $space['title'],
    'host_author'    => $space['host_author'],
    'me'             => ['author' => $author, 'tripcode' => $trip],
    'participants'   => $participants,
    'turn'           => turn_credentials($sid),
    'last_signal_id' => $max_id,
]);
