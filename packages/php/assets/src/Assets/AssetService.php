<?php
namespace ZoltaGaska\Assets;

use PDO;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Jobs\Lease;
use ZoltaGaska\Jobs\Queue;
use ZoltaGaska\Security\Identity;

final class AssetService
{
    public const CHUNK_BYTES = 262144;
    private const KINDS = ['model', 'poster'];
    private const URL_TTL = 300;
    private PDO $pdo;
    private \Closure $mayWrite;
    private \Closure $mayRead;

    public function __construct(private Queue $queue, private Storage $storage, private string $signingKey,
        callable $mayWrite, callable $mayRead, private int $projectByteBudget = 536870912, private int $maxPending = 16)
    {
        if ($projectByteBudget < 1 || $maxPending < 1) throw new \InvalidArgumentException('Invalid project upload quota.');
        if (strlen($signingKey) < 32) throw new \InvalidArgumentException('Asset signing key requires at least 32 bytes.');
        $this->pdo = $queue->connection();
        $this->mayWrite = \Closure::fromCallable($mayWrite);
        $this->mayRead = \Closure::fromCallable($mayRead);
    }

    private function writer(string $project, ?Identity $actor): Identity
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $project)) throw new HttpException(404, 'Unknown project.');
        if (!$actor || ($this->mayWrite)($actor, $project) !== true) throw new HttpException($actor ? 403 : 401, 'Upload access denied.');
        return $actor;
    }

    private function row(string $sql, array $params): ?array
    {
        $find = $this->pdo->prepare($sql);
        $find->execute($params);
        return $find->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function execute(string $sql, array $params): int
    {
        $write = $this->pdo->prepare($sql);
        $write->execute($params);
        return $write->rowCount();
    }

    public function begin(string $project, ?Identity $actor, array $input): array
    {
        $owner = $this->writer($project, $actor);
        foreach (['model' => GlbProfile::MAX_BYTES, 'poster' => ImageProfile::MAX_BYTES] as $kind => $limit) {
            $bytes = $input[$kind . 'Bytes'] ?? null;
            $sum = $input[$kind . 'Checksum'] ?? null;
            if (!is_int($bytes) || $bytes < 1 || $bytes > $limit || !is_string($sum) || !preg_match('/^[0-9a-f]{64}$/D', $sum)) {
                throw new HttpException(422, 'Invalid file size or SHA-256 checksum.', [$kind => ['Invalid size or checksum.']]);
            }
        }
        return $this->storage->locked('quota-' . strtolower($project), function () use ($project, $owner, $input) {
            // Charge pending sessions and worker tasks together. Expired bytes remain charged until pruning.
            $quota = $this->row("SELECT
                SUM(CASE WHEN u.finalized=0 OR v.state IN ('queued','processing') THEN 1 ELSE 0 END) AS pending,
                SUM(8192 + 2*u.model_bytes + u.poster_bytes + COALESCE(v.prepared_poster_bytes,?)) AS reserved
                FROM yg_uploads u LEFT JOIN yg_asset_versions v ON v.id=u.id WHERE u.project_id=?",
                [ImageProfile::MAX_BYTES, $project]);
            $reserve = 8192 + 2 * $input['modelBytes'] + $input['posterBytes'] + ImageProfile::MAX_BYTES;
            if ((int) $quota['pending'] >= $this->maxPending || (int) $quota['reserved'] + $reserve > $this->projectByteBudget) {
                throw new HttpException(429, 'Project upload quota reached.');
            }
            $id = bin2hex(random_bytes(16));
            $token = bin2hex(random_bytes(32));
            $this->execute('INSERT INTO yg_uploads
                (id,project_id,actor_id,token_hash,expires,model_bytes,model_checksum,poster_bytes,poster_checksum)
                VALUES (?,?,?,?,?,?,?,?,?)', [$id, $project, $owner->id, hash('sha256', $token), time() + 86400,
                    $input['modelBytes'], $input['modelChecksum'], $input['posterBytes'], $input['posterChecksum']]);
            return ['uploadId' => $id, 'token' => $token, 'offsets' => ['model' => 0, 'poster' => 0], 'chunkBytes' => self::CHUNK_BYTES];
        });
    }

    private function requireUploadId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new HttpException(404, 'Upload not found.');
    }

    private function session(string $project, string $id, ?Identity $actor, string $token): array
    {
        $actor = $this->writer($project, $actor);
        $row = $this->row('SELECT * FROM yg_uploads WHERE id=? AND project_id=?', [$id, $project]);
        if (!$row || $row['id'] !== $id || $row['project_id'] !== $project || $row['actor_id'] !== $actor->id || !hash_equals($row['token_hash'], hash('sha256', $token))) {
            throw new HttpException(404, 'Upload not found.');
        }
        if ((int) $row['expires'] <= time()) throw new HttpException(410, 'Upload expired.');
        return $row;
    }

    private function size(string $area, string $id, string $kind): int
    {
        return $this->storage->size("$area/$id.$kind") ?? 0;
    }

    public function progress(string $project, string $id, ?Identity $actor, string $token): array
    {
        $this->requireUploadId($id);
        return $this->storage->locked($id, function () use ($project, $id, $actor, $token) {
            $row = $this->session($project, $id, $actor, $token);
            $offsets = [];
            foreach (self::KINDS as $kind) {
                $offsets[$kind] = (int) $row['finalized'] ? (int) $row[$kind . '_bytes']
                    : max($this->size('staging', $id, $kind), $this->size('quarantine', $id, $kind));
            }
            return ['uploadId' => $id, 'offsets' => $offsets, 'chunkBytes' => self::CHUNK_BYTES, 'finalized' => (bool) $row['finalized']];
        });
    }

    public function append(string $project, string $id, ?Identity $actor, string $token, string $kind, int $offset, string $bytes): array
    {
        $this->requireUploadId($id);
        if (!in_array($kind, self::KINDS, true)) throw new HttpException(404, 'Unknown upload part.');
        return $this->storage->locked($id, function () use ($project, $id, $actor, $token, $kind, $offset, $bytes) {
            $row = $this->session($project, $id, $actor, $token);
            if ((int) $row['finalized'] || $this->storage->size("quarantine/$id.$kind") !== null) throw new HttpException(409, 'Upload is frozen.');
            $length = strlen($bytes);
            if ($length < 1 || $length > self::CHUNK_BYTES || $offset < 0 || $offset > (int) $row[$kind . '_bytes'] - $length) {
                throw new HttpException(413, 'Chunk outside declared byte limit.');
            }
            $this->storage->append("staging/$id.$kind", $offset, $bytes);
            return ['offset' => $offset + $length];
        });
    }

    private function verifyObject(string $key, int $size, string $checksum): void
    {
        if ($this->storage->size($key) !== $size) throw new \DomainException('File length mismatch.');
        $stream = $this->storage->open($key);
        try {
            $ctx = hash_init('sha256');
            $read = hash_update_stream($ctx, $stream);
            if ($read !== $size || !hash_equals($checksum, hash_final($ctx))) throw new \DomainException('File checksum mismatch.');
        } finally { fclose($stream); }
    }

    public function complete(string $project, string $id, ?Identity $actor, string $token): array
    {
        $this->requireUploadId($id);
        return $this->storage->locked($id, function () use ($project, $id, $actor, $token) {
            $row = $this->session($project, $id, $actor, $token);
            if (!(int) $row['finalized']) {
                // Freeze both verified byte sequences under the shared upload lock.
                // If SQL rolls back or PHP stops between two renames, resume from the frozen copy.
                foreach (self::KINDS as $kind) {
                    $frozen = "quarantine/$id.$kind";
                    $key = $this->storage->size($frozen) === null ? "staging/$id.$kind" : $frozen;
                    try { $this->verifyObject($key, (int) $row[$kind . '_bytes'], $row[$kind . '_checksum']); }
                    catch (\DomainException $error) { throw new HttpException(422, $error->getMessage(), [$kind => [$error->getMessage()]]); }
                }
                foreach (self::KINDS as $kind) $this->storage->freeze("staging/$id.$kind", "quarantine/$id.$kind");
                $this->pdo->beginTransaction();
                try {
                    $this->execute("INSERT INTO yg_asset_versions (id,project_id,state,checksum,model_bytes,created_at) VALUES (?,?,'queued',?,?,?)",
                        [$id, $project, $row['model_checksum'], $row['model_bytes'], (int) (microtime(true) * 1000000)]);
                    $this->queue->enqueue('assets.prepare:' . $id, 'assets.prepare', ['id' => $id]);
                    $this->execute('UPDATE yg_uploads SET finalized=1 WHERE id=?', [$id]);
                    $this->pdo->commit();
                } catch (\Throwable $error) {
                    $this->pdo->rollBack();
                    throw $error;
                }
            }
            $version = $this->row('SELECT * FROM yg_asset_versions WHERE id=?', [$id]);
            return $this->describe($version, $actor, ($this->mayRead)($actor, $project, $id) === true);
        });
    }

    private function checkedBytes(string $id, string $kind, int $size, int $limit, string $checksum): string
    {
        $key = "quarantine/$id.$kind";
        if ($size < 1 || $size > $limit || $this->storage->size($key) !== $size) throw new \DomainException('Frozen length mismatch.');
        $stream = $this->storage->open($key);
        try { $bytes = stream_get_contents($stream, $limit + 1); }
        finally { fclose($stream); }
        if ($bytes === false) throw new \RuntimeException('Cannot read frozen object.');
        if (strlen($bytes) !== $size || !hash_equals($checksum, hash('sha256', $bytes))) throw new \DomainException('Frozen checksum mismatch.');
        return $bytes;
    }

    public function process(array $payload, Lease $lease): void
    {
        $id = $payload['id'];
        $upload = $this->row('SELECT * FROM yg_uploads WHERE id=? AND finalized=1', [$id]);
        if (!$upload) throw new \RuntimeException('Missing frozen upload.');
        $this->execute("UPDATE yg_asset_versions SET state='processing' WHERE id=? AND state='queued'", [$id]);
        try {
            $bytes = $this->checkedBytes($id, 'model', (int) $upload['model_bytes'], GlbProfile::MAX_BYTES, $upload['model_checksum']);
            GlbProfile::validate($bytes);
            unset($bytes);
            $image = $this->checkedBytes($id, 'poster', (int) $upload['poster_bytes'], ImageProfile::MAX_BYTES, $upload['poster_checksum']);
            [$poster, $mime] = ImageProfile::poster($image);
            try {
                $model = $this->storage->open("quarantine/$id.model");
                try { $this->storage->install("ready/$id.model", $model); }
                finally { fclose($model); }
                $this->storage->install("ready/$id.poster", $poster);
            } finally { fclose($poster); }
            $this->verifyObject("ready/$id.model", (int) $upload['model_bytes'], $upload['model_checksum']);
            $posterBytes = $this->storage->size("ready/$id.poster");
            if (!$posterBytes || $posterBytes > ImageProfile::MAX_BYTES) throw new \DomainException('Invalid prepared poster size.');
            $lease->complete(fn () => $this->execute("UPDATE yg_asset_versions SET state='ready',poster_type=?,prepared_poster_bytes=?,failure=NULL WHERE id=?", [$mime, $posterBytes, $id]));
        } catch (\Throwable $error) {
            if (!$error instanceof \DomainException && !$lease->lastAttempt()) throw $error;
            $reason = $error instanceof \DomainException ? $error->getMessage() : 'Asset processing failed.';
            $lease->fail($reason, fn () => $this->execute("UPDATE yg_asset_versions SET state='failed',failure=? WHERE id=?", [$reason, $id]));
        }
    }

    public function failed(array $payload, string $reason): void
    {
        $this->execute("UPDATE yg_asset_versions SET state='failed',failure=? WHERE id=? AND state IN ('queued','processing')",
            [$reason, $payload['id']]);
    }

    public function prune(): int
    {
        $find = $this->pdo->prepare('SELECT id FROM yg_uploads WHERE finalized=0 AND expires<=? ORDER BY expires LIMIT 100');
        $find->execute([time()]);
        $count = 0;
        foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $count += $this->storage->locked($id, function () use ($id) {
                $row = $this->row('SELECT id FROM yg_uploads WHERE id=? AND finalized=0 AND expires<=?', [$id, time()]);
                if (!$row) return 0;
                foreach (self::KINDS as $kind) foreach (['staging', 'quarantine'] as $area) $this->storage->delete("$area/$id.$kind");
                return $this->execute('DELETE FROM yg_uploads WHERE id=? AND finalized=0', [$id]);
            });
        }
        return $count;
    }

    public function listVersions(string $projectId, Identity $editor): array
    {
        $this->writer($projectId, $editor);
        $find = $this->pdo->prepare('SELECT * FROM yg_asset_versions WHERE project_id=? ORDER BY created_at DESC,id DESC LIMIT 100');
        $find->execute([$projectId]);
        $items = [];
        foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['project_id'] !== $projectId) continue;
            $allowed = ($this->mayRead)($editor, $projectId, $row['id']) === true;
            if ($allowed) $items[] = $this->describe($row, $editor, true);
        }
        return $items;
    }

    public function descriptor(string $projectId, string $assetVersionId, ?Identity $viewer): ?array
    {
        $row = $this->row('SELECT * FROM yg_asset_versions WHERE id=? AND project_id=?', [$assetVersionId, $projectId]);
        if (!$row || $row['project_id'] !== $projectId || $row['id'] !== $assetVersionId || ($this->mayRead)($viewer, $projectId, $assetVersionId) !== true) return null;
        return $this->describe($row, $viewer, true);
    }

    private function signature(string $project, string $id, string $kind, int $exp, ?Identity $actor): string
    {
        return hash_hmac('sha256', json_encode([$project, $id, $kind, $exp, $actor?->id], JSON_THROW_ON_ERROR), $this->signingKey);
    }

    private function describe(array $row, ?Identity $viewer, bool $readable): array
    {
        $id = $row['id'];
        $project = $row['project_id'];
        $data = ['assetVersionId' => $id, 'projectId' => $project, 'status' => $row['state'],
            'modelUrl' => null, 'posterUrl' => null, 'checksum' => $row['checksum'], 'bytes' => (int) $row['model_bytes']];
        if ($readable && $row['state'] === 'ready') {
            $exp = time() + self::URL_TTL;
            foreach (self::KINDS as $kind) {
                $sig = $this->signature($project, $id, $kind, $exp, $viewer);
                $data[$kind . 'Url'] = "/api/assets/projects/$project/versions/$id/$kind?exp=$exp&sig=$sig";
            }
        }
        return $data;
    }

    public function download(string $project, string $id, string $kind, ?Identity $viewer, mixed $exp, mixed $sig, ?string $range): FileResponse
    {
        if (!in_array($kind, self::KINDS, true) || !is_string($exp) || !preg_match('/^[0-9]{1,12}$/D', $exp) || !is_string($sig)
            || (int) $exp <= time() || (int) $exp > time() + self::URL_TTL
            || !hash_equals($this->signature($project, $id, $kind, (int) $exp, $viewer), $sig)) throw new HttpException(403, 'Invalid or expired asset link.');
        $row = $this->row("SELECT * FROM yg_asset_versions WHERE id=? AND project_id=? AND state='ready'", [$id, $project]);
        if (!$row || $row['project_id'] !== $project || $row['id'] !== $id || ($this->mayRead)($viewer, $project, $id) !== true) throw new HttpException(404, 'Asset not available.');
        $key = "ready/$id.$kind";
        $size = $this->storage->size($key);
        if ($size === null) throw new \RuntimeException('Prepared asset is unavailable.');
        return new FileResponse($this->storage->open($key), $size, $kind === 'model' ? 'model/gltf-binary' : $row['poster_type'], $range);
    }
}
