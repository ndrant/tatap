# Error Log & Root Cause Analysis

## Incident 1: Repeated AudioContext Not Allowed to Start Warning
- **Severity:** P3 (Minor defect / Console hygiene)
- **Symptom:** Browser console printed `The AudioContext was not allowed to start. It must be resumed (or created) after a user gesture on the page.` ~24 times on mouseover.
- **Root Cause:** In `frontend/js/app.js`, `TatapSfx.play("tick")` was attached to `mouseover` events on `.poster-card` and navigation elements. Modern browsers (Chrome, Safari, Lightpanda) do not consider `mouseover` an interaction gesture, so calling `audioCtx.resume()` triggered the browser security policy warning.
- **Resolution:** Added `unlocked` state flag in `TatapSfx`. Hover ticks are silently suppressed until the user performs a true interaction gesture (`pointerdown`, `keydown`, `click`).
- **Verification:** Verified via Lightpanda `consoleLogs` — 0 warnings observed.

## Incident 2: Stream Resolve 404 for "Cyberpunk: Edgerunners 2"
- **Severity:** P1 (Major playback defect)
- **Symptom:** `GET http://tatapapp.test/api/stream/resolve?slug=195539&ep=1&mode=sub&q=best&source=otakudesu&force=true&title=Cyberpunk%3A%20Edgerunners%202` returned 404 Not Found.
- **Root Cause:**
  1. `php/scrapers/Anilist.php` returned AniList integer ID `195539` in the card's `'id'` field instead of the string slug. Frontend logic `a.id || a.slug` selected the numeric ID.
  2. In `php/index.php`, when `source=otakudesu`, `Otakudesu::resolveStream` was called with `$slug` instead of `$title ?: $slug`, causing Otakudesu to search for the numeric string `"195539"`.
  3. In `php/scrapers/Otakudesu.php`, `isTitleMatch` stripped season digits, falsely matching Season 1 instead of Season 2, and `resolveStream` stripped trailing `-2` thinking it was a database ID.
  4. In `php/scrapers/Hianime.php`, `resolveSlug` treated single digits as resolved IDs, and `extractSourcesFromEmbed` only supported `data-id` MegaCloud embeds, lacking support for `window.__P` XOR-obfuscated embeds used by ZokoAnime and VidPlay.
- **Resolution:**
  1. Set `'id' => $slug` and `'anilist_id' => $m['id']` in `Anilist.php`.
  2. Updated `resolveHandler` in `php/index.php` to extract title and resolve properly.
  3. Implemented `extractSeasonNum` in `Otakudesu.php` to strictly enforce season consistency.
  4. Added `window.__P` XOR deobfuscation (`otaku-embed-v1`) in `Hianime.php`.
- **Verification:** Both Otakudesu and HiAnime stream resolution test cases pass in `tests/test_php_backend.php`.

## Incident 3: Bookmark Check Response Format Discrepancy
- **Severity:** P2 (Functional defect in toggle state detection)
- **Symptom:** When clicking bookmark button on detail page, state check evaluated `r.data.bookmarked` as `undefined` when using PHP backend because PHP returned `data: true/false` directly rather than an associative array `data: ['bookmarked' => bool]`.
- **Root Cause:** Backend API divergence: Python FastAPI returned `ok({"bookmarked": await is_bookmarked(slug)})` (producing `{"data": {"bookmarked": bool}}`), whereas `php/index.php` returned `['data' => $isBookmarked]`.
- **Resolution:**
  1. Standardized `php/index.php` line 223 to return `['data' => ['bookmarked' => $isBookmarked], 'bookmarked' => $isBookmarked]`.
  2. Enhanced `checkModalBookmark` in `frontend/js/app.js` to inspect `r.data.bookmarked`, `r.bookmarked`, and `r.data` (boolean fallback).
- **Verification:** Verified via Lightpanda interactive browser testing — clicking `#tm-bookmark-btn` successfully toggled between `+ FAVORIT` and `TERSIMPAN` and back to `+ FAVORIT`.

## Incident 4: Missing HTMLMediaElement TimeRanges in Headless / WebView Engines
- **Severity:** P1 (Prevented ArtPlayer initialization in headless browsers and minimal WebViews)
- **Symptom:** When launching video playback in Lightpanda, player threw `TypeError: Cannot read properties of undefined (reading 'length')` at ArtPlayer's `loadedTime` getter (`e.buffered.length`).
- **Root Cause:** In headless browsers (Lightpanda, headless Chromium without codecs, or Android WebView environments with disabled media decoders), `HTMLMediaElement.prototype.buffered`, `played`, and `seekable` are `undefined`. ArtPlayer 5.4.0 assumes `video.buffered` is always a valid `TimeRanges` instance.
- **Resolution:** Added a lightweight polyfill at the top of `frontend/js/app.js` defining getter properties for `buffered`, `played`, and `seekable` returning mock `TimeRanges` objects with `length: 0`.
- **Verification:** Verified via Lightpanda — ArtPlayer 5.4.0 now initializes cleanly, rendering all video controls, resolution selectors, and subtitle menus without any exceptions.

