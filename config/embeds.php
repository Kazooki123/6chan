<?php
/**
 * Open Graph / rich link embeds for 6chan post bodies.
 *
 * Any bare http(s) URL in a post gets a preview card: a real <iframe>
 * for YouTube specifically, a generic Open Graph card (via
 * fusonic/opengraph) for everything else. Results are cached by URL
 * (not by post — many posts can share the same link) via the Redis-
 * backed cache_get/cache_set from config/cache.php, so the same video
 * or article never gets re-fetched/re-parsed on every page view.
 *
 * Requires: composer require fusonic/opengraph guzzlehttp/guzzle
 *
 * SSRF PROTECTION — read this before touching the fetch logic:
 * a post body is attacker-controlled text. Before fetching ANY URL, we
 * resolve its hostname ourselves and reject it if it (or any redirect
 * target along the way) resolves to a private, loopback, or reserved
 * IP range — otherwise a post linking to your own MinIO container,
 * localhost, or a cloud metadata endpoint would make your server
 * dutifully fetch it on the poster's behalf.
 *
 * Known residual risk: this does NOT pin the connection to the specific
 * IP it validated, so a sufficiently-timed DNS-rebinding attack (the
 * hostname resolving differently between our check and the actual
 * connection a few milliseconds later) isn't fully closed. A stronger
 * defense would pin the TCP connection to the validated IP via
 * CURLOPT_RESOLVE — not implemented here, to keep this reviewable; the
 * window is small and this is a hobby imageboard, not a bank, but it's
 * worth knowing this exists if the threat model ever changes.
 */

require_once __DIR__ . '/cache.php';

use Fusonic\OpenGraph\Consumer;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;

const EMBED_MAX_PER_POST       = 2;      // cap how many links in one post get fetched — don't let a post spam a dozen fetch attempts
const EMBED_CACHE_TTL          = 21600;  // 6 hours — OG data for a given URL rarely changes
const EMBED_FETCH_TIMEOUT      = 5;      // seconds
const EMBED_MAX_RESPONSE_BYTES = 2 * 1024 * 1024; // 2MB cap, checked via Content-Length

/**
 * Extracts up to EMBED_MAX_PER_POST distinct bare URLs from raw post
 * text (not the rendered HTML — we want what the user actually typed,
 * before markdown/greentext processing touches it).
 */
function extract_embed_urls(string $text): array {
    if (!preg_match_all('#\bhttps?://[^\s<>"\']+#i', $text, $matches)) {
        return [];
    }

    $urls = [];
    foreach ($matches[0] as $url) {
        $url = rtrim($url, '.,!?)]'); // strip trailing punctuation caught by the greedy match
        if ($url === '' || in_array($url, $urls, true)) continue;
        $urls[] = $url;
        if (count($urls) >= EMBED_MAX_PER_POST) break;
    }
    return $urls;
}

/**
 * Checks whether $host resolves ONLY to public, routable IPs — rejects
 * if any resolved address is private/loopback/link-local/reserved, and
 * rejects on resolution failure too (fail closed, not open).
 */
function host_resolves_to_public_ip(string $host): bool {
    // A bare IP literal in the URL itself — validate it directly.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (!$records) return false;

    foreach ($records as $record) {
        $ip = $record['ip'] ?? $record['ipv6'] ?? null;
        if (!$ip) continue;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false; // at least one resolved address is private/reserved — reject the whole host
        }
    }

    return true;
}

/** Validates a URL is safe to fetch: http(s) only, and resolves entirely to public IPs. */
function is_safe_embed_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) return false;
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) return false;

    $allowed = array_filter(array_map('trim', explode(',', $_ENV['EMBED_ALLOWED_HOSTS'] ?? '')));
    if ($allowed) {
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (in_array(strtolower($parts['host']) . ':' . $port, array_map('strtolower', $allowed), true)) {
            return true;
        }
    }

    return host_resolves_to_public_ip($parts['host']);
}

