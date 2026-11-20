<?php
return static function (PDO $pdo): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $indexes = $mysql ? ', INDEX yg_jobs_available (state,available_at), INDEX yg_jobs_expiring (state,lease_until)' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS yg_jobs (
        job_key VARCHAR(150) PRIMARY KEY, job_type VARCHAR(80) NOT NULL, payload TEXT NOT NULL,
        state VARCHAR(12) NOT NULL, available_at BIGINT NOT NULL, attempts INTEGER NOT NULL,
        lease_token VARCHAR(64), lease_until BIGINT, last_error VARCHAR(255)
        $indexes
    )");
    if (!$mysql) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS yg_jobs_available ON yg_jobs (state,available_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS yg_jobs_expiring ON yg_jobs (state,lease_until)');
    }
};
