<?php
declare(strict_types=1);

namespace Tatap;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Http.php';
require_once __DIR__ . '/core/Cache.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Session.php';
require_once __DIR__ . '/user/History.php';
require_once __DIR__ . '/user/Bookmarks.php';
require_once __DIR__ . '/user/Settings.php';
require_once __DIR__ . '/scrapers/Anilist.php';
require_once __DIR__ . '/scrapers/Hianime.php';
require_once __DIR__ . '/scrapers/Otakudesu.php';

use Tatap\Core\Router;
use Tatap\Core\Response;
use Tatap\Core\Cache;
use Tatap\Core\Session;
use Tatap\User\History;
use Tatap\User\Bookmarks;
use Tatap\User\Settings;
use Tatap\Scrapers\Anilist;
use Tatap\Scrapers\Hianime;
use Tatap\Scrapers\Otakudesu;

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

// Helper to parse JSON request body
$getJsonBody = function(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};

// --- User Data Routes (Visitor Isolated) ---
$router->add('GET', '/api/history', function() {
    $token = Session::getVisitorToken();
    return ['success' => true, 'data' => History::list($token)];
});

$router->add('POST', '/api/history', function() use ($getJsonBody) {
    $token = Session::getVisitorToken();
    $body = $getJsonBody();
    $res = History::add($token, $body);
    return ['success' => $res, 'data' => $res];
});

$router->add('DELETE', '/api/history', function() {
    $token = Session::getVisitorToken();
    $res = History::clear($token);
    return ['success' => $res, 'data' => $res];
});

$router->add('GET', '/api/progress', function() {
    $token = Session::getVisitorToken();
    $slug = (string)($_GET['slug'] ?? '');
    $ep = (int)($_GET['ep'] ?? 1);
    $data = History::getProgress($token, $slug, $ep);
    return ['success' => true, 'data' => $data ?: ['progress' => 0, 'duration' => 0]];
});

$router->add('POST', '/api/progress', function() use ($getJsonBody) {
    $token = Session::getVisitorToken();
    $body = $getJsonBody();
    $slug = (string)($body['slug'] ?? '');
    $ep = (int)($body['episode'] ?? 1);
    $progress = (int)($body['progress'] ?? 0);
    $duration = (int)($body['duration'] ?? 0);
    $res = History::saveProgress($token, $slug, $ep, $progress, $duration);
    return ['success' => $res, 'data' => $res];
});

$router->add('DELETE', '/api/progress', function() {
    $token = Session::getVisitorToken();
    $slug = (string)($_GET['slug'] ?? '');
    $ep = (int)($_GET['ep'] ?? 1);
    $res = History::deleteProgress($token, $slug, $ep);
    return ['success' => $res, 'data' => $res];
});

$router->add('GET', '/api/bookmarks', function() {
    $token = Session::getVisitorToken();
    return ['success' => true, 'data' => Bookmarks::list($token)];
});

$router->add('POST', '/api/bookmarks', function() use ($getJsonBody) {
    $token = Session::getVisitorToken();
    $body = $getJsonBody();
    $res = Bookmarks::add($token, $body);
    return ['success' => $res, 'data' => $res];
});

$router->add('DELETE', '/api/bookmarks/{slug}', function(array $params) {
    $token = Session::getVisitorToken();
    $slug = $params['slug'] ?? '';
    $res = Bookmarks::remove($token, $slug);
    return ['success' => $res, 'data' => $res];
});

$router->add('DELETE', '/api/bookmarks', function() {
    $token = Session::getVisitorToken();
    $res = Bookmarks::clear($token);
    return ['success' => $res, 'data' => $res];
});

$router->add('GET', '/api/bookmarks/check', function() {
    $token = Session::getVisitorToken();
    $slug = (string)($_GET['slug'] ?? '');
    $isBookmarked = Bookmarks::check($token, $slug);
    return [
        'success' => true,
        'bookmarked' => $isBookmarked,
        'data' => $isBookmarked,
    ];
});

$router->add('GET', '/api/settings', function() {
    $token = Session::getVisitorToken();
    return ['success' => true, 'data' => Settings::all($token)];
});

