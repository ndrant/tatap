<?php
/**
 * Local development router for PHP built-in web server:
 * php -S 127.0.0.1:8768 -t dist/tatap-web-php dist/tatap-web-php/router.php
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . $uri;

// 1. Serve existing static file directly
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // Built-in web server serves the static file as-is
}

// 2. Route all /api/* requests to api/index.php
if (strpos($uri, '/api') === 0) {
    require __DIR__ . '/api/index.php';
    exit;
}

// 3. Fallback to index.html for Single Page Application
require __DIR__ . '/index.html';
