<?php

namespace ZoltaGaska\Database;

use PDO;
use PDOException;

class Connection
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            // Załaduj konfigurację. W prawdziwej aplikacji byłby tu bardziej zaawansowany system.
            $config = require __DIR__ . '/../../config/database.php';
            
            $default = $config['default'];
            $connectionConfig = $config['connections'][$default];

            $driver = $connectionConfig['driver'];
            $host = $connectionConfig['host'];
            $db = $connectionConfig['database'];
            $user = $connectionConfig['username'];
            $pass = $connectionConfig['password'];
            $charset = $connectionConfig['charset'];

            $dsn = "{$driver}:host={$host};dbname={$db};charset={$charset}";
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                // W prawdziwej aplikacji, obsługa błędów byłaby bardziej rozbudowana
                throw new PDOException($e->getMessage(), (int)$e->getCode());
            }
        }

        return self::$instance;
    }
}
