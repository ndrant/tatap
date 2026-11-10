<?php
declare(strict_types=1);

namespace Tatap\Core;

class Http
{
    public const DEFAULT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public static function request(string $method, string $url, array $options = []): array
    {
        $ch = curl_init();
        $headers = $options['headers'] ?? [];
        $timeout = $options['timeout'] ?? 15;
        $referer = $options['referer'] ?? '';
        $body = $options['body'] ?? null;
        $ua = $options['user_agent'] ?? self::DEFAULT_UA;

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = is_numeric($k) ? $v : "$k: $v";
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (!empty($referer)) {
            curl_setopt($ch, CURLOPT_REFERER, $referer);
        }

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                if (is_array($body) || is_object($body)) {
                    $bodyStr = json_encode($body);
                    $curlHeaders[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
                }
            }
        }

        if (!empty($curlHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        return [
            'status' => $httpCode,
            'body' => (string)$rawResponse,
            'content_type' => $contentType,
            'error' => $error,
        ];
    }

    public static function get(string $url, array $headers = [], int $timeout = 15, string $referer = ''): array
    {
        return self::request('GET', $url, [
            'headers' => $headers,
            'timeout' => $timeout,
            'referer' => $referer,
        ]);
    }

    public static function post(string $url, $body = null, array $headers = [], int $timeout = 15): array
    {
        return self::request('POST', $url, [
            'body' => $body,
            'headers' => $headers,
            'timeout' => $timeout,
        ]);
    }

    public static function jsonGet(string $url, array $headers = [], int $timeout = 15, string $referer = ''): ?array
    {
        $res = self::get($url, $headers, $timeout, $referer);
        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['body'])) {
            $data = json_decode($res['body'], true);
            return is_array($data) ? $data : null;
        }
        return null;
    }
}
