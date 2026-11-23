<?php
try {
    $site = require __DIR__ . '/bootstrap.php';
    $command = $argv[1] ?? 'help';
    if ($command === 'migrate') {
        foreach ($site->migrate() as $name) echo $name . PHP_EOL;
    } elseif ($command === 'seed') {
        $site->seed(getenv('STRATEGY_PLAYER_PASSWORD') ?: '');
        echo "Demo accounts and seasonal game initialized.\n";
    } elseif ($command === 'season:open') {
        if (count($argv) !== 5 || !ctype_digit($argv[3]) || !ctype_digit($argv[4])) throw new InvalidArgumentException('Usage: season:open ID START_UNIX END_UNIX');
        $site->strategy->createSeason($argv[2], (int) $argv[3], (int) $argv[4]);
        echo "Season initialized.\n";
    } elseif ($command === 'help') {
        echo implode(PHP_EOL, ['migrate', 'seed', 'season:open ID START_UNIX END_UNIX', ...$site->registry->commands()]) . PHP_EOL;
    } else {
        exit($site->registry->run($command, array_slice($argv, 2)));
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
