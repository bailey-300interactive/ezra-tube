<?php
require_once __DIR__ . '/../config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die('Could not connect to the database. Check the settings in config.php. (' . htmlspecialchars($e->getMessage()) . ')');
        }
        seed_default_admin($pdo);
    }
    return $pdo;
}

/**
 * If there is no admin account yet, create one from the DEFAULT_ADMIN_USER /
 * DEFAULT_ADMIN_PASS constants in config.php. This runs at most once -
 * as soon as one admin row exists, it's skipped forever.
 */
function seed_default_admin(PDO $pdo): void {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($count === 0) {
        $hash = password_hash(DEFAULT_ADMIN_PASS, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)');
        $stmt->execute([DEFAULT_ADMIN_USER, $hash]);
    }
}
