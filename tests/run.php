<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Models\Post;
use ZoltaGaska\Config\Environment;
use ZoltaGaska\Database\Connection;
use ZoltaGaska\Database\Migrator;
use ZoltaGaska\Frontend\Assets;
use ZoltaGaska\Http\Application;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;
use ZoltaGaska\Routing\Router;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$count = 0;
function check(bool $condition, string $description): void {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    $count++;
}
function raises(callable $action, string $type, ?int $status = null): void {
    try { $action(); }
    catch (Throwable $error) {
        check($error instanceof $type, 'exception type: ' . get_class($error));
        if ($status !== null) check($error->status === $status, 'HTTP exception ' . $status);
        return;
    }
    throw new RuntimeException('FAIL: expected ' . $type);
}
function fixture(string $path, string $content = ''): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $content);
}
function pageData(string $html): array {
    if (!preg_match('~<script id="page-data" type="application/json">(.*?)</script>~s', $html, $match)) {
        throw new RuntimeException('Page data missing');
    }
    return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
}
$temp = sys_get_temp_dir() . '/gaska-tests-' . bin2hex(random_bytes(8));
mkdir($temp);
$root = dirname(__DIR__);
try {
    // Routing: multiple params, literal escaping, priorities, reserved files.
    $pages = $temp . '/pages';
    foreach (['index.jsx', 'posts/[id].jsx', 'posts/new.jsx', 'docs/[...parts].tsx',
        'search/[[...query]].jsx', 'teams/[team]/members/[id].jsx', 'literal/a+b.jsx',
        '_app.jsx', '_private/hidden.jsx', 'hidden.php', 'api/browser.jsx', 'api/hello.php'] as $file) {
        fixture($pages . '/' . $file);
    }
    $router = new Router($pages);
    foreach ([
        ['/', 'index.jsx', []], ['/posts/new', 'posts/new.jsx', []],
        ['/posts/42', 'posts/[id].jsx', ['id' => '42']], ['/posts/42/', 'posts/[id].jsx', ['id' => '42']],
        ['/docs/a/b', 'docs/[...parts].tsx', ['parts' => ['a', 'b']]],
        ['/search', 'search/[[...query]].jsx', []], ['/search/', 'search/[[...query]].jsx', []],
        ['/search/php/react', 'search/[[...query]].jsx', ['query' => ['php', 'react']]],
        ['/teams/red/members/7', 'teams/[team]/members/[id].jsx', ['team' => 'red', 'id' => '7']],
        ['/posts/a%20b', 'posts/[id].jsx', ['id' => 'a b']],
        ['/literal/a+b', 'literal/a+b.jsx', []], ['/api/hello', 'api/hello.php', []],
    ] as [$uri, $file, $params]) {
        $route = $router->dispatch(new Request($uri, 'GET'));
        check($route?->filePath === $pages . '/' . $file, 'route ' . $uri);
        check($route->params === $params, 'params ' . $uri);
    }
    foreach (['/missing', '/docs', '/_app', '/_private/hidden', '/hidden', '/api/browser', '/literal/ab'] as $uri) {
        check($router->dispatch(new Request($uri, 'GET')) === null, 'exclude ' . $uri);
    }

    // Literal environment parser and process override.
    fixture($temp . '/.env', "# comment\nGASKA_TEST_VALUE=\"hello # world\"\nGASKA_TEST_OVERRIDE=file\nGASKA_TEST_EMPTY=\nGASKA_TEST_COMMENT=value # comment\n");
    putenv('GASKA_TEST_OVERRIDE=process');
    Environment::load($temp . '/.env');
    check(getenv('GASKA_TEST_VALUE') === 'hello # world', 'quoted environment value');
    check(getenv('GASKA_TEST_OVERRIDE') === 'process', 'process env wins');
    check(getenv('GASKA_TEST_EMPTY') === '', 'empty environment value');
    check(getenv('GASKA_TEST_COMMENT') === 'value', 'inline comment');
    fixture($temp . '/bad.env', "not a valid assignment\n");
    raises(fn () => Environment::load($temp . '/bad.env'), RuntimeException::class);

    // HTTP contract independent of server globals.
    $request = new Request('/api/posts?page=2&filter[]=a', 'post', ['CONTENT-TYPE' => 'application/json; charset=UTF-8'], '{"title":"Zażółć"}');
    check($request->uri === '/api/posts' && $request->method === 'POST', 'parsed URI/method');
    check($request->query('page') === '2' && $request->query('filter') === ['a'], 'query values');
    check($request->header('content-type') === 'application/json; charset=UTF-8', 'case insensitive headers');
    check($request->json()['title'] === 'Zażółć', 'JSON decoded');
    foreach (['[]', 'null', '"string"', '123', '{', ''] as $body) {
        raises(fn () => (new Request('/', 'POST', ['Content-Type' => 'application/json'], $body))->json(), HttpException::class, 400);
    }
    raises(fn () => (new Request('/', 'POST', [], '{}'))->json(), HttpException::class, 415);
    raises(fn () => (new Request('/', 'POST', ['Content-Type' => 'application/json'], str_repeat('x', Request::MAX_BODY_BYTES + 1)))->json(), HttpException::class, 413);
    raises(fn () => new Request('bad-uri', 'GET'), HttpException::class, 400);
    raises(fn () => $request->requireMethod(['GET', 'HEAD']), HttpException::class, 405);

    // SQLite and MySQL DSNs, including configured port.
    check(Connection::dsn(['driver' => 'sqlite', 'database' => ':memory:']) === 'sqlite::memory:', 'SQLite DSN');
    $mysql = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3307, 'database' => 'yellow_goose_test', 'charset' => 'utf8mb4'];
    check(str_contains(Connection::dsn($mysql), 'port=3307'), 'MySQL port');
    raises(fn () => Connection::dsn(array_replace($mysql, ['port' => 99999])), InvalidArgumentException::class);
    raises(fn () => Connection::dsn(array_replace($mysql, ['host' => 'localhost;dbname=other'])), InvalidArgumentException::class);
    raises(fn () => Connection::dsn(['driver' => 'unknown']), InvalidArgumentException::class);
    $driver = getenv('GASKA_TEST_MYSQL') === '1' ? 'mysql' : 'sqlite';
    if ($driver === 'mysql') {
        $databaseName = getenv('MYSQL_TEST_DATABASE') ?: 'yellow_goose_test';
        if (!str_ends_with($databaseName, '_test')) throw new RuntimeException('Refusing a non-test MySQL database.');
        $database = array_replace($mysql, [
            'port' => getenv('MYSQL_TEST_PORT') ?: 3306, 'database' => $databaseName,
            'username' => getenv('MYSQL_TEST_USERNAME') ?: 'root',
            'password' => getenv('MYSQL_TEST_PASSWORD') ?: '',
        ]);
    } else {
        $database = ['driver' => 'sqlite', 'database' => $temp . '/test.sqlite'];
    }
    $config = ['default' => $driver, 'connections' => [$driver => $database]];
    Connection::configure($config);
    $pdo = Connection::getInstance();
    if ($driver === 'mysql') {
        // This is an explicitly selected disposable database ending in "_test".
        $pdo->exec('DROP TABLE IF EXISTS posts');
        $pdo->exec('DROP TABLE IF EXISTS migrations');
    }
    $migrator = new Migrator($pdo, $root . '/database/migrations');
    check(count($migrator->migrate()) === 1, 'first migration');
    check($migrator->migrate() === [], 'migration idempotent');

    $post = Post::create(['title' => 'Pierwszy', 'content' => 'Treść', 'id' => 999999, 'created_at' => 'fake', 'is_admin' => true]);
    $id = (string) $post->id;
    check($id !== '999999' && $post->created_at !== 'fake' && $post->is_admin === null, 'mass assignment filters protected fields');
    check(Post::find($id)->id == $id, 'hydration preserves primary key');
    $post->fill(['id' => 999999, 'title' => 'Zmieniony'])->save();
    check(Post::find($id)->title === 'Zmieniony', 'update original record');
    check(Post::count() === 1, 'save hydrated model does not insert');
    $post->save();
    check(Post::count() === 1, 'unchanged save does not insert');
    check(Post::where('id', (int) $id)->count() === 1, 'where accepts numeric shorthand');
    check(Post::where('title', "' OR 1=1 --")->count() === 0, 'values are bound');
    check(Post::where('title', null)->count() === 0, 'null comparison');
    raises(fn () => Post::where('title OR 1=1', 'x')->get(), InvalidArgumentException::class);
    raises(fn () => Post::where('title', '= ? OR 1=1 --', 'x')->get(), InvalidArgumentException::class);
    raises(fn () => Post::orderBy('id', 'desc; drop table posts'), InvalidArgumentException::class);
    raises(fn () => Post::orderBy('password'), InvalidArgumentException::class);
    raises(fn () => Post::limit(0), InvalidArgumentException::class);
    raises(fn () => Post::create(['id' => 123]), InvalidArgumentException::class);
    raises(function () use ($post) { $post->id = 2; }, InvalidArgumentException::class);
    $fresh = new Post(['title' => 'Nowy', 'content' => 'Nowy rekord']);
    $fresh->save();
    check($fresh->id !== null && Post::count() === 2, 'new model save');
    check(Post::orderBy('id', 'desc')->first()->id == $fresh->id, 'ordering');
    check(count(Post::orderBy('id')->limit(1, 1)->get()) === 1, 'pagination');
    check($fresh->delete() && !$fresh->delete(), 'model delete idempotent');
    Connection::disconnect();
    check(Post::find($id)->title === 'Zmieniony', 'data survives reconnect');
    check($migrator->migrate() === [] && Post::count() === 1, 'migration preserves data');
    check(Post::destroy($id) && !Post::destroy($id), 'destroy');
    $seed = require $root . '/database/seed.php';
    check($seed() === 2 && $seed() === 0, 'idempotent seed');
    check(Post::count() === 2, 'seed count');

    // Manifest and generic page loaders: independent of application-specific code.
    fixture($temp . '/build/.vite/manifest.json', json_encode([
        'app/client.jsx' => ['file' => 'assets/client.js', 'imports' => ['shared'], 'css' => ['assets/app.css']],
        'shared' => ['file' => 'assets/shared.js', 'css' => ['assets/shared.css', 'assets/app.css']],
    ]));
    $assets = new Assets($temp . '/build');
    $tags = $assets->tags();
    check(str_contains($tags, '/build/assets/client.js'), 'manifest entry');
    check(str_contains($tags, '/build/assets/shared.css'), 'imported CSS');
    check(substr_count($tags, '/build/assets/app.css') === 1, 'deduplicated CSS');
    $dev = (new Assets($temp . '/build', 'http://127.0.0.1:5173'))->tags();
    check(str_contains($dev, '/build/@react-refresh') && str_contains($dev, '/build/@vite/client'), 'HMR preamble/client');
    $branded = new Application($pages, $assets, document: [
        'title' => 'Example <app>', 'favicon' => '/icon.svg?name="quoted"',
        'appleTouchIcon' => '/touch.png',
    ]);
    $brandedHtml = $branded->handle(new Request('/', 'GET'))->body;
    check(str_contains($brandedHtml, '<title>Example &lt;app&gt;</title>'), 'document title escaped');
    check(str_contains($brandedHtml, 'href="/icon.svg?name=&quot;quoted&quot;"'), 'favicon URL escaped');
    check(str_contains($brandedHtml, 'rel="apple-touch-icon" href="/touch.png"'), 'touch icon metadata');
    fixture($temp . '/loaders/posts/[id].php', '<?php return fn($request, $params) => ["custom" => "loader:" . $params["id"]];');
    $generic = new Application($pages, $assets, str_repeat('a', 64));
    $genericResponse = $generic->handle(new Request('/posts/abc', 'GET'));
    check(pageData($genericResponse->body)['props']['custom'] === 'loader:abc', 'new loader without core changes');
    $unsafe = $generic->handle(new Request('/posts/%3C%2Fscript%3E%3Cscript%3Ealert(1)%3C%2Fscript%3E', 'GET'));
    check(!str_contains($unsafe->body, '</script><script>alert'), 'JSON escapes script markup');
    check(str_contains(pageData($unsafe->body)['props']['params']['id'], '<script>'), 'escaped props roundtrip');
    fixture($pages . '/api/custom.php', '<?php return fn($request, $params) => ["custom" => true];');
    $generic = new Application($pages, $assets, str_repeat('a', 64));
    check(json_decode($generic->handle(new Request('/api/custom', 'GET'))->body, true)['custom'], 'new API without core changes');

    // Real application: database, page loaders, API, validation and CSRF.
    $csrf = str_repeat('b', 64);
    $app = new Application($root . '/app/pages', $assets, $csrf);
    $send = static function (string $uri, string $method = 'GET', ?array $body = null, array $headers = []) use ($app, $csrf) {
        return $app->handle(new Request($uri, $method,
            $headers + ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf],
            $body === null ? '' : json_encode((object) $body, JSON_THROW_ON_ERROR)));
    };
    $home = $send('/');
    check($home->status === 200 && count(pageData($home->body)['props']['posts']) === 2, 'list loader');
    check(pageData($home->body)['props']['csrfToken'] === $csrf, 'page CSRF token');
    check($home->headers['Cache-Control'] === 'no-store', 'session HTML not cached');
    check($send('/posts/new')->status === 200, 'new page beats dynamic ID');
    check($send('/posts/999999')->status === 404, 'missing post page');
    check($send('/posts/999999/edit')->status === 404, 'missing edit page');
    check($send('/missing')->status === 404 && $send('/api/missing')->status === 404, '404 response');
    check($send('/', 'POST')->status === 405, 'page method rejected');
    check($send('/api/hello', 'POST')->status === 405, 'endpoint method rejected');
    check(isset(json_decode($send('/api/hello')->body, true)['time']), 'hello endpoint');
    check($send('/api/posts?page[]=1')->status === 400, 'invalid query shape');
    check($send('/api/posts?page=0')->status === 400, 'invalid page number');
    $blocked = $send('/api/posts', 'POST', ['title' => 'Blocked', 'content' => 'Blocked'], ['X-CSRF-Token' => '']);
    check($blocked->status === 403 && Post::count() === 2, 'missing CSRF blocks write');
    check($send('/api/posts', 'POST', ['title' => 'Blocked', 'content' => 'Blocked'], ['X-CSRF-Token' => 'wrong'])->status === 403, 'wrong CSRF rejected');
    check($send('/api/posts', 'POST', [], ['Content-Type' => 'text/plain'])->status === 415, 'API media type');
    check($app->handle(new Request('/api/posts', 'POST', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf], '{'))->status === 400, 'API malformed JSON');
    check($send('/api/posts', 'POST', ['title' => '', 'content' => ''])->status === 422, 'empty form');
    check($send('/api/posts', 'POST', ['title' => ['bad'], 'content' => 3])->status === 422, 'field types');
    check($send('/api/posts', 'POST', ['title' => str_repeat('ą', 201), 'content' => 'text'])->status === 422, 'Unicode title length');
    check($send('/api/posts', 'POST', ['title' => 'text', 'content' => str_repeat('x', 10001)])->status === 422, 'content length');
    check($send('/api/posts', 'POST', ['title' => 'text', 'content' => 'text', 'id' => 10])->status === 422, 'protected API field');
    check(Post::count() === 2, 'invalid payloads never written');

    $created = $send('/api/posts', 'POST', ['title' => '  CRUD wpis  ', 'content' => "Linia 1\nLinia 2"]);
    check($created->status === 201, 'POST creates');
    $createdPost = json_decode($created->body, true)['post'];
    $id = (string) $createdPost['id'];
    check($createdPost['title'] === 'CRUD wpis' && isset($created->headers['Location']), 'normalized title and location');
    check($send('/api/posts/' . $id)->status === 200, 'GET detail');
    $detail = $send('/posts/' . $id);
    check(pageData($detail->body)['props']['post']['title'] === 'CRUD wpis', 'detail loader');
    check($send('/posts/' . $id . '/edit')->status === 200, 'edit loader');
    check($send('/posts/' . $id, 'HEAD')->status === 200, 'HEAD route');
    $edit = $send('/api/posts/' . $id, 'PATCH', ['title' => 'Po edycji']);
    check($edit->status === 200 && Post::find($id)->content === "Linia 1\nLinia 2", 'PATCH retains unspecified fields');
    check($send('/api/posts/' . $id, 'PATCH', [])->status === 422, 'empty PATCH rejected');
    check($send('/api/posts/' . $id, 'PATCH', ['content' => ''])->status === 422, 'invalid PATCH rejected');
    check(Post::find($id)->title === 'Po edycji', 'invalid PATCH preserves row');
    Connection::disconnect();
    check(pageData($send('/posts/' . $id)->body)['props']['post']['title'] === 'Po edycji', 'page after reconnect');
    check($send('/api/posts/' . $id, 'PUT')->status === 405, 'unsupported method');
    $deleted = $send('/api/posts/' . $id, 'DELETE');
    check($deleted->status === 204 && $deleted->body === '', 'DELETE empty 204');
    check($send('/api/posts/' . $id)->status === 404 && $send('/posts/' . $id)->status === 404, 'deleted record unavailable');
    check($send('/api/posts/' . $id, 'DELETE')->status === 404, 'repeated delete 404');
    for ($i = 0; $i < 10; $i++) Post::create(['title' => 'Pagination ' . $i, 'content' => 'body']);
    $list = json_decode($send('/api/posts?page=2')->body, true);
    check(count($list['posts']) === 2 && $list['pagination']['total'] === 12, 'API pagination');
    check(count(pageData($send('/?page=2')->body)['props']['posts']) === 2, 'page pagination');
    $missingBuild = new Application($root . '/app/pages', new Assets($temp . '/absent'), $csrf);
    check($missingBuild->handle(new Request('/', 'GET'))->status === 503, 'missing build');
    check($missingBuild->handle(new Request('/api/hello', 'GET'))->status === 200, 'API independent of build');
    fixture($pages . '/api/failure.php', '<?php return function() { throw new RuntimeException("SECRET_INTERNAL_DETAIL"); };');
    $broken = new Application($pages, $assets, $csrf);
    $oldLog = ini_get('error_log');
    ini_set('error_log', $temp . '/errors.log');
    $failure = $broken->handle(new Request('/api/failure', 'GET'));
    ini_set('error_log', $oldLog);
    check($failure->status === 500 && !str_contains($failure->body, 'SECRET_INTERNAL_DETAIL'), 'exception details not exposed');
    check(str_contains(file_get_contents($temp . '/errors.log'), 'SECRET_INTERNAL_DETAIL'), 'internal error logged');
    echo "PASS: {$count} assertions ({$driver})\n";
} finally {
    Connection::configure(null);
    foreach (['GASKA_TEST_VALUE', 'GASKA_TEST_OVERRIDE', 'GASKA_TEST_EMPTY', 'GASKA_TEST_COMMENT'] as $key) putenv($key);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($temp);
}
