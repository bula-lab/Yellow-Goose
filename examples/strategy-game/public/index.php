<?php
use ZoltaGaska\Http\{HttpException, Request};
try {
    $site = require dirname(__DIR__) . '/bootstrap.php';
    $request = Request::capture();
    $response = $site->handle($request);
} catch (Throwable $error) {
    error_log($error->getMessage());
    http_response_code($error instanceof HttpException ? $error->status : 503);
    header('Cache-Control: private, no-store');
    header('Content-Type: text/plain; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo 'Strategy game is unavailable.';
    return;
}
$response->send($request->method === 'HEAD');
