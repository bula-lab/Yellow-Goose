<?php
namespace ZoltaGaska\Jobs;

use ZoltaGaska\Module\Module;
use ZoltaGaska\Module\Registry;

final class JobsModule implements Module
{
    public function __construct(private Queue $queue, private array $handlers, private array $onFailure = []) {}
    public function id(): string { return 'jobs'; }
    public function dependencies(): array { return []; }

    public function register(Registry $registry): void
    {
        $registry->migrations(__DIR__ . '/../../migrations');
        $registry->command('jobs:work', function (array $args): int {
            $limit = $args[0] ?? '1';
            if (count($args) > 1 || !preg_match('/^[0-9]{1,4}$/D', (string) $limit)) throw new \InvalidArgumentException('Usage: jobs:work [1..1000]');
            $this->queue->work($this->handlers, (int) $limit, $this->onFailure);
            return 0;
        });
    }
}
