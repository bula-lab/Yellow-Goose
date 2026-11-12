<?php
namespace ZoltaGaska\Database;

use PDO;

final class Migrator
{
    public function __construct(private PDO $pdo, private string $directory) {}

    public function migrate(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');
        $applied = $this->pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->directory . '/*.php') ?: [];
        sort($files);
        $ran = [];
        foreach ($files as $file) {
            $name = basename($file);
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
