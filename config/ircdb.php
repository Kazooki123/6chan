<?php
const CHAT_RETENTION_DAYS = 14;
const CHAT_MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

function get_chat_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dir . '/chat.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL'); // readers don't block the writer on every poll

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS irc_messages (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            channel    TEXT    NOT NULL CHECK (channel IN ('general', 'staffs')),
            author     TEXT    NOT NULL DEFAULT 'Anonymous',
            tripcode   TEXT,
            is_staff   INTEGER NOT NULL DEFAULT 0,
            body       TEXT    NOT NULL,
            created_at TEXT    NOT NULL
        )"
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_irc_messages_channel_id ON irc_messages (channel, id)'
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff_invites (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            code        TEXT    NOT NULL UNIQUE,
            created_by  TEXT,
            created_at  TEXT    NOT NULL,
            expires_at  TEXT    NOT NULL,
            redeemed_at TEXT
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff_tokens (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            username   TEXT    NOT NULL,
            token_hash TEXT    NOT NULL UNIQUE,
            created_at TEXT    NOT NULL,
            revoked    INTEGER NOT NULL DEFAULT 0
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS presence (
            client_id    TEXT    NOT NULL,
            channel      TEXT    NOT NULL,
            display_name TEXT    NOT NULL,
            tripcode     TEXT,
            is_staff     INTEGER NOT NULL DEFAULT 0,
            last_seen    TEXT    NOT NULL,
            PRIMARY KEY (client_id, channel)
        )"
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_presence_channel_seen ON presence (channel, last_seen)'
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS chat_files (
            id         TEXT PRIMARY KEY,
            channel    TEXT    NOT NULL,
            uploader   TEXT    NOT NULL,
            filename   TEXT    NOT NULL,
            size_bytes INTEGER NOT NULL,
            created_at TEXT    NOT NULL
        )"
    );

    return $pdo;
}

/**
 * Deletes messages older than CHAT_RETENTION_DAYS. Called probabilistically
 * from the send path (see irc.php) rather than via cron — an indexed
 * DELETE on a table this size is cheap enough that it doesn't need its
 * own scheduled job.
 */
function prune_old_chat_messages(PDO $chat_db): void {
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - CHAT_RETENTION_DAYS * 86400);
    $chat_db->prepare('DELETE FROM irc_messages WHERE created_at < ?')->execute([$cutoff]);
}

function prune_old_chat_files(PDO $chat_db): void {
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - CHAT_RETENTION_DAYS * 86400);
    $stmt = $chat_db->prepare('SELECT id FROM chat_files WHERE created_at < ?');
    $stmt->execute([$cutoff]);
    $dir = __DIR__ . '/../storage/files';
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        @unlink($dir . '/' . $id);
    }
    $chat_db->prepare('DELETE FROM chat_files WHERE created_at < ?')->execute([$cutoff]);
}
