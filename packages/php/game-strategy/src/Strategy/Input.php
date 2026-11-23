<?php
namespace ZoltaGaska\Strategy;

use ZoltaGaska\Http\HttpException;

final class Input
{
    public static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $value)
            || in_array($value, ['constructor', 'prototype'], true)) throw new HttpException(422, 'Invalid identifier.');
        return $value;
    }

    public static function text(mixed $value, int $max): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max || trim($value) !== $value
            || !preg_match('//u', $value) || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new HttpException(422, 'Invalid text.');
        return $value;
    }

    public static function keys(array $input, array $keys): void
    {
        if (array_diff($keys, array_keys($input)) || array_diff(array_keys($input), $keys)) {
            throw new HttpException(422, 'Unexpected or missing field.');
        }
    }

    public static function command(array $input): array
    {
        self::keys($input, ['commandId', 'type', 'expectedRevision', 'expectedRulesVersion', 'payload']);
        $id = self::id($input['commandId']);
        $revision = $input['expectedRevision'];
        if (!is_int($revision) || $revision < 0) throw new HttpException(422, 'Invalid expected revision.');
        $field = match ($input['type']) {
            'build.start' => 'target',
            'reward.claim' => 'rewardId',
            default => throw new HttpException(422, 'Unknown command.'),
        };
        $payload = $input['payload'];
        if ($payload instanceof \stdClass) $payload = (array) $payload;
        if (!is_array($payload)) throw new HttpException(422, 'Expected an object payload.');
        self::keys($payload, [$field]);
        return ['commandId' => $id, 'type' => $input['type'], 'expectedRevision' => $revision,
            'expectedRulesVersion' => self::text($input['expectedRulesVersion'], 64),
            'payload' => [$field => self::id($payload[$field])]];
    }

    public static function limit(int $limit): int
    {
        if ($limit < 1 || $limit > 100) throw new HttpException(422, 'Limit must be between 1 and 100.');
        return $limit;
    }

    public static function queryInt(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $value) || (string) (int) $value !== $value) {
            throw new HttpException(422, 'Invalid numeric query parameter.');
        }
        return (int) $value;
    }
}
