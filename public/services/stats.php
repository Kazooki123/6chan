<?php
require_once '../../config/db.php';
require_once '../../config/cache.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db        = get_db();
$cache_key = 'homepage:stats';
$cached    = cache_get($cache_key, $db);

if ($cached) {
    echo json_encode($cached);
    exit;
}

$stats = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM boards)   AS boards,
        (SELECT COUNT(*) FROM threads)  AS threads,
        (SELECT COUNT(*) FROM posts)    AS posts,
        (SELECT COUNT(*) FROM threads
         WHERE created_at > NOW() - INTERVAL '24 hours') AS threads_today,
        (SELECT COUNT(*) FROM posts
         WHERE created_at > NOW() - INTERVAL '24 hours') AS posts_today,
        (SELECT COUNT(*) FROM threads
         WHERE created_at > NOW() - INTERVAL '1 hour')   AS threads_this_hour,
        (SELECT COUNT(*) FROM posts
         WHERE created_at > NOW() - INTERVAL '1 hour')   AS posts_this_hour,
        -- Approx unique posters today via distinct ip hashes on posts+threads
        (SELECT COUNT(DISTINCT ip) FROM posts
         WHERE created_at > NOW() - INTERVAL '24 hours'
         AND ip IS NOT NULL)            AS active_posters
    "
)->fetch(PDO::FETCH_ASSOC);

// Cast all to int
foreach ($stats as &$v) $v = (int)$v;
unset($v);

$stats['generated_at'] = date('c');

cache_set($cache_key, $stats, $db, 45);

echo json_encode($stats);
