<?php
require __DIR__ . '/vendor/autoload.php';
ZoltaGaska\Runtime::boot(__DIR__);
if (class_exists('App\\Models\\Post')) throw new RuntimeException('Demo model leaked into consumer.');
$path = (new ReflectionClass(ZoltaGaska\Http\Application::class))->getFileName();
if (!str_starts_with($path, __DIR__ . '/vendor/yellow-goose/framework/')) {
    throw new RuntimeException('Framework was not installed in the consumer vendor directory.');
}
$app = ZoltaGaska\Runtime::application(__DIR__);
$response = $app->handle(new ZoltaGaska\Http\Request('/', 'GET'));
if ($response->status !== 200) throw new RuntimeException($response->body);
if ($app->handle(new ZoltaGaska\Http\Request('/api/health', 'GET'))->body !== '{"consumer":true}') {
    throw new RuntimeException('Consumer API failed.');
}
echo $response->body;
