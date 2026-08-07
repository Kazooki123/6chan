<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/space.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') space_error('POST only', 405);

$db = get_db();
$sid = $_POST['sid'] ?? '';
if (!space_valid_session($sid)) space_error('Missing or  malformed session id!');

$space_id = (int)($_POST['space_id'] ?? 0);
$type     = $_POST['type'] ?? '';
$to       = $_POST['to'] ?? null;
$payload  = $_POST['payload'] ?? '{}';

if (!in_array($type, ['offer', 'answer', 'ice'], true)) space_error('Invalid signal type');

space_require_live($db, $space_id);
$me = space_require_participant($db, $space_id, $sid);

if ($to !== null) {
    if (!space_valid_session($to)) space_error('Malformed target session');
    if ($me['role'] === 'listener') {
        $target = $db->prepare(
            "SELECT role FROM space_participants
             WHERE space_id = ? AND session_id = ? AND left_at IS NULL"
        );
        $target->execute([$space_id, $to]);
        if (!in_array($target->fetchColumn(), ['host', 'speaker'], true)) {
            space_error('Listeners may only signal speakers/host');
        }
    }
}

$decoded = json_decode($payload, true);
if (!is_array($decoded)) space_error('Payload must be JSON');

$db->prepare(
    "INSERT INTO space_signals (space_id, from_session, to_session, type, payload)
     VALUES (?, ?, ?, ?, ?)"
)->execute([$space_id, $sid, $to, $type, json_encode($decoded)]);

space_notify($db, $space_id);
space_json(['ok' => true]);
