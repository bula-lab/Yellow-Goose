<?php
namespace Garage;

use PDO;
use ZoltaGaska\Cms\{Catalog, CmsModule};
use ZoltaGaska\Database\{Connection, Migrator};
use ZoltaGaska\Frontend\{Assets, PageData, V8JsRenderer};
use ZoltaGaska\Http\{Application, HttpException, Kernel, Request, Response};
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Resources\QueryOptions;
use ZoltaGaska\Security\{CsrfGuard, Gate, RequestAuthorizer, Session, SessionAuthenticator};

final class Site
{
    public const PROJECT = 'garage-demo';
    public readonly PDO $db;
    public readonly Catalog $catalog;
    public readonly SessionAuthenticator $auth;
    public readonly Registry $registry;
    private AssetIntegration $assets;
    private Kernel $kernel;
    private string $sessions;

    public function __construct(private string $root, string $data, string $signingKey)
    {
        $data = realpath($data);
        $public = realpath($root . '/public');
        if (!$data || $data === $public || ($public && str_starts_with($data, $public . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('Initialize GARAGE_DATA_DIR outside the public directory.');
        }
        $this->sessions = $data . '/sessions';
        if (!getenv('GASKA_SESSION_PATH')) session_save_path($this->sessions);
        Connection::configure(['default' => 'garage', 'connections' => ['garage' => ['driver' => 'sqlite', 'database' => $data . '/garage.sqlite']]]);
        $this->db = Connection::getInstance();
        $accounts = new Accounts($this->db);
        $this->auth = new SessionAuthenticator($accounts);
        $this->assets = AssetIntegration::connect($this->db, $this->auth, [$accounts, 'canEdit'],
            fn ($project, $version) => $this->catalog->usesPublishedAsset($project, $version), $data . '/files', $signingKey);
        $this->catalog = new Catalog($this->db, [$accounts, 'canEdit'], $this->assets->catalog(), requireAsset: true,
            validatePublication: static fn ($draft) => DemoRules::initial($draft));
        $this->registry = Registry::boot([...CmsModule::forProject($this->catalog, self::PROJECT, $this->auth),
            new GarageModule($this->catalog, $this->auth, $accounts, new Enquiries($this->db)), ...$this->assets->modules()]);
        $this->kernel = new Kernel($this->registry, new RequestAuthorizer($this->auth, new Gate($this->registry->policies()),
            new CsrfGuard([Session::class, 'csrfToken'])));
    }

    public function handle(Request $request): Response
    {
        $bridge = new Application($this->root . '/app/pages', new Assets($this->root . '/public/build'), Session::csrfToken(),
            renderer: getenv('GASKA_SSR') === '0' ? null : new V8JsRenderer($this->root . '/build/ssr/server.js', allowLegacyBundle: false),
            document: ['title' => 'Garage studio'], moduleKernel: $this->kernel, loaderContext: $this);
        $response = $bridge->handle($request);
        // The assets module streams files itself. Documents and JSON carry per-session props.
        if ($response::class !== Response::class) return $response;
        $vary = trim(($response->headers['Vary'] ?? '') . ', Cookie', ', ');
        return new Response($response->body, $response->status, ['Cache-Control' => 'private, no-store', 'Vary' => $vary] + $response->headers);
    }

    public function migrate(): array
    {
        if (!is_dir($this->sessions) && !mkdir($this->sessions, 0700) && !is_dir($this->sessions)) {
            throw new \RuntimeException('Cannot initialize private garage sessions.');
        }
        return (new Migrator($this->db, $this->root . '/migrations', $this->registry->migrationDirectories()))->migrate();
    }

    public function seed(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72) throw new \InvalidArgumentException('GARAGE_EDITOR_PASSWORD must contain 12–72 bytes.');
        $this->db->prepare("INSERT INTO garage_accounts (id,login,password_hash,project_id,role,enabled)
            VALUES ('demo-editor','editor',?,?,'editor',1) ON CONFLICT(id) DO NOTHING")
            ->execute([password_hash($password, PASSWORD_DEFAULT), self::PROJECT]);
        $editor = (new Accounts($this->db))->findById('demo-editor')?->identity;
        foreach (DemoRules::MATERIALS as $material) {
            $exists = $this->db->prepare('SELECT 1 FROM cms_materials WHERE project_id=? AND id=?');
            $exists->execute([self::PROJECT, $material['id']]);
            if (!$exists->fetchColumn()) $this->catalog->saveMaterial(self::PROJECT, $editor, $material, false);
        }
    }

    public function home(Request $request): PageData
    {
        $options = QueryOptions::fromRequest($request, ['id','title'], ['title']);
        $listing = $this->catalog->products(self::PROJECT, null, $options);
        $next = $listing['nextCursor'] === null ? null : '/?' . http_build_query(['limit' => $options->limit,
            'sort' => $options->sort, 'direction' => $options->direction, 'filter' => $options->filters, 'cursor' => $listing['nextCursor']]);
        return new PageData(['listing' => $listing, 'filter' => $options->filters['title'] ?? '', 'nextUrl' => $next],
            'Garage studio | Catalog', 'Configure a garage from the published studio catalog.', '/');
    }

    public function login(): PageData { return new PageData([], 'Editor sign in | Garage studio'); }

    public function desk(Request $request): PageData
    {
        $viewer = $this->catalog->editor(self::PROJECT, $this->auth->identity($request));
        return new PageData(['listing' => $this->catalog->products(self::PROJECT, $viewer, new QueryOptions(25, null, null, 'asc', []), true),
            'fields' => CmsModule::PRODUCT_FIELDS], 'Products | Garage studio');
    }

    public function materialDesk(Request $request): PageData
    {
        $viewer = $this->catalog->editor(self::PROJECT, $this->auth->identity($request));
        return new PageData(['listing' => $this->catalog->materials(self::PROJECT, $viewer, new QueryOptions(25, null, null, 'asc', [])),
            'fields' => CmsModule::MATERIAL_FIELDS], 'Materials | Garage studio');
    }

    public function edit(Request $request, ?string $id): PageData
    {
        $editor = $this->catalog->editor(self::PROJECT, $this->auth->identity($request));
        $draft = $id === null ? null : $this->catalog->draft(self::PROJECT, $id, $editor);
        $materials = $this->catalog->materials(self::PROJECT, $editor, new QueryOptions(100, null, null, 'asc', []));
        $versions = $this->assets->listForEditor(self::PROJECT, $editor);
        if (isset($draft['asset']) && !in_array($draft['assetVersionId'], array_column($versions, 'assetVersionId'), true)) {
            $versions[] = $draft['asset'];
        }
        return new PageData(['projectId' => self::PROJECT, 'fields' => CmsModule::PRODUCT_FIELDS, 'draft' => $draft,
            'materials' => $materials, 'assets' => $versions],
            ($draft ? 'Edit ' . $draft['title'] : 'New product') . ' | Garage studio');
    }

    public function offer(Request $request, string $id, bool $preview): PageData
    {
        $viewer = $this->auth->identity($request);
        $product = $preview ? $this->catalog->draft(self::PROJECT, $id, $viewer) : $this->catalog->published(self::PROJECT, $id, $viewer);
        $initial = $product['materials'] ? DemoRules::initial($product) : null;
        $panel = $request->query('panel', 'configure');
        if (!in_array($panel, ['configure','finishes'], true)) throw new HttpException(400, 'Unknown panel.');
        return new PageData(['projectId' => self::PROJECT, 'product' => $product, 'preview' => $preview, 'panel' => $panel,
            'details' => $request->query('dialog') === 'details', 'fields' => DemoRules::fields($product),
            'configuration' => $initial, 'exampleQuote' => $initial ? DemoRules::quote($product, $initial) : null],
            ($preview ? 'Preview | ' : '') . $product['title'] . ' | Garage studio', $product['description'],
            $preview ? null : '/garages/' . rawurlencode($product['id']));
    }
}
