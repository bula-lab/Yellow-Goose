# yellow-goose/cms

The CMS stores plain-text product descriptions, project materials and immutable
product revisions. Each application chooses a project, an identity provider and
an editor policy. The asset provider is optional.

```php
use ZoltaGaska\Cms\{Catalog, CmsModule};
use ZoltaGaska\Database\Migrator;
use ZoltaGaska\Http\Kernel;
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Security\{CsrfGuard, Gate, RequestAuthorizer, Session};

$catalog = new Catalog($pdo, $canEditProject);
$registry = Registry::boot(CmsModule::forProject($catalog, $projectId, $identities));
$kernel = new Kernel($registry, new RequestAuthorizer(
    $identities, new Gate($registry->policies()), new CsrfGuard([Session::class, 'csrfToken']),
));
// Run this explicitly on deployment.
(new Migrator($pdo, $appMigrations, $registry->migrationDirectories()))->migrate();
```

`$canEditProject(?Identity $viewer, string $projectId): bool` must check the
current account and its project membership. The application mounts one project
per registry. Private page loaders call `Catalog::draft()` or `Catalog::editor()`.
Public loaders call `Catalog::published()` and use React text children for content.
Session-bearing HTML, navigation JSON and API responses need `Cache-Control: private, no-store`.

| Resource | Query | Explicit POST actions under `/actions/` |
| --- | --- | --- |
| `/api/resources/offers` | Published snapshots | — |
| `/api/resources/products` | Current project's editorial drafts | `create`, `save`, `publish`, `unpublish` |
| `/api/resources/materials` | Current project's materials | `create`, `save` |

Each resource has a `/schema` route. Query parameters include `limit`, `cursor`,
`sort=id|title`, `direction=asc|desc` and `filter[title]`. Follow the returned
`nextCursor` using the same filter and ordering. Detail routes live at
`/api/cms/offers/{id}` and `/api/cms/preview/{id}`.

Create a draft with `id`, `title`, `description`, `assetVersionId` (nullable)
and an ordered `materialIds` list containing up to 32 IDs. Saving a draft
captures the selected materials' titles and colors. Changes to an existing
product or material require its current integer `expectedRevision`. Publish and
unpublish accept only `id` and `expectedRevision`. A stale write returns HTTP 409
with the current counter in `X-YG-Current-Revision`. Public readers see only
the revision selected by the publication pointer. Later editorial changes have
their own counter.

Implement `AssetCatalog::descriptor($projectId, $assetVersionId, $viewer)` to
attach assets. The catalog verifies the version ID, project, ready state and
both file URLs when linking and publishing a model. The asset provider checks
access before issuing URLs. Its public read policy can call
`Catalog::usesPublishedAsset()` to check the current publication pointer.
The garage example requires a ready model and at least one finish on publication.
