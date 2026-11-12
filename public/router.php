<?php

// Router for the PHP development server. Only public, non-PHP assets bypass the app.
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath(__DIR__ . $uri);
if (
    $file !== false
    && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
    && is_file($file)
    && !preg_match('~(^|/)\.~', $uri)
    && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php'
) {
    return false;
}
require __DIR__ . '/index.php';
