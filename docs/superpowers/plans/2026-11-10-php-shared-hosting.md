# PHP Shared Hosting Edition & Deployment Tool Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a standalone PHP-based backend (`php/`) and an automated packaging/FTP deployment tool (`tools/build_php_web.py`) enabling Tatap to run directly on standard shared web hosting (cPanel, Apache, LiteSpeed) with zero external server dependencies, full core streaming parity, and visitor session isolation.

**Architecture:** A lightweight front controller (`php/index.php`) routes `/api/*` requests through pure vanilla PHP adapters (AniList GraphQL, HiAnime with AES-CBC MegaCloud decryptor, Otakudesu, AniSkip, Google Translate GTX). Watch history and bookmarks are isolated per visitor using secure session tokens backed by SQLite PDO (`data/tatap.sqlite`) protected by Apache `.htaccess`. A packaging script (`tools/build_php_web.py`) bundles the web assets and optionally uploads via FTP/FTPS.

**Tech Stack:** PHP 7.4–8.3+ (ext-curl, ext-pdo_sqlite, ext-openssl, ext-json, ext-mbstring), Apache/LiteSpeed `.htaccess`, Python 3 (packaging & FTP deployer), Vanilla JavaScript / CSS.

**Spec:** `docs/SPEC-php-shared-hosting.md`

## Global Constraints

- **PHP Version Floor**: Strict compatibility with PHP 7.4 through PHP 8.3+.
- **Zero Composer / No Vendor**: Zero external PHP packages; 100% self-contained standard library and extensions.
- **Strict Database Security**: SQLite database (`data/tatap.sqlite`) must be completely inaccessible from the web via `.htaccess`.
- **Runtime Isolation**: The existing Python FastAPI backend, Android Chaquopy runtime, and desktop packaging scripts must remain 100% untouched and functional.
- **Hentai Filter**: Zero-tolerance blacklist filter must be respected across all search and catalog endpoints.

## Review Focus

1. **Subfolder deployment path resolution**: When extracted to `https://example.com/tatap/`, API fetch calls must not drop the subfolder prefix.
2. **MegaCloud AES-CBC source decryption**: Changes in ciphertext padding or byte alignment must not crash the stream resolver.
3. **M3U8 manifest proxy URL rewrite**: Nested `#EXT-X-STREAM-INF` and relative chunk paths must rewrite cleanly to `/api/player/video?url=...` with valid Referer headers.
4. **Visitor token collision/spoofing**: Querying bookmarks or history must strictly bind to `WHERE visitor_token = ?`.
5. **Shared hosting memory exhaustion during video proxying**: Video segments must be streamed chunk-by-chunk with `readfile()` or cURL write callbacks, never buffered completely in PHP memory.

---

### Task 1: Core Framework, Router, Response & Security Scaffolding

**Files:**
- Create: `php/index.php`
- Create: `php/config.php`
- Create: `php/core/Router.php`
- Create: `php/core/Response.php`
- Create: `php/core/Http.php`
- Create: `php/core/Cache.php`
- Create: `data/.htaccess`
- Create: `.htaccess.example`
- Create: `tests/test_php_backend.php`

**Interfaces:**
- Produces: `Router::add(string $method, string $pattern, callable $handler)`
- Produces: `Router::dispatch(string $method, string $uri): void`
- Produces: `Response::json($data, int $code = 200, array $headers = []): void`
- Produces: `Http::get(string $url, array $headers = [], int $timeout = 15): array`
- Produces: `Cache::get(string $key, int $ttl = 300)` / `Cache::set(string $key, $val, int $ttl = 300): void`

- [ ] **Step 1: Write test for Router, Response, and Cache**

```php
// In tests/test_php_backend.php: test core router, json responses, and cache
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test_php_backend.php`
Expected: FAIL (files missing)

- [ ] **Step 3: Implement core classes in `php/core/` and front controller `php/index.php`**

