<?php
declare(strict_types=1);

namespace Tatap\Scrapers;

use Tatap\Core\Http;
use Tatap\Core\Cache;
use Tatap\Core\Database;

class Translate
{
    private const ENDPOINTS = [
        'https://clients5.google.com/translate_a/t?client=dict-chrome-ex',
        'https://clients5.google.com/translate_a/t?client=tw-ob',
        'https://translate.google.com/translate_a/t?client=dict-chrome-ex',
        'https://translate.googleapis.com/translate_a/single?client=dict-chrome-ex&dt=t',
    ];

    public static function translateText(string $text, string $target = 'id', string $source = 'auto'): string
    {
        $text = trim($text);
        if (empty($text)) {
            return '';
        }

        $cacheKey = 'gtx_' . md5($source . '_' . $target . '_' . $text);
        $cached = Cache::get($cacheKey, 86400 * 7);
        if ($cached !== null && is_string($cached)) {
            return $cached;
        }

        foreach (self::ENDPOINTS as $base) {
            $url = $base . '&sl=' . urlencode($source) . '&tl=' . urlencode($target);
            $res = Http::request('POST', $url, [
                'body' => http_build_query(['q' => $text]),
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'timeout' => 8,
            ]);

            if ($res['status'] === 200 && !empty($res['body'])) {
                $json = json_decode($res['body'], true);
                $translated = '';
                if (is_array($json)) {
                    if (isset($json[0]) && is_array($json[0]) && isset($json[0][0]) && is_string($json[0][0])) {
                        $translated = $json[0][0];
                    } elseif (isset($json[0]) && is_string($json[0])) {
                        $translated = $json[0];
                    } elseif (isset($json[0]) && is_array($json[0])) {
                        foreach ($json[0] as $segment) {
                            if (isset($segment[0]) && is_string($segment[0])) {
                                $translated .= $segment[0];
                            }
                        }
                    }
                } elseif (is_string($json)) {
                    $translated = $json;
                }

                if (!empty($translated)) {
                    Cache::set($cacheKey, $translated, 86400 * 7);
                    return $translated;
                }
            }
        }

        return $text;
    }

    public static function translateVttString(string $vtt, string $target = 'id'): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $vtt));
        $output = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (strpos($trimmed, '-->') !== false) {
                // Timing line
                $output[] = $line;
            } elseif ($trimmed === '' || strpos($trimmed, 'WEBVTT') === 0 || strpos($trimmed, 'NOTE') === 0 || is_numeric($trimmed)) {
                // Header, blank, or cue counter
                $output[] = $line;
            } else {
                // Subtitle dialogue line
                $output[] = self::translateText($trimmed, $target);
            }
        }

        return implode("\n", $output);
    }

    public static function fetchAndTranslateVtt(string $url, string $target = 'raw', string $referer = ''): string
    {
        $baseHash = sha1($url . '|' . $referer . '|' . $target);
        $keys = [
            'sub2|gtx+|' . $baseHash,
            'sub2|gtx|' . $baseHash,
            'sub_vtt_' . md5($url . '_' . $target),
        ];

        foreach ($keys as $k) {
            $cachedDb = Database::getSubtitleCache($k);
            if ($cachedDb !== null && !empty($cachedDb)) {
                return $cachedDb;
            }
            $cachedFile = Cache::get($k, 86400 * 30);
            if ($cachedFile !== null && is_string($cachedFile) && !empty($cachedFile)) {
                return $cachedFile;
            }
        }

        $res = Http::get($url, [], 10, $referer);
        if ($res['status'] !== 200 || empty($res['body'])) {
            return "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\n[Subtitle unavailable]\n";
        }

        $vtt = $res['body'];
        if (strpos($vtt, 'WEBVTT') === false) {
            $vtt = "WEBVTT\n\n" . $vtt;
        }

        if ($target !== 'raw' && !empty($target)) {
            $vtt = self::translateVttString($vtt, $target);
        }

        $cacheKey = 'sub_vtt_' . md5($url . '_' . $target);
        Cache::set($cacheKey, $vtt, 86400 * 30);
        Database::setSubtitleCache($cacheKey, $vtt);
        return $vtt;
    }
}
