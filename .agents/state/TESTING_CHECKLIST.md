# Testing Checklist

## 1. System & Protocol Verification
| Feature | Scenario | Test Environment | Expected Result | Actual Result | Status | Notes |
|---------|----------|------------------|-----------------|---------------|--------|-------|
| SemVer 2.0.0 | Version consistency across files | CLI / macOS | All 7 files match version 2.12.7 | 100% consistent | **PASS** | `python3 tools/bump_version.py --check` |
| SemVer Automated Tests | Pytest test suite | Python 3.9 venv | 5/5 assertions pass | 5/5 passed in 0.08s | **PASS** | `pytest tests/test_semver.py` |
| PHP Backend Test Suite | Unit & Integration tests | PHP 8.5 CLI | 84/84 assertions pass | 84/84 passed | **PASS** | `php tests/test_php_backend.php` |

## 2. Browser E2E Workflows (Lightpanda)
| Feature | Scenario | Test Environment | Expected Result | Actual Result | Status | Notes |
|---------|----------|------------------|-----------------|---------------|--------|-------|
| Application Load | Open `http://tatapapp.test/` | Lightpanda 1.0.3 | HTTP 200, clean DOM, ArtPlayer initialized | HTTP 200, clean render, 0 errors | **PASS** | Verified via `goto` & `consoleLogs` |
| AudioContext Hygiene | Mouseover & initial render | Lightpanda 1.0.3 | Zero security policy warnings on hover | No `AudioContext was not allowed` warnings | **PASS** | Gated behind user gesture `unlocked` flag |
| Interactive Discovery | Survey interactive elements | Lightpanda 1.0.3 | Returns all buttons, tabs, inputs, cards | 74+ interactive elements discovered | **PASS** | Verified via `interactiveElements` |
| Search Interaction | Click search, fill "Frieren", press Enter | Lightpanda 1.0.3 | Spotlight modal opens, input receives value | Input `#cmd` filled, zero console exceptions | **PASS** | Verified via `fill` & `press` |
| Detail Page Navigation | Click poster card | Lightpanda 1.0.3 | `#detail-page` opens with title, banner, episodes | Detail rendered with episodes & source badge | **PASS** | Verified via live evaluation |
| Source Switching (Live) | Click `#tm-source-btn` / `#sidebar-switch-source` | Lightpanda 1.0.3 | Switches HiAnime ⇄ Otakudesu ⇄ AniChan, reloads episodes in real time | Source badge & episode list dynamically reloaded | **PASS** | Verified via live evaluation |
| Schedule Filter (Senin–Minggu) | Click day chips on "Terbaru" | Lightpanda 1.0.3 | Day chip highlights, title & cards update dynamically | Senin (7 items), Selasa (11 items, is-today), Sabtu (27 items), Minggu (32 items) | **PASS** | Verified live in real time |
| Catalog Cross-Filter | Apply Sort: "score" + Format: "TV" + Genre: "Action" | Lightpanda 1.0.3 | Catalog updates to cross-filtered anime | Title updates to `Katalog (Genre: Action · Skor Tertinggi)` with 24 cards | **PASS** | Verified live via `evaluate` |
| Filter Reset | Click `#cf-reset-btn` | Lightpanda 1.0.3 | Restores default popular catalog | Title restores to `Katalog Populer` with 24 cards | **PASS** | Verified live via `evaluate` |
| Bookmark Toggle | Add & remove favorite | Lightpanda 1.0.3 | Button toggles `+ FAVORIT` <-> `TERSIMPAN` | State transitioned accurately on click | **PASS** | Verified via `click` |
| Player Modal Launch | Click "Putar Episode 1" | Lightpanda 1.0.3 | Player modal opens with ArtPlayer controls | ArtPlayer controls, resolution, subtitles rendered | **PASS** | TimeRanges polyfill resolved |
| Navigation Tabs Sweep | Switch Katalog, Musim, Terbaru, Airing, Riwayat, Favorit | Lightpanda 1.0.3 | Smooth view rendering without errors | All 6 tabs switched with 0 console warnings | **PASS** | Verified |
| Help Overlay | Open command palette help (:bantuan) | Lightpanda 1.0.3 | Command list displays and dismisses on Esc | Verified | **PASS** | Verified |
| AI Fansub Settings Modal | Open AI modal and dismiss | Lightpanda 1.0.3 | Template providers and persona fields render | Rendered and closed cleanly | **PASS** | Verified |

## 3. Backend & API Endpoints
| Feature | Scenario | Test Environment | Expected Result | Actual Result | Status | Notes |
|---------|----------|------------------|-----------------|---------------|--------|-------|
| Ping | `GET /api/ping` | Apache 2.4 / PHP | `{"success": true, "message": "pong"}` | 200 OK | **PASS** | Verified |
| Version | `GET /api/version` | Apache 2.4 / PHP | `{"success": true, "version": "v2.12.7"}` | 200 OK | **PASS** | Verified |
| Health | `GET /api/health` | Apache 2.4 / PHP | `{"success": true, "status": "ok"}` | 200 OK | **PASS** | Verified |
| Catalog | `GET /api/catalog?page=1` | Apache 2.4 / PHP | 200 with list of cards (`id = slug`) | 200 OK | **PASS** | Verified |
| Schedule 7-Day | `GET /api/schedule?day=monday` | Apache 2.4 / PHP | 200 with Monday schedule items & day metadata | 200 OK (`weekday: monday`, `day_name: Senin`) | **PASS** | Verified |
| Browse Filtered | `GET /api/browse?sort=score&type=TV` | Apache 2.4 / PHP | 200 with TV anime sorted by score | 200 OK (24 items) | **PASS** | Verified |
| Episodes by Source | `GET /api/anime/{slug}/episodes?source=otakudesu` | Apache 2.4 / PHP | 200 with Otakudesu episodes and source: otakudesu | 200 OK | **PASS** | Verified |
| Bookmark Check | `GET /api/bookmarks/check?slug=...` | Apache 2.4 / PHP | Returns `data: {bookmarked: bool}` | 200 OK | **PASS** | Parity resolved |
| Stream Resolve | Otakudesu / HiAnime streams | Apache 2.4 / PHP | Master HLS playlist URL returned | 200 OK | **PASS** | Failover supported |
| Sub Chunk Translate | `POST /api/player/sub_chunk` | Apache 2.4 / PHP | Translates text cues to Indonesian | 200 OK ("Halo dunia, selamat datang di Tatap!") | **PASS** | Multi-endpoint GTX verified |
| Sub Cache Save | `POST /api/player/sub_cache_save` | Apache 2.4 / PHP | Persists VTT to SQLite `subtitle_cache` | 200 OK (`data: true`) | **PASS** | SQLite persistent storage verified |
| Cached Sub Retrieval | `GET /api/player/sub?url=...` | Apache 2.4 / PHP | Direct 0.005s retrieval from SQLite | Returns VTT text from SQLite | **PASS** | Verified |
