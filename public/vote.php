<?php
require_once '../config/db.php';
require_once '../config/reactions.php'; // reusing `hash_ip()`
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'POST only']); exit;
}

$db        = get_db();
$option_id = (int)($_POST['option_id'] ?? 0);
$ip_hash   = hash_ip($_SERVER['REMOTE_ADDR']);

if (!$option_id) {
    echo json_encode(['ok'=>false,'error'=>'Invalid option']); exit;
}

$opt = $db->prepare(
    "SELECT o.id, o.poll_id, p.closes_at
     FROM poll_options o JOIN polls p ON p.id=o.poll_id
     WHERE o.id=?"
);
$opt->execute([$option_id]);
$opt = $opt->fetch(PDO::FETCH_ASSOC);

if (!$opt) {
    echo json_encode(['ok'=>false,'error'=>'Option not found']); exit;
}
if ($opt['closes_at'] && strtotime($opt['closes_at']) < time()) {
    echo json_encode(['ok'=>false,'error'=>'Poll is closed']); exit;
}

$poll_id = $opt['poll_id'];

$already = $db->prepare(
    "SELECT pv.id FROM poll_votes pv
     JOIN poll_options po ON po.id=pv.option_id
     WHERE po.poll_id=? AND pv.ip_hash=?"
);
$already->execute([$poll_id, $ip_hash]);
$existing = $already->fetch();

if ($existing) {
    $db->prepare(
        "DELETE FROM poll_votes WHERE id=?"
    )->execute([$existing['id']]);
}

$db->prepare(
    "INSERT INTO poll_votes (option_id, ip_hash) VALUES (?,?)
     ON CONFLICT DO NOTHING"
)->execute([$option_id, $ip_hash]);

// Returns fresh vote counts
$counts = $db->prepare(
    "SELECT po.id, po.label, COUNT(pv.id) AS votes
     FROM poll_options po
     LEFT JOIN poll_votes pv ON pv.option_id=po.id
     WHERE po.poll_id=?
     GROUP BY po.id, po.label, po.position
     ORDER BY po.position"
);
$counts->execute([$poll_id]);
$results = $counts->fetchAll(PDO::FETCH_ASSOC);
$total   = array_sum(array_column($results, 'votes'));

echo json_encode(['ok'=>true,'results'=>$results,'total'=>(int)$total]);