Implement `Router.php`, `Response.php`, `Http.php`, `Cache.php`, `config.php`, `index.php`, `.htaccess.example`, and `data/.htaccess`. Ensure `/api/ping`, `/api/version`, and `/api/health` are registered and return valid JSON.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test_php_backend.php`
Expected: PASS for Task 1 tests.

- [ ] **Step 5: Commit**

```bash
git add php/ tests/test_php_backend.php data/.htaccess .htaccess.example
git commit -m "feat(php): implement core router, http, cache, and front controller"
```

---

### Task 2: Database & Multi-Visitor Isolation (History & Bookmarks)

**Files:**
- Create: `php/core/Database.php`
- Create: `php/core/Session.php`
- Create: `php/user/History.php`
- Create: `php/user/Bookmarks.php`
- Create: `php/user/Settings.php`
- Modify: `php/index.php`
- Modify: `tests/test_php_backend.php`

**Interfaces:**
- Consumes: `Router`, `Response`, `Database`
- Produces: `Session::getVisitorToken(): string`
- Produces: `Database::pdo(): PDO`
- Produces: `History::handle(string $method, array $params): void`
- Produces: `Bookmarks::handle(string $method, array $params): void`
- Produces: `Settings::handle(string $method, array $params): void`

- [ ] **Step 1: Write test for visitor isolation and CRUD**

Test that Visitor A's bookmarks and history cannot be read or deleted by Visitor B with a different token.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test_php_backend.php`
Expected: FAIL

- [ ] **Step 3: Implement `Database.php`, `Session.php`, `History.php`, `Bookmarks.php`, and `Settings.php`**

Initialize SQLite schema (`tatap_history`, `tatap_bookmarks`, `tatap_settings`) if not exists. Ensure `visitor_token` is extracted from `tatap_token` cookie or `X-Visitor-Token` header.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test_php_backend.php`
Expected: PASS for Task 2 tests.

- [ ] **Step 5: Commit**

```bash
git add php/core/Database.php php/core/Session.php php/user/ php/index.php tests/test_php_backend.php
git commit -m "feat(php): add SQLite database and multi-visitor session isolation"
```

---

### Task 3: AniList GraphQL Scraper Adapter

**Files:**
- Create: `php/scrapers/Anilist.php`
- Modify: `php/index.php`
- Modify: `tests/test_php_backend.php`

**Interfaces:**
- Consumes: `Http`, `Cache`, `Response`
- Produces: `Anilist::search(string $query, int $page, int $perPage): array`
- Produces: `Anilist::catalog(array $filters): array`
- Produces: `Anilist::detail(string $slugOrId): ?array`
- Produces: `Anilist::seasonal(string $which): array`
- Produces: `Anilist::genres(): array`

- [ ] **Step 1: Write test for AniList scraper**

Test AniList search and catalog queries, genre list, and hentai filter enforcement.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test_php_backend.php`
Expected: FAIL

- [ ] **Step 3: Implement `php/scrapers/Anilist.php` and register routes**

Register routes `/api/search`, `/api/catalog`, `/api/genres`, `/api/filters`, `/api/seasonal`, `/api/schedule`, `/api/browse`, `/api/anime/{slug}/detail`, `/api/anime/{slug}/relations`. Apply caching (1 hour for catalog/genres, 5 mins for search).

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test_php_backend.php`
Expected: PASS for Task 3 tests.

- [ ] **Step 5: Commit**

```bash
git add php/scrapers/Anilist.php php/index.php tests/test_php_backend.php
git commit -m "feat(php): implement AniList GraphQL scraper and catalog routes"
```

---

### Task 4: HiAnime & MegaCloud Stream Scraper with AES Decryption

**Files:**
- Create: `php/scrapers/Hianime.php`
- Modify: `php/index.php`
- Modify: `tests/test_php_backend.php`

**Interfaces:**
- Consumes: `Http`, `Cache`, `Response`
- Produces: `Hianime::episodes(string $slug): array`
- Produces: `Hianime::servers(string $epId): array`
- Produces: `Hianime::sources(string $epId, string $server, string $subType): array`
- Produces: `Hianime::decryptMegaCloud(string $ciphertext): string`

- [ ] **Step 1: Write test for HiAnime and MegaCloud AES decryption**

Test `decryptMegaCloud()` with standard AES-256-CBC key/IV vectors, and test episode list parsing.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test_php_backend.php`
Expected: FAIL