## Incident 5: Quality / Variant Key Divergence (`quality` vs `q`)
- **Severity:** P2 (Caused `Cannot read properties of undefined (reading 'toLowerCase')` on variant render)
- **Symptom:** During player modal setup, `renderVariants()` threw an unhandled TypeError when accessing `v.q.toLowerCase()`.
- **Root Cause:** In `php/scrapers/Hianime.php` and `php/scrapers/Otakudesu.php`, variants and picked objects used `'quality' => 'auto'` instead of `'q' => 'auto'`, whereas Python backend used `'q'`. Frontend called `v.q.toLowerCase()` without checking for `v.quality`.
- **Resolution:**
  1. Updated PHP scrapers to include both `'q' => 'auto'` and `'quality' => 'auto'`.
  2. Hardened `renderVariants()`, `picked.q`, and `switchQ()` in `frontend/js/app.js` with `String(v.q || v.quality || "auto")`.
- **Verification:** Verified via Lightpanda — player metadata rendered `ep 1 · SUB · AUTO` and quality dropdown populated seamlessly.

## Incident 6: Google GTX HTTP 429 Rate Limiting in PHP
- **Severity:** P2 (Subtitle translation fallback failure)
- **Symptom:** `POST /api/player/sub_chunk` returned untranslated text because `translate.googleapis.com/translate_a/single?client=gtx` returned HTTP 429 Too Many Requests.
- **Root Cause:** Google restricts direct GET calls to `translate.googleapis.com` with `client=gtx` from automated servers. Python backend solved this using pooled POST endpoints across `clients5.google.com` with `client=dict-chrome-ex`.
- **Resolution:** Upgraded `php/scrapers/Translate.php` with multi-endpoint fallback:
  1. `https://clients5.google.com/translate_a/t?client=dict-chrome-ex`
  2. `https://clients5.google.com/translate_a/t?client=tw-ob`
  3. `https://translate.google.com/translate_a/t?client=dict-chrome-ex`
  4. `https://translate.googleapis.com/translate_a/single?client=dict-chrome-ex&dt=t`
- **Verification:** Verified via curl — translated `"Hello world, welcome to Tatap!"` to `"Halo dunia, selamat datang di Tatap!"` with instant cache hits.

## Incident 7: Missing `use Tatap\Core\Database;` in `php/index.php`
- **Severity:** P2 (Caused `Class "Tatap\Database" not found` on `/api/player/sub_cache_save`)
- **Symptom:** `POST /api/player/sub_cache_save` failed with 500 status when saving compiled VTT subtitles.
- **Root Cause:** `Database::setSubtitleCache` was called within the `Tatap` namespace without importing `Tatap\Core\Database`.
- **Resolution:** Added `use Tatap\Core\Database;` to imports in `php/index.php`.
- **Verification:** Verified live via curl — returned `{"success": true, "data": true}` and saved VTT to SQLite.

## Incident 8: "Terbaru" Schedule Filter Returned Static Airing Anime
- **Severity:** P1 (Day filtering did not update in real time)
- **Symptom:** In "Terbaru", clicking day chips (Senin–Minggu) did not update the anime list to match the selected day; it constantly showed the exact same static seasonal airing items.
- **Root Cause:** `GET /api/schedule` in `php/index.php` accepted `$_GET['day']`, but discarded it and called `Anilist::seasonal('airing')`, returning the exact same array regardless of day. `Anilist.php` lacked the GraphQL `airingSchedules` range query.
- **Resolution:**
  1. Implemented `Anilist::schedule($day, $safeMode)` in `php/scrapers/Anilist.php` to query AniList GraphQL `airingSchedules` within the Monday 00:00 WIB to Sunday 23:59 WIB window, grouping results into Monday–Sunday buckets.
  2. Updated `php/index.php` to route `/api/schedule` and `/api/upcoming-episodes` to `Anilist::schedule`.
- **Verification:** Verified in Lightpanda: clicking Senin (7 items), Selasa (11 items, is-today), Sabtu (27 items), Minggu (32 items) dynamically updates title and cards with 0 errors.

## Incident 9: Source Switcher Did Not Refresh Episodes in Real Time
- **Severity:** P1 (Episodes and view stayed on previous source)
- **Symptom:** Switching source between HiAnime and Otakudesu did not reload the episode list when viewing anime details or browsing catalog.
- **Root Cause:**
  1. `switchSource()` in `frontend/js/app.js` only changed `cur.source` without calling `loadEps()` or `loadView()`.
  2. `_epsMemCache` stored items by `thisSlug` alone, returning stale cached episodes from the previous provider.
  3. `window.Tatap.episodes()` in `frontend/js/api.js` did not send the `source` parameter to the backend.
  4. `GET /api/anime/{slug}/episodes` in `php/index.php` always invoked `Hianime::episodes()` first, ignoring `$_GET['source']`.
- **Resolution:**
  1. Added `source` parameter handling to `GET /api/anime/{slug}/episodes` in `php/index.php`.
  2. Enhanced `Otakudesu::episodes` with title fallback search when direct `/anime/{slug}/` 404s.
  3. Partitioned `_epsMemCache` by `(cur.source || "hianime") + ":" + thisSlug`.
  4. Updated `switchSource()` to trigger `loadEps()` when on detail view and `loadView()` when on catalog view.
- **Verification:** Verified in Lightpanda: clicking source switch dynamically toggles badges and re-fetches episodes from the selected provider in real time.
