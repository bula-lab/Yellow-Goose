<?php
require __DIR__ . '/../vendor/autoload.php';

use ZoltaGaska\Database\{Connection, Migrator};
use ZoltaGaska\Http\{HttpException, Kernel, Request};
use ZoltaGaska\Jobs\{JobsModule, Queue, StaleLease};
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Security\{CsrfGuard, Gate, Identity, IdentityProvider, RequestAuthorizer};
use ZoltaGaska\Strategy\{Rules, StrategyModule, StrategyService};

final class TestStrategyRules implements Rules
{
    public int $multiplier = 1;
    public function version(): string { return 'v' . $this->multiplier; }
    public function economy(): array { return ['initial' => 100,'capacity' => 200,'perSecond' => 2]; }
    public function targets(): array { return ['mine','mill','bank']; }
    public function build(string $target, int $level): ?array
    {
        if ($level > 4) return null;
        return ['cost' => ($target === 'bank' ? 201 : 30) * $level * $this->multiplier, 'duration' => 5, 'points' => 10 * $this->multiplier,
            'rewardResources' => 22, 'rewardPoints' => 3 * $this->multiplier];
    }
}

function dbForStrategy(string $target): PDO
{
    if ($target !== '--mysql') {
        $db = new PDO('sqlite:' . $target);
        $db->exec('PRAGMA foreign_keys=ON');
        $db->exec('PRAGMA busy_timeout=10000');
        return $db;
    }
    $name = getenv('MYSQL_TEST_DATABASE') ?: '';
    if (getenv('YG_INTEGRATION_MYSQL') !== '1' || !preg_match('/^[a-zA-Z0-9_]+_test$/D', $name)) {
        throw new RuntimeException('Select a disposable *_test database and YG_INTEGRATION_MYSQL=1.');
    }
    return new PDO(Connection::dsn(['driver' => 'mysql','host' => '127.0.0.1','port' => getenv('MYSQL_TEST_PORT') ?: 3306,
        'database' => $name,'charset' => 'utf8mb4']), getenv('MYSQL_TEST_USERNAME') ?: 'root', getenv('MYSQL_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}

function assertStrategy(bool $ok, string $what): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $what);
    ++$checks;
}

function rejectsStrategy(callable $run, int $status, ?string $code = null): void
{
    try {
        $run();
    } catch (HttpException $e) {
        assertStrategy($e->status === $status && ($code === null || ($e->errors['code'] ?? null) === $code),
            "Expected $status $code, got {$e->status} {$e->getMessage()}");
        return;
    }
    throw new RuntimeException("Expected rejection $status $code.");
}

function waitStrategy(callable $ready): void
{
    $limit = microtime(true) + 15;
    while (!$ready()) {
        if (microtime(true) >= $limit) throw new RuntimeException('Concurrent writer timed out.');
        usleep(10000);
        clearstatcache();
    }
}

function inputStrategy(string $id, string $type, int $expectedRevision, string $value, string $version = 'v1'): array
{
    return ['commandId' => $id, 'type' => $type, 'expectedRevision' => $expectedRevision, 'expectedRulesVersion' => $version,
        'payload' => [($type === 'build.start' ? 'target' : 'rewardId') => $value]];
}

if (($argv[1] ?? '') === '--writer') {
    [, , $target, $prefix, $slot, $game, $actorId, $at, $command] = $argv;
    $service = new StrategyService(new Queue(dbForStrategy($target)), new TestStrategyRules(), static fn () => (int) $at);
    touch($prefix . $slot);
    waitStrategy(fn () => is_file($prefix . 'go'));
    try {
        $ack = $service->dispatch(new Identity($actorId), $game, json_decode($command, true, 8, JSON_THROW_ON_ERROR));
        echo json_encode($ack, JSON_THROW_ON_ERROR);
    } catch (HttpException $e) {
        echo json_encode(['status' => $e->status,'code' => $e->errors['code'] ?? null], JSON_THROW_ON_ERROR);
    }
    return;
}

function raceStrategy(string $target, string $tmp, string $game, string $actorId, int $at, array $commands): array
{
    $prefix = $tmp . '/barrier-' . bin2hex(random_bytes(4)) . '-';
    $workers = [];
    try {
        foreach ($commands as $slot => $cmd) {
            $process = proc_open([PHP_BINARY, __FILE__, '--writer', $target, $prefix, (string) $slot, $game, $actorId, (string) $at,
                json_encode($cmd, JSON_THROW_ON_ERROR)], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start writer.');
            $workers[] = [$process,$pipes];
        }
        waitStrategy(fn () => is_file($prefix . '0') && is_file($prefix . '1'));
        touch($prefix . 'go');
        $result = [];
        foreach ($workers as [$proc,$pipes]) {
            $result[] = json_decode(stream_get_contents($pipes[1]), true, 8);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($proc) !== 0) throw new RuntimeException($err);
        }
        return $result;
    } finally {
        touch($prefix . 'go');
        foreach ($workers as [$proc]) if (is_resource($proc)) proc_close($proc);
    }
}

$checks = 0;
$tmp = sys_get_temp_dir() . '/yg-d1-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$target = ($argv[1] ?? '') === '--mysql' ? '--mysql' : $tmp . '/strategy.sqlite';
$db = dbForStrategy($target);
$prefix = 'd1-' . bin2hex(random_bytes(6));
$season = $prefix . '-a';
$nextSeason = $prefix . '-b';
$at = random_int(900000000, 1300000000);
$now = $at;
$queue = new Queue($db);
$rules = new TestStrategyRules();
$service = new StrategyService($queue, $rules, static function () use (&$now) { return $now; }, 4);
$actor = new Identity('alice');
$bob = new Identity('bob');
$carol = new Identity('carol');
$guest = new Identity('outsider', ['admin']);
$auth = new class implements IdentityProvider {
    public ?Identity $user = null;
    public function identity(Request $request): ?Identity { return $this->user; }
};
$trigger = 'fail_' . bin2hex(random_bytes(8));
try {
    $registry = Registry::boot([new StrategyModule($service, $auth),new JobsModule($queue, $service->jobHandlers())]);
    $migrator = new Migrator($db, $tmp, $registry->migrationDirectories());
    $migrator->migrate();
    assertStrategy($migrator->migrate() === [], 'migration repeat');
    $scheduledKey = 'strategy-test:' . $prefix;
    $queue->enqueue($scheduledKey, 'strategy.test', [], time() + 300);
    $queue->enqueue($scheduledKey, 'strategy.test', []);
    assertStrategy($queue->claim() === null, 'future job remains ineligible after producer retry');
    $db->prepare('DELETE FROM yg_jobs WHERE job_key=?')->execute([$scheduledKey]);
    $service->createSeason($season, $at, $at + 30);
    $service->createSeason($season, $at, $at + 30);
    $service->createSeason($nextSeason, $at + 30, $at + 90);
    try {
        $service->createSeason($prefix . '-overlap', $at + 29, $at + 31);
        throw new RuntimeException('Overlapping seasons accepted.');
    } catch (LogicException $e) { assertStrategy(str_contains($e->getMessage(), 'overlap'), 'single active season'); }
    try {
        $service->createSeason($season, $at, $at + 31);
        throw new RuntimeException('Changed bounds accepted.');
    } catch (LogicException $e) { assertStrategy(str_contains($e->getMessage(), 'immutable'), 'immutable season'); }

    $csrf = str_repeat('c', 40);
    $kernel = new Kernel($registry, new RequestAuthorizer($auth, new Gate($registry->policies()), new CsrfGuard(fn () => $csrf)));
    $headers = ['Content-Type' => 'application/json','X-CSRF-Token' => $csrf];
    assertStrategy($kernel->handle(new Request('/api/games', 'GET'))->status === 401, 'anonymous discovery');
    $auth->user = $actor;
    $body = json_encode(['seasonId' => $season,'displayName' => 'Alice']);
    assertStrategy($kernel->handle(new Request('/api/games', 'POST', ['Content-Type' => 'application/json'], $body))->status === 403, 'CSRF join');
    assertStrategy($kernel->handle(new Request('/api/games', 'POST', $headers, $body))->status === 201, 'join through HTTP');
    $initial = $service->join($actor, $season, 'Ignored on retry');
    $game = $initial['gameId'];
    $other = $service->join($bob, $season, 'Bob')['gameId'];
    $service->join($carol, $season, 'Carol');
    assertStrategy($service->seasons()[0]['seasonId'] === $season && $initial['displayName'] === 'Alice', 'season list and duplicate join');
    assertStrategy($initial['resources'] === 100 && $initial['revision'] === 0 && $initial['operations'] === [], 'initial snapshot');
    assertStrategy($service->games($actor) === [['gameId' => $game,'seasonId' => $season,'displayName' => 'Alice']], 'owner list');
    rejectsStrategy(fn () => $service->join($actor, $nextSeason, 'Early'), 409, 'season_closed');
    rejectsStrategy(fn () => $service->snapshot($guest, $game), 403);
    rejectsStrategy(fn () => $service->events($guest, $game, 0), 403);
    rejectsStrategy(fn () => $service->leaderboard($guest, $season), 403);

    $build = inputStrategy('same', 'build.start', 0, 'mine');
    $missingVersion = $build;
    unset($missingVersion['expectedRulesVersion']);
    rejectsStrategy(fn () => $service->dispatch($actor, $game, $missingVersion), 422);
    foreach (['cost','points','startedAt','completesAt','serverNow'] as $forged) {
        $bad = $build;
        $bad['payload'][$forged] = 1;
        rejectsStrategy(fn () => $service->dispatch($actor, $game, $bad), 422);
    }
    rejectsStrategy(fn () => $service->dispatch($actor, $game, $build + ['points' => 9999]), 422);
    rejectsStrategy(fn () => $service->dispatch($actor, $game, inputStrategy('bank', 'build.start', 0, 'bank')), 409, 'insufficient_resources');

    $same = raceStrategy($target, $tmp, $game, $actor->id, $now, [$build,$build]);
    assertStrategy($same[0] === $same[1] && $same[0]['revision'] === 1, 'parallel replay returned same receipt');
    $snapshot = $service->snapshot($actor, $game);
    $op = $snapshot['operations'][0];
    assertStrategy($snapshot['resources'] === 70 && count($snapshot['operations']) === 1 && $snapshot['points'] === 0, 'one cost, no points on start');
    assertStrategy($op['startedAt'] === $at && $op['completesAt'] === $at + 5 && $op['cost'] === 30 && $op['rulesVersion'] === 'v1', 'immutable quote');
    $key = 'strategy:' . $op['operationId'];
    $dueAt = $db->prepare('SELECT available_at FROM yg_jobs WHERE job_key=?');
    $dueAt->execute([$key]);
    assertStrategy((int) $dueAt->fetchColumn() === $at + 5, 'job scheduled for deadline');
    $dueAt->closeCursor();
    $reordered = array_reverse($build, true);
    assertStrategy($service->dispatch($actor, $game, $reordered) === $same[0], 'key order survives retry');
    rejectsStrategy(fn () => $service->dispatch($actor, $game, inputStrategy('same','build.start',1,'mine')), 409, 'command_id_conflict');
    rejectsStrategy(fn () => $service->dispatch($actor, $game, inputStrategy('same','build.start',0,'mill')), 409, 'command_id_conflict');
    rejectsStrategy(fn () => $service->dispatch($bob, $game, $build), 403);
    rejectsStrategy(fn () => $service->dispatch($actor, $game, inputStrategy('again','build.start',1,'mine')), 409, 'build_in_progress');
    rejectsStrategy(fn () => $service->dispatch($actor, $game, inputStrategy('early','reward.claim',1,$op['operationId'])), 409, 'reward_unavailable');
    $now += 2;
    $snapshot = $service->snapshot($actor, $game);
    assertStrategy($snapshot['resources'] === 74 && $snapshot['revision'] === 1, 'lazy integer regeneration');
    assertStrategy($service->snapshot($actor, $game)['resources'] === 74, 'read does not credit another tick');
    $now = $at;
    $attempts = [inputStrategy('first','build.start',0,'mine'),inputStrategy('second','build.start',0,'mine')];
    $race = raceStrategy($target, $tmp, $other, $bob->id, $now, $attempts);
    assertStrategy(array_column($race,'code') === ['revision_conflict'] && array_column($race,'revision') === [1], 'distinct parallel build succeeds once');
    assertStrategy($service->snapshot($bob, $other)['resources'] === 70, 'single debit in separate-command race');
    $service->dispatch($actor, $game, inputStrategy('parallel-target','build.start',1,'mill'));
    $rules->multiplier = 9;
    $now = $at + 5;
    rejectsStrategy(fn () => $service->dispatch($actor, $game, inputStrategy('late','build.start',2,'mill','v9')), 409, 'revision_conflict');
    $reconciled = $db->prepare('SELECT points FROM yg_strategy_games WHERE game_id=?');
    $reconciled->execute([$game]);
    assertStrategy((int) $reconciled->fetchColumn() === 20, 'rejected command still committed due outcomes using original rules');
    $reconciled->closeCursor();
    $page = $service->leaderboard($actor, $season, null, 1);
    assertStrategy($page['items'][0] === ['actorId' => 'alice','displayName' => 'Alice','rank' => 1,'points' => 20], 'outage ranking is current');
    $second = $service->leaderboard($actor, $season, $page['nextCursor'], 1);
    assertStrategy($second['items'][0]['points'] === 10 && $second['items'][0]['actorId'] === 'bob', 'rank reconciles unvisited opponent');
    $events = $service->events($actor, $game, 2, 1);
    $more = $service->events($actor, $game, $events['cursor'], 1);
    assertStrategy($events['hasMore'] && !$more['hasMore'] && $events['items'][0]['at'] === $at + 5
        && $events['items'][0]['payload']['operationId'] < $more['items'][0]['payload']['operationId'], 'stable completion order and paginated catch-up');
    $snapshot = $service->snapshot($actor, $game);
    assertStrategy($snapshot['revision'] === 4 && $snapshot['resources'] === 50 && $snapshot['points'] === 20
        && $snapshot['operations'] === [] && count($snapshot['rewards']) === 2, 'authoritative reconnect after idle worker');
    $rules->multiplier = 1;
    $claim = inputStrategy('claim', 'reward.claim', 4, $op['operationId']);
    $claim2 = inputStrategy('other-claim', 'reward.claim', 4, $op['operationId']);
    $race = raceStrategy($target, $tmp, $game, $actor->id, $now, [$claim,$claim2]);
    assertStrategy(array_column($race,'code') === ['revision_conflict'] && array_column($race,'revision') === [5], 'parallel rewards committed once');
    $winner = isset($race[0]['revision']) ? $claim : $claim2;
    assertStrategy($service->dispatch($actor, $game, $winner)['revision'] === 5, 'reward replay');
    rejectsStrategy(fn () => $service->dispatch($actor, $game, inputStrategy('already','reward.claim',5,$op['operationId'])), 409, 'reward_unavailable');
    assertStrategy($service->snapshot($actor, $game)['resources'] === 72 && $service->snapshot($actor, $game)['points'] === 23, 'fixed reward resources and score');
    $reset = $service->leaderboard($actor, $season, $page['nextCursor'], 1);
    assertStrategy($reset['reset'] && $reset['items'][0]['points'] === 23, 'changed standings restart page');
    $lastReward = $service->snapshot($actor, $game)['rewards'][0]['rewardId'];
    $service->dispatch($actor, $game, inputStrategy('last-claim','reward.claim',5,$lastReward));
    assertStrategy($service->events($actor, $game, 0)['reset'] && $service->events($actor, $game, 2)['items'][0]['cursor'] === 3, 'retention floor resets stale cursor');
    assertStrategy($service->events($actor, $game, 999)['reset'], 'future cursor reset');
    rejectsStrategy(fn () => $service->leaderboard($actor, $season, 'bad cursor'), 422);
    rejectsStrategy(fn () => $service->leaderboard($actor, $season, null, 101), 422);
    $auth->user = $guest;
    assertStrategy($kernel->handle(new Request("/api/games/$game/events?cursor=4", 'GET'))->status === 403, 'resume checks current identity');
    $auth->user = null;
    assertStrategy($kernel->handle(new Request("/api/games/$game/commands", 'POST', $headers, json_encode($winner)))->status === 401, 'replay checks current login');
    $auth->user = $actor;
    assertStrategy($kernel->handle(new Request("/api/games/$game/events?cursor[]=1", 'GET'))->status === 422, 'reject array cursor');

    // Reject the final receipt insert after the balance, operation, queue entry and event have been written.
    $db->exec($target === '--mysql'
        ? "CREATE TRIGGER $trigger BEFORE INSERT ON yg_strategy_receipts FOR EACH ROW BEGIN IF NEW.command_id='fault' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced fault'; END IF; END"
        : "CREATE TRIGGER $trigger BEFORE INSERT ON yg_strategy_receipts WHEN NEW.command_id='fault' BEGIN SELECT RAISE(ABORT,'forced fault'); END");
    $before = $service->snapshot($actor, $game);
    $fault = inputStrategy('fault','build.start',6,'mine');
    try {
        $service->dispatch($actor, $game, $fault);
        throw new RuntimeException('Injected fault ignored.');
    } catch (PDOException $e) { assertStrategy(str_contains($e->getMessage(), 'forced fault'), 'injected failure reached receipt'); }
    $db->exec("DROP TRIGGER $trigger");
    assertStrategy(json_encode($before) === json_encode($service->snapshot($actor, $game)), 'rollback restored balance, revision, and operations');
    assertStrategy($service->events($actor, $game, 6)['items'] === [], 'rollback removed outbox event');
    $jobCount = $db->prepare("SELECT COUNT(*) FROM yg_jobs WHERE job_type='strategy.complete' AND payload LIKE ?");
    $jobCount->execute(['%' . $game . '%']);
    assertStrategy((int) $jobCount->fetchColumn() === 2, 'rollback removed new job');
    $jobCount->closeCursor();
    assertStrategy($service->dispatch($actor, $game, $fault)['revision'] === 7, 'same request succeeds after rollback');

    // Move this job ahead of all others to test the real claim, recovery, and fenced effect without waiting.
    $running = $service->snapshot($actor, $game)['operations'][0];
    $jobKey = 'strategy:' . $running['operationId'];
    $db->prepare('UPDATE yg_jobs SET available_at=0 WHERE job_key=?')->execute([$jobKey]);
    $lease = $queue->claim();
    assertStrategy($lease !== null && $lease->key === $jobKey, 'worker claimed intended job');
    $db->prepare('UPDATE yg_jobs SET lease_until=0 WHERE job_key=?')->execute([$jobKey]);
    $replacement = $queue->claim();
    assertStrategy($replacement !== null && $replacement->key === $jobKey, 'lease reclaimed');
    $now = $running['completesAt'];
    $handler = $service->jobHandlers()['strategy.complete'];
    try {
        $handler($lease->payload, $lease);
        throw new RuntimeException('Stale lease settled.');
    } catch (StaleLease) { assertStrategy(true, 'stale worker fenced out'); }
    $handler($replacement->payload, $replacement);
    $after = $service->snapshot($actor, $game);
    assertStrategy($after['revision'] === 8 && $after['points'] === 36 && $after['levels']->mine === 2, 'current worker completed operation exactly once');
    assertStrategy($queue->work($service->jobHandlers(), 10) === 3, 'delayed jobs acknowledged after reads completed their operations');
    assertStrategy($service->snapshot($actor, $game)['revision'] === 8 && $service->snapshot($bob, $other)['revision'] === 2,
        'late worker produced no additional event or score');
    $now = $at + 28;
    rejectsStrategy(fn () => $service->dispatch($actor, $game, inputStrategy('too-late','build.start',8,'mine')), 409, 'insufficient_time');
    $now = $at + 30;
    rejectsStrategy(fn () => $service->dispatch($actor, $game, inputStrategy('closed','reward.claim',8,$running['operationId'])), 409, 'season_closed');
    $frozen = $service->snapshot($actor, $game)['resources'];
    $now += 50;
    assertStrategy($service->snapshot($actor, $game)['resources'] === $frozen, 'season close freezes production');
    assertStrategy($service->seasons()[0]['seasonId'] === $nextSeason, 'adjacent season activated');
    $now = $at + 30;
    $next = $service->join($actor, $nextSeason, 'Alice');
    $nextBob = $service->join($bob, $nextSeason, 'Bob');
    $nextCarol = $service->join($carol, $nextSeason, 'Carol');
    assertStrategy($next['points'] === 0 && $next['revision'] === 0 && $next['gameId'] !== $game, 'new season starts independently');
    $service->dispatch($actor, $next['gameId'], $build);
    $service->dispatch($bob, $nextBob['gameId'], $build);
    $now += 55;
    $standings = $service->leaderboard($actor, $nextSeason, null, 2);
    $last = $service->leaderboard($actor, $nextSeason, $standings['nextCursor'], 2);
    assertStrategy(array_column($standings['items'],'rank') === [1,1] && array_column($standings['items'],'actorId') === ['alice','bob']
        && $last['items'][0]['rank'] === 3 && $last['nextCursor'] === null, 'competition tie and deterministic keyset across boundary');
    $tiePage = $service->leaderboard($actor, $nextSeason, null, 1);
    $tieNext = $service->leaderboard($actor, $nextSeason, $tiePage['nextCursor'], 1);
    assertStrategy($tieNext['items'][0]['rank'] === 1 && $tieNext['items'][0]['actorId'] === 'bob', 'shared rank across a page boundary');
    assertStrategy($service->snapshot($actor, $next['gameId'])['resources'] === 180, 'lazy regeneration across long idle period');
    $carolGame = $nextCarol['gameId'];
    $displayed = $service->snapshot($carol, $carolGame);
    $outdated = inputStrategy('displayed','build.start',$displayed['revision'],'mine',$displayed['rulesVersion']);
    $rules->multiplier = 2;
    rejectsStrategy(fn () => $service->dispatch($carol, $carolGame, $outdated), 409, 'rules_conflict');
    $refreshed = $service->snapshot($carol, $carolGame);
    assertStrategy($refreshed['revision'] === $displayed['revision'] && $refreshed['resources'] === $displayed['resources']
        && $refreshed['operations'] === [] && $refreshed['rulesVersion'] === 'v2' && $displayed['rulesVersion'] === 'v1'
        && $refreshed['quotes'][0]['cost'] === 60 && $displayed['quotes'][0]['cost'] === 30, 'outdated displayed price caused no debit');
    $confirmed = inputStrategy('confirmed','build.start',$refreshed['revision'],'mine',$refreshed['rulesVersion']);
    $service->dispatch($carol, $carolGame, $confirmed);
    $paid = $service->snapshot($carol, $carolGame);
    assertStrategy($paid['resources'] === $displayed['resources'] - 60 && $paid['operations'][0]['rulesVersion'] === 'v2',
        'new confirmation paid the revised price once');
    $originalAck = $service->dispatch($actor, $next['gameId'], $build);
    assertStrategy($originalAck['revision'] === 1, 'successful v1 envelope replays after v2 deployment');
    rejectsStrategy(fn () => $service->dispatch($actor, $next['gameId'], inputStrategy('same','build.start',0,'mine','v2')), 409, 'command_id_conflict');
    $bobReward = $service->snapshot($bob, $nextBob['gameId'])['rewards'][0]['rewardId'];
    rejectsStrategy(fn () => $service->dispatch($bob, $nextBob['gameId'], inputStrategy('stale-payout','reward.claim',2,$bobReward)), 409, 'rules_conflict');
    $rules->multiplier = 1;
    $service->dispatch($bob, $nextBob['gameId'], inputStrategy('cap','reward.claim',2,$bobReward));
    $credit = $service->events($bob, $nextBob['gameId'], 2)['items'][0]['payload'];
    assertStrategy($credit['resources'] === 20 && $credit['points'] === 3, 'reward credits only remaining resource capacity');
    $service->dispatch($actor, $next['gameId'], inputStrategy('boundary','build.start',2,'mill'));
    $now += 5;
    $otherState = $service->snapshot($bob, $nextBob['gameId']);
    assertStrategy($otherState['resources'] === 200 && $otherState['points'] === 13, 'capped resource balance at season closure');
    $closed = $service->snapshot($actor, $next['gameId']);
    assertStrategy($closed['points'] === 20 && $closed['levels']->mill === 1 && $closed['rewards'] === [], 'build finishes at season end and closed rewards disappear');
    echo "D1 strategy: $checks checks passed (" . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . ").\n";
} finally {
    $db->exec("DROP TRIGGER IF EXISTS $trigger");
    if ($target === '--mysql') {
        $db->prepare('DELETE FROM yg_jobs WHERE job_type=? AND payload LIKE ?')->execute(['strategy.complete','%' . $prefix . '%']);
        foreach (['events','receipts','operations'] as $table) {
            $db->prepare("DELETE FROM yg_strategy_$table WHERE game_id IN (SELECT game_id FROM yg_strategy_games WHERE season_id IN (?,?))")->execute([$season,$nextSeason]);
        }
        $db->prepare('DELETE FROM yg_strategy_games WHERE season_id IN (?,?)')->execute([$season,$nextSeason]);
        $db->prepare('DELETE FROM yg_strategy_seasons WHERE season_id IN (?,?)')->execute([$season,$nextSeason]);
    }
    unset($db);
    foreach (glob($tmp . '/*') ?: [] as $file) unlink($file);
    rmdir($tmp);
}
