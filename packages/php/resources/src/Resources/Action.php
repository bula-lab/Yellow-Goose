<?php
namespace ZoltaGaska\Resources;

final class Action
{
    public readonly \Closure $handler;

    public function __construct(
        public readonly string $name,
        public readonly string $policy,
        public readonly Validator $validator,
        callable $handler,
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $name) || $policy === '') throw new \InvalidArgumentException('Invalid action.');
        $this->handler = \Closure::fromCallable($handler);
    }
}
