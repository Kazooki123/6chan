<?php
/**
 * POST /services/pfp_set.php
 *
 * Sets (or replaces) the profile picture tied to a tripcode.
 * Auth model: whoever can reproduce the trip from name#password owns it -
 * same trust model as posting. No session, no login </3
 *
 * Params:
 *   name  (string) — raw "Name#password" field, same syntax as the postform
 *   image (file)   — the pfp to upload
 *
 * Response: { ok, error?, trip?, url? }
 */

require_once '../../config/db.php';
require_once '../../config/pfps.php';

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__ . '/../../.env'));
$dotenv->load();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$db = get_db();

$raw_name = trim($_POST['name'] ?? '');

if ($raw_name === '' || !str_contains($raw_name, '#')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Enter Name#password (same as posting) to set a profile picture.']);
    exit;
}

[$display, $trip] = parse_tripcode($raw_name);

if ($trip === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Could not generate a tripcode from that password.']);
    exit;
}

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No image provided.']);
    exit;
}

$result = pfp_set($trip, $_FILES['image'], $db);

if (!$result['ok']) {
    http_response_code(422);
    echo json_encode($result);
    exit;
}

echo json_encode([
    'ok'   => true,
    'trip' => $trip,
    'url'  => $result['url'],
]);
