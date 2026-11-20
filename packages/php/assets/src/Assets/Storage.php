<?php
namespace ZoltaGaska\Assets;

/** Keys are private to the service. All mutations of one upload use the same cross-process lock. */
interface Storage
{
    public function locked(string $upload, callable $operation): mixed;
    public function size(string $key): ?int;
    public function append(string $key, int $offset, string $bytes): void;
    public function freeze(string $from, string $to): void;
    /** @return resource Seekable, read-only binary stream. */
    public function open(string $key);
    /** Install complete bytes atomically, never replacing an existing object. */
    public function install(string $key, $stream): void;
    public function delete(string $key): void;
}
