<?php
$database = getenv('DB_DATABASE');
$sqlite = $database !== false && $database !== '' ? $database : 'database/database.sqlite';
if ($sqlite !== ':memory:' && !str_starts_with($sqlite, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $sqlite)) {
    $sqlite = dirname(__DIR__) . '/' . $sqlite;
}
return [
    'default' => getenv('DB_CONNECTION') ?: 'sqlite',
    'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => $sqlite],
        'mysql' => [
            'driver' => 'mysql', 'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: '3306',
            'database' => $database !== false && $database !== '' ? $database : 'yellow_goose',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
            'charset' => 'utf8mb4',
        ],
    ],
];
