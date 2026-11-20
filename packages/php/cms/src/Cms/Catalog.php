<?php
namespace ZoltaGaska\Cms;

use PDO;
use PDOException;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Resources\QueryOptions;
use ZoltaGaska\Security\Identity;

final class Catalog
{
    private \Closure $canEdit;
    private ?\Closure $validatePublication;

    public function __construct(
        private PDO $db, callable $canEditProject, private ?AssetCatalog $assets = null, private bool $requireAsset = false,
        ?callable $validatePublication = null,
    ) {
        $this->canEdit = \Closure::fromCallable($canEditProject);
        $this->validatePublication = $validatePublication === null ? null : \Closure::fromCallable($validatePublication);
    }

    public function editor(string $projectId, ?Identity $viewer): Identity
    {
        if ($viewer === null || !$this->mayEdit($projectId, $viewer)) {
            throw new HttpException($viewer === null ? 401 : 403, 'Access denied.');
        }
        return $viewer;
    }

    public function mayEdit(string $projectId, ?Identity $viewer): bool
    {
        return $viewer !== null && ($this->canEdit)($viewer, $projectId) === true;
    }

    public function saveProduct(string $projectId, ?Identity $viewer, array $data, bool $existing): array
    {
        $editor = $this->editor($projectId, $viewer);
        CatalogInput::product($existing)->check($data);
        $id = $data['id'];
        $this->db->beginTransaction();
        try {
            if ($existing) {
                $revision = $this->advance($projectId, $id, $data['expectedRevision']);
            } else {
                $revision = 1;
                try {
                    $this->statement('INSERT INTO cms_products (project_id,id,revision,draft_revision) VALUES (?,?,1,1)', [$projectId, $id]);
                } catch (PDOException $error) {
                    if ($error->getCode() !== '23000') throw $error;
                    throw new HttpException(409, 'Product ID already exists.', ['id' => ['Choose another ID.']]);
                }
            }
            $this->readyAsset($projectId, $data['assetVersionId'], $editor);
            $materials = [];
            foreach ($data['materialIds'] as $materialId) {
                $material = $this->statement('SELECT id,title,color FROM cms_materials WHERE project_id=? AND id=?', [$projectId, $materialId])->fetch(PDO::FETCH_ASSOC);
                if (!$material) throw new HttpException(422, 'Unknown material.', ['materialIds' => ['Choose materials from this project.']]);
                $materials[] = $material;
            }
            $this->statement('INSERT INTO cms_product_revisions (project_id,id,revision,title,description,asset_version_id,materials_json)
                VALUES (?,?,?,?,?,?,?)', [$projectId, $id, $revision, trim($data['title']), $data['description'],
                $data['assetVersionId'], json_encode($materials, JSON_THROW_ON_ERROR)]);
            $this->statement('UPDATE cms_products SET draft_revision=? WHERE project_id=? AND id=?', [$revision, $projectId, $id]);
            $this->db->commit();
            return ['id' => $id, 'revision' => $revision, 'draftRevision' => $revision];
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function setPublication(string $projectId, ?Identity $viewer, array $data, bool $publish): array
    {
        $editor = $this->editor($projectId, $viewer);
        CatalogInput::publication()->check($data);
        $id = $data['id'];
        $this->db->beginTransaction();
        try {
            $revision = $this->advance($projectId, $id, $data['expectedRevision']);
            $published = null;
            if ($publish) {
                $draft = $this->snapshot($projectId, $id, true);
                if ($this->requireAsset && $draft['assetVersionId'] === null) {
                    throw new HttpException(422, 'A ready model and poster are required.', ['assetVersionId' => ['Select a ready version.']]);
                }
                $this->readyAsset($projectId, $draft['assetVersionId'], $editor);
                if ($this->validatePublication !== null) ($this->validatePublication)($draft);
                $published = $draft['draftRevision'];
            }
            $this->statement('UPDATE cms_products SET published_revision=? WHERE project_id=? AND id=?', [$published, $projectId, $id]);
            $this->db->commit();
            return ['id' => $id, 'revision' => $revision, 'publishedRevision' => $published];
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function saveMaterial(string $projectId, ?Identity $viewer, array $data, bool $existing): array
    {
        $this->editor($projectId, $viewer);
        CatalogInput::material($existing)->check($data);
        $row = ['id' => $data['id'], 'title' => trim($data['title']), 'color' => strtolower($data['color'])];
        try {
            if ($existing) {
                $affected = $this->statement('UPDATE cms_materials SET title=?,color=?,revision=revision+1 WHERE project_id=? AND id=? AND revision=?',
                    [$row['title'], $row['color'], $projectId, $row['id'], $data['expectedRevision']])->rowCount();
                if (!$affected) $this->conflict('cms_materials', $projectId, $row['id']);
            } else {
                $this->statement('INSERT INTO cms_materials (project_id,id,title,color,revision) VALUES (?,?,?,?,1)',
                    [$projectId, $row['id'], $row['title'], $row['color']]);
            }
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') throw $error;
            throw new HttpException(409, 'Material ID or color already exists in this project.');
        }
        return $row + ['revision' => $existing ? $data['expectedRevision'] + 1 : 1];
    }

    public function draft(string $projectId, string $id, ?Identity $viewer): array
    {
        $editor = $this->editor($projectId, $viewer);
        $draft = $this->snapshot($projectId, $id, true);
        // An editor must still be able to replace a version that later became unavailable.
        $draft['asset'] = $this->asset($projectId, $draft['assetVersionId'], $editor);
        return $draft;
    }

    public function published(string $projectId, string $id, ?Identity $viewer): array
    {
        $product = $this->snapshot($projectId, $id, false);
        $product['asset'] = $this->asset($projectId, $product['assetVersionId'], $viewer);
        if ($product['assetVersionId'] !== null && $product['asset'] === null) throw new HttpException(404, 'Product unavailable.');
        return $product;
    }

    /** A signing policy can consult this pointer without resolving or issuing another URL. */
    public function usesPublishedAsset(string $projectId, string $assetVersionId): bool
    {
        return (bool) $this->statement('SELECT 1 FROM cms_products p JOIN cms_product_revisions v
            ON v.project_id=p.project_id AND v.id=p.id AND v.revision=p.published_revision
            WHERE p.project_id=? AND v.asset_version_id=? LIMIT 1', [$projectId, $assetVersionId])->fetchColumn();
    }

    /** Public summaries never select the draft pointer or issue model URLs. */
    public function products(string $projectId, ?Identity $viewer, QueryOptions $options, bool $drafts = false): array
    {
        if ($drafts) $this->editor($projectId, $viewer);
        $pointer = $drafts ? 'p.draft_revision' : 'p.published_revision';
        $state = $drafts ? 'p.revision,p.draft_revision AS draftRevision,p.published_revision AS publishedRevision' : 'v.revision';
        return $this->page("SELECT v.id,v.title,v.description,$state FROM cms_products p JOIN cms_product_revisions v
            ON v.project_id=p.project_id AND v.id=p.id AND v.revision=$pointer WHERE p.project_id=?", $projectId, $options);
    }

    public function materials(string $projectId, ?Identity $viewer, QueryOptions $options): array
    {
        $this->editor($projectId, $viewer);
        return $this->page('SELECT id,title,color,revision FROM cms_materials WHERE project_id=?', $projectId, $options);
    }

    private function snapshot(string $projectId, string $id, bool $draft): array
    {
        $pointer = $draft ? 'p.draft_revision' : 'p.published_revision';
        $state = $draft ? 'p.revision,p.draft_revision AS draftRevision,p.published_revision AS publishedRevision' : 'v.revision';
        $row = $this->statement("SELECT v.id,v.title,v.description,v.asset_version_id AS assetVersionId,v.materials_json,$state
            FROM cms_products p JOIN cms_product_revisions v ON v.project_id=p.project_id AND v.id=p.id AND v.revision=$pointer
            WHERE p.project_id=? AND p.id=?", [$projectId, $id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new HttpException(404, 'Product unavailable.');
        $row['materials'] = json_decode($row['materials_json'], true, 16, JSON_THROW_ON_ERROR);
        unset($row['materials_json']);
        return $this->normalize($row);
    }

    private function asset(string $projectId, ?string $id, ?Identity $viewer): ?array
    {
        if ($id === null) return null;
        $asset = $this->assets?->descriptor($projectId, $id, $viewer);
        if (($asset['assetVersionId'] ?? null) !== $id || ($asset['projectId'] ?? null) !== $projectId
            || ($asset['status'] ?? null) !== 'ready' || !is_string($asset['modelUrl'] ?? null) || $asset['modelUrl'] === ''
            || !is_string($asset['posterUrl'] ?? null) || $asset['posterUrl'] === '') return null;
        return array_intersect_key($asset, array_flip(['assetVersionId','projectId','status','modelUrl','posterUrl','checksum','bytes']));
    }

    private function readyAsset(string $projectId, ?string $id, Identity $editor): void
    {
        if ($id !== null && $this->asset($projectId, $id, $editor) === null) {
            throw new HttpException(422, 'Asset unavailable.', ['assetVersionId' => ['Choose a ready version from this project.']]);
        }
    }

    private function advance(string $projectId, string $id, int $expected): int
    {
        // Acquire the write lock before reading either pointer; publishing and editing share the same counter.
        $changed = $this->statement('UPDATE cms_products SET revision=revision+1 WHERE project_id=? AND id=? AND revision=?',
            [$projectId, $id, $expected])->rowCount();
        if (!$changed) $this->conflict('cms_products', $projectId, $id);
        return $expected + 1;
    }

    private function conflict(string $table, string $projectId, string $id): never
    {
        $current = $this->statement("SELECT revision FROM $table WHERE project_id=? AND id=?", [$projectId, $id])->fetchColumn();
        if ($current === false) throw new HttpException(404, 'Resource unavailable.');
        throw new HttpException(409, 'Revision conflict.', ['expectedRevision' => ['Current revision: ' . (int) $current]],
            ['X-YG-Current-Revision' => (string) $current]);
    }

    private function page(string $source, string $projectId, QueryOptions $options): array
    {
        $sort = $options->sort ?? 'id';
        $direction = $options->direction;
        if (!in_array($sort, ['id','title'], true) || !in_array($direction, ['asc','desc'], true)
            || $options->limit < 1 || $options->limit > 100 || array_diff(array_keys($options->filters), ['title'])) {
            throw new HttpException(400, 'Invalid catalog query.');
        }
        $params = [$projectId];
        $where = [];
        if (isset($options->filters['title'])) {
            $where[] = "title LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($options->filters['title'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($options->cursor !== null) {
            $cursor = json_decode(base64_decode($options->cursor, true) ?: '', true);
            if (!is_array($cursor) || ($cursor[0] ?? null) !== $sort || ($cursor[1] ?? null) !== $direction
                || !is_string($cursor[2] ?? null) || !is_string($cursor[3] ?? null)) throw new HttpException(400, 'Invalid cursor.');
            $comparison = $direction === 'asc' ? '>' : '<';
            $where[] = "($sort $comparison ? OR ($sort=? AND id $comparison ?))";
            array_push($params, $cursor[2], $cursor[2], $cursor[3]);
        }
        $sql = "SELECT * FROM ($source) catalog" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY $sort $direction,id $direction LIMIT " . ($options->limit + 1);
        $rows = $this->statement($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $options->limit) {
            array_pop($rows);
            $last = end($rows);
            $next = base64_encode(json_encode([$sort, $direction, $last[$sort], $last['id']], JSON_THROW_ON_ERROR));
        }
        return ['items' => array_map([$this, 'normalize'], $rows), 'nextCursor' => $next];
    }

    private function normalize(array $row): array
    {
        foreach (['revision','draftRevision','publishedRevision'] as $key) if (isset($row[$key])) $row[$key] = (int) $row[$key];
        return $row;
    }

    private function statement(string $sql, array $params): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
}
