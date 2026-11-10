<?php
declare(strict_types=1);

namespace Tatap\Core;

use Tatap\Config;

class Cache
{
    private static function filePath(string $key): string
    {
        $hash = md5($key);
        return Config::cacheDir() . '/' . $hash . '.cache';
    }

    public static function get(string $key, int $ttl = 300)
    {
        $file = self::filePath($key);
        if (!file_exists($file)) {
            return null;
        }

        $mtime = filemtime($file);
        if ($mtime === false || (time() - $mtime) > $ttl) {
            @unlink($file);
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = @unserialize($content, ['allowed_classes' => false]);
        return ($data !== false) ? $data : null;
    }

    public static function set(string $key, $val, int $ttl = 300): void
    {
        $file = self::filePath($key);
        $serialized = serialize($val);
        @file_put_contents($file, $serialized, LOCK_EX);
        if ($ttl < 0) {
            // Force expired for testing
            @touch($file, time() - 3600);
        }
    }

    public static function delete(string $key): void
    {
        $file = self::filePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public static function clear(): void
    {
        $dir = Config::cacheDir();
        $files = glob($dir . '/*.cache');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}
