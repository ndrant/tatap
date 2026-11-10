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
echo "Total: $testCount, Passed: $passCount\n";
