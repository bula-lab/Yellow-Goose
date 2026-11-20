<?php
$autoload = require dirname(__DIR__) . '/vendor/autoload.php';
$autoload->addPsr4('Garage\\', dirname(__DIR__) . '/examples/garage-cms/src/Garage');

use Garage\DemoRules;
use ZoltaGaska\Cms\{AssetCatalog, Catalog, CmsModule};
use ZoltaGaska\Database\Migrator;
use ZoltaGaska\Frontend\{Assets, PageData};
use ZoltaGaska\Http\{Application, HttpException, Kernel, Request};
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Resources\QueryOptions;
use ZoltaGaska\Security\{CsrfGuard, Gate, Identity, IdentityProvider, RequestAuthorizer};

$checks = 0;
function check(bool $ok, string $scenario): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $scenario);
    $checks++;
}
function rejects(int $status, callable $operation, string $scenario): void {
    try { $operation(); }
    catch (HttpException $exception) {
        check($exception->status === $status, $scenario . ' (got ' . $exception->status . ')');
        return;
    }
    throw new RuntimeException('Unexpected success: ' . $scenario);
}
$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$auth = new class implements IdentityProvider {
    public ?Identity $current = null;
    public function identity(Request $request): ?Identity { return $this->current; }
};
$assets = new class implements AssetCatalog {
    public array $records = [];
    public function descriptor(string $projectId, string $assetVersionId, ?Identity $viewer): ?array {
        return $this->records[$assetVersionId] ?? null;
    }
};
$editor = new Identity('writer');
$other = new Identity('other');
$projects = [$editor->id => 'a', $other->id => 'b'];
$catalog = new Catalog($db, static function (?Identity $viewer, string $project) use (&$projects): bool {
    return ($projects[$viewer?->id] ?? null) === $project;
}, $assets, true);
$registry = Registry::boot(CmsModule::forProject($catalog, 'a', $auth));
$migrator = new Migrator($db, __DIR__ . '/fixtures/b2', $registry->migrationDirectories());
check($migrator->migrate() === ['cms:001_catalog.php'] && $migrator->migrate() === [], 'owner migration explicitly runs once');
$csrf = str_repeat('t', 64);
$kernel = new Kernel($registry, new RequestAuthorizer($auth, new Gate($registry->policies()), new CsrfGuard(fn () => $csrf)));
$action = fn (string $action, array $data, string $token = '') => $kernel->handle(new Request('/api/resources/products/actions/' . $action,
    'POST', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $token], json_encode($data, JSON_THROW_ON_ERROR)));
$get = fn (string $path) => $kernel->handle(new Request($path, 'GET'));
$product = ['id' => 'example', 'title' => 'Text <b>only</b>', 'description' => 'An immutable draft.',
    'materialIds' => ['graphite','forest'], 'assetVersionId' => 'v1'];
foreach (DemoRules::MATERIALS as $material) {
    $catalog->saveMaterial('a', $editor, $material, false);
    $catalog->saveMaterial('b', $other, $material, false);
}
$asset = ['assetVersionId' => 'v1', 'projectId' => 'a', 'status' => 'ready', 'modelUrl' => '/model', 'posterUrl' => '/poster',
    'checksum' => str_repeat('a',64), 'bytes' => 123, 'internal' => 'never serialize'];
check($action('create', $product, $csrf)->status === 401, 'valid token cannot supply missing identity');
$auth->current = $editor;
check($action('create', $product)->status === 403, 'mutation requires current token');
check($action('create', $product + ['role' => 'editor'], $csrf)->status === 422, 'unknown write fields rejected');
foreach ([['projectId' => 'b'], ['assetVersionId' => 'different'], ['status' => 'queued'], ['status' => 'failed'], ['posterUrl' => null]] as $invalid) {
    $assets->records['v1'] = $invalid + $asset;
    check($action('create', $product, $csrf)->status === 422, 'wrong project/version, unavailable state or absent poster denied');
}
$assets->records['v1'] = $asset;
check($action('create', $product, $csrf)->status === 200, 'first revision created after rejected attempts');
check($action('create', $product, $csrf)->status === 409, 'unique product id in project');
$catalog->saveProduct('b', $other, ['title' => 'Private other', 'assetVersionId' => null] + $product, false);
check(!$catalog->usesPublishedAsset('a','v1') && $get('/api/cms/offers/example')->status === 404, 'draft unaddressable on public endpoints');
$publish = ['id' => 'example', 'expectedRevision' => 1];
foreach (['queued','failed'] as $status) {
    $assets->records['v1']['status'] = $status;
    check($action('publish', $publish, $csrf)->status === 422, 'recheck readiness at publication');
}
$assets->records['v1'] = $asset;
check($action('publish', $publish, $csrf)->status === 200 && $catalog->usesPublishedAsset('a','v1'), 'published pointer permits current project version');
$published = $catalog->published('a', 'example', null);
check($published['revision'] === 1 && !isset($published['asset']['internal'], $published['draftRevision']), 'public revision and descriptor allowlist');
$assets->records['v1']['projectId'] = 'b';
rejects(404, fn () => $catalog->published('a', 'example', null), 'wrong owner cannot supply model URL on read');
$assets->records['v1'] = $asset;
$new = ['title' => 'Confidential draft', 'expectedRevision' => 2] + $product;
check($action('save', $new, $csrf)->status === 200, 'new draft created');
$conflict = $action('save', $new, $csrf);
check($conflict->status === 409 && $conflict->headers['X-YG-Current-Revision'] === '3'
    && str_contains($conflict->body, 'Current revision: 3'), 'two editors using same revision cannot overwrite');
