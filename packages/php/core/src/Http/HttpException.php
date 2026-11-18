<?php
namespace ZoltaGaska\Http;

class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) { parent::__construct($message); }
}
