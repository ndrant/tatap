<?php
declare(strict_types=1);

namespace Tatap;

// Prevent PHP warnings/deprecations from corrupting JSON payloads
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

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
require_once __DIR__ . '/scrapers/Aniskip.php';
require_once __DIR__ . '/scrapers/Translate.php';
require_once __DIR__ . '/player/VideoProxy.php';

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
use Tatap\Scrapers\Aniskip;
use Tatap\Scrapers\Translate;
use Tatap\Player\VideoProxy;

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
        'success' => true,
        'status' => 'ok',
        'data' => [
            'status' => 'running',
            'version' => Config::VERSION,
            'app' => Config::APP_NAME,
        ],
        'app' => Config::APP_NAME,
        'version' => Config::VERSION,
        'timestamp' => time(),
    ];
});

$router->add('GET', '/api/version', function() {
    return [
        'success' => true,
        'version' => Config::VERSION,
        'data' => [
            'version' => Config::VERSION,
        ],
        'app' => Config::APP_NAME,
        'runtime' => 'PHP ' . PHP_VERSION,
    ];
});

$router->add('GET', '/api/health', function() {
    return [
        'success' => true,
        'status' => 'ok',
        'data' => [
            'status' => 'ok',
            'php_version' => PHP_VERSION,
            'data_dir' => is_writable(Config::dataDir()),
            'cache_dir' => is_writable(Config::cacheDir()),
        ],
        'php_version' => PHP_VERSION,
        'data_dir' => is_writable(Config::dataDir()),
        'cache_dir' => is_writable(Config::cacheDir()),
    ];
});

