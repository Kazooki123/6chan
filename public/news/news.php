<?php
require_once '../../config/db.php';
require_once '../../config/markdownposts.php';

$db     = get_db();
$boards = $db->query("SELECT slug, name FROM boards ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$view_slug = $_GET['slug'] ?? '';
$post      = null;

if ($view_slug !== '') {
    // Sanitize slug
    $safe_slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $view_slug);
    $post_file = __DIR__ . '/../../content/news/' . $safe_slug . '.md';
    $post      = parse_md_post($post_file);
    if (!$post) { http_response_code(404); header('Location: news.php'); exit; }
}

// View list
$posts = $post ? [] : load_md_posts(__DIR__ . '/../../content/news');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $post ? htmlspecialchars($post['title']) . ' - ' : '' ?>News — 6chan</title>
    <link rel="icon" type="image/x-icon" href="../assets/favicon.svg">
    <link rel="stylesheet" href="../style/style.css">
    <script src="../js/settingsloader.js"></script>
    <style>
    .news-wrap { max-width: 720px; margin: 0 auto; padding: 12px; }
    
    /* ── List view ── */
    .news-card {
        background: var(--surface);
        border: 1px solid var(--border);
        margin-bottom: 12px;
        transition: border-color .15s;
    }
    .news-card:hover { border-color: var(--orange); }
    .news-card.pinned { border-left: 3px solid var(--orange); }
    .news-banner {
        width: 100%;
        height: 140px;
        object-fit: cover;
        display: block;
        background: #111;
        border-bottom: 1px solid var(--border);
    }
    .news-card-body { padding: 12px 16px; }
    .news-card-title {
        font-size: 15px;
        font-weight: bold;
        margin-bottom: 5px;
    }
    .news-card-title a { color: var(--white); text-decoration: none; }
    .news-card-title a:hover { color: var(--orange); }
    .news-meta {
        font-size: 11px;
        color: var(--grey);
        margin-bottom: 8px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    .news-meta .pinned-tag {
        color: var(--orange);
        font-weight: bold;
    }
    .news-summary {
        font-size: 13px;
        color: #bbb;
        line-height: 1.65;
        margin-bottom: 10px;
    }
    .news-read-more {
        font-size: 12px;
        color: var(--orange);
    }
    .news-read-more:hover { text-decoration: underline; }
    
    /* ── Single post view ── */
    .news-post-banner {
        width: 100%;
        max-height: 280px;
        object-fit: cover;
        display: block;
        border: 1px solid var(--border);
        margin-bottom: 16px;
    }
    .news-post-header {
        background: var(--surface);
        border: 1px solid var(--border);
        border-left: 4px solid var(--orange);
        padding: 12px 16px;
        margin-bottom: 16px;
    }
    .news-post-title {
        color: var(--orange);
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 5px;
    }
    .news-post-meta {
        font-size: 12px;
        color: var(--grey);
        display: flex;
        gap: 14px;
        flex-wrap: wrap;
    }
    .news-post-body {
        background: var(--surface);
        border: 1px solid var(--border);
        padding: 16px 20px;
        font-size: 13px;
        line-height: 1.8;
    }
    .news-post-body h1,
    .news-post-body h2,
    .news-post-body h3 {
        color: var(--orange);
        border-bottom: 1px solid var(--border);
        padding-bottom: 3px;
        margin: 14px 0 8px;
    }
    .news-post-body p   { margin: 0 0 10px; color: var(--white); }
    .news-post-body img {
        max-width: 100%;
        border: 1px solid var(--border);
        margin: 8px 0;
    }
    .news-post-body code {
        background: #111;
        border: 1px solid var(--border);
        color: #e6a817;
        font: 12px 'Courier New',monospace;
        padding: 1px 5px;
    }
    .news-post-body pre {
        background: #111;
        border: 1px solid var(--border);
        padding: 10px;
        overflow-x: auto;
    }
    .news-post-body blockquote {
        border-left: 3px solid var(--orange);
        padding-left: 12px;
        color: var(--grey);
        margin: 8px 0;
    }
    .news-post-body ul,
    .news-post-body ol { padding-left: 20px; margin: 6px 0; color: var(--white); }
    .news-post-body a  { color: var(--link); }
    .news-empty {
        color: var(--grey);
        font-size: 13px;
        padding: 20px 0;
        text-align: center;
    }
    </style>
</head>
<body>
    <div id="topbar">
        <span class="site-name">@6chan</span>
        <a href="../index.php">Home</a>
        <a href="../rules.html">[Rules]</a>
        <a href="../contact.php">[Contact]</a>
        <a href="../support.html">[Support]</a>
    </div>

    <div class="news-wrap">
    <?php if ($post): ?>
        <div class="page-header">
            <h1>News</h1>
            <p><a href="news.php" style="color:var(--orange);">[← All news]</a></p>
        </div>
    
        <?php if ($post['banner']): ?>
        <img src="<?= htmlspecialchars($post['banner']) ?>"
             alt="Banner" class="news-post-banner">
        <?php endif; ?>
    
        <div class="news-post-header">
            <div class="news-post-title"><?= htmlspecialchars($post['title']) ?></div>
            <div class="news-post-meta">
                <?php if ($post['date']): ?>
                    <span>📅 <?= htmlspecialchars($post['date']) ?></span>
                <?php endif; ?>
                <span>✍ <?= htmlspecialchars($post['author']) ?></span>
                <?php if ($post['pinned']): ?>
                    <span style="color:var(--orange);">📌 Pinned</span>
                <?php endif; ?>
            </div>
        </div>
    
        <div class="news-post-body post-body">
            <?= $post['body_html'] ?>
        </div>
    
        <div style="margin-top:12px;font-size:13px;">
            <a href="news.php" style="color:var(--orange);">[← Back to all news]</a>
        </div>
    
    <?php else: ?>
        <div class="page-header">
            <h1>News</h1>
            <p>Updates, announcements, and site changes from the @6chan team.</p>
        </div>
    
        <?php if (empty($posts)): ?>
            <div class="news-empty">
                No news posts yet. Check back soon.<br>
                <span style="font-size:11px;color:var(--border);">
                    (Add .md files to content/news/ to publish)
                </span>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $p): ?>
            <div class="news-card <?= $p['pinned'] ? 'pinned' : '' ?>">
                <?php if ($p['banner']): ?>
                <a href="news.php?slug=<?= urlencode($p['slug']) ?>">
                    <img src="<?= htmlspecialchars($p['banner']) ?>"
                         alt="Banner" class="news-banner" loading="lazy">
                </a>
                <?php endif; ?>
                <div class="news-card-body">
                    <div class="news-card-title">
                        <a href="news.php?slug=<?= urlencode($p['slug']) ?>">
                            <?= htmlspecialchars($p['title']) ?>
                        </a>
                    </div>
                    <div class="news-meta">
                        <?php if ($p['date']): ?>
                            <span>📅 <?= htmlspecialchars($p['date']) ?></span>
                        <?php endif; ?>
                        <span>✍ <?= htmlspecialchars($p['author']) ?></span>
                        <?php if ($p['pinned']): ?>
                            <span class="pinned-tag">📌 Pinned</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($p['summary']): ?>
                    <div class="news-summary"><?= htmlspecialchars($p['summary']) ?></div>
                    <?php endif; ?>
                    <a href="news.php?slug=<?= urlencode($p['slug']) ?>"
                       class="news-read-more">[Read more →]</a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    
    <?php endif; ?>
    </div>

    <footer>
        @6chan — <a href="../index.php">Home</a> —
        <a href="../blogs/blogs.php">Blogs</a> —
        <a href="../contact.php">Contact</a>
    </footer>
</body>
</html>