$catalog->saveMaterial('a', $editor, ['id'=>'forest','title'=>'Recolored','color'=>'#abcdef','expectedRevision'=>1], true);
check($catalog->published('a', 'example', null) === $published, 'editing draft or material cannot change published text or finish snapshot');
check($get('/api/resources/products?filter[title]=Confidential')->status === 200, 'private resource query authorized');
check(!str_contains($get('/api/resources/offers?filter[title]=Confidential')->body, 'Confidential'), 'guest search cannot discover draft');
check($get('/api/resources/products?sort=project_id')->status === 400 && $get('/api/resources/offers?filter[table]=secret')->status === 400,
    'sorting and filtering limited to declared fields');
check($get('/api/resources/offers?cursor=bad')->status === 400, 'invalid cursor rejected');
$auth->current = $other;
check($get('/api/resources/products')->status === 403 && $get('/api/cms/preview/example')->status === 403, 'other project editor cannot read draft');
$auth->current = $editor;
unset($projects[$editor->id]);
check($get('/api/resources/products/schema')->status === 403 && $action('unpublish', ['id'=>'example','expectedRevision'=>3], $csrf)->status === 403,
    'revoked role applies to schema and commands');
rejects(403, fn () => $catalog->draft('a', 'example', $editor), 'role revocation applies to direct loader read');
$projects[$editor->id] = 'a';
$catalog->saveProduct('a', $editor, ['id'=>'next','title'=>'Zinc'] + $product, false);
$catalog->setPublication('a', $editor, ['id'=>'next','expectedRevision'=>1], true);
$first = $catalog->products('a', null, new QueryOptions(1,null,'title','asc',[]));
$next = $catalog->products('a', null, new QueryOptions(1,$first['nextCursor'],'title','asc',[]));
check($first['items'][0]['id'] === 'example' && $next['items'][0]['id'] === 'next' && $next['nextCursor'] === null, 'keyset pages use stable sorted IDs');
check($action('unpublish', ['id'=>'example','expectedRevision'=>3], $csrf)->status === 200 && $get('/api/cms/offers/example')->status === 404,
    'unpublish atomically hides detail');
check($catalog->usesPublishedAsset('a','v1'), 'another published product can still use the shared version');
$catalog->setPublication('a', $editor, ['id'=>'next','expectedRevision'=>2], false);
check(!$catalog->usesPublishedAsset('a','v1') && $catalog->products('a', null, new QueryOptions(25,null,null,'asc',[]))['items'] === [],
    'last unpublish withdraws signing permission and public list');
$config = ['widthMm'=>3500,'depthMm'=>6000,'heightMm'=>2130,'color'=>'#41634c'];
check(DemoRules::quote($published, $config)['amountMinor'] === 624500, 'deterministic amount 400000 + 84000 + 110000 + 12500 + 18000 grosze');
foreach ([['amountMinor'=>1]+$config, ['widthMm'=>'3500']+$config, ['color'=>'#ffffff']+$config, ['widthMm'=>2500]+$config] as $bad) {
    rejects(422, fn () => DemoRules::quote($published, $bad), 'forged amount, string geometry, unknown finish or forbidden combination');
}
$head = (object) ['title' => '</title><script>bad()</script>', 'description' => '" onload="bad()'];
$bridge = new Application(__DIR__ . '/fixtures/b2/head/pages', new Assets(__DIR__, 'http://localhost:5173'), $csrf, loaderContext: $head);
$document = $bridge->handle(new Request('/', 'GET'));
$nav = json_decode($bridge->handle(new Request('/', 'GET', ['X-YG-Navigation'=>'1']))->body, true, 32, JSON_THROW_ON_ERROR);
check(str_contains($document->body, '&lt;/title&gt;&lt;script&gt;') && str_contains($document->body, '&quot; onload=&quot;')
    && str_contains($document->body, '/offer?view=1&amp;v=2') && !str_contains($document->body, '<script>bad()'), 'HTML escapes title, description and canonical');
check($nav['head'] === ['title'=>$head->title,'description'=>$head->description,'canonical'=>'/offer?view=1&v=2']
    && $nav['props']['csrfToken'] === $csrf && $nav['props']['params'] === [], 'document/navigation share typed metadata, reserved props protected');
foreach (['javascript:bad()', '//other.test/path', "/\\other.test", 'https://login@example.test/path', "/path\nvalue"] as $invalid) {
    try { new PageData([], canonical: $invalid); throw new RuntimeException('Unsafe canonical accepted.'); }
    catch (InvalidArgumentException) { check(true, 'unsafe canonical rejected'); }
}
echo "PASS: $checks CMS domain, module, quote and page metadata assertions\n";
