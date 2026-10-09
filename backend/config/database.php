<?php
declare(strict_types=1);

function database_connection(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $configuredPath = getenv('AUTH_DATABASE_PATH');
    if ($configuredPath === false || $configuredPath === '') {
        $projectKey = substr(hash('sha256', dirname(__DIR__, 2)), 0, 12);
        $databaseDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'northstar-auth-' . $projectKey;
        $databasePath = $databaseDirectory . DIRECTORY_SEPARATOR . 'auth.sqlite';
    } else {
        $databasePath = $configuredPath;
        $databaseDirectory = dirname($databasePath);
    }
    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0700, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Database directory could not be created.');
    }

    $connection = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec('PRAGMA foreign_keys = ON');
    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL COLLATE NOCASE UNIQUE,
            password TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME
        )',
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS rate_limits (
            rate_key TEXT PRIMARY KEY,
            window_started INTEGER NOT NULL,
            attempts INTEGER NOT NULL
        )',
    );

    return $connection;
}