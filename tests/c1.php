<?php
require __DIR__ . '/../vendor/autoload.php';

use ZoltaGaska\Database\{Connection, Migrator};
use ZoltaGaska\Http\{HttpException, Kernel, Request};
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Scene\{SceneModule, SceneService};
use ZoltaGaska\Security\{CsrfGuard, Gate, Identity, IdentityProvider, RequestAuthorizer};

function sceneDb(string $target): PDO
{
    if ($target !== '--mysql') {
        $db = new PDO('sqlite:' . $target);
        $db->exec('PRAGMA foreign_keys=ON');
        $db->exec('PRAGMA busy_timeout=5000');
        return $db;
    }
    $database = getenv('MYSQL_TEST_DATABASE') ?: '';
    if (getenv('YG_INTEGRATION_MYSQL') !== '1' || !preg_match('/^[a-zA-Z0-9_]+_test$/D', $database)) {
        throw new RuntimeException('Select a disposable *_test database and YG_INTEGRATION_MYSQL=1.');
    }
    return new PDO(Connection::dsn(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => $database,
        'port' => getenv('MYSQL_TEST_PORT') ?: 3306, 'charset' => 'utf8mb4']),
        getenv('MYSQL_TEST_USERNAME') ?: 'root', getenv('MYSQL_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}

function sceneCheck(bool $ok, string $description): void
{
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: ' . $description);
    ++$count;
}

function sceneRejects(int $status, callable $run): HttpException
{
    try { $run(); } catch (HttpException $e) {
        sceneCheck($e->status === $status, "Expected $status, received {$e->status} ({$e->getMessage()})");
        return $e;
    }
    throw new RuntimeException("Expected rejection $status.");
}

function sceneCommand(string $commandId, string $type, string $entityId, int $expectedRevision, array $payload = []): array
{
    return compact('commandId', 'type', 'entityId', 'expectedRevision', 'payload');
}

function sceneWait(callable $ready): void
{
    $deadline = microtime(true) + 10;
    while (!$ready()) {
        if (microtime(true) > $deadline) throw new RuntimeException('Timed out waiting for concurrent scene writer.');
        usleep(10000);
        clearstatcache();
    }
}

// Each contender opens a separate connection before both cross the write barrier.
if (($argv[1] ?? '') === '--writer') {
    [, , $target, $barrier, $slot, $project, $revision, $commandId] = $argv;
    $writer = new SceneService(sceneDb($target));
    touch($barrier . $slot);
    sceneWait(fn () => is_file($barrier . 'go'));
    try {
        $r = $writer->dispatch(new Identity('owner'), $project, 'main',
            sceneCommand($commandId, 'entity.transform', 'garage', (int) $revision, ['position' => [3, 0, 2]]));
        echo json_encode(['status' => 200, 'result' => $r], JSON_THROW_ON_ERROR);
    } catch (HttpException $e) {
        echo json_encode(['status' => $e->status, 'error' => $e->errors], JSON_THROW_ON_ERROR);
    }
    return;
}

function sceneRace(string $target, string $temp, string $project, int $revision, array $ids): array
{
    $barrier = $temp . '/barrier-' . $revision . '-';
    $writers = [];
    try {
        foreach ($ids as $slot => $id) {
            $process = proc_open([PHP_BINARY, __FILE__, '--writer', $target, $barrier, (string) $slot, $project, (string) $revision, $id],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Could not start scene writer.');
            $writers[] = [$process, $pipes];
        }
        sceneWait(fn () => is_file($barrier . '0') && is_file($barrier . '1'));
        touch($barrier . 'go');
        $results = [];
        foreach ($writers as [$process, $pipes]) {
            $json = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new RuntimeException('Scene writer failed: ' . $errors);
            $results[] = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        touch($barrier . 'go');
        foreach ($writers as [$process]) if (is_resource($process)) proc_close($process);
    }
}

$count = 0;
$temp = sys_get_temp_dir() . '/yg-c1-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$target = ($argv[1] ?? '') === '--mysql' ? '--mysql' : $temp . '/scene.sqlite';
$project = 'c1-' . bin2hex(random_bytes(6));
$foreign = $project . '-foreign';
$owner = new Identity('owner');
$editor = new Identity('editor');
$outsider = new Identity('account-with-global-owner-role', ['owner']);
$db = sceneDb($target);
$allow = true;
$assets = static function (string $p, string $v, Identity $actor) use ($project, &$allow): bool {
    return $allow && $p === $project && $v === 'ready-model' && in_array($actor->id, ['owner', 'editor'], true);
};
$service = new SceneService($db, $assets);
$auth = new class implements IdentityProvider {
    public ?Identity $user = null;
    public function identity(Request $request): ?Identity { return $this->user; }
};
try {
    $registry = Registry::boot([new SceneModule($service, $auth)]);
    $migrator = new Migrator($db, $temp, $registry->migrationDirectories());
    $migrator->migrate();
    sceneCheck($migrator->migrate() === [], 'second migration changes nothing');
    $csrf = str_repeat('t', 40);
    $kernel = new Kernel($registry, new RequestAuthorizer($auth, new Gate($registry->policies()), new CsrfGuard(fn () => $csrf)));
    $headers = ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf];
    sceneCheck($kernel->handle(new Request('/api/projects', 'GET'))->status === 401, 'guest cannot discover projects');
    $auth->user = $owner;
    sceneCheck($kernel->handle(new Request('/api/projects', 'POST', [], '{}'))->status === 403, 'unsafe route requires CSRF');
    sceneCheck($kernel->handle(new Request('/api/projects', 'POST', $headers, '{"name":"HTTP project"}'))->status === 201, 'HTTP project creation');
    $service->createProject($owner, 'House', $project);
    $service->createProject($outsider, 'Another project', $foreign);
    $service->createScene($outsider, $foreign, 'Foreign scene', 'other');
    sceneCheck(!$service->mayReadProject($outsider, $project) && !$service->mayReadProject(null, $project), 'project membership is current and scoped');
    sceneCheck(!in_array($project, array_column($service->projects($outsider), 'projectId'), true), 'listing excludes other projects');
    $scene = $service->createScene($owner, $project, 'Garden', 'main');
    sceneCheck($scene['revision'] === 0 && $scene['schemaVersion'] === 1 && $scene['entities'] === [], 'new empty document');
    sceneCheck($service->scenes($owner, $project)[0]['sceneId'] === 'main', 'project scene list');
    sceneRejects(403, fn () => $service->document($outsider, $project, 'main'));
    sceneRejects(404, fn () => $service->document($owner, $project, 'other'));
    sceneRejects(422, fn () => $service->setMember($owner, $project, 'owner', null));
    $service->setMember($owner, $project, 'editor', 'editor');
    $service->setMember($owner, $project, 'viewer', 'viewer');
    sceneCheck($service->mayReadProject(new Identity('viewer'), $project) && !$service->mayEditProject(new Identity('viewer'), $project), 'viewer read-only');
    sceneRejects(403, fn () => $service->setMember($editor, $project, 'viewer', 'editor'));
    $dispatch = fn ($command, $who = null) => $service->dispatch($who ?? $editor, $project, 'main', $command);
    $add = sceneCommand('first', 'entity.add', 'garage', 0, ['type' => 'garage', 'name' => 'Garage', 'assetVersionId' => 'ready-model',
        'properties' => ['heightMm' => 2130], 'transform' => ['rotation' => [0, 2, 0, 2]]]);
    $ack = $dispatch($add);
    $entity = $ack['changes']['upsert'][0];
    sceneCheck($ack['revision'] === 1 && $entity['properties']->heightMm === 2130 && $entity['properties']->heightMm / 1000 === 2.13, 'integer mm survives write and converts to metres');
    sceneCheck(abs($entity['transform']['rotation'][1] - sqrt(0.5)) < 1e-9 && $entity['transform']['scale'] === [1,1,1], 'unit quaternion and default scale');
    $reordered = $add;
    $reordered['payload'] = array_reverse($add['payload'], true);
    sceneCheck(json_encode($ack) === json_encode($dispatch($reordered)), 'canonical hash accepts reordered JSON keys');
    $changed = $add;
    $changed['payload']['name'] = 'Changed';
    sceneCheck(sceneRejects(409, fn () => $dispatch($changed))->errors['code'] === 'command_id_conflict', 'changed duplicate rejected');
    $service->setMember($owner, $project, 'editor', 'viewer');
    sceneRejects(403, fn () => $dispatch($add));
    $service->setMember($owner, $project, 'editor', null);
    sceneRejects(403, fn () => $dispatch($add));
    sceneRejects(403, fn () => $service->document($editor, $project, 'main'));
    $service->setMember($owner, $project, 'editor', 'editor');
    $stale = sceneRejects(409, fn () => $dispatch(sceneCommand('different', 'entity.transform', 'garage', 0, ['position' => [1, 0, 0]])));
    sceneCheck($stale->errors['code'] === 'revision_conflict' && $stale->errors['document']['entities'][0]['name'] === 'Garage', 'conflict includes latest document');
    foreach ([
        ['entity.transform', ['rotation' => [0,0,0,0]]],
        ['entity.transform', ['scale' => [1,0,1]]],
        ['entity.transform', ['position' => [INF,0,0]]],
        ['entity.transform', ['position' => ['0',0,0]]],
        ['entity.transform', ['position' => [0, 0, 1000001]]],
        ['entity.update', ['visible' => 0]],
        ['entity.update', ['parentId' => 'unknown-foreign-id']],
        ['entity.update', ['parentId' => 'garage']],
        ['entity.update', ['assetVersionId' => 'other-project-version']],
        ['entity.update', ['assetVersionId' => 'pending-version']],
        ['entity.update', ['properties' => ['heightMm' => 213.5]]],
        ['entity.update', ['properties' => ['x' => ['large' => 'nested']]]],
        ['entity.update', ['type' => 'other']],
        ['entity.unsupported', []],
    ] as [$type, $payload]) sceneRejects(422, fn () => $dispatch(sceneCommand('invalid', $type, 'garage', 1, $payload)));
    $addChild = sceneCommand('child', 'entity.add', 'child', 1, ['type' => 'model', 'parentId' => 'garage']);
    $dispatch($addChild);
    sceneRejects(422, fn () => $dispatch(sceneCommand('cycle', 'entity.update', 'garage', 2, ['parentId' => 'child'])));
    sceneRejects(422, fn () => $dispatch(sceneCommand('orphan', 'entity.remove', 'garage', 2)));
    $allow = false;
    sceneRejects(422, fn () => $dispatch(sceneCommand('clone', 'entity.duplicate', 'copy', 2, ['sourceId' => 'garage'])));
    sceneRejects(422, fn () => $service->exportDocument($owner, $project, 'main'));
    $allow = true;
    $dup = $dispatch(sceneCommand('clone', 'entity.duplicate', 'copy', 2, ['sourceId' => 'garage', 'transform' => ['position' => [1,0,1]]]));
    sceneCheck($dup['changes']['upsert'][0]['assetVersionId'] === 'ready-model' && $dup['changes']['upsert'][0]['entityId'] === 'copy', 'duplicate shares version with distinct entity ID');
    $undo = $dispatch(['commandId' => 'undo-clone', 'expectedRevision' => 3] + $dup['inverse']);
    sceneCheck($undo['changes']['remove'] === ['copy'], 'undo duplicate applies new remove command');
    $redo = $dispatch(['commandId' => 'redo-clone', 'expectedRevision' => 4] + $undo['inverse']);
    sceneCheck($redo['revision'] === 5 && $redo['changes']['upsert'][0]['transform']['position'] === [1,0,1], 'redo restores exact entity');
    $move = $dispatch(sceneCommand('first', 'entity.transform', 'garage', 5, ['position' => [2,0,0]]), $owner);
    sceneCheck($move['inverse']['payload'] === ['position' => [0,0,0]], 'actor-scoped command ID and field-scoped inverse');
    $scene = $service->exportDocument($owner, $project, 'main');
    $import = $scene;
    $import['entities'] = array_reverse($import['entities']);
    $copy = $service->importScene($owner, $project, $import);
    sceneCheck($copy['sceneId'] !== $scene['sceneId'] && $copy['revision'] === 0 && $copy['entities'][1]['parentId'] === 'garage', 'import remaps scene and accepts a child before its parent');
    sceneRejects(422, fn () => $service->importScene($outsider, $foreign, $scene));
    $tooLarge = $import;
    foreach ($tooLarge['entities'] as &$entry) $entry['transform']['scale'] = [10000,10000,10000];
    unset($entry);
    sceneRejects(422, fn () => $service->importScene($owner, $project, $tooLarge));
    $broken = $import;
    $broken['coordinateSystem']['up'] = true;
    sceneRejects(422, fn () => $service->importScene($owner, $project, $broken));
    $broken = $import;
    $broken['entities'][] = $broken['entities'][0];
    sceneRejects(422, fn () => $service->importScene($owner, $project, $broken));
    $broken = $import;
    $broken['entities'][0]['parentId'] = $broken['entities'][0]['entityId'];
    sceneRejects(422, fn () => $service->importScene($owner, $project, $broken));
    sceneCheck(count($service->scenes($owner, $project)) === 2, 'failed imports leave no partial scene');

    $path = '/api/projects/' . $project . '/scenes/main';
    sceneCheck($kernel->handle(new Request($path . '/export', 'GET'))->headers['Content-Disposition'] === 'attachment; filename="scene.json"', 'export served as scoped download');
    $bad = sceneCommand('bad', 'entity.remove', 'copy', 6);
    $response = $kernel->handle(new Request($path . '/commands', 'POST', $headers, json_encode($bad)));
    sceneCheck($response->status === 422, 'HTTP payload must be a JSON object, including empty payload');
    $response = $kernel->handle(new Request($path . '/commands', 'POST', $headers, json_encode($add)));
    sceneCheck($response->status === 409 && json_decode($response->body, true)['errors']['code'] === 'command_id_conflict', 'HTTP returns typed JSON error');
    $json = $kernel->handle(new Request($path, 'GET'))->body;
    sceneCheck(json_decode($json)->entities[1]->properties instanceof stdClass, 'empty property maps survive round trip');
    $response = $kernel->handle(new Request('/api/projects/' . $project . '/scenes/import', 'POST', $headers, '{"document":' . $json . '}'));
    sceneCheck($response->status === 201, 'HTTP snapshot export/import round trip');

    $counts = function (string $table) use ($db, $project): int {
        $q = $db->prepare("SELECT COUNT(*) FROM $table WHERE project_id=? AND scene_id='main'");
        $q->execute([$project]);
        return (int) $q->fetchColumn();
    };
    $db->prepare("INSERT INTO yg_scene_outbox(project_id,scene_id,revision,event_json) VALUES (?,'main',7,'{}')")->execute([$project]);
    try {
        $dispatch(sceneCommand('retry-on-failure', 'entity.update', 'garage', 6, ['name' => 'Restored']));
        throw new RuntimeException('Expected outbox constraint failure.');
    } catch (PDOException $e) { sceneCheck($e->getCode() === '23000', 'outbox unique constraint fired'); }
    sceneCheck($service->document($owner, $project, 'main')['revision'] === 6 && $counts('yg_scene_receipts') === 6, 'failed outbox insert rolls back CAS and receipt');
    $db->prepare("DELETE FROM yg_scene_outbox WHERE project_id=? AND scene_id='main' AND revision=7")->execute([$project]);
    $retry = $dispatch(sceneCommand('retry-on-failure', 'entity.update', 'garage', 6, ['name' => 'Restored']));
    sceneCheck($retry['revision'] === 7, 'retry after rollback commits once');

    $same = sceneRace($target, $temp, $project, 7, ['concurrent', 'concurrent']);
    sceneCheck($same[0]['status'] === 200 && $same[0] === $same[1], 'concurrent identical commands return identical committed result');
    $different = sceneRace($target, $temp, $project, 8, ['from-tab-a', 'from-tab-b']);
    $statuses = array_column($different, 'status');
    sort($statuses);
    sceneCheck($statuses === [200,409], 'distinct commands from one revision yield one winner');
    foreach ($different as $response) if ($response['status'] === 409) sceneCheck($response['error']['document']['revision'] === 9, 'loser reads committed winner');
    sceneCheck($counts('yg_scene_receipts') === 9 && $counts('yg_scene_outbox') === 9, 'one receipt and event per revision');
    $reopened = new SceneService(sceneDb($target), $assets);
    sceneCheck($reopened->document($owner, $project, 'main')['revision'] === 9, 'fresh connection reads committed snapshot');
    sceneCheck(json_encode($reopened->dispatch($editor, $project, 'main', $add)) === json_encode($ack), 'receipt survives process restart');

    $seen = [];
    $collect = function (array $event) use (&$seen, $project): void {
        if ($event['projectId'] === $project) $seen[] = $event;
    };
    if ($target !== '--mysql') {
        $attempt = null;
        try {
            $reopened->publishOutbox(function ($event) use (&$attempt) { $attempt = $event; throw new RuntimeException('broker unavailable'); }, 1);
        } catch (RuntimeException $e) { sceneCheck($e->getMessage() === 'broker unavailable', 'publisher failure propagated'); }
        $reopened->publishOutbox($collect);
        sceneCheck($reopened->publishOutbox($collect) === 0 && count($seen) === 9 && $seen[0]['eventId'] === $attempt['eventId'], 'publisher retries stable event ID and marks successful deliveries');
        sceneCheck(array_column($seen, 'revision') === range(1, 9), 'events preserve scene revision order');
    }
    echo 'PASS: ' . $count . ' C1 ' . ($target === '--mysql' ? 'MySQL' : 'SQLite') . " assertions\n";
} finally {
    foreach (glob($temp . '/*') as $file) unlink($file);
    rmdir($temp);
}
