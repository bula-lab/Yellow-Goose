<?php
namespace ZoltaGaska\Security;

interface UserProvider
{
    public function findByLogin(string $login): ?Account;
    public function findById(string $id): ?Account;
}
