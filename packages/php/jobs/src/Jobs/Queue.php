<?php
namespace ZoltaGaska\Jobs;

use PDO;

final class Queue
{
    public function __construct(private PDO $pdo, private int $leaseSeconds = 120, private int $maxAttempts = 3)
    {
        if (!in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['sqlite','mysql'], true)
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException('Queue requires SQLite or MySQL PDO with exception error mode.');
        }
        if ($leaseSeconds < 1 || $maxAttempts < 1) throw new \InvalidArgumentException('Invalid job limits.');
    }

    public function connection(): PDO { return $this->pdo; }

    /** Use this connection for the enclosing domain transaction. */
    public function enqueue(string $key, string $type, array $payload): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,150}$/D', $key) || !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $type)) {
            throw new \InvalidArgumentException('Invalid job identity.');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR, 64);
        if (strlen($json) > 65535) throw new \InvalidArgumentException('Job payload exceeds 65535 bytes.');
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $conflict = $mysql
            ? ' ON DUPLICATE KEY UPDATE job_key = job_key' : ' ON CONFLICT(job_key) DO NOTHING';
        $insert = $this->pdo->prepare("INSERT INTO yg_jobs (job_key,job_type,payload,state,available_at,attempts)
            VALUES (?,?,?,'queued',?,0)" . $conflict);
        $insert->execute([$key, $type, $json, time()]);
        $find = $this->pdo->prepare('SELECT job_key,job_type,payload FROM yg_jobs WHERE job_key=?' . ($mysql ? ' FOR UPDATE' : ''));
        $find->execute([$key]);
        $job = $find->fetch(PDO::FETCH_ASSOC);
        if (!$job || $job['job_key'] !== $key || $job['job_type'] !== $type || $job['payload'] !== $json) {
            throw new \LogicException('Job key already used for another operation.');
        }
    }

    public function claim(): ?Lease
    {
        if ($this->pdo->inTransaction()) throw new \LogicException('Run workers outside the producer transaction.');
        $eligible = "((state='queued' AND available_at<=?) OR (state='running' AND lease_until<=?))";
        $find = $this->pdo->prepare("SELECT job_key,attempts FROM yg_jobs WHERE $eligible ORDER BY available_at,job_key LIMIT 1");
        $claim = $this->pdo->prepare("UPDATE yg_jobs SET state='running', lease_token=?, lease_until=?,
            attempts=CASE WHEN attempts<? THEN attempts+1 ELSE attempts END WHERE job_key=? AND attempts=? AND $eligible");
        while (true) {
            $now = time();
            $find->execute([$now, $now]);
            $candidate = $find->fetch(PDO::FETCH_ASSOC);
            $find->closeCursor();
            if (!$candidate) return null;
            $key = $candidate['job_key'];
            $previous = (int) $candidate['attempts'];
            $token = bin2hex(random_bytes(24));
            $claim->execute([$token, $now + $this->leaseSeconds, $this->maxAttempts, $key, $previous, $now, $now]);
            if (!$claim->rowCount()) continue;
            $read = $this->pdo->prepare('SELECT job_type,payload,attempts FROM yg_jobs WHERE job_key=? AND lease_token=?');
            $read->execute([$key, $token]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            if ($row) return new Lease($this->pdo, $key, $row['job_type'], json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR),
                $token, (int) $row['attempts'], $this->maxAttempts, $previous >= $this->maxAttempts);
        }
    }

    public function work(array $handlers, int $limit = 1, array $onFailure = []): int
    {
        if ($limit < 1 || $limit > 1000) throw new \InvalidArgumentException('Worker limit must be 1..1000.');
        $count = 0;
        while ($count < $limit && ($lease = $this->claim())) {
            $failureEffect = fn (string $reason) => isset($onFailure[$lease->type])
                ? fn () => $onFailure[$lease->type]($lease->payload, $reason) : null;
            try {
                if ($lease->exhausted) {
                    $reason = 'Worker lease expired on final attempt.';
                    $lease->fail($reason, $failureEffect($reason));
                } else {
                    $handler = $handlers[$lease->type] ?? throw new \RuntimeException('Unregistered job type.');
                    $handler($lease->payload, $lease);
                    if (!$lease->settled()) $lease->complete();
                }
            } catch (StaleLease) {
                // A new lease owns the outcome.
            } catch (\Throwable $error) {
                if (!$lease->settled()) {
                    try {
                        if ($lease->lastAttempt()) $lease->fail(get_class($error), $failureEffect(get_class($error)));
                        else $lease->retry($error);
                    } catch (StaleLease) { /* The expired owner cannot resolve the job. */ }
                }
            }
            ++$count;
        }
        return $count;
    }
}
