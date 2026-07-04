<?php
// Caching for 6chan's threads and posts

function cache_get(string $key, PDO $db): mixed {
    $stmt = $db->prepare(
        "SELECT value FROM cache
         WHERE key = ?
         AND (expires_at IS NULL OR expires_at > NOW())"
    );
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return json_decode($row['value'], true);
}

function cache_set(string $key, mixed $value, PDO $db, int $ttl_seconds = 300): void {
    $expires = date('c', time() + $ttl_seconds);
    $json    = json_encode($value);
    $db->prepare(
        "INSERT INTO cache (key, value, expires_at)
         VALUES (?, ?::jsonb, ?)
         ON CONFLICT (key) DO UPDATE
             SET value      = EXCLUDED.value,
                 expires_at = EXCLUDED.expires_at,
                 created_at = NOW()"
    )->execute([$key, $json, $expires]);
}

function cache_delete(string $key, PDO $db): void {
    $db->prepare("DELETE FROM cache WHERE key = ?")->execute([$key]);
}

function cache_flush_expired(PDO $db): void {
    $db->exec("DELETE FROM cache WHERE expires_at < NOW()");
}
