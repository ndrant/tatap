<?php
declare(strict_types=1);

/**
 * Tatap PHP Backend Integration & Unit Test Suite
 */

$testCount = 0;
$passCount = 0;

function assert_true(bool $condition, string $msg): void {
    global $testCount, $passCount;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo "  [PASS] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        throw new Exception("Assertion failed: $msg");
    }
}

function assert_equals($expected, $actual, string $msg): void {
    global $testCount, $passCount;
    $testCount++;
    if ($expected === $actual) {
        $passCount++;
        echo "  [PASS] $msg\n";
    } else {
        echo "  [FAIL] $msg (Expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
        throw new Exception("Assertion failed: $msg");
    }
}

echo "=== Running Tatap PHP Test Suite ===\n\n";

// --- Task 1 Tests: Core Framework ---
echo "Testing Task 1: Core Framework (Router, Response, Cache, Http)...\n";

require_once __DIR__ . '/../php/config.php';
require_once __DIR__ . '/../php/core/Router.php';
require_once __DIR__ . '/../php/core/Response.php';
require_once __DIR__ . '/../php/core/Http.php';
require_once __DIR__ . '/../php/core/Cache.php';

use Tatap\Core\Router;
use Tatap\Core\Response;
use Tatap\Core\Http;
use Tatap\Core\Cache;

// 1. Router Tests
$router = new Router();
$hitPing = false;
$router->add('GET', '/api/ping', function() use (&$hitPing) {
    $hitPing = true;
    return ['status' => 'ok', 'pong' => true];
});

$capturedSlug = '';
$router->add('GET', '/api/anime/{slug}/detail', function(array $params) use (&$capturedSlug) {
    $capturedSlug = $params['slug'] ?? '';
    return ['slug' => $capturedSlug];
});

$res = $router->resolve('GET', '/api/ping');
assert_true($res !== null, 'Router matches exact path /api/ping');
if ($res) {
    call_user_func($res['handler'], $res['params']);
}
assert_equals(true, $hitPing, 'Router executes handler for /api/ping');

$res2 = $router->resolve('GET', '/api/anime/naruto-shippuden/detail');
assert_true($res2 !== null, 'Router matches parameterized route');
if ($res2) {
    call_user_func($res2['handler'], $res2['params']);
}
assert_equals('naruto-shippuden', $capturedSlug, 'Router correctly extracts URL parameter {slug}');

$res404 = $router->resolve('GET', '/api/nonexistent');
assert_true($res404 === null, 'Router returns null for unmatched route');

// 2. Cache Tests
Cache::set('test_key', 'hello_tatap', 10);
$cached = Cache::get('test_key');
assert_equals('hello_tatap', $cached, 'Cache stores and retrieves value within TTL');

Cache::set('test_expired', 'expire_me', -1);
$expired = Cache::get('test_expired');
assert_true($expired === null, 'Cache returns null for expired key');

// 3. Response JSON Helper Check
$jsonOutput = Response::formatJson(['version' => '2.3.0', 'active' => true]);
assert_true(strpos($jsonOutput, '"version":"2.3.0"') !== false, 'Response formats clean JSON payload');

echo "\nTask 1 Tests Passed Successfully!\n";

// --- Task 2 Tests: Database & Visitor Isolation ---
echo "\nTesting Task 2: Database & Multi-Visitor Isolation (History & Bookmarks)...\n";

require_once __DIR__ . '/../php/core/Database.php';
require_once __DIR__ . '/../php/core/Session.php';
require_once __DIR__ . '/../php/user/History.php';
require_once __DIR__ . '/../php/user/Bookmarks.php';
require_once __DIR__ . '/../php/user/Settings.php';

use Tatap\Core\Database;
use Tatap\Core\Session;
use Tatap\User\History;
use Tatap\User\Bookmarks;
use Tatap\User\Settings;

$pdo = Database::pdo();
assert_true($pdo instanceof PDO, 'Database initializes PDO SQLite connection');

// Test visitor isolation
$tokenA = 'visitor_token_aaa_111';
$tokenB = 'visitor_token_bbb_222';

// 1. Bookmarks isolation
Bookmarks::add($tokenA, [
    'slug' => 'one-piece',
    'title' => 'One Piece',
    'poster' => 'https://img.example/op.jpg',
    'type' => 'TV',
]);

$bA = Bookmarks::list($tokenA);
$bB = Bookmarks::list($tokenB);
assert_equals(1, count($bA), 'Visitor A has 1 bookmark');
assert_equals('one-piece', $bA[0]['slug'], 'Visitor A has one-piece bookmark');
assert_equals(0, count($bB), 'Visitor B sees 0 bookmarks (isolation verified)');

// Check checkBookmark
assert_equals(true, Bookmarks::check($tokenA, 'one-piece'), 'Bookmark check true for Visitor A');
assert_equals(false, Bookmarks::check($tokenB, 'one-piece'), 'Bookmark check false for Visitor B');

// 2. Watch History isolation
History::add($tokenA, [
    'slug' => 'one-piece',
    'title' => 'One Piece',
    'episode' => 1080,
    'progress' => 120,
    'poster' => 'https://img.example/op.jpg',
]);

$hA = History::list($tokenA);
$hB = History::list($tokenB);
assert_equals(1, count($hA), 'Visitor A has 1 history record');
assert_equals(0, count($hB), 'Visitor B sees 0 history records (isolation verified)');

// 3. Clear History isolation
History::clear($tokenA);
assert_equals(0, count(History::list($tokenA)), 'Visitor A history cleared successfully');

// 4. Remove Bookmark
Bookmarks::remove($tokenA, 'one-piece');
assert_equals(false, Bookmarks::check($tokenA, 'one-piece'), 'Visitor A bookmark removed');

echo "\nTask 2 Tests Passed Successfully!\n";

// --- Task 3 Tests: AniList GraphQL Scraper ---
echo "\nTesting Task 3: AniList GraphQL Scraper...\n";

require_once __DIR__ . '/../php/scrapers/Anilist.php';

use Tatap\Scrapers\Anilist;

// 1. Test Hentai Filter Zero Tolerance
assert_equals(true, Anilist::isHentai('Hentai Movie'), 'Zero-tolerance filter flags hentai title');
assert_equals(true, Anilist::isHentai('some hentai anime'), 'Zero-tolerance filter flags hentai substring');
assert_equals(false, Anilist::isHentai('Naruto Shippuden'), 'Zero-tolerance filter passes safe anime');

// 2. Test AniList Genres
$genres = Anilist::genres(1);
assert_true(is_array($genres), 'AniList genres returns array');
assert_true(count($genres) > 0, 'AniList genres list is populated');
$hasHentai = false;
foreach ($genres as $g) {
    $slug = strtolower($g['slug'] ?? '');
    $title = strtolower($g['title'] ?? '');
    if ($slug === 'hentai' || strpos($title, 'hentai') !== false) {
        $hasHentai = true;
    }
}
assert_equals(false, $hasHentai, 'AniList genres strictly excludes hentai');

// 3. Test AniList Search Query
$searchRes = Anilist::search('Frieren', 1, 5);
assert_true(is_array($searchRes), 'AniList search returns array');
assert_true(isset($searchRes['results']), 'AniList search contains results key');
assert_true(count($searchRes['results']) > 0, 'AniList search returns Frieren results');
assert_true(isset($searchRes['results'][0]['title']), 'Search result item has title');

echo "\nTask 3 Tests Passed Successfully!\n";
echo "Total: $testCount, Passed: $passCount\n";


