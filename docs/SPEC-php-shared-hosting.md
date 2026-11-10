# Spec: PHP Shared Hosting Edition & Deployment Tool (Tatap Web PHP)

## 1. Objective
Enable Tatap to be hosted and deployed on standard shared web hosting (cPanel, DirectAdmin, Apache, LiteSpeed) running standard PHP (7.4 to 8.3+) with zero external dependencies (no Composer, no Python daemon on the server). The system consists of:
1. A standalone, modular PHP backend (`php/`) providing parity for core Tatap endpoints (`/api/*`).
2. Multi-visitor session/token isolation for watch history and bookmarks stored in a protected local SQLite database.
3. Subfolder & root domain auto-detection in frontend and Apache `.htaccess`.
4. A Python build and automated upload tool (`tools/build_php_web.py`) producing `dist/tatap-web-php.zip` with optional `--deploy-ftp` functionality.

---

## 2. Tech Stack & Commands

### Tech Stack
- **Backend Language**: Pure Vanilla PHP (compatible with PHP 7.4, 8.0, 8.1, 8.2, 8.3).
- **PHP Extensions Required**: `ext-curl`, `ext-pdo_sqlite`, `ext-openssl`, `ext-json`, `ext-mbstring` (all default-enabled on standard shared hosting).
- **Web Server**: Apache / LiteSpeed with `mod_rewrite` via `.htaccess` (fallback routing to `api/index.php`).
- **Database**: SQLite3 via PDO (`data/tatap.sqlite`).
- **Packaging & Deployment**: Python 3 standard library (`ftplib`, `zipfile`, `argparse`, `os`, `sys`).
- **Frontend**: Existing vanilla HTML/JS/CSS, adapted for dynamic base path resolution.

### Commands
- **Local PHP Dev Server**:
  ```bash
  php -S 127.0.0.1:8080 -t php_dist/
  ```
- **Build Distribution Zip**:
  ```bash
  python3 tools/build_php_web.py
  ```
  *Output*: `dist/tatap-web-php.zip`
- **Build & Deploy via FTP/FTPS**:
  ```bash
  python3 tools/build_php_web.py --deploy-ftp --ftp-host ftp.example.com --ftp-user user --ftp-pass secret --ftp-dir /public_html
  ```
- **Lint / Syntax Check PHP files**:
  ```bash
  find php/ -name "*.php" -exec php -l {} \;
  ```
- **Run Automated PHP Backend Tests**:
  ```bash
  php tests/test_php_backend.php
  ```

---

## 3. Project Structure

```
tatap/
├── php/                                # Standalone PHP backend source
│   ├── index.php                       # Front controller for /api/* routes
│   ├── config.php                      # Environment, cache paths, base URL detection
│   ├── core/
│   │   ├── Router.php                  # Lightweight URI/HTTP method dispatcher
│   │   ├── Response.php                # JSON / Stream / M3U8 response emitter
│   │   ├── Database.php                # PDO SQLite connection & schema initialization
│   │   ├── Session.php                 # Visitor session / token generator & resolver
│   │   ├── Http.php                    # cURL helper with timeout, headers, & redirect control
│   │   └── Cache.php                   # Simple file-based TTL cache for API responses
│   ├── scrapers/
│   │   ├── Anilist.php                 # AniList GraphQL queries (search, catalog, detail, genres)
│   │   ├── Hianime.php                 # HiAnime scraper + MegaCloud/RabbitStream AES decryptor
│   │   ├── Otakudesu.php               # Otakudesu Indonesian subtitle scraper & stream extractor
│   │   ├── Aniskip.php                 # AniSkip intro/outro timestamp lookup
│   │   └── Translate.php               # Google Translate GTX proxy for subtitle cues/VTT
│   ├── player/
│   │   ├── VideoProxy.php              # M3U8 manifest parser/rewriter & video segment streamer
│   │   └── Subtitle.php                # Subtitle track fetcher, chunker, & format converter
│   └── user/
│       ├── History.php                 # Visitor watch history CRUD (isolated per visitor token)
│       ├── Bookmarks.php               # Visitor bookmarks CRUD
│       └── Settings.php                # Visitor player preferences (sub size, opacity, etc.)
├── tools/
│   └── build_php_web.py                # Bundles frontend + php into zip, with optional FTP deploy
├── tests/
│   └── test_php_backend.php            # Automated CLI integration test suite for PHP endpoints
├── .htaccess.example                   # Production Apache/LiteSpeed rewrite rules
└── docs/
    ├── intent/php-shared-hosting.md    # Confirmed statement of intent
    └── SPEC-php-shared-hosting.md      # This specification
```

### Deployed Shared Hosting Bundle Layout (`tatap-web-php.zip`)
When extracted into `public_html/` or a subfolder, the root contains:
```
public_html/
├── index.html                          # Frontend SPA
├── bootscreen.html                     # Splash / bootloader
├── favicon.ico
├── assets/                             # Audio SFX, fonts, images
├── css/                                # style.css
├── js/                                 # app.js, api.js, term.js, hls.min.js
├── api/
│   ├── index.php                       # PHP front controller
│   └── ... (php backend code)
├── data/
│   ├── .htaccess                       # "Deny from all" (blocks direct browser access)
│   ├── tatap.sqlite                    # Visitor database
│   └── cache/                          # Cached API responses
└── .htaccess                           # URL rewrites: route /api/* to api/index.php
```

