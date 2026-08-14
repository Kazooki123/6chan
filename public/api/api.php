<?php
require_once '../../config/db.php';
$db = get_db();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$endpoint = $_GET['endpoint'] ?? '';
$limit    = min((int)($_GET['limit'] ?? 20), 100);
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $limit;

// Rate Limits
// Reuses cache table from config/cache.php
require_once '../../config/cache.php';
$rate_key = 'api_rate:' . hash('sha256', $_SERVER['REMOTE_ADDR']);
$rate     = cache_get($rate_key, $db);
$hits     = ($rate['hits'] ?? 0) + 1;
cache_set($rate_key, ['hits' => $hits], $db, 60);
if ($hits > 120) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Max 120 requests/minute.']);
    exit;
}

// ROUTER
switch ($endpoint) {

    // GET api/api.php?endpoint=boards
    case 'boards':
        $rows = $db->query(
            "SELECT slug, name, descript,
                    (SELECT COUNT(*) FROM threads WHERE board_id=b.id) AS thread_count,
                    (SELECT COUNT(*) FROM posts p JOIN threads t ON t.id=p.thread_id
                     WHERE t.board_id=b.id) AS post_count
             FROM boards b ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'boards'=>$rows]);
        break;

    // GET api/api.php?endpoint=threads&board=g&page=1&limit=20
    case 'threads':
        $slug = $_GET['board'] ?? '';
        if (!$slug) { echo json_encode(['error'=>'Missing board']); break; }

        $board = $db->prepare("SELECT id,slug,name FROM boards WHERE slug=?");
        $board->execute([$slug]);
        $board = $board->fetch(PDO::FETCH_ASSOC);
        if (!$board) { http_response_code(404); echo json_encode(['error'=>'Board not found']); break; }

        $rows = $db->prepare(
            "SELECT t.id, t.subject, LEFT(t.body,300) AS excerpt,
                    t.author, t.created_at, t.bumped_at,
                    t.pinned, t.locked, t.nsfw,
                    t.img_path IS NOT NULL AS has_image,
                    (SELECT COUNT(*) FROM posts WHERE thread_id=t.id) AS reply_count
             FROM threads t WHERE t.board_id=?
             ORDER BY t.pinned DESC, t.bumped_at DESC
             LIMIT ? OFFSET ?"
        );
        $rows->execute([$board['id'], $limit, $offset]);
        echo json_encode([
            'ok'     => true,
            'board'  => $board,
            'page'   => $page,
            'limit'  => $limit,
            'threads'=> $rows->fetchAll(PDO::FETCH_ASSOC),
        ]);
        break;

    // GET api/api.php?endpoint=thread&id=42
    case 'thread':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'Missing id']); break; }

        $t = $db->prepare(
            "SELECT t.*, b.slug, b.name AS bname
             FROM threads t JOIN boards b ON b.id=t.board_id WHERE t.id=?"
        );
        $t->execute([$id]);
        $thread = $t->fetch(PDO::FETCH_ASSOC);
        if (!$thread) { http_response_code(404); echo json_encode(['error'=>'Not found']); break; }

        unset($thread['ip']);

        $posts = $db->prepare(
            "SELECT id, body, author, img_path IS NOT NULL AS has_image,
                    img_path, nsfw, options, created_at
             FROM posts WHERE thread_id=? ORDER BY created_at ASC
             LIMIT ? OFFSET ?"
        );
        $posts->execute([$id, $limit, $offset]);
        $posts = $posts->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($posts as &$p) unset($p['ip']);
        unset($p);

        $poll_stmt = $db->prepare(
            "SELECT p.id, p.question, p.closes_at,
                    json_agg(json_build_object(
                        'id', po.id, 'label', po.label,
                        'votes', (SELECT COUNT(*) FROM poll_votes WHERE option_id=po.id)
                    ) ORDER BY po.position) AS options
             FROM polls p JOIN poll_options po ON po.poll_id=p.id
             WHERE p.thread_id=? GROUP BY p.id"
        );
        $poll_stmt->execute([$id]);
        $poll = $poll_stmt->fetch(PDO::FETCH_ASSOC);
        if ($poll && is_string($poll['options'])) {
            $poll['options'] = json_decode($poll['options'], true);
        }

        echo json_encode([
            'ok'     => true,
            'thread' => $thread,
            'posts'  => $posts,
            'poll'   => $poll ?: null,
        ]);
        break;

    // GET api/api.php?endpoint=search&q=hello&board=g
    case 'search':
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode(['error'=>'Query too short']); break; }

        $words   = array_filter(array_map('trim', explode(' ', $q)));
        $tsquery = implode(' & ', array_map(fn($w) => $w.':*', $words));
        $like    = '%'.$q.'%';

        $rows = $db->prepare(
            "SELECT t.id, t.subject, LEFT(t.body,200) AS excerpt,
                    b.slug, t.created_at
             FROM threads t JOIN boards b ON b.id=t.board_id
             WHERE t.search_vec @@ to_tsquery('english',?)
                OR t.body ILIKE ?
             ORDER BY t.bumped_at DESC LIMIT ?"
        );
        $rows->execute([$tsquery, $like, $limit]);
        echo json_encode(['ok'=>true,'results'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // GET api/api.php?endpoint=stats
    case 'stats':
        $stats = $db->query(
            "SELECT
                (SELECT COUNT(*) FROM boards)  AS boards,
                (SELECT COUNT(*) FROM threads) AS threads,
                (SELECT COUNT(*) FROM posts)   AS posts,
                (SELECT COUNT(*) FROM threads
                 WHERE created_at > NOW() - INTERVAL '24 hours') AS threads_today,
                (SELECT COUNT(*) FROM posts
                 WHERE created_at > NOW() - INTERVAL '24 hours') AS posts_today"
        )->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'stats'=>$stats]);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'error'     => 'Unknown endpoint.',
            'endpoints' => [
                'boards'  => '/api.php?endpoint=boards',
                'threads' => '/api.php?endpoint=threads&board=g&page=1',
                'thread'  => '/api.php?endpoint=thread&id=42',
                'search'  => '/api.php?endpoint=search&q=hello',
                'stats'   => '/api.php?endpoint=stats',
            ]
        ]);
}
