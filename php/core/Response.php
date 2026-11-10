<?php
declare(strict_types=1);

namespace Tatap\Core;

class Response
{
    public static function formatJson($data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function json($data, int $statusCode = 200, array $headers = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Visitor-Token, Range');
        foreach ($headers as $k => $v) {
            header("$k: $v");
        }
        echo self::formatJson($data);
        exit;
    }

    public static function error(string $message, int $statusCode = 400, array $extra = []): void
    {
        $payload = array_merge([
            'status' => 'error',
            'detail' => $message,
            'message' => $message,
        ], $extra);
        self::json($payload, $statusCode);
    }

    public static function corsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Visitor-Token, Range');
    }
}
