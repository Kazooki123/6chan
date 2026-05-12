<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

function get_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $db_pass = $_ENV['DB_PASS'];
    
    $pdo = new PDO(
        'pgsql:host=localhost;dbname=imageboard',
        'postgres',
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}
