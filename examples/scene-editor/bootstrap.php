<?php
$local = __DIR__ . '/vendor/autoload.php';
$autoload = require (is_file($local) ? $local : dirname(__DIR__, 2) . '/vendor/autoload.php');
$autoload->addPsr4('SceneStudio\\', __DIR__ . '/src/SceneStudio');
$data = getenv('SCENE_DATA_DIR') ?: __DIR__ . '/var';
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'migrate' && !is_dir($data) && !mkdir($data, 0700, true) && !is_dir($data)) {
    throw new RuntimeException('Cannot initialize scene data directory.');
}
return new SceneStudio\Site(__DIR__, $data, getenv('SCENE_SIGNING_KEY') ?: '');