$router->add('POST', '/api/settings', function() use ($getJsonBody) {
    $token = Session::getVisitorToken();
    $body = $getJsonBody();
    foreach ($body as $k => $v) {
        Settings::set($token, (string)$k, (string)$v);
    }
    return ['success' => true, 'data' => true];
});

// --- Catalog & Anime Metadata Routes (AniList) ---
$router->add('GET', '/api/search', function() {
    $q = (string)($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 24)));
    $safeMode = (int)($_GET['safe_mode'] ?? 1);
    $res = Anilist::search($q, $page, $limit, $safeMode);
    return ['success' => true, 'data' => $res];
});

$router->add('GET', '/api/genres', function() {
    $safeMode = (int)($_GET['safe_mode'] ?? 1);
    $res = Anilist::genres($safeMode);
    return ['success' => true, 'data' => $res];
});

$router->add('GET', '/api/filters', function() {
    $safeMode = (int)($_GET['safe_mode'] ?? 1);
    return [
        'success' => true,
        'data' => [
            'genres' => Anilist::genres($safeMode),
            'types' => ['TV', 'Movie', 'OVA', 'ONA', 'Special'],
            'statuses' => ['All', 'Releasing', 'Finished', 'Not Yet Aired'],
            'seasons' => ['Winter', 'Spring', 'Summer', 'Fall'],
        ],
    ];
});

$router->add('GET', '/api/seasonal', function() {
    $which = (string)($_GET['which'] ?? 'airing');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 25)));
    $safeMode = (int)($_GET['safe_mode'] ?? 1);
    $res = Anilist::seasonal($which, $page, $limit, $safeMode);
    return ['success' => true, 'data' => $res];
});

$router->add('GET', '/api/catalog', function() {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $safeMode = (int)($_GET['safe_mode'] ?? 1);
    $res = Anilist::seasonal('popular', $page, 24, $safeMode);
    return ['success' => true, 'data' => $res];
});

$router->add('GET', '/api/browse', function() {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $safeMode = (int)($_GET['safe_mode'] ?? 1);
    $res = Anilist::seasonal('popular', $page, 24, $safeMode);
    return ['success' => true, 'data' => $res];
});

$router->add('GET', '/api/anime/{slug}/detail', function(array $params) {
    $slug = $params['slug'] ?? '';
    $detail = Anilist::detail($slug);
    if (!$detail) {
        Response::error('Anime detail not found', 404);
        return;
    }
    return ['success' => true, 'data' => $detail];
});

// --- Stream Resolution Routes (HiAnime) ---
$router->add('GET', '/api/anime/{slug}/episodes', function(array $params) {
    $slug = $params['slug'] ?? '';
    $eps = Hianime::episodes($slug);
    return ['success' => true, 'data' => $eps];
});

$router->add('GET', '/api/episode', function() {
    $slug = (string)($_GET['slug'] ?? '');
    $ep = max(1, (int)($_GET['ep'] ?? 1));
    $mode = (string)($_GET['mode'] ?? 'sub');
    $server = (string)($_GET['server'] ?? '');
    $source = strtolower((string)($_GET['source'] ?? ''));

    if ($source === 'otakudesu') {
        $stream = Otakudesu::resolveStream($slug, $ep);
    } else {
        $stream = Hianime::resolveStream($slug, $ep, $mode, $server);
        if (!$stream && ($mode === 'indo' || $source === 'auto')) {
            $stream = Otakudesu::resolveStream($slug, $ep);
        }
    }

    if (!$stream) {
        Response::error('Stream source not found or offline', 404);
        return;
    }
    return ['success' => true, 'data' => $stream];
});

$router->add('GET', '/api/stream/resolve', function() {
    $slug = (string)($_GET['slug'] ?? '');
    $ep = max(1, (int)($_GET['ep'] ?? 1));
    $mode = (string)($_GET['mode'] ?? 'sub');
    $server = (string)($_GET['server'] ?? '');

    $stream = Hianime::resolveStream($slug, $ep, $mode, $server);
    if (!$stream) {
        Response::error('Stream source not found or offline', 404);
        return;
    }
    return ['success' => true, 'data' => $stream];
});

// If run from web request, dispatch
if (php_sapi_name() !== 'cli' || isset($_SERVER['HTTP_HOST'])) {
    $router->dispatch($method, $uri);
}
