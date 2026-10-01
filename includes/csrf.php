<?php
/**
 * ============================================================
 * ACES System — CSRF Protection
 * ============================================================
 * Provides CSRF token generation, injection into forms, and
 * verification on POST requests.
 *
 * Usage in forms:
 *   <?= csrf_field() ?>
 *
 * Usage in POST handlers (top of file):
 *   require_once __DIR__ . '/../includes/csrf.php';
 *   csrf_verify();
 * ============================================================
 */

// Ensure session is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get (or create) the current CSRF token for this session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Return an HTML hidden input for a form.
 * Use inside every <form method="post">.
 */
function csrf_field(): string
{
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify the CSRF token on a POST request.
 * Halts with 403 if invalid.
 *
 * @param bool $dieOnFail If true (default), stops execution on failure.
 * @return bool
 */
function csrf_verify(bool $dieOnFail = true): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return true; // Nothing to verify
    }

    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $submitted)) {
        if ($dieOnFail) {
            http_response_code(403);
            // Log it for the audit trail
            error_log('CSRF token mismatch from IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                    . ' on URI ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
            die('Security check failed. Please refresh the page and try again.');
        }
        return false;
    }

    return true;
}

/**
 * Regenerate the CSRF token (e.g., after login/logout).
 */
function csrf_rotate(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}