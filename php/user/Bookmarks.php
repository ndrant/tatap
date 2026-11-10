<?php
declare(strict_types=1);

namespace Tatap\User;

use Tatap\Core\Database;

class Bookmarks
{
    public static function list(string $token): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT slug, title, poster, type, sub, eps, score, created_at FROM bookmarks WHERE visitor_token = ? ORDER BY created_at DESC');
        $stmt->execute([$token]);
        return $stmt->fetchAll();
    }

    public static function add(string $token, array $data): bool
    {
        $slug = trim((string)($data['slug'] ?? ''));
        if (empty($slug)) {
            return false;
        }

        $title = (string)($data['title'] ?? $slug);
        $poster = (string)($data['poster'] ?? '');
        $type = (string)($data['type'] ?? '');
        $sub = (string)($data['sub'] ?? '');
        $eps = (string)($data['eps'] ?? '');
        $score = (float)($data['score'] ?? 0.0);

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO bookmarks (visitor_token, slug, title, poster, type, sub, eps, score, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
        return $stmt->execute([$token, $slug, $title, $poster, $type, $sub, $eps, $score]);
    }

    public static function remove(string $token, string $slug): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('DELETE FROM bookmarks WHERE visitor_token = ? AND slug = ?');
        return $stmt->execute([$token, $slug]);
    }

    public static function check(string $token, string $slug): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT 1 FROM bookmarks WHERE visitor_token = ? AND slug = ? LIMIT 1');
        $stmt->execute([$token, $slug]);
        return (bool)$stmt->fetchColumn();
    }

    public static function clear(string $token): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('DELETE FROM bookmarks WHERE visitor_token = ?');
        return $stmt->execute([$token]);
    }
}
