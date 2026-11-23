<?php
namespace ZoltaGaska\Strategy;

use PDO;
use PDOStatement;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Jobs\{Lease, Queue};
use ZoltaGaska\Security\Identity;

final class StrategyService
{
    private PDO $db;
    private \Closure $clock;

    public function __construct(private Queue $queue, private Rules $rules, ?callable $clock = null, private int $eventRetention = 256)
    {
        if ($eventRetention < 1) throw new \InvalidArgumentException('Event retention must be positive.');
        $this->clock = \Closure::fromCallable($clock ?? 'time');
        $this->db = $queue->connection();
    }

    private function sql(string $sql, array $values = []): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return $stmt;
    }

    public function createSeason(string $id, int $startsAt, int $endsAt, string $tiePolicy = 'competition'): void
    {
        Input::id($id);
        if ($startsAt < 0 || $endsAt <= $startsAt || $tiePolicy !== 'competition') {
            throw new \InvalidArgumentException('Invalid season bounds or tie policy.');
        }
        $this->db->beginTransaction();
        try {
            // The calendar row serializes inserts even when there are no seasons yet.
            $this->db->exec('UPDATE yg_strategy_calendar SET calendar_id=1 WHERE calendar_id=1');
            $season = $this->sql('SELECT * FROM yg_strategy_seasons WHERE season_id=?', [$id])->fetch(PDO::FETCH_ASSOC);
            if ($season) {
                if ((int) $season['starts_at'] !== $startsAt || (int) $season['ends_at'] !== $endsAt || $season['ties'] !== $tiePolicy) {
                    throw new \LogicException('Season definition is immutable.');
                }
            } else {
                if ($this->sql('SELECT 1 FROM yg_strategy_seasons WHERE starts_at<? AND ends_at>? LIMIT 1', [$endsAt,$startsAt])->fetchColumn()) {
                    throw new \LogicException('Season windows overlap.');
                }
                $this->sql('INSERT INTO yg_strategy_seasons(season_id,starts_at,ends_at,ties,generation) VALUES(?,?,?,?,0)',
                    [$id, $startsAt, $endsAt, $tiePolicy]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function seasons(): array
    {
        $now = ($this->clock)();
        $rows = $this->sql('SELECT * FROM yg_strategy_seasons WHERE starts_at<=? AND ends_at>? ORDER BY starts_at,season_id LIMIT 100',
            [$now, $now])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn ($s) => ['seasonId' => $s['season_id'], 'startsAt' => (int) $s['starts_at'],
            'endsAt' => (int) $s['ends_at'], 'tiePolicy' => $s['ties']], $rows);
    }

    private function season(string $id): array
    {
        return $this->sql('SELECT * FROM yg_strategy_seasons WHERE season_id=?', [$id])->fetch(PDO::FETCH_ASSOC)
            ?: throw new HttpException(404, 'Season not found.');
    }

    private function lockSeason(string $id): array
    {
        // Taking the write lock before the first SELECT also prevents stale MySQL repeatable-read snapshots.
        $this->sql('UPDATE yg_strategy_seasons SET generation=generation WHERE season_id=?', [$id]);
        return $this->season($id);
    }

    private function inSeason(string $id, callable $run): mixed
    {
        $this->db->beginTransaction();
        try {
            $season = $this->lockSeason($id);
            $result = $run($season, ($this->clock)());
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        if ($result instanceof HttpException) throw $result;
        return $result;
    }

    private function game(string $id): array
    {
        return $this->sql('SELECT * FROM yg_strategy_games WHERE game_id=?', [$id])->fetch(PDO::FETCH_ASSOC)
            ?: throw new HttpException(404, 'Game not found.');
    }

    private function owned(Identity $actor, string $id): array
    {
        $game = $this->game(Input::id($id));
        if ($game['actor_id'] !== $actor->id) throw new HttpException(403, 'Game access denied.');
        return $game;
    }

    private function withGame(Identity $actor, string $id, callable $run): mixed
    {
        $seasonId = $this->owned($actor, $id)['season_id'];
        return $this->inSeason($seasonId, function ($season, $now) use ($actor, $id, $run) {
            $this->owned($actor, $id);
            $this->settleDue($season['season_id'], $id, $now);
            return $run($this->game($id), $season, $now);
        });
    }

    public function games(Identity $actor): array
    {
        Input::text($actor->id, 128);
        $rows = $this->sql('SELECT game_id FROM yg_strategy_games WHERE actor_id=? ORDER BY season_id', [$actor->id])->fetchAll(PDO::FETCH_COLUMN);
        return array_map(fn ($id) => $this->withGame($actor, $id, static fn ($g) => ['gameId' => $g['game_id'],
            'seasonId' => $g['season_id'], 'displayName' => $g['display_name']]), $rows);
    }

    public function join(Identity $actor, string $seasonId, string $displayName): array
    {
        Input::text($actor->id, 128);
        Input::text($displayName, 120);
        return $this->inSeason(Input::id($seasonId), function ($season, $now) use ($actor, $displayName, $seasonId) {
            $id = $this->sql('SELECT game_id FROM yg_strategy_games WHERE season_id=? AND actor_id=?', [$seasonId, $actor->id])->fetchColumn();
            if (!$id) {
                if (!$this->active($season, $now)) throw $this->conflict('season_closed');
                $economy = $this->rules->economy();
                $this->amounts($economy, ['initial','capacity','perSecond']);
                if ($economy['initial'] > $economy['capacity']) throw new \LogicException('Initial balance exceeds capacity.');
                $id = bin2hex(random_bytes(16));
                $this->sql('INSERT INTO yg_strategy_games(game_id,season_id,actor_id,display_name,revision,points,resources,checkpoint,capacity,per_second)
                    VALUES(?,?,?,?,0,0,?,?,?,?)', [$id, $seasonId, $actor->id, $displayName, $economy['initial'], $now, $economy['capacity'], $economy['perSecond']]);
                $this->advanceRanking($seasonId);
            } else {
                $this->settleDue($seasonId, $id, $now);
            }
            return $this->view($this->game($id), $season, $now);
        });
    }

    private function active(array $season, int $now): bool
    {
        return $now >= (int) $season['starts_at'] && $now < (int) $season['ends_at'];
    }

    private function conflict(string $code): HttpException
    {
        return new HttpException(409, str_replace('_', ' ', ucfirst($code)) . '.', ['code' => $code]);
    }

    private function amounts(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (!is_int($data[$field] ?? null) || $data[$field] < 0 || $data[$field] > 2147483647) {
                throw new \LogicException('Invalid game rule amount: ' . $field);
            }
        }
    }

    private function resources(array $game, array $season, int $now): int
    {
        $checkpoint = (int) $game['checkpoint'];
        $elapsed = max(0, min($now, (int) $season['ends_at']) - $checkpoint);
        $rate = (int) $game['per_second'];
        $balance = (int) $game['resources'];
        $capacity = (int) $game['capacity'];
        if ($rate > 0 && $elapsed > intdiv($capacity - $balance, $rate)) return $capacity;
        return $balance + $elapsed * $rate;
    }

    private function saveBalance(array $game, int $resources, int $now): void
    {
        $this->sql('UPDATE yg_strategy_games SET resources=?,checkpoint=? WHERE game_id=?',
            [$resources, max((int) $game['checkpoint'], $now), $game['game_id']]);
    }

    private function advanceRanking(string $seasonId): void
    {
        $this->sql('UPDATE yg_strategy_seasons SET generation=generation+1 WHERE season_id=?', [$seasonId]);
    }

    private function record(string $gameId, string $seasonId, string $type, int $at, array $payload, int $points): int
    {
        $this->sql('UPDATE yg_strategy_games SET revision=revision+1,points=points+? WHERE game_id=?', [$points, $gameId]);
        $revision = (int) $this->sql('SELECT revision FROM yg_strategy_games WHERE game_id=?', [$gameId])->fetchColumn();
        $this->sql('INSERT INTO yg_strategy_events(game_id,revision,event_type,occurred_at,payload) VALUES(?,?,?,?,?)',
            [$gameId, $revision, $type, $at, json_encode($payload, JSON_THROW_ON_ERROR)]);
        $this->sql('DELETE FROM yg_strategy_events WHERE game_id=? AND revision<=?', [$gameId, $revision - $this->eventRetention]);
        if ($points > 0) $this->advanceRanking($seasonId);
        return $revision;
    }

    private function settleDue(string $seasonId, ?string $gameId, int $now): void
    {
        $filter = $gameId === null ? 'season_id=?' : 'game_id=?';
        do {
            $due = $this->sql("SELECT * FROM yg_strategy_operations WHERE $filter AND pending=1 AND completes_at<=?
                ORDER BY completes_at,operation_id LIMIT 128", [$gameId ?? $seasonId, $now])->fetchAll(PDO::FETCH_ASSOC);
            foreach ($due as $op) {
                $claimable = (int) $op['reward_resources'] > 0 || (int) $op['reward_points'] > 0;
                $this->sql('UPDATE yg_strategy_operations SET pending=0,claimable=? WHERE operation_id=?', [(int) $claimable, $op['operation_id']]);
                $this->record($op['game_id'], $seasonId, 'build.completed', (int) $op['completes_at'],
                    ['operationId' => $op['operation_id'], 'target' => $op['target'], 'level' => (int) $op['build_level'],
                     'points' => (int) $op['build_points']], (int) $op['build_points']);
            }
        } while (count($due) === 128);
    }

    public function jobHandlers(): array
    {
        return ['strategy.complete' => function (array $payload, Lease $lease): void {
            $lease->complete(function (PDO $db) use ($payload) {
                if ($db !== $this->db) throw new \LogicException('Lease and game must share their PDO connection.');
                $this->lockSeason($payload['seasonId']);
                $this->settleDue($payload['seasonId'], $payload['gameId'], ($this->clock)());
                $pending = $this->sql('SELECT pending FROM yg_strategy_operations WHERE operation_id=?', [$payload['operationId']])->fetchColumn();
                if ($pending === false || (int) $pending !== 0) throw new \RuntimeException('Scheduled operation is not due.');
            });
        }];
    }

    public function snapshot(Identity $actor, string $gameId): array
    {
        return $this->withGame($actor, $gameId, fn ($game, $season, $now) => $this->view($game, $season, $now));
    }

    private function levels(string $gameId): array
    {
        $rows = $this->sql('SELECT target,MAX(build_level) AS level FROM yg_strategy_operations WHERE game_id=? AND pending=0 GROUP BY target',
            [$gameId])->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows);
    }

    private function targets(): array
    {
        $targets = $this->rules->targets();
        if (!array_is_list($targets) || count(array_unique($targets)) !== count($targets)) throw new \LogicException('Expected distinct target IDs.');
        foreach ($targets as $target) Input::id($target);
        return $targets;
    }

    private function quote(string $target, int $level): ?array
    {
        $quote = $this->rules->build($target, $level);
        if ($quote === null) return null;
        $this->amounts($quote, ['cost','duration','points','rewardResources','rewardPoints']);
        if ($quote['duration'] === 0) throw new \LogicException('Duration must be positive.');
        return $quote;
    }

    private function unavailable(array $season, int $now, bool $pending, array $quote, int $resources): ?string
    {
        if (!$this->active($season, $now)) return 'season_closed';
        if ($pending) return 'build_in_progress';
        if ($quote['duration'] > (int) $season['ends_at'] - $now) return 'insufficient_time';
        if ($quote['cost'] > $resources) return 'insufficient_resources';
        return null;
    }

    private function view(array $game, array $season, int $now): array
    {
        $id = $game['game_id'];
        $resources = $this->resources($game, $season, $now);
        $levels = $this->levels($id);
        $rows = $this->sql('SELECT * FROM yg_strategy_operations WHERE game_id=? AND pending=1 ORDER BY completes_at,operation_id', [$id]);
        $operations = [];
        $pending = [];
        foreach ($rows as $op) {
            $pending[$op['target']] = true;
            $operations[] = ['operationId' => $op['operation_id'], 'target' => $op['target'], 'level' => (int) $op['build_level'],
                'startedAt' => (int) $op['started_at'], 'completesAt' => (int) $op['completes_at'],
                'rulesVersion' => $op['rules_version'], 'cost' => (int) $op['cost']];
        }
        $rewards = [];
        if ($this->active($season, $now)) {
            foreach ($this->sql('SELECT * FROM yg_strategy_operations WHERE game_id=? AND claimable=1 ORDER BY completes_at,operation_id', [$id]) as $op) {
                $rewards[] = ['rewardId' => $op['operation_id'], 'target' => $op['target'],
                    'resources' => (int) $op['reward_resources'], 'points' => (int) $op['reward_points']];
            }
        }
        $quotes = [];
        foreach ($this->targets() as $target) {
            $level = ($levels[$target] ?? 0) + 1;
            if (($quote = $this->quote($target, $level)) === null) continue;
            $reason = $this->unavailable($season, $now, isset($pending[$target]), $quote, $resources);
            $quotes[] = ['target' => $target, 'level' => $level, 'cost' => $quote['cost'], 'duration' => $quote['duration'],
                'points' => $quote['points'], 'available' => $reason === null, 'reason' => $reason];
        }
        return ['gameId' => $id, 'seasonId' => $season['season_id'], 'displayName' => $game['display_name'],
            'rulesVersion' => Input::text($this->rules->version(), 64),
            'revision' => (int) $game['revision'], 'cursor' => (int) $game['revision'], 'serverNow' => $now,
            'startsAt' => (int) $season['starts_at'], 'endsAt' => (int) $season['ends_at'], 'resources' => $resources,
            'capacity' => (int) $game['capacity'], 'perSecond' => (int) $game['per_second'], 'points' => (int) $game['points'],
            'levels' => (object) $levels, 'operations' => $operations, 'rewards' => $rewards, 'quotes' => $quotes];
    }

    public function dispatch(Identity $actor, string $gameId, array $command): array
    {
        return $this->withGame($actor, $gameId, function ($game, $season, $now) use ($command, $gameId) {
            // A rejected command must still commit the due completions reconciled by withGame().
            $this->db->exec('SAVEPOINT strategy_command');
            try {
                $input = Input::command($command);
                $id = $input['commandId'];
                $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
                $receipt = $this->sql('SELECT command_hash,revision FROM yg_strategy_receipts WHERE game_id=? AND command_id=?',
                    [$gameId, $id])->fetch(PDO::FETCH_ASSOC);
                if ($receipt) {
                    if ($hash !== $receipt['command_hash']) throw $this->conflict('command_id_conflict');
                    $revision = (int) $receipt['revision'];
                } else {
                    $rulesVersion = Input::text($this->rules->version(), 64);
                    if ($input['expectedRulesVersion'] !== $rulesVersion) throw $this->conflict('rules_conflict');
                    if ($input['expectedRevision'] !== (int) $game['revision']) throw $this->conflict('revision_conflict');
                    if (!$this->active($season, $now)) throw $this->conflict('season_closed');
                    $revision = $input['type'] === 'build.start'
                        ? $this->start($game, $season, $now, $input['payload']['target'], $rulesVersion)
                        : $this->claim($game, $season, $now, $input['payload']['rewardId']);
                    $this->sql('INSERT INTO yg_strategy_receipts(game_id,command_id,command_hash,revision) VALUES(?,?,?,?)',
                        [$gameId, $id, $hash, $revision]);
                }
                return ['commandId' => $id, 'revision' => $revision, 'cursor' => $revision];
            } catch (HttpException $error) {
                $this->db->exec('ROLLBACK TO SAVEPOINT strategy_command');
                return $error;
            }
        });
    }

    private function start(array $game, array $season, int $now, string $target, string $version): int
    {
        $id = $game['game_id'];
        if (!in_array($target, $this->targets(), true)) throw new HttpException(422, 'Unknown build target.');
        $level = ($this->levels($id)[$target] ?? 0) + 1;
        $quote = $this->quote($target, $level) ?? throw new HttpException(422, 'No build available for this level.');
        $pending = $this->sql('SELECT 1 FROM yg_strategy_operations WHERE game_id=? AND target=? AND pending=1', [$id,$target])->fetchColumn();
        $resources = $this->resources($game, $season, $now);
        $reason = $this->unavailable($season, $now, (bool) $pending, $quote, $resources);
        if ($reason !== null) throw $this->conflict($reason);
        $operation = bin2hex(random_bytes(16));
        $deadline = $now + $quote['duration'];
        $this->sql('INSERT INTO yg_strategy_operations(operation_id,game_id,season_id,target,build_level,started_at,completes_at,
            rules_version,cost,build_points,reward_resources,reward_points,pending,claimable) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,1,0)',
            [$operation, $id, $season['season_id'], $target, $level, $now, $deadline, $version, $quote['cost'], $quote['points'],
             $quote['rewardResources'], $quote['rewardPoints']]);
        $this->saveBalance($game, $resources - $quote['cost'], $now);
        $this->queue->enqueue('strategy:' . $operation, 'strategy.complete',
            ['seasonId' => $season['season_id'], 'gameId' => $id, 'operationId' => $operation], $deadline);
        return $this->record($id, $season['season_id'], 'build.started', $now, ['operationId' => $operation, 'target' => $target,
            'level' => $level, 'completesAt' => $deadline, 'cost' => $quote['cost'], 'rulesVersion' => $version], 0);
    }

    private function claim(array $game, array $season, int $now, string $rewardId): int
    {
        $id = $game['game_id'];
        $op = $this->sql('SELECT reward_resources,reward_points FROM yg_strategy_operations WHERE game_id=? AND operation_id=? AND claimable=1',
            [$id, $rewardId])->fetch(PDO::FETCH_ASSOC);
        if (!$op) throw $this->conflict('reward_unavailable');
        $balance = $this->resources($game, $season, $now);
        $credit = min((int) $op['reward_resources'], (int) $game['capacity'] - $balance);
        $this->saveBalance($game, $balance + $credit, $now);
        $this->sql('UPDATE yg_strategy_operations SET claimable=0 WHERE game_id=? AND operation_id=?', [$id, $rewardId]);
        return $this->record($id, $season['season_id'], 'reward.claimed', $now,
            ['rewardId' => $rewardId, 'resources' => $credit, 'points' => (int) $op['reward_points']], (int) $op['reward_points']);
    }

    public function events(Identity $actor, string $gameId, int $cursor = 0, int $limit = 50): array
    {
        Input::limit($limit);
        if ($cursor < 0) throw new HttpException(422, 'Invalid event cursor.');
        return $this->withGame($actor, $gameId, function ($game) use ($gameId, $cursor, $limit) {
            $revision = (int) $game['revision'];
            $oldest = $this->sql('SELECT MIN(revision) FROM yg_strategy_events WHERE game_id=?', [$gameId])->fetchColumn();
            if ($cursor > $revision || ($oldest !== null && $cursor < (int) $oldest - 1)) {
                return ['items' => [], 'cursor' => $revision, 'hasMore' => false, 'reset' => true];
            }
            $items = [];
            foreach ($this->sql("SELECT * FROM yg_strategy_events WHERE game_id=? AND revision>? ORDER BY revision LIMIT $limit", [$gameId, $cursor]) as $row) {
                $cursor = (int) $row['revision'];
                $items[] = ['cursor' => $cursor, 'type' => $row['event_type'], 'at' => (int) $row['occurred_at'],
                    'payload' => json_decode($row['payload'], true, 8, JSON_THROW_ON_ERROR)];
            }
            return ['items' => $items, 'cursor' => $cursor, 'hasMore' => $cursor < $revision, 'reset' => false];
        });
    }

    public function leaderboard(Identity $actor, string $seasonId, ?string $cursor = null, int $limit = 25): array
    {
        Input::text($actor->id, 128);
        Input::limit($limit);
        return $this->inSeason(Input::id($seasonId), function ($season, $now) use ($actor, $seasonId, $cursor, $limit) {
            if (!$this->sql('SELECT 1 FROM yg_strategy_games WHERE season_id=? AND actor_id=?', [$seasonId, $actor->id])->fetchColumn()) {
                throw new HttpException(403, 'Season membership required.');
            }
            $this->settleDue($seasonId, null, $now);
            $generation = (int) $this->season($seasonId)['generation'];
            $filter = '';
            $params = [$seasonId];
            $reset = false;
            if ($cursor !== null) {
                $key = $this->rankKey($cursor, $seasonId);
                $reset = $key[1] !== $generation;
                if (!$reset) {
                    $filter = ' AND (g.points<? OR (g.points=? AND g.actor_id>?))';
                    array_push($params, $key[2], $key[2], $key[3]);
                }
            }
            $read = $limit + 1;
            $rows = $this->sql("SELECT g.actor_id,g.display_name,g.points,
                (SELECT COUNT(*)+1 FROM yg_strategy_games r WHERE r.season_id=g.season_id AND r.points>g.points) AS placing
                FROM yg_strategy_games g WHERE g.season_id=? $filter ORDER BY g.points DESC,g.actor_id LIMIT $read", $params)->fetchAll(PDO::FETCH_ASSOC);
            $more = count($rows) > $limit;
            if ($more) array_pop($rows);
            $items = array_map(static fn ($row) => ['actorId' => $row['actor_id'], 'displayName' => $row['display_name'],
                'rank' => (int) $row['placing'], 'points' => (int) $row['points']], $rows);
            $next = null;
            if ($more) {
                $last = end($items);
                $next = rtrim(strtr(base64_encode(json_encode([$seasonId, $generation, $last['points'], $last['actorId']], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            }
            return ['items' => $items, 'nextCursor' => $next, 'asOf' => $now, 'reset' => $reset];
        });
    }

    private function rankKey(string $cursor, string $seasonId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,1024}$/D', $cursor)) throw new HttpException(422, 'Invalid standings cursor.');
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $key = $decoded === false ? null : json_decode($decoded, true, 4);
        if (!is_array($key) || !array_is_list($key) || count($key) !== 4 || $key[0] !== $seasonId
            || !is_int($key[1]) || $key[1] < 0 || !is_int($key[2]) || $key[2] < 0) throw new HttpException(422, 'Invalid standings cursor.');
        Input::text($key[3], 128);
        return $key;
    }
}
