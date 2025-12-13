<?php

namespace StoneScriptPHP\Tenancy;

use PDO;
use PDOStatement;

/**
 * Tenant-Aware Query Builder
 *
 * For shared database multi-tenancy strategy where all tenants share the same database
 * but are isolated using a tenant_id column.
 *
 * Automatically adds tenant_id filtering to all queries to ensure data isolation.
 *
 * Usage:
 *   $builder = new TenantQueryBuilder($pdo, 'products');
 *   $products = $builder->all(); // SELECT * FROM products WHERE tenant_id = ?
 *   $product = $builder->find(123); // SELECT * FROM products WHERE id = ? AND tenant_id = ?
 */
class TenantQueryBuilder
{
    /**
     * WHERE clauses
     */
    private array $where = [];

    /**
     * Bound parameters
     */
    private array $bindings = [];

    /**
     * SELECT columns
     */
    private array $select = ['*'];

    /**
     * ORDER BY clause
     */
    private ?string $orderBy = null;

    /**
     * LIMIT clause
     */
    private ?int $limit = null;

    /**
     * OFFSET clause
     */
    private ?int $offset = null;

    /**
     * Create a new TenantQueryBuilder
     *
     * @param PDO $db Database connection
     * @param string $table Table name
     * @param bool $autoFilter Automatically add tenant_id filter (default: true)
     * @param string $tenantColumn Column name for tenant ID (default: 'tenant_id')
     */
    public function __construct(
        private PDO $db,
        private string $table,
        private bool $autoFilter = true,
        private string $tenantColumn = 'tenant_id'
    ) {}

    /**
     * Set SELECT columns
     *
     * @param array|string $columns
     * @return self
     */
    public function select(array|string $columns): self
    {
        $this->select = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    /**
     * Add WHERE condition
     *
     * @param string|array $column Column name or associative array of conditions
     * @param mixed $operator Operator or value if using = operator
     * @param mixed $value Value (optional if operator is the value)
     * @return self
     */
    public function where(string|array $column, mixed $operator = null, mixed $value = null): self
    {
        // Handle associative array
        if (is_array($column)) {
            foreach ($column as $col => $val) {
                $this->where($col, '=', $val);
            }
            return $this;
        }

        // Handle two-parameter form: where('column', 'value')
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->where[] = "{$column} {$operator} ?";
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add WHERE IN condition
     *
     * @param string $column
     * @param array $values
     * @return self
     */
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            // Empty IN clause would fail, so add impossible condition
            $this->where[] = '1 = 0';
            return $this;
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->where[] = "{$column} IN ({$placeholders})";
        $this->bindings = array_merge($this->bindings, $values);

        return $this;
    }

    /**
     * Add ORDER BY clause
     *
     * @param string $column
     * @param string $direction 'ASC' or 'DESC'
     * @return self
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new \InvalidArgumentException("Invalid order direction: {$direction}");
        }

        $this->orderBy = "{$column} {$direction}";
        return $this;
    }

    /**
     * Set LIMIT
     *
     * @param int $limit
     * @return self
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set OFFSET
     *
     * @param int $offset
     * @return self
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Get all records
     *
     * @return array
     */
    public function all(): array
    {
        return $this->get();
    }

    /**
     * Execute query and get results
     *
     * @return array
     */
    public function get(): array
    {
        $sql = $this->buildSelectSql();
        $stmt = $this->execute($sql);

        return $stmt->fetchAll();
    }

    /**
     * Get first record
     *
     * @return array|null
     */
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();

        return $results[0] ?? null;
    }

    /**
     * Find record by ID
     *
     * @param int $id
     * @return array|null
     */
    public function find(int $id): ?array
    {
        return $this->where('id', $id)->first();
    }

    /**
     * Insert new record
     *
     * @param array $data Associative array of column => value
     * @return int Last insert ID
     */
    public function insert(array $data): int
    {
        // Add tenant_id if auto-filtering is enabled
        if ($this->autoFilter && TenantContext::check()) {
            $data[$this->tenantColumn] = TenantContext::id();
        }

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($data), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update records
     *
     * @param array $data Associative array of column => value
     * @return int Number of affected rows
     */
    public function update(array $data): int
    {
        if (empty($this->where) && $this->autoFilter) {
            $this->addTenantFilter();
        }

        if (empty($this->where)) {
            throw new \RuntimeException('Cannot update without WHERE clause. Use where() to specify conditions.');
        }

        $setClauses = [];
        $values = [];

        foreach ($data as $column => $value) {
            $setClauses[] = "{$column} = ?";
            $values[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->table,
            implode(', ', $setClauses),
            implode(' AND ', $this->where)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge($values, $this->bindings));

        return $stmt->rowCount();
    }

    /**
     * Delete records
     *
     * @return int Number of affected rows
     */
    public function delete(): int
    {
        if (empty($this->where) && $this->autoFilter) {
            $this->addTenantFilter();
        }

        if (empty($this->where)) {
            throw new \RuntimeException('Cannot delete without WHERE clause. Use where() to specify conditions.');
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->table,
            implode(' AND ', $this->where)
        );

        $stmt = $this->execute($sql);

        return $stmt->rowCount();
    }

    /**
     * Get count of records
     *
     * @return int
     */
    public function count(): int
    {
        $originalSelect = $this->select;
        $this->select = ['COUNT(*) as count'];

        $result = $this->first();

        $this->select = $originalSelect;

        return (int) ($result['count'] ?? 0);
    }

    /**
     * Check if any records exist
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Build SELECT SQL
     *
     * @return string
     */
    private function buildSelectSql(): string
    {
        // Add tenant filter if enabled
        if ($this->autoFilter && empty($this->where)) {
            $this->addTenantFilter();
        }

        $sql = sprintf(
            'SELECT %s FROM %s',
            implode(', ', $this->select),
            $this->table
        );

        if (!empty($this->where)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }

        if ($this->orderBy) {
            $sql .= ' ORDER BY ' . $this->orderBy;
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * Add tenant filter to WHERE clauses
     *
     * @return void
     */
    private function addTenantFilter(): void
    {
        if (!TenantContext::check()) {
            throw new \RuntimeException(
                'Tenant context not set. Ensure TenantMiddleware is applied or disable autoFilter.'
            );
        }

        $this->where[] = "{$this->tenantColumn} = ?";
        $this->bindings[] = TenantContext::id();
    }

    /**
     * Execute prepared statement
     *
     * @param string $sql
     * @return PDOStatement
     */
    private function execute(string $sql): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->bindings);

        return $stmt;
    }

    /**
     * Begin transaction
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return $this->db->beginTransaction();
    }

    /**
     * Commit transaction
     *
     * @return bool
     */
    public function commit(): bool
    {
        return $this->db->commit();
    }

    /**
     * Rollback transaction
     *
     * @return bool
     */
    public function rollback(): bool
    {
        return $this->db->rollBack();
    }
}