---

## 4. Code Style & Conventions

- **Formatting**: PSR-12 compliant, 4 spaces indent, strict type hints where compatible with PHP 7.4+ (`declare(strict_types=1);`).
- **No Global Leakage**: All classes namespaced under `Tatap\...` or encapsulated inside static helper classes.
- **Defensive Error Handling**: Scraper failures must catch exceptions and return graceful JSON errors (`{"status": "error", "message": "..."}`) rather than emitting PHP warnings or 500 fatal errors.

### Example Code Snippet (`php/core/Response.php`):
```php
<?php
declare(strict_types=1);

namespace Tatap\Core;

class Response
{
    public static function json($data, int $statusCode = 200, array $headers = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Visitor-Token');
        foreach ($headers as $k => $v) {
            header("$k: $v");
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
```

---

## 5. Security & Multi-Visitor Isolation

1. **Database Protection**:
   - `data/.htaccess` contains `Deny from all` (Apache 2.2/2.4 compatible `Require all denied`).
   - Root `.htaccess` rejects any request matching `\.(sqlite|db|log|lock|ini)$`.
2. **Visitor Isolation**:
   - Every visitor receives a secure, random 32-character token stored in `HttpOnly` cookie `tatap_token` (and mirrored in `localStorage.tatap_visitor_token` sent via header `X-Visitor-Token`).
   - All database queries for history and bookmarks include `WHERE visitor_token = ?`.
3. **SSRF & Stream Proxy Whitelisting**:
   - `/api/player/video` validates URLs against allowed streaming domain patterns (MegaCloud, RapidCloud, Broforgotsave, Akamaized, Google Translate, etc.) to prevent Open Proxy / Server-Side Request Forgery abuse.

---

## 6. Testing Strategy

1. **Syntax & Linter**:
   - `php -l` on all PHP files in CI/tools.
2. **Automated Integration Test (`tests/test_php_backend.php`)**:
   - Executes via CLI `php tests/test_php_backend.php`.
   - Tests `api/ping`, `api/version`, `api/search?q=naruto`, `api/seasonal`, and AniList metadata parsing.
   - Tests AES decryption helper against known MegaCloud ciphertexts.
   - Tests SQLite visitor isolation (Token A cannot read Token B's bookmarks).
3. **Web Browser Verification**:
   - Runs local PHP server (`php -S 127.0.0.1:8768 -t dist/tatap-web-php/`).
   - Verifies frontend search, catalog browsing, and video playback live in browser.

---

## 7. Boundaries

- **Always**:
  - Keep PHP code compatible with PHP 7.4 through PHP 8.3+.
  - Sanitize and validate all incoming query parameters.
  - Set CORS headers on API endpoints to allow smooth browser access.
  - Preserve all existing Python backend, Android Chaquopy, and desktop packaging code untouched.
- **Ask First**:
  - Adding any external PHP library or changing server extension requirements.
  - Altering the existing frontend JavaScript files in a way that affects the Python desktop version.
- **Never**:
  - Require `composer install` on the user's shared hosting server.
  - Expose `tatap.sqlite` without `.htaccess` protection.
  - Store unencrypted passwords or bypass the Hentai zero-tolerance filter.

---

## 8. Success Criteria

- [ ] `php/` implements all core endpoints needed by `frontend/`:
  - `/api/ping`, `/api/version`, `/api/health`
  - `/api/search`, `/api/filters`, `/api/genres`, `/api/seasonal`, `/api/schedule`, `/api/browse`
  - `/api/anime/{slug}/detail`, `/api/anime/{slug}/episodes`, `/api/anime/{slug}/relations`
  - `/api/episode` (stream resolution with AES decrypt), `/api/player/video` (M3U8 proxy & segment relay)
  - `/api/episode/skip_times` (AniSkip), `/api/player/sub` (subtitles & GTX translation)
  - `/api/history`, `/api/bookmarks`, `/api/settings` (with visitor token isolation)
- [ ] `frontend/` auto-resolves base URL correctly whether installed at `https://domain.com/` or `https://domain.com/subfolder/`.
- [ ] `tools/build_php_web.py` successfully generates `dist/tatap-web-php.zip` with clean file layout and production `.htaccess`.
- [ ] `--deploy-ftp` CLI option connects to FTP/FTPS, creates target directories, and uploads the deployment bundle.
- [ ] Automated tests in `tests/test_php_backend.php` pass with 100% success.
- [ ] Existing desktop packaging and tests (`pytest tests/test_semver.py`) remain completely unbroken.

---

## 9. Open Questions

- None currently identified. All architectural decisions (standalone PHP distribution, full core stack, visitor isolation, subfolder support, Python packaging CLI with `--deploy-ftp`) were aligned and confirmed.
