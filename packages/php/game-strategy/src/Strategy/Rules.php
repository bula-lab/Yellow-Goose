<?php
namespace ZoltaGaska\Strategy;

interface Rules
{
    /** Identifies one immutable ruleset. Change it whenever quotes or payouts change. */
    public function version(): string;

    /** @return array{initial:int,capacity:int,perSecond:int} Stored when the player joins. */
    public function economy(): array;

    /** @return list<string> */
    public function targets(): array;

    /** Quote the requested level (first level is 1). Return null at the level cap.
     * @return array{cost:int,duration:int,points:int,rewardResources:int,rewardPoints:int}|null
     */
    public function build(string $target, int $level): ?array;
}
