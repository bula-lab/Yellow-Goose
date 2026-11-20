<?php
namespace Garage;

use PDO;
use ZoltaGaska\Assets\AssetModule;
use ZoltaGaska\Assets\AssetService;
use ZoltaGaska\Assets\LocalStorage;
use ZoltaGaska\Cms\AssetCatalog;
use ZoltaGaska\Jobs\JobsModule;
use ZoltaGaska\Jobs\Queue;
use ZoltaGaska\Security\Identity;
use ZoltaGaska\Security\SessionAuthenticator;

final class AssetIntegration
{
    private function __construct(
        private AssetService $assets,
        private AssetCatalog $catalog,
        private array $modules,
    ) {}

    public static function connect(
        PDO $pdo,
        SessionAuthenticator $auth,
        callable $canEditProject,
        callable $usesPublishedAsset,
        string $storage,
        string $signingKey,
    ): self {
        if (strlen($signingKey) < 32) throw new \InvalidArgumentException('GARAGE_SIGNING_KEY must contain at least 32 bytes.');
        $files = new LocalStorage($storage);
        $resolvedStorage = realpath($storage);
        $public = realpath(dirname(__DIR__, 2) . '/public');
        if ($resolvedStorage === false || ($public !== false && ($resolvedStorage === $public
            || str_starts_with($resolvedStorage, $public . DIRECTORY_SEPARATOR)))) {
            throw new \InvalidArgumentException('Asset storage must be outside the public directory.');
        }
        $queue = new Queue($pdo);
        $mayWrite = static fn (Identity $editor, string $project): bool => $canEditProject($editor, $project) === true;
        $mayRead = static fn (?Identity $viewer, string $project, string $version): bool =>
            ($viewer !== null && $canEditProject($viewer, $project) === true)
            || $usesPublishedAsset($project, $version) === true;
        $assets = new AssetService($queue, $files, $signingKey, $mayWrite, $mayRead);
        $catalog = new class($assets) implements AssetCatalog {
            public function __construct(private AssetService $assets) {}

            public function descriptor(string $projectId, string $assetVersionId, ?Identity $viewer): ?array
            {
                return $this->assets->descriptor($projectId, $assetVersionId, $viewer);
            }
        };
        return new self($assets, $catalog, [
            new JobsModule($queue, ['assets.prepare' => [$assets, 'process']], ['assets.prepare' => [$assets, 'failed']]),
            new AssetModule($assets, $auth),
        ]);
    }

    public function catalog(): AssetCatalog { return $this->catalog; }
    public function modules(): array { return $this->modules; }

    public function listForEditor(string $projectId, Identity $editor): array
    {
        return $this->assets->listVersions($projectId, $editor);
    }
}
