<?php

require_once __DIR__ . '/coturn.php';

function space_json(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function space_error(string $msg, int $code = 400): never {
    space_json(['ok' => false, 'error' => $msg], $code);
}

function space_valid_session(?string $sid): bool {
    return $sid !== null && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $sid) === 1;
}

function space_raw_pg() {
    static $conn = null;
    if ($conn) return $conn;

    $conn = pg_connect(sprintf(
        "host=%s dbname=%s user=%s password=%s",
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']
    ));
    if (!$conn) space_error('Signaling connection failed', 500);
    return $conn;
}

function space_notify(PDO $db, int $space_id): void {
    $db->prepare("SELECT pg_notify('space_channel', ?)")->execute([(string)$space_id]);
}

function space_require_live(PDO $db, int $space_id): array {
    $stmt = $db->prepare("SELECT * FROM spaces WHERE id = ?");
    $stmt->execute([$space_id]);
    $space = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$space)                     space_error('Space not found', 404);
    if ($space['status'] !== 'live') space_error('Space has ended', 410);
    return $space;
}

function space_require_participant(PDO $db, int $space_id, string $sid): array {
    $stmt = $db->prepare(
        "SELECT * FROM space_participants
         WHERE space_id = ? AND session_id = ? AND left_at IS NULL"
    );
    $stmt->execute([$space_id, $sid]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) space_error('Not a participant in this space', 403);
    return $p;
}

function space_require_host(PDO $db, int $space_id, string $sid): array {
    $p = space_require_participant($db, $space_id, $sid);
    if ($p['role'] !== 'host') space_error('Host only', 403);
    return $p;
}

function space_touch_participant(PDO $db, int $space_id, string $sid): void {
    $db->prepare(
        "UPDATE space_participants SET last_seen = NOW()
         WHERE space_id = ? AND session_id = ?"
    )->execute([$space_id, $sid]);
}

function space_sweep_stale(PDO $db, int $space_id): void {
    $stale = $db->prepare(
        "SELECT session_id, role FROM space_participants
         WHERE space_id = ? AND left_at IS NULL
           AND last_seen < NOW() - INTERVAL '45 seconds'"
    );
    $stale->execute([$space_id]);
    $gone = $stale->fetchAll(PDO::FETCH_ASSOC);
    if (!$gone) return;

    $mark = $db->prepare("UPDATE space_participants SET left_at = last_seen WHERE space_id = ? AND session_id = ?");
    $sig  = $db->prepare("INSERT INTO space_signals (space_id, from_session, type, payload) VALUES (?, ?, 'leave', '{}')");

    $host_gone = false;
    foreach ($gone as $p) {
        $mark->execute([$space_id, $p['session_id']]);
        $sig->execute([$space_id, $p['session_id']]);
        if ($p['role'] === 'host') $host_gone = true;
    }

    if ($host_gone) {
        $db->prepare("UPDATE spaces SET status='ended', ended_at=NOW() WHERE id=? AND status='live'")->execute([$space_id]);
        $db->prepare("INSERT INTO space_signals (space_id, from_session, type, payload) VALUES (?, 'system', 'ended', '{}')")->execute([$space_id]);
    }

    space_notify($db, $space_id);
}
