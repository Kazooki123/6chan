<?php
session_start();
require_once '../../config/db.php';
require_once '../../config/tripcode.php';
require_once '../../config/cache.php';

$db = get_db();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$endpoint = $_GET['endpoint'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];

$rate_key = 'irc_rate:' . hash('sha256', $_SERVER['REMOTE_ADDR']);
$rate     = cache_get($rate_key, $db);
$hits     = ($rate['hits'] ?? 0) + 1;
cache_set($rate_key, ['hits' => $hits], $db, 60);
if ($hits > 120) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Max 120 requests/minute.']);
    exit;
}

const VALID_CHANNELS = ['general', 'staffs'];

function is_staff_session(): bool {
    return (bool)($_SESSION['admin'] ?? false);
}

function staff_username(): ?string {
    return $_SESSION['admin_user'] ?? null;
}

switch ($endpoint) {

    // GET irc.php?endpoint=poll&channel=general&since=0&limit=50
    case 'poll':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error'=>'GET only']); break; }

        $channel = $_GET['channel'] ?? '';
        $since   = (int)($_GET['since'] ?? 0);
        $limit   = min((int)($_GET['limit'] ?? 50), 200);

        if (!in_array($channel, VALID_CHANNELS, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown channel']);
            break;
        }
        if ($channel === 'staffs' && !is_staff_session()) {
            http_response_code(403);
            echo json_encode(['error' => 'Staff only']);
            break;
        }

        $rows = $db->prepare(
            "SELECT id, channel, author, tripcode, is_staff, body,
                    to_char(created_at, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created_at
             FROM irc_messages
             WHERE channel = ? AND id > ?
             ORDER BY id ASC
             LIMIT ?"
        );
        $rows->execute([$channel, $since, $limit]);
        $messages = $rows->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'       => true,
            'channel'  => $channel,
            'messages' => $messages,
            'latest_id'=> $messages ? end($messages)['id'] : $since,
        ]);
        break;

    // POST irc.php?endpoint=send   body: channel, author, body
    case 'send':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST only']); break; }

        $channel = $_POST['channel'] ?? '';
        $rawName = trim($_POST['author'] ?? '');
        $body    = trim($_POST['body'] ?? '');

        if (!in_array($channel, VALID_CHANNELS, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown channel']);
            break;
        }

        $is_staff = is_staff_session();
        if ($channel === 'staffs' && !$is_staff) {
            http_response_code(403);
            echo json_encode(['error' => 'Staff only']);
            break;
        }

        if ($body === '' || mb_strlen($body) > 500) {
            http_response_code(400);
            echo json_encode(['error' => 'Message must be 1-500 characters']);
            break;
        }

        if ($channel === 'staffs') {
            // Staff are already identified via their panel login —
            // use that, don't trust a client-supplied name here.
            $author   = staff_username() ?? 'Staff';
            $tripcode = null;
        } else {
            [$author, $tripcode] = parse_tripcode($rawName);
        }

        $ins = $db->prepare(
            "INSERT INTO irc_messages (channel, author, tripcode, is_staff, body)
             VALUES (?, ?, ?, ?, ?)
             RETURNING id, to_char(created_at, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created_at"
        );
        $ins->execute([$channel, $author, $tripcode, $is_staff, $body]);
        $result = $ins->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'         => true,
            'id'         => $result['id'],
            'channel'    => $channel,
            'author'     => $author,
            'tripcode'   => $tripcode,
            'is_staff'   => $is_staff,
            'body'       => $body,
            'created_at' => $result['created_at'],
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'error'     => 'Unknown endpoint.',
            'endpoints' => [
                'poll' => '/irc.php?endpoint=poll&channel=general&since=0',
                'send' => '/irc.php?endpoint=send (POST: channel, author, body)',
            ]
        ]);
}
