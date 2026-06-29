<?php
/**
 * Router for PHP's local development server.
 *
 * Existing files are served directly by the built-in server. All other
 * requests are forwarded to the application's front controller.
 */

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
