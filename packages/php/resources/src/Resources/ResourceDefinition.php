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
                || array_diff(array_keys($field), ['type', 'label', 'required', 'minLength', 'maxLength', 'min', 'max', 'choices', 'unit', 'group'])
                || !in_array($field['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)) {
                throw new \InvalidArgumentException('Unsupported field schema.');
            }
            if ((isset($field['group']) && !is_string($field['group']))
                || (isset($field['label']) && !is_string($field['label']))
                || (isset($field['required']) && !is_bool($field['required']))) {
                throw new \InvalidArgumentException('Invalid field metadata.');
            }
            if (array_key_exists('unit', $field)) {
                $unit = $field['unit'];
                if (!in_array($field['type'], ['integer', 'number'], true) || !is_array($unit)
                    || array_diff(array_keys($unit), ['label', 'factor'])
                    || !is_string($unit['label'] ?? null) || $unit['label'] === ''
                    || (!is_int($unit['factor'] ?? null) && !is_float($unit['factor'] ?? null))
                    || !is_finite((float) $unit['factor']) || $unit['factor'] <= 0) {
                    throw new \InvalidArgumentException('Invalid unit metadata.');
                }
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
