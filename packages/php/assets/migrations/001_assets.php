<?php
return static function (PDO $pdo): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $uploads = $mysql ? ', INDEX yg_uploads_project (project_id), INDEX yg_uploads_expired (finalized,expires)' : '';
    $versions = $mysql ? ', INDEX yg_asset_project_time (project_id,created_at,id)' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS yg_uploads (
        id CHAR(32) PRIMARY KEY, project_id VARCHAR(128) NOT NULL, actor_id VARCHAR(190) NOT NULL,
        token_hash CHAR(64) NOT NULL, expires BIGINT NOT NULL, model_bytes BIGINT NOT NULL,
        model_checksum CHAR(64) NOT NULL, poster_bytes BIGINT NOT NULL, poster_checksum CHAR(64) NOT NULL,
        finalized INTEGER NOT NULL DEFAULT 0 $uploads
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS yg_asset_versions (
        id CHAR(32) PRIMARY KEY, project_id VARCHAR(128) NOT NULL, state VARCHAR(12) NOT NULL,
        checksum CHAR(64) NOT NULL, model_bytes BIGINT NOT NULL, created_at BIGINT NOT NULL, poster_type VARCHAR(16), prepared_poster_bytes BIGINT, failure VARCHAR(255) $versions
    )");
    if (!$mysql) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS yg_uploads_project ON yg_uploads (project_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS yg_uploads_expired ON yg_uploads (finalized,expires)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS yg_asset_project_time ON yg_asset_versions (project_id,created_at,id)');
    }
};
