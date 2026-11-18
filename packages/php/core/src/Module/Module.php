<?php
namespace ZoltaGaska\Module;

interface Module
{
    public function id(): string;
    /** @return list<string> */
    public function dependencies(): array;
    public function register(Registry $registry): void;
}