- [ ] **Step 3: Implement `php/scrapers/Hianime.php` and register stream routes**

Implement HTML parsing for HiAnime episodes and servers, embed URL extraction, and AES decryption using `openssl_decrypt()`. Register `/api/anime/{slug}/episodes`, `/api/episode`, and `/api/stream/resolve`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test_php_backend.php`
Expected: PASS for Task 4 tests.

- [ ] **Step 5: Commit**

```bash
git add php/scrapers/Hianime.php php/index.php tests/test_php_backend.php
git commit -m "feat(php): implement HiAnime scraper and MegaCloud AES decryptor"
```

---

### Task 5: Otakudesu Indonesian Subtitle Scraper

**Files:**
- Create: `php/scrapers/Otakudesu.php`
- Modify: `php/index.php`
- Modify: `tests/test_php_backend.php`

**Interfaces:**
- Consumes: `Http`, `Cache`, `Response`
- Produces: `Otakudesu::search(string $q): array`
- Produces: `Otakudesu::episodes(string $slug): array`
- Produces: `Otakudesu::stream(string $epSlug): array`

- [ ] **Step 1: Write test for Otakudesu scraper**

Test search parsing and episode extractor.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test_php_backend.php`
Expected: FAIL

- [ ] **Step 3: Implement `php/scrapers/Otakudesu.php` and register routes**

Implement Otakudesu search, episode list, and video player mirror extraction. Wire into `/api/episode` provider switcher.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test_php_backend.php`
Expected: PASS for Task 5 tests.

- [ ] **Step 5: Commit**

```bash
git add php/scrapers/Otakudesu.php php/index.php tests/test_php_backend.php
git commit -m "feat(php): implement Otakudesu Indonesian anime scraper"
```

---

### Task 6: AniSkip & Google Translate GTX Proxy

**Files:**
- Create: `php/scrapers/Aniskip.php`
- Create: `php/scrapers/Translate.php`
- Modify: `php/index.php`
- Modify: `tests/test_php_backend.php`

**Interfaces:**
- Consumes: `Http`, `Cache`, `Response`
- Produces: `Aniskip::skipTimes(int $malId, int $episodeNum): array`
- Produces: `Translate::translateVtt(string $vttUrl, string $targetLang): string`

- [ ] **Step 1: Write test for AniSkip and Google Translate GTX proxy**

Verify skip times formatting and VTT cue translation.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test_php_backend.php`
Expected: FAIL

- [ ] **Step 3: Implement `Aniskip.php` and `Translate.php`**

Register `/api/episode/skip_times`, `/api/player/sub`, and `/api/player/sub_chunk`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test_php_backend.php`
Expected: PASS for Task 6 tests.

- [ ] **Step 5: Commit**

```bash
git add php/scrapers/Aniskip.php php/scrapers/Translate.php php/index.php tests/test_php_backend.php
git commit -m "feat(php): add AniSkip timestamps and Google Translate GTX subtitle proxy"
```

---

### Task 7: Video Stream Relay & M3U8 Proxy (`/api/player/video`)

**Files:**
- Create: `php/player/VideoProxy.php`
- Modify: `php/index.php`
- Modify: `tests/test_php_backend.php`

**Interfaces:**
- Consumes: `Http`, `Response`
- Produces: `VideoProxy::serve(string $url, string $referer, ?string $rangeHeader): void`

- [ ] **Step 1: Write test for M3U8 manifest URL rewriting and SSRF guard**

Test that disallowed domains are rejected, and relative segment URLs inside `.m3u8` are rewritten to point to `/api/player/video?url=...`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test_php_backend.php`
Expected: FAIL

