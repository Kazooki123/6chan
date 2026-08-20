<?php

/**
 * This recommendation algorithm uses a basic scoring system without tracking who you really are
 * unlike Megacorps that sells your algorithm data and feed it to their AI machines, you can optionally
 * disable your sessions and erase your recommendation since we're not assholes.
 */

require_once '../../config/db.php';
require_once '../../config/cache.php';

header('Content-Type: application/json');

$db = get_db();

$limit = min(20, max(1, (int)($_GET['limit'] ?? 8)));

$cache_key = "recommend:global:limit{$limit}";
$cached    = cache_get($cache_key, $db);

if ($cached) {
    echo json_encode($cached);
    exit;
}

/*
 * Score = (reply_count + reaction_count * 1.5) / (hours_since_last_bump + 2) ^ 1.5
 *
 * Same shape as HN's ranking formula: engagement in the numerator,
 * time decay in the denominator. `bumped_at` (not created_at) drives the
 * decay so a thread that's still getting replies stays competitive even
 * if it's old asf. A thread that went quiet fades out even if it once did well.
 * Weighting reactions 1.5x over replies since a reaction takes less
 * effort than typing a reply, so raw counts would otherwise overweight
 * high-traffic-but-low-quality threads.
 */
$sql = "
    WITH engagement AS (
        SELECT
            t.id,
            t.board_id,
            t.subject,
            t.body,
            t.nsfw,
            t.created_at,
            t.bumped_at,
            COALESCE(p.reply_count, 0)    AS reply_count,
            COALESCE(r.reaction_count, 0) AS reaction_count
        FROM threads t
        LEFT JOIN (
            SELECT thread_id, COUNT(*) AS reply_count
            FROM posts
            GROUP BY thread_id
        ) p ON p.thread_id = t.id
        LEFT JOIN (
            SELECT post_id, COUNT(*) AS reaction_count
            FROM reactions
            WHERE post_type = 'thread'
            GROUP BY post_id
        ) r ON r.post_id = t.id
    )
    SELECT
        e.id,
        b.slug AS board_slug,
        b.name AS board_name,
        e.subject,
        e.body,
        e.nsfw,
        e.reply_count,
        e.reaction_count,
        e.created_at,
        (
            (e.reply_count + e.reaction_count * 1.5)
            / POWER(
                GREATEST(EXTRACT(EPOCH FROM (NOW() - e.bumped_at)) / 3600.0, 0) + 2,
                1.5
            )
        ) AS score
    FROM engagement e
    JOIN boards b ON b.id = e.board_id
    ORDER BY score DESC, e.bumped_at DESC
    LIMIT ?
";

$stmt = $db->prepare($sql);
$stmt->execute([$limit]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = array_map(function ($row) {
    $snippet = trim(preg_replace('/\s+/', ' ', (string)$row['body']));
    if (mb_strlen($snippet) > 140) {
        $snippet = mb_substr($snippet, 0, 140) . '…';
    }

    return [
        'id'             => (int)$row['id'],
        'board_slug'     => $row['board_slug'],
        'board_name'     => $row['board_name'],
        'subject'        => $row['subject'],
        'snippet'        => $snippet,
        'nsfw'           => (bool)$row['nsfw'],
        'reply_count'    => (int)$row['reply_count'],
        'reaction_count' => (int)$row['reaction_count'],
        'score'          => round((float)$row['score'], 4),
        'created_at'     => $row['created_at'],
    ];
}, $rows);

cache_set($cache_key, $results, $db, 90);

echo json_encode($results);
