<?php
namespace Colony;

use ZoltaGaska\Strategy\Rules;

final class DemoRules implements Rules
{
    public function version(): string { return 'colony-demo-1'; }
    public function economy(): array { return ['initial' => 300, 'capacity' => 1000, 'perSecond' => 2]; }
    public function targets(): array { return ['mine', 'lab', 'reactor']; }
    public function build(string $target, int $level): ?array
    {
        $base = ['mine' => [100, 5, 10, 25, 2], 'lab' => [150, 8, 20, 30, 3], 'reactor' => [80, 4, 8, 20, 1]][$target] ?? null;
        if (!$base || $level < 1 || $level > ($target === 'lab' ? 6 : 10)) return null;
        return array_combine(['cost', 'duration', 'points', 'rewardResources', 'rewardPoints'], array_map(static fn ($n) => $n * $level, $base));
    }
}
