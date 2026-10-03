<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';

// Delete the remember-me token from DB
if (isset($_COOKIE['aces_remember'])) {
    $parts = explode(':', $_COOKIE['aces_remember'], 2);
    if (count($parts) === 2) {
        try {
            $pdo->prepare("DELETE FROM remember_tokens WHERE selector = ?")->execute([$parts[0]]);
        } catch (Exception $e) {
            error_log('Logout token delete failed: ' . $e->getMessage());
        }
    }
}

// Clear the cookie
aces_clear_remember_cookie();

// Destroy session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: index.php');
exit;
?>