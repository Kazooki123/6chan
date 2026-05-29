<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

function media_url(string $key): string {
    $uploads  = $_ENV['S3_UPLOAD'];
    $endpoint = $_ENV['S3_ENDPOINT'];
    
    const STORAGE_BUCKET   = $uploads;
    const STORAGE_ENDPOINT = $endpoint;

    return STORAGE_ENDPOINT . '/' . STORAGE_BUCKET . '/' . $key;
}

function extract_audio_meta(string $filepath, string $filename): array {
    $getID3  = new getID3();
    $info    = $getID3->analyze($filepath);
    getid3_lib::CopyTagsToComments($info);

    $meta = [
        'title'       => null,
        'artist'      => null,
        'album'       => null,
        'duration'    => null,
        'cover_file'  => null,
        'lyrics'      => null,   // plain string
        'lrc'         => null,   // parsed LRC array [{time, line}]
    ];

    $comments = $info['comments'] ?? [];
    $meta['title']  = $comments['title'][0]  ?? null;
    $meta['artist'] = $comments['artist'][0] ?? null;
    $meta['album']  = $comments['album'][0]  ?? null;
    $raw_lyrics     = null;

    if (isset($info['playtime_seconds'])) {
        $secs            = (int)$info['playtime_seconds'];
        $meta['duration'] = sprintf('%d:%02d', intdiv($secs, 60), $secs % 60);
    }

    $cover_data = null;
    $cover_mime = null;

    if (isset($info['id3v2']['APIC'])) {
        $apic       = reset($info['id3v2']['APIC']);
        $cover_data = $apic['data'];
        $cover_mime = $apic['image_mime'] ?? 'image/jpeg';
    }

    elseif (isset($comments['picture'][0]['data'])) {
        $p          = $comments['picture'][0];
        $cover_data = $p['data'];
        $cover_mime = $p['image_mime'] ?? 'image/jpeg';
    }

    if ($cover_data) {
        $ext_map    = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];
        $cover_ext  = $ext_map[$cover_mime] ?? 'jpg';
        $cover_name = 'cover_' . pathinfo($filename, PATHINFO_FILENAME) . '.' . $cover_ext;
        $cover_path = media_url($cover_name);

        file_put_contents($cover_path, $cover_data);
        chmod($cover_path, 0644);
        $meta['cover_file'] = $cover_name;
    }

    if (isset($info['id3v2']['USLT'])) {
        foreach ($info['id3v2']['USLT'] as $frame) {
            $candidate = $frame['data']   ?? null;
            if (!$candidate) $candidate = $frame['lyrics'] ?? null;
            if (!$candidate) $candidate = $frame['text']   ?? null;

            if ($candidate && trim($candidate) !== '') {
                $raw_lyrics = $candidate;
                break;
            }

            if (
                isset($frame['dataoffset'], $frame['datalength'])
                && (int)$frame['datalength'] > 10
                && ($frame['encodingid'] ?? 0) === 1
            ) {
                $fh = fopen($filepath, 'rb');
                if ($fh) {
                    fseek($fh, (int)$frame['dataoffset'], SEEK_SET);
                    $raw = fread($fh, (int)$frame['datalength']);
                    fclose($fh);

                    if ($raw !== false && strlen($raw) > 4) {
                        $payload = substr($raw, 4);

                        $desc_end = strpos($payload, "\x00\x00");
                        if ($desc_end !== false) {
                            $lyrics_utf16 = substr($payload, $desc_end + 2);
                        } else {
                            $lyrics_utf16 = $payload;
                        }

                        if (strlen($lyrics_utf16) >= 2) {
                            $bom = substr($lyrics_utf16, 0, 2);
                            if ($bom === "\xFF\xFF") {
                                $decoded = mb_convert_encoding(
                                    substr($lyrics_utf16, 2), 'UTF-8', 'UTF-16LE'
                                );
                            } elseif ($bom === "\xFE\xFF") {
                                $decoded = mb_convert_encoding(
                                    subtr($lyrics_utf16, 2), 'UTF-8', 'UTF-16BE'
                                );
                            } else {
                                $decoded = mb_convert_encoding(
                                    $lyrics_utf16, 'UTF-8', 'UTF-16LE'
                                );
                            }

                            if ($decoded && trim($decoded) !== '') {
                                $raw_lyrics = $decoded;
                            }
                        }
                    }
                }
            }
            
            $raw_lyrics = $frame['data']
                       ?? $frame['lyrics']
                       ?? $frame['text']
                       ?? null;
            if ($raw_lyrics !== null && trim($raw_lyrics) !== '') break;
        }
    }

    if (!$raw_lyrics && !empty($comments['lyrics'][0])) {
        $raw_lyrics = $comments['lyrics'][0];
    }

    if (!$raw_lyrics && !empty($comments['unsyncedlyrics'][0])) {
        $raw_lyrics = $comments['unsyncedlyrics'][0];
    }

    if (!$raw_lyrics && !empty($info['tags']['id3v2']['lyrics'][0])) {
        $raw_lyrics = $info['tags']['id3v2']['lyrics'][0];
    }

    if ($raw_lyrics && trim($raw_lyrics) !== '') {
        $raw_lyrics = str_replace("\x00", '', $raw_lyrics);
        $raw_lyrics = trim($raw_lyrics);
        
        if (preg_match('/\[\d+:\d+/', $raw_lyrics)) {
            $meta['lrc']    = parse_lrc($raw_lyrics);
            $meta['lyrics'] = null;
        } else {
            $meta['lyrics'] = $raw_lyrics;
            $meta['lrc']    = null;
        }
    }
    
    if (!$raw_lyrics && isset($info['tags']['id3v2']['SYLT'])) {
        $sylt = reset($info['tags']['id3v2']['SYLT']);
        if (!empty($sylt['data'])) {
            $lines = [];
            foreach ($sylt['data'] as $entry) {
                $lines[] = [
                    'time' => ($entry['timestampformat'] ?? 0) / 1000,
                    'line' => $entry['text'] ?? '',
                ];
            }
            $meta['lrc'] = $lines;
        }
    }

    // if (!$meta['lyrics'] && !$meta['lrc'] && isset($comments['lyrics'][0])) {
    //     $raw = $comments['lyrics'][0];
    //     if (preg_match('/\[\d+:\d+/', $raw)) {
    //         $meta['lrc'] = parse_lrc($raw);
    //     } else {
    //         $meta['lyrics'] = $raw;
    //     }
    // }

    return $meta;
}


function parse_lrc(string $lrc): array {
    $lines  = explode("\n", $lrc);
    $parsed = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Match all timestamps on a line (one line can have multiple)
        if (!preg_match_all('/\[(\d{1,3}):(\d{2})(?:\.(\d{1,3}))?\]/', $line, $matches, PREG_SET_ORDER)) {
            continue;
        }

        // Strip timestamps to get the lyric text
        $text = trim(preg_replace('/\[\d+:\d+(?:\.\d+)?\]/', '', $line));
        if ($text === '') continue;

        foreach ($matches as $m) {
            $minutes  = (int)$m[1];
            $seconds  = (int)$m[2];
            $centis   = isset($m[3]) ? $m[3] : '0';
            $frac     = (float)('0.' . str_pad($centis, 2, '0'));
            $time     = $minutes * 60 + $seconds + $frac;

            $parsed[] = ['time' => $time, 'line' => $text];
        }
    }

    usort($parsed, fn($a, $b) => $a['time'] <=> $b['time']);

    return $parsed;
}
