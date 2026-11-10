<?php
declare(strict_types=1);

namespace Tatap\Scrapers;

use Tatap\Core\Http;
use Tatap\Core\Cache;

class Hianime
{
    public const BASE_URL = 'https://hianime.at';
    public const MEGA_KEY = "i?LMTAx0Q6,:}50U\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";
    public const MEGA_IV = "W0;27ToaUpl_P%'c";

    public static function b64UrlDecode(string $s): string
    {
        $s = str_replace(['-', '_'], ['+', '/'], $s);
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode($s);
    }

    public static function decryptMegaCloud(string $enc): string
    {
        $raw = self::b64UrlDecode(trim($enc));
        if (empty($raw)) {
            return '';
        }

        $pt = openssl_decrypt(
            $raw,
            'aes-256-cbc',
            self::MEGA_KEY,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            self::MEGA_IV
        );

        if ($pt === false || $pt === '') {
            return '';
        }

        // Strip PKCS7 padding
        $pad = ord(substr($pt, -1));
        if ($pad >= 1 && $pad <= 16 && substr($pt, -$pad) === str_repeat(chr($pad), $pad)) {
            $pt = substr($pt, 0, -$pad);
        }

        return trim($pt);
    }

    public static function parseEpisodesHtml(string $html): array
    {
        $eps = [];
        if (preg_match_all('/data-number="([0-9]+)"[^>]*?data-id="([0-9]+)"/', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $eps[] = [
                    'ep' => (int)$m[1],
                    'ep_id' => $m[2],
                ];
            }
        }
        return $eps;
    }

    public static function parseServersHtml(string $html, string $mode = 'sub'): array
    {
        $items = explode('server-item', str_replace('\\"', '"', $html));
        $servers = [];

        foreach ($items as $it) {
            if (strpos($it, 'data-type="' . $mode . '"') === false) {
                continue;
            }

            if (!preg_match('/data-hash="([^"]*)"/', $it, $mh)) {
                continue;
            }

            preg_match('/data-server-name="([^"]*)"/', $it, $mn);
            $name = $mn[1] ?? 'unknown';

            $embed = @base64_decode($mh[1]);
            if (!$embed || strpos($embed, 'http') !== 0) {
                continue;
            }

            $servers[] = [
                'name' => $name,
                'embed' => $embed,
            ];
        }

        usort($servers, function($a, $b) {
            $scoreA = (strpos($a['embed'], 'megaplay') !== false) ? 0 : 9;
            $scoreB = (strpos($b['embed'], 'megaplay') !== false) ? 0 : 9;
            return $scoreA <=> $scoreB;
        });

        return $servers;
    }

