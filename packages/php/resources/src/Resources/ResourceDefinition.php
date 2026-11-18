<?php
namespace ZoltaGaska\Resources;

final class ResourceDefinition
{
    public readonly \Closure $query;
    public readonly array $actions;

    public function __construct(
        public readonly string $id,
        public readonly string $readPolicy,
        public readonly array $fields,
        callable $query,
        array $actions = [],
        public readonly array $sortable = [],
        public readonly array $filterable = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $id) || $readPolicy === '') throw new \InvalidArgumentException('Invalid resource.');
        foreach ($fields as $name => $field) {
            if (!preg_match('/^[a-zA-Z_]\w*$/D', $name) || !is_array($field)
                || array_diff(array_keys($field), ['type', 'label', 'required', 'minLength', 'maxLength', 'min', 'max', 'choices'])
                || !in_array($field['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)) {
                throw new \InvalidArgumentException('Unsupported field schema.');
            }
        }
        if (array_diff([...$sortable, ...$filterable], array_keys($fields))) throw new \InvalidArgumentException('Unknown query field.');
        $byName = [];
        foreach ($actions as $action) {
            if (!$action instanceof Action || isset($byName[$action->name])) throw new \InvalidArgumentException('Duplicate or invalid action.');
            $byName[$action->name] = $action;
        }
        $this->actions = $byName;
        $this->query = \Closure::fromCallable($query);
    }

    public function schema(): array
    {
        return ['id' => $this->id, 'fields' => $this->fields, 'sortable' => $this->sortable,
            'filterable' => $this->filterable, 'actions' => array_keys($this->actions)];
    }

    public function serialize(array $row): array
    {
        return array_intersect_key($row, $this->fields);
    }
}
