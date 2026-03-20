<?php

/**
 * Router script for PHP's built-in development server.
 *
 * Usage: php -S localhost:8000 -t public public/router.php
 *
 * Returns false for static files (letting the built-in server handle them),
 * and routes all other requests through index.php.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

// If the request is for a real file in public/, let the built-in server handle it
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// Route everything else through the front controller
require __DIR__ . '/index.php';