    public static function search(string $query): array
    {
        $clean = trim($query);
        if (empty($clean) || Anilist::isHentai($clean)) {
            return [];
        }
        $cacheKey = 'hianime_search_' . md5($clean);
        $cached = Cache::get($cacheKey, 86400);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $url = self::BASE_URL . '/search?keyword=' . urlencode($clean);
        $res = Http::get($url, [], 10, self::BASE_URL . '/');
        if ($res['status'] !== 200 || empty($res['body'])) {
            return [];
        }

        $results = [];
        if (preg_match_all('/<h3 class="film-name">\s*<a\s+href="[^"]*\/([^\/"?]+)"\s+title="([^"]*)"/is', $res['body'], $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $s = $m[1];
                $t = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!Anilist::isHentai($s) && !Anilist::isHentai($t)) {
                    $results[] = [
                        'id' => $s,
                        'title' => $t,
                    ];
                }
            }
        }
        if (!empty($results)) {
            Cache::set($cacheKey, $results, 86400);
        }
        return $results;
    }

    public static function resolveSlug(string $slug, string $title = ''): ?string
    {
        $slug = trim($slug);
        $parts = explode('-', $slug);
        $last = end($parts);
        if (count($parts) > 1 && is_numeric($last) && (int)$last > 99) {
            return $slug;
        }

        $cacheKey = 'hianime_slug_res_' . md5($slug . '|' . $title);
        $cached = Cache::get($cacheKey, 86400 * 7);
        if ($cached !== null) {
            return $cached ?: null;
        }

        $query = !empty($title) ? $title : (is_numeric($slug) ? '' : str_replace('-', ' ', $slug));
        if (empty($query) && is_numeric($slug)) {
            $detail = Anilist::detail($slug);
            if ($detail && !empty($detail['title'])) {
                $query = $detail['title'];
            }
        }
        if (empty($query)) {
            return null;
        }

        $res = self::search($query);
        if (empty($res) && !empty($title) && !is_numeric($slug)) {
            $res = self::search(str_replace('-', ' ', $slug));
        }

        $picked = null;
        if (!empty($res)) {
            foreach ($res as $item) {
                if (Otakudesu::isTitleMatch($query, $item['title'])) {
                    $picked = $item['id'];
                    break;
                }
            }
        }

        Cache::set($cacheKey, $picked ?? '', 86400 * 7);
        return $picked;
    }

    public static function episodes(string $slug, string $title = ''): array
    {
        $slug = trim($slug);
        if (empty($slug) || Anilist::isHentai($slug)) {
            return [];
        }

        $cacheKey = 'hianime_eps_' . $slug;
        $cached = Cache::get($cacheKey, 3600);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        // Numeric id is usually the last part of slug, e.g. "frieren-beyond-journeys-end-481"
        $parts = explode('-', $slug);
        $numeric = end($parts);
        $effSlug = $slug;

        if (count($parts) <= 1 || !is_numeric($numeric) || (int)$numeric <= 99) {
            $resolved = self::resolveSlug($slug, $title);
            if ($resolved) {
                $effSlug = $resolved;
                $p2 = explode('-', $resolved);
                $numeric = end($p2);
            }
        }

        if (!is_numeric($numeric)) {
            return [];
        }

        $url = self::BASE_URL . '/api/theme/episode/list/' . $numeric;
        $res = Http::get($url, [], 10, self::BASE_URL . '/' . $effSlug);

        $html = '';
        if ($res['status'] === 200 && !empty($res['body'])) {
            $json = json_decode($res['body'], true);
            $html = $json['html'] ?? $res['body'];
        }

        $eps = self::parseEpisodesHtml($html);
        if (empty($eps) && $effSlug === $slug && (!empty($title) || !empty($slug))) {
            $resolved = self::resolveSlug($slug, $title);
            if ($resolved && $resolved !== $slug) {
                $p2 = explode('-', $resolved);
                $num2 = end($p2);
                if (is_numeric($num2)) {
                    $url2 = self::BASE_URL . '/api/theme/episode/list/' . $num2;
                    $res2 = Http::get($url2, [], 10, self::BASE_URL . '/' . $resolved);
                    if ($res2['status'] === 200 && !empty($res2['body'])) {
                        $json2 = json_decode($res2['body'], true);
                        $eps = self::parseEpisodesHtml($json2['html'] ?? $res2['body']);
                    }
                }
            }
        }

        if (!empty($eps)) {
            Cache::set($cacheKey, $eps, 3600);
        }

        return $eps;
    }

    public static function servers(string $epId, string $mode = 'sub'): array
    {
        $cacheKey = 'hianime_servers_' . $epId . '_' . $mode;
        $cached = Cache::get($cacheKey, 600);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $url = self::BASE_URL . '/api/theme/episode/servers?episodeId=' . urlencode($epId);
        $res = Http::get($url, [], 10);

        $html = '';
        if ($res['status'] === 200 && !empty($res['body'])) {
            $json = json_decode($res['body'], true);
            $html = $json['html'] ?? $res['body'];
        }

        $servers = self::parseServersHtml($html, $mode);
        if (!empty($servers)) {
            Cache::set($cacheKey, $servers, 600);
        }

        return $servers;
    }

    public static function extractSourcesFromEmbed(string $embedUrl): ?array
    {
        $embedRes = Http::get($embedUrl, [], 10, self::BASE_URL . '/');
        if ($embedRes['status'] !== 200 || empty($embedRes['body'])) {
            return null;
        }

        $parsed = parse_url($embedUrl);
        $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        // 1. Check window.__P pattern (ZokoAnime, VidPlay, MegaCloud legacy)
        if (preg_match('/window\.__P\s*=\s*"([^"]*)"/', $embedRes['body'], $pm)) {
            $key = 'otaku-embed-v1';
            $kLen = strlen($key);
            $raw = base64_decode($pm[1]);
            if ($raw !== false && $raw !== '') {
                $out = '';
                for ($i = 0; $i < strlen($raw); $i++) {
                    $out .= chr(ord($raw[$i]) ^ ord($key[$i % $kLen]));
                }
                $cfg = json_decode($out, true);
                if (is_array($cfg)) {
                    $master = $cfg['src'] ?? '';
                    if (empty($master) && !empty($cfg['sources']) && is_array($cfg['sources'])) {
                        $master = $cfg['sources'][0]['src'] ?? $cfg['sources'][0]['file'] ?? '';
                    }
                    if (!empty($master)) {
                        $subtitles = [];
                        foreach ($cfg['subtitles'] ?? [] as $t) {
                            $subtitles[] = [
                                'url' => $t['src'] ?? $t['file'] ?? '',
                                'label' => $t['label'] ?? $t['lang'] ?? 'English',
                                'default' => (bool)($t['default'] ?? false),
                            ];
                        }
                        return [
                            'master' => $master,
                            'referer' => $origin . '/',
                            'subtitles' => $subtitles,
                        ];
                    }
                }
            }
        }

        if (!preg_match('/data-id="(\d+)"/', $embedRes['body'], $m)) {
            return null;
        }

        $dataId = $m[1];

        $apiUrl = $origin . '/stream/getSourcesNew?id=' . $dataId;
        $res = Http::get($apiUrl, [], 10, $embedUrl);
        if ($res['status'] !== 200 || empty($res['body'])) {
            $apiUrl = $origin . '/stream/getSources?id=' . $dataId;
            $res = Http::get($apiUrl, [], 10, $embedUrl);
        }

        if ($res['status'] !== 200 || empty($res['body'])) {
            return null;
        }

        $cfg = json_decode($res['body'], true);
        if (!is_array($cfg)) {
            return null;
        }

        $master = '';
        if (!empty($cfg['sources']) && is_array($cfg['sources'])) {
            $master = $cfg['sources'][0]['file'] ?? $cfg['sources'][0]['src'] ?? '';
        } elseif (!empty($cfg['sources']) && is_string($cfg['sources'])) {
            $master = $cfg['sources'];
        }

        if (empty($master) && !empty($cfg['enc'])) {
            $dec = json_decode(self::decryptMegaCloud($cfg['enc']), true);
            if (is_array($dec)) {
                $master = $dec['file'] ?? ($dec['sources'][0]['file'] ?? '');
            }
        }

        if (empty($master)) {
            return null;
        }

        if (strpos($master, '/') === 0) {
            $master = $origin . $master;
        }

        // Subtitles normalization
        $tracks = $cfg['tracks'] ?? [];
        $subtitles = [];
        foreach ($tracks as $t) {
            if (($t['kind'] ?? '') === 'captions' || ($t['kind'] ?? '') === 'subtitles' || isset($t['file'])) {
                $subtitles[] = [
                    'url' => $t['file'] ?? '',
                    'label' => $t['label'] ?? 'English',
                    'default' => (bool)($t['default'] ?? false),
                ];
            }
        }

        return [
            'master' => $master,
            'referer' => $origin . '/',
            'subtitles' => $subtitles,
        ];
    }

    public static function resolveStream(string $slug, int $ep, string $mode = 'sub', string $server = '', string $title = ''): ?array
    {
        $eps = self::episodes($slug, $title);
        if (empty($eps)) {
            return null;
        }

        $targetEp = null;
        foreach ($eps as $e) {
            if ($e['ep'] === $ep) {
                $targetEp = $e;
                break;
            }
        }

        if (!$targetEp) {
            $targetEp = $eps[0] ?? null;
        }

        if (!$targetEp || empty($targetEp['ep_id'])) {
            return null;
        }

        $servers = self::servers($targetEp['ep_id'], $mode);
        if (empty($servers)) {
            return null;
        }

        $chosenServer = $servers[0];
        if (!empty($server)) {
            foreach ($servers as $s) {
                if (stripos($s['name'], $server) !== false) {
                    $chosenServer = $s;
                    break;
                }
            }
        }

        $sources = self::extractSourcesFromEmbed($chosenServer['embed']);
        if (!$sources) {
            // Try next server
            foreach ($servers as $s) {
                if ($s['embed'] !== $chosenServer['embed']) {
                    $sources = self::extractSourcesFromEmbed($s['embed']);
                    if ($sources) {
                        $chosenServer = $s;
                        break;
                    }
                }
            }
        }

        if (!$sources) {
            return null;
        }

        return [
            'slug' => $slug,
            'ep' => $ep,
            'mode' => $mode,
            'server' => $chosenServer['name'],
            'master' => $sources['master'],
            'variants' => [
                ['quality' => 'auto', 'q' => 'auto', 'url' => $sources['master']],
            ],
            'picked' => ['quality' => 'auto', 'q' => 'auto', 'url' => $sources['master']],
            'referer' => $sources['referer'],
            'subtitles' => $sources['subtitles'],
            'intro' => ['start' => 0, 'end' => 0],
            'outro' => ['start' => 0, 'end' => 0],
            'source' => 'hianime',
        ];
    }
}
