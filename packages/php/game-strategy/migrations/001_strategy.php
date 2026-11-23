<?php
return static function (PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $options = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
    $db->exec("CREATE TABLE IF NOT EXISTS yg_strategy_calendar (calendar_id INTEGER PRIMARY KEY)$options");
    $db->exec('INSERT INTO yg_strategy_calendar(calendar_id) VALUES(1)' . ($mysql
        ? ' ON DUPLICATE KEY UPDATE calendar_id=calendar_id' : ' ON CONFLICT(calendar_id) DO NOTHING'));
    $db->exec("CREATE TABLE IF NOT EXISTS yg_strategy_seasons (
        season_id VARCHAR(64) PRIMARY KEY, starts_at BIGINT NOT NULL, ends_at BIGINT NOT NULL,
        ties VARCHAR(16) NOT NULL, generation BIGINT NOT NULL
    )$options");
    $tables = [
        'yg_strategy_games' => [
            'game_id CHAR(32) PRIMARY KEY, season_id VARCHAR(64) NOT NULL, actor_id VARCHAR(128) NOT NULL,
             display_name VARCHAR(120) NOT NULL, revision BIGINT NOT NULL, points BIGINT NOT NULL,
             resources BIGINT NOT NULL, checkpoint BIGINT NOT NULL, capacity BIGINT NOT NULL, per_second BIGINT NOT NULL,
             UNIQUE(season_id,actor_id), FOREIGN KEY(season_id) REFERENCES yg_strategy_seasons(season_id)',
            ['yg_strategy_ranking' => 'season_id,points DESC,actor_id ASC', 'yg_strategy_actor' => 'actor_id,season_id'],
        ],
        'yg_strategy_operations' => [
            'operation_id CHAR(32) PRIMARY KEY, game_id CHAR(32) NOT NULL, season_id VARCHAR(64) NOT NULL,
             target VARCHAR(64) NOT NULL, build_level BIGINT NOT NULL, started_at BIGINT NOT NULL, completes_at BIGINT NOT NULL,
             rules_version VARCHAR(64) NOT NULL, cost BIGINT NOT NULL, build_points BIGINT NOT NULL,
             reward_resources BIGINT NOT NULL, reward_points BIGINT NOT NULL, pending INTEGER NOT NULL, claimable INTEGER NOT NULL,
             UNIQUE(game_id,target,build_level), FOREIGN KEY(game_id) REFERENCES yg_strategy_games(game_id)',
            ['yg_strategy_due' => 'season_id,pending,completes_at,operation_id',
             'yg_strategy_game_due' => 'game_id,pending,completes_at,operation_id',
             'yg_strategy_rewards' => 'game_id,claimable,operation_id'],
        ],
        'yg_strategy_receipts' => [
            'game_id CHAR(32) NOT NULL, command_id VARCHAR(64) NOT NULL, command_hash CHAR(64) NOT NULL,
             revision BIGINT NOT NULL, PRIMARY KEY(game_id,command_id), FOREIGN KEY(game_id) REFERENCES yg_strategy_games(game_id)', []],
        'yg_strategy_events' => [
            'game_id CHAR(32) NOT NULL, revision BIGINT NOT NULL, event_type VARCHAR(64) NOT NULL, occurred_at BIGINT NOT NULL,
             payload TEXT NOT NULL, PRIMARY KEY(game_id,revision), FOREIGN KEY(game_id) REFERENCES yg_strategy_games(game_id)', []],
    ];
    foreach ($tables as $table => [$columns, $indexes]) {
        if ($mysql) foreach ($indexes as $name => $fields) $columns .= ", INDEX $name ($fields)";
        $db->exec("CREATE TABLE IF NOT EXISTS $table ($columns)$options");
        if (!$mysql) foreach ($indexes as $name => $fields) $db->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($fields)");
    }
};
