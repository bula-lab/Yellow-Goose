<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$config = require __DIR__ . '/modules.php';
$registry = ZoltaGaska\Module\Registry::boot($config['modules']);
exit($registry->run($argv[1] ?? 'example:status', array_slice($argv, 2)));
