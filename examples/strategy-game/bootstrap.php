<?php
$local = __DIR__ . '/vendor/autoload.php';
$autoload = require (is_file($local) ? $local : dirname(__DIR__, 2) . '/vendor/autoload.php');
$autoload->addPsr4('Colony\\', __DIR__ . '/src/Colony');
$data = getenv('STRATEGY_DATA_DIR') ?: __DIR__ . '/var';
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'migrate' && !is_dir($data) && !mkdir($data, 0700, true) && !is_dir($data)) {
    throw new RuntimeException('Cannot initialize strategy data directory.');
}
return new Colony\Site(__DIR__, $data);
