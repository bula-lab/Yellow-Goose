<?php
namespace ZoltaGaska\Database;

use PDO;

final class Migrator
{
    public function __construct(private PDO $pdo, private string $directory, private array $sources = []) {}

    public function migrate(): array
    {
        if ($this->pdo->inTransaction()) throw new \LogicException('Migrations require their own connection boundary.');
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lock = null;
        $mysqlLock = null;
        if ($driver === 'mysql') {
            $database = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $mysqlLock = 'yg-migrations-' . substr(hash('sha256', (string) $database), 0, 40);
            $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
            $statement->execute([$mysqlLock]);
            if ((int) $statement->fetchColumn() !== 1) throw new \RuntimeException('Migration lock unavailable.');
        } elseif ($driver === 'sqlite') {
            $database = $this->pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
            $file = $database[0]['file'] ?: sys_get_temp_dir() . '/yg-memory-' . getmypid() . '-' . spl_object_id($this->pdo);
            $lock = fopen($file . '.migrations.lock', 'c');
            if ($lock === false) throw new \RuntimeException('Cannot open migration lock.');
            $deadline = microtime(true) + 5;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) { fclose($lock); throw new \RuntimeException('Migration lock unavailable.'); }
                usleep(10000);
            }
        } else throw new \InvalidArgumentException('Unsupported migration driver.');
        try { return $this->apply(); }
        finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            if ($mysqlLock !== null) {
                $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$mysqlLock]);
            }
        }
    }

    private function apply(): array
    {
        $files = [];
        // App basenames remain the exact keys used by pre-module deployments.
        foreach ($this->sources + ['app' => $this->directory] as $owner => $directory) {
            if (!preg_match('/^[a-z][a-z0-9-]*$/D', $owner)) throw new \InvalidArgumentException('Invalid migration owner.');
            if ($owner !== 'app' && !is_dir($directory)) throw new \InvalidArgumentException('Missing module migrations.');
            $paths = glob($directory . '/*.php') ?: [];
            sort($paths);
            foreach ($paths as $file) {
                $name = ($owner === 'app' ? '' : $owner . ':') . basename($file);
                if (strlen($name) > 190 || !preg_match('/^[a-zA-Z0-9_.:-]+$/D', $name)) throw new \InvalidArgumentException('Invalid migration ID.');
                if (isset($files[$name])) throw new \LogicException('Duplicate migration: ' . $name);
                $files[$name] = $file;
            }
        }
        if (isset($this->sources['app'])) throw new \InvalidArgumentException('App migration owner is reserved.');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');
        $applied = $this->pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $ran = [];
        foreach ($files as $name => $file) {
            if (in_array($name, $applied, true)) continue;
            $migration = require $file;
            if (!is_callable($migration)) throw new \RuntimeException('Invalid migration: ' . $name);
            // MySQL DDL commits implicitly; SQLite schema and history are atomic.
            $transactional = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            if ($transactional) $this->pdo->beginTransaction();
            try {
                $migration($this->pdo);
                $stmt = $this->pdo->prepare('INSERT INTO migrations (name, applied_at) VALUES (?, ?)');
                $stmt->execute([$name, gmdate('c')]);
                if ($transactional) $this->pdo->commit();
                $ran[] = $name;
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $error;
            }
        }
        return $ran;
    }
}
