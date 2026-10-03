<?php
/**
 * ============================================================
 * ACES System — Database Connection
 * ============================================================
 * Loads credentials from .env via config/env.php.
 * Returns a shared PDO instance.
 *
 * Usage:
 *   require_once __DIR__ . '/../config/database.php';
 *   $stmt = $pdo->prepare('...');
 * ============================================================
 */

require_once __DIR__ . '/env.php';

// Build connection string from .env
$host     = aces_env('DB_HOST', 'localhost');
$port     = aces_env('DB_PORT', '3306');
$dbname   = aces_env('DB_NAME', 'aces_db');
$username = aces_env('DB_USER', 'root');
$password = aces_env('DB_PASS', '');

$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // Log the full error for the developer
    error_log('DB connection failed: ' . $e->getMessage());

    // .env values are strings — "true", "1", "on", "yes" all count as debug mode.
    // filter_var() handles every variant, so this works no matter how APP_DEBUG is written.
    $debug = filter_var(aces_env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);

    if ($debug) {
        die('Database connection failed: ' . $e->getMessage());
    } else {
        http_response_code(500);
        die('System temporarily unavailable. Please try again later.');
    }
}