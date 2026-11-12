<?php
namespace ZoltaGaska\Database;

use PDO;

final class Builder
{
    private PDO $pdo;
    private Model $model;
    private array $conditions = [];
    private array $bindings = [];
    private array $order = [];
    private ?int $limit = null;
    private int $offset = 0;
    private string $table;

    public function __construct(private string $modelClass)
    {
        $this->model = new $modelClass();
        $this->table = $this->identifier($this->model->getTable());
        $this->pdo = Connection::getInstance();
    }

    private function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value)) throw new \InvalidArgumentException('Invalid SQL identifier.');
        return chr(96) . $value . chr(96);
    }

    private function column(string $column): string
    {
        if (!in_array($column, $this->model->getColumns(), true)) throw new \InvalidArgumentException('Unknown column: ' . $column);
        return $this->identifier($column);
    }

    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) { $value = $operator; $operator = '='; }
        $operator = strtoupper(trim((string) $operator));
        if (!in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE'], true)) throw new \InvalidArgumentException('Unsupported SQL operator.');
        $quoted = $this->column($column);
        if ($value === null && in_array($operator, ['=', '!=', '<>'], true)) {
            $this->conditions[] = $quoted . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');
        } else {
            $this->conditions[] = $quoted . ' ' . $operator . ' ?';
            $this->bindings[] = $value;
        }
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) throw new \InvalidArgumentException('Invalid ordering.');
        $this->order[] = $this->column($column) . ' ' . $direction;
        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        if ($limit < 1 || $limit > 1000 || $offset < 0) throw new \InvalidArgumentException('Invalid pagination.');
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }

    private function whereSql(): string
    {
        return $this->conditions ? ' WHERE ' . implode(' AND ', $this->conditions) : '';
    }

    public function get(): array
    {
        $sql = 'SELECT * FROM ' . $this->table . $this->whereSql();
        if ($this->order) $sql .= ' ORDER BY ' . implode(', ', $this->order);
        if ($this->limit !== null) $sql .= ' LIMIT ' . $this->limit . ' OFFSET ' . $this->offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return array_map(fn (array $row) => $this->modelClass::hydrate($row), $stmt->fetchAll());
    }

    public function all(): array { return $this->get(); }

    public function count(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->table . $this->whereSql());
        $stmt->execute($this->bindings);
        return (int) $stmt->fetchColumn();
    }

    public function first(): ?Model
    {
        $query = clone $this;
        return $query->limit(1)->get()[0] ?? null;
    }

    public function find(int|string $id): ?Model
    {
        return $this->where($this->model->getKeyName(), $id)->first();
    }

    public function create(array $attributes): Model
    {
        $attributes = array_intersect_key($attributes, array_flip($this->model->getFillable()));
        if (!$attributes) throw new \InvalidArgumentException('No writable attributes.');
        $columns = array_map(fn ($key) => $this->column($key), array_keys($attributes));
        $stmt = $this->pdo->prepare('INSERT INTO ' . $this->table . ' (' . implode(', ', $columns)
            . ') VALUES (' . implode(', ', array_fill(0, count($attributes), '?')) . ')');
        $stmt->execute(array_values($attributes));
        return (new self($this->modelClass))->find($this->pdo->lastInsertId())
            ?? throw new \RuntimeException('Inserted record could not be read.');
    }

    public function update(string $keyName, mixed $keyValue, array $attributes): bool
    {
        $key = $this->column($keyName);
        $attributes = array_intersect_key($attributes, array_flip($this->model->getFillable()));
        if (!$attributes) return true;
        $sets = array_map(fn ($name) => $this->column($name) . ' = ?', array_keys($attributes));
        $stmt = $this->pdo->prepare('UPDATE ' . $this->table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $key . ' = ?');
        return $stmt->execute([...array_values($attributes), $keyValue]);
    }

    public function delete(string $keyName, mixed $keyValue): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->table . ' WHERE ' . $this->column($keyName) . ' = ?');
        $stmt->execute([$keyValue]);
        return $stmt->rowCount() > 0;
    }

    public function destroy(int|string $id): bool
    {
        return $this->delete($this->model->getKeyName(), $id);
    }
}
