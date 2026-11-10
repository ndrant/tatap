<?php
declare(strict_types=1);

namespace Tatap\Core;

use PDO;
use Tatap\Config;

class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dbPath = Config::dbPath();
            $dsn = 'sqlite:' . $dbPath;
            self::$pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            self::$pdo->exec('PRAGMA journal_mode = WAL;');
            self::$pdo->exec('PRAGMA synchronous = NORMAL;');
            self::$pdo->exec('PRAGMA busy_timeout = 5000;');

            self::initSchema();
        }

        return self::$pdo;
    }

    private static function initSchema(): void
    {
        $sql = <<<SQL
        CREATE TABLE IF NOT EXISTS watch_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visitor_token TEXT NOT NULL,
            slug TEXT NOT NULL,
            title TEXT,
            episode INTEGER DEFAULT 1,
            mode TEXT DEFAULT 'sub',
            progress INTEGER DEFAULT 0,
            poster TEXT DEFAULT '',
            type TEXT DEFAULT '',
            played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_hist_visitor ON watch_history(visitor_token, slug);

        CREATE TABLE IF NOT EXISTS bookmarks (
            visitor_token TEXT NOT NULL,
            slug TEXT NOT NULL,
            title TEXT,
            poster TEXT DEFAULT '',
            type TEXT DEFAULT '',
            sub TEXT DEFAULT '',
            eps TEXT DEFAULT '',
            score REAL DEFAULT 0.0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (visitor_token, slug)
        );

        CREATE TABLE IF NOT EXISTS watch_progress (
            visitor_token TEXT NOT NULL,
            slug TEXT NOT NULL,
            episode INTEGER NOT NULL,
            progress INTEGER DEFAULT 0,
            duration INTEGER DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (visitor_token, slug, episode)
        );

        CREATE TABLE IF NOT EXISTS settings (
            visitor_token TEXT NOT NULL,
            key TEXT NOT NULL,
            value TEXT NOT NULL,
            PRIMARY KEY (visitor_token, key)
        );
SQL;
        self::$pdo->exec($sql);
    }
}
