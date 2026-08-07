<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/space.php';

$db = get_db();
$sid = $_GET['sid'] ?? '';
if (!space_valid_session($sid)) space_error('Missing or  malformed session id!');

$space_id = (int)($_GET['space_id'] ?? 0);
$since_id = (int)($_GET['since'] ?? 0);

space_require_participant($db, $space_id, $sid);
space_touch_participant($db, $space_id, $sid);
space_sweep_stale($db, $space_id);

function fetch_signals(PDO $db, int $space_id, string $sid, int $since_id): array {
    $stmt = $db->prepare(
        "SELECT id, from_session, to_session, type, payload
         FROM space_signals
         WHERE space_id = ? AND id > ?
           AND (to_session IS NULL OR to_session = ?)
           AND from_session != ?
         ORDER BY id ASC LIMIT 200"
    );
    $stmt->execute([$space_id, $since_id, $sid, $sid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function respond_with(array $rows, int $since_id, $conn = null): never {
    if ($conn) @pg_query($conn, "UNLISTEN space_channel");
    $last = $rows ? end($rows)['id'] : $since_id;
    space_json(['ok' => true, 'signals' => $rows, 'last_id' => (int)$last]);
}

$rows = fetch_signals($db, $space_id, $sid, $since_id);
if ($rows) respond_with($rows, $since_id);

$conn = space_raw_pg();
pg_query($conn, "LISTEN space_channel");

$deadline = microtime(true) + 25;
while (microtime(true) < $deadline) {
    $read = [pg_socket($conn)]; $write = null; $except = null;
    stream_select($read, $write, $except, 1);

    pg_consume_input($conn);
    while ($notify = pg_get_notify($conn)) {
        if ((int)$notify['payload'] === $space_id) {
            $rows = fetch_signals($db, $space_id, $sid, $since_id);
            if ($rows) respond_with($rows, $since_id, $conn);
        }
    }

    $rows = fetch_signals($db, $space_id, $sid, $since_id);
    if ($rows) respond_with($rows, $since_id, $conn);
}

respond_with([], $since_id, $conn);
