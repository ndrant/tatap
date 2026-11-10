<?php
declare(strict_types=1);

namespace Tatap\User;

use Tatap\Core\Database;

class Settings
{
    public static function all(string $token): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT key, value FROM settings WHERE visitor_token = ?');
        $stmt->execute([$token]);
        $rows = $stmt->fetchAll();
        $res = [];
        foreach ($rows as $r) {
            $res[$r['key']] = $r['value'];
        }
        return $res;
    }

    public static function get(string $token, string $key, string $default = ''): string
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE visitor_token = ? AND key = ? LIMIT 1');
        $stmt->execute([$token, $key]);
        $val = $stmt->fetchColumn();
        return ($val !== false) ? (string)$val : $default;
    }

    public static function set(string $token, string $key, string $value): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings (visitor_token, key, value) VALUES (?, ?, ?)');
        return $stmt->execute([$token, $key, $value]);
    }
}