/** Builds a Guzzle client that re-validates every redirect hop, not just the original URL, and caps response size. */
function embed_http_client(): GuzzleClient {
    $size_guard = Middleware::mapResponse(function ($response) {
        $len = $response->getHeaderLine('Content-Length');
        if ($len !== '' && (int)$len > EMBED_MAX_RESPONSE_BYTES) {
            throw new \RuntimeException('Response too large: ' . $len . ' bytes');
        }
        return $response;
    });

    $stack = HandlerStack::create();
    $stack->push($size_guard);

    return new GuzzleClient([
        'handler'         => $stack,
        'timeout'         => EMBED_FETCH_TIMEOUT,
        'connect_timeout' => EMBED_FETCH_TIMEOUT,
        'http_errors'     => false,
        'headers'         => ['User-Agent' => '6chan-embed-bot/1.0 (+link preview fetcher)'],
        'allow_redirects' => [
            'max'       => 3,
            'protocols' => ['http', 'https'],
            'strict'    => true,
            'referer'   => false,
            'on_redirect' => function ($request, $response, $uri) {
                // Re-validate EVERY redirect target, not just the
                // original URL — otherwise a safe-looking link that
                // 302s to an internal address would sail straight
                // through the check we already did.
                if (!is_safe_embed_url((string)$uri)) {
                    throw new \RuntimeException('Redirect target failed SSRF validation: ' . $uri);
                }
            },
        ],
    ]);
}

function extract_youtube_id(string $url): ?string {
    if (preg_match('#(?:youtube\.com/watch\?v=|youtube\.com/shorts/|youtu\.be/)([A-Za-z0-9_-]{11})#i', $url, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Fetches and parses embed data for a URL, cached by URL (shared across
 * every post that happens to link the same thing). Returns null if the
 * URL is unsafe, the fetch failed, or no usable data was found —
 * callers should render nothing in that case, not an error.
 */
function get_embed_card(string $url, PDO $db): ?array {
    if (!is_safe_embed_url($url)) {
        return null;
    }

    $cache_key = 'embed:' . md5($url);
    $cached    = cache_get($cache_key, $db);
    if ($cached !== null) {
        return $cached ?: null; // cached `false` means "already checked, nothing usable" — still a cache hit, just a negative one
    }

    $card = fetch_embed_card($url);

    // Cache the result either way, including failures/no-data — so a
    // broken or slow link doesn't get re-fetched on every page view.
    cache_set($cache_key, $card ?? false, $db, EMBED_CACHE_TTL);

    return $card;
}

function fetch_embed_card(string $url): ?array {
    $youtube_id = extract_youtube_id($url);
    if ($youtube_id !== null) {
        return ['type' => 'youtube', 'id' => $youtube_id, 'url' => $url];
    }

    try {
        $client   = embed_http_client();
        $factory  = new HttpFactory();
        $consumer = new Consumer($client, $factory);
        $consumer->useFallbackMode = true; // fall back to <title>/<meta description> for sites without proper OG tags

        $object = $consumer->loadUrl($url);

        if (!$object->title && !$object->description) {
            return null;
        }

        return [
            'type'        => 'website',
            'title'       => $object->title,
            'description' => mb_strimwidth($object->description ?? '', 0, 200, '…'),
            'image'       => $object->images[0]->url ?? null,
            'site_name'   => $object->siteName ?: (parse_url($url, PHP_URL_HOST) ?? ''),
            'url'         => $url,
        ];
    } catch (\Throwable $e) {
        error_log('[embeds] Failed to fetch ' . $url . ': ' . $e->getMessage());
        return null;
    }
}

function render_embed_card(array $card): string {
    if ($card['type'] === 'youtube') {
        $id = htmlspecialchars($card['id'], ENT_QUOTES);
        return "
            <div class='embed-card embed-youtube'>
                <iframe
                    src='https://www.youtube-nocookie.com/embed/{$id}'
                    loading='lazy'
                    allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture'
                    allowfullscreen>
                </iframe>
            </div>";
    }

    $title       = htmlspecialchars($card['title'] ?? '', ENT_QUOTES);
    $description = htmlspecialchars($card['description'] ?? '', ENT_QUOTES);
    $site_name   = htmlspecialchars($card['site_name'] ?? '', ENT_QUOTES);
    $link        = htmlspecialchars($card['url'], ENT_QUOTES);

    $image_html = '';
    if (!empty($card['image'])) {
        $img = htmlspecialchars($card['image'], ENT_QUOTES);
        $image_html = "<img src='{$img}' alt='' loading='lazy' class='embed-image'>";
    }

    return "
        <a href='{$link}' target='_blank' rel='noopener noreferrer nofollow' class='embed-card embed-website'>
            {$image_html}
            <div class='embed-text'>
                <div class='embed-site'>{$site_name}</div>
                <div class='embed-title'>{$title}</div>
                <div class='embed-desc'>{$description}</div>
            </div>
        </a>";
}
