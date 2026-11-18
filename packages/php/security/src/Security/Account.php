<?php
namespace ZoltaGaska\Security;

final class Account
{
    public function __construct(
        public readonly Identity $identity,
        public readonly string $passwordHash,
        public readonly bool $enabled = true,
    ) {}
}
