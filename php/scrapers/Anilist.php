<?php
declare(strict_types=1);

namespace Tatap\Scrapers;

use Tatap\Core\Http;
use Tatap\Core\Cache;

class Anilist
{
    public const API_URL = 'https://graphql.anilist.co';

    public static array $DAYS_KEYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    public static array $EN_TO_ID = [
        'monday' => 'Senin',
        'tuesday' => 'Selasa',
        'wednesday' => 'Rabu',
        'thursday' => 'Kamis',
        'friday' => 'Jumat',
        'saturday' => 'Sabtu',
        'sunday' => 'Minggu',
    ];
    public static array $ID_TO_EN = [
        'senin' => 'monday',
        'selasa' => 'tuesday',
        'rabu' => 'wednesday',
        'kamis' => 'thursday',
        'jumat' => 'friday',
        'sabtu' => 'saturday',
        'minggu' => 'sunday',
    ];

    public static function isHentai(string $text): bool
    {
        $low = strtolower($text);
        if ($low === 'hentai') {
            return true;
        }
        $tokens = preg_split('/[\s\-_,.:;!?\/\\\]+/', $low);
        if ($tokens && in_array('hentai', $tokens, true)) {
            return true;
        }
        return false;
    }

    public static function executeQuery(string $query, array $variables = [], int $cacheTtl = 300): ?array
    {
        $cacheKey = 'anilist_' . md5($query . json_encode($variables));
        if ($cacheTtl > 0) {
            $hit = Cache::get($cacheKey, $cacheTtl);
            if ($hit !== null && is_array($hit)) {
                return $hit;
            }
        }

        $res = Http::post(self::API_URL, [
            'query' => $query,
            'variables' => $variables,
        ], [
            'Content-Type: application/json',
            'Accept: application/json',
        ], 12);

        if ($res['status'] !== 200 || empty($res['body'])) {
            return null;
        }

        $json = json_decode($res['body'], true);
        if (!is_array($json) || empty($json['data'])) {
            return null;
        }

        $data = $json['data'];
        if ($cacheTtl > 0) {
            Cache::set($cacheKey, $data, $cacheTtl);
        }

        return $data;
    }

    public static function genres(int $safeMode = 1): array
    {
        $cacheKey = 'anilist_genres_' . $safeMode;
        $cached = Cache::get($cacheKey, 86400);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $query = 'query { GenreCollection }';
        $data = self::executeQuery($query, [], 86400);
        $genres = $data['GenreCollection'] ?? [];
        if (empty($genres)) {
            $genres = [
                "Action", "Adventure", "Comedy", "Drama", "Ecchi", "Fantasy",
                "Horror", "Mahou Shoujo", "Mecha", "Music", "Mystery",
                "Psychological", "Romance", "Sci-Fi", "Slice of Life",
                "Sports", "Supernatural", "Thriller"
            ];
        }

        $filtered = [];
        $vulgar = ['ecchi', 'erotica', 'magical-sex-shift'];
        foreach ($genres as $g) {
            $name = (string)$g;
            $slug = strtolower(str_replace(' ', '-', $name));
            if (self::isHentai($name) || self::isHentai($slug)) {
                continue;
            }
            if ($safeMode === 1 && in_array($slug, $vulgar, true)) {
                continue;
            }
            $filtered[] = [
                'title' => $name,
                'slug' => $slug,
            ];
        }

        Cache::set($cacheKey, $filtered, 86400);
        return $filtered;
    }

