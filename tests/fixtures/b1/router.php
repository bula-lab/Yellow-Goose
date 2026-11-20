<?php
use ZoltaGaska\Frontend\{Assets,V8JsRenderer};
use ZoltaGaska\Http\{Application,Request,Response};
require getenv('B1_AUTOLOAD');
$r = Request::capture();
if (str_starts_with($r->uri, '/build/') && is_file(__DIR__ . '/public' . $r->uri)) return false;
if (in_array($r->uri, ['/box.glb','/poster-square.png'], true)) {
    header('Content-Type: ' . ($r->uri === '/box.glb' ? 'model/gltf-binary' : 'image/png'));
    readfile(__DIR__ . $r->uri); return;
}
if ($r->uri === '/api/table') {
    $items = [['id'=>'a','name'=>'Alpha'],['id'=>'b','name'=>'Bravo']];
    $filter = $r->query('filter', [])['name'] ?? '';
    if ($filter !== '') $items = array_values(array_filter($items, fn ($row) => stripos($row['name'], $filter) !== false));
    if ($r->query('direction') === 'desc') $items = array_reverse($items);
    $offset = (int) $r->query('cursor', '0');
    Response::json(['items' => array_slice($items, $offset, 1), 'nextCursor' => count($items) > $offset+1 ? (string) ($offset+1) : null])->send();
    return;
}
if ($r->uri === '/api/config') {
    $value = $r->json()['heightMm'];
    ($value === 2000 ? Response::json(['error'=>'Validation failed.','errors'=>['heightMm'=>['Unavailable height.']]],422)
        : Response::json(['heightMm' => $value]))->send(); return;
}
$app = new Application(__DIR__ . '/app/pages', new Assets(__DIR__ . '/public/build'), str_repeat('c', 32),
    renderer: new V8JsRenderer(__DIR__ . '/build/ssr/server.js', allowLegacyBundle: false));
$app->handle($r)->send($r->method === 'HEAD');
