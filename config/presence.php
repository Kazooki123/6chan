<?php
require_once __DIR__ . '/ircdb.php';

const PRESENCE_ACTIVE_SECONDS = 60;
const PRESENCE_MAX_AGE_SECONDS = 600;

function upsert_presence(
    PDO $db,
    string $client_id,
    string $channel,
    string $display_name,
    ?string $tripcode,
    bool $is_staff
): void {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $stmt = $db->prepare(
        'INSERT INTO presence (client_id, channel, display_name, tripcode, is_staff, last_seen)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(client_id, channel) DO UPDATE SET
            display_name = excluded.display_name,
            tripcode     = excluded.tripcode,
            is_staff     = excluded.is_staff,
            last_seen    = excluded.last_seen'
    );
    $stmt->execute([$client_id, $channel, $display_name, $tripcode, $is_staff ? 1 : 0, $now]);
}

/** Distinct identities seen in `$channel` within PRESENCE_ACTIVE_SECONDS. */
function list_active_users(PDO $db, string $channel): array {
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - PRESENCE_ACTIVE_SECONDS);
    $stmt = $db->prepare(
        'SELECT DISTINCT display_name, tripcode, is_staff
         FROM presence
         WHERE channel = ? AND last_seen > ?
         ORDER BY is_staff DESC, display_name ASC'
    );
    $stmt->execute([$channel, $cutoff]);

    return array_map(
        function (array $row): array {
            $row['is_staff'] = (bool)$row['is_staff'];
            return $row;
        },
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

function prune_stale_presence(PDO $db): void {
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - PRESENCE_MAX_AGE_SECONDS);
    $db->prepare('DELETE FROM presence WHERE last_seen < ?')->execute([$cutoff]);
}