- [ ] **Step 3: Implement `php/player/VideoProxy.php`**

Stream segments directly to output with `curl_setopt($ch, CURLOPT_WRITEFUNCTION, ...)` without memory buffering. Forward HTTP `Range` headers.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test_php_backend.php`
Expected: PASS for Task 7 tests.

- [ ] **Step 5: Commit**

```bash
git add php/player/VideoProxy.php php/index.php tests/test_php_backend.php
git commit -m "feat(php): implement M3U8 rewrite and low-memory video segment streaming proxy"
```

---

### Task 8: Frontend Subfolder & Dynamic Base Path Compatibility

**Files:**
- Modify: `frontend/js/api.js`

**Interfaces:**
- Consumes: `window.location.pathname`
- Produces: Updated `apiBase()` that dynamically detects if Tatap is served under a subfolder (e.g. `/tatap/`) or domain root (`/`), preserving desktop compatibility.

- [ ] **Step 1: Test base URL resolution for root and subfolders**

Verify `apiBase()` returns `""` on root and `"/subfolder"` when hosted in subfolders without breaking localhost desktop mode.

- [ ] **Step 2: Update `frontend/js/api.js`**

Refactor `apiBase()` cleanly with zero side-effects on desktop/Android.

- [ ] **Step 3: Verify frontend script executes cleanly without syntax errors**

Run node/lint or browser test check.

- [ ] **Step 4: Commit**

```bash
git add frontend/js/api.js
git commit -m "fix(frontend): support dynamic subfolder base URL resolution"
```

---

### Task 9: Packaging & Deployment Script (`tools/build_php_web.py`)

**Files:**
- Create: `tools/build_php_web.py`
- Modify: `tests/test_php_backend.php`

**Interfaces:**
- Produces: `build_php_package(dist_dir, output_zip): str`
- Produces: `deploy_ftp(zip_path, host, user, password, remote_dir, ssl, port): bool`

- [ ] **Step 1: Write test for packaging script**

Test creating `dist/tatap-web-php.zip` and verifying required files (`index.html`, `api/index.php`, `.htaccess`, `data/.htaccess`) are present.

- [ ] **Step 2: Run test to verify it fails**

Run: `python3 -m unittest ...` or integration check
Expected: FAIL

- [ ] **Step 3: Implement `tools/build_php_web.py`**

Build ZIP bundle with clean production layout. Add `--deploy-ftp` CLI arguments and auto-upload implementation using standard library `ftplib`.

- [ ] **Step 4: Run test to verify it passes**

Run: `python3 tools/build_php_web.py`
Expected: Creates `dist/tatap-web-php.zip` successfully.

- [ ] **Step 5: Commit**

```bash
git add tools/build_php_web.py
git commit -m "feat(tools): add PHP shared hosting packager and FTP deployment CLI"
```

---

### Task 10: End-to-End Verification & Documentation

**Files:**
- Create: `docs/SHARED_HOSTING_GUIDE.md`
- Modify: `CHANGELOG.md`
- Run: `php tests/test_php_backend.php`
- Run: `pytest tests/test_semver.py`

- [ ] **Step 1: Run complete PHP backend test suite**

Run: `php tests/test_php_backend.php`
Expected: All tests pass (100% green).

- [ ] **Step 2: Write deployment guide `docs/SHARED_HOSTING_GUIDE.md`**

Clear step-by-step instructions for cPanel / DirectAdmin / shared hosting upload.

- [ ] **Step 3: Update `CHANGELOG.md`**

Record the new PHP shared hosting edition and packaging tool following Keep a Changelog.

- [ ] **Step 4: Commit**

```bash
git add docs/SHARED_HOSTING_GUIDE.md CHANGELOG.md
git commit -m "docs: add shared hosting deployment guide and changelog entry"
```
