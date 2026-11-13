<?php
require __DIR__ . '/../vendor/autoload.php';

use ZoltaGaska\Frontend\Assets;
use ZoltaGaska\Frontend\Renderer;
use ZoltaGaska\Http\Application;
use ZoltaGaska\Http\Request;

$count = 0;
function checkNavigation(bool $ok, string $message): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $count++;
}
$temp = sys_get_temp_dir() . '/gaska-navigation-' . bin2hex(random_bytes(8));
mkdir($temp);
$log = ini_get('error_log');
ini_set('error_log', $temp . '/errors.log');
try {
    mkdir($temp . '/pages');
    mkdir($temp . '/pages/api');
    mkdir($temp . '/loaders');
    file_put_contents($temp . '/pages/index.jsx', '');
    file_put_contents($temp . '/pages/redirect.jsx', '');
    file_put_contents($temp . '/pages/failure.jsx', '');
    file_put_contents($temp . '/pages/api/hello.php', '<?php return fn() => ["api" => true];');
    file_put_contents($temp . '/loaders/index.php', '<?php return fn($request) => ["value" => $request->query("value"), "csrfToken" => "forged", "params" => "forged"];');
    file_put_contents($temp . '/loaders/redirect.php', '<?php return fn() => new ZoltaGaska\\Http\\Response("", 302, ["Location" => "/?value=redirected"]);');
    file_put_contents($temp . '/loaders/failure.php', '<?php return function() { throw new RuntimeException("SECRET_LOADER_DETAIL"); };');
    $renderer = new class implements Renderer {
        public int $calls = 0;
        public function render(string $page, array $props): string { $this->calls++; throw new RuntimeException('SSR should not run for navigation'); }
    };
    $app = new Application($temp . '/pages', new Assets($temp, 'http://127.0.0.1:5173'),
        'real-csrf', renderer: $renderer, document: ['title' => 'Example <safe>']);
    $headers = ['X-YG-Navigation' => '1', 'X-YG-Version' => 'development'];
    $response = $app->handle(new Request('/?value=a%26b', 'GET', $headers));
    $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    checkNavigation($response->status === 200, 'page navigation succeeds');
    checkNavigation($response->headers['X-YG-Navigation'] === '1', 'response protocol marker');
    checkNavigation(str_contains($response->headers['Vary'], 'X-YG-Navigation'), 'HTML/JSON cache separation');
    checkNavigation($response->headers['Cache-Control'] === 'no-store', 'private props never cached');
    checkNavigation($data['page'] === 'index.jsx' && $data['url'] === '/?value=a%26b', 'page and query URL');
    checkNavigation($data['props']['value'] === 'a&b', 'loader uses request query');
    checkNavigation($data['props']['csrfToken'] === 'real-csrf' && $data['props']['params'] === [], 'framework props protected');
    checkNavigation($data['head']['title'] === 'Example <safe>', 'head metadata stays JSON data');
    checkNavigation($data['version'] === 'development' && $renderer->calls === 0, 'no SSR during navigation');
    $response = $app->handle(new Request('/', 'GET', ['X-YG-Navigation' => '1', 'X-YG-Version' => 'old']));
    checkNavigation($response->status === 409 && json_decode($response->body, true)['reload'], 'stale build forces full reload');
    $response = $app->handle(new Request('/redirect', 'GET', $headers));
    checkNavigation($response->status === 409 && json_decode($response->body, true)['location'] === '/?value=redirected', 'loader redirect becomes protocol redirect');
    checkNavigation($app->handle(new Request('/redirect', 'GET'))->status === 302, 'ordinary redirect remains HTTP');
    $response = $app->handle(new Request('/missing', 'GET', $headers));
    checkNavigation($response->status === 404 && isset(json_decode($response->body, true)['error']), 'unknown navigation returns JSON 404');
    $response = $app->handle(new Request('/failure', 'GET', $headers));
    checkNavigation($response->status === 500 && !str_contains($response->body, 'SECRET_LOADER_DETAIL'), 'loader failure sanitized');
    checkNavigation(str_contains(file_get_contents($temp . '/errors.log'), 'SECRET_LOADER_DETAIL'), 'loader failure logged');
    checkNavigation($app->handle(new Request('/', 'POST', $headers))->status === 405, 'navigation only reads pages');
    $api = $app->handle(new Request('/api/hello', 'GET', $headers));
    checkNavigation($api->body === '{"api":true}' && !isset($api->headers['X-YG-Navigation']), 'API protocol unchanged');
    checkNavigation($renderer->calls === 0, 'errors, API and redirects also skip SSR');
    checkNavigation($app->handle(new Request('/', 'GET'))->status === 500 && $renderer->calls === 1, 'document request still invokes SSR');
    mkdir($temp . '/.vite');
    file_put_contents($temp . '/.vite/manifest.json', '{"app/client.jsx":{"file":"client-v1.js"}}');
    $assets = new Assets($temp);
    $version = $assets->version();
    file_put_contents($temp . '/.vite/manifest.json', '{"app/client.jsx":{"file":"client-v2.js"}}');
    checkNavigation($version !== $assets->version(), 'manifest change invalidates version');
    echo "PASS: {$count} navigation assertions\n";
} finally {
    ini_set('error_log', $log);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($temp);
}
