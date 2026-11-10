<?php
declare(strict_types=1);

namespace Tatap\Player;

use Tatap\Config;
use Tatap\Core\Http;
use Tatap\Core\Response;

class VideoProxy
{
    public static function isAllowedUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = strtolower($parsed['host'] ?? '');
        if (empty($host)) {
            return false;
        }

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '0.0.0.0') {
            return false;
        }

        if (strpos($host, '10.') === 0 || strpos($host, '192.168.') === 0 || strpos($host, '169.254.') === 0) {
            return false;
        }

        // Check if IP is private
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    public static function resolveAbsoluteUrl(string $base, string $rel): string
    {
        if (strpos($rel, 'http://') === 0 || strpos($rel, 'https://') === 0) {
            return $rel;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $root = "$scheme://{$host}{$port}";

        if (strpos($rel, '//') === 0) {
            return "$scheme:$rel";
        }

        if (strpos($rel, '/') === 0) {
            return $root . $rel;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);
        return $root . $dir . $rel;
    }

    public static function rewriteM3u8(string $manifest, string $baseUrl, string $referer = ''): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $manifest));
        $output = [];
        $baseApi = Config::getBasePath() . '/api/player/video';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                $output[] = '';
                continue;
            }

            if (strpos($trimmed, '#') === 0) {
                // Check for URI inside quotes in tags (e.g. URI="sub.m3u8")
                if (preg_match('/URI="([^"]+)"/', $line, $m)) {
                    $abs = self::resolveAbsoluteUrl($baseUrl, $m[1]);
                    $proxyUrl = $baseApi . '?url=' . urlencode($abs);
                    if (!empty($referer)) {
                        $proxyUrl .= '&referer=' . urlencode($referer);
                    }
                    $line = str_replace('URI="' . $m[1] . '"', 'URI="' . $proxyUrl . '"', $line);
                }
                $output[] = $line;
            } else {
                // Media segment or child playlist URL
                $abs = self::resolveAbsoluteUrl($baseUrl, $trimmed);
                $proxyUrl = $baseApi . '?url=' . urlencode($abs);
                if (!empty($referer)) {
                    $proxyUrl .= '&referer=' . urlencode($referer);
                }
                $output[] = $proxyUrl;
            }
        }

        return implode("\n", $output);
    }

    public static function serve(string $url, string $referer = '', ?string $rangeHeader = null): void
    {
        if (!self::isAllowedUrl($url)) {
            Response::error('Invalid or restricted target URL', 400);
            return;
        }

        $isM3u8 = (stripos($url, '.m3u8') !== false || stripos($url, 'manifest') !== false);

        if ($isM3u8) {
            // Atomic manifest fetch & rewrite
            $res = Http::get($url, [], 10, $referer);
            if ($res['status'] < 200 || $res['status'] >= 400 || empty($res['body'])) {
                Response::error('Failed to fetch M3U8 manifest from upstream', 502);
                return;
            }

            $rewritten = self::rewriteM3u8($res['body'], $url, $referer);
            header('Content-Type: application/vnd.apple.mpegurl');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: no-cache, no-transform');
            header('Content-Length: ' . strlen($rewritten));
            echo $rewritten;
            exit;
        }

        // Direct low-memory streaming chunk proxy for video segments
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, Http::DEFAULT_UA);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (!empty($referer)) {
            curl_setopt($ch, CURLOPT_REFERER, $referer);
        }

        // Forward Range header if present and valid
        if (!empty($rangeHeader) && preg_match('/^bytes=\d*-\d*$/', $rangeHeader)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Range: {$rangeHeader}"]);
        }

        // Forward upstream response headers to client
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) {
            $len = strlen($header);
            $trimmed = trim($header);
            if (empty($trimmed)) {
                return $len;
            }

            if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d+)/i', $trimmed, $m)) {
                http_response_code((int)$m[1]);
                return $len;
            }

            $low = strtolower($trimmed);
            if (strpos($low, 'content-type:') === 0 ||
                strpos($low, 'content-length:') === 0 ||
                strpos($low, 'content-range:') === 0 ||
                strpos($low, 'accept-ranges:') === 0) {
                header($trimmed);
            }

            return $len;
        });

        // Stream body directly to browser output
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=86400, immutable');

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) {
            echo $chunk;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            return strlen($chunk);
        });

        curl_exec($ch);
        curl_close($ch);
        exit;
    }
}
