<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config->string('DB_HOST'),
            $config->string('DB_PORT'),
            $config->string('DB_NAME')
        );
        $pdo = new PDO($dsn, $config->string('DB_USER'), $config->string('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }

    /**
     * UTC timestamp for DATETIME columns.
     */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
