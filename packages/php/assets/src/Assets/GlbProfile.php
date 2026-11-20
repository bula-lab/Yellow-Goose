<?php
namespace ZoltaGaska\Assets;

use DomainException;
use stdClass;

/** Strict static-triangle GLB profile. Every accepted field has an explicit interpretation. */
final class GlbProfile
{
    public const MAX_BYTES = 16777216;
    private const MAX_VERTICES = 250000;
    private const MAX_TRIANGLES = 250000;
    private const COMPONENTS = ['SCALAR' => 1, 'VEC2' => 2, 'VEC3' => 3];
    private const ENCODINGS = [5121 => [1, 'C'], 5123 => [2, 'v'], 5125 => [4, 'V'], 5126 => [4, 'g']];

    private string $binary;
    private array $views = [];
    private array $accessors = [];
    private array $materials = [];
    private array $viewRoles = [];
    private array $vertexAccessors = [];
    private int $vertices = 0;
    private int $triangles = 0;

    public static function validate(string $file): void
    {
        $size = strlen($file);
        self::check($size >= 32 && $size <= self::MAX_BYTES && substr($file, 0, 4) === 'glTF');
        $head = unpack('Vversion/Vlength/VjsonLength/VjsonType', $file, 4);
        $jsonLength = $head['jsonLength'];
        $binHeader = 20 + $jsonLength;
        self::check($head['version'] === 2 && $head['length'] === $size && $head['jsonType'] === 0x4E4F534A
            && $jsonLength > 0 && $jsonLength <= 262144 && $jsonLength % 4 === 0 && $binHeader + 8 < $size);
        $bin = unpack('Vlength/Vtype', $file, $binHeader);
        self::check($bin['type'] === 0x004E4942 && $bin['length'] % 4 === 0 && $binHeader + 8 + $bin['length'] === $size);
        try { $root = json_decode(substr($file, 20, $jsonLength), false, 48, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new DomainException('Invalid glTF JSON.'); }
        $profile = new self();
        $profile->binary = substr($file, $binHeader + 8);
        $profile->document($root);
    }

    private static function check(bool $ok): void
    {
        if (!$ok) throw new DomainException('Invalid or unsupported static glTF model.');
    }

    private static function object(mixed $obj, array $required, array $optional = []): stdClass
    {
        self::check($obj instanceof stdClass);
        $keys = array_keys(get_object_vars($obj));
        self::check(!array_diff($required, $keys) && !array_diff($keys, $required, $optional, ['name']));
        if (property_exists($obj, 'name')) self::check(is_string($obj->name) && strlen($obj->name) <= 256);
        foreach ($keys as $key) self::check($obj->$key !== null);
        return $obj;
    }

    private static function listing(mixed $items, int $max): array
    {
        self::check(is_array($items) && count($items) >= 1 && count($items) <= $max);
        return $items;
    }

    private static function index(mixed $i, array $items): int
    {
        self::check(is_int($i) && array_key_exists($i, $items));
        return $i;
    }

    private static function integer(mixed $n, int $min, int $max): int
    {
        self::check(is_int($n) && $n >= $min && $n <= $max);
        return $n;
    }

    private static function number(mixed $n, float $min = -1000000, float $max = 1000000): float
    {
        self::check((is_int($n) || is_float($n)) && is_finite((float) $n) && $n >= $min && $n <= $max);
        return $n;
    }

    private static function vector(mixed $values, int $count, float $min = -1000000, float $max = 1000000): array
    {
        self::check(is_array($values) && count($values) === $count);
        return array_map(fn ($v) => self::number($v, $min, $max), $values);
    }

    private function document(mixed $value): void
    {
        $root = self::object($value, ['asset', 'buffers', 'bufferViews', 'accessors', 'meshes', 'nodes', 'scenes'],
            ['scene', 'images', 'textures', 'materials', 'samplers']);
        $asset = self::object($root->asset, ['version'], ['generator', 'copyright']);
        self::check($asset->version === '2.0');
        foreach (['generator', 'copyright'] as $key) if (isset($asset->$key)) self::check(is_string($asset->$key));
        $buffer = self::listing($root->buffers, 1)[0];
        self::object($buffer, ['byteLength']);
        $length = self::integer($buffer->byteLength, 1, strlen($this->binary));
        self::check(strlen($this->binary) - $length <= 3 && trim(substr($this->binary, $length), "\0") === '');
        foreach (self::listing($root->bufferViews, 1024) as $view) {
            self::object($view, ['buffer', 'byteLength'], ['byteOffset', 'byteStride', 'target']);
            self::check($view->buffer === 0);
            $start = self::integer($view->byteOffset ?? 0, 0, $length - 1);
            $bytes = self::integer($view->byteLength, 1, $length - $start);
            if (isset($view->byteStride)) self::check(self::integer($view->byteStride, 4, 252) % 4 === 0);
            if (isset($view->target)) self::check(in_array($view->target, [34962, 34963], true));
            $this->views[] = [$start, $bytes, $view->byteStride ?? null, $view->target ?? null];
        }
        $components = 0;
        foreach (self::listing($root->accessors, 1024) as $accessor) {
            self::object($accessor, ['bufferView', 'type', 'componentType', 'count'], ['byteOffset', 'min', 'max']);
            $viewId = self::index($accessor->bufferView, $this->views);
            [$base, $length, $stride, $target] = $this->views[$viewId];
            self::check(is_string($accessor->type) && isset(self::COMPONENTS[$accessor->type])
                && is_int($accessor->componentType) && isset(self::ENCODINGS[$accessor->componentType]));
            $arity = self::COMPONENTS[$accessor->type];
            [$width, $format] = self::ENCODINGS[$accessor->componentType];
            $offset = self::integer($accessor->byteOffset ?? 0, 0, $length - 1);
            $count = self::integer($accessor->count, 1, self::MAX_TRIANGLES * 3);
            $stride ??= $arity * $width;
            self::check($stride >= $arity * $width && $stride % $width === 0 && $offset % $width === 0 && ($base + $offset) % $width === 0
                && $offset + ($count - 1) * $stride + $arity * $width <= $length);
            $components += $count * $arity;
            self::check($components <= 2500000);
            $low = array_fill(0, $arity, INF);
            $high = array_fill(0, $arity, -INF);
            $unitVectors = $format === 'g' && $arity === 3;
            for ($i = 0; $i < $count; ++$i) {
                $values = unpack($format . $arity, $this->binary, $base + $offset + $i * $stride);
                if ($unitVectors) $unitVectors = abs($values[1]**2 + $values[2]**2 + $values[3]**2 - 1) < .001;
                foreach ($values as $axis => $v) {
                    self::number($v, -1000000, $format === 'g' ? 1000000 : 4294967295);
                    $low[$axis - 1] = min($low[$axis - 1], $v);
                    $high[$axis - 1] = max($high[$axis - 1], $v);
                }
            }
            foreach (['min' => $low, 'max' => $high] as $key => $actual) {
                if (!isset($accessor->$key)) continue;
                $bounds = self::vector($accessor->$key, $arity, -1000000, 4294967295);
                foreach ($bounds as $axis => $n) {
                    // glTF JSON bounds describe binary single-precision values.
                    self::check($format === 'g' ? pack('g', $n) === pack('g', $actual[$axis]) : $n == $actual[$axis]);
                }
            }
            $this->accessors[] = compact('accessor', 'low', 'high', 'target', 'viewId', 'unitVectors');
        }
        $this->materials = $this->materials($root);
        $meshes = self::listing($root->meshes, 256);
        $costs = [];
        foreach ($meshes as $mesh) {
            self::object($mesh, ['primitives']);
            $before = [$this->vertices, $this->triangles];
            $radius = 0;
            foreach (self::listing($mesh->primitives, 64) as $primitive) $radius = max($radius, $this->primitive($primitive));
            $costs[] = [$this->vertices - $before[0], $this->triangles - $before[1], $radius];
        }
        $this->hierarchy($root, $costs);
    }

    private function materials(stdClass $root): array
    {
        $images = isset($root->images) ? self::listing($root->images, 8) : [];
        $pixels = 0;
        foreach ($images as $image) {
            self::object($image, ['bufferView', 'mimeType']);
            $id = self::index($image->bufferView, $this->views);
            $this->role($id, 'image');
            [$start, $length, $stride, $target] = $this->views[$id];
            self::check(is_string($image->mimeType) && $length <= ImageProfile::MAX_BYTES && $stride === null && $target === null);
            [$decoded, , $cost] = ImageProfile::decode(substr($this->binary, $start, $length), $image->mimeType);
            unset($decoded);
            $pixels += $cost;
            self::check($pixels <= ImageProfile::MAX_PIXELS);
        }
        $samplers = isset($root->samplers) ? self::listing($root->samplers, 16) : [];
        foreach ($samplers as $sampler) {
            self::object($sampler, [], ['wrapS', 'wrapT', 'magFilter', 'minFilter']);
            foreach (['wrapS', 'wrapT'] as $k) if (isset($sampler->$k)) self::check(in_array($sampler->$k, [33071, 33648, 10497], true));
            if (isset($sampler->magFilter)) self::check(in_array($sampler->magFilter, [9728, 9729], true));
            if (isset($sampler->minFilter)) self::check(in_array($sampler->minFilter, [9728, 9729, 9984, 9985, 9986, 9987], true));
        }
        $textures = isset($root->textures) ? self::listing($root->textures, 16) : [];
        foreach ($textures as $texture) {
            self::object($texture, ['source'], ['sampler']);
            self::index($texture->source, $images);
            if (isset($texture->sampler)) self::index($texture->sampler, $samplers);
        }
        $materials = isset($root->materials) ? self::listing($root->materials, 128) : [];
        foreach ($materials as $material) {
            self::object($material, [], ['pbrMetallicRoughness', 'doubleSided']);
            if (isset($material->doubleSided)) self::check(is_bool($material->doubleSided));
            if (!isset($material->pbrMetallicRoughness)) continue;
            $pbr = self::object($material->pbrMetallicRoughness, [], ['baseColorFactor', 'baseColorTexture', 'metallicFactor', 'roughnessFactor']);
            if (isset($pbr->baseColorFactor)) self::vector($pbr->baseColorFactor, 4, 0, 1);
            foreach (['metallicFactor', 'roughnessFactor'] as $k) if (isset($pbr->$k)) self::number($pbr->$k, 0, 1);
            if (isset($pbr->baseColorTexture)) {
                $tex = self::object($pbr->baseColorTexture, ['index'], ['texCoord']);
                self::index($tex->index, $textures);
                self::check(($tex->texCoord ?? 0) === 0);
            }
        }
        return $materials;
    }

    private function role(int $viewId, string $role): void
    {
        self::check(($this->viewRoles[$viewId] ?? $role) === $role);
        $this->viewRoles[$viewId] = $role;
    }

    private function primitive(mixed $value): float
    {
        $p = self::object($value, ['attributes'], ['indices', 'material', 'mode']);
        self::check(($p->mode ?? 4) === 4);
        $attrs = self::object($p->attributes, ['POSITION'], ['NORMAL', 'TEXCOORD_0']);
        self::check(!isset($attrs->name));
        $count = null;
        $radius = 0;
        foreach (['POSITION' => 'VEC3', 'NORMAL' => 'VEC3', 'TEXCOORD_0' => 'VEC2'] as $attr => $type) {
            if (!isset($attrs->$attr)) continue;
            $entry = $this->accessors[self::index($attrs->$attr, $this->accessors)];
            $a = $entry['accessor'];
            self::check($a->componentType === 5126 && $a->type === $type && ($entry['target'] ?? 34962) === 34962);
            $view = $entry['viewId'];
            $this->role($view, 'vertex');
            if (isset($this->vertexAccessors[$view]) && $this->vertexAccessors[$view] !== $attrs->$attr) self::check($this->views[$view][2] !== null);
            $this->vertexAccessors[$view] = $attrs->$attr;
            if ($attr === 'POSITION') {
                self::check(isset($a->min, $a->max));
                $axisBounds = array_map(fn ($lo, $hi) => max(abs($lo), abs($hi)), $entry['low'], $entry['high']);
                $radius = sqrt(array_sum(array_map(fn ($v) => $v * $v, $axisBounds)));
            }
            if ($attr === 'NORMAL') self::check($entry['unitVectors']);
            $count ??= $a->count;
            self::check($a->count === $count);
        }
        $elements = $count;
        if (isset($p->indices)) {
            $entry = $this->accessors[self::index($p->indices, $this->accessors)];
            $this->role($entry['viewId'], 'index');
            $indices = $entry['accessor'];
            self::check(in_array($indices->componentType, [5121, 5123, 5125], true) && $indices->type === 'SCALAR'
                && $entry['high'][0] < $count && ($entry['target'] ?? 34963) === 34963 && $this->views[$entry['viewId']][2] === null
                && $entry['high'][0] < [5121 => 255, 5123 => 65535, 5125 => 4294967295][$indices->componentType]);
            $elements = $indices->count;
        }
        if (isset($p->material)) {
            $mat = $this->materials[self::index($p->material, $this->materials)];
            if (isset($mat->pbrMetallicRoughness->baseColorTexture)) self::check(isset($attrs->TEXCOORD_0));
        }
        $this->vertices += $count;
        $this->triangles += intdiv($elements, 3);
        self::check($elements % 3 === 0 && $this->vertices <= self::MAX_VERTICES && $this->triangles <= self::MAX_TRIANGLES);
        return $radius;
    }

    private function hierarchy(stdClass $root, array $meshes): void
    {
        $nodes = self::listing($root->nodes, 512);
        $parents = [];
        $cost = [0, 0];
        foreach ($nodes as $parent => $node) {
            self::object($node, [], ['mesh', 'children', 'translation', 'scale', 'rotation']);
            if (isset($node->mesh)) {
                $mesh = $meshes[self::index($node->mesh, $meshes)];
                $cost[0] += $mesh[0]; $cost[1] += $mesh[1];
                self::check($cost[0] <= self::MAX_VERTICES && $cost[1] <= self::MAX_TRIANGLES);
            }
            foreach (['translation', 'scale'] as $k) if (isset($node->$k)) self::vector($node->$k, 3);
            if (isset($node->rotation)) {
                $q = self::vector($node->rotation, 4, -1, 1);
                self::check(abs(array_sum(array_map(fn ($v) => $v * $v, $q)) - 1) < 0.0001);
            }
            foreach (isset($node->children) ? self::listing($node->children, 512) : [] as $child) {
                self::index($child, $nodes);
                self::check(!isset($parents[$child]));
                $parents[$child] = $parent;
            }
        }
        // A single, reachable forest bounds transform evaluation and rejects cycles and hidden sub-scenes.
        $scene = self::listing($root->scenes, 1)[0];
        self::check(($root->scene ?? 0) === 0);
        self::object($scene, ['nodes']);
        $roots = self::listing($scene->nodes, 512);
        $visited = [];
        $walk = function ($id, int $depth, float $maxScale, float $minScale, float $distance) use (&$walk, &$visited, $nodes, $meshes): void {
            self::index($id, $nodes);
            self::check(!isset($visited[$id]) && $depth <= 32);
            $visited[$id] = true;
            $node = $nodes[$id];
            // Rotations preserve length. These bounds include ancestor translation and non-uniform scale.
            $offset = $node->translation ?? [0, 0, 0];
            $distance += $maxScale * sqrt(array_sum(array_map(fn ($v) => $v * $v, $offset)));
            $scale = array_map('abs', $node->scale ?? [1, 1, 1]);
            $maxScale *= max($scale);
            $minScale *= min($scale);
            $radius = isset($node->mesh) ? $meshes[$node->mesh][2] : 0;
            self::check($minScale >= 1e-6 && $maxScale <= 1e6 && $distance + $maxScale * $radius <= 1e6);
            foreach ($node->children ?? [] as $child) $walk($child, $depth + 1, $maxScale, $minScale, $distance);
        };
        foreach ($roots as $id) { self::index($id, $nodes); self::check(!isset($parents[$id])); $walk($id, 1, 1, 1, 0); }
        self::check(count($visited) === count($nodes) && $cost[1] > 0);
    }
}
