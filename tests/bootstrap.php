<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap — autoloading and path constants only.
 *
 * Deliberately does NOT require /bootstrap.php: that loads .env, sends HTTP
 * headers and installs web error handlers. Config values for tests come from
 * real environment variables (Config::get falls back to getenv()).
 *
 * Integration tests need a disposable PostgreSQL database whose name ends in
 * "_test" (they DROP and recreate its public schema):
 *
 *   LIFEDASH_TEST_PG_HOST=127.0.0.1 LIFEDASH_TEST_PG_DB=lifedash_test \
 *   LIFEDASH_TEST_PG_USER=lifedash LIFEDASH_TEST_PG_PASS=... vendor/bin/phpunit
 */

define('ROOT_DIR', dirname(__DIR__));
define('SRC_DIR', ROOT_DIR . '/src');
define('STORAGE_DIR', sys_get_temp_dir() . '/lifedash-test-storage');
define('VIEWS_DIR', ROOT_DIR . '/templates');
define('PUBLIC_DIR', ROOT_DIR . '/public');

if (file_exists(ROOT_DIR . '/vendor/autoload.php')) {
    require ROOT_DIR . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    $map = ['Dashboard\\' => SRC_DIR . '/', 'Dashboard\\Tests\\' => __DIR__ . '/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
});

date_default_timezone_set('UTC');

if (!function_exists('jsonResponse')) {
    /**
     * Same exit hatch as bootstrap.php. Tests set LIFEDASH_TEST_CAPTURE=1.
     *
     * @param mixed $data
     */
    function jsonResponse(mixed $data, int $status = 200): never
    {
        if (getenv('LIFEDASH_TEST_CAPTURE') === '1') {
            throw new Dashboard\Support\CapturedResponse($data, $status);
        }
        throw new RuntimeException('jsonResponse is only captured in tests.');
    }
}
