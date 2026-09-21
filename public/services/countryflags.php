<?php

/**
 * Country flag detection for 6chan.
 *
 * This displays a country flag of a user, uses IP API without tracking,
 * optional if they are willing to in settings. 
 * ========================================================================
 * Real countries: looked up from a LOCAL MaxMind GeoLite2-Country database
 * (no network call, no third party ever sees a poster's IP). The flag
 * itself is just the standard Unicode regional-indicator flag emoji built
 * from the ISO code at render time — no image assets, rendered by
 * whatever emoji font the visitor's OS already has (Segoe UI Emoji on
 * Windows, etc).
 *
 * Novelty flags: shown instead of a real country when the IP is private/
 * reserved (localhost, LAN, can't be geolocated anyway) or a known Tor
 * exit node. Deterministically picked from the IP so the same anon keeps
 * the same novelty flag across posts, without needing to store which
 * reason triggered it.
 *
 * Setup required (both need periodic manual re-download):
 *   composer require geoip2/geoip2
 *   Download GeoLite2-Country.mmdb (free MaxMind account) to:
 *     services/geoip/GeoLite2-Country.mmdb
 *   Download the Tor exit list from:
 *     https://check.torproject.org/torbulkexitlist
 *   and save it to:
 *     services/geoip/tor-exit-nodes.txt
 */

define('GEOIP_DB_PATH', __DIR__ . '/geoip/GeoLite2-Country.mmdb');
define('TOR_EXIT_LIST_PATH', __DIR__ . '/geoip/tor-exit-nodes.txt');

const NOVELTY_FLAGS = [
    ['code' => 'pirate',  'name' => 'Pirate',      'emoji' => "\u{1F3F4}\u{200D}\u{2620}\u{FE0F}"],
    ['code' => 'trans',   'name' => 'Trans Pride',  'emoji' => "\u{1F3F3}\u{FE0F}\u{200D}\u{26A7}\u{FE0F}"],
    ['code' => 'ussr',    'name' => 'USSR',        'emoji' => "\u{262D}"],
    ['code' => 'rainbow', 'name' => 'Pride',       'emoji' => "\u{1F3F3}\u{FE0F}\u{200D}\u{1F308}"],
];

/**
 * Detects the flag for an IP at post-creation time. Returns
 * ['code' => ..., 'name' => ...] — either a real ISO country code, or one
 * of the novelty codes above. Called ONCE per post and stored, same as
 * tripcode/author — never recomputed at render time (Tor exit-node status
 * changes daily; the flag should reflect what was true when they posted,
 * not shift on every future page view).
 */
function detect_flag(string $ip): array {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return novelty_flag_for($ip);
    }

    if (is_tor_exit_node($ip)) {
        return novelty_flag_for($ip);
    }

    $country = lookup_country($ip);
    if ($country !== null) {
        return $country;
    }

    return novelty_flag_for($ip);
}

function render_flag_by_code(?string $code, ?string $name): string {
    if (!$code) return '';

    $novelty_lookup = array_column(NOVELTY_FLAGS, 'emoji', 'code');
    $emoji = $novelty_lookup[$code] ?? country_code_to_emoji($code);
    $title = htmlspecialchars($name ?: $code, ENT_QUOTES);

    return "<span class='post-flag' title='{$title}'>{$emoji}</span>";
}

/** Builds a flag emoji from a 2-letter ISO country code via codepoint math. */
function country_code_to_emoji(string $code): string {
    $code = strtoupper($code);
    if (!preg_match('/^[A-Z]{2}$/', $code)) return '';

    $emoji = '';
    foreach (str_split($code) as $c) {
        $emoji .= mb_chr(0x1F1E6 + (ord($c) - 65), 'UTF-8');
    }
    return $emoji;
}

/** Deterministic pick from the novelty pool so a given IP always gets the same one. */
function novelty_flag_for(string $ip): array {
    $pool = NOVELTY_FLAGS;
    $pick = $pool[crc32($ip) % count($pool)];
    return ['code' => $pick['code'], 'name' => $pick['name']];
}

function is_tor_exit_node(string $ip): bool {
    static $set = null;

    if ($set === null) {
        $set = [];
        if (file_exists(TOR_EXIT_LIST_PATH)) {
            $lines = file(TOR_EXIT_LIST_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $set = array_flip($lines);
        }
    }

    return isset($set[$ip]);
}

/** @return array{code: string, name: string}|null */
function lookup_country(string $ip): ?array {
    static $reader = null;
    static $tried  = false;

    if ($reader === null && !$tried) {
        $tried = true;
        if (!file_exists(GEOIP_DB_PATH)) {
            error_log('[countryflags] GeoLite2-Country.mmdb not found at ' . GEOIP_DB_PATH);
        } else {
            try {
                $reader = new \GeoIp2\Database\Reader(GEOIP_DB_PATH);
            } catch (\Throwable $e) {
                error_log('[countryflags] Failed to open GeoIP db: ' . $e->getMessage());
            }
        }
    }

    if ($reader === null) return null;

    try {
        $record = $reader->country($ip);
        $code   = $record->country->isoCode;
        if (!$code) return null;
        return ['code' => $code, 'name' => $record->country->name ?: $code];
    } catch (\Throwable $e) {
        return null;
    }
}
