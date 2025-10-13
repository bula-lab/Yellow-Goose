<?php

namespace ZoltaGaska\Database;

use PDO;

class Builder
{
    private PDO $pdo;
    private string $query = '';
    private array $bindings = [];
    private string $table;
    private string $modelClass;

    public function __construct(string $modelClass)
    {
        $this->pdo = Connection::getInstance();
        $this->modelClass = $modelClass;
        $this->table = (new $modelClass())->getTable();
    }

    public function where(string $column, string $operator, $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (strpos(strtoupper($this->query), 'WHERE') === false) {
            $this->query .= " WHERE {$column} {$operator} ?";
        } else {
            $this->query .= " AND {$column} {$operator} ?";
        }
        $this->bindings[] = $value;
        return $this;
    }

    public function get(): array
    {
        $sql = "SELECT * FROM {$this->table}" . $this->query;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        
        // Hydrate results into model instances
        return array_map(function ($record) {
            return new $this->modelClass($record, false);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function first(): ?object
    {
        $sql = "SELECT * FROM {$this->table}" . $this->query . " LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        return $record ? new $this->modelClass($record, false) : null;
    }

    public function find($id, $columns = ['*']): ?object
    {
        $primaryKey = (new $this->modelClass())->getKeyName();
        $this->where($primaryKey, '=', $id);
        return $this->first();
    }
    
    public function create(array $attributes): object
    {
        $columns = implode(', ', array_keys($attributes));
        $placeholders = implode(', ', array_fill(0, count($attributes), '?'));
        
        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($attributes));
        
        $id = $this->pdo->lastInsertId();
        $primaryKey = (new $this->modelClass())->getKeyName();
        $attributes[$primaryKey] = $id;
        
        return new $this->modelClass($attributes, false);
    }

    public function update(string $keyName, $keyValue, array $attributes): bool
    {
        $setClauses = [];
        foreach (array_keys($attributes) as $column) {
            $setClauses[] = "{$column} = ?";
        }
        $setClause = implode(', ', $setClauses);

        $sql = "UPDATE {$this->table} SET {$setClause} WHERE {$keyName} = ?";
        
        $bindings = array_merge(array_values($attributes), [$keyValue]);

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($bindings);
    }

    public function delete(string $keyName, $keyValue): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE {$keyName} = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$keyValue]);
    }
}