    public static function search(string $query, int $page = 1, int $perPage = 24, int $safeMode = 1): array
    {
        $q = trim($query);
        if (empty($q) || self::isHentai($q)) {
            return [
                'results' => [],
                'page' => $page,
                'total_pages' => 1,
                'has_next' => false,
                'cached' => false,
            ];
        }

        $gql = <<<'GQL'
        query ($search: String, $page: Int, $perPage: Int) {
            Page(page: $page, perPage: $perPage) {
                pageInfo {
                    total
                    perPage
                    currentPage
                    lastPage
                    hasNextPage
                }
                media(search: $search, type: ANIME, isAdult: false, sort: SEARCH_MATCH) {
                    id
                    idMal
                    title {
                        romaji
                        english
                        native
                    }
                    format
                    episodes
                    status
                    season
                    seasonYear
                    genres
                    averageScore
                    coverImage {
                        extraLarge
                        large
                        medium
                    }
                    bannerImage
                    description
                }
            }
        }
GQL;

        $cacheKey = "search_{$q}_p{$page}_l{$perPage}_s{$safeMode}";
        $data = self::executeQuery($gql, [
            'search' => $q,
            'page' => $page,
            'perPage' => $perPage,
        ], 300);

        if (!$data || empty($data['Page']['media'])) {
            return [
                'results' => [],
                'page' => $page,
                'total_pages' => 1,
                'has_next' => false,
                'cached' => false,
            ];
        }

        $pageInfo = $data['Page']['pageInfo'] ?? [];
        $items = [];
        $vulgar = ['ecchi', 'erotica', 'hentai'];

        foreach ($data['Page']['media'] as $m) {
            $genres = $m['genres'] ?? [];
            if (self::isHentai(implode(' ', $genres))) {
                continue;
            }
            if ($safeMode === 1) {
                $hasVulgar = false;
                foreach ($genres as $genre) {
                    if (in_array(strtolower($genre), $vulgar, true)) {
                        $hasVulgar = true;
                        break;
                    }
                }
                if ($hasVulgar) {
                    continue;
                }
            }

            $title = $m['title']['english'] ?? $m['title']['romaji'] ?? 'Unknown';
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
            $slug = trim($slug, '-');
            if (empty($slug)) {
                $slug = 'anime-' . $m['id'];
            }

            $items[] = [
                'id' => $slug,
                'anilist_id' => $m['id'],
                'idMal' => $m['idMal'] ?? 0,
                'slug' => $slug,
                'title' => $title,
                'romaji' => $m['title']['romaji'] ?? '',
                'japanese' => $m['title']['native'] ?? '',
                'poster' => $m['coverImage']['large'] ?? $m['coverImage']['medium'] ?? '',
                'banner' => $m['bannerImage'] ?? '',
                'type' => $m['format'] ?? 'TV',
                'episodes' => $m['episodes'] ?? 0,
                'status' => $m['status'] ?? '',
                'score' => isset($m['averageScore']) ? round($m['averageScore'] / 10, 1) : 0.0,
                'genres' => $genres,
                'synopsis' => strip_tags($m['description'] ?? ''),
                'season' => ($m['season'] ?? '') . ' ' . ($m['seasonYear'] ?? ''),
            ];
        }

        return [
            'results' => $items,
            'page' => $page,
            'total_pages' => $pageInfo['lastPage'] ?? 1,
            'has_next' => (bool)($pageInfo['hasNextPage'] ?? false),
            'cached' => false,
        ];
    }

    public static function seasonal(string $which = 'airing', int $page = 1, int $perPage = 25, int $safeMode = 1): array
    {
        $year = (int)date('Y');
        $month = (int)date('n');
        $season = 'WINTER';
        if ($month >= 3 && $month <= 5) $season = 'SPRING';
        elseif ($month >= 6 && $month <= 8) $season = 'SUMMER';
        elseif ($month >= 9 && $month <= 11) $season = 'FALL';

        $statusIn = ['RELEASING'];
        if ($which === 'upcoming') {
            $statusIn = ['NOT_YET_RELEASED'];
        } elseif ($which === 'popular') {
            $statusIn = ['RELEASING', 'FINISHED'];
        }

        $gql = <<<'GQL'
        query ($season: MediaSeason, $year: Int, $page: Int, $perPage: Int, $statusIn: [MediaStatus]) {
            Page(page: $page, perPage: $perPage) {
                pageInfo {
                    total
                    perPage
                    currentPage
                    lastPage
                    hasNextPage
                }
                media(season: $season, seasonYear: $year, status_in: $statusIn, type: ANIME, isAdult: false, sort: POPULARITY_DESC) {
                    id
                    idMal
                    title {
                        romaji
                        english
                    }
                    format
                    episodes
                    status
                    coverImage {
                        large
                        medium
                    }
                    bannerImage
                    averageScore
                    genres
                }
            }
        }
GQL;

        $data = self::executeQuery($gql, [
            'season' => $season,
            'year' => $year,
            'page' => $page,
            'perPage' => $perPage,
            'statusIn' => $statusIn,
        ], 3600);

        $items = [];
        if ($data && !empty($data['Page']['media'])) {
            foreach ($data['Page']['media'] as $m) {
                $title = $m['title']['english'] ?? $m['title']['romaji'] ?? 'Unknown';
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
                $slug = trim($slug, '-');
                $items[] = [
                    'id' => $slug,
                    'anilist_id' => $m['id'],
                    'idMal' => $m['idMal'] ?? 0,
                    'slug' => $slug,
                    'title' => $title,
                    'poster' => $m['coverImage']['large'] ?? $m['coverImage']['medium'] ?? '',
                    'banner' => $m['bannerImage'] ?? '',
                    'type' => $m['format'] ?? 'TV',
                    'episodes' => $m['episodes'] ?? 0,
                    'status' => $m['status'] ?? '',
                    'score' => isset($m['averageScore']) ? round($m['averageScore'] / 10, 1) : 0.0,
                    'genres' => $m['genres'] ?? [],
                ];
            }
        }

        return [
            'season' => $season,
            'year' => $year,
            'items' => $items,
            'page' => $page,
            'has_next' => (bool)($data['Page']['pageInfo']['hasNextPage'] ?? false),
        ];
    }

