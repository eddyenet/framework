<?php

declare(strict_types=1);

namespace Lovante\Database;

use Exception;

/**
 * Lovante Query Builder
 *
 * Fluent, lightweight SQL query builder.
 * Zero overhead — compiles directly to SQL strings.
 *
 * Example:
 *   DB::table('users')
 *     ->where('active', true)
 *     ->orderBy('created_at', 'desc')
 *     ->limit(10)
 *     ->get();
 */
class QueryBuilder
{
    protected Connection $connection;

    protected string $table = '';
    protected array  $columns = ['*'];
    protected array  $wheres = [];
    protected array  $bindings = [];
    protected array  $orders = [];
    protected ?int   $limitValue = null;
    protected ?int   $offsetValue = null;
    protected array  $joins = [];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    // =========================================================================
    // Table
    // =========================================================================

    public function table(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    public function from(string $table): static
    {
        return $this->table($table);
    }

    // =========================================================================
    // Select
    // =========================================================================

    public function select(string|array $columns = ['*']): static
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    // =========================================================================
    // Where
    // =========================================================================

    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        // where('id', 1)  → column, value
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type'     => 'basic',
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
            'boolean'  => 'and',
        ];

        $this->bindings[] = $value;
        return $this;
    }

    public function orWhere(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type'     => 'basic',
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
            'boolean'  => 'or',
        ];

        $this->bindings[] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = [
            'type'    => 'in',
            'column'  => $column,
            'values'  => $values,
            'boolean' => 'and',
        ];

        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = [
            'type'    => 'null',
            'column'  => $column,
            'boolean' => 'and',
        ];
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = [
            'type'    => 'not_null',
            'column'  => $column,
            'boolean' => 'and',
        ];
        return $this;
    }

    public function whereBetween(string $column, array $values): static
    {
        $this->wheres[] = [
            'type'    => 'between',
            'column'  => $column,
            'values'  => $values,
            'boolean' => 'and',
        ];

        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    // =========================================================================
    // Joins
    // =========================================================================

    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): static
    {
        $this->joins[] = compact('table', 'first', 'operator', 'second', 'type');
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    // =========================================================================
    // Order, Limit, Offset
    // =========================================================================

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orders[] = ['column' => $column, 'direction' => strtolower($direction)];
        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    public function limit(int $value): static
    {
        $this->limitValue = $value;
        return $this;
    }

    public function take(int $value): static
    {
        return $this->limit($value);
    }

    public function offset(int $value): static
    {
        $this->offsetValue = $value;
        return $this;
    }

    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    // =========================================================================
    // Execution
    // =========================================================================

    /**
     * Get all rows
     */
    public function get(): array
    {
        $sql = $this->toSql();
        return $this->connection->select($sql, $this->bindings);
    }

    /**
     * Get first row
     */
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
     * Find by ID
     */
    public function find(int|string $id, string $column = 'id'): ?array
    {
        return $this->where($column, $id)->first();
    }

    /**
     * Get single column value
     */
    public function value(string $column): mixed
    {
        $result = $this->select($column)->first();
        return $result ? $result[$column] : null;
    }

    /**
     * Pluck a single column as array
     */
    public function pluck(string $column): array
    {
        return array_column($this->select($column)->get(), $column);
    }

    /**
     * Count rows
     */
    public function count(string $column = '*'): int
    {
        $original = $this->columns;
        $this->columns = ["COUNT({$column}) as aggregate"];

        $result = $this->first();
        $this->columns = $original;

        return (int) ($result['aggregate'] ?? 0);
    }

    /**
     * Check if any rows exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    // =========================================================================
    // Insert / Update / Delete
    // =========================================================================

    /**
     * Insert a row and return inserted ID
     */
    public function insert(array $data): string
    {
        $columns = array_keys($data);
        $values  = array_values($data);

        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = "INSERT INTO {$this->table} ({$columnList}) VALUES ({$placeholders})";

        return $this->connection->insert($sql, $values);
    }

    /**
     * Insert multiple rows
     */
    public function insertMany(array $rows): int
    {
        if (empty($rows)) return 0;

        $columns = array_keys($rows[0]);
        $columnList = implode(', ', $columns);

        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $placeholders));

        $sql = "INSERT INTO {$this->table} ({$columnList}) VALUES {$allPlaceholders}";

        $bindings = [];
        foreach ($rows as $row) {
            $bindings = array_merge($bindings, array_values($row));
        }

        return $this->connection->affectingStatement($sql, $bindings);
    }

    /**
     * Update rows
     */
    public function update(array $data): int
    {
        $sets = [];
        $values = [];

        foreach ($data as $column => $value) {
            $sets[] = "{$column} = ?";
            $values[] = $value;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->compileWheres();
            $values = array_merge($values, $this->bindings);
        }

        return $this->connection->affectingStatement($sql, $values);
    }

    /**
     * Delete rows
     */
    public function delete(): int
    {
        $sql = "DELETE FROM {$this->table}";

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }

        return $this->connection->affectingStatement($sql, $this->bindings);
    }

    /**
     * Truncate table
     */
    public function truncate(): bool
    {
        return $this->connection->statement("TRUNCATE TABLE {$this->table}");
    }

    // =========================================================================
    // SQL Compilation
    // =========================================================================

    public function toSql(): string
    {
        $parts = [];

        // SELECT
        $parts[] = 'SELECT ' . implode(', ', $this->columns);

        // FROM
        $parts[] = "FROM {$this->table}";

        // JOIN
        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $type = strtoupper($join['type']);
                $parts[] = "{$type} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }

        // WHERE
        if (!empty($this->wheres)) {
            $parts[] = 'WHERE ' . $this->compileWheres();
        }

        // ORDER BY
        if (!empty($this->orders)) {
            $orders = [];
            foreach ($this->orders as $order) {
                $orders[] = "{$order['column']} {$order['direction']}";
            }
            $parts[] = 'ORDER BY ' . implode(', ', $orders);
        }

        // LIMIT
        if ($this->limitValue !== null) {
            $parts[] = "LIMIT {$this->limitValue}";
        }

        // OFFSET
        if ($this->offsetValue !== null) {
            $parts[] = "OFFSET {$this->offsetValue}";
        }

        return implode(' ', $parts);
    }

    protected function compileWheres(): string
    {
        $compiled = [];

        foreach ($this->wheres as $index => $where) {
            $boolean = $index === 0 ? '' : strtoupper($where['boolean']) . ' ';

            $compiled[] = match($where['type']) {
                'basic'    => $boolean . "{$where['column']} {$where['operator']} ?",
                'in'       => $boolean . "{$where['column']} IN (" . implode(',', array_fill(0, count($where['values']), '?')) . ")",
                'null'     => $boolean . "{$where['column']} IS NULL",
                'not_null' => $boolean . "{$where['column']} IS NOT NULL",
                'between'  => $boolean . "{$where['column']} BETWEEN ? AND ?",
                default    => '',
            };
        }

        return implode(' ', $compiled);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Dump the SQL and bindings (for debugging)
     */
    public function dd(): never
    {
        dump([
            'sql'      => $this->toSql(),
            'bindings' => $this->bindings,
        ]);
        exit(1);
    }

    /**
     * Clone for reusability
     */
    public function clone(): static
    {
        return clone $this;
    }
}