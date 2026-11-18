<?php
require __DIR__ . '/../vendor/autoload.php';

function contractType(?ReflectionType $type, string $class): string
{
    // PHP 8.5 resolves self in Reflection; PHP 8.1/8.4 preserve the keyword.
    return str_replace(['?self', 'self'], ['?' . $class, $class], (string) $type);
}

function publicContract(string $class): array
{
    $reflection = new ReflectionClass($class);
    $methods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) continue;
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[] = [$parameter->getName(), contractType($parameter->getType(), $class),
                $parameter->isPassedByReference(), $parameter->isVariadic(),
                $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                $parameter->isOptional()];
        }
        $methods[$method->getName()] = [$method->isPublic(), $method->isStatic(), $parameters, contractType($method->getReturnType(), $class)];
    }
    ksort($methods);
    $properties = [];
    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
        if ($property->getDeclaringClass()->getName() !== $class) continue;
        $properties[$property->getName()] = [contractType($property->getType(), $class), $property->isPublic(), $property->isStatic(), $property->isReadOnly()];
    }
    ksort($properties);
    return ['methods' => $methods, 'properties' => $properties, 'constants' => $reflection->getConstants()];
}

$file = __DIR__ . '/contracts/legacy.json';
$contracts = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
foreach ($contracts as $class => $expected) {
    $actual = publicContract($class);
    // A2 adds optional arguments/methods; the legacy prefix and defaults must survive.
    foreach ($expected['methods'] as $name => $method) {
        $current = $actual['methods'][$name] ?? throw new RuntimeException('Missing method: ' . $class . '::' . $name);
        if (array_slice($current[2], 0, count($method[2])) !== $method[2]
            || [$current[0], $current[1], $current[3]] !== [$method[0], $method[1], $method[3]]) {
            throw new RuntimeException('Changed method contract: ' . $class . '::' . $name);
        }
        foreach (array_slice($current[2], count($method[2])) as $parameter) {
            if (!$parameter[5]) throw new RuntimeException('New required argument: ' . $class . '::' . $name);
        }
    }
    foreach (['properties', 'constants'] as $kind) foreach ($expected[$kind] as $name => $value) {
        if (($actual[$kind][$name] ?? null) !== $value) throw new RuntimeException('Changed ' . $kind . ': ' . $class . '::' . $name);
    }
}
echo 'PASS: ' . count($contracts) . " legacy class contracts\n";
