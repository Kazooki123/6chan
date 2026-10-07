<?php

/**
 * ROBOT 9000 (R9K)
 * 
 * R9K is an open source chat moderation tool that forces originality by checking messages against a history log.
 * Duplicated text gets delete, and then it increases the timeout of the user.
 * All thanks to Randall Munroe.
 */
define('R9K_MIN_LENGTH', 12);

function r9k_bool(mixed $val): bool {
    if (is_bool($val)) return $val;
    if (is_int($val))  return $val !== 0;
    if (is_string($val)) return in_array(strtolower($val), ['t', 'true', '1', 'yes'], true);
    return (bool)$val;
}

/**
 * Normalizes post text for comparison: case-folded, quotelinks stripped,
 * punctuation/symbols stripped, whitespace collapsed. Two posts that
 * differ only in capitalization, punctuation, or a quoted post number
 * are treated as the same post.
 */
function r9k_normalize(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/>>\d+/', '', $text);            // quotelinks
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);  // punctuation/symbols
    $text = preg_replace('/\s+/', ' ', $text);              // collapse whitespace
    return trim($text);
}

function r9k_hash(string $normalized): string {
    return hash('sha256', $normalized);
}

function r9k_is_duplicate(PDO $db, int $board_id, string $body): bool {
    $normalized = r9k_normalize($body);
    if (mb_strlen($normalized) < R9K_MIN_LENGTH) {
        return false;
    }

    $stmt = $db->prepare('SELECT 1 FROM robot9k_hashes WHERE board_id = ? AND hash = ?');
    $stmt->execute([$board_id, r9k_hash($normalized)]);
    return (bool)$stmt->fetchColumn();
}

function r9k_record(PDO $db, int $board_id, string $body): void {
    $normalized = r9k_normalize($body);
    if (mb_strlen($normalized) < R9K_MIN_LENGTH) {
        return;
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO robot9k_hashes (board_id, hash) VALUES (?, ?) ON CONFLICT (board_id, hash) DO NOTHING'
        );
        $stmt->execute([$board_id, r9k_hash($normalized)]);
    } catch (\PDOException $e) {
        error_log('[robot9k] Failed to record hash: ' . $e->getMessage());
    }
}
