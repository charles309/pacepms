<?php

declare(strict_types=1);

/**
 * PDO connection factory with a per-database connection pool (singleton per DB
 * name). Provides a least-privilege API connection (global + per-client) and a
 * separate privileged connection used only for provisioning new client DBs.
 */
final class Database
{
    /** @var array<string, PDO> Pool of API (least-privilege) connections keyed by db name. */
    private static array $instances = [];

    /** @var PDO|null Privileged provisioning connection (no default database). */
    private static ?PDO $privileged = null;

    private function __construct()
    {
    }

    /**
     * Connection to the global platform database.
     */
    public static function global(): PDO
    {
        return self::connect(DB_GLOBAL_NAME);
    }

    /**
     * Connection to a specific client database (e.g. pms_client_012).
     */
    public static function client(string $dbName): PDO
    {
        return self::connect($dbName);
    }

    /**
     * Return the shared PDO options used for every connection.
     *
     * @return array<int, mixed>
     */
    private static function pdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
    }

    /**
     * Open (or reuse) a pooled least-privilege connection to $dbName.
     */
    private static function connect(string $dbName): PDO
    {
        if (isset(self::$instances[$dbName])) {
            return self::$instances[$dbName];
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            $dbName
        );

        $pdo = new PDO($dsn, DB_USER, DB_PASS, self::pdoOptions());
        self::$instances[$dbName] = $pdo;

        return $pdo;
    }

    /**
     * Privileged connection used exclusively for database provisioning
     * (CREATE DATABASE + table creation). Configured with separate credentials
     * that hold the elevated grants the API user deliberately lacks.
     *
     * No default database is selected; callers issue USE / fully-qualified DDL.
     */
    public static function privileged(): PDO
    {
        if (self::$privileged instanceof PDO) {
            return self::$privileged;
        }

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
        self::$privileged = new PDO($dsn, DB_ROOT_USER, DB_ROOT_PASS, self::pdoOptions());

        return self::$privileged;
    }

    /**
     * Reset the pool (primarily for long-running cron processes / tests).
     */
    public static function reset(): void
    {
        self::$instances = [];
        self::$privileged = null;
    }
}
