"""Competing migration processes must serialize and preserve app migration IDs."""
from pathlib import Path
import json
import os
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def main():
    with tempfile.TemporaryDirectory(prefix='yg-migration-race-') as tmp:
        directory = Path(tmp)
        for owner in ['app', 'test-base', 'test-child']:
            (directory / owner).mkdir()
        (directory / 'app/000_legacy_test.php').write_text('''<?php return function (PDO $pdo) {
            $pdo->exec('CREATE TABLE yg_legacy_test (id INTEGER)');
        };''')
        (directory / 'test-base/001.php').write_text('''<?php return function (PDO $pdo) {
            usleep(300000);
            $pdo->exec('CREATE TABLE yg_module_a (id INTEGER)');
        };''')
        (directory / 'test-child/001.php').write_text('''<?php return function (PDO $pdo) {
            $pdo->query('SELECT * FROM yg_module_a');
            $pdo->exec('CREATE TABLE yg_module_b (id INTEGER)');
        };''')
        worker = directory / 'worker.php'
        worker.write_text('''<?php
            require getenv('YG_TEST_AUTOLOAD');
            $mysql = getenv('GASKA_TEST_MYSQL') === '1';
            if ($mysql) {
                $database = getenv('MYSQL_TEST_DATABASE');
                if (!str_ends_with($database, '_test')) throw new RuntimeException('Test database required');
                $pdo = new PDO('mysql:host=127.0.0.1;port=' . (getenv('MYSQL_TEST_PORT') ?: '3306') . ';dbname=' . $database,
                    getenv('MYSQL_TEST_USERNAME') ?: 'root', getenv('MYSQL_TEST_PASSWORD') ?: '');
            } else $pdo = new PDO('sqlite:' . __DIR__ . '/db.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if (($argv[1] ?? '') === 'cleanup') {
                foreach (['yg_module_b','yg_module_a','yg_legacy_test'] as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $table);
                $pdo->exec("DELETE FROM migrations WHERE name IN ('test-base:001.php','test-child:001.php','000_legacy_test.php')");
                exit;
            }
            $sources = ($argv[1] ?? '') === 'legacy' ? [] : ['test-base' => __DIR__ . '/test-base', 'test-child' => __DIR__ . '/test-child'];
            echo json_encode((new ZoltaGaska\\Database\\Migrator($pdo, __DIR__ . '/app', $sources))->migrate());
        ''')
        env = dict(os.environ, YG_TEST_AUTOLOAD=str(ROOT / 'vendor/autoload.php'))
        first = subprocess.run(['php', str(worker), 'legacy'], env=env, capture_output=True, text=True, check=True)
        assert json.loads(first.stdout) == ['000_legacy_test.php']
        try:
            processes = [subprocess.Popen(['php', str(worker)], env=env, stdout=subprocess.PIPE,
                                          stderr=subprocess.PIPE, text=True) for _ in range(2)]
            results = []
            for process in processes:
                stdout, stderr = process.communicate(timeout=20)
                assert process.returncode == 0, stderr
                results.append(json.loads(stdout))
            assert sorted(map(len, results)) == [0, 2], results
            assert ['test-base:001.php', 'test-child:001.php'] in results, results
            repeat = subprocess.run(['php', str(worker)], env=env, capture_output=True, text=True, check=True)
            assert json.loads(repeat.stdout) == []
            print('PASS: competing migrators, dependency order, owner IDs, legacy mapping and repeat (' +
                  ('mysql' if env.get('GASKA_TEST_MYSQL') == '1' else 'sqlite') + ')')
        finally:
            subprocess.run(['php', str(worker), 'cleanup'], env=env, check=True, capture_output=True)


if __name__ == '__main__':
    main()
