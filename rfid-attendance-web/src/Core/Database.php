<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = config('database');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        $connection = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // TIMESTAMP reads and server-generated dates must use the application zone
        // on every request, regardless of which service initializes first.
        $zone = new \DateTimeZone(config('app')['timezone']);
        $offset = (new \DateTimeImmutable('now', $zone))->format('P');
        $connection->exec('SET SESSION time_zone = ' . $connection->quote($offset));
        self::$connection = $connection;
        AuditContext::configure($connection);

        return self::$connection;
    }
}
