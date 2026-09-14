<?php
require_once __DIR__ . '/ircdb.php';

function create_staff_invite(PDO $db, string $created_by, int $ttl_hours = 24): string {
    $code    = bin2hex(random_bytes(16));
    $now     = gmdate('Y-m-d\TH:i:s\Z');
    $expires = gmdate('Y-m-d\TH:i:s\Z', time() + $ttl_hours * 3600);

    $db->prepare(
        'INSERT INTO staff_invites (code, created_by, created_at, expires_at) VALUES (?, ?, ?, ?)'
    )->execute([$code, $created_by, $now, $expires]);

    return $code;
}

/** Returns the plaintext token on success, or null if the code is invalid/used/expired. */
function redeem_staff_invite(PDO $db, string $code, string $username): ?string {
    $stmt = $db->prepare('SELECT * FROM staff_invites WHERE code = ?');
    $stmt->execute([$code]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invite || $invite['redeemed_at'] !== null) {
        return null;
    }
    if ($invite['expires_at'] < gmdate('Y-m-d\TH:i:s\Z')) {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $now   = gmdate('Y-m-d\TH:i:s\Z');

    $db->beginTransaction();
    $db->prepare('UPDATE staff_invites SET redeemed_at = ? WHERE id = ?')
       ->execute([$now, $invite['id']]);
    $db->prepare('INSERT INTO staff_tokens (username, token_hash, created_at) VALUES (?, ?, ?)')
       ->execute([$username, $hash, $now]);
    $db->commit();

    return $token;
}

/** Returns the staff username for a valid, non-revoked token, or null. */
function verify_staff_token(PDO $db, ?string $token): ?string {
    if (!$token) {
        return null;
    }
    $hash = hash('sha256', $token);
    $stmt = $db->prepare('SELECT username FROM staff_tokens WHERE token_hash = ? AND revoked = 0');
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['username'] ?? null;
}

function revoke_staff_token(PDO $db, int $id): void {
    $db->prepare('UPDATE staff_tokens SET revoked = 1 WHERE id = ?')->execute([$id]);
}

function list_staff_invites(PDO $db): array {
    return $db->query('SELECT * FROM staff_invites ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
}

function list_staff_tokens(PDO $db): array {
    return $db->query('SELECT id, username, created_at, revoked FROM staff_tokens ORDER BY id DESC LIMIT 50')
               ->fetchAll(PDO::FETCH_ASSOC);
}
