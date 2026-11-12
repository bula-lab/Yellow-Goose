<?php
require __DIR__ . '/../vendor/autoload.php';

use ZoltaGaska\Frontend\Assets;
use ZoltaGaska\Frontend\NodeRenderer;
use ZoltaGaska\Http\Application;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;

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
    $bundle = $temp . '/server.mjs';
    file_put_contents($bundle, 'export function render(page, props) { return page + ":" + (props.text ?? "empty"); }');
    $renderer = new NodeRenderer($bundle);
    verify($renderer->render('index.jsx', []) === 'index.jsx:empty', 'empty props object');
    $large = str_repeat('ą', 50000);
    verify($renderer->render('page.jsx', ['text' => $large]) === 'page.jsx:' . $large, 'pipe backpressure and Unicode');
    rejects(fn () => $renderer->render('index.jsx', ['text' => str_repeat('a', 1048576)]), 'props exceed');
    $missing = rejects(fn () => (new NodeRenderer($temp . '/missing'))->render('index.jsx', []), 'Brak builda SSR');
    verify($missing instanceof HttpException && $missing->status === 503, 'missing build gives 503');

    file_put_contents($bundle, 'export function render() { throw new Error("SECRET_SSR_FAILURE"); }');
    rejects(fn () => $renderer->render('index.jsx', []), 'SECRET_SSR_FAILURE');
    file_put_contents($bundle, 'export function render() { return undefined; }');
    rejects(fn () => $renderer->render('index.jsx', []), 'must return HTML');
    file_put_contents($bundle, 'console.log("INVALID_PROTOCOL"); export function render() { return "hi"; }');
    $badJson = rejects(fn () => $renderer->render('index.jsx', []), 'Syntax error');
    verify($badJson instanceof JsonException, 'invalid transport response rejected');
    file_put_contents($bundle, 'export function render() { return "x".repeat(65536); }');
    rejects(fn () => (new NodeRenderer($bundle, maxOutput: 1024))->render('index.jsx', []), 'output limit');
    file_put_contents($bundle, 'console.error("x".repeat(65536)); export function render() { return "hi"; }');
    rejects(fn () => (new NodeRenderer($bundle, maxOutput: 1024))->render('index.jsx', []), 'output limit');
    file_put_contents($bundle, 'import fs from "node:fs"; fs.writeFileSync(' . json_encode($temp . '/pid')
        . ',String(process.pid)); export function render() { return new Promise(() => { setInterval(() => {}, 1000); }); }');
    $started = microtime(true);
    rejects(fn () => (new NodeRenderer($bundle, timeout: 0.4))->render('index.jsx', []), 'timed out');
    verify(microtime(true) - $started < 2, 'timeout bounded');
    if (function_exists('posix_kill') && is_file($temp . '/pid')) {
        verify(!@posix_kill((int) file_get_contents($temp . '/pid'), 0), 'timed-out Node process reaped');
    }

    // Exercise actual Application handling, loader props and error isolation.
    mkdir($temp . '/pages');
    mkdir($temp . '/pages/api');
    mkdir($temp . '/loaders');
    file_put_contents($temp . '/pages/index.jsx', '');
    file_put_contents($temp . '/pages/api/hello.php', '<?php return fn() => ["ok" => true];');
    file_put_contents($temp . '/loaders/index.php', '<?php return fn() => ["text" => "Loaded", "csrfToken" => "forged"];');
    $assets = new Assets($temp, 'http://127.0.0.1:5173');
    $app = new Application($temp . '/pages', $assets, 'real-token', renderer: $renderer);
    file_put_contents($bundle, 'export function render(page, props) { return "<h1>" + props.text + ":" + props.csrfToken + "</h1>"; }');
    $response = $app->handle(new Request('/', 'GET'));
    verify($response->status === 200 && str_contains($response->body, '<h1>Loaded:real-token</h1>'), 'loader and protected props reach SSR');
    verify(str_contains($response->body, 'data-render-mode="ssr"'), 'hydration marker');
    file_put_contents($bundle, 'export function render() { throw new Error("SECRET_SSR_FAILURE"); }');
    $response = $app->handle(new Request('/', 'GET'));
    verify($response->status === 500 && !str_contains($response->body, 'SECRET_SSR_FAILURE'), 'SSR error sanitized');
    verify(str_contains(file_get_contents($temp . '/errors.log'), 'SECRET_SSR_FAILURE'), 'SSR error logged');
    unlink($bundle);
    verify($app->handle(new Request('/', 'GET'))->status === 503, 'missing SSR bundle status through Application');
    verify($app->handle(new Request('/api/hello', 'GET'))->status === 200, 'API works without SSR bundle');
    echo "PASS: {$count} SSR assertions\n";
} finally {
    ini_set('error_log', $log);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($temp);
}
