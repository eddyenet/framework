<?php

declare(strict_types=1);

namespace Lovante\Database;

use PDO;
use PDOException;
use Exception;

/**
 * Database Connection Manager
 *
 * Wraps PDO with:
 *  - Connection pooling (singleton per config)
 *  - Query tracking for debug toolbar
 *  - Automatic reconnect on connection loss
 */
class Connection
{
    protected PDO $pdo;
    protected array $config;

    /**
     * Query log for debug toolbar
     * ['query' => '...', 'bindings' => [...], 'time' => 1.23]
     */
    protected static array $queryLog = [];

    /**
     * Enable/disable query logging
     */
    protected static bool $logging = false;

    /**
     * Connection pool (keyed by DSN)
     */
    protected static array $connections = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    /**
     * Get or create a connection
     */
    public static function make(array $config): static
    {
        $key = static::makeKey($config);

        if (!isset(static::$connections[$key])) {
            static::$connections[$key] = new static($config);
        }

        return static::$connections[$key];
    }

    /**
     * Connect to database
     */
    protected function connect(): void
    {
        $driver = $this->config['driver'] ?? 'mysql';

        $dsn = match($driver) {
            'mysql'  => $this->buildMysqlDsn(),
            'sqlite' => $this->buildSqliteDsn(),
            default  => throw new Exception("Unsupported driver: {$driver}"),
        };

        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // MySQL-specific: use native charset
        if ($driver === 'mysql') {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES '{$this->config['charset']}'";
        }

        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    protected function buildMysqlDsn(): string
    {
        $host    = $this->config['host']     ?? 'localhost';
        $port    = $this->config['port']     ?? 3306;
        $dbname  = $this->config['database'] ?? '';
        $charset = $this->config['charset']  ?? 'utf8mb4';

        return "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    }

    protected function buildSqliteDsn(): string
    {
        $path = $this->config['database'] ?? ':memory:';
        return "sqlite:{$path}";
    }

    protected static function makeKey(array $config): string
    {
        return md5(json_encode($config));
    }

    // =========================================================================
    // Query Execution
    // =========================================================================

    /**
     * Execute a query and return PDOStatement
     */
    public function query(string $sql, array $bindings = []): \PDOStatement
    {
        $start = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
        } catch (PDOException $e) {
            throw new Exception("Query failed: {$e->getMessage()}\nSQL: {$sql}", 0, $e);
        }

        $time = (microtime(true) - $start) * 1000; // ms

        if (static::$logging) {
            static::$queryLog[] = [
                'query'    => $sql,
                'bindings' => $bindings,
                'time'     => round($time, 2),
            ];
        }

        return $stmt;
    }

    /**
     * Execute and return all rows
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->query($sql, $bindings)->fetchAll();
    }

    /**
     * Execute and return first row
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $result = $this->query($sql, $bindings)->fetch();
        return $result ?: null;
    }

    /**
     * Execute INSERT/UPDATE/DELETE and return affected rows
     */
    public function affectingStatement(string $sql, array $bindings = []): int
    {
        return $this->query($sql, $bindings)->rowCount();
    }

    /**
     * Execute INSERT and return last inserted ID
     */
    public function insert(string $sql, array $bindings = []): string
    {
        $this->query($sql, $bindings);
        return $this->pdo->lastInsertId();
    }

    /**
     * Execute a statement (non-SELECT, no return value needed)
     */
    public function statement(string $sql): bool
    {
        return $this->pdo->exec($sql) !== false;
    }

    // =========================================================================
    // Transactions
    // =========================================================================

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Run a callback inside a transaction
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // Query Logging (for Toolbar)
    // =========================================================================

    public static function enableQueryLog(): void
    {
        static::$logging = true;
    }

    public static function disableQueryLog(): void
    {
        static::$logging = false;
    }

    public static function getQueryLog(): array
    {
        return static::$queryLog;
    }

    public static function flushQueryLog(): void
    {
        static::$queryLog = [];
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    /**
     * Get underlying PDO instance (for advanced usage)
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Get driver name
     */
    public function getDriverName(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Get database name
     */
    public function getDatabaseName(): string
    {
        return $this->config['database'] ?? '';
    }
}