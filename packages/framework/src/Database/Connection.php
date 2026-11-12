<?php
namespace ZoltaGaska\Database;

use PDO;

final class Connection
{
    private static ?PDO $instance = null;
    private static ?array $configuration = null;

    public static function configure(?array $configuration): void
    {
        self::$instance = null;
        self::$configuration = $configuration;
    }

    public static function disconnect(): void { self::$instance = null; }

    public static function dsn(array $config): string
    {
        $driver = $config['driver'] ?? '';
        if ($driver === 'sqlite') {
            if (!isset($config['database']) || $config['database'] === '') {
                throw new \InvalidArgumentException('SQLite database path is required.');
            }
            return 'sqlite:' . $config['database'];
        }
        if ($driver !== 'mysql') throw new \InvalidArgumentException('Unsupported database driver.');
        foreach (['host', 'database', 'charset'] as $field) {
            if (empty($config[$field]) || preg_match('/[;\x00-\x1f]/', $config[$field])) {
                throw new \InvalidArgumentException('Invalid MySQL configuration: ' . $field);
            }
        }
        $port = filter_var($config['port'] ?? 3306, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) throw new \InvalidArgumentException('Invalid database port.');
        return "mysql:host={$config['host']};port={$port};dbname={$config['database']};charset={$config['charset']}";
    }

    public static function getInstance(): PDO
    {
        if (self::$instance !== null) return self::$instance;
        $configuration = self::$configuration ?? throw new \RuntimeException('Database not configured. Call Runtime::boot() or Connection::configure().');
        $config = $configuration['connections'][$configuration['default']] ?? null;
        if (!is_array($config)) throw new \RuntimeException('Unknown database connection.');
        $pdo = new PDO(self::dsn($config), $config['username'] ?? null, $config['password'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if ($config['driver'] === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
        return self::$instance = $pdo;
    }
}
