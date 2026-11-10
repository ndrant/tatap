<?php
declare(strict_types=1);

namespace Tatap;

class Config
{
    public const VERSION = '2.12.7';
    public const APP_NAME = 'Tatap Web PHP';

    public static function dataDir(): string
    {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return realpath($dir) ?: $dir;
    }

    public static function cacheDir(): string
    {
        $dir = self::dataDir() . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function dbPath(): string
    {
        return self::dataDir() . '/tatap.sqlite';
    }

    public static function getBasePath(): string
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        // If script is in /tatap/api/index.php, base path is /tatap
        $dir = dirname($scriptName);
        if ($dir === '/' || $dir === '\\' || $dir === '.') {
            return '';
        }
        // If ending with /api, strip it
        if (substr($dir, -4) === '/api') {
            $dir = substr($dir, 0, -4);
        }
        return rtrim($dir, '/');
    }
}
