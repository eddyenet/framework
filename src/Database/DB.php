<?php

declare(strict_types=1);

namespace Lovante\Database;

/**
 * DB Facade
 *
 * Static accessor for database operations.
 *
 * Usage:
 *   DB::table('users')->where('active', 1)->get();
 *   DB::select('SELECT * FROM users WHERE id = ?', [1]);
 *   DB::insert('INSERT INTO users (name) VALUES (?)', ['Alice']);
 */
class DB
{
    protected static ?Connection $connection = null;
    protected static ?array $config = null;

    /**
     * Set the default connection configuration
     */
    public static function setConfig(array $config): void
    {
        static::$config = $config;
        static::$connection = null; // reset connection
    }

    /**
     * Get the connection instance
     */
    public static function connection(): Connection
    {
        if (static::$connection === null) {
            if (static::$config === null) {
                throw new \Exception('Database not configured. Call DB::setConfig() first.');
            }
            static::$connection = Connection::make(static::$config);
        }

        return static::$connection;
    }

    /**
     * Start a query builder on a table
     */
    public static function table(string $table): QueryBuilder
    {
        return (new QueryBuilder(static::connection()))->table($table);
    }

    /**
     * Raw select query
     */
    public static function select(string $sql, array $bindings = []): array
    {
        return static::connection()->select($sql, $bindings);
    }

    /**
     * Raw select one query
     */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        return static::connection()->selectOne($sql, $bindings);
    }

    /**
     * Raw insert query (returns last insert ID)
     */
    public static function insert(string $sql, array $bindings = []): string
    {
        return static::connection()->insert($sql, $bindings);
    }

    /**
     * Raw update/delete query (returns affected rows)
     */
    public static function affectingStatement(string $sql, array $bindings = []): int
    {
        return static::connection()->affectingStatement($sql, $bindings);
    }

    /**
     * Raw statement (no return value)
     */
    public static function statement(string $sql): bool
    {
        return static::connection()->statement($sql);
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool
    {
        return static::connection()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool
    {
        return static::connection()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollBack(): bool
    {
        return static::connection()->rollBack();
    }

    /**
     * Execute callback in a transaction
     */
    public static function transaction(callable $callback): mixed
    {
        return static::connection()->transaction($callback);
    }

    /**
     * Enable query logging
     */
    public static function enableQueryLog(): void
    {
        Connection::enableQueryLog();
    }

    /**
     * Get query log
     */
    public static function getQueryLog(): array
    {
        return Connection::getQueryLog();
    }

    /**
     * Flush query log
     */
    public static function flushQueryLog(): void
    {
        Connection::flushQueryLog();
    }
}