    public static function detail(string $slugOrId): ?array
    {
        $id = is_numeric($slugOrId) ? (int)$slugOrId : null;
        $search = $id === null ? str_replace('-', ' ', $slugOrId) : null;

        $gql = $id !== null ? <<<'GQL'
        query ($id: Int) {
            Media(id: $id, type: ANIME, isAdult: false) {
                id
                idMal
                title { romaji english native }
                format
                episodes
                duration
                status
                season
                seasonYear
                startDate { year month day }
                genres
                averageScore
                coverImage { extraLarge large medium }
                bannerImage
                description
            }
        }
GQL
        : <<<'GQL'
        query ($search: String) {
            Media(search: $search, type: ANIME, isAdult: false) {
                id
                idMal
                title { romaji english native }
                format
                episodes
                duration
                status
                season
                seasonYear
                startDate { year month day }
                genres
                averageScore
                coverImage { extraLarge large medium }
                bannerImage
                description
            }
        }
GQL;

        $vars = $id !== null ? ['id' => $id] : ['search' => $search];
        $data = self::executeQuery($gql, $vars, 86400);

        if (!$data || empty($data['Media'])) {
            return null;
        }

        $m = $data['Media'];
        $title = $m['title']['english'] ?? $m['title']['romaji'] ?? 'Unknown';
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        $slug = trim($slug, '-');

        return [
            'id' => $slug,
            'anilist_id' => $m['id'],
            'idMal' => $m['idMal'] ?? 0,
            'slug' => $slug,
            'title' => $title,
            'romaji' => $m['title']['romaji'] ?? '',
            'japanese' => $m['title']['native'] ?? '',
            'poster' => $m['coverImage']['extraLarge'] ?? $m['coverImage']['large'] ?? '',
            'banner' => $m['bannerImage'] ?? '',
            'type' => $m['format'] ?? 'TV',
            'episodes' => $m['episodes'] ?? 0,
            'duration' => $m['duration'] ?? 24,
            'status' => $m['status'] ?? '',
            'season' => ($m['season'] ?? '') . ' ' . ($m['seasonYear'] ?? ''),
            'score' => isset($m['averageScore']) ? round($m['averageScore'] / 10, 1) : 0.0,
            'genres' => $m['genres'] ?? [],
            'synopsis' => strip_tags($m['description'] ?? ''),
        ];
    }

