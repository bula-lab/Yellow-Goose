<?php

namespace ZoltaGaska\Http;

class Request
{
    public function __construct(
        public readonly string $uri,
        public readonly string $method
    ) {}

    public static function capture(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        return new self($uri, $_SERVER['REQUEST_METHOD']);
    }
}
