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
        if ($pad >= 1 && $pad <= 16) {
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

    public static function episodes(string $slug): array
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

        // Numeric id is usually the last part of slug, e.g. "frieren-beyond-journeys-end-18413"
        $parts = explode('-', $slug);
        $numeric = end($parts);

        $url = self::BASE_URL . '/api/theme/episode/list/' . $numeric;
        $res = Http::get($url, [], 10, self::BASE_URL . '/' . $slug);

        $html = '';
        if ($res['status'] === 200 && !empty($res['body'])) {
            $json = json_decode($res['body'], true);
            $html = $json['html'] ?? $res['body'];
        }

        $eps = self::parseEpisodesHtml($html);
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

        if (!preg_match('/data-id="(\d+)"/', $embedRes['body'], $m)) {
            return null;
        }

        $dataId = $m[1];
        $parsed = parse_url($embedUrl);
        $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

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

    public static function resolveStream(string $slug, int $ep, string $mode = 'sub', string $server = ''): ?array
    {
        $eps = self::episodes($slug);
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
            'referer' => $sources['referer'],
            'subtitles' => $sources['subtitles'],
        ];
    }
}
