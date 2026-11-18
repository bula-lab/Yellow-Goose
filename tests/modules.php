<?php
require __DIR__ . '/../vendor/autoload.php';

use ZoltaGaska\Module\Module;
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Http\Kernel;
use ZoltaGaska\Http\Request;
use ZoltaGaska\Http\Response;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Application;
use ZoltaGaska\Frontend\Assets;
use ZoltaGaska\Security\{Identity, IdentityProvider, Account, UserProvider, SessionAuthenticator, Session, Gate, CsrfGuard, RequestAuthorizer};
use ZoltaGaska\Resources\{Action, ResourceDefinition, ResourceModule, SymfonyValidator, QueryOptions};
use ZoltaGaska\Database\Migrator;
use Symfony\Component\Validator\Constraints as Assert;

$count = 0;
function expectModule(bool $ok, string $label): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $count++;
}
function moduleRejects(callable $action, string $message): void {
    try { $action(); } catch (Throwable $error) {
        expectModule(str_contains($error->getMessage(), $message), $message . ': ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}
final class TestModule implements Module {
    public function __construct(private string $name, private array $requires, private Closure $configure) {}
    public function id(): string { return $this->name; }
    public function dependencies(): array { return $this->requires; }
    public function register(Registry $registry): void { ($this->configure)($registry); }
}
function mod(string $id, array $requires = [], ?Closure $configure = null): Module {
    return new TestModule($id, $requires, $configure ?? static function () {});
}
$temp = sys_get_temp_dir() . '/yg-modules-' . bin2hex(random_bytes(8));
mkdir($temp);
ini_set('error_log', $temp . '/errors.log');
putenv('GASKA_SESSION_PATH=' . $temp);
try {
    $order = [];
    $registry = Registry::boot([
        mod('child', ['base'], function ($r) use (&$order) { $order[] = 'child'; $r->service('answer', fn ($r) => $r->get('base') + 1); }),
        mod('base', [], function ($r) use (&$order) { $order[] = 'base'; $r->service('base', fn () => 41); $r->command('check', fn ($args) => count($args)); }),
    ]);
    expectModule($order === ['base', 'child'] && $registry->get('answer') === 42, 'dependency order and services');
    expectModule($registry->owners()['service']['answer'] === 'child', 'service ownership');
    expectModule($registry->run('check', ['a', 'b']) === 2, 'registered command');
    moduleRejects(fn () => $registry->run('unknown'), 'Unknown module command');
    moduleRejects(fn () => $registry->service('late', fn () => 1), 'frozen');
    moduleRejects(fn () => Registry::boot([mod('base'), mod('base')]), 'Duplicate module');
    moduleRejects(fn () => Registry::boot([mod('base', ['missing'])]), 'Missing module');
    moduleRejects(fn () => Registry::boot([mod('a', ['b']), mod('b', ['a'])]), 'cycle');
    moduleRejects(fn () => Registry::boot([mod('app')]), 'reserved');
    foreach (['service', 'command', 'policy'] as $kind) {
        moduleRejects(fn () => Registry::boot([mod('a', [], fn ($r) => $r->$kind('same', fn () => true)), mod('b', [], fn ($r) => $r->$kind('same', fn () => true))]), 'Duplicate');
    }
    $cycle = Registry::boot([mod('base', [], function ($r) { $r->service('a', fn ($r) => $r->get('b')); $r->service('b', fn ($r) => $r->get('a')); })]);
    moduleRejects(fn () => $cycle->get('a'), 'cycle');
    $routes = Registry::boot([mod('http', [], function ($r) {
        $r->route('health', 'GET', '/api/test', '/{id}', fn ($request, $params) => ['id' => $params['id']]);
        $r->route('failure', 'GET', '/api/failure', '', function () { throw new RuntimeException('SECRET_MODULE_FAILURE'); });
    })]);
    $kernel = new Kernel($routes);
    expectModule($kernel->handle(new Request('/api/test/a%20b', 'GET'))->body === '{"id":"a b"}', 'module route parameters');
    expectModule($kernel->handle(new Request('/api/test/x', 'HEAD'))->status === 200, 'module HEAD fallback');
    $response = $kernel->handle(new Request('/api/test/x', 'POST'));
    expectModule($response->status === 405 && $response->headers['Allow'] === 'GET, HEAD', 'module method protection');
    expectModule($kernel->handle(new Request('/missing', 'GET'))->status === 404, 'module not found');
    $response = $kernel->handle(new Request('/api/failure', 'GET'));
    expectModule($response->status === 500 && !str_contains($response->body, 'SECRET_MODULE_FAILURE'), 'module exception sanitized');
    foreach ([['/{id}', '/{other}'], ['/{id}', '/fixed'], ['/fixed', '/fixed']] as [$first, $second]) {
        moduleRejects(fn () => Registry::boot([mod('a', [], function ($r) use ($first, $second) {
            $r->route('a', 'GET', '/api/test', $first, fn () => []);
            $r->route('b', 'GET', '/api/test', $second, fn () => []);
        })]), 'Overlapping');
    }
    moduleRejects(fn () => Registry::boot([mod('a', [], fn ($r) => $r->route('a', 'POST', '/api/a', '', fn () => []))]), 'explicit policy');
    moduleRejects(fn () => Registry::boot([mod('a', [], fn ($r) => $r->route('a', 'GET', '/api/a', '', fn () => [], ['missing']))]), 'Missing policy');
    $protected = Registry::boot([mod('a', [], function ($r) { $r->policy('read', fn () => true); $r->route('a', 'GET', '/api/a', '', fn () => [], ['read']); })]);
    moduleRejects(fn () => new Kernel($protected), 'authorizer');

    // Accounts are injected; sessions contain only the account ID, not client roles.
    $users = new class implements UserProvider {
        public bool $enabled = true;
        private string $hash;
        public function __construct() { $this->hash = password_hash('test-password', PASSWORD_DEFAULT); }
        public function findByLogin(string $login): ?Account { return $login === 'editor' ? $this->findById('1') : null; }
        public function findById(string $id): ?Account { return $id === '1' ? new Account(new Identity('1', ['editor']), $this->hash, $this->enabled) : null; }
    };
    $auth = new SessionAuthenticator($users);
    $oldToken = Session::csrfToken();
    $oldSession = session_id();
    moduleRejects(fn () => $auth->login('editor', 'wrong'), 'Invalid credentials');
    moduleRejects(fn () => $auth->login('unknown', 'test-password'), 'Invalid credentials');
    expectModule($auth->identity(new Request('/', 'GET', ['X-User-Id' => '1'])) === null, 'untrusted identity header ignored');
    expectModule($auth->login('editor', 'test-password')->id === '1', 'account login');
    expectModule(session_id() !== $oldSession && Session::csrfToken() !== $oldToken, 'login rotates session and CSRF');
    expectModule($auth->identity(new Request('/', 'GET'))->roles === ['editor'], 'server reloads account');
    $users->enabled = false;
    expectModule($auth->identity(new Request('/', 'GET')) === null, 'revoked account rejected on next request');
    $users->enabled = true;
    $auth->logout();
    expectModule($auth->identity(new Request('/', 'GET')) === null, 'logout clears identity');
    moduleRejects(fn () => (new Gate([]))->authorize('missing', new Identity('1')), 'Access denied');

    // A real resource consumer: query scope, explicit action, schema and Symfony rules.
    $identities = new class implements IdentityProvider {
        public ?Identity $current = null;
        public function identity(Request $request): ?Identity { return $this->current; }
    };
    $validator = new SymfonyValidator(['title' => new Assert\Sequentially([
        new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 20),
    ])]);
    $writes = 0;
    $resource = new ResourceDefinition('notes', 'notes.read', ['id' => ['type' => 'integer'], 'title' => ['type' => 'string', 'required' => true, 'maxLength' => 20]],
        fn ($options, $identity) => ['items' => [['id' => (int) $identity->id, 'title' => 'visible', 'passwordHash' => 'hidden']], 'nextCursor' => null],
        [new Action('save', 'notes.write', $validator, function ($data, $identity) use (&$writes) { $writes++; return ['owner' => $identity->id, 'title' => $data['title']]; })],
        sortable: ['title'], filterable: ['title']);
    $registry = Registry::boot([
        new ResourceModule($resource, $identities, ['auth']),
        mod('auth', [], function ($r) {
            $r->policy('notes.read', fn ($identity) => $identity !== null);
            $r->policy('notes.write', fn ($identity) => $identity?->id === '1');
        }),
    ]);
    $token = str_repeat('a', 64);
    $kernel = new Kernel($registry, new RequestAuthorizer($identities, new Gate($registry->policies()), new CsrfGuard(fn () => $token)));
    $send = function ($uri, $method = 'GET', $body = '{}', $csrf = null, $type = 'application/json') use ($kernel, $token) {
        return $kernel->handle(new Request($uri, $method, ['Content-Type' => $type, 'X-CSRF-Token' => $csrf ?? $token], $body));
    };
    expectModule($send('/api/resources/notes')->status === 401, 'anonymous query rejected');
    expectModule($send('/api/resources/notes/schema')->status === 401, 'anonymous schema rejected');
    $identities->current = new Identity('2', ['viewer']);
    $result = json_decode($send('/api/resources/notes?limit=1&sort=title&filter[title]=visible')->body, true);
    expectModule($result['items'] === [['id' => 2, 'title' => 'visible']], 'identity-scoped query and field allowlist');
    expectModule($send('/api/resources/notes/actions/save', 'POST', '{"title":"ok"}')->status === 403 && $writes === 0, 'unauthorized action does not write');
    $identities->current = new Identity('1', ['editor']);
    expectModule($send('/api/resources/notes/actions/save', 'POST', '{"title":"ok"}', 'wrong')->status === 403 && $writes === 0, 'CSRF blocks action before write');
    foreach (['{"title":""}', '{"title":42}', '{"title":"ok","isAdmin":true}', '{"title":"123456789012345678901"}', '{}'] as $body) {
        $response = $send('/api/resources/notes/actions/save', 'POST', $body);
        expectModule($response->status === 422 && json_decode($response->body, true)['errors'] && $writes === 0, 'invalid action rejected: ' . $body);
    }
    expectModule($send('/api/resources/notes/actions/save', 'POST', '{')->status === 400, 'malformed JSON rejected');
    expectModule($send('/api/resources/notes/actions/save', 'POST', '{}', null, 'text/plain')->status === 415, 'wrong media type rejected');
    expectModule($send('/api/resources/notes/actions/save', 'POST', str_repeat('x', Request::MAX_BODY_BYTES + 1))->status === 413, 'oversized action rejected');
    foreach (['limit=0', 'limit=101', 'limit[]=1', 'sort=passwordHash', 'sort[]=title', 'direction[]=asc', 'filter[isAdmin]=1', 'filter[title][]=x', 'cursor[]=x'] as $query) {
        expectModule($send('/api/resources/notes?' . $query)->status === 400, 'query rejected: ' . $query);
    }
    $response = $send('/api/resources/notes/actions/save', 'POST', '{"title":"safe"}');
    expectModule($response->status === 200 && $writes === 1 && json_decode($response->body, true)['owner'] === '1', 'validated action reaches consumer once');
    $schema = json_decode($send('/api/resources/notes/schema')->body, true);
    expectModule($schema['actions'] === ['save'] && $schema['fields']['title']['maxLength'] === 20, 'restricted UI schema');
    expectModule($send('/api/resources/notes/actions/delete', 'POST')->status === 404, 'undeclared action unavailable');
    expectModule($send('/api/resources/notes/actions/save', 'GET')->status === 405, 'action method fixed');
    expectModule($response->headers['Cache-Control'] === 'no-store', 'resource response private');

    // File routing remains intact; module namespaces cannot silently shadow it.
    mkdir($temp . '/pages/api', 0700, true);
    file_put_contents($temp . '/pages/index.jsx', '');
    file_put_contents($temp . '/pages/api/health.php', '<?php return fn () => ["legacy" => true];');
    $bridge = new Application($temp . '/pages', new Assets($temp, 'http://127.0.0.1:5173'), moduleKernel: $kernel);
    expectModule($bridge->handle(new Request('/api/health', 'GET'))->body === '{"legacy":true}', 'legacy API preserved');
    expectModule($bridge->handle(new Request('/api/resources/notes', 'GET'))->status === 200, 'module API integrated into page application');
    file_put_contents($temp . '/pages/api/resources.php', '<?php return fn () => [];');
    moduleRejects(fn () => new Application($temp . '/pages', new Assets($temp), moduleKernel: $kernel), 'conflicts');

    // Boot only registers migrations. Topological owners and old app basenames persist.
    foreach (['app', 'base', 'child'] as $owner) {
        mkdir($temp . '/' . $owner);
        file_put_contents($temp . '/' . $owner . '/001.php', '<?php return function (PDO $pdo) { $pdo->exec("CREATE TABLE ' . $owner . '_table (id INTEGER)"); };');
    }
    $registry = Registry::boot([
        mod('child', ['base'], fn ($r) => $r->migrations($temp . '/child')),
        mod('base', [], fn ($r) => $r->migrations($temp . '/base')),
    ]);
    $pdo = new PDO('sqlite:' . $temp . '/db.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    expectModule($pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table'")->fetchColumn() === 0, 'boot does not execute DDL');
    (new Migrator($pdo, $temp . '/app'))->migrate();
    $migrator = new Migrator($pdo, $temp . '/app', $registry->migrationDirectories());
    expectModule($migrator->migrate() === ['base:001.php', 'child:001.php'], 'dependency migration order; app not rerun');
    expectModule($migrator->migrate() === [], 'owned migrations idempotent');
    expectModule($pdo->query('SELECT count(*) FROM migrations')->fetchColumn() === 3, 'same basenames have distinct owners');
    file_put_contents($temp . '/child/002.php', '<?php return function (PDO $pdo) { $pdo->exec("CREATE TABLE rollback_table (id INTEGER)"); throw new RuntimeException("migration failed"); };');
    moduleRejects(fn () => $migrator->migrate(), 'migration failed');
    expectModule($pdo->query("SELECT count(*) FROM sqlite_master WHERE name='rollback_table'")->fetchColumn() === 0, 'SQLite failure rolls back schema');
    unlink($temp . '/child/002.php');
    expectModule($migrator->migrate() === [], 'migration lock released after failure');
    moduleRejects(fn () => (new Migrator($pdo, $temp . '/app', ['app' => $temp . '/base']))->migrate(), 'reserved');
    $pdo->beginTransaction();
    moduleRejects(fn () => $migrator->migrate(), 'connection boundary');
    $pdo->rollBack();
    echo "PASS: {$count} module/security/resource/migration assertions\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    putenv('GASKA_SESSION_PATH');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($temp);
}
