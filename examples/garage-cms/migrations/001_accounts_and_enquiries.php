<?php
return static function (PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS garage_accounts (
        id VARCHAR(64) PRIMARY KEY, login VARCHAR(120) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
        project_id VARCHAR(64) NOT NULL, role VARCHAR(24) NOT NULL, enabled INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS garage_enquiries (
        confirmation_id CHAR(32) PRIMARY KEY, project_id VARCHAR(64) NOT NULL, product_id VARCHAR(64) NOT NULL,
        product_revision INTEGER NOT NULL, email VARCHAR(254) NOT NULL, configuration_json TEXT NOT NULL,
        amount_minor INTEGER NOT NULL, currency CHAR(3) NOT NULL, created_at VARCHAR(32) NOT NULL
    )');
};
