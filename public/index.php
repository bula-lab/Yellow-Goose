<?php
use ZoltaGaska\Runtime;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;
use ZoltaGaska\Http\Response;

if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Brak autoloadera. Uruchom composer install.');
}
try {
    require __DIR__ . '/../bootstrap.php';
    $request = Request::capture();
    $app = Runtime::application(dirname(__DIR__));
    $response = $app->handle($request);
} catch (HttpException $error) {
    $response = Response::json(['error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log((string) $error);
    $response = Response::json(['error' => 'Wewnętrzny błąd serwera.'], 500);
}
$response->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
