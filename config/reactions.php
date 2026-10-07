<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__ . '../.env'));
$dotenv->load();

$r_salt = $_ENV['R_SALT'];

define('ALLOWED_REACTIONS', [
    '😂', '🥹', '⭐', '🔥',
    '💀', '🤔', '😭', '💯',
    '🎵', '🤯', '🤡', '🩷',
    '🤤'

]);
define('REACTION_SALT', $r_salt);

function hash_ip(string $ip): string {
    return hash('sha256', REACTION_SALT . $ip);
}

/**
 * GET all reactions for a set of POSTS in one query.
 * Returns: ['thread:X' => ['⭐' => true, '😭' => false, ...], ...] where 'X' is the number of threads.
 * true = current user has reacted
 * false = someone else has, but not the current user.
 */
function get_reactions(array $targets, string $viewer_ip, PDO $db): array {
    if (empty($targets)) return [];

    $ip_hash = hash_ip($viewer_ip);
    $result = [];

    $placeholders = implode(',', array_fill(0, count($targets), '(?,?)'));
    $params       = [];
    foreach ($targets as $t) {
        $params[] = $t['type'];
        $params[] = $t['id'];
    }

    $rows = $db->prepare(
        "SELECT post_type, post_id, emoji, ip_hash
         FROM reactions
         WHERE (post_type, post_id) IN ($placeholders)"
    );
    $rows->execute($params);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $key = $row['post_type'] . ':' . $row['post_id'];
        if (!isset($result[$key])) $result[$key] = [];

        if (!isset($result[$key][$row['emoji']])) {
            $result[$key][$row['emoji']] = false;
        }
        if ($row['ip_hash'] === $ip_hash) {
            $result[$key][$row['emoji']] = true;
        }
    }

    return $result;
}

function toggle_reaction(
    string $post_type,
    int    $post_id,
    string $emoji,
    string $ip,
    PDO    $db
): array {
    if (!in_array($emoji, ALLOWED_REACTIONS, true)) {
        return ['ok' => false, 'error' => 'Invalid reaction.'];
    }
    if (!in_array($post_type, ['thread', 'post'], true)) {
        return ['ok' => false, 'error' => 'Invalid post type.'];
    }

    $ip_hash = hash_ip($ip);

    $exists = $db->prepare(
        "SELECT id FROM reactions
         WHERE post_type=? AND post_id=? AND emoji=? AND ip_hash=?"
    );
    $exists->execute([$post_type, $post_id, $emoji, $ip_hash]);

    if ($exists->fetch()) {
        $db->prepare(
            "DELETE FROM reactions
             WHERE post_type=? AND post_id=? AND emoji=? AND ip_hash=?"
        )->execute([$post_type, $post_id, $emoji, $ip_hash]);
        return ['ok' => true, 'action' => 'removed'];
    } else {
        // Rate limit: max 10 reactions per IP per minute
        $rate = $db->prepare(
            "SELECT COUNT(*) FROM reactions
             WHERE ip_hash=? AND created_at > NOW() - INTERVAL '1 minute'"
        );
        $rate->execute([$ip_hash]);
        if ((int)$rate->fetchColumn() >= 10) {
            return ['ok' => false, 'error' => 'Reacting too fast.'];
        }

        $db->prepare(
            "INSERT INTO reactions (post_type, post_id, emoji, ip_hash)
             VALUES (?,?,?,?)
             ON CONFLICT DO NOTHING"
        )->execute([$post_type, $post_id, $emoji, $ip_hash]);
        return ['ok' => true, 'action' => 'added'];
    }
}
