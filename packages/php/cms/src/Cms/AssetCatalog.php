<?php
namespace ZoltaGaska\Cms;

use ZoltaGaska\Security\Identity;

interface AssetCatalog
{
    /** Resolve the version with the viewer's current access. URLs are issued by storage. */
    public function descriptor(string $projectId, string $assetVersionId, ?Identity $viewer): ?array;
}
