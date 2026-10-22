<?php
// Caching for 6chan's threads and posts — Redis-backed when available,
// with a transparent fallback to the original Postgres `cache` table.
//
// Same cache_get/cache_set/cache_delete/cache_flush_expired signatures
// as before this change — nothing that already calls this file needs
// to be touched.
//
// Works with EITHER the phpredis PECL extension (class Redis) or the
// pure-PHP Predis library (Predis\Client), whichever is actually
// installed — detected once per request, automatically. If neither is
// available, or Redis errors out at any point, every call transparently
// falls back to the Postgres table exactly as before. Redis going down
// mid-request never breaks a page — it just gets a bit slower, never
// an error.
//
// Optional env vars: REDIS_HOST (default 127.0.0.1), REDIS_PORT
// (default 6379). Not required — if unset, it just tries localhost,
// and falls back to Postgres-only if nothing's listening there.

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__ . '/../.env'));
$dotenv->load();

function cache_redis(): mixed {
    static $client = false;

    if ($client !== false) {
        return $client;
    }

    $host     = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
    $port     = (int)($_ENV['REDIS_PORT'] ?? 6379);
    $password = $_ENV['REDIS_PASSWORD'] ?? null;

    try {
        if (class_exists('Redis')) {
            $redis = new Redis();
            $redis->connect($host, $port, 0.5); // 500ms connect timeout — fail fast, don't hang a page load waiting on a dead Redis
            if ($password !== null && $password !== '') {
                $redis->auth($password);
            }
            $client = $redis;
        } elseif (class_exists('Predis\Client')) {
            // Predis (pure PHP, composer-installed) — works anywhere PHP
            // runs with no extension/DLL needed.
            $client = new Predis\Client(array_filter([
                'host'                => $host,
                'port'                => $port,
                'timeout'             => 0.5,
                'read_write_timeout'  => 0.5,
                'password'            => $password,
            ], fn($v) => $v !== null && $v !== ''));
            $client->connect();
        } else {
            $client = null;
        }
    } catch (\Throwable $e) {
        error_log('[cache] Redis unavailable, falling back to Postgres: ' . $e->getMessage());
        $client = null;
    }

    return $client;
}

function cache_get(string $key, PDO $db): mixed {
    $redis = cache_redis();

    if ($redis) {
        try {
            $raw = $redis->get($key);
            if ($raw !== false && $raw !== null) {
                return json_decode($raw, true);
            }
            return null;
        } catch (\Throwable $e) {
            error_log('[cache] Redis GET failed, falling back to Postgres: ' . $e->getMessage());
        }
    }

    $stmt = $db->prepare(
        "SELECT value FROM cache
         WHERE key = ?
         AND (expires_at IS NULL OR expires_at > NOW())"
    );
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return json_decode($row['value'], true);
}

function cache_set(string $key, mixed $value, PDO $db, int $ttl_seconds = 300): void {
    $json = json_encode($value);

    $redis = cache_redis();
    if ($redis) {
        try {
            $redis->setex($key, $ttl_seconds, $json);
        } catch (\Throwable $e) {
            error_log('[cache] Redis SET failed: ' . $e->getMessage());
        }
    }

    $expires = date('c', time() + $ttl_seconds);
    $db->prepare(
        "INSERT INTO cache (key, value, expires_at)
         VALUES (?, ?::jsonb, ?)
         ON CONFLICT (key) DO UPDATE
             SET value      = EXCLUDED.value,
                 expires_at = EXCLUDED.expires_at,
                 created_at = NOW()"
    )->execute([$key, $json, $expires]);
}

function cache_delete(string $key, PDO $db): void {
    $redis = cache_redis();
    if ($redis) {
        try {
            $redis->del($key);
        } catch (\Throwable $e) {
            error_log('[cache] Redis DEL failed: ' . $e->getMessage());
        }
    }

    $db->prepare("DELETE FROM cache WHERE key = ?")->execute([$key]);
}

function cache_flush_expired(PDO $db): void {
    $db->exec("DELETE FROM cache WHERE expires_at < NOW()");
}
