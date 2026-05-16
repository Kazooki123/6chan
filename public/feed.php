<?php
require_once '../config/db.php';
$db = get_db();

$threads = $db->query(
    "SELECT t.id, t.subject, t.body, t.created_at, t.img_path,
            b.slug, b.name AS bname
     FROM threads t
     JOIN boards b ON b.id = t.board_id
     ORDER BY t.created_at DESC
     LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

// Will change once deployed online
$base = 'http://localhost:8080';

header('Content-Type: application/rss+xml; charset=utf-8');
echo `<?xml version="1.0" encoding="UTF-8">`;
?>
<rss version="2.0">
<channel>
    <title>@6chan - Recent Threads</title>
    <link><?= $base ?></link>
    <description>The LATEST threads from 6chan</description>
    <language>en-us</language>
    <lastBuildDate><?= date(DATE_RSS) ?></lastBuildDate>

    <?php foreach ($threads as $t): ?>
    <item>
        <title><?= htmlspecialchars($t['subject'] ?: 'Thread #'.$t['id']) ?></title>
        <link><?= $base ?>/thread.php?id=<?= $t['id'] ?></link>
        <guid><?= $base ?>/thread.php?id=<?= $t['id'] ?></guid>
        <pubDate><?= date(DATE_RSS, strtotime($t['created_at'])) ?></pubDate>
        <category>/<?= htmlspecialchars($t['slug']) ?>/ - <?= htmlspecialchars($t['bname']) ?></category>
        <description><![CDATA[
            <?php if (!empty($t['img_path'])): ?>
            <img src="<?= $base ?>/uploads/<?= htmlspecialchars($t['img_path']) ?>"><br>
            <?php endif; ?>
            <?= nl2br(htmlspecialchars(mb_substr($t['body'], 0, 300))) ?>
            <?= mb_strlen($t['body']) > 300 ? '...' : '' ?>
        ]]></description>
    </item>
    <?php endforeach; ?>

</channel>
</rss>
