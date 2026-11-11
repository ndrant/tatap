# Tatap Web & Multiplatform — Project Status

**Current Version:** v2.12.7 (Draft in-progress — Directive 8 enforced: NO version bump until public release)  
**Git Head:** `55fa817` (Synchronized with `origin/main`)  
**Environment:** macOS (Apache 2.4 via FlyEnv + PHP 8.5 FastCGI & Python 3.9 / 3.11)  
**Web Endpoint:** `http://tatapapp.test/` (DocumentRoot: `dist/tatap-web-php/`)  
**Autonomous Mode:** ACTIVE / CONTINUOUS EXECUTION  
**Overall Status:** GREEN / STABLE / PRODUCTION-READY  
**Last Checkpoint:** 2026-11-11 01:08 WIB  

---

## 1. Integrations Status

| Integration / Service | Provider / Binary | Verification Details | Status |
|-----------------------|-------------------|----------------------|--------|
| **Lightpanda Browser** | `/usr/local/bin/lightpanda` v1.0.3 | Navigation (`goto`), page tree (`tree`), element discovery (`interactiveElements`), interactions (`click`, `fill`, `press`), modal lifecycle, and zero-error console auditing (`consoleLogs`) verified live | **ACTIVE / PASS** |
| **Context7 MCP** | `~/.gemini/antigravity/mcp/context7` | Library ID resolution (`resolve-library-id`) and live API doc retrieval (`query-docs`) verified | **ACTIVE / PASS** |
| **Web Runtime (PHP)** | Apache 2.4 + PHP 8.5 FastCGI | Full routing, SSRF guards, SQLite session isolation, scraper failover, M3U8 proxy, and SQLite persistent subtitle cache verified | **ACTIVE / PASS** |
| **Test Suites** | `tests/test_php_backend.php` & `pytest tests/test_semver.py` | 84 PHP backend assertions + 5 SemVer test assertions passing | **100% PASS** |

---

## 2. Recent Autonomous Self-Healing & Hardening

1. **Mobile Adaptive Layout & Thumb-Zone Ergonomics (Pure Native CSS Zero-Build):**
   - **Thumb-Zone Bottom Navigation Bar (`#mobile-bottom-nav`):** Integrated 5 thumb-friendly tabs (*Katalog*, *Musim*, *Terbaru*, *Riwayat*, *Favorit*) with safe-area insets (`env(safe-area-inset-bottom)`), active glow indicators, and tactile audio feedback (`TatapSfx.play("tab")`) on viewports `< 768px`.
   - **Responsive Header & Quick Search Button (`#btn-search-mobile`):** Added a one-tap mobile search icon in `.head-actions` that opens Spotlight search smoothly on touch screens.
   - **Anime Detail Page Vertical Reflow (`#detail-page`):** Unlocked vertical scrolling on `body.in-detail-view` for viewports `< 768px`, allowing full scrollable view with ambient backdrop, 2:3 poster header, full-width quick action buttons (*Putar Sekarang*, *+ Favorit*, *Ganti Sumber*), expandable synopsis (*Selengkapnya ▼* / *Ringkas ▲*), and responsive episode grid with $\ge 44\text{px}$ touch targets.
   - **Sticky Top Player & Portrait Episode Panel (`#pm-mobile-panel`):** Video player docks 16:9 at the top in portrait mode on mobile with episode selector and quick Prev/Next navigation below, expanding to 100vw x 100vh cinema mode on landscape or fullscreen.
   - **Mobile Bottom Sheet Modals:** Modals (Catalog Filter, Genre Filter, Settings) reflow into smooth bottom sheets sliding up from the bottom with 20px top rounded corners.
   - **Fluid 2-Column Poster Grid:** Formatted `.poster-grid` into a balanced 2-column layout on small phones (`< 480px`) with 2-line title clamps and clean 2:3 card aspect ratio.
