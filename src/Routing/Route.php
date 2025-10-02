<?php

namespace ZoltaGaska\Routing;

class Route
{
    public function __construct(
        public readonly string $filePath,
        public readonly array $params = []
    ) {}
}
