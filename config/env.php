<?php
/**
 * ============================================================
 * ACES System — Environment Loader
 * ============================================================
 * Reads the .env file from the project root and makes its
 * values available throughout the application.
 *
 * Usage:
 *   require_once __DIR__ . '/env.php';
 *   $host = aces_env('DB_HOST', 'localhost');
 * ============================================================
 */

/**
 * Load .env file into $_ENV once per request.
 */
function aces_load_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $envPath = dirname(__DIR__) . '/.env';

    if (!file_exists($envPath)) {
        // In production, missing .env is fatal. In local, warn.
        if (getenv('APP_ENV') === 'production') {
            error_log('CRITICAL: .env file not found at ' . $envPath);
            http_response_code(500);
            die('Configuration error. Contact administrator.');
        }
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and blank lines
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        // Must have = sign
        if (strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip surrounding quotes
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Store in $_ENV and putenv (both available)
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

/**
 * Get an environment variable with optional default.
 *
 * @param string $key     The .env key
 * @param mixed  $default Fallback if key not set
 * @return mixed
 */
function aces_env(string $key, $default = null)
{
    aces_load_env();

    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    // Convert common string booleans
    $lower = strtolower($value);
    if ($lower === 'true')  return true;
    if ($lower === 'false') return false;
    if ($lower === 'null')  return null;

    return $value;
}

/**
 * Check if a key exists (even if empty).
 */
function aces_env_has(string $key): bool
{
    aces_load_env();
    return array_key_exists($key, $_ENV) || getenv($key) !== false;
}

// Auto-load on include
aces_load_env();