<?php
require_once '../../config/db.php';
require_once '../../config/markdownposts.php';

$db     = get_db();
$boards = $db->query("SELECT slug, name FROM boards ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$view_slug = $_GET['slug'] ?? '';
$post      = null;

if ($view_slug !== '') {
    $safe_slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $view_slug);
    $post_file = __DIR__ . '/../../content/blogs/' . $safe_slug . '.md';
    $post      = parse_md_post($post_file);
    if (!$post) { http_response_code(404); header('Location: blogs.php'); exit; }
}

$posts = $post ? [] : load_md_posts(__DIR__ . '/../../content/blogs');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $post ? htmlspecialchars($post['title']) . ' — ' : '' ?>Blogs — @6chan</title>
<link rel="icon" type="image/x-icon" href="../assets/favicon.ico">
<link rel="stylesheet" href="../style.css">
<script src="../settingsloader.js"></script>
<style>
.blogs-wrap { max-width: 720px; margin: 0 auto; padding: 12px; }

.blog-card {
    background: var(--surface);
    border: 1px solid var(--border);
    margin-bottom: 12px;
    transition: border-color .15s;
    display: grid;
    grid-template-columns: 1fr;
}
.blog-card:hover { border-color: var(--orange); }
.blog-card.has-banner {
    grid-template-columns: 180px 1fr;
}
@media (max-width: 480px) {
    .blog-card.has-banner { grid-template-columns: 1fr; }
}
.blog-banner {
    width: 180px;
    height: 100%;
    min-height: 120px;
    object-fit: cover;
    display: block;
    background: #111;
    border-right: 1px solid var(--border);
}
@media (max-width: 480px) {
    .blog-banner {
        width: 100%;
        height: 120px;
        border-right: none;
        border-bottom: 1px solid var(--border);
    }
}
.blog-card-body { padding: 12px 16px; }
.blog-card-title {
    font-size: 14px;
    font-weight: bold;
    margin-bottom: 5px;
}
.blog-card-title a { color: var(--white); text-decoration: none; }
.blog-card-title a:hover { color: var(--orange); }
.blog-meta {
    font-size: 11px;
    color: var(--grey);
    margin-bottom: 8px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.blog-summary {
    font-size: 12px;
    color: #bbb;
    line-height: 1.65;
    margin-bottom: 8px;
}
.blog-read-more {
    font-size: 12px;
    color: var(--orange);
}
.blog-read-more:hover { text-decoration: underline; }

/* ── Single post ── */
.blog-post-banner {
    width: 100%;
    max-height: 260px;
    object-fit: cover;
    border: 1px solid var(--border);
    margin-bottom: 16px;
    display: block;
}
.blog-post-header {
    background: var(--surface);
    border: 1px solid var(--border);
    border-left: 4px solid var(--orange);
    padding: 12px 16px;
    margin-bottom: 16px;
}
.blog-post-title {
    color: var(--orange);
    font-size: 17px;
    font-weight: bold;
    margin-bottom: 5px;
}
.blog-post-meta {
    font-size: 12px;
    color: var(--grey);
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
}
.blog-post-body {
    background: var(--surface);
    border: 1px solid var(--border);
    padding: 16px 20px;
    font-size: 13px;
    line-height: 1.8;
}
.blog-post-body h1,
.blog-post-body h2,
.blog-post-body h3 {
    color: var(--orange);
    border-bottom: 1px solid var(--border);
    padding-bottom: 3px;
    margin: 14px 0 8px;
}
.blog-post-body p   { margin: 0 0 10px; color: var(--white); }
.blog-post-body img {
    max-width: 100%;
    border: 1px solid var(--border);
    margin: 8px 0;
}
.blog-post-body code {
    background: #111;
    border: 1px solid var(--border);
    color: #e6a817;
    font: 12px 'Courier New',monospace;
    padding: 1px 5px;
}
.blog-post-body pre {
    background: #111;
    border: 1px solid var(--border);
    padding: 10px;
    overflow-x: auto;
}
.blog-post-body blockquote {
    border-left: 3px solid var(--orange);
    padding-left: 12px;
    color: var(--grey);
    margin: 8px 0;
}
.blog-post-body ul,
.blog-post-body ol { padding-left: 20px; margin: 6px 0; color: var(--white); }
.blog-post-body a  { color: var(--link); }

.blogs-empty {
    color: var(--grey);
    font-size: 13px;
    padding: 20px 0;
    text-align: center;
}
.submit-note {
    background: var(--surface);
    border: 1px solid var(--border);
    border-left: 3px solid var(--orange);
    padding: 10px 14px;
    font-size: 12px;
    color: var(--grey);
    margin-bottom: 14px;
    line-height: 1.7;
}
.submit-note a { color: var(--orange); }
</style>
</head>
<body>

<div id="topbar">
    <span class="site-name">@6chan</span>
    <a href="../index.php">Home</a>
    <a href="../rules.html">[Rules]</a>
    <a href="../search.php">[Search]</a>
    <a href="../news/news.php">[News]</a>
    <a href="../support.html">[Support]</a>
    <a href="../contact.php">[Contact]</a>
</div>

<div class="blogs-wrap">

<?php if ($post): ?>
    <div class="page-header">
        <h1>Blogs</h1>
        <p><a href="blogs.php" style="color:var(--orange);">[← All posts]</a></p>
    </div>

    <?php if ($post['banner']): ?>
    <img src="<?= htmlspecialchars($post['banner']) ?>"
         alt="Banner" class="blog-post-banner">
    <?php endif; ?>

    <div class="blog-post-header">
        <div class="blog-post-title"><?= htmlspecialchars($post['title']) ?></div>
        <div class="blog-post-meta">
            <?php if ($post['date']): ?>
                <span><?= htmlspecialchars($post['date']) ?></span> |
            <?php endif; ?>
            <span><?= htmlspecialchars($post['author']) ?></span>
        </div>
    </div>

    <div class="blog-post-body post-body">
        <?= $post['body_html'] ?>
    </div>

    <div style="margin-top:12px;font-size:13px;">
        <a href="blogs.php" style="color:var(--orange);">[← Back to all posts]</a>
    </div>

<?php else: ?>
    <div class="page-header">
        <h1>Blogs</h1>
        <p>Long-form writing from the @6chan community and staff.</p>
    </div>

    <div class="submit-note">
        Want to publish a blog post on @6chan?
        <a href="../contact.php">Contact the staff</a> with your draft.
        We accept writing on tech, culture, philosophy, and anything worth reading.
    </div>

    <?php if (empty($posts)): ?>
        <div class="blogs-empty">
            No blog posts yet.<br>
            <span style="font-size:11px;color:var(--border);">
                (Add .md files to content/blogs/ to publish)
            </span>
        </div>
    <?php else: ?>
        <?php foreach ($posts as $p): ?>
        <div class="blog-card <?= $p['banner'] ? 'has-banner' : '' ?>">
            <?php if ($p['banner']): ?>
            <a href="blogs.php?slug=<?= urlencode($p['slug']) ?>">
                <img src="<?= htmlspecialchars($p['banner']) ?>"
                     alt="Banner" class="blog-banner" loading="lazy">
            </a>
            <?php endif; ?>
            <div class="blog-card-body">
                <div class="blog-card-title">
                    <a href="blogs.php?slug=<?= urlencode($p['slug']) ?>">
                        <?= htmlspecialchars($p['title']) ?>
                    </a>
                </div>
                <div class="blog-meta">
                    <?php if ($p['date']): ?>
                        <span><?= htmlspecialchars($p['date']) ?></span> |
                    <?php endif; ?>
                    <span><?= htmlspecialchars($p['author']) ?></span>
                </div>
                <?php if ($p['summary']): ?>
                <div class="blog-summary"><?= htmlspecialchars($p['summary']) ?></div>
                <?php endif; ?>
                <a href="blogs.php?slug=<?= urlencode($p['slug']) ?>"
                   class="blog-read-more">[Read more →]</a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php endif; ?>
</div>

<footer>
    @6chan — <a href="../index.php">Home</a> —
    <a href="../news/news.php">News</a> —
    <a href="../contact.php">Contact</a>
</footer>
</body>
</html>
