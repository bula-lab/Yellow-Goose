<?php
namespace ZoltaGaska\Scene;

use PDO;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Security\Identity;

final class SceneService
{
    private bool $mysql;
    private ?\Closure $readyAsset;

    /** @param callable(string,string,Identity):bool|null $readyAsset */
    public function __construct(private PDO $db, ?callable $readyAsset = null)
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true) || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException('Scene service requires SQLite or MySQL PDO with exception mode.');
        }
        $this->mysql = $driver === 'mysql';
        $this->readyAsset = $readyAsset === null ? null : \Closure::fromCallable($readyAsset);
    }

    public function projects(Identity $actor): array
    {
        return $this->run('SELECT p.project_id AS projectId, p.name, m.role FROM yg_scene_projects p
            JOIN yg_scene_members m ON m.project_id=p.project_id WHERE m.actor_id=? ORDER BY p.name,p.project_id',
            [SceneInput::actor($actor->id)])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createProject(Identity $actor, string $name, ?string $projectId = null): array
    {
        $name = SceneInput::name($name);
        $projectId = SceneInput::id($projectId ?? bin2hex(random_bytes(16)), 'projectId');
        $actorId = SceneInput::actor($actor->id);
        return $this->transaction(function () use ($projectId, $name, $actorId) {
            $this->insertUnique('INSERT INTO yg_scene_projects(project_id,name) VALUES (?,?)', [$projectId, $name]);
            $this->run("INSERT INTO yg_scene_members(project_id,actor_id,role) VALUES (?,?,'owner')", [$projectId, $actorId]);
            return ['projectId' => $projectId, 'name' => $name, 'role' => 'owner'];
        });
    }

    public function mayReadProject(?Identity $actor, string $projectId): bool
    {
        return $actor !== null && $this->role($actor, $projectId) !== false;
    }

    public function mayEditProject(?Identity $actor, string $projectId): bool
    {
        return $actor !== null && in_array($this->role($actor, $projectId), ['owner', 'editor'], true);
    }

    private function role(Identity $actor, string $projectId): string|false
    {
        return $this->run('SELECT role FROM yg_scene_members WHERE project_id=? AND actor_id=?',
            [SceneInput::id($projectId, 'projectId'), SceneInput::actor($actor->id)])->fetchColumn();
    }

    public function members(Identity $actor, string $projectId): array
    {
        $this->authorize($actor, $projectId, ['owner']);
        return $this->run('SELECT actor_id AS userId, role FROM yg_scene_members WHERE project_id=? ORDER BY actor_id', [$projectId])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setMember(Identity $actor, string $projectId, string $userId, ?string $role): array
    {
        return $this->transaction(function () use ($actor, $projectId, $userId, $role) {
            $this->lockProject($projectId);
            $this->authorize($actor, $projectId, ['owner']);
            SceneInput::actor($userId);
            if ($userId === $actor->id || !in_array($role, [null, 'editor', 'viewer'], true)) throw new HttpException(422, 'Invalid membership change.');
            $this->run('DELETE FROM yg_scene_members WHERE project_id=? AND actor_id=?', [$projectId, $userId]);
            if ($role !== null) $this->run('INSERT INTO yg_scene_members(project_id,actor_id,role) VALUES (?,?,?)', [$projectId, $userId, $role]);
            return ['userId' => $userId, 'role' => $role];
        });
    }

    public function scenes(Identity $actor, string $projectId): array
    {
        $this->authorize($actor, $projectId);
        $rows = $this->run('SELECT s.project_id AS projectId,s.scene_id AS sceneId,s.name,s.revision FROM yg_scenes s
            JOIN yg_scene_members m ON m.project_id=s.project_id WHERE s.project_id=? AND m.actor_id=? ORDER BY s.name,s.scene_id',
            [$projectId, $actor->id])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['revision'] = (int) $row['revision'];
        return $rows;
    }

    public function document(Identity $actor, string $projectId, string $sceneId): array
    {
        $this->authorize($actor, $projectId);
        $json = $this->run('SELECT s.document_json FROM yg_scenes s JOIN yg_scene_members m ON m.project_id=s.project_id
            WHERE s.project_id=? AND s.scene_id=? AND m.actor_id=?', [$projectId, SceneInput::id($sceneId, 'sceneId'), $actor->id])->fetchColumn();
        if ($json === false) throw new HttpException(404, 'Scene not found.');
        return SceneInput::decode($json);
    }

    public function exportDocument(Identity $actor, string $projectId, string $sceneId): array
    {
        $document = $this->document($actor, $projectId, $sceneId);
        $check = $this->assetCheck($actor, $projectId);
        foreach (array_unique(array_column($document['entities'], 'assetVersionId'), SORT_STRING) as $id) {
            if ($id !== null) $check($id);
        }
        return $document;
    }

    public function createScene(Identity $actor, string $projectId, string $name, ?string $sceneId = null): array
    {
        return $this->transaction(function () use ($actor, $projectId, $name, $sceneId) {
            $this->lockProject($projectId);
            $this->authorize($actor, $projectId, ['owner', 'editor']);
            return $this->insertDocument(SceneDocument::create($projectId, SceneInput::id($sceneId ?? bin2hex(random_bytes(16)), 'sceneId'), SceneInput::name($name)));
        });
    }

    public function importScene(Identity $actor, string $projectId, array $document): array
    {
        return $this->transaction(function () use ($actor, $projectId, $document) {
            $this->lockProject($projectId);
            $this->authorize($actor, $projectId, ['owner', 'editor']);
            return $this->insertDocument(SceneDocument::imported($document, $projectId, bin2hex(random_bytes(16)), $this->assetCheck($actor, $projectId)));
        });
    }

    private function insertDocument(array $document): array
    {
        $this->insertUnique('INSERT INTO yg_scenes(project_id,scene_id,name,revision,document_json) VALUES (?,?,?,0,?)',
            [$document['projectId'], $document['sceneId'], $document['name'], $this->snapshot($document)]);
        return $document;
    }

    public function dispatch(Identity $actor, string $projectId, string $sceneId, array $command): array
    {
        return $this->transaction(function () use ($actor, $projectId, $sceneId, $command) {
            $this->lockProject($projectId);
            $this->authorize($actor, $projectId, ['owner', 'editor']);
            SceneInput::id($sceneId, 'sceneId');
            $fields = ['commandId', 'type', 'entityId', 'expectedRevision', 'payload'];
            SceneInput::keys($command, $fields, $fields);
            $commandId = SceneInput::id($command['commandId'], 'commandId');
            $entityId = SceneInput::id($command['entityId'], 'entityId');
            $revision = $command['expectedRevision'];
            if (!is_int($revision) || $revision < 0 || !is_string($command['type'])) throw new HttpException(422, 'Invalid command type or revision.');
            $payload = SceneInput::object($command['payload'], 'payload');
            $command['payload'] = (object) $payload;

            $scope = [$projectId, $sceneId, $actor->id, $commandId];
            $hash = hash('sha256', SceneInput::json(SceneInput::canonical($command)));
            $prior = $this->run('SELECT operation_hash,result_json FROM yg_scene_receipts
                WHERE project_id=? AND scene_id=? AND actor_id=? AND command_id=?', $scope)->fetch(PDO::FETCH_ASSOC);
            if ($prior) {
                if (!hash_equals($prior['operation_hash'], $hash)) throw new HttpException(409, 'Command ID already used.', ['code' => 'command_id_conflict']);
                return SceneInput::decode($prior['result_json']);
            }

            $document = $this->document($actor, $projectId, $sceneId);
            if ($document['revision'] !== $revision) $this->conflict($document);
            $result = ['commandId' => $commandId, 'revision' => $revision + 1] + SceneDocument::apply(
                $document, $command['type'], $entityId, $payload, $this->assetCheck($actor, $projectId));
            $update = $this->run('UPDATE yg_scenes SET revision=?, document_json=? WHERE project_id=? AND scene_id=? AND revision=?',
                [$result['revision'], $this->snapshot($document), $projectId, $sceneId, $revision]);
            if ($update->rowCount() !== 1) $this->conflict($this->document($actor, $projectId, $sceneId));
            $this->run('INSERT INTO yg_scene_receipts(project_id,scene_id,actor_id,command_id,operation_hash,result_json) VALUES (?,?,?,?,?,?)',
                [...$scope, $hash, SceneInput::json($result)]);
            $event = ['eventId' => bin2hex(random_bytes(16)), 'type' => 'scene.changed', 'projectId' => $projectId, 'sceneId' => $sceneId,
                'actorId' => $actor->id, 'commandId' => $commandId, 'revision' => $result['revision'], 'changes' => $result['changes']];
            $this->run('INSERT INTO yg_scene_outbox(project_id,scene_id,revision,event_json) VALUES (?,?,?,?)',
                [$projectId, $sceneId, $result['revision'], SceneInput::json($event)]);
            return $result;
        });
    }

    /** At-least-once delivery. The callback must deduplicate eventId before acknowledging delivery. */
    public function publishOutbox(callable $publish, int $limit = 100): int
    {
        if ($this->db->inTransaction()) throw new \LogicException('Publish after the scene transaction commits.');
        if ($limit < 1 || $limit > 1000) throw new \InvalidArgumentException('Invalid outbox batch size.');
        $events = $this->db->query('SELECT event_json FROM yg_scene_outbox WHERE published_at IS NULL
            ORDER BY project_id,scene_id,revision LIMIT ' . $limit)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($events as $json) {
            $event = SceneInput::decode($json);
            $publish($event);
            $this->run('UPDATE yg_scene_outbox SET published_at=? WHERE project_id=? AND scene_id=? AND revision=? AND published_at IS NULL',
                [gmdate('c'), $event['projectId'], $event['sceneId'], $event['revision']]);
        }
        return count($events);
    }

    private function authorize(Identity $actor, string $projectId, array $roles = ['owner', 'editor', 'viewer']): void
    {
        if (!in_array($this->role($actor, $projectId), $roles, true)) throw new HttpException(403, 'Project access denied.');
    }

    private function assetCheck(Identity $actor, string $projectId): \Closure
    {
        return function (string $id) use ($actor, $projectId): void {
            if ($this->readyAsset === null || ($this->readyAsset)($projectId, $id, $actor) !== true) {
                throw new HttpException(422, 'Asset version is unavailable in this project.');
            }
        };
    }

    private function snapshot(array $document): string
    {
        $json = SceneInput::json($document);
        if (strlen($json) > 524288) throw new HttpException(422, 'Scene snapshot exceeds 512 KiB.');
        return $json;
    }

    private function conflict(array $document): never
    {
        throw new HttpException(409, 'Scene revision has changed.', ['code' => 'revision_conflict', 'revision' => $document['revision'], 'document' => $document]);
    }

    private function lockProject(string $projectId): void
    {
        SceneInput::id($projectId, 'projectId');
        // Both writers and membership changes acquire this lock before their first consistent read.
        if ($this->mysql) $this->run('SELECT project_id FROM yg_scene_projects WHERE project_id=? FOR UPDATE', [$projectId])->fetchColumn();
        else $this->run('UPDATE yg_scene_projects SET name=name WHERE project_id=?', [$projectId]);
    }

    private function run(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private function insertUnique(string $sql, array $params): void
    {
        try { $this->run($sql, $params); }
        catch (\PDOException $e) {
            if ($e->getCode() === '23000') throw new HttpException(409, 'ID already exists.');
            throw $e;
        }
    }

    private function transaction(callable $work): mixed
    {
        if ($this->db->inTransaction()) throw new \LogicException('Scene mutations require their own transaction.');
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof \PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [5, 6, 1205, 1213], true)) {
                throw new HttpException(503, 'Scene is busy. Retry the same command.', ['code' => 'scene_busy'], ['Retry-After' => '1']);
            }
            throw $e;
        }
    }
}
