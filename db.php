<?php
// ── Connexion à la base de données MySQL centrale ──────────
define('DB_HOST', '192.168.1.145');   // IP du serveur central
define('DB_PORT', '3306');
define('DB_NAME', 'ipam');
define('DB_USER', 'root');
define('DB_PASS', 'changeme');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
?>
