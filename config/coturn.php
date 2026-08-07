<?php

function turn_credentials(string $session_id, int $ttl = 3600): array {
    $secret = $_ENV['TURN_SECRET'] ?? '';
    if ($secret === '') {
        throw new RuntimeException('TURN_SECRET is not set in .env');
    }

    if (!preg_match('/^[A-Za-z0-9\-]{8,64}$/', $session_id)) {
        throw new InvalidArgumentException('Malformed session_id');
    }

    $expiry     = time() + +$ttl;
    $username   = $expiry . ':' . $session_id;
    $credential = base64_encode(
        hash_hmac('sha1', $username, $secret, true)
    );

    $stun_uris = explode(',', $_ENV['TURN_STUN_URIS'] ?? 'stun:localhost:3478');
    $turn_uris = explode(',', $_ENV['TURN_TURN_URIS'] ?? 'turn:localhost:3478');

    return [
        'username'   => $username,
        'credential' => $credential,
        'ttl'        => $ttl,
        'uris'       => array_merge($stun_uris, $turn_uris),
    ];
}
