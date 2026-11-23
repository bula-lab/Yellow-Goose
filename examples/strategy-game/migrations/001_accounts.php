<?php
return static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE colony_accounts (id VARCHAR(64) PRIMARY KEY, login VARCHAR(120) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1)');
};
