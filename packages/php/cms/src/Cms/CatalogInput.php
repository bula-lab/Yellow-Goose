<?php
namespace ZoltaGaska\Cms;

use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Resources\Validator;

final class CatalogInput implements Validator
{
    private function __construct(private array $rules) {}

    public static function product(bool $existing): self
    {
        return new self(['id' => 'id', 'title' => 'title', 'description' => 'description',
            'assetVersionId' => 'asset', 'materialIds' => 'materials'] + ($existing ? ['expectedRevision' => 'revision'] : []));
    }

    public static function publication(): self { return new self(['id' => 'id', 'expectedRevision' => 'revision']); }
    public static function material(bool $existing = false): self
    {
        return new self(['id' => 'id', 'title' => 'title', 'color' => 'color'] + ($existing ? ['expectedRevision' => 'revision'] : []));
    }

    private static function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $value) === 1;
    }

    private static function validMaterials(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 32) return false;
        foreach ($value as $id) if (!self::validId($id)) return false;
        return count(array_unique($value)) === count($value);
    }

    public function validate(array $data): array
    {
        $errors = [];
        foreach (array_diff_key($data, $this->rules) as $key => $_) $errors[$key][] = 'Unknown field.';
        foreach ($this->rules as $name => $rule) {
            $value = $data[$name] ?? null;
            $valid = array_key_exists($name, $data) && match ($rule) {
                'id' => self::validId($value),
                'title', 'description' => is_string($value) && trim($value) !== ''
                    && preg_match($rule === 'title' ? '/^.{1,160}$/usD' : '/^.{1,4000}$/usD', $value) === 1,
                'asset' => $value === null || (is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value) === 1),
                'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1,
                'materials' => self::validMaterials($value),
                'revision' => is_int($value) && $value > 0,
            };
            if (!$valid) $errors[$name][] = 'Invalid or missing value.';
        }
        return $errors;
    }

    public function check(array $data): void
    {
        if ($errors = $this->validate($data)) throw new HttpException(422, 'Validation failed.', $errors);
    }
}
