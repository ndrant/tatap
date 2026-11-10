<?php
declare(strict_types=1);

namespace Tatap\Scrapers;

use Tatap\Core\Http;
use Tatap\Core\Cache;

class Otakudesu
{
    public const BASE_URL = 'https://otakudesu.cloud';

    public static function isTitleMatch(string $a, string $b): bool
    {
        $clean = function(string $s): array {
            $s = strtolower($s);
            $s = preg_replace('/\b(sub|indo|subtitle|indonesia|batch|lengkap|season|\d+)\b/', '', $s);
            $s = preg_replace('/[^\w\s]/', ' ', $s);
            $tokens = preg_split('/\s+/', trim($s));
            return array_filter($tokens ?: [], function($t) {
                return strlen($t) > 1;
            });
        };

        $tA = array_values(array_unique($clean($a)));
        $tB = array_values(array_unique($clean($b)));

        if (empty($tA) || empty($tB)) {
            return false;
        }

        $common = array_intersect($tA, $tB);
        $overlap = count($common) / max(count($tA), count($tB));
        return $overlap >= 0.50;
    }

    public static function parseSearchHtml(string $html): array
    {
        $results = [];
        // Pattern: <a class="ot-k" href="([^"]+)">.*?<img[^>]*src="([^"]+)".*?<h2 class="ot-k-j">([^<]+)</h2>
        if (preg_match_all('/<a class="ot-k"\s+href="([^"]+)">.*?<img[^>]*src="([^"]+)".*?<h2 class="ot-k-j">([^<]+)<\/h2>/s', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $href = $m[1];
                $poster = $m[2];
                $title = html_entity_decode(trim($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $slug = '';
                if (preg_match('/\/anime\/([^\/]+)\/?/', $href, $sm)) {
                    $slug = $sm[1];
                } else {
                    $slug = basename(rtrim($href, '/'));
                }
                $results[] = [
                    'title' => $title,
                    'url' => $href,
                    'slug' => $slug,
                    'id' => $slug,
                    'poster' => $poster,
                ];
            }
        }
        return $results;
    }

    public static function parseEpisodesHtml(string $html): array
    {
        $out = [];
        $seen = [];
        if (preg_match_all('/<a class="ot-ep"\s+href="([^"]+)">\s*<span>([^<]+)<\/span>/', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $href = $m[1];
                if (isset($seen[$href])) {
                    continue;
                }
                $seen[$href] = true;
                $title = trim($m[2]);
                $epNo = count($out) + 1;
                if (preg_match('/(?:episode|eps|ep)\s*#?\s*(\d+)/i', $title, $em)) {
                    $epNo = (int)$em[1];
                }
                $out[] = [
                    'ep' => $epNo,
                    'title' => $title,
                    'url' => $href,
                ];
            }
        }
        usort($out, function($a, $b) {
            return $a['ep'] <=> $b['ep'];
        });
        return $out;
    }

    public static function search(string $query, int $limit = 10): array
    {
        $clean = trim(preg_replace('/[^\w\s-]/', '', $query));
        if (empty($clean) || Anilist::isHentai($clean)) {
            return [];
        }

        $cacheKey = 'otaku_search_' . md5($clean);
        $cached = Cache::get($cacheKey, 3600);
        if ($cached !== null && is_array($cached)) {
            return array_slice($cached, 0, $limit);
        }

        $url = self::BASE_URL . '/?s=' . urlencode($clean) . '&post_type=anime';
        $res = Http::get($url, [], 10, self::BASE_URL . '/');
        if ($res['status'] !== 200 || empty($res['body'])) {
            return [];
        }

        $results = self::parseSearchHtml($res['body']);
        if (!empty($results)) {
            Cache::set($cacheKey, $results, 3600);
        }

        return array_slice($results, 0, $limit);
    }

    public static function episodes(string $slugOrUrl): array
    {
        $url = strpos($slugOrUrl, 'http') === 0 ? $slugOrUrl : self::BASE_URL . '/anime/' . trim($slugOrUrl, '/') . '/';
        $cacheKey = 'otaku_eps_' . md5($url);
        $cached = Cache::get($cacheKey, 1800);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $res = Http::get($url, [], 10, self::BASE_URL . '/');
        if ($res['status'] !== 200 || empty($res['body'])) {
            return [];
        }

        $eps = self::parseEpisodesHtml($res['body']);
        if (!empty($eps)) {
            Cache::set($cacheKey, $eps, 1800);
        }

        return $eps;
    }

    public static function decryptPutarinConfig(string $embedUrl): ?array
    {
        $res = Http::get($embedUrl, [], 10, self::BASE_URL . '/');
        if ($res['status'] !== 200 || empty($res['body'])) {
            return null;
        }

        if (!preg_match('/window\.__PX\s*=\s*({[^<]+});/', $res['body'], $pm)) {
            return null;
        }

        $px = json_decode($pm[1], true);
        if (!is_array($px) || empty($px['n']) || empty($px['d'])) {
            return null;
        }

        $nonce = $px['n'];
        $cipherB64 = $px['d'];

        $parsed = parse_url($embedUrl);
        $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'putarin.xyz');

        $keyUrl = $origin . '/api/pk?n=' . urlencode($nonce);
        $keyRes = Http::get($keyUrl, [], 10, $embedUrl);
        $keyHex = trim($keyRes['body'] ?? '');
        if (strlen($keyHex) < 64) {
            return null;
        }

        $raw = base64_decode($cipherB64);
        if (strlen($raw) < 28) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $ctWithTag = substr($raw, 12);
        $tag = substr($ctWithTag, -16);
        $ct = substr($ctWithTag, 0, -16);

        $dec = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            hex2bin($keyHex),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (!$dec) {
            return null;
        }

        $cfg = json_decode($dec, true);
        if (is_array($cfg)) {
            $cfg['_origin'] = $origin;
            return $cfg;
        }

        return null;
    }

    public static function resolveStream(string $titleOrSlug, int $ep = 1): ?array
    {
        if (Anilist::isHentai($titleOrSlug)) {
            return null;
        }

        $cleanTitle = trim(str_replace('-', ' ', preg_replace('/-\d+$/', '', $titleOrSlug)));
        $searchRes = self::search($cleanTitle);
        if (empty($searchRes)) {
            return null;
        }

        $matched = null;
        foreach ($searchRes as $item) {
            if (self::isTitleMatch($cleanTitle, $item['title'])) {
                $matched = $item;
                break;
            }
        }

        if (!$matched) {
            $matched = $searchRes[0] ?? null;
        }

        if (!$matched) {
            return null;
        }

        $episodes = self::episodes($matched['url']);
        if (empty($episodes)) {
            return null;
        }

        $targetEp = null;
        foreach ($episodes as $e) {
            if ($e['ep'] === $ep) {
                $targetEp = $e;
                break;
            }
        }

        if (!$targetEp) {
            $idx = $ep - 1;
            if ($idx >= 0 && $idx < count($episodes)) {
                $targetEp = $episodes[$idx];
            }
        }

        if (!$targetEp) {
            return null;
        }

        $epPageRes = Http::get($targetEp['url'], [], 10, self::BASE_URL . '/');
        if ($epPageRes['status'] !== 200 || empty($epPageRes['body'])) {
            return null;
        }

        $embedUrl = '';
        if (preg_match('/<iframe[^>]*src="([^"]*putarin\.[a-z]+\/e\/[^"]+)"/', $epPageRes['body'], $im)) {
            $embedUrl = $im[1];
        } elseif (preg_match('/<div class="ot-player"[^>]*>.*?<iframe[^>]*src="([^"]+)"/s', $epPageRes['body'], $im)) {
            $embedUrl = $im[1];
        }

        if (empty($embedUrl)) {
            return null;
        }

        if (strpos($embedUrl, '//') === 0) {
            $embedUrl = 'https:' . $embedUrl;
        }

        $cfg = self::decryptPutarinConfig($embedUrl);
        if (!$cfg) {
            return null;
        }

        $origin = $cfg['_origin'] ?? 'https://putarin.xyz';
        $file = $cfg['file'] ?? ($cfg['sources'][0]['file'] ?? '');
        if (empty($file)) {
            return null;
        }

        $masterUrl = strpos($file, 'http') === 0 ? $file : rtrim($origin, '/') . '/' . ltrim($file, '/');

        return [
            'slug' => $titleOrSlug,
            'ep' => $ep,
            'mode' => 'sub',
            'server' => 'Otakudesu (Sub Indo)',
            'source' => 'otakudesu',
            'master' => $masterUrl,
            'referer' => $origin . '/',
            'subtitles' => [],
        ];
    }
}
