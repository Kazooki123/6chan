<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

function get_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $db_name = $_ENV['DB_NAME'];
    $db_user = $_ENV['DB_USER'];
    $db_pass = $_ENV['DB_PASS'];
    
    $pdo = new PDO(
        "pgsql:host=localhost;dbname=$db_name",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}
