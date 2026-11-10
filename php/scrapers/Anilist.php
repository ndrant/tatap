<?php
declare(strict_types=1);

namespace Tatap\Scrapers;

use Tatap\Core\Http;
use Tatap\Core\Cache;

class Anilist
{
    public const API_URL = 'https://graphql.anilist.co';

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
                'id' => $m['id'],
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
                    'id' => $m['id'],
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
            'id' => $m['id'],
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
}
