<?php
return static function (PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $options = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
    $memberIndex = $mysql ? ', INDEX yg_scene_actor_projects (actor_id, project_id)' : '';
    $outboxIndex = $mysql ? ', INDEX yg_scene_pending_events (published_at, project_id, scene_id, revision)' : '';
    $text = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $db->exec("CREATE TABLE IF NOT EXISTS yg_scene_projects (
        project_id VARCHAR(64) PRIMARY KEY, name VARCHAR(160) NOT NULL
    )$options");
    $db->exec("CREATE TABLE IF NOT EXISTS yg_scene_members (
        project_id VARCHAR(64) NOT NULL, actor_id VARCHAR(128) NOT NULL, role VARCHAR(12) NOT NULL,
        PRIMARY KEY (project_id, actor_id), FOREIGN KEY (project_id) REFERENCES yg_scene_projects(project_id) $memberIndex
    )$options");
    $db->exec("CREATE TABLE IF NOT EXISTS yg_scenes (
        project_id VARCHAR(64) NOT NULL, scene_id VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL,
        revision BIGINT NOT NULL, document_json $text NOT NULL,
        PRIMARY KEY (project_id, scene_id), FOREIGN KEY (project_id) REFERENCES yg_scene_projects(project_id)
    )$options");
    $db->exec("CREATE TABLE IF NOT EXISTS yg_scene_receipts (
        project_id VARCHAR(64) NOT NULL, scene_id VARCHAR(64) NOT NULL, actor_id VARCHAR(128) NOT NULL,
        command_id VARCHAR(64) NOT NULL, operation_hash CHAR(64) NOT NULL, result_json $text NOT NULL,
        PRIMARY KEY (project_id, scene_id, actor_id, command_id),
        FOREIGN KEY (project_id, scene_id) REFERENCES yg_scenes(project_id, scene_id)
    )$options");
    $db->exec("CREATE TABLE IF NOT EXISTS yg_scene_outbox (
        project_id VARCHAR(64) NOT NULL, scene_id VARCHAR(64) NOT NULL, revision BIGINT NOT NULL,
        event_json $text NOT NULL, published_at VARCHAR(32) NULL,
        PRIMARY KEY (project_id, scene_id, revision),
        FOREIGN KEY (project_id, scene_id) REFERENCES yg_scenes(project_id, scene_id) $outboxIndex
    )$options");
    if (!$mysql) {
        $db->exec('CREATE INDEX IF NOT EXISTS yg_scene_actor_projects ON yg_scene_members(actor_id, project_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS yg_scene_pending_events ON yg_scene_outbox(published_at, project_id, scene_id, revision)');
    }
};
