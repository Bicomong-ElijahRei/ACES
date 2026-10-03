<?php
/**
 * ============================================================
 * ACES System — Session Management
 * ============================================================
 * Handles:
 *   - Session cookie settings (httponly, samesite, secure)
 *   - Idle timeout (auto-logout after N seconds)
 *   - "Remember me" cookie validation and restoration
 *   - Token rotation on each use
 *
 * This file should be loaded BEFORE any auth checks.
 * It replaces the raw session_start() call in auth.php.
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

// ---- Cookie settings ----
$https_on = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https_on,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

$idle_limit     = (int) aces_env('SESSION_IDLE_TIMEOUT', 3600);
$remember_days  = (int) aces_env('SESSION_REMEMBER_DAYS', 30);

// ============================================================
// IDLE TIMEOUT CHECK
// ============================================================
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idle_limit) {
        // Session expired — clear it
        session_unset();
        session_destroy();

        // Build login URL
        $base = rtrim(aces_env('APP_URL', ''), '/');
        $login_url = $base ? "$base/login.php?timeout=1" : 'login.php?timeout=1';

        header('Location: ' . $login_url);
        exit;
    }

    // Update last activity
    $_SESSION['last_activity'] = time();
}

// ============================================================
// REMEMBER-ME COOKIE RESTORATION
// ============================================================
if (!isset($_SESSION['user_id']) && isset($_COOKIE['aces_remember'])) {
    $cookie = $_COOKIE['aces_remember'];
    $parts  = explode(':', $cookie, 2);

    if (count($parts) === 2) {
        [$selector, $validator] = $parts;

        try {
            $stmt = $pdo->prepare("
                SELECT rt.id, rt.user_id, rt.token_hash, rt.expires_at,
                       u.email, u.full_name, u.role, u.staff_role, u.is_active
                FROM remember_tokens rt
                JOIN users u ON rt.user_id = u.user_id
                WHERE rt.selector = ?
                  AND rt.expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$selector]);
            $row = $stmt->fetch();

            if ($row && hash_equals($row['token_hash'], hash('sha256', $validator))) {
                if ($row['is_active']) {
                    // ---- Valid token + active account ----
                    session_regenerate_id(true);

                    $_SESSION['user_id']       = $row['user_id'];
                    $_SESSION['role']          = $row['role'];
                    $_SESSION['full_name']     = $row['full_name'];
                    $_SESSION['staff_role']    = $row['role'] === 'staff' ? ($row['staff_role'] ?? 'viewer') : null;
                    $_SESSION['last_activity'] = time();

                    // Rotate the token (best practice — single-use tokens)
                    try {
                        $pdo->prepare("DELETE FROM remember_tokens WHERE id = ?")->execute([$row['id']]);
                        aces_set_remember_cookie($row['user_id'], $pdo, $remember_days, $https_on);
                    } catch (Exception $e) {
                        error_log('Token rotation failed: ' . $e->getMessage());
                    }
                } else {
                    // ---- Deactivated account ----
                    aces_clear_remember_cookie();
                }
            } else {
                // ---- Invalid token — clear it ----
                aces_clear_remember_cookie();
            }
        } catch (Exception $e) {
            error_log('Remember-me check failed: ' . $e->getMessage());
        }
    } else {
        // Malformed cookie
        aces_clear_remember_cookie();
    }
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Issue a new remember-me cookie + save its hash to the DB.
 * Format of cookie: "selector:validator"
 *   - selector  → short, indexed lookup key
 *   - validator → long secret; only its SHA-256 hash is stored
 */
function aces_set_remember_cookie(int $user_id, PDO $pdo, int $days = 30, bool $secure = false): void
{
    try {
        $selector   = bin2hex(random_bytes(12));  // 24 chars
        $validator  = bin2hex(random_bytes(32));  // 64 chars
        $hash       = hash('sha256', $validator);
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $pdo->prepare("
            INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at)
            VALUES (?, ?, ?, ?)
        ")->execute([$user_id, $selector, $hash, $expires_at]);

        // Keep only the 5 most recent tokens per user
        $pdo->prepare("
            DELETE FROM remember_tokens
            WHERE user_id = ?
              AND id NOT IN (
                  SELECT id FROM (
                      SELECT id FROM remember_tokens
                      WHERE user_id = ?
                      ORDER BY created_at DESC
                      LIMIT 5
                  ) AS recent
              )
        ")->execute([$user_id, $user_id]);

        setcookie('aces_remember', "$selector:$validator", [
            'expires'  => strtotime($expires_at),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (Exception $e) {
        error_log('aces_set_remember_cookie failed: ' . $e->getMessage());
    }
}

/**
 * Remove the remember-me cookie from the browser.
 */
function aces_clear_remember_cookie(): void
{
    if (isset($_COOKIE['aces_remember'])) {
        setcookie('aces_remember', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}