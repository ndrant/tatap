<?php
declare(strict_types=1);

namespace Tatap\Core;

class Session
{
    private static ?string $token = null;

    public static function getVisitorToken(): string
    {
        if (self::$token !== null) {
            return self::$token;
        }

        // 1. Check custom header
        if (!empty($_SERVER['HTTP_X_VISITOR_TOKEN'])) {
            self::$token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_SERVER['HTTP_X_VISITOR_TOKEN']);
            return self::$token;
        }

        // 2. Check cookie
        if (!empty($_COOKIE['tatap_token'])) {
            self::$token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_COOKIE['tatap_token']);
            return self::$token;
        }

        // 3. Generate new 32-character token
        self::$token = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            setcookie('tatap_token', self::$token, [
                'expires' => time() + (365 * 24 * 3600),
                'path' => '/',
                'httponly' => false, // allow frontend JS to also read it if needed
                'samesite' => 'Lax',
            ]);
        }

        return self::$token;
    }

    public static function setVisitorToken(string $token): void
    {
        self::$token = $token;
    }
}
