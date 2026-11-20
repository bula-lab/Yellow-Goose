<?php
return static function (PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS cms_products (
        project_id VARCHAR(64) NOT NULL, id VARCHAR(64) NOT NULL,
        revision INTEGER NOT NULL, draft_revision INTEGER NOT NULL, published_revision INTEGER NULL,
        PRIMARY KEY (project_id, id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS cms_product_revisions (
        project_id VARCHAR(64) NOT NULL, id VARCHAR(64) NOT NULL, revision INTEGER NOT NULL,
        title VARCHAR(160) NOT NULL, description TEXT NOT NULL, asset_version_id VARCHAR(128) NULL,
        materials_json TEXT NOT NULL,
        PRIMARY KEY (project_id, id, revision),
        FOREIGN KEY (project_id, id) REFERENCES cms_products (project_id, id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS cms_materials (
        project_id VARCHAR(64) NOT NULL, id VARCHAR(64) NOT NULL,
        title VARCHAR(160) NOT NULL, color CHAR(7) NOT NULL, revision INTEGER NOT NULL,
        PRIMARY KEY (project_id, id), UNIQUE (project_id, color)
    )');
};
