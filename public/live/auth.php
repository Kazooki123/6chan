<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/db.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__ . '/../../.env'));
$dotenv->load();

define('PIONEER_SECRET', $_ENV['PIONEER_SECRET']);
define('PIONEER_RELAY_URL', $_ENV['PIONEER_RELAY_URL']);
const PIONEER_TOKEN_TTL = 60 * 60 * 4; // 4 hours

function pioneer_mint_token(string $modId): string
{
    $expiry = time() + PIONEER_TOKEN_TTL;
    $payload = $modId . '.' . $expiry;
    $sig = hash_hmac('sha256', $payload, PIONEER_SECRET);
    return rtrim(strtr(base64_encode($payload . '.' . $sig), '+/', '-_'), '=');
}

function pioneer_require_mod(): ?string
{
    if (!($_SESSION['admin'] ?? false)) {
        return null;
    }
    return $_SESSION['admin_user'];
}
