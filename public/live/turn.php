<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__ . '/../../.env'));
$dotenv->load();

function pioneer_turn_credentials(): array
{
    $ttl = 660;
    $username = (string)(time() + $ttl) . ':pioneer';
    $credential = base64_encode(hash_hmac('sha1', $username, $_ENV['TURN_SECRET'], true));

    return [
        'urls'        => $_ENV['TURN_TURN_URIS'] ?? 'turn:localhost:3478',
        'username'    => $username,
        'credentials' => $credential,
    ];
}