    public static function schedule(string $day = 'today', int $safeMode = 1): array
    {
        $tzOffset = 7 * 3600; // WIB (UTC+7)
        $nowUtc = time();
        $nowWib = $nowUtc + $tzOffset;
        $curWeekday = (int)gmdate('N', $nowWib) - 1; // 0=Monday, ..., 6=Sunday
        $todayKey = self::$DAYS_KEYS[$curWeekday] ?? 'monday';
        $yesterdayKey = self::$DAYS_KEYS[($curWeekday + 6) % 7];
        $tomorrowKey = self::$DAYS_KEYS[($curWeekday + 1) % 7];

        $todayMidWib = intdiv($nowWib, 86400) * 86400;
        $monMidWib = $todayMidWib - ($curWeekday * 86400);
        $monMidUtc = $monMidWib - $tzOffset;
        $sunEndUtc = $monMidUtc + (7 * 86400) - 1;

        $cacheKey = "schedule_7days_v1_{$monMidWib}_sm{$safeMode}";
        $cachedData = Cache::get($cacheKey, 7200);

        if ($cachedData === null || !is_array($cachedData)) {
            $gql = <<<'GQL'
            query GetSchedule($page: Int, $now: Int, $end: Int, $perPage: Int) {
              Page(page: $page, perPage: $perPage) {
                pageInfo { hasNextPage currentPage }
                airingSchedules(airingAt_greater: $now, airingAt_lesser: $end, sort: TIME) {
                  id airingAt episode
                  media {
                    id idMal title { romaji english }
                    season seasonYear format
                    genres averageScore isAdult
                    coverImage { extraLarge large medium }
                    bannerImage
                  }
                }
              }
            }
GQL;

            $buckets = array_fill_keys(self::$DAYS_KEYS, []);
            $seen = [];

            for ($p = 1; $p <= 6; $p++) {
                $res = self::executeQuery($gql, [
                    'page' => $p,
                    'now' => $monMidUtc,
                    'end' => $sunEndUtc,
                    'perPage' => 50,
                ], 3600);

                $pageData = $res['Page'] ?? [];
                $schedules = $pageData['airingSchedules'] ?? [];
                if (empty($schedules)) {
                    break;
                }

                foreach ($schedules as $s) {
                    $m = $s['media'] ?? [];
                    $mid = $m['id'] ?? null;
                    if (!$mid || isset($seen[$mid])) {
                        continue;
                    }
                    $seen[$mid] = true;
                    if (!empty($m['isAdult'])) {
                        continue;
                    }

                    $genres = $m['genres'] ?? [];
                    if (in_array('Hentai', $genres, true) || self::isHentai(implode(' ', $genres))) {
                        continue;
                    }

                    $primaryTitle = $m['title']['english'] ?? $m['title']['romaji'] ?? '';
                    if (empty($primaryTitle) || self::isHentai($primaryTitle)) {
                        continue;
                    }

                    $airingAt = (int)($s['airingAt'] ?? 0);
                    $dtWib = $airingAt + $tzOffset;
                    $itemWeekday = (int)gmdate('N', $dtWib) - 1;
                    $dayCat = self::$DAYS_KEYS[$itemWeekday] ?? 'monday';
                    $dayName = self::$EN_TO_ID[$dayCat] ?? 'Senin';
                    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $primaryTitle));
                    $slug = trim($slug, '-');
                    $score = isset($m['averageScore']) ? round($m['averageScore'] / 10, 1) : 0.0;
                    $poster = $m['coverImage']['large'] ?? $m['coverImage']['medium'] ?? '';

                    $buckets[$dayCat][] = [
                        'id' => $slug,
                        'anilist_id' => $mid,
                        'idMal' => $m['idMal'] ?? 0,
                        'slug' => $slug,
                        'title' => $primaryTitle,
                        'episode' => (int)($s['episode'] ?? 0),
                        'airing_at' => $airingAt,
                        'time' => gmdate('H:i', $dtWib) . ' WIB',
                        'date' => gmdate('d M', $dtWib),
                        'day' => $dayCat,
                        'day_name' => $dayName,
                        'poster' => $poster,
                        'banner' => $m['bannerImage'] ?? '',
                        'type' => $m['format'] ?? 'TV',
                        'score' => $score,
                        'mal_id' => (string)($m['idMal'] ?? ''),
                        'matched' => true,
                    ];
                }

                if (empty($pageData['pageInfo']['hasNextPage'])) {
                    break;
                }
            }

            foreach ($buckets as $k => &$arr) {
                usort($arr, function($a, $b) {
                    return $a['airing_at'] <=> $b['airing_at'];
                });
            }
            unset($arr);

            $counts = [];
            foreach (self::$DAYS_KEYS as $k) {
                $counts[$k] = count($buckets[$k]);
            }
            $counts['today'] = count($buckets[$todayKey]);
            $counts['yesterday'] = count($buckets[$yesterdayKey]);
            $counts['tomorrow'] = count($buckets[$tomorrowKey]);

            $totalItems = array_sum(array_map('count', $buckets));

            // Fallback if network was blocked/empty
            if ($totalItems === 0) {
                $seasonal = self::seasonal('airing', 1, 30, $safeMode);
                $fallbackItems = $seasonal['items'] ?? [];
                $buckets[$todayKey] = $fallbackItems;
                $counts[$todayKey] = count($fallbackItems);
                $totalItems = count($fallbackItems);
            }

