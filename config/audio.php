<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__ . '/../.env'));
$dotenv->load();

function extract_audio_meta(string $filepath, string $filename): array {
    $getID3  = new getID3();
    $info    = $getID3->analyze($filepath);
    getid3_lib::CopyTagsToComments($info);

    $s3key    = $_ENV['S3_KEY'];
    $s3secret = $_ENV['S3_SECRET'];

    $meta = [
        'title'       => null,
        'artist'      => null,
        'album'       => null,
        'duration'    => null,
        'cover_file'  => null,
        'lyrics'      => null,   // plain string
        'lrc'         => null,   // parsed LRC array [{time, line}]
    ];

    $s3 = new S3Client([
        'version'  => 'latest',
        'region'   => 'us-east-1',
        'endpoint' => 'http://localhost:9000', // Endpoint will change once deployed
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key'    => $s3key,
            'secret' => $s3secret,
        ],
    ]);
    
    $bucket = '6chan';

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
        $cover_key  = 'covers/' . $cover_name;

        $s3->putObject([
            'Bucket'      => $bucket,
            'Key'         => $cover_key,
            'Body'        => $cover_data,
            'ContentType' => $cover_mime,
        ]);
        
        $meta['cover_file'] = $cover_key;
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
                                    substr($lyrics_utf16, 2), 'UTF-8', 'UTF-16BE'
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
    
    if (!$raw_lyrics && isset($info['id3v2']['SYLT'])) {
        foreach ($info['id3v2']['SYLT'] as $sylt) {
            $lines  = [];
            $format = $sylt['timestampformat'] ?? 2; // 1 = MPEG frames, 2 = milliseconds

            // Preferred path: getID3's own SYLT parser (module.tag.id3v2.php)
            // structures entries under $frame['lyrics'][i]['data'/'timestamp'].
            // It does NOT convert the text to UTF-8 for us (unlike USLT), so
            // we'll just decode each of the lines ourselves using the frames `encodingid`.
            if (!empty($sylt['lyrics']) && is_array($sylt['lyrics']) && $format == 2) {
                foreach ($sylt['lyrics'] as $entry) {
                    $text = $entry['data'] ?? '';
                    if (trim($text) === '') continue;
                    $lines[] = [
                        'time' => ($entry['timestamp'] ?? 0) / 1000,
                        'line' => sylt_decode_text($text, $sylt['encodingid'] ?? 0),
                    ];
                }
            }

            // Fallback
            if (empty($lines) && isset($sylt['dataoffset'], $sylt['datalength'])) {
                $parsed = parse_sylt_raw($filepath, (int)$sylt['dataoffset'], (int)$sylt['datalength']);
                if ($parsed && $parsed['format'] == 2) {
                    foreach ($parsed['entries'] as $entry) {
                        if (trim($entry['text']) === '') continue;
                        $lines[] = [
                            'time' => $entry['timestamp'] / 1000,
                            'line' => $entry['text'],
                        ];
                    }
                }
            }

            if (!empty($lines)) {
                usort($lines, fn($a, $b) => $a['time'] <=> $b['time']);
                $meta['lrc'] = $lines;
                break;
            }
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

/**
 * Decodes a raw SYLT lyric-line string according to its ID3v2 text-encoding id.
 *   0 = ISO-8859-1
 *   1 = UTF-16 with BOM
 *   2 = UTF-16BE without BOM
 *   3 = UTF-8 (ID3v2.4 only)
 */
function sylt_decode_text(string $text, int $encodingid): string {
    switch ($encodingid) {
        case 1:
            return trim((string)mb_convert_encoding($text, 'UTF-8', 'UTF-16'));
        case 2:
            return trim((string)mb_convert_encoding($text, 'UTF-8', 'UTF-16BE'));
        case 3:
            return trim($text); // already UTF-8
        case 0:
        default:
            return trim((string)mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1'));
    }
}

/**
 * Manually parses a raw SYLT frame's payload straight from the file.
 * Layout (per ID3v2 spec, matches getid3_write_id3v2's SYLT encoder):
 *   encoding(1) + language(3) + timestampformat(1) + contenttype(1)
 *   + description + terminator
 *   + repeated: [syllable text][terminator][timestamp, 4 bytes BE]
 */
function parse_sylt_raw(string $filepath, int $offset, int $length): ?array {
    if ($length < 6) return null;

    $fh = fopen($filepath, 'rb');
    if (!$fh) return null;
    fseek($fh, $offset, SEEK_SET);
    $raw = fread($fh, $length);
    fclose($fh);

    if ($raw === false || strlen($raw) < 6) return null;

    $pos         = 0;
    $encodingid  = ord($raw[$pos]); $pos += 1;
    $pos        += 3;
    $tsformat    = ord($raw[$pos]); $pos += 1;
    $pos        += 1;

    $is_utf16 = ($encodingid === 1 || $encodingid === 2);
    $termlen  = $is_utf16 ? 2 : 1;

    // Skip the content descriptor field
    $descEnd = $is_utf16 ? strpos($raw, "\x00\x00", $pos) : strpos($raw, "\x00", $pos);
    if ($descEnd === false) return null;
    $pos = $descEnd + $termlen;

    $len     = strlen($raw);
    $entries = [];

    while ($pos < $len) {
        $textEnd = $is_utf16 ? strpos($raw, "\x00\x00", $pos) : strpos($raw, "\x00", $pos);
        if ($textEnd === false) break;

        $textRaw = substr($raw, $pos, $textEnd - $pos);
        $pos     = $textEnd + $termlen;

        if ($pos + 4 > $len) break;
        $unpacked  = unpack('N', substr($raw, $pos, 4));
        $timestamp = $unpacked[1];
        $pos      += 4;

        $entries[] = [
            'timestamp' => $timestamp,
            'text'      => sylt_decode_text($textRaw, $encodingid),
        ];
    }

    return ['format' => $tsformat, 'entries' => $entries];
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
