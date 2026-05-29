<?php
// Reads .md files from a directory, parses YAML-style frontmatter,
// renders body via CommonMark. No DB needed.

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Parse a markdown file with optional frontmatter.
 *
 * Frontmatter format (between --- delimiters):
 * ---
 * title: Post Title
 * date: 2026-05-18
 * author: User
 * banner: /uploads/banner.png
 * summary: A short description shown in the list view.
 * pinned: true
 * ---
 *
 * Returns array with keys:
 *   slug, title, date, author, banner, summary, pinned, body_html, raw_body, file
 */
function parse_md_post(string $filepath): ?array {
    if (!file_exists($filepath) || !is_readable($filepath)) return null;

    $content = file_get_contents($filepath);
    $slug    = pathinfo($filepath, PATHINFO_FILENAME);

    $meta = [
        'slug'    => $slug,
        'title'   => $slug,
        'date'    => null,
        'author'  => 'Admin',
        'banner'  => null,
        'summary' => null,
        'pinned'  => false,
        'file'    => $filepath,
    ];

    $body = $content;

    if (str_starts_with(ltrim($content), '---')) {
        $content  = ltrim($content);
        $end      = strpos($content, '---', 3);
        if ($end !== false) {
            $fm_raw   = substr($content, 3, $end - 3);
            $body     = ltrim(substr($content, $end + 3));

            foreach (explode("\n", $fm_raw) as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) continue;
                [$key, $val] = array_map('trim', explode(':', $line, 2));
                $key = strtolower($key);
                switch ($key) {
                    case 'title':   $meta['title']   = $val; break;
                    case 'date':    $meta['date']    = $val; break;
                    case 'author':  $meta['author']  = $val; break;
                    case 'banner':  $meta['banner']  = $val; break;
                    case 'summary': $meta['summary'] = $val; break;
                    case 'pinned':  $meta['pinned']  = in_array(strtolower($val), ['true','yes','1']); break;
                }
            }
        }
    }

    // Autogenerates summary from first paragraph if not set
    if (!$meta['summary'] && $body !== '') {
        $first_para = strtok($body, "\n\n");
        // Strip markdown syntax for plain text summary
        $plain = preg_replace('/[#*_`\[\]()>~]/', '', $first_para ?? '');
        $meta['summary'] = mb_substr(trim($plain), 0, 160);
    }

    require_once __DIR__ . '/markdown.php';
    $meta['body_html'] = render_markdown($body);
    $meta['raw_body']  = $body;

    return $meta;
}

function load_md_posts(string $dir): array {
    if (!is_dir($dir)) return [];

    $posts = [];
    foreach (glob($dir . '/*.md') as $file) {
        $post = parse_md_post($file);
        if ($post) $posts[] = $post;
    }

    // Sort: pinned first, then by date descending, then filename
    usort($posts, function($a, $b) {
        if ($a['pinned'] !== $b['pinned']) return $a['pinned'] ? -1 : 1;
        $da = $a['date'] ? strtotime($a['date']) : 0;
        $db = $b['date'] ? strtotime($b['date']) : 0;
        if ($da !== $db) return $db <=> $da;
        return strcmp($b['slug'], $a['slug']);
    });

    return $posts;
}
