<?php
require __DIR__ . '/../vendor/autoload.php';

use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;
use ZoltaGaska\Resources\ResourceDefinition;

$checks = 0;
foreach (["0", "\0\xff\r\n", '', 'model fragment'] as $bytes) {
    $request = new Request('/api/upload', 'PUT', ['Content-Type' => 'application/octet-stream'], $bytes);
    if ($request->bytes(262144) !== $bytes) throw new RuntimeException('Upload bytes changed.');
    $checks++;
}
$request = new Request('/api/upload', 'PUT', [], str_repeat('x', 262145));
try { $request->bytes(262144); throw new RuntimeException('Oversized chunk accepted.'); }
catch (HttpException $error) { if ($error->status !== 413) throw $error; $checks++; }
foreach ([0, -1, Request::MAX_BODY_BYTES + 1] as $limit) {
    try { $request->bytes($limit); throw new RuntimeException('Invalid byte limit accepted.'); }
    catch (InvalidArgumentException) { $checks++; }
}
$field = ['type' => 'integer', 'label' => 'Height', 'group' => 'Dimensions', 'unit' => ['label' => 'cm', 'factor' => 10],
    'choices' => [2000, 2130, 2400]];
$resource = new ResourceDefinition('geometry', 'geometry.read', ['heightMm' => $field], fn () => ['items' => []]);
if ($resource->schema()['fields']['heightMm'] !== $field) throw new RuntimeException('Unit schema was changed.');
$checks++;
foreach ([['label' => 'cm', 'factor' => 0], ['label' => 'cm', 'factor' => INF], ['label' => 'cm', 'factor' => '10'],
    ['label' => 'cm', 'factor' => 10, 'script' => 'run()']] as $unit) {
    try {
        new ResourceDefinition('geometry', 'geometry.read', ['heightMm' => array_replace($field, ['unit' => $unit])], fn () => ['items' => []]);
        throw new RuntimeException('Invalid unit metadata accepted.');
    } catch (InvalidArgumentException) { $checks++; }
}
echo "PASS: {$checks} bounded binary request and unit schema assertions\n";