$router->add('GET', '/api/update/check', function() {
    return [
        'success' => true,
        'data' => [
            'has_update' => false,
            'current_version' => Config::VERSION,
            'latest_version' => Config::VERSION,
            'update_available' => false,
        ],
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
    $prog = (int)($data['progress'] ?? 0);
    $dur = (int)($data['duration'] ?? 0);
    $shouldResume = ($prog >= 15);
    if ($dur > 30 && $prog >= ($dur - 30)) {
        $shouldResume = false;
    }
    return [
        'success' => true,
        'data' => [
            'slug' => $slug,
            'episode' => $ep,
            'progress' => $prog,
            'duration' => $dur,
            'formatted' => sprintf('%02d:%02d', (int)floor($prog / 60), $prog % 60),
            'should_resume' => $shouldResume,
        ],
    ];
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

$router->add('GET', '/api/schedule', function() {
    $day = (string)($_GET['day'] ?? 'today');
    $safeMode = (int)($_GET['safe_mode'] ?? 1);
    $seasonal = Anilist::seasonal('airing', 1, 30, $safeMode);
    $currentDay = strtolower(date('l'));
    if ($day === 'today') {
        $day = $currentDay;
    }
    return [
        'success' => true,
        'data' => [
            'day' => $day,
            'current_day' => $currentDay,
            'items' => $seasonal['items'] ?? [],
            'total' => count($seasonal['items'] ?? []),
        ],
    ];
});

$router->add('GET', '/api/upcoming-episodes', function() {
    $day = (string)($_GET['day'] ?? 'today');
    $safeMode = (int)($_GET['safe_mode'] ?? 1);
    $seasonal = Anilist::seasonal('airing', 1, 30, $safeMode);
    $currentDay = strtolower(date('l'));
    if ($day === 'today') {
        $day = $currentDay;
    }
    return [
        'success' => true,
        'data' => [
            'day' => $day,
            'current_day' => $currentDay,
            'items' => $seasonal['items'] ?? [],
            'total' => count($seasonal['items'] ?? []),
        ],
    ];
});

$router->add('GET', '/api/translate/logs', function() {
    return ['success' => true, 'data' => ['logs' => []]];
});

$router->add('GET', '/api/anime/{slug}/relations', function(array $params) {
    $slug = $params['slug'] ?? '';
    return ['success' => true, 'data' => []];
});

// --- Stream Resolution Routes (HiAnime) ---
$router->add('GET', '/api/anime/{slug}/episodes', function(array $params) {
    $slug = $params['slug'] ?? '';
    $title = (string)($_GET['title'] ?? '');
    $eps = Hianime::episodes($slug, $title);
    if (empty($eps) && (strpos($slug, 'otakudesu:') === 0 || empty($title))) {
        $eps = Otakudesu::episodes($slug);
    }
    return [
        'success' => true,
        'data' => [
            'slug' => $slug,
            'episodes' => $eps,
            'total' => count($eps),
            'synopsis' => '',
            'detail' => [],
            'poster' => '',
        ],
    ];
});

$resolveHandler = function() {
    $slug = (string)($_GET['slug'] ?? '');
    $title = (string)($_GET['title'] ?? '');
    $ep = max(1, (int)($_GET['ep'] ?? 1));
    $mode = (string)($_GET['mode'] ?? 'sub');
    $server = (string)($_GET['server'] ?? '');
    $source = strtolower((string)($_GET['source'] ?? ''));

    if ($source === 'otakudesu' || strpos($slug, 'otakudesu:') === 0) {
        $stream = Otakudesu::resolveStream($slug, $ep);
    } else {
        $stream = Hianime::resolveStream($slug, $ep, $mode, $server, $title);
        if (!$stream && ($mode === 'indo' || $source === 'auto' || empty($source))) {
            $stream = Otakudesu::resolveStream($title ?: $slug, $ep);
        }
    }

    if (!$stream) {
        Response::error('Stream source not found or offline', 404);
        return;
    }
    return ['success' => true, 'data' => $stream];
};

$router->add('GET', '/api/episode', $resolveHandler);
$router->add('GET', '/api/stream/resolve', $resolveHandler);

// --- Skip Times & Subtitle Translation Routes ---
$router->add('GET', '/api/episode/skip_times', function() {
    $malId = (int)($_GET['mal_id'] ?? 0);
    $ep = max(1, (int)($_GET['ep'] ?? 1));
    $duration = (int)($_GET['duration'] ?? 0);
    $slug = (string)($_GET['slug'] ?? '');

    if ($malId <= 0 && !empty($slug)) {
        $detail = Anilist::detail($slug);
        if ($detail && !empty($detail['idMal'])) {
            $malId = (int)$detail['idMal'];
        }
    }

    $skip = Aniskip::lookup($malId, $ep, $duration);
    return ['success' => true, 'data' => $skip];
});

$router->add('GET', '/api/player/sub', function() {
    $url = (string)($_GET['url'] ?? '');
    $lang = (string)($_GET['lang'] ?? 'id');
    $referer = (string)($_GET['referer'] ?? '');

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        Response::error('Invalid subtitle URL', 400);
        return;
    }

    $vtt = Translate::fetchAndTranslateVtt($url, $lang, $referer);
    header('Content-Type: text/vtt; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: public, max-age=86400');
    echo $vtt;
    exit;
});

$router->add('POST', '/api/player/sub_chunk', function() use ($getJsonBody) {
    $body = $getJsonBody();
    $cues = $body['cues'] ?? [];
    $target = (string)($body['target'] ?? ($body['lang'] ?? 'id'));
    $translated = [];
    foreach ($cues as $c) {
        $text = (string)($c['text'] ?? '');
        $trText = Translate::translateText($text, $target);
        $translated[] = [
            'start' => $c['start'] ?? 0,
            'end' => $c['end'] ?? 0,
            'text' => $trText,
        ];
    }
    return ['success' => true, 'data' => $translated, 'cues' => $translated];
});

$router->add('POST', '/api/player/sub_cache_save', function() {
    return ['success' => true, 'data' => true];
});

// --- Low-Memory Streaming Video Proxy Route ---
$router->add('GET', '/api/player/video', function() {
    $url = (string)($_GET['url'] ?? '');
    $referer = (string)($_GET['referer'] ?? '');
    $range = $_SERVER['HTTP_RANGE'] ?? ($_GET['range'] ?? null);

    VideoProxy::serve($url, $referer, $range);
});

// If run from web request, dispatch
if (php_sapi_name() !== 'cli' || isset($_SERVER['HTTP_HOST'])) {
    $router->dispatch($method, $uri);
}
