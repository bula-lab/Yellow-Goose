<?php
use ZoltaGaska\Assets\{AssetService,LocalStorage};
use ZoltaGaska\Jobs\Queue;
use ZoltaGaska\Security\Identity;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$input = json_decode(fgets(STDIN), true, 16, JSON_THROW_ON_ERROR);
$dir = $input['dir'];
$pdo = new PDO('sqlite:' . $dir . '/assets.sqlite');
$pdo->exec('PRAGMA busy_timeout=10000');
$queue = new Queue($pdo);
$assets = new AssetService($queue, new LocalStorage($dir . '/files'), random_bytes(32),
    fn ($actor, $project) => $actor->id === 'editor' && $project === 'p', fn () => true);
echo "ready\n"; fflush(STDOUT);
if (fgets(STDIN) !== "start\n") exit(1);
if ($input['operation'] === 'complete') {
    $result = $assets->complete('p', $input['id'], new Identity('editor'), $input['token']);
    echo $result['assetVersionId'], "\n";
} else {
    $count = $queue->work(['assets.prepare' => [$assets, 'process']], 1, ['assets.prepare' => [$assets, 'failed']]);
    echo $count, "\n";
}
