<?php
session_start();
require_once '../../config/db.php';
require_once '../../config/ircdb.php';
require_once '../../config/cache.php';
require_once '../../config/tripcode.php';
require_once '../../config/staff_auth.php';
require_once '../../config/presence.php';

$db       = get_db();
$chat_db  = get_chat_db();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$endpoint = $_GET['endpoint'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];

function check_rate_limit(PDO $db, string $scope, int $max_per_minute): void {
    $ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR']);
    $bucket  = intdiv(time(), 60);
    $key     = "irc_rate:{$scope}:{$ip_hash}:{$bucket}";

    $rate = cache_get($key, $db);
    $hits = ($rate['hits'] ?? 0) + 1;
    cache_set($key, ['hits' => $hits], $db, 90); // outlives the bucket, just for cleanup

    if ($hits > $max_per_minute) {
        http_response_code(429);
        echo json_encode(['error' => "Rate limit exceeded. Max {$max_per_minute} requests/minute."]);
        exit;
    }
}

const VALID_CHANNELS = ['general', 'staffs'];

function is_staff_session(): bool {
    return (bool)($_SESSION['admin'] ?? false);
}

function resolve_staff(PDO $chat_db): ?string {
    if (is_staff_session()) {
        return $_SESSION['admin_user'] ?? 'Staff';
    }
    $token = $_SERVER['HTTP_X_YUKIKO_TOKEN'] ?? null;
    return verify_staff_token($chat_db, $token);
}

switch ($endpoint) {

    // GET irc.php?endpoint=poll&channel=general&since=0&limit=50
    case 'poll':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error'=>'GET only']); break; }
        check_rate_limit($db, 'poll', 300);

        $channel   = $_GET['channel'] ?? '';
        $since     = (int)($_GET['since'] ?? 0);
        $limit     = min((int)($_GET['limit'] ?? 50), 200);
        $client_id = trim($_GET['client_id'] ?? '');
        $raw_name  = trim($_GET['name'] ?? '');

        if (!in_array($channel, VALID_CHANNELS, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown channel']);
            break;
        }

        $staff_user = resolve_staff($chat_db);
        $is_staff   = $staff_user !== null;

        if ($channel === 'staffs' && !$is_staff) {
            http_response_code(403);
            echo json_encode(['error' => 'Staff only']);
            break;
        }

        if ($client_id !== '') {
            if ($channel === 'staffs') {
                [$presence_name, $presence_trip] = [$staff_user, null];
            } else {
                [$presence_name, $presence_trip] = parse_tripcode($raw_name !== '' ? $raw_name : 'Anon');
            }
            upsert_presence($chat_db, $client_id, $channel, $presence_name, $presence_trip, $is_staff);
            if (random_int(1, 10) === 1) {
                prune_stale_presence($chat_db);
            }
        }

        $rows = $chat_db->prepare(
            "SELECT id, channel, author, tripcode, is_staff, body, created_at
             FROM irc_messages
             WHERE channel = ? AND id > ?
             ORDER BY id ASC
             LIMIT ?"
        );
        $rows->execute([$channel, $since, $limit]);
        $messages = array_map(
            function (array $row): array {
                $row['is_staff'] = (bool)$row['is_staff']; // SQLite has no native boolean type
                $row['id']       = (int)$row['id'];
                return $row;
            },
            $rows->fetchAll(PDO::FETCH_ASSOC)
        );

        echo json_encode([
            'ok'       => true,
            'channel'  => $channel,
            'messages' => $messages,
            'latest_id'=> $messages ? end($messages)['id'] : $since,
            'users'    => list_active_users($chat_db, $channel),
        ]);
        break;

    // POST irc.php?endpoint=send   body: channel, author, body
    case 'send':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST only']); break; }
        check_rate_limit($db, 'send', 20);

        $channel = $_POST['channel'] ?? '';
        $rawName = trim($_POST['author'] ?? '');
        $body    = trim($_POST['body'] ?? '');

        if (!in_array($channel, VALID_CHANNELS, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown channel']);
            break;
        }

        $staff_user = resolve_staff($chat_db);
        $is_staff   = $staff_user !== null;
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
            $author   = $staff_user;
            $tripcode = null;
        } else {
            [$author, $tripcode] = parse_tripcode($rawName);
        }

        $created_at = gmdate('Y-m-d\TH:i:s\Z');

        $ins = $chat_db->prepare(
            "INSERT INTO irc_messages (channel, author, tripcode, is_staff, body, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$channel, $author, $tripcode, $is_staff ? 1 : 0, $body, $created_at]);
        $new_id = (int)$chat_db->lastInsertId();

        if (random_int(1, 20) === 1) {
            prune_old_chat_messages($chat_db);
        }

        echo json_encode([
            'ok'         => true,
            'id'         => $new_id,
            'channel'    => $channel,
            'author'     => $author,
            'tripcode'   => $tripcode,
            'is_staff'   => $is_staff,
            'body'       => $body,
            'created_at' => $created_at,
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
