<?php
declare(strict_types=1);

namespace Tatap\User;

use Tatap\Core\Database;

class History
{
    public static function list(string $token): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT slug, title, episode, mode, progress, poster, type, played_at FROM watch_history WHERE visitor_token = ? ORDER BY played_at DESC LIMIT 100');
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
        $episode = (int)($data['episode'] ?? 1);
        $mode = (string)($data['mode'] ?? 'sub');
        $progress = (int)($data['progress'] ?? 0);
        $poster = (string)($data['poster'] ?? '');
        $type = (string)($data['type'] ?? '');

        $pdo = Database::pdo();
        // Remove existing row for same slug and episode to prevent duplicates
        $del = $pdo->prepare('DELETE FROM watch_history WHERE visitor_token = ? AND slug = ? AND episode = ?');
        $del->execute([$token, $slug, $episode]);

        $stmt = $pdo->prepare('INSERT INTO watch_history (visitor_token, slug, title, episode, mode, progress, poster, type, played_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
        return $stmt->execute([$token, $slug, $title, $episode, $mode, $progress, $poster, $type]);
    }

    public static function clear(string $token): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('DELETE FROM watch_history WHERE visitor_token = ?');
        return $stmt->execute([$token]);
    }

    public static function getProgress(string $token, string $slug, int $episode): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT progress, duration FROM watch_progress WHERE visitor_token = ? AND slug = ? AND episode = ? LIMIT 1');
        $stmt->execute([$token, $slug, $episode]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function saveProgress(string $token, string $slug, int $episode, int $progress, int $duration = 0): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO watch_progress (visitor_token, slug, episode, progress, duration, updated_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
        return $stmt->execute([$token, $slug, $episode, $progress, $duration]);
    }

    public static function deleteProgress(string $token, string $slug, int $episode): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('DELETE FROM watch_progress WHERE visitor_token = ? AND slug = ? AND episode = ?');
        return $stmt->execute([$token, $slug, $episode]);
    }
}
