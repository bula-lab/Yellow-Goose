<?php
namespace ZoltaGaska\Security;

final class Identity
{
    public function __construct(public readonly string $id, public readonly array $roles = [])
    {
        if ($id === '') throw new \InvalidArgumentException('Identity ID cannot be empty.');
        foreach ($roles as $role) if (!is_string($role)) throw new \InvalidArgumentException('Invalid role.');
    }
}
