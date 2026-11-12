<?php
namespace ZoltaGaska\Console;

use ZoltaGaska\Database\Connection;
use ZoltaGaska\Database\Migrator;
use ZoltaGaska\Runtime;

final class Cli
{
    public static function run(string $root, array $args): int
    {
        $command = array_shift($args) ?? 'help';
        try {
            chdir($root);
            Runtime::boot($root);
            if (in_array($command, ['help', '--help', '-h'], true)) {
                echo "Żółta-Gąska CLI\n"
                    . "php gaska dev [--port=8000] [--vite-port=5173]  PHP + Vite, Ctrl+C zatrzymuje oba\n"
                    . "php gaska serve [--host=127.0.0.1] [--port=8000] [--dev]  Tylko serwer PHP\n"
                    . "php gaska db:migrate    Aktualizuj schemat bazy\n"
                    . "php gaska db:seed       Dane demonstracyjne do pustej bazy\n";
                return 0;
            }
            if (in_array($command, ['db:migrate', 'db:seed'], true)) {
                $config = require $root . '/config/database.php';
                if ($config['default'] === 'sqlite') {
                    $database = $config['connections']['sqlite']['database'];
                    if ($database !== ':memory:' && !is_dir(dirname($database))) {
                        if (!mkdir(dirname($database), 0700, true) && !is_dir(dirname($database))) {
                            throw new \RuntimeException('Cannot create database directory.');
                        }
                    }
                }
                $pdo = Connection::getInstance();
                if ($command === 'db:migrate') {
                    $ran = (new Migrator($pdo, $root . '/database/migrations'))->migrate();
                    echo $ran ? 'Wykonano: ' . implode(', ', $ran) . "\n" : "Baza jest aktualna.\n";
                } else {
                    $seed = require $root . '/database/seed.php';
                    echo 'Dodano wpisów: ' . $seed() . "\n";
                }
                return 0;
            }
            if (!in_array($command, ['serve', 'dev'], true)) {
                throw new \InvalidArgumentException("Nieznana komenda: {$command}");
            }
            $options = ['host' => '127.0.0.1', 'port' => '8000', 'vite-port' => '5173'];
            $dev = false;
            for ($i = 0; $i < count($args); $i++) {
                if ($args[$i] === '--dev' && $command === 'serve') {
                    $dev = true;
                    continue;
                }
                if (!preg_match('/^--(host|port|vite-port)(?:=(.*))?$/D', $args[$i], $match)
                    || ($match[1] === 'vite-port' && $command !== 'dev')) {
                    throw new \InvalidArgumentException('Nieznana opcja: ' . $args[$i]);
                }
                $value = $match[2] ?? ($args[++$i] ?? null);
                if ($value === null) throw new \InvalidArgumentException('Brak wartości opcji.');
                $options[$match[1]] = $value;
            }
            foreach (['port', 'vite-port'] as $key) {
                if (!ctype_digit($options[$key]) || (int) $options[$key] < 1 || (int) $options[$key] > 65535) {
                    throw new \InvalidArgumentException('Port musi należeć do zakresu 1–65535.');
                }
            }
            if (!preg_match('/^[a-zA-Z0-9.\-]+$/D', $options['host'])) {
                throw new \InvalidArgumentException('Nieprawidłowy host.');
            }
            if ($command === 'dev') {
                if ($options['port'] === $options['vite-port']) {
                    throw new \InvalidArgumentException('PHP i Vite wymagają różnych portów.');
                }
                $process = [
                    getenv('GASKA_NODE_BINARY') ?: 'node',
                    dirname(__DIR__, 2) . '/resources/dev.mjs',
                    $root, PHP_BINARY, $options['host'], $options['port'], $options['vite-port'],
                ];
            } else {
                if ($dev) putenv('GASKA_VITE_DEV_SERVER=' . (getenv('GASKA_VITE_DEV_SERVER') ?: 'http://127.0.0.1:5173'));
                echo "Żółta-Gąska: http://{$options['host']}:{$options['port']}\n";
                $process = [PHP_BINARY, '-S', $options['host'] . ':' . $options['port'],
                    '-t', $root . '/public', $root . '/public/router.php'];
            }
            passthru(implode(' ', array_map('escapeshellarg', $process)), $status);
            return $status;
        } catch (\Throwable $error) {
            fwrite(STDERR, 'Błąd: ' . $error->getMessage() . "\n");
            return 1;
        }
    }
}
