<?php
function get_db(): PDO {
    $env = getenv(".env");
    $db_pass = $env['DB_PASS'];
    
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'pgsql:host=localhost;dbname=imageboard',
        'postgres',
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}
