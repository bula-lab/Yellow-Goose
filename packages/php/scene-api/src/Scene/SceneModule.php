<?php
namespace ZoltaGaska\Scene;

use ZoltaGaska\Http\{HttpException, Request, Response};
use ZoltaGaska\Module\{Module, Registry};
use ZoltaGaska\Security\{Identity, IdentityProvider};

final class SceneModule implements Module
{
    public function __construct(private SceneService $service, private IdentityProvider $auth) {}
    public function id(): string { return 'scene-api'; }
    public function dependencies(): array { return []; }

    public function register(Registry $registry): void
    {
        $registry->migrations(dirname(__DIR__, 2) . '/migrations');
        $registry->policy('scene.authenticated', static fn (?Identity $actor) => $actor !== null);
        $route = fn (string $name, string $method, string $path, callable $handler) =>
            $registry->route('scene.' . $name, $method, '/api/projects', $path, $handler, ['scene.authenticated']);
        $actor = fn (Request $r) => $this->auth->identity($r) ?? throw new HttpException(401, 'Sign in required.');
        $scenes = '/{projectId}/scenes';
        $scene = $scenes . '/{sceneId}';
        $members = '/{projectId}/members';
        $route('projects', 'GET', '', fn ($r) => ['items' => $this->service->projects($actor($r))]);
        $route('create-project', 'POST', '', fn ($r) => Response::json($this->service->createProject($actor($r), $this->readName($r)), 201));
        $route('scenes', 'GET', $scenes, fn ($r, $p) => ['items' => $this->service->scenes($actor($r), $p['projectId'])]);
        $route('create-scene', 'POST', $scenes, fn ($r, $p) =>
            Response::json($this->service->createScene($actor($r), $p['projectId'], $this->readName($r)), 201));
        $route('document', 'GET', $scene, fn ($r, $p) => $this->service->document($actor($r), $p['projectId'], $p['sceneId']));
        $route('export', 'GET', $scene . '/export', fn ($r, $p) =>
            Response::json($this->service->exportDocument($actor($r), $p['projectId'], $p['sceneId']), 200,
                ['Content-Disposition' => 'attachment; filename="scene.json"']));
        $route('import', 'POST', $scenes . '/import', function ($r, $p) use ($actor) {
            $body = $r->json();
            SceneInput::keys($body, ['document'], ['document']);
            $doc = $this->jsonObject($body['document'], 'document');
            if (array_key_exists('coordinateSystem', $doc)) $this->jsonObject($doc['coordinateSystem'], 'coordinateSystem');
            if (is_array($doc['entities'] ?? null)) {
                foreach ($doc['entities'] as $entity) $this->entityObjects($this->jsonObject($entity, 'entity'));
            }
            return Response::json($this->service->importScene($actor($r), $p['projectId'], $doc), 201);
        });
        $route('commands', 'POST', $scene . '/commands', function ($r, $p) use ($actor) {
            $body = $r->json();
            $this->entityObjects($this->jsonObject($body['payload'] ?? null, 'payload'));
            return $this->service->dispatch($actor($r), $p['projectId'], $p['sceneId'], $body);
        });
        $route('members', 'GET', $members, fn ($r, $p) => ['items' => $this->service->members($actor($r), $p['projectId'])]);
        $route('set-member', 'PUT', $members . '/{userId}', function ($r, $p) use ($actor) {
            $body = $r->json();
            SceneInput::keys($body, ['role'], ['role']);
            if (!is_string($body['role'])) throw new HttpException(422, 'Invalid role.');
            return $this->service->setMember($actor($r), $p['projectId'], $p['userId'], $body['role']);
        });
        $route('revoke-member', 'DELETE', $members . '/{userId}', fn ($r, $p) =>
            $this->service->setMember($actor($r), $p['projectId'], $p['userId'], null));
    }

    private function readName(Request $request): string
    {
        $body = $request->json();
        SceneInput::keys($body, ['name'], ['name']);
        return SceneInput::name($body['name']);
    }

    private function jsonObject(mixed $value, string $field): array
    {
        if (!$value instanceof \stdClass) throw new HttpException(422, "$field must be a JSON object.");
        return (array) $value;
    }

    private function entityObjects(array $fields): void
    {
        foreach (['transform', 'properties'] as $key) if (array_key_exists($key, $fields)) $this->jsonObject($fields[$key], $key);
    }
}
