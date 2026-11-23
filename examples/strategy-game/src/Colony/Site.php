<?php
namespace Colony;

use PDO;
use ZoltaGaska\Database\Migrator;
use ZoltaGaska\Frontend\{Assets, PageData, V8JsRenderer};
use ZoltaGaska\Http\{Application, HttpException, Kernel, Request, Response};
use ZoltaGaska\Jobs\{JobsModule, Queue};
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Strategy\{StrategyModule, StrategyService};
use ZoltaGaska\Security\{CsrfGuard, Gate, Identity, RequestAuthorizer, Session, SessionAuthenticator};

final class Site
{
    public readonly PDO $db;
    public readonly StrategyService $strategy;
    public readonly SessionAuthenticator $auth;
    public readonly Registry $registry;
    private Kernel $kernel;
    private string $sessions;

    public function __construct(private string $root, string $data)
    {
        $data = realpath($data);
        $public = realpath($root . '/public');
        if (!$data || $data === $public || ($public && str_starts_with($data, $public . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('Initialize STRATEGY_DATA_DIR outside the public directory.');
        }
        $this->sessions = $data . '/sessions';
        if (!getenv('GASKA_SESSION_PATH')) session_save_path($this->sessions);
        $this->db = new PDO('sqlite:' . $data . '/colony.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->auth = new SessionAuthenticator(new Accounts($this->db));
        $queue = new Queue($this->db);
        $this->strategy = new StrategyService($queue, new DemoRules());
        $this->registry = Registry::boot([new JobsModule($queue, $this->strategy->jobHandlers()),
            new StrategyModule($this->strategy, $this->auth), new ColonyModule($this->auth)]);
        $this->kernel = new Kernel($this->registry, new RequestAuthorizer($this->auth, new Gate($this->registry->policies()),
            new CsrfGuard([Session::class, 'csrfToken'])));
    }

    public function handle(Request $request): Response
    {
        $application = new Application($this->root . '/app/pages', new Assets($this->root . '/public/build'), Session::csrfToken(),
            renderer: getenv('GASKA_SSR') === '0' ? null : new V8JsRenderer($this->root . '/build/ssr/server.js', allowLegacyBundle: false),
            document: ['title' => 'Colony strategy'], moduleKernel: $this->kernel, loaderContext: $this);
        $response = $application->handle($request);
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
        if (strlen($password) < 12 || strlen($password) > 72) throw new \InvalidArgumentException('STRATEGY_PLAYER_PASSWORD must contain 12–72 bytes.');
        $insert = $this->db->prepare('INSERT INTO colony_accounts (id,login,password_hash,enabled) VALUES (?,?,?,1) ON CONFLICT(id) DO NOTHING');
        foreach (['player', 'rival'] as $login) $insert->execute(['demo-' . $login, $login, password_hash($password, PASSWORD_DEFAULT)]);
        $year = (int) gmdate('Y');
        $this->strategy->createSeason('demo-' . $year, gmmktime(0, 0, 0, 1, 1, $year), gmmktime(0, 0, 0, 1, 1, $year + 1));
    }

    private function actor(Request $request): Identity
    {
        return $this->auth->identity($request) ?? throw new HttpException(401, 'Sign in required.');
    }

    public function home(Request $request): PageData
    {
        $actor = $this->auth->identity($request);
        $seasons = array_map(static fn ($season) => ['name' => $season['seasonId']] + $season, $this->strategy->seasons());
        return new PageData(['identity' => $actor?->id, 'games' => $actor ? $this->strategy->games($actor) : [], 'seasons' => $seasons], 'Colonies | Strategy demo');
    }

    public function game(Request $request, string $id): PageData
    {
        $snapshot = $this->strategy->snapshot($this->actor($request), $id);
        $panel = $request->query('panel', 'overview');
        $dialog = $request->query('dialog');
        $target = $request->query('target');
        if (!in_array($panel, ['overview','buildings','leaderboard'], true) || !in_array($dialog, [null,'build'], true)
            || ($target !== null && !in_array($target, (new DemoRules())->targets(), true))) throw new HttpException(400, 'Unknown game view.');
        return new PageData(['snapshot' => $snapshot, 'panel' => $panel, 'dialog' => $dialog, 'target' => $target],
            $snapshot['displayName'] . ' | Colony strategy');
    }
}
