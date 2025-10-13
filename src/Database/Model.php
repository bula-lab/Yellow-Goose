<?php

namespace ZoltaGaska\Database;

use Exception;

abstract class Model
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $attributes = [];
    protected array $original = [];
    protected array $fillable = [];
    private bool $isNew = true;

    public function __construct(array $attributes = [], $isNew = true)
    {
        $this->isNew = $isNew;
        $this->fill($attributes);
        if (!$isNew) {
            $this->syncOriginal();
        }
    }

    public function getTable(): string
    {
        if (isset($this->table)) {
            return $this->table;
        }
        $className = substr(strrchr(get_class($this), '\\'), 1);
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className)) . 's';
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getKey()
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    public function fill(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    public function save(): bool
    {
        $builder = new Builder(get_class($this));
        $dirty = $this->getDirty();

        if ($this->isNew) {
            if (empty($dirty)) return true; // Nic do zrobienia
            $id = $builder->create($dirty)->getKey();
            if ($id) {
                $this->setAttribute($this->primaryKey, $id);
                $this->isNew = false;
                $this->syncOriginal();
                return true;
            }
            return false;
        }
        
        if (empty($dirty)) return true; // Nic się nie zmieniło
        $result = $builder->update($this->primaryKey, $this->getKey(), $dirty);
        if ($result) {
            $this->syncOriginal();
        }
        return $result;
    }

    public function delete(): bool
    {
        if ($this->isNew) {
            return false;
        }
        $builder = new Builder(get_class($this));
        return $builder->delete($this->primaryKey, $this->getKey());
    }

    private function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    private function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, $value): void
    {
        $this->setAttribute($key, $value);
    }

    public static function __callStatic(string $method, array $parameters)
    {
        return (new Builder(static::class))->$method(...$parameters);
    }

    public function __call(string $method, array $parameters)
    {
        return (new Builder(get_class($this)))->$method(...$parameters);
    }
}