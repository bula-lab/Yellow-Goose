<?php
require dirname(__DIR__) . '/vendor/autoload.php';

use ZoltaGaska\Assets\{AssetModule, AssetService, GlbProfile, ImageProfile, LocalStorage, Storage};
use ZoltaGaska\Database\Migrator;
use ZoltaGaska\Http\{HttpException, Kernel, Request};
use ZoltaGaska\Jobs\{JobsModule, Queue, StaleLease};
use ZoltaGaska\Module\{Module, Registry};
use ZoltaGaska\Security\{CsrfGuard, Gate, Identity, IdentityProvider, RequestAuthorizer};

$tests = 0;
function check(bool $valid, string $label): void {
    global $tests;
    if (!$valid) throw new RuntimeException($label);
    ++$tests;
}
function fails(string $class, callable $run, string $label): void {
    try { $run(); }
    catch (Throwable $e) { check($e instanceof $class, $label . ' raised ' . $e::class); return; }
    throw new RuntimeException($label . ' accepted');
}
function fixture(string $name): string { return file_get_contents(__DIR__ . '/fixtures/b1/' . $name); }
function transformed(string $glb, callable $change): string {
    $length = unpack('V', $glb, 12)[1];
    $doc = json_decode(substr($glb, 20, $length), true, 64, JSON_THROW_ON_ERROR);
    $bin = substr($glb, 28 + $length);
    $change($doc, $bin);
    $json = json_encode($doc, JSON_THROW_ON_ERROR);
    $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);
    $bin .= str_repeat("\0", (4 - strlen($bin) % 4) % 4);
    return 'glTF' . pack('V4', 2, 28 + strlen($json) + strlen($bin), strlen($json), 0x4e4f534a) . $json
        . pack('V2', strlen($bin), 0x004e4942) . $bin;
}
$box = fixture('box.glb'); $poster = fixture('poster.png');
foreach ([$box, fixture('triangle.glb')] as $glb) { GlbProfile::validate($glb); check(true, 'static GLB'); }
$invalid = [
    'external buffer' => function (&$doc) { $doc['buffers'][0]['uri'] = 'https://invalid.example/model'; },
    'external image' => function (&$doc) { $doc['images'] = [['uri' => 'a.png']]; },
    'unknown extension' => function (&$doc) { $doc['extensionsUsed'] = ['unsupported']; },
    'out-of-range accessor' => function (&$doc) { $doc['accessors'][0]['byteOffset'] = 128; },
    'cyclic graph' => function (&$doc) { $doc['nodes'][0]['children'] = [0]; },
    'geometry budget' => function (&$doc) { $doc['accessors'][0]['count'] = 1000000; },
    'mismatched bounds' => function (&$doc) { $doc['accessors'][0]['min'] = [-2, 0, -.5]; },
    'invalid index' => function (&$doc, &$bin) { $bin[$doc['bufferViews'][1]['byteOffset']] = chr(100); },
    'non-finite vertex' => function (&$doc, &$bin) { $bin = pack('g', INF) . substr($bin, 4); },
    'compounded scale' => function (&$doc) {
        $doc['nodes'] = [['scale' => [1000,1000,1000], 'children' => [1]], ['scale' => [2000,2000,2000], 'mesh' => 0]];
    },
    'non-unit normals' => function (&$doc) { $doc['meshes'][0]['primitives'][0]['attributes']['NORMAL'] = 0; },
    'invalid material' => function (&$doc) { $doc['meshes'][0]['primitives'][0]['material'] = 999; },
    'unsafe texture dimensions' => function (&$doc, &$bin) use ($poster) {
        $p = substr_replace($poster, pack('N', 20000), 16, 4);
        $doc['images'][] = ['bufferView' => count($doc['bufferViews']), 'mimeType' => 'image/png'];
        $doc['bufferViews'][] = ['buffer' => 0, 'byteOffset' => strlen($bin), 'byteLength' => strlen($p)];
        $bin .= $p; $doc['buffers'][0]['byteLength'] = strlen($bin);
    },
];
foreach ($invalid as $why => $mutate) fails(DomainException::class, fn () => GlbProfile::validate(transformed($box, $mutate)), $why);
[$posterStream, $mime] = ImageProfile::poster($poster);
check($mime === 'image/png' && is_resource($posterStream), 'decoded and encoded PNG'); fclose($posterStream);
$image = imagecreatetruecolor(4, 4); ob_start(); imagejpeg($image); $jpeg = ob_get_clean();
[$posterStream, $mime] = ImageProfile::poster($jpeg);
check($mime === 'image/jpeg', 'decoded and encoded JPEG'); fclose($posterStream);
fails(DomainException::class, fn () => ImageProfile::poster(substr($poster, 0, 40)), 'truncated poster');

$dir = sys_get_temp_dir() . '/yg-b1-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
register_shutdown_function(static function () use ($dir): void {
    if (!is_dir($dir)) return;
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($paths as $f) { if ($f->isDir()) rmdir($f); else unlink($f); } rmdir($dir);
});
$pdo = new PDO('sqlite:' . $dir . '/assets.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raw = new LocalStorage($dir . '/files');
$store = new class($raw) implements Storage {
    public bool $failPoster = false;
    public array $locks = [];
    public function __construct(private Storage $delegate) {}
    public function locked(string $upload, callable $operation): mixed {
        $this->locks[] = $upload;
        return $this->delegate->locked($upload, $operation);
    }
    public function size(string $key): ?int { return $this->delegate->size($key); }
    public function append(string $key, int $offset, string $bytes): void { $this->delegate->append($key, $offset, $bytes); }
    public function freeze(string $from, string $to): void { $this->delegate->freeze($from, $to); }
    public function open(string $key) { return $this->delegate->open($key); }
    public function install(string $key, $stream): void {
        if ($this->failPoster && str_ends_with($key, '.poster')) throw new RuntimeException('Storage temporarily unavailable.');
        $this->delegate->install($key, $stream);
    }
    public function delete(string $key): void { $this->delegate->delete($key); }
};
$queue = new Queue($pdo, 60, 3);
$identities = new class implements IdentityProvider {
    public ?Identity $actor;
    public function identity(Request $r): ?Identity { return $this->actor; }
};
$editor = $identities->actor = new Identity('editor');
$other = new Identity('other');
$published = [];
$mayWrite = fn ($actor, $project) => $project === 'p' && in_array($actor?->id, ['editor','other'], true);
$mayRead = function ($actor, $project, $id) use ($mayWrite, &$published) { return $mayWrite($actor, $project) || isset($published[$project][$id]); };
$service = new AssetService($queue, $store, random_bytes(40), $mayWrite, $mayRead);
$policy = new class implements Module {
    public function id(): string { return 'test-permissions'; }
    public function dependencies(): array { return []; }
    public function register(Registry $r): void { $r->policy('assets.write', fn ($actor) => $actor !== null); }
};
$handlers = ['assets.prepare' => [$service, 'process']];
$failed = ['assets.prepare' => [$service, 'failed']];
$registry = Registry::boot([new AssetModule($service, $identities), new JobsModule($queue, $handlers, $failed), $policy]);
$kernel = new Kernel($registry, new RequestAuthorizer($identities, new Gate($registry->policies()), new CsrfGuard(fn () => str_repeat('c', 32))));
(new Migrator($pdo, $dir . '/empty', $registry->migrationDirectories()))->migrate();
$csrf = ['X-CSRF-Token' => str_repeat('c', 32)];
$json = $csrf + ['Content-Type' => 'application/json'];
$request = fn ($method, $url, $headers = [], $body = '') => $kernel->handle(new Request($url, $method, $headers, $body));
$input = fn ($model, $poster) => ['modelBytes' => strlen($model), 'modelChecksum' => hash('sha256', $model),
    'posterBytes' => strlen($poster), 'posterChecksum' => hash('sha256', $poster)];
$base = '/api/assets/projects/p/uploads';
$large = transformed(fixture('triangle.glb'), function (&$doc, &$bin) {
    $bin = str_repeat($bin, 30000);
    $doc['accessors'][0]['count'] *= 30000;
    $doc['bufferViews'][0]['byteLength'] = $doc['buffers'][0]['byteLength'] = strlen($bin);
});
check(strlen($large) > Request::MAX_BODY_BYTES, 'large GLB');
$meta = json_encode($input($large, $poster));
check($request('POST', $base, ['Content-Type' => 'application/json'], $meta)->status === 403, 'CSRF before write');
check($request('POST', str_replace('/p/', '/q/', $base), $json, $meta)->status === 403, 'unknown/foreign project write');
$badMeta = $input($large, $poster); $badMeta['modelBytes'] = GlbProfile::MAX_BYTES + 1;
check($request('POST', $base, $json, json_encode($badMeta))->status === 422, 'size limit');
$session = json_decode($request('POST', $base, $json, $meta)->body, true);
$id = $session['uploadId']; $url = "$base/$id";
$headers = $csrf + ['X-Upload-Token' => $session['token'], 'Content-Type' => 'application/octet-stream'];
check($request('GET', $url, $csrf)->status === 404, 'upload capability required');
$priorLocks = count($store->locks);
$alias = strtoupper($id) === $id ? 'A' . substr($id, 1) : strtoupper($id);
check($request('GET', "$base/$alias", $headers)->status === 404, 'case-aliased upload ID');
check($request('PUT', "$base/../model?offset=0", $headers, 'x')->status === 404, 'malformed upload part ID');
check($request('POST', "$base/not-a-version/complete", $headers)->status === 404, 'malformed completion ID');
check(count($store->locks) === $priorLocks, 'invalid IDs do not reach storage locks');
$caseService = new AssetService($queue, $store, random_bytes(32), fn () => true, fn () => true);
$caseService->begin('Case', $editor, $input($box,$poster));
$caseService->begin('case', $editor, $input($box,$poster));
check(array_slice($store->locks, -2) === ['quota-case','quota-case'], 'project quota lock follows ASCII case-insensitive SQL equality');
$identities->actor = $other;
check($request('GET', $url, $headers)->status === 404, 'another editor cannot use session');
$identities->actor = $editor;
check($request('POST', "$url/complete", $headers)->status === 422, 'incomplete upload');
check($request('PUT', "$url/model?offset=0", $headers, str_repeat('x', AssetService::CHUNK_BYTES + 1))->status === 413, 'hard chunk cap');
foreach (['model' => $large, 'poster' => $poster] as $kind => $bytes) {
    for ($offset = 0; $offset < strlen($bytes); $offset += AssetService::CHUNK_BYTES) {
        $chunk = substr($bytes, $offset, AssetService::CHUNK_BYTES);
        check($request('PUT', "$url/$kind?offset=$offset", $headers, $chunk)->status === 200, 'binary chunk');
        $progress = json_decode($request('GET', $url, $headers)->body, true);
        check($progress['offsets'][$kind] === $offset + strlen($chunk), 'resumable offset');
    }
}
check($request('PUT', "$url/model?offset=0", $headers, 'x')->status === 409, 'old offset rejected');
// Simulate a failed outbox write after the filesystem ownership transfer.
$pdo->exec("CREATE TRIGGER b1_job_error BEFORE INSERT ON yg_jobs BEGIN SELECT RAISE(ABORT,'injected outbox failure'); END");
fails(PDOException::class, fn () => $service->complete('p', $id, $editor, $session['token']), 'outbox rollback');
check((int) $pdo->query('SELECT COUNT(*) FROM yg_asset_versions')->fetchColumn() === 0, 'atomic rollback');
check($request('PUT', "$url/model?offset=0", $headers, 'x')->status === 409, 'frozen after failed SQL');
$pdo->exec('DROP TRIGGER b1_job_error');
$queued = json_decode($request('POST', "$url/complete", $headers)->body, true);
check($queued['status'] === 'queued' && $queued['modelUrl'] === null && $queued['posterUrl'] === null, 'no queued URL');
check(json_decode($request('POST', "$url/complete", $headers)->body, true) === $queued, 'finalization replay');
foreach (['yg_jobs','yg_asset_versions'] as $table) check((int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 1, 'one row per completed upload');
$limited = new AssetService($queue, $store, random_bytes(32), $mayWrite, $mayRead, maxPending: 1);
fails(HttpException::class, fn () => $limited->begin('p', $editor, $input($box,$poster)), 'queued plus open quota');
$tiny = new AssetService($queue, $store, random_bytes(32), $mayWrite, $mayRead, projectByteBudget: 1024);
fails(HttpException::class, fn () => $tiny->begin('p', $editor, $input($box,$poster)), 'reserved byte quota');
$store->failPoster = true;
$registry->run('jobs:work', ['1']);
check($pdo->query('SELECT state FROM yg_jobs')->fetchColumn() === 'queued', 'worker retried storage failure');
check($service->descriptor('p', $id, $editor)['modelUrl'] === null, 'no partially promoted output');
$store->failPoster = false;
$pdo->exec('UPDATE yg_jobs SET available_at=0');
$registry->run('jobs:work', ['1']);
$ready = $service->descriptor('p', $id, $editor);
check($ready['status'] === 'ready' && $ready['checksum'] === hash('sha256', $large), 'ready descriptor with frozen checksum');
check($request('PUT', "$url/model?offset=0", $headers, 'x')->status === 409, 'old capability cannot mutate ready');
check(hash_file('sha256', "$dir/files/ready/$id.model") === hash('sha256', $large), 'immutable ready bytes');
check($request('GET', $ready['modelUrl'], ['Range' => 'bytes=0-3'])->headers['Content-Range'] === 'bytes 0-3/' . strlen($large), 'single range');
$r = $request('GET', $ready['modelUrl'], ['Range' => 'bytes=0-3']);
ob_start(); $r->send(); $out = ob_get_clean();
check($r->status === 206 && $r->body === '' && $out === 'glTF', 'range streamed without response string');
check($request('GET', $ready['modelUrl'], ['Range' => 'bytes=-0'])->status === 416, 'unsatisfiable range');
check($request('GET', $ready['posterUrl'])->headers['Content-Type'] === 'image/png', 'checked poster MIME');
$identities->actor = null;
check($request('GET', $ready['modelUrl'])->status === 403 && $service->descriptor('p', $id, null) === null, 'private original and actor binding');
$published['p'][$id] = true;
$public = $service->descriptor('p', $id, null);
check($request('GET', $public['modelUrl'])->status === 200, 'public access through current publication');
check($request('GET', preg_replace('/exp=\d+/', 'exp=1', $public['modelUrl']))->status === 403, 'expired signature');
unset($published['p'][$id]);
check($request('GET', $public['modelUrl'])->status === 404, 'revocation on issued link');
check($service->descriptor('q', $id, $editor) === null, 'cross-project version rejected');
$identities->actor = $editor;
$upload = function (string $model, string $image, ?string $sum = null) use ($input, $service, $editor): array {
    $data = $input($model, $image);
    if ($sum) $data['modelChecksum'] = $sum;
    $s = $service->begin('p', $editor, $data);
    foreach (['model' => $model,'poster' => $image] as $kind => $file) $service->append('p', $s['uploadId'], $editor, $s['token'], $kind, 0, $file);
    return $s;
};
$s = $upload($box, $poster, str_repeat('0', 64));
fails(HttpException::class, fn () => $service->complete('p', $s['uploadId'], $editor, $s['token']), 'wrong SHA-256');
$expiredId = $s['uploadId'];
$pdo->exec("UPDATE yg_uploads SET expires=0 WHERE id='$expiredId'");
$registry->run('assets:prune');
check(!file_exists("$dir/files/staging/$expiredId.model"), 'expired chunks pruned');
foreach ([[transformed($box, $invalid['external buffer']), $poster], [$box, 'invalid poster']] as [$model, $image]) {
    $s = $upload($model, $image);
    $service->complete('p', $s['uploadId'], $editor, $s['token']);
    $registry->run('jobs:work', ['1']);
    $result = $service->descriptor('p', $s['uploadId'], $editor);
    check($result['status'] === 'failed' && $result['posterUrl'] === null, 'rejected file cannot publish');
}
// Lost leases cannot commit. Repeated process deaths stop dispatching business work.
$s = $upload($box, $poster); $crashId = $s['uploadId']; $service->complete('p', $crashId, $editor, $s['token']);
for ($n = 1; $n <= 3; ++$n) {
    $lease = $queue->claim(); check($lease->attempt === $n && !$lease->exhausted, 'bounded lease attempt');
    $pdo->exec("UPDATE yg_jobs SET lease_until=0 WHERE state='running'");
}
$effect = false;
fails(StaleLease::class, function () use ($lease, &$effect) { $lease->complete(function () use (&$effect) { $effect = true; }); }, 'stale completion fenced');
check(!$effect, 'stale worker did not publish');
$registry->run('jobs:work', ['1']);
check($service->descriptor('p', $crashId, $editor)['status'] === 'failed' && !$queue->claim(), 'queue and asset fail after crash limit');
$race = function (string $operation, array $session) use ($dir): array {
    $children = [];
    foreach ([1,2] as $n) {
        $child = proc_open([PHP_BINARY, __DIR__ . '/fixtures/b1/race.php'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        if (!is_resource($child)) throw new RuntimeException('Cannot start PHP race fixture.');
        fwrite($pipes[0], json_encode(['dir' => $dir, 'operation' => $operation, 'id' => $session['uploadId'], 'token' => $session['token']]) . "\n");
        fflush($pipes[0]);
        $children[] = [$child, $pipes];
    }
    foreach ($children as [, $pipes]) check(fgets($pipes[1]) === "ready\n", 'independent connection ready');
    foreach ($children as [, $pipes]) { fwrite($pipes[0], "start\n"); fclose($pipes[0]); }
    $results = [];
    foreach ($children as [$child, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($child) === 0, 'parallel worker: ' . $error);
    }
    return $results;
};
$s = $upload($box, $poster);
check($race('complete', $s) === [$s['uploadId'], $s['uploadId']], 'concurrent finalization keeps one ID');
$results = $race('work', $s); sort($results);
check($results === ['0','1'], 'only one process claims the job');
check($service->descriptor('p', $s['uploadId'], $editor)['status'] === 'ready', 'concurrent workers produce ready once');
$queue->enqueue('probe', 'probe', ['v' => 1]);
$queue->enqueue('probe', 'probe', ['v' => 1]);
fails(LogicException::class, fn () => $queue->enqueue('probe', 'probe', ['v' => 2]), 'idempotency key conflict');
echo "PASS: $tests checks (private upload, GLB/image validation, atomic finalization, recovery, streaming, authorization).\n";
