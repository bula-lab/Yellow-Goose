<?php
namespace ZoltaGaska\Security;

use ZoltaGaska\Http\HttpException;

final class Gate
{
    public function __construct(private array $policies)
    {
        foreach ($policies as $policy) if (!is_callable($policy)) throw new \InvalidArgumentException('Invalid policy.');
    }

    public function authorize(string $policy, ?Identity $identity, mixed $subject = null): void
    {
        if (!isset($this->policies[$policy]) || ($this->policies[$policy])($identity, $subject) !== true) {
            throw new HttpException($identity === null ? 401 : 403, 'Access denied.');
        }
    }
}
