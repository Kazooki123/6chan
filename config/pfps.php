<?php

/**
 * PROFILE PICTURES (PFP)
 * ================
 * Pfps are tied to a users tripcode (user#pwrd) meaning if they got their password correct, it'll almost always show their profile picture.
 * Pfps are also stored in a storage bucket (MinIO for now).
 * Users can setup their PFP in the Postform and pick an image format of their choice (6chan resizes it down anyway)
 */

defined('ROOT_PATH') || define('ROOT_PATH', dirname(__DIR__));
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/tripcode.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

const PFP_MAX_BYTES     = 3 * 1024 * 1024; // 3MB
const PFP_DEFAULT_PATH  = '/assets/profile.jpg';
const PFP_PREFIX        = 'pfps/';

const PFP_ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

function pfps_s3(): S3Client {
    static $client = null;
    if ($client !== null) return $client;

    $client = new S3Client([
        'version'  => 'latest',
        'region'   => 'us-east-1',
        'endpoint' => $_ENV['S3_ENDPOINT'] ?? 'http://localhost:9000',
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key'    => $_ENV['S3_KEY'],
            'secret' => $_ENV['S3_SECRET'],
        ],
    ]);

    return $client;
}

function pfp_key_for_trip(?string $trip, PDO $db): ?string {
    static $cache = [];

    if (!$trip) return null;
    if (array_key_exists($trip, $cache)) return $cache[$trip];

    $stmt = $db->prepare("SELECT img_key FROM pfps WHERE tripcode = ?");
    $stmt->execute([$trip]);
    $key = $stmt->fetchColumn();

    return $cache[$trip] = ($key !== false ? $key : null);
}

function pfp_url_for_trip(?string $trip, PDO $db): string {
    $key = pfp_key_for_trip($trip, $db);
    if ($key === null) {
        return PFP_DEFAULT_PATH;
    }

    $bucket   = $_ENV['S3_UPLOAD'];
    $endpoint = rtrim($_ENV['S3_ENDPOINT'], '/');
    return $endpoint . '/' . $bucket . '/' . $key;
}

function pfp_validate_upload(array $file): array {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload error (code ' . $file['error'] . ').'];
    }
    if ($file['size'] > PFP_MAX_BYTES) {
        return ['ok' => false, 'error' => 'File too large. Max 3MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    if (!isset(PFP_ALLOWED_MIME[$mime])) {
        return ['ok' => false, 'error' => 'Unsupported image type. Use JPG, PNG, WEBP, or GIF.'];
    }

    // getimagesize() also catches files that pass the mime sniff but
    // aren't actually decodable images (corrupt/truncated uploads).
    $dims = @getimagesize($file['tmp_name']);
    if ($dims === false) {
        return ['ok' => false, 'error' => 'File is not a valid image.'];
    }

    return ['ok' => true, 'error' => null, 'ext' => PFP_ALLOWED_MIME[$mime], 'mime' => $mime];
}

function pfp_set(string $trip, array $file, PDO $db): array {
    $validated = pfp_validate_upload($file);
    if (!$validated['ok']) {
        return ['ok' => false, 'error' => $validated['error'], 'url' => null];
    }

    // Strip the leading '!' for a cleaner object key.
    $safe_trip = ltrim($trip, '!');
    $key       = PFP_PREFIX . $safe_trip . '.' . $validated['ext'];

    $s3     = pfps_s3();
    $bucket = $_ENV['S3_UPLOAD'];

    try {
        $s3->putObject([
            'Bucket'      => $bucket,
            'Key'         => $key,
            'SourceFile'  => $file['tmp_name'],
            'ContentType' => $validated['mime'],
            'ACL'         => 'public-read',
        ]);
    } catch (AwsException $e) {
        error_log('[pfps] MinIO upload failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Upload to storage failed.', 'url' => null];
    }

    $stmt = $db->prepare(
        "INSERT INTO pfps (tripcode, img_key, updated_at)
         VALUES (?, ?, NOW())
         ON CONFLICT (tripcode) DO UPDATE
         SET img_key = EXCLUDED.img_key, updated_at = NOW()"
    );
    $stmt->execute([$trip, $key]);

    $endpoint = rtrim($_ENV['S3_ENDPOINT'], '/');
    return ['ok' => true, 'error' => null, 'url' => $endpoint . '/' . $bucket . '/' . $key];
}
