<?php
require __DIR__ . '/../vendor/autoload.php';

use ZoltaGaska\Assets\{AssetService, LocalStorage};
use ZoltaGaska\Cms\{AssetCatalog, Catalog};
use ZoltaGaska\Database\{Connection, Migrator};
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Jobs\Queue;
use ZoltaGaska\Resources\QueryOptions;
use ZoltaGaska\Security\Identity;

$database = getenv('MYSQL_TEST_DATABASE') ?: '';
if (getenv('YG_INTEGRATION_MYSQL') !== '1' || !preg_match('/^[a-zA-Z0-9_]+_test$/D', $database)) {
    throw new RuntimeException('Select a disposable *_test database and YG_INTEGRATION_MYSQL=1.');
}
$db = new PDO(Connection::dsn(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => $database,
    'port' => getenv('MYSQL_TEST_PORT') ?: 3306, 'charset' => 'utf8mb4']),
    getenv('MYSQL_TEST_USERNAME') ?: 'root', getenv('MYSQL_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$temp = sys_get_temp_dir() . '/yg-product-mysql-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
mkdir($temp . '/app');
$checks = 0;
function mysqlCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
try {
    $migrator = new Migrator($db, $temp . '/app', [
        'jobs' => __DIR__ . '/../packages/php/jobs/migrations',
        'assets' => __DIR__ . '/../packages/php/assets/migrations',
        'cms' => __DIR__ . '/../packages/php/cms/migrations',
    ]);
    $migrator->migrate();
    mysqlCheck($migrator->migrate() === [], 'module migrations repeat safely');
    $project = 'mysql-' . bin2hex(random_bytes(6));
    $identity = new Identity('mysql-editor');
    $editable = true;
    $canEdit = static function ($viewer, $candidate) use ($identity, $project, &$editable): bool {
        return $editable && $viewer?->id === $identity->id && $candidate === $project;
    };
    $queue = new Queue($db);
    $catalog = null;
    // Bind the live catalog after constructing its asset port, without a policy recursion.
    $assets = new AssetService($queue, new LocalStorage($temp . '/files'), str_repeat('test-signing-key-', 3), $canEdit,
        static function ($viewer, $candidate, $version) use ($canEdit, &$catalog) {
            return $canEdit($viewer, $candidate) || ($catalog !== null && $catalog->usesPublishedAsset($candidate, $version));
        });
    $port = new class($assets) implements AssetCatalog {
        public function __construct(private AssetService $assets) {}
        public function descriptor(string $projectId, string $assetVersionId, ?Identity $viewer): ?array {
            return $this->assets->descriptor($projectId, $assetVersionId, $viewer);
        }
    };
    $catalog = new Catalog($db, $canEdit, $port, requireAsset: true);
    $jobKey = 'rollback.' . $project;
    $db->beginTransaction();
    $queue->enqueue($jobKey, 'sample.task', ['projectId' => $project]);
    $db->rollBack();
    $find = $db->prepare('SELECT COUNT(*) FROM yg_jobs WHERE job_key=?');
    $find->execute([$jobKey]);
    mysqlCheck((int) $find->fetchColumn() === 0, 'job enqueue shares producer transaction');
    $bytes = ['model' => file_get_contents(__DIR__ . '/fixtures/b1/box.glb'),
        'poster' => file_get_contents(__DIR__ . '/fixtures/b1/poster.png')];
    $metadata = [];
    foreach ($bytes as $kind => $file) { $metadata[$kind . 'Bytes'] = strlen($file); $metadata[$kind . 'Checksum'] = hash('sha256', $file); }
    $upload = $assets->begin($project, $identity, $metadata);
    foreach ($bytes as $kind => $file) $assets->append($project, $upload['uploadId'], $identity, $upload['token'], $kind, 0, $file);
    $queued = $assets->complete($project, $upload['uploadId'], $identity, $upload['token']);
    $repeat = $assets->complete($project, $upload['uploadId'], $identity, $upload['token']);
    mysqlCheck($queued['assetVersionId'] === $repeat['assetVersionId'] && $repeat['status'] === 'queued', 'finalization is idempotent');
    mysqlCheck($queue->work(['assets.prepare' => [$assets, 'process']], 1, ['assets.prepare' => [$assets, 'failed']]) === 1, 'worker processed exactly one asset');
    $ready = $assets->descriptor($project, $queued['assetVersionId'], $identity);
    mysqlCheck($ready['status'] === 'ready' && $ready['checksum'] === $metadata['modelChecksum'], 'worker publishes correct frozen bytes');
    mysqlCheck($assets->listVersions($project, $identity)[0]['assetVersionId'] === $ready['assetVersionId'], 'version picker selects latest indexed assets');
    $catalog->saveMaterial($project, $identity, ['id' => 'graphite', 'title' => 'Graphite', 'color' => '#343d46'], false);
    $data = ['id' => 'garage', 'title' => 'Published MySQL', 'description' => 'Original snapshot',
        'assetVersionId' => $ready['assetVersionId'], 'materialIds' => ['graphite']];
    $catalog->saveProduct($project, $identity, $data, false);
    $publication = $catalog->setPublication($project, $identity, ['id' => 'garage', 'expectedRevision' => 1], true);
    mysqlCheck($publication['publishedRevision'] === 1, 'publication points at immutable draft');
    $public = $catalog->published($project, 'garage', null);
    mysqlCheck($public['title'] === 'Published MySQL' && $public['asset']['status'] === 'ready', 'public CMS resolves actual asset service');
    $catalog->saveProduct($project, $identity, array_replace($data, ['title' => 'Private draft', 'expectedRevision' => 2]), true);
    mysqlCheck($catalog->published($project, 'garage', null)['title'] === 'Published MySQL', 'later draft remains private');
    $page = $catalog->products($project, null, new QueryOptions(1, null, 'title', 'desc', ['title' => 'Published']));
    mysqlCheck(count($page['items']) === 1 && $page['items'][0]['title'] === 'Published MySQL', 'public table applies server filter and sort');
    $page = $catalog->products($project, null, new QueryOptions(1, null, 'id', 'asc', ['title' => 'Private']));
    mysqlCheck($page['items'] === [], 'public search cannot find later draft');
    try {
        $catalog->saveProduct($project, $identity, array_replace($data, ['expectedRevision' => 2]), true);
        throw new RuntimeException('Stale revision accepted');
    } catch (HttpException $error) { mysqlCheck($error->status === 409, 'stale revision conflict'); }
    $editable = false;
    try { $catalog->draft($project, 'garage', $identity); throw new RuntimeException('Revoked editor accepted'); }
    catch (HttpException $error) { mysqlCheck($error->status === 403, 'current edit policy enforced'); }
    $editable = true;
    $catalog->setPublication($project, $identity, ['id' => 'garage', 'expectedRevision' => 3], false);
    mysqlCheck(!$catalog->usesPublishedAsset($project, $ready['assetVersionId']), 'unpublish removes public asset reference');
    mysqlCheck($assets->descriptor($project, $ready['assetVersionId'], null) === null, 'asset read revoked through live publication callback');
    echo "PASS: {$checks} MySQL CMS/assets/jobs integration assertions\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($temp);
}
