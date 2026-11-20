<?php
namespace ZoltaGaska\Jobs;

use PDO;

final class Lease
{
    private bool $settled = false;

    public function __construct(private PDO $pdo, public readonly string $key, public readonly string $type,
        public readonly array $payload, private string $token, public readonly int $attempt, private int $maxAttempts, public readonly bool $exhausted) {}

    public function settled(): bool { return $this->settled; }
    public function lastAttempt(): bool { return $this->attempt >= $this->maxAttempts; }
    public function complete(?callable $effect = null): void { $this->settle('done', null, $effect); }
    public function fail(string $reason, ?callable $effect = null): void { $this->settle('failed', $reason, $effect); }

    public function retry(\Throwable $error): void
    {
        $this->settle($this->lastAttempt() ? 'failed' : 'queued', get_class($error), null);
    }

    /** The lease CAS and the domain result share a transaction. A stale worker cannot publish. */
    private function settle(string $state, ?string $reason, ?callable $effect): void
    {
        if ($this->settled) throw new \LogicException('Job already settled.');
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare("UPDATE yg_jobs SET state=?, last_error=?, lease_token=NULL,
                lease_until=NULL, available_at=? WHERE job_key=? AND state='running' AND lease_token=? AND lease_until>?");
            $now = time();
            $update->execute([$state, $reason === null ? null : substr($reason, 0, 255),
                $now + min(60, 1 << min(6, $this->attempt - 1)), $this->key, $this->token, $now]);
            if (!$update->rowCount()) throw new StaleLease('Worker lease is no longer current.');
            if ($effect !== null) $effect($this->pdo);
            $this->pdo->commit();
            $this->settled = true;
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }
}
