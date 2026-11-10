<?php
declare(strict_types=1);

namespace Tatap\Scrapers;

use Tatap\Core\Http;
use Tatap\Core\Cache;

class Translate
{
    public const GOOGLE_URL = 'https://translate.googleapis.com/translate_a/single';

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

        $params = http_build_query([
            'client' => 'gtx',
            'sl' => $source,
            'tl' => $target,
            'dt' => 't',
            'q' => $text,
        ]);

        $url = self::GOOGLE_URL . '?' . $params;
        $res = Http::get($url, [], 8);

        if ($res['status'] !== 200 || empty($res['body'])) {
            return $text;
        }

        $json = json_decode($res['body'], true);
        if (!is_array($json) || empty($json[0]) || !is_array($json[0])) {
            return $text;
        }

        $translated = '';
        foreach ($json[0] as $segment) {
            if (isset($segment[0]) && is_string($segment[0])) {
                $translated .= $segment[0];
            }
        }

        if (!empty($translated)) {
            Cache::set($cacheKey, $translated, 86400 * 7);
            return $translated;
        }

        return $text;
    }

    public static function translateVttString(string $vtt, string $target = 'id'): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $vtt));
        $output = [];
        $textBuffer = [];
        $timingIndexMap = [];

        foreach ($lines as $idx => $line) {
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
        $cacheKey = 'sub_vtt_' . md5($url . '_' . $target);
        $cached = Cache::get($cacheKey, 86400 * 7);
        if ($cached !== null && is_string($cached)) {
            return $cached;
        }

        $res = Http::get($url, [], 10, $referer);
        if ($res['status'] !== 200 || empty($res['body'])) {
            return "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\n[Subtitle unavailable]\n";
        }

        $vtt = $res['body'];
        if (strpos($vtt, 'WEBVTT') === false) {
            $vtt = "WEBVTT\n\n" . $vtt;
        }

        Cache::set($cacheKey, $vtt, 86400 * 7);
        return $vtt;
    }
}
