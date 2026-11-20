<?php
namespace SceneStudio;

use PDO;
use ZoltaGaska\Assets\{AssetModule, AssetService, LocalStorage};
use ZoltaGaska\Database\Migrator;
use ZoltaGaska\Frontend\{Assets, PageData, V8JsRenderer};
use ZoltaGaska\Http\{Application, HttpException, Kernel, Request, Response};
use ZoltaGaska\Jobs\{JobsModule, Queue};
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Scene\{SceneModule, SceneService};
use ZoltaGaska\Security\{CsrfGuard, Gate, Identity, RequestAuthorizer, Session, SessionAuthenticator};

final class Site
{
    public readonly PDO $db;
    public readonly SceneService $scenes;
    public readonly AssetService $assets;
    public readonly SessionAuthenticator $auth;
    public readonly Registry $registry;
    private Kernel $kernel;
    private string $sessions;

    public function __construct(private string $root, string $data, string $signingKey)
    {
        $data = realpath($data);
        $public = realpath($root . '/public');
        if (!$data || $data === $public || ($public && str_starts_with($data, $public . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('Initialize SCENE_DATA_DIR outside the public directory.');
        }
        if (strlen($signingKey) < 32) throw new \InvalidArgumentException('SCENE_SIGNING_KEY must contain at least 32 bytes.');
        $this->sessions = $data . '/sessions';
        if (!getenv('GASKA_SESSION_PATH')) session_save_path($this->sessions);
        $this->db = new PDO('sqlite:' . $data . '/studio.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->auth = new SessionAuthenticator(new Accounts($this->db));
        $queue = new Queue($this->db);
        $this->scenes = new SceneService($this->db, fn ($project, $version, $actor) =>
            ($this->assets->descriptor($project, $version, $actor)['status'] ?? null) === 'ready');
        $this->assets = new AssetService($queue, new LocalStorage($data . '/files'), $signingKey,
            fn (Identity $actor, string $project) => $this->scenes->mayEditProject($actor, $project),
            fn (?Identity $actor, string $project, string $version) => $this->scenes->mayReadProject($actor, $project));
        $this->registry = Registry::boot([new SceneModule($this->scenes, $this->auth), new StudioModule($this->scenes, $this->auth),
            new JobsModule($queue, ['assets.prepare' => [$this->assets, 'process']], ['assets.prepare' => [$this->assets, 'failed']]),
            new AssetModule($this->assets, $this->auth)]);
        $this->kernel = new Kernel($this->registry, new RequestAuthorizer($this->auth, new Gate($this->registry->policies()),
            new CsrfGuard([Session::class, 'csrfToken'])));
    }

    public function handle(Request $request): Response
    {
        $application = new Application($this->root . '/app/pages', new Assets($this->root . '/public/build'), Session::csrfToken(),
            renderer: getenv('GASKA_SSR') === '0' ? null : new V8JsRenderer($this->root . '/build/ssr/server.js', allowLegacyBundle: false),
            document: ['title' => 'Scene studio'], moduleKernel: $this->kernel, loaderContext: $this);
        $response = $application->handle($request);
        if ($response::class !== Response::class) return $response;
        return new Response($response->body, $response->status, ['Cache-Control' => 'private, no-store',
            'Vary' => trim(($response->headers['Vary'] ?? '') . ', Cookie', ', ')] + $response->headers);
    }

    public function migrate(): array
    {
        if (!is_dir($this->sessions) && !mkdir($this->sessions, 0700) && !is_dir($this->sessions)) throw new \RuntimeException('Cannot initialize private sessions.');
        return (new Migrator($this->db, $this->root . '/migrations', $this->registry->migrationDirectories()))->migrate();
    }

    public function seed(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72) throw new \InvalidArgumentException('SCENE_EDITOR_PASSWORD must contain 12–72 bytes.');
        $this->db->prepare("INSERT INTO scene_studio_accounts (id,login,password_hash,enabled) VALUES ('demo-editor','editor',?,1)
            ON CONFLICT(id) DO NOTHING")->execute([password_hash($password, PASSWORD_DEFAULT)]);
    }

    private function actor(Request $request): Identity
    {
        return $this->auth->identity($request) ?? throw new HttpException(401, 'Sign in required.');
    }

    public function home(Request $request): PageData
    {
        $actor = $this->auth->identity($request);
        return new PageData(['identity' => $actor?->id, 'projects' => $actor ? $this->scenes->projects($actor) : []], 'Projects | Scene studio');
    }

    public function project(Request $request, string $projectId): PageData
    {
        $actor = $this->actor($request);
        $scenes = $this->scenes->scenes($actor, $projectId);
        $project = null;
        foreach ($this->scenes->projects($actor) as $candidate) if ($candidate['projectId'] === $projectId) $project = $candidate;
        if (!$project) throw new HttpException(404, 'Project not found.');
        return new PageData(['project' => $project, 'scenes' => $scenes, 'assets' => $project['role'] === 'viewer'
            ? [] : $this->assets->listVersions($projectId, $actor)], $project['name'] . ' | Scene studio');
    }

    public function scene(Request $request, string $projectId, string $sceneId): PageData
    {
        $actor = $this->actor($request);
        $document = $this->scenes->document($actor, $projectId, $sceneId);
        $assets = [];
        if ($this->scenes->mayEditProject($actor, $projectId)) $assets = $this->assets->listVersions($projectId, $actor);
        foreach ($document['entities'] as $entity) {
            $id = $entity['assetVersionId'];
            if ($id && !in_array($id, array_column($assets, 'assetVersionId'), true)) {
                $descriptor = $this->assets->descriptor($projectId, $id, $actor);
                if ($descriptor) $assets[] = $descriptor;
            }
        }
        return new PageData(['document' => $document, 'assets' => $assets,
            'readOnly' => !$this->scenes->mayEditProject($actor, $projectId)], $document['name'] . ' | Scene studio');
    }
}