            $cachedData = [
                'week_start' => $monMidUtc,
                'week_end' => $sunEndUtc,
                'current_day' => $todayKey,
                'current_day_name' => self::$EN_TO_ID[$todayKey] ?? 'Senin',
                'days' => $buckets,
                'monday' => $buckets['monday'],
                'tuesday' => $buckets['tuesday'],
                'wednesday' => $buckets['wednesday'],
                'thursday' => $buckets['thursday'],
                'friday' => $buckets['friday'],
                'saturday' => $buckets['saturday'],
                'sunday' => $buckets['sunday'],
                'today' => $buckets[$todayKey],
                'yesterday' => $buckets[$yesterdayKey],
                'tomorrow' => $buckets[$tomorrowKey],
                'counts' => $counts,
                'total' => $totalItems,
                'cached' => false,
            ];

            if ($totalItems > 0) {
                Cache::set($cacheKey, $cachedData, 7200);
            }
        }

        $reqDay = strtolower(trim($day));
        if ($reqDay === 'all') {
            return $cachedData;
        }

        if ($reqDay === 'today' || $reqDay === '') {
            $targetDay = $todayKey;
        } elseif ($reqDay === 'yesterday') {
            $targetDay = $yesterdayKey;
        } elseif ($reqDay === 'tomorrow') {
            $targetDay = $tomorrowKey;
        } elseif (isset(self::$ID_TO_EN[$reqDay])) {
            $targetDay = self::$ID_TO_EN[$reqDay];
        } elseif (in_array($reqDay, self::$DAYS_KEYS, true)) {
            $targetDay = $reqDay;
        } else {
            $targetDay = $todayKey;
        }

        $respDay = in_array($reqDay, ['today', 'yesterday', 'tomorrow'], true) ? $reqDay : $targetDay;
        $targetItems = $cachedData['days'][$targetDay] ?? ($cachedData[$targetDay] ?? []);

        return [
            'items' => $targetItems,
            'day' => $respDay,
            'weekday' => $targetDay,
            'day_name' => self::$EN_TO_ID[$targetDay] ?? ucfirst($targetDay),
            'current_day' => $todayKey,
            'is_today' => ($targetDay === $todayKey),
            'total' => count($targetItems),
            'counts' => $cachedData['counts'] ?? [],
            'cached' => !empty($cachedData['cached']),
        ];
    }

    public static function browse(array $params = [], int $page = 1, int $perPage = 24, int $safeMode = 1): array
    {
        $genre = trim((string)($params['genre'] ?? ''));
        if (!empty($genre)) {
            if (self::isHentai($genre) || stripos($genre, 'hentai') !== false) {
                return ['items' => [], 'page' => $page, 'total_pages' => 1, 'has_next' => false, 'params' => $params];
            }
            if ($safeMode === 1 && in_array(strtolower($genre), ['ecchi', 'erotica', 'magical-sex-shift'], true)) {
                return ['items' => [], 'page' => $page, 'total_pages' => 1, 'has_next' => false, 'params' => $params];
            }
        }

        $sortParam = strtolower(trim((string)($params['sort'] ?? 'popularity')));
        $sortMap = [
            'popularity' => ['POPULARITY_DESC'],
            'score' => ['SCORE_DESC'],
            'latest' => ['START_DATE_DESC'],
            'trending' => ['TRENDING_DESC'],
            'az' => ['TITLE_ROMAJI'],
        ];
        $sortGql = $sortMap[$sortParam] ?? ['POPULARITY_DESC'];

        $formatParam = strtoupper(trim((string)($params['type'] ?? $params['format'] ?? '')));
        $allowedFormats = ['TV', 'TV_SHORT', 'MOVIE', 'SPECIAL', 'OVA', 'ONA', 'MUSIC'];
        $formatGql = in_array($formatParam, $allowedFormats, true) ? $formatParam : null;

        $statusParam = strtoupper(trim((string)($params['status'] ?? '')));
        $statusMap = [
            'RELEASING' => 'RELEASING',
            'AIRING' => 'RELEASING',
            'CURRENTLY_AIRING' => 'RELEASING',
            'FINISHED' => 'FINISHED',
            'NOT_YET_RELEASED' => 'NOT_YET_RELEASED',
            'NOT_YET_AIRED' => 'NOT_YET_RELEASED',
            'CANCELLED' => 'CANCELLED',
        ];
        $statusGql = $statusMap[$statusParam] ?? null;

        $seasonParam = strtoupper(trim((string)($params['season'] ?? '')));
        $allowedSeasons = ['WINTER', 'SPRING', 'SUMMER', 'FALL'];
        $seasonGql = in_array($seasonParam, $allowedSeasons, true) ? $seasonParam : null;

        $yearParam = trim((string)($params['year'] ?? $params['sy'] ?? ''));
        $yearGql = (is_numeric($yearParam) && (int)$yearParam >= 1960 && (int)$yearParam <= 2050) ? (int)$yearParam : null;

        $genreGql = null;
        if (!empty($genre)) {
            $genres = self::genres(0);
            foreach ($genres as $g) {
                if (strcasecmp($g['slug'], $genre) === 0 || strcasecmp($g['title'], $genre) === 0) {
                    $genreGql = $g['title'];
                    break;
                }
            }
            if (!$genreGql) {
                $genreGql = ucwords(str_replace('-', ' ', $genre));
            }
        }

        $cacheKey = 'browse_' . md5(json_encode([
            'sort' => $sortGql,
            'format' => $formatGql,
            'status' => $statusGql,
            'season' => $seasonGql,
            'year' => $yearGql,
            'genre' => $genreGql,
            'page' => $page,
            'perPage' => $perPage,
            'safeMode' => $safeMode,
        ]));

        $cached = Cache::get($cacheKey, 3600);
        if ($cached !== null && is_array($cached)) {
            $cached['cached'] = true;
            return $cached;
        }

        $gql = <<<'GQL'
        query ($page: Int, $perPage: Int, $sort: [MediaSort], $season: MediaSeason, $seasonYear: Int, $format: MediaFormat, $status: MediaStatus, $genre: String) {
          Page(page: $page, perPage: $perPage) {
            pageInfo {
              total
              perPage
              currentPage
              lastPage
              hasNextPage
            }
            media(type: ANIME, isAdult: false, sort: $sort, season: $season, seasonYear: $seasonYear, format: $format, status: $status, genre: $genre) {
              id
              idMal
              title {
                romaji
                english
              }
              format
              episodes
              status
              season
              seasonYear
              genres
              averageScore
              coverImage {
                large
                medium
              }
              bannerImage
            }
          }
        }
GQL;

        $vars = [
            'page' => $page,
            'perPage' => $perPage,
            'sort' => $sortGql,
        ];
        if ($seasonGql) $vars['season'] = $seasonGql;
        if ($yearGql) $vars['seasonYear'] = $yearGql;
        if ($formatGql) $vars['format'] = $formatGql;
        if ($statusGql) $vars['status'] = $statusGql;
        if ($genreGql) $vars['genre'] = $genreGql;

        $res = self::executeQuery($gql, $vars, 3600);
        $pageData = $res['Page'] ?? [];
        $mediaList = $pageData['media'] ?? [];

        $items = [];
        $vulgar = ['ecchi', 'erotica', 'magical-sex-shift'];
        foreach ($mediaList as $m) {
            $genres = $m['genres'] ?? [];
            if (in_array('Hentai', $genres, true) || self::isHentai(implode(' ', $genres))) {
                continue;
            }
            if ($safeMode === 1 && array_intersect(array_map('strtolower', $genres), $vulgar)) {
                continue;
            }
            $title = $m['title']['english'] ?? $m['title']['romaji'] ?? 'Unknown';
            if (empty($title) || self::isHentai($title)) {
                continue;
            }

            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
            $slug = trim($slug, '-');
            $items[] = [
                'id' => $slug,
                'anilist_id' => $m['id'],
                'idMal' => $m['idMal'] ?? 0,
                'slug' => $slug,
                'title' => $title,
                'poster' => $m['coverImage']['large'] ?? $m['coverImage']['medium'] ?? '',
                'banner' => $m['bannerImage'] ?? '',
                'type' => $m['format'] ?? 'TV',
                'episodes' => $m['episodes'] ?? 0,
                'status' => $m['status'] ?? '',
                'score' => isset($m['averageScore']) ? round($m['averageScore'] / 10, 1) : 0.0,
                'genres' => $m['genres'] ?? [],
            ];
        }

        $result = [
            'items' => $items,
            'page' => $page,
            'total_pages' => (int)($pageData['pageInfo']['lastPage'] ?? 1),
            'has_next' => (bool)($pageData['pageInfo']['hasNextPage'] ?? false),
            'params' => $params,
            'cached' => false,
        ];

        Cache::set($cacheKey, $result, 3600);
        return $result;
    }
}
