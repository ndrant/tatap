<?php
declare(strict_types=1);

namespace Tatap\Scrapers;

use Tatap\Core\Http;
use Tatap\Core\Cache;

class Aniskip
{
    public const API_BASE = 'https://api.aniskip.com/v2/skip-times';

    public static function formatSkipTimes(array $raw): array
    {
        $out = [
            'found' => (bool)($raw['found'] ?? false),
            'op' => null,
            'ed' => null,
        ];

        $results = $raw['results'] ?? [];
        foreach ($results as $r) {
            $type = strtolower((string)($r['skipType'] ?? ''));
            $interval = $r['interval'] ?? [];
            if (!isset($interval['startTime']) || !isset($interval['endTime'])) {
                continue;
            }

            $entry = [
                'start' => (float)$interval['startTime'],
                'end' => (float)$interval['endTime'],
            ];

            if ($type === 'op' || $type === 'mixed-op') {
                $out['op'] = $entry;
            } elseif ($type === 'ed' || $type === 'mixed-ed') {
                $out['ed'] = $entry;
            }
        }

        if ($out['op'] !== null || $out['ed'] !== null) {
            $out['found'] = true;
        }

        return $out;
    }

    public static function lookup(int $malId, int $ep = 1, int $duration = 0): array
    {
        if ($malId <= 0) {
            return ['found' => false, 'op' => null, 'ed' => null];
        }

        $cacheKey = "aniskip_{$malId}_{$ep}";
        $cached = Cache::get($cacheKey, 86400);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $url = self::API_BASE . "/{$malId}/{$ep}?types=op&types=ed";
        if ($duration > 0) {
            $url .= "&episodeLength={$duration}";
        }

        $res = Http::get($url, [], 6);
        $result = ['found' => false, 'op' => null, 'ed' => null];

        if ($res['status'] === 200 && !empty($res['body'])) {
            $json = json_decode($res['body'], true);
            if (is_array($json)) {
                $result = self::formatSkipTimes($json);
            }
        }

        Cache::set($cacheKey, $result, $result['found'] ? 86400 : 900);
        return $result;
    }
}
