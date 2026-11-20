<?php
namespace ZoltaGaska\Scene;

use ZoltaGaska\Http\HttpException;

/** JSON representation shared by imports, direct service calls and HTTP commands. */
final class SceneInput
{
    public static function id(mixed $value, string $field = 'ID'): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $value)
            || in_array($value, ['constructor', 'prototype'], true)) {
            throw new HttpException(422, "Invalid $field.");
        }
        return $value;
    }

    public static function actor(mixed $id): string
    {
        if (!is_string($id) || $id === '' || strlen($id) > 128 || trim($id) !== $id || preg_match('/[\x00-\x1f\x7f]/', $id)
            || !preg_match('//u', $id)) throw new HttpException(422, 'Invalid account ID.');
        return $id;
    }

    public static function name(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 160
            || !preg_match('//u', $value) || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new HttpException(422, 'Invalid name.');
        return trim($value);
    }

    public static function object(mixed $value, string $field): array
    {
        if ($value instanceof \stdClass) return (array) $value;
        if (is_array($value) && ($value === [] || !array_is_list($value))) return $value;
        throw new HttpException(422, "$field must be an object.");
    }

    public static function keys(array $input, array $allowed, array $required = []): void
    {
        if (array_diff(array_keys($input), $allowed) || array_diff($required, array_keys($input))) {
            throw new HttpException(422, 'Unexpected or missing field.');
        }
    }

    public static function json(mixed $value): string
    {
        try { return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 32); }
        catch (\JsonException) { throw new HttpException(422, 'Invalid scene JSON.'); }
    }

    public static function decode(string $json): array
    {
        return self::unpack(json_decode($json, false, 32, JSON_THROW_ON_ERROR));
    }

    private static function unpack(mixed $value, string $key = ''): mixed
    {
        if ($value instanceof \stdClass) {
            if ($key === 'properties' || (array) $value === []) return $value;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $child => $item) $value[$child] = self::unpack($item, (string) $child);
        }
        return $value;
    }

    /** Sort JSON object keys for hashing while retaining array order. */
    public static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass || (is_array($value) && !array_is_list($value))) {
            $fields = (array) $value;
            ksort($fields, SORT_STRING);
            return (object) array_map([self::class, 'canonical'], $fields);
        }
        return is_array($value) ? array_map([self::class, 'canonical'], $value) : $value;
    }
}
