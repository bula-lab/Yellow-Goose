<?php
namespace ZoltaGaska\Cms;

use ZoltaGaska\Http\Request;
use ZoltaGaska\Module\{Module, Registry};
use ZoltaGaska\Resources\{Action, ResourceDefinition, ResourceModule};
use ZoltaGaska\Security\{Identity, IdentityProvider};

final class CmsModule implements Module
{
    public const PRODUCT_FIELDS = [
        'id' => ['type' => 'string', 'label' => 'Product ID', 'required' => true, 'maxLength' => 64],
        'title' => ['type' => 'string', 'label' => 'Product name', 'required' => true, 'maxLength' => 160],
        'description' => ['type' => 'string', 'label' => 'Description', 'required' => true, 'maxLength' => 4000],
    ];
    public const MATERIAL_FIELDS = [
        'id' => ['type' => 'string', 'label' => 'Material ID', 'required' => true, 'maxLength' => 64],
        'title' => ['type' => 'string', 'label' => 'Material name', 'required' => true, 'maxLength' => 160],
        'color' => ['type' => 'string', 'label' => 'Color (#rrggbb)', 'required' => true, 'maxLength' => 7],
    ];

    private function __construct(private Catalog $catalog, private string $projectId, private IdentityProvider $auth) {}

    /** One explicitly scoped catalog per application registry. The browser cannot select its project. */
    public static function forProject(Catalog $catalog, string $projectId, IdentityProvider $auth): array
    {
        $public = new ResourceDefinition('offers', 'cms.public', self::PRODUCT_FIELDS + ['revision' => ['type' => 'integer']],
            fn ($options, $viewer) => $catalog->products($projectId, $viewer, $options), sortable: ['id','title'], filterable: ['title']);
        $products = new ResourceDefinition('products', 'cms.edit', self::PRODUCT_FIELDS + [
            'revision' => ['type' => 'integer'], 'draftRevision' => ['type' => 'integer'], 'publishedRevision' => ['type' => 'integer'],
        ], fn ($options, $viewer) => $catalog->products($projectId, $viewer, $options, true), [
            new Action('create', 'cms.edit', CatalogInput::product(false), fn ($data, $viewer) => $catalog->saveProduct($projectId, $viewer, $data, false)),
            new Action('save', 'cms.edit', CatalogInput::product(true), fn ($data, $viewer) => $catalog->saveProduct($projectId, $viewer, $data, true)),
            new Action('publish', 'cms.edit', CatalogInput::publication(), fn ($data, $viewer) => $catalog->setPublication($projectId, $viewer, $data, true)),
            new Action('unpublish', 'cms.edit', CatalogInput::publication(), fn ($data, $viewer) => $catalog->setPublication($projectId, $viewer, $data, false)),
        ], ['id','title'], ['title']);
        $materials = new ResourceDefinition('materials', 'cms.edit', self::MATERIAL_FIELDS + ['revision' => ['type' => 'integer']],
            fn ($options, $viewer) => $catalog->materials($projectId, $viewer, $options), [
                new Action('create', 'cms.edit', CatalogInput::material(), fn ($data, $viewer) => $catalog->saveMaterial($projectId, $viewer, $data, false)),
                new Action('save', 'cms.edit', CatalogInput::material(true), fn ($data, $viewer) => $catalog->saveMaterial($projectId, $viewer, $data, true)),
            ], ['id','title'], ['title']);
        return [new self($catalog, $projectId, $auth), new ResourceModule($public, $auth, ['cms']),
            new ResourceModule($products, $auth, ['cms']), new ResourceModule($materials, $auth, ['cms'])];
    }

    public function id(): string { return 'cms'; }
    public function dependencies(): array { return []; }

    public function register(Registry $registry): void
    {
        $registry->migrations(dirname(__DIR__, 2) . '/migrations');
        $registry->policy('cms.public', static fn () => true);
        $registry->policy('cms.edit', fn (?Identity $viewer) => $this->catalog->mayEdit($this->projectId, $viewer));
        $registry->route('cms.offer', 'GET', '/api/cms', '/offers/{id}', fn (Request $request, $params) =>
            $this->catalog->published($this->projectId, $params['id'], $this->auth->identity($request)), ['cms.public']);
        $registry->route('cms.preview', 'GET', '/api/cms', '/preview/{id}', fn (Request $request, $params) =>
            $this->catalog->draft($this->projectId, $params['id'], $this->auth->identity($request)), ['cms.edit']);
    }
}
