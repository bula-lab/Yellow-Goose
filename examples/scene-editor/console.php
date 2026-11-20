<?php
try {
    $site = require __DIR__ . '/bootstrap.php';
    $command = $argv[1] ?? 'help';
    if ($command === 'migrate') {
        foreach ($site->migrate() as $name) echo $name . PHP_EOL;
    } elseif ($command === 'seed') {
        $site->seed(getenv('SCENE_EDITOR_PASSWORD') ?: '');
        echo "Scene editor account initialized.\n";
    } elseif ($command === 'help') {
        echo implode(PHP_EOL, ['migrate', 'seed', ...$site->registry->commands()]) . PHP_EOL;
    } else {
        exit($site->registry->run($command, array_slice($argv, 2)));
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
