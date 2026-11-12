<?php
namespace ZoltaGaska\Database;

abstract class Model implements \JsonSerializable
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $columns = [];
    protected array $attributes = [];
    private array $original = [];
    private bool $exists = false;

    public function __construct(array $attributes = []) { $this->fill($attributes); }

    /** Database records are trusted; user input must go through fill(). */
    public static function hydrate(array $record): static
    {
        $model = new static();
        $model->attributes = array_intersect_key($record, array_flip($model->getColumns()));
        $model->original = $model->attributes;
        $model->exists = true;
        return $model;
    }

    public function getTable(): string
    {
        if (isset($this->table)) return $this->table;
        $class = (new \ReflectionClass($this))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class)) . 's';
    }

    public function getKeyName(): string { return $this->primaryKey; }
    public function getKey(): mixed { return $this->attributes[$this->primaryKey] ?? null; }
    public function getColumns(): array { return array_unique([$this->primaryKey, ...$this->fillable, ...$this->columns]); }
    public function getFillable(): array { return $this->fillable; }

    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->fillable, true)) $this->attributes[$key] = $value;
        }
        return $this;
    }

    public function save(): bool
    {
        $builder = new Builder(static::class);
        $dirty = [];
        foreach ($this->fillable as $key) {
            if (array_key_exists($key, $this->attributes)
                && (!array_key_exists($key, $this->original) || $this->original[$key] !== $this->attributes[$key])) {
                $dirty[$key] = $this->attributes[$key];
            }
        }
        if (!$this->exists) {
            $created = $builder->create($dirty);
            $this->attributes = $created->attributes;
            $this->exists = true;
        } elseif ($dirty !== []) {
            $builder->update($this->primaryKey, $this->getKey(), $dirty);
        }
        $this->original = $this->attributes;
        return true;
    }

    public function delete(): bool
    {
        if (!$this->exists) return false;
        $deleted = (new Builder(static::class))->delete($this->primaryKey, $this->getKey());
        if ($deleted) $this->exists = false;
        return $deleted;
    }

    public function __get(string $key): mixed { return $this->attributes[$key] ?? null; }

    public function __set(string $key, mixed $value): void
    {
        if (!in_array($key, $this->fillable, true)) throw new \InvalidArgumentException('Field is not writable: ' . $key);
        $this->attributes[$key] = $value;
    }

    public function toArray(): array { return $this->attributes; }
    public function jsonSerialize(): array { return $this->toArray(); }

    public static function __callStatic(string $method, array $parameters): mixed
    {
        return (new Builder(static::class))->$method(...$parameters);
    }
}
