<?php

declare(strict_types=1);

/**
 * bootstrap.php — loaded once by every public entry point.
 *
 * Responsibilities:
 *   - Autoloading (composer or manual PSR-4 fallback)
 *   - Config loading + validation
 *   - Error/exception handling
 *   - Global helpers
 */

// -------------------------------------------------------
// Paths
// -------------------------------------------------------
define('ROOT_DIR', __DIR__);
define('SRC_DIR', ROOT_DIR . '/src');
define('STORAGE_DIR', ROOT_DIR . '/storage');
define('VIEWS_DIR', ROOT_DIR . '/templates');
define('PUBLIC_DIR', defined('DASHBOARD_WEB_ROOT') ? DASHBOARD_WEB_ROOT : (ROOT_DIR . '/public'));

// -------------------------------------------------------
// Autoloader (Composer preferred, manual PSR-4 fallback)
// -------------------------------------------------------
if (file_exists(ROOT_DIR . '/vendor/autoload.php')) {
    require ROOT_DIR . '/vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'Dashboard\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file     = SRC_DIR . '/' . $relative . '.php';
        if (file_exists($file)) {
            require $file;
        }
    });
}

// -------------------------------------------------------
// Config
// -------------------------------------------------------
use Dashboard\Config;

Config::load(ROOT_DIR . '/.env');

Config::validate([
    'APP_SECRET',
    'DB_DRIVER',
]);

// -------------------------------------------------------
// Error handling
// -------------------------------------------------------
$debug = Config::bool('APP_DEBUG', false);

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use ($debug): bool {
    if (!($errno & error_reporting())) {
        return false;
    }

    $msg = "{$errstr} in {$errfile}:{$errline}";

    error_log("[Dashboard][Error {$errno}] {$msg}");

    if ($debug) {
        throw new \ErrorException($msg, 0, $errno, $errfile, $errline);
    }

    return true;
});

set_exception_handler(function (\Throwable $e) use ($debug): void {
    $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    error_log('[Dashboard][Exception] ' . $msg);

    // CLI (migrate, cron jobs, importer): plain text on stderr and a non-zero exit,
    // so callers like setup.sh / systemd see the failure instead of an HTML page.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "\nERROR: {$e->getMessage()}\n  at {$e->getFile()}:{$e->getLine()}\n");
        if ($debug) {
            fwrite(STDERR, $e->getTraceAsString() . "\n");
        }
        exit(1);
    }

    if ($debug) {
        http_response_code(500);
        echo '<pre>' . htmlspecialchars($msg) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    } else {
        http_response_code(500);
        require VIEWS_DIR . '/errors/500.php';
    }
});

// -------------------------------------------------------
// Timezone
// -------------------------------------------------------
date_default_timezone_set(Config::get('APP_TIMEZONE', 'America/Chicago'));

// -------------------------------------------------------
// Security headers (applied to every response)
// -------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://sdk.scdn.co; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data: https:; connect-src 'self' https://api.spotify.com https://api-partner.spotify.com https://accounts.spotify.com https://*.scdn.co wss://*.spotify.com; media-src 'self' blob: https://*.scdn.co; worker-src 'self' blob:");

// -------------------------------------------------------
// Global helper functions
// -------------------------------------------------------

/**
 * HTML-escape a string for safe output.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirect to a URL and exit.
 */
function redirect(string $url, int $status = 302): never
{
    header("Location: {$url}", true, $status);
    exit;
}

/**
 * Emit a JSON response and exit.
 *
 * @param mixed $data
 */
function jsonResponse(mixed $data, int $status = 200): never
{
    if (getenv('LIFEDASH_TEST_CAPTURE') === '1') {
        throw new \Dashboard\Support\CapturedResponse($data, $status);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Start the app session (shared by Auth and CSRF helpers).
 */
function initAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = Config::bool('SESSION_SECURE', true);

    session_set_cookie_params([
        'lifetime' => Config::int('SESSION_LIFETIME', 86400),
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('dashboard_sess');
    session_start();
}

/**
 * CSRF token — generate once per session, store in session.
 */
function csrfToken(): string
{
    initAppSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * CSRF token from the current request (form field or X-CSRF-Token header).
 */
function requestCsrfToken(): string
{
    $posted = $_POST['_csrf'] ?? '';
    if (is_string($posted) && $posted !== '') {
        return $posted;
    }

    foreach (['HTTP_X_CSRF_TOKEN', 'REDIRECT_HTTP_X_CSRF_TOKEN'] as $key) {
        $header = $_SERVER[$key] ?? '';
        if (is_string($header) && $header !== '') {
            return $header;
        }
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'X-CSRF-Token') === 0 && is_string($value) && $value !== '') {
                return $value;
            }
        }
    }

    return '';
}

/**
 * Validate CSRF token from POST data.
 * Call on any state-mutating POST handler.
 */
function validateCsrf(): void
{
    if (\Dashboard\Api\AgentAccess::currentUser() !== null) {
        return;
    }

    initAppSession();

    $token    = requestCsrfToken();
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $token)) {
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        if (str_starts_with($path, '/api/') || str_contains($accept, 'application/json')) {
            jsonResponse(['error' => 'Invalid CSRF token. Refresh the page and try again.'], 403);
        }

        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}
