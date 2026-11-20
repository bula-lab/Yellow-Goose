# yellow-goose/assets

A PHP worker accepts a self-contained static GLB and a separately supplied PNG or JPEG poster. PHP 8.1+, PDO with SQLite or MySQL, and GD with PNG/JPEG support are required. The worker decodes and re-encodes the poster.

## Application setup

Configure `AssetService($queue, $storage, $signingKey, $mayWrite, $mayRead)`. Pass an instance of `LocalStorage` rooted outside the document root, or implement `Storage` with equivalent cross-process locking, atomic transfer, immutable installation and seekable reads. Give all worker and HTTP processes access to the same private files and database. Use a persistent random signing key of at least 32 bytes.

`$mayWrite(Identity $actor, string $projectId): bool` authorizes project uploads. `$mayRead(?Identity $viewer, string $projectId, string $assetVersionId): bool` resolves current object access. For an anonymous visitor the garage example checks a current published CMS revision. The application registers an `assets.write` route policy and passes a CSRF-aware authorizer to the Kernel.

Register the two modules and run the application's migrator. A bounded cron command such as the following processes one job with an explicit memory and CPU budget. Run `assets:prune` periodically to remove up to 100 abandoned uploads per invocation.

```php
$jobs = new JobsModule($queue, ['assets.prepare' => [$assets, 'process']],
    ['assets.prepare' => [$assets, 'failed']]);
$uploads = new AssetModule($assets, $identityProvider);
```

```sh
php -d memory_limit=192M -d max_execution_time=90 examples/garage-cms/console.php jobs:work 1
```

## Upload and read protocol

All paths below start with `/api/assets/projects/{project}`. Mutations require `X-CSRF-Token`. Upload sessions expire after 24 hours and bind an opaque capability to a single identity and project.

| Request | Input and result |
| --- | --- |
| `POST /uploads` | JSON with `modelBytes`, `posterBytes`, `modelChecksum`, `posterChecksum`. Checksums are lowercase hex SHA-256. Returns `uploadId`, `token`, `chunkBytes`, and offsets for both files. |
| `GET /uploads/{id}` | `X-Upload-Token` returns verified next offsets, including after a lost chunk acknowledgement. |
| `PUT /uploads/{id}/{model or poster}?offset=N` | `X-Upload-Token` and `application/octet-stream`. Append at the exact offset, up to 256 KiB per request. |
| `POST /uploads/{id}/complete` | `X-Upload-Token`. Check both sizes and checksums, transfer both files to private quarantine, and insert one version and one job in the same PDO transaction. Repeating completion returns that version. |
| `GET /versions` | Project editor receives up to 100 recent readable versions in `items`. |
| `GET /versions/{id}` | Current read policy gates metadata. |
| `GET` or `HEAD /versions/{id}/{model or poster}` | Signed expiry and actor plus current read policy. Supports single closed, open-ended and suffix byte ranges. Responses use private, no-store caching. |

The descriptor carries `assetVersionId`, `projectId`, `status`, `modelUrl`, `posterUrl`, `checksum` and `bytes`. Status progresses from queued through processing to ready or failed. URLs appear on readable ready versions and expire after five minutes. Revoking access prevents a subsequent read through an existing URL. The file controller can only open ready objects. A new upload always creates another immutable version.

The default project budget is 512 MiB and 16 simultaneous open or processing uploads, including queued jobs. The byte reservation covers staging/quarantine, a ready model, up to 4 MiB for the normalized poster, and 8 KiB per record. A completed job replaces the poster reservation with its actual length. Set the optional `projectByteBudget` and `maxPending` constructor arguments to change the limits. Frozen inputs and versions, including failed inspections, stay private for operator retention. Pruning expired unfinished sessions releases their reservation.

## Accepted GLB profile

`GlbProfile` validates one glTF 2.0 GLB with ordered JSON and BIN chunks, a single embedded buffer, a reachable scene tree, and triangle primitives. It checks referenced indices, aligned bounded buffers/strides, finite binary components, actual position bounds, optional unit normals and texture coordinates, materials, decoded embedded image dimensions, and composed world geometry. The first profile supports static TRS nodes, PBR base color or embedded PNG/JPEG textures. Compression, extensions, shaders, animations, skins, external URI references and matrix transforms are outside this profile. Validation runs inside PHP without invoking Node or downloading dependencies.

| Limit | Value |
| --- | --- |
| GLB and its JSON chunk | 16 MiB and 256 KiB |
| World-space envelope and scales | Conservative radius up to 1,000,000 m, composed scales from 0.000001 to 1,000,000 |
| Geometry | Up to 250,000 vertices and triangles, checked again for node instances |
| Structure | One scene, 512 nodes, depth 32, 256 meshes |
| Textures | Up to 8 embedded PNG/JPEG images, 2048×2048 each, 4,194,304 pixels in total |
| Poster | PNG or JPEG, 4 MiB, 2048×2048 |

The worker verifies the frozen checksums again, installs complete immutable outputs and marks the version ready in a fenced transaction. A later worker can finish promotion after an interrupted write. The file controller resolves private keys from authorized version IDs.
