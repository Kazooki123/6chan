#!/usr/bin/env php
<?php
$autoload_paths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
$loaded = false;
foreach ($autoload_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        echo "✔ Composer autoload found at: $path\n\n";
        break;
    }
}
if (!$loaded) {
    die("✘ FATAL: vendor/autoload.php not found.\n"
      . "  Run: composer require james-heinrich/getid3\n"
      . "  from your imageboard root directory.\n");
}

if (!class_exists('getID3')) {
    die("✘ FATAL: getID3 class not found even though autoload loaded.\n"
      . "  Try: composer require james-heinrich/getid3\n");
}
echo "✔ getID3 class found\n\n";

$file = $argv[1] ?? null;
if (!$file) {
    die("Usage: php debug_audio.php path/to/audio.mp3\n");
}
if (!file_exists($file)) {
    die("✘ File not found: $file\n");
}

echo "── File ─────────────────────────────────────────────────────────────\n";
echo "Path:      $file\n";
echo "Size:      " . number_format(filesize($file)) . " bytes\n";
echo "Readable:  " . (is_readable($file) ? '✔ yes' : '✘ NO') . "\n\n";

$getID3 = new getID3();
$info   = $getID3->analyze($file);
getid3_lib::CopyTagsToComments($info);

echo "── Format ───────────────────────────────────────────────────────────\n";
echo "File format:  " . ($info['fileformat']        ?? '(none)') . "\n";
echo "Audio codec:  " . ($info['audio']['dataformat'] ?? '(none)') . "\n";
echo "Duration:     " . ($info['playtime_string']   ?? '(none)') . "\n";
echo "Bitrate:      " . (isset($info['audio']['bitrate'])
                           ? round($info['audio']['bitrate'] / 1000) . ' kbps'
                           : '(none)') . "\n\n";

echo "── Raw tags found ───────────────────────────────────────────────────\n";
$tag_keys = ['id3v1','id3v2','ape','vorbiscomment','quicktime','matroska'];
$found_tags = false;
foreach ($tag_keys as $k) {
    if (!empty($info['tags'][$k])) {
        echo "  [$k] present\n";
        $found_tags = true;
    }
    if (!empty($info[$k])) {
        echo "  \$info['$k'] present\n";
    }
}
if (!$found_tags) {
    echo "  (no tag blocks detected — file may have no ID3 tags)\n";
}
echo "\n";

echo "── Unified comments (after CopyTagsToComments) ──────────────────────\n";
$comments = $info['comments'] ?? [];
if (empty($comments)) {
    echo "  (empty — no tags were found or copied)\n";
} else {
    $important = ['title','artist','album','year','genre','lyrics','comment'];
    foreach ($important as $k) {
        if (isset($comments[$k])) {
            $val = is_array($comments[$k]) ? $comments[$k][0] : $comments[$k];
            $preview = mb_substr((string)$val, 0, 120);
            echo "  $k: " . json_encode($preview) . "\n";
        }
    }
    // Show any other keys
    foreach ($comments as $k => $v) {
        if (!in_array($k, $important) && $k !== 'picture') {
            $val = is_array($v) ? $v[0] : $v;
            echo "  $k: " . json_encode(mb_substr((string)$val, 0, 80)) . "\n";
        }
    }
}
echo "\n";

echo "── Cover art ────────────────────────────────────────────────────────\n";
$cover_found = false;

// Check id3v2 APIC
if (isset($info['id3v2']['APIC'])) {
    $apic = reset($info['id3v2']['APIC']);
    echo "  ✔ id3v2 APIC frame found\n";
    echo "  MIME:  " . ($apic['image_mime'] ?? '?') . "\n";
    echo "  Size:  " . number_format(strlen($apic['data'] ?? '')) . " bytes\n";
    echo "  Type:  " . ($apic['picturetypeid'] ?? '?')
                     . " (" . ($apic['picture_type'] ?? '?') . ")\n";
    $cover_found = true;
}

if (!empty($comments['picture'])) {
    foreach ($comments['picture'] as $i => $p) {
        echo "  ✔ comments[picture][$i] found\n";
        echo "  MIME:  " . ($p['image_mime'] ?? '?') . "\n";
        echo "  Size:  " . number_format(strlen($p['data'] ?? '')) . " bytes\n";
        $cover_found = true;
    }
}

if (!$cover_found) {
    echo "  ✘ No cover art found in this file\n";
    echo "  Tip: Use a tag editor (Mp3tag, MusicBrainz Picard) to embed cover art\n";
}
echo "\n";

echo "── Lyrics ───────────────────────────────────────────────────────────\n";
$lyrics_found = false;

// USLT
if (isset($info['id3v2']['USLT'])) {
    $uslt = reset($info['id3v2']['USLT']);
    $raw  = $uslt['data'] ?? '';
    echo "  ✔ USLT (unsynchronised lyrics) frame found\n";
    echo "  Length:   " . strlen($raw) . " chars\n";
    echo "  Language: " . ($uslt['language'] ?? '?') . "\n";
    $is_lrc = (bool)preg_match('/\[\d+:\d+/', $raw);
    echo "  Format:   " . ($is_lrc ? 'LRC (timestamped)' : 'plain text') . "\n";
    echo "  Preview:  " . json_encode(mb_substr($raw, 0, 200)) . "\n";
    $lyrics_found = true;
}

