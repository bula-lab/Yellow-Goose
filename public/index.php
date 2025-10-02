<?php

// public/index.php

// 1. Załaduj autoloader Composera
require __DIR__ . '/../vendor/autoload.php';

use ZoltaGaska\Http\Request;
use ZoltaGaska\Routing\Router;

// 2. Przechwyć bieżące żądanie
$request = Request::capture();

// 3. Utwórz instancję routera, podając ścieżkę do stron
// Używamy realpath, aby uzyskać bezwzględną ścieżkę
$router = new Router(realpath(__DIR__ . '/../app/pages'));

// 4. Znajdź dopasowanie (dispatch)
$route = $router->dispatch($request);

// 5. Wyświetl wynik
header('Content-Type: text/plain');

if ($route) {
    echo "Route Matched!\n\n";
    echo "File: " . $route->filePath . "\n";
    echo "Params: " . print_r($route->params, true) . "\n";
} else {
    http_response_code(404);
    echo "404 - Route Not Found";
}

