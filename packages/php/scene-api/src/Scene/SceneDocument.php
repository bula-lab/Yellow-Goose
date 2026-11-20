<?php
namespace ZoltaGaska\Scene;

use ZoltaGaska\Http\HttpException;

final class SceneDocument
{
    public const COORDINATES = ['handedness' => 'right', 'up' => 'Y', 'unit' => 'm'];
    private const IDENTITY = ['position' => [0, 0, 0], 'rotation' => [0, 0, 0, 1], 'scale' => [1, 1, 1]];
    private const EDITABLE = ['name', 'parentId', 'visible', 'assetVersionId', 'properties'];
    private const MAX_ENTITIES = 512;

    public static function create(string $projectId, string $sceneId, string $name, array $entities = []): array
    {
        return ['schemaVersion' => 1, 'projectId' => $projectId, 'sceneId' => $sceneId, 'name' => $name,
            'revision' => 0, 'coordinateSystem' => self::COORDINATES, 'entities' => $entities];
    }

    public static function imported(array $document, string $projectId, string $sceneId, callable $checkAsset): array
    {
        $fields = ['schemaVersion', 'projectId', 'sceneId', 'name', 'revision', 'coordinateSystem', 'entities'];
        SceneInput::keys($document, $fields, $fields);
        $coordinates = SceneInput::object($document['coordinateSystem'], 'coordinateSystem');
        ksort($coordinates);
        if ($document['schemaVersion'] !== 1 || !is_int($document['revision']) || $document['revision'] < 0
            || $coordinates !== ['handedness' => 'right', 'unit' => 'm', 'up' => 'Y']) {
            throw new HttpException(422, 'Unsupported scene schema or coordinate system.');
        }
        foreach (['projectId', 'sceneId'] as $field) SceneInput::id($document[$field], $field);
        $list = $document['entities'];
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_ENTITIES) throw new HttpException(422, 'Invalid entity list.');
        $entities = [];
        foreach ($list as $raw) {
            $entry = SceneInput::object($raw, 'entity');
            $id = SceneInput::id($entry['entityId'] ?? null, 'entityId');
            unset($entry['entityId']);
            if (isset($entities[$id])) throw new HttpException(422, 'Duplicate entity ID.');
            $entities[$id] = self::entity($id, $entry, $checkAsset);
        }
        self::hierarchy($entities);
        return self::create($projectId, $sceneId, SceneInput::name($document['name']), array_values($entities));
    }

    /** Mutate a local snapshot and return the committed change plus its compensating command. */
    public static function apply(array &$document, string $type, string $id, array $payload, callable $checkAsset): array
    {
        $entities = array_column($document['entities'], null, 'entityId');
        $old = $entities[$id] ?? null;
        $insert = in_array($type, ['entity.add', 'entity.duplicate'], true);
        if ($insert && ($old || count($entities) >= self::MAX_ENTITIES)) throw new HttpException(422, 'Entity exists or scene is full.');
        if (!$insert && !$old) throw new HttpException(422, 'Unknown scene entity.');
        $inverse = ['type' => 'entity.remove', 'entityId' => $id, 'payload' => (object) []];
        switch ($type) {
            case 'entity.add':
                $entities[$id] = self::entity($id, $payload, $checkAsset);
                break;
            case 'entity.duplicate':
                SceneInput::keys($payload, ['sourceId', 'name', 'transform'], ['sourceId']);
                $source = $entities[SceneInput::id($payload['sourceId'], 'sourceId')] ?? throw new HttpException(422, 'Unknown source entity.');
                unset($source['entityId'], $payload['sourceId']);
                if (array_key_exists('transform', $payload)) $payload['transform'] = self::transform($payload['transform'], $source['transform']);
                $entities[$id] = self::entity($id, array_replace($source, $payload), $checkAsset);
                break;
            case 'entity.transform':
                if (!$payload) throw new HttpException(422, 'Missing transform fields.');
                $entities[$id]['transform'] = self::transform($payload, $old['transform']);
                $inverse = ['type' => $type, 'entityId' => $id, 'payload' => array_intersect_key($old['transform'], $payload)];
                break;
            case 'entity.update':
                SceneInput::keys($payload, self::EDITABLE);
                if (!$payload) throw new HttpException(422, 'Missing entity fields.');
                $fields = array_replace($old, $payload);
                unset($fields['entityId']);
                $entities[$id] = self::entity($id, $fields, $checkAsset);
                $inverse = ['type' => $type, 'entityId' => $id, 'payload' => array_intersect_key($old, $payload)];
                break;
            case 'entity.remove':
                SceneInput::keys($payload, []);
                unset($entities[$id], $old['entityId']);
                $inverse = ['type' => 'entity.add', 'entityId' => $id, 'payload' => $old];
                break;
            default:
                throw new HttpException(422, 'Unsupported scene command.');
        }
        self::hierarchy($entities);
        $document['entities'] = array_values($entities);
        ++$document['revision'];
        $changes = isset($entities[$id]) ? ['upsert' => [$entities[$id]], 'remove' => []] : ['upsert' => [], 'remove' => [$id]];
        return ['changes' => $changes, 'inverse' => $inverse];
    }

    private static function entity(string $id, array $input, callable $checkAsset): array
    {
        SceneInput::keys($input, [...self::EDITABLE, 'type', 'transform'], ['type']);
        $fields = $input + ['name' => $id, 'visible' => true, 'parentId' => null, 'assetVersionId' => null,
            'transform' => self::IDENTITY, 'properties' => (object) []];
        if (!is_string($fields['type']) || !preg_match('/^[a-z][a-z0-9.-]{0,39}$/D', $fields['type'])) throw new HttpException(422, 'Invalid entity type.');
        if (!is_bool($fields['visible'])) throw new HttpException(422, 'Invalid visibility.');
        if ($fields['parentId'] !== null) SceneInput::id($fields['parentId'], 'parentId');
        if ($fields['assetVersionId'] !== null) $checkAsset(SceneInput::id($fields['assetVersionId'], 'assetVersionId'));
        return ['entityId' => $id, 'type' => $fields['type'], 'name' => SceneInput::name($fields['name']), 'parentId' => $fields['parentId'],
            'assetVersionId' => $fields['assetVersionId'], 'visible' => $fields['visible'],
            'transform' => self::transform($fields['transform'], self::IDENTITY), 'properties' => self::properties($fields['properties'])];
    }

    private static function transform(mixed $input, array $base): array
    {
        $fields = SceneInput::object($input, 'transform');
        SceneInput::keys($fields, array_keys(self::IDENTITY));
        foreach ($fields as $key => $vector) {
            $size = $key === 'rotation' ? 4 : 3;
            if (!is_array($vector) || !array_is_list($vector) || count($vector) !== $size) throw new HttpException(422, "Invalid $key vector.");
            foreach ($vector as $n) {
                if ((!is_float($n) && !is_int($n)) || !is_finite((float) $n) || abs($n) > 1000000
                    || ($key === 'scale' && $n < 0.000001)) throw new HttpException(422, "Invalid $key component.");
            }
            if ($key === 'rotation') {
                $length = sqrt(array_sum(array_map(static fn ($v) => $v * $v, $vector)));
                if ($length < 0.000001) throw new HttpException(422, 'Invalid quaternion magnitude.');
                if (abs($length - 1) > 1e-12) $vector = array_map(static fn ($v) => $v / $length, $vector);
            }
            $base[$key] = $vector;
        }
        return $base;
    }

    private static function properties(mixed $value): \stdClass
    {
        $fields = SceneInput::object($value, 'properties');
        if (count($fields) > 32) throw new HttpException(422, 'Too many properties.');
        foreach ($fields as $key => $item) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,47}$/D', $key)
                || in_array($key, ['constructor', 'prototype'], true)) throw new HttpException(422, 'Invalid property key.');
            if (!is_scalar($item) && $item !== null) throw new HttpException(422, 'Properties must contain scalar values.');
            if ((is_float($item) || is_int($item)) && (!is_finite((float) $item) || abs($item) > 1000000000)) throw new HttpException(422, 'Property number out of bounds.');
            if (is_string($item) && (strlen($item) > 512 || !preg_match('//u', $item))) throw new HttpException(422, 'Invalid property text.');
            if ($key === 'heightMm' && (!is_int($item) || $item < 1 || $item > 10000000)) throw new HttpException(422, 'heightMm must be a positive integer in range.');
        }
        return (object) $fields;
    }

    private static function hierarchy(array $entities): void
    {
        $bounds = [];
        foreach ($entities as $entity) {
            $path = [];
            $id = $entity['entityId'];
            while ($id !== null && !isset($bounds[$id])) {
                if (!isset($entities[$id]) || isset($path[$id])) throw new HttpException(422, 'Parent must exist in this scene and cannot form a cycle.');
                $path[$id] = true;
                $id = $entities[$id]['parentId'];
            }
            // Rotation preserves length. Bounds cover rotated, non-uniform parent scales without decomposing world matrices.
            [$radius, $minScale, $maxScale] = $id === null ? [0, 1, 1] : $bounds[$id];
            foreach (array_reverse(array_keys($path)) as $child) {
                $transform = $entities[$child]['transform'];
                [$x, $y, $z] = $transform['position'];
                $radius += $maxScale * sqrt($x * $x + $y * $y + $z * $z);
                $minScale *= min($transform['scale']);
                $maxScale *= max($transform['scale']);
                if ($radius > 1000000 || $minScale < 0.000001 || $maxScale > 1000000) {
                    throw new HttpException(422, 'Composed transform exceeds the scene bounds.');
                }
                $bounds[$child] = [$radius, $minScale, $maxScale];
            }
        }
    }
}