2. **Jelajah Terbaru Real-Time Day Schedule Filtering:**
   - Added multi-page GraphQL `airingSchedules` range fetcher (`monMidUtc` to `sunEndUtc`) and 7-day Monday–Sunday grouping in `php/scrapers/Anilist.php` (`Anilist::schedule`).
   - Wired `GET /api/schedule` and `GET /api/upcoming-episodes` in `php/index.php` to return dynamic, day-specific anime airing buckets with `day`, `weekday`, `day_name`, `current_day`, `is_today`, and `counts`.
   - Verified live in Lightpanda: clicking Senin (7 items), Selasa (11 items, is-today), Sabtu (27 items), Minggu (32 items) dynamically re-renders results title and cards in real time with 0 console errors.
2. **Real-Time Anime Source Switching (HiAnime ⇄ Otakudesu):**
   - Added `source` parameter support to `GET /api/anime/{slug}/episodes` in `php/index.php`, prioritizing `Otakudesu::episodes` when `source=otakudesu` or `otakudesu:` slug.
   - Enhanced `Otakudesu::episodes` in `php/scrapers/Otakudesu.php` with title fallback search when direct `/anime/{slug}/` 404s.
   - Updated `window.Tatap.episodes(slug, title, source)` in `frontend/js/api.js`.
   - Partitioned `_epsMemCache` in `frontend/js/app.js` by `(cur.source || "hianime") + ":" + thisSlug`.
   - Updated `switchSource()` in `frontend/js/app.js` to immediately reload episode list (`loadEps()`) when on detail view, reload player stream if playing, and reload active view (`Tui.loadView()`).
   - Verified live in Lightpanda: switching source via `#tm-source-btn` or `#sidebar-switch-source` dynamically updates source badges and reloads episodes live.
3. **Cross-Filter Support on `/api/browse`:**
   - Implemented `Anilist::browse()` in `php/scrapers/Anilist.php` with GraphQL parameters for `sort` (`score`, `popularity`, `latest`, `trending`, `az`), `format` / `type` (`TV`, `MOVIE`, `OVA`, `ONA`, `SPECIAL`), `status`, `season`, `year`, and `genre`.
   - Wired `GET /api/browse` in `php/index.php` to accept all cross-filter query parameters.
   - Verified live in Lightpanda: applying "Skor Tertinggi" + "TV" + "Action" dynamically queries and loads 24 matching anime.
4. **Persistent SQLite Subtitle Caching:**
   - Added `subtitle_cache` table to `php/core/Database.php` with `getSubtitleCache`, `setSubtitleCache`, and `deleteSubtitleCache`.
   - Wired `POST /api/player/sub_cache_save` in `php/index.php` to persist compiled WebVTT chunks into SQLite and file cache.
5. **Git Synchronization & Rebase:**
   - Rebased local commits onto `origin/main` reconciling upstream translation model updates (`b48655c`, `dda3fcd`, `910cd91`).
   - Successfully pushed rebased branch to `origin/main` (`55fa817`), maintaining clean working tree.

---

## 3. Automated Test Verification

- `php tests/test_php_backend.php` — **84 / 84 PASS (100%)**
- `./venv/bin/pytest tests/test_semver.py` — **5 / 5 PASS (100%)**
- `python3 tools/bump_version.py --check` — **100% SemVer 2.0.0 COMPLIANT** (v2.12.7)
- Lightpanda Interactive Verification Matrix:
  - Sidebar Navigation (Katalog, Musim, Terbaru, Masih Tayang, Riwayat, Favorit): **100% PASS**
  - "Terbaru" Day-by-Day Schedule Filters (Senin–Minggu): **100% PASS**
  - Source Switcher (HiAnime ⇄ Otakudesu ⇄ AniChan): **100% PASS**
  - Catalog Cross-Filter (Urutan, Tipe, Season, Genre): **100% PASS**
  - Modals (Spotlight Search, Genre Filter, Catalog Filter, Bantuan, AI Fansub): **100% PASS**
  - Player Initializer & Subtitle Menu Rendering: **100% PASS**
  - Console Diagnostics: **0 warnings, 0 errors**