// SYLT
if (isset($info['id3v2']['SYLT'])) {
    $sylt = reset($info['id3v2']['SYLT']);
    echo "  ✔ SYLT (synchronised lyrics) frame found\n";
    echo "  Lines: " . count($sylt['data'] ?? []) . "\n";
    if (!empty($sylt['data'])) {
        $first = $sylt['data'][0];
        echo "  First: [" . ($first['milliseconds'] ?? 0) . "ms] "
                          . json_encode($first['text'] ?? '') . "\n";
    }
    $lyrics_found = true;
}

// Plain lyrics in comments
if (!empty($comments['lyrics'])) {
    $raw = is_array($comments['lyrics']) ? $comments['lyrics'][0] : $comments['lyrics'];
    echo "  ✔ lyrics in comments found\n";
    echo "  Preview: " . json_encode(mb_substr($raw, 0, 200)) . "\n";
    $lyrics_found = true;
}

if (!$lyrics_found) {
    echo "  ✘ No lyrics found in this file\n";
    echo "  Tip: Embed lyrics with Mp3tag → Extended Tags → Add USLT frame\n";
    echo "       Or use an .lrc file upload in the post form\n";
}
echo "\n";

echo "── Raw USLT frame dumpmaxxing ──\n";
if (isset($info['id3v2']['USLT'])) {
    foreach ($info['id3v2']['USLT'] as $i => $frame) {
        echo " Frame $i keys: " . implode(', ', array_keys($frame)) . "\n";
        foreach ($frame as $k => $v) {
            echo " $k length: " . strlen((string)$v) . "\n";
            echo " $k preview: " . json_encode(mb_substr((string)$v, 0, 150)) . "\n";
        }
    }
} else {
    echo " USLT not in \$info['id3v2'] at all\n";
}

if (isset($info['tags']['id3v2']['SYLT'])) {
    foreach ($info['tags']['id3v2']['SYLT'] as $i => $frame) {
        echo " Frame $i keys: " . implode(', ', array_keys($frame)) . "\n";
        foreach ($frame as $k => $v) {
            echo " $k length: " . strlen((string)$v) . "\n";
            echo " $k preview: " . json_encode(mb_substr((string)$v, 0, 150)) . "\n";
        }
    }
} else {
    echo " SYLT not in \$info['id3v2'] at all\n";
}

echo "\n── All id3v2 frame keys present ──\n";
if (isset($info['id3v2'])) {
    echo " " . implode(', ', array_keys($info['id3v2'])) . "\n";
}

echo "\n";

echo "── Sidecar .json check ──\n";
$sidecar = $file . '.json';
if (file_exists($sidecar)) {
    echo "  ✔ Sidecar found: $sidecar\n";
    $data = json_decode(file_get_contents($sidecar), true);
    if (!$data) {
        echo "  ✘ Sidecar exists but JSON is invalid!\n";
    } else {
        echo "  title:      " . json_encode($data['title']      ?? null) . "\n";
        echo "  artist:     " . json_encode($data['artist']     ?? null) . "\n";
        echo "  album:      " . json_encode($data['album']      ?? null) . "\n";
        echo "  duration:   " . json_encode($data['duration']   ?? null) . "\n";
        echo "  cover_file: " . json_encode($data['cover_file'] ?? null) . "\n";
        echo "  lrc lines:  " . (isset($data['lrc']) ? count($data['lrc']) : 'null') . "\n";
        echo "  lyrics len: " . (isset($data['lyrics']) ? strlen($data['lyrics']) : 'null') . "\n";

        if (!empty($data['cover_file'])) {
            $cover_path = dirname($file) . '/' . $data['cover_file'];
            echo "  cover path: $cover_path\n";
            echo "  cover exists: " . (file_exists($cover_path) ? '✔ yes' : '✘ NO') . "\n";
        }
    }
} else {
    echo "  ✘ No sidecar found at: $sidecar\n";
    echo "  This means extract_audio_meta() never ran, or failed silently.\n";
    echo "  Check: does config/audio.php get required in post.php?\n";
    echo "  Check: is the upload path correct (__DIR__ . '/uploads/')?\n";
}
echo "\n";

echo "── PHP extensions ──\n";
$exts = ['fileinfo', 'json', 'mbstring', 'gd', 'imagick'];
foreach ($exts as $ext) {
    echo "  " . ($ext === ('imagick' ? class_exists('Imagick') : extension_loaded($ext))
        ? "✔ $ext" : "✘ $ext (missing)") . "\n";
}
echo "\n";

echo "── Uploads directory ──\n";
$uploads_candidates = [
    dirname($file),
    __DIR__ . '/uploads',
    __DIR__ . '/../public/uploads',
];
foreach ($uploads_candidates as $dir) {
    if (is_dir($dir)) {
        echo "  $dir\n";
        echo "  writable: " . (is_writable($dir) ? '✔ yes' : '✘ NO — chmod 775') . "\n";
    }
}
echo "\n";

echo "── Done ─────────────────────────────────────────────────────────────\n";
echo "If cover/lyrics show ✔ above but don't render in 6chan:\n";
echo "  1. Check the sidecar .json path matches what render_media() reads\n";
echo "  2. Check uploads/ path in audio.php (__DIR__ . '/../public/uploads/')\n";
echo "  3. Verify the cover image URL is correct in the browser devtools\n";
