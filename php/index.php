<?php
declare(strict_types=1);

namespace Tatap;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Http.php';
require_once __DIR__ . '/core/Cache.php';

use Tatap\Core\Router;
use Tatap\Core\Response;
use Tatap\Core\Cache;

// Determine URI path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

// If endpoint query param is passed by .htaccess
if (isset($_GET['endpoint'])) {
    $uri = '/api/' . ltrim((string)$_GET['endpoint'], '/');
} else {
    // Strip subfolder base path if any
    $base = Config::getBasePath();
    if (!empty($base) && strpos($uri, $base) === 0) {
        $uri = substr($uri, strlen($base));
    }
}

// Remove query parameters
if (($pos = strpos($uri, '?')) !== false) {
    $uri = substr($uri, 0, $pos);
}

$router = new Router();

// Base health & info routes
$router->add('GET', '/api/ping', function() {
    return [
        'status' => 'ok',
        'app' => Config::APP_NAME,
        'version' => Config::VERSION,
        'timestamp' => time(),
    ];
});

$router->add('GET', '/api/version', function() {
    return [
        'version' => Config::VERSION,
        'app' => Config::APP_NAME,
        'runtime' => 'PHP ' . PHP_VERSION,
    ];
});

$router->add('GET', '/api/health', function() {
    return [
        'status' => 'ok',
        'php_version' => PHP_VERSION,
        'data_dir' => is_writable(Config::dataDir()),
        'cache_dir' => is_writable(Config::cacheDir()),
    ];
});

$router->add('GET', '/api/update/check', function() {
    return [
        'update_available' => false,
        'current_version' => Config::VERSION,
        'latest_version' => Config::VERSION,
    ];
});

// If run from web request, dispatch
if (php_sapi_name() !== 'cli' || isset($_SERVER['HTTP_HOST'])) {
    $router->dispatch($method, $uri);
}
