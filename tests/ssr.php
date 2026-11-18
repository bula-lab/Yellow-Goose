<?php
require __DIR__ . '/../vendor/autoload.php';

use ZoltaGaska\Frontend\Assets;
use ZoltaGaska\Frontend\V8JsRenderer;
use ZoltaGaska\Http\Application;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;

if (!extension_loaded('v8js')) {
    throw new RuntimeException('SSR tests require ext-v8js. See docs/ssr.md for the Docker test environment.');
}
$count = 0;
function verify(bool $ok, string $message): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $count++;
}
function rejects(callable $fn, string $message): Throwable {
    try { $fn(); } catch (Throwable $error) {
        verify(str_contains($error->getMessage(), $message), $message . ': got ' . $error->getMessage());
        return $error;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}
$temp = sys_get_temp_dir() . '/gaska-ssr-' . bin2hex(random_bytes(8));
mkdir($temp);
$log = ini_get('error_log');
ini_set('error_log', $temp . '/errors.log');
try {
    $bundle = $temp . '/server.js';
    file_put_contents($bundle, 'var GaskaSSR = { render: function(page, props) { return page + ":" + (props.text || "empty"); }};');
    $renderer = new V8JsRenderer($bundle);
    verify($renderer->render('index.jsx', []) === 'index.jsx:empty', 'empty props object');
    rejects(fn () => (new V8JsRenderer($bundle, allowLegacyBundle: false))->render('index.jsx', []), 'Missing Yellow-Goose SSR ABI');
    file_put_contents($bundle, 'var GaskaSSR = { protocolVersion: 1, bundleAbiVersion: 99, render: function() { throw new Error("render was called"); }};');
    rejects(fn () => $renderer->render('index.jsx', []), 'Incompatible Yellow-Goose SSR ABI');
    file_put_contents($bundle, 'var GaskaSSR = { protocolVersion: 1, bundleAbiVersion: 1, render: function() { return "strict"; }};');
    verify((new V8JsRenderer($bundle, allowLegacyBundle: false))->render('page', []) === 'strict', 'strict ABI accepted');
    file_put_contents($bundle, 'var GaskaSSR = { render: function(page, props) { return page + ":" + (props.text || "empty"); }};');
    $large = str_repeat('ą', 50000);
    verify($renderer->render('page.jsx', ['text' => $large]) === 'page.jsx:' . $large, 'large Unicode props');
    $unsafe = "'\"\\</script>\n; throw new Error('injected');";
    verify($renderer->render('page.jsx', ['text' => $unsafe]) === 'page.jsx:' . $unsafe, 'props are data, not executable source');
    rejects(fn () => $renderer->render('index.jsx', ['text' => str_repeat('a', 1048576)]), 'props exceed');
    $missing = rejects(fn () => (new V8JsRenderer($temp . '/missing'))->render('index.jsx', []), 'Brak builda SSR');
    verify($missing instanceof HttpException && $missing->status === 503, 'missing build gives 503');
    rejects(fn () => new V8JsRenderer($bundle, timeoutMs: 0), 'Invalid SSR limits');

    file_put_contents($bundle, 'var counter = 0; var GaskaSSR = { render: function() { return String(++counter); }};');
    verify($renderer->render('page', []) === '1' && $renderer->render('page', []) === '1', 'fresh context for each request');
    file_put_contents($bundle, 'var GaskaSSR = { render: function() { return typeof process + ":" + typeof Buffer; }};');
    verify($renderer->render('page', []) === 'undefined:undefined', 'no Node runtime globals');
    file_put_contents($bundle, 'print("UNWANTED_OUTPUT"); var GaskaSSR = { render: function() { return "safe"; }};');
    ob_start();
    $html = $renderer->render('page', []);
    verify(ob_get_clean() === '' && $html === 'safe', 'script output cannot corrupt HTTP response');
    file_put_contents($bundle, 'var GaskaSSR = { render: function() { throw new Error("SECRET_SSR_FAILURE"); }};');
    rejects(fn () => $renderer->render('index.jsx', []), 'SECRET_SSR_FAILURE');
    file_put_contents($bundle, 'var GaskaSSR = { render: function() {} };');
    rejects(fn () => $renderer->render('index.jsx', []), 'must return HTML');
    file_put_contents($bundle, 'var GaskaSSR = { render: function() { return "x".repeat(65536); }};');
    rejects(fn () => (new V8JsRenderer($bundle, maxOutput: 1024))->render('index.jsx', []), 'output limit');
    file_put_contents($bundle, 'var GaskaSSR = { render: function() { while(true) {} }};');
    $started = microtime(true);
    try {
        (new V8JsRenderer($bundle, timeoutMs: 100))->render('index.jsx', []);
        throw new RuntimeException('Timeout was not enforced');
    } catch (V8JsTimeLimitException) {
        verify(microtime(true) - $started < 2, 'native execution timeout');
    }
    file_put_contents($bundle, 'var GaskaSSR = { render: function() { var a = []; while(true) a.push(new Array(10000).fill("memory")); }};');
    try {
        (new V8JsRenderer($bundle, memoryLimit: 8388608))->render('index.jsx', []);
        throw new RuntimeException('Memory limit was not enforced');
    } catch (V8JsMemoryLimitException) {
        verify(true, 'native memory limit');
    }

    mkdir($temp . '/pages');
    mkdir($temp . '/pages/api');
    mkdir($temp . '/loaders');
    file_put_contents($temp . '/pages/index.jsx', '');
    file_put_contents($temp . '/pages/api/hello.php', '<?php return fn() => ["ok" => true];');
    file_put_contents($temp . '/loaders/index.php', '<?php return fn() => ["text" => "Loaded", "csrfToken" => "forged"];');
    $assets = new Assets($temp, 'http://127.0.0.1:5173');
    $app = new Application($temp . '/pages', $assets, 'real-token', renderer: $renderer);
    file_put_contents($bundle, 'var GaskaSSR = { render: function(page, props) { return "<h1>" + props.text + ":" + props.csrfToken + "</h1>"; }};');
    $response = $app->handle(new Request('/', 'GET'));
    verify($response->status === 200 && str_contains($response->body, '<h1>Loaded:real-token</h1>'), 'loader and protected props reach SSR');
    verify(str_contains($response->body, 'data-render-mode="ssr"'), 'hydration marker');
    file_put_contents($bundle, 'var GaskaSSR = { render: function() { throw new Error("SECRET_SSR_FAILURE"); }};');
    $response = $app->handle(new Request('/', 'GET'));
    verify($response->status === 500 && !str_contains($response->body, 'SECRET_SSR_FAILURE'), 'SSR error sanitized');
    verify(str_contains(file_get_contents($temp . '/errors.log'), 'SECRET_SSR_FAILURE'), 'SSR error logged');
    $navigation = $app->handle(new Request('/?page=2', 'GET', ['X-YG-Navigation' => '1']));
    verify($navigation->status === 200 && json_decode($navigation->body, true)['url'] === '/?page=2', 'navigation skips broken SSR');
    unlink($bundle);
    verify($app->handle(new Request('/', 'GET'))->status === 503, 'missing bundle through Application');
    verify($app->handle(new Request('/api/hello', 'GET'))->status === 200, 'API works without SSR');
    verify($app->handle(new Request('/', 'GET', ['X-YG-Navigation' => '1']))->status === 200, 'navigation works without SSR bundle');

    $real = new V8JsRenderer(dirname(__DIR__) . '/build/ssr/server.js', allowLegacyBundle: false);
    $html = $real->render('posts/[id].jsx', ['post' => ['id' => 42, 'title' => '<unsafe>', 'content' => 'Treść', 'created_at' => '2026-11-13'], 'csrfToken' => 'test']);
    verify(str_contains($html, '<h1>&lt;unsafe&gt;</h1>') && str_contains($html, 'Treść'), 'actual self-contained React bundle');
    echo "PASS: {$count} native V8Js SSR assertions (no Node required)\n";
} finally {
    ini_set('error_log', $log);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($temp);
}
