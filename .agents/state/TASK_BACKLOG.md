# Task Backlog

## Completed Tasks
- [x] **Autonomous Development System Initialization**: Configured `.agents/skills/autonomous-development/SKILL.md`, `.agents/skills/lightpanda/SKILL.md`, `.agents/skills/context7-mcp/SKILL.md`, and `.agents/mcp_config.json`.
- [x] **Tool Verification & Live Integration**: Verified Lightpanda browser MCP and Context7 MCP documentation retrieval.
- [x] **AudioContext Warning Elimination**: Gated `TatapSfx.play("tick")` behind user interaction gesture `unlocked` state in `frontend/js/app.js`.
- [x] **Catalog Card Slug ID Mapping**: Mapped `'id' => $slug` and `'anilist_id' => $m['id']` in `php/scrapers/Anilist.php`.
- [x] **Otakudesu Season Consistency**: Implemented `extractSeasonNum` and updated `isTitleMatch` in `php/scrapers/Otakudesu.php` to prevent Season 1 vs Season 2 mismatch (Directive 7.3).
- [x] **HiAnime XOR Embed Decryptor**: Added `window.__P` extraction using key `otaku-embed-v1` in `Hianime::extractSourcesFromEmbed`.
- [x] **Stream Failover Integration**: Added automatic failover between Otakudesu and HiAnime in `php/index.php`.
- [x] **Bookmark Check Parity Fix**: Standardized `/api/bookmarks/check` payload in `php/index.php` and made `frontend/js/app.js` resilient.
- [x] **Resolution & Quality Property Parity (Incident 5)**: Standardized `q` and `quality` in PHP scrapers and hardened `renderVariants()` in `frontend/js/app.js`.
- [x] **HTMLMediaElement TimeRanges Polyfill (Incident 4)**: Polyfilled `buffered`, `played`, and `seekable` on `HTMLMediaElement.prototype` to allow ArtPlayer 5.4.0 to initialize cleanly in headless and codec-free environments.
- [x] **Google GTX Multi-Endpoint Translation Resilience (Incident 6)**: Upgraded `php/scrapers/Translate.php` with POST pooling across `clients5.google.com` (`dict-chrome-ex`, `tw-ob`), resolving 429 rate-limiting.
- [x] **Persistent Subtitle Cache & Chunk Stash (P3)**: Added SQLite `subtitle_cache` table to `php/core/Database.php`, wired `POST /api/player/sub_cache_save` in `php/index.php`, and updated `Translate::fetchAndTranslateVtt` to read from persistent cache.
- [x] **Jelajah Terbaru Day Schedule Real-Time Filtering**: Added `Anilist::schedule` GraphQL `airingSchedules` range fetcher and 7-day Monday–Sunday grouping in `php/scrapers/Anilist.php`, connected `/api/schedule` in `php/index.php`, and verified live in Lightpanda across all day chips (Senin–Minggu).
- [x] **Real-Time Anime Source Switching (HiAnime ⇄ Otakudesu ⇄ AniChan)**: Added `source` parameter to `GET /api/anime/{slug}/episodes`, enhanced `Otakudesu::episodes` with title fallback search, partitioned `_epsMemCache` by source, and updated `switchSource()` in `frontend/js/app.js` to reload episodes immediately in real time on detail page and player modal.
- [x] **Catalog Cross-Filter Support on `/api/browse`**: Implemented `Anilist::browse()` supporting `sort`, `type`, `format`, `status`, `season`, `year`, and `genre`, and connected `/api/browse` in `php/index.php`. Verified live cross-filtering in Lightpanda.
- [x] **Git Upstream Rebase & Synchronization**: Resolved divergence between local commits and remote upstream commits (`910cd91`), rebased cleanly with 0 conflicts, and pushed to `origin/main` (`55fa817`).
- [x] **E2E Lightpanda Modal & Tab Sweep**: Tested all navigation tabs, filter modals, help overlays, and AI fansub settings with 0 console warnings/errors.
- [x] **Pytest Environment Configuration**: Installed pytest in `./venv/bin/pytest` and verified `tests/test_semver.py` (5/5 PASS).
- [x] **Regression & Staging Sync**: Expanded test suite to 84 assertions in `php tests/test_php_backend.php` (all PASS) and synced distribution via `python3 tools/build_php_web.py`.
- [x] **Mobile Adaptive Layout & Thumb-Zone Ergonomics (Pure Native CSS Zero-Build)**: Implemented responsive mobile layout (< 768px) with Bottom Navigation Bar (5 tabs), Spotlight mobile search button in header, vertical reflow on Anime Detail Page, Sticky Top Video Player in portrait with in-player episode switcher (`#pm-mobile-panel`), Mobile Bottom Sheet modals, and fluid 2-column card grid on small phones without any npm/Tailwind dependencies.
- [x] **Explicit Mobile Mode Toggle & Frame Simulation**: Added device mode switcher (`TatapDeviceMode`), `#btn-toggle-device-mode` in header actions, `#pop-toggle-device-mode` in Apple options popover, `#drawer-toggle-device-mode` in drawer, `#sidebar-toggle-device-mode` in sidebar, `⌘M` / `Ctrl+M` keyboard shortcut, and terminal commands `:mobile`, `:desktop`, `:device` allowing instant preview of 440px phone simulation on desktop screens. Verified live with Lightpanda MCP.
- [x] **Responsive 3-Tier Artplayer Controls Architecture & Playback Interactivity (`.art-controls`, `antislop-ui`)**: Restored ALL 18 player controls on mobile without suppression (`display: none` eliminated for Volume, Screenshot, MPV, Web Fullscreen, separators). Structured into a zero-overflow 3-tier layout: Row 1 (Seekbar 100% full width), Row 2 (Playback transport: Prev, Play, Next, Volume, Time 00:00 / 23:45), and Row 3 (Actions: Quality, Subtitles, TR-ID, AI, Screenshot, MPV, Settings, PiP, Web Fullscreen, Fullscreen, Close). Fixed playback tap interactivity: single tap on video stage reliably wakes up and toggles controls (`art.controls.show = true` and `TvNavigationManager.showPlayerControls()`). Verified live in Lightpanda browser with 0 console errors.

## Pending Tasks
- [ ] **P4 — Directive Adherence**: Adhere strictly to Directive 8 (No version bump while v2.12.7 is in draft) and Directive 9 (Zero desktop/Android binary build during web iterations).
- [ ] **P5 — Post-Release Sync Preparation**: When v2.12.7 draft is published officially by user (`isDraft: false`), run `python3 tools/bump_version.py --sync-readme` to update download links.
- [ ] **P6 — Ongoing Performance & Scraper Health Monitoring**: Monitor AniList GraphQL rate limits and Otakudesu mirror stability